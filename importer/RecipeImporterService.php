<?php

namespace Importer;

use Exception;
use mysqli;
use Importer\Parsers\ParserInterface;
use Importer\Parsers\BbcFoodParser;

class RecipeImporterService
{
    private mysqli $db;
    /** @var ParserInterface[] */
    private array $parsers = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->parsers = [
            new BbcFoodParser(),
        ];
    }

    /**
     * Resolves an appropriate parser for the given URL.
     */
    public function getParserForUrl(string $url): ?ParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($url)) {
                return $parser;
            }
        }
        return null;
    }

    /**
     * Imports a recipe from a URL: validates, fetches, parses, and saves as draft.
     * Enforces URL uniqueness to prevent duplicate records.
     *
     * @param string $sourceUrl
     * @return array
     * @throws Exception
     */
    public function importUrl(string $sourceUrl): array
    {
        $startTime = microtime(true);
        $normalizedUrl = UrlValidator::normalizeUrl($sourceUrl);
        $domain = UrlValidator::extractDomain($sourceUrl);

        // 1. Check if normalized URL is already in database
        $existing = $this->getImportByNormalizedUrl($normalizedUrl);
        if ($existing && $existing['status'] === 'published') {
            return [
                'success' => false,
                'already_published' => true,
                'recipe_id' => $existing['recipe_id'],
                'import_id' => $existing['id'],
                'message' => 'Resep dari URL ini sudah pernah diterbitkan (Recipe ID #' . $existing['recipe_id'] . ').',
                'data' => $existing,
            ];
        }

        // 2. Validate URL and SSRF
        $validation = UrlValidator::validateUrl($sourceUrl);
        if (!$validation['valid']) {
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->recordFailure($sourceUrl, $normalizedUrl, $domain, $validation['error'], $durationMs, $existing['id'] ?? null);
            throw new Exception($validation['error']);
        }

        // 3. Find matching parser
        $parser = $this->getParserForUrl($sourceUrl);
        if (!$parser) {
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            $errMsg = 'Domain ' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . ' belum memiliki parser yang sesuai.';
            $this->recordFailure($sourceUrl, $normalizedUrl, $domain, $errMsg, $durationMs, $existing['id'] ?? null);
            throw new Exception($errMsg);
        }

        // 4. Safe Fetch
        $fetch = UrlValidator::safeFetch($sourceUrl);
        if (!$fetch['success']) {
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->recordFailure($sourceUrl, $normalizedUrl, $domain, $fetch['error'], $durationMs, $existing['id'] ?? null);
            throw new Exception('Gagal mengakses sumber: ' . $fetch['error']);
        }

        // 5. Parse
        try {
            $parsed = $parser->parse($fetch['body'], $fetch['effective_url']);
        } catch (Exception $e) {
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->recordFailure($sourceUrl, $normalizedUrl, $domain, $e->getMessage(), $durationMs, $existing['id'] ?? null);
            throw $e;
        }

        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        // 6. Suggest category
        $catId = $this->guessCategoryId($parsed['recipe_title'], $parsed['summary']);

        // 7. Pick primary candidate image
        $selectedImage = !empty($parsed['image_candidates']) ? $parsed['image_candidates'][0] : null;

        // 8. Insert or update as draft
        $importId = $this->upsertDraft([
            'id' => $existing['id'] ?? null,
            'source_url' => $sourceUrl,
            'normalized_url' => $normalizedUrl,
            'source_domain' => $domain,
            'recipe_title' => $parsed['recipe_title'],
            'prep_time' => $parsed['prep_time'],
            'cook_time' => $parsed['cook_time'],
            'total_time' => $parsed['total_time'],
            'servings' => $parsed['servings'],
            'summary' => $parsed['summary'],
            'ingredients_json' => json_encode($parsed['ingredients'], JSON_UNESCAPED_UNICODE),
            'instructions_json' => json_encode($parsed['instructions'], JSON_UNESCAPED_UNICODE),
            'image_candidates_json' => json_encode($parsed['image_candidates'], JSON_UNESCAPED_UNICODE),
            'selected_image_url' => $selectedImage,
            'cat_id' => $catId,
            'status' => 'draft',
            'failure_reason' => null,
            'processing_time_ms' => $durationMs,
        ]);

        $savedRecord = $this->getImportById($importId);

        return [
            'success' => true,
            'import_id' => $importId,
            'message' => 'Data resep berhasil diambil dan disimpan sebagai draf.',
            'data' => $savedRecord,
        ];
    }

    /**
     * Updates an existing draft with admin edits.
     */
    public function saveDraft(int $importId, array $data): bool
    {
        $stmt = $this->db->prepare("UPDATE tbl_recipe_imports SET
            recipe_title = ?,
            prep_time = ?,
            cook_time = ?,
            total_time = ?,
            servings = ?,
            summary = ?,
            ingredients_json = ?,
            instructions_json = ?,
            selected_image_url = ?,
            cat_id = ?,
            status = 'draft',
            failure_reason = NULL
            WHERE id = ?");

        if (!$stmt) {
            throw new Exception('Gagal menyiapkan query simpan draf: ' . $this->db->error);
        }

        $totalTime = trim(($data['prep_time'] ?? '') . ' / ' . ($data['cook_time'] ?? ''));
        $ingredientsJson = is_string($data['ingredients_json']) ? $data['ingredients_json'] : json_encode($data['ingredients_json'], JSON_UNESCAPED_UNICODE);
        $instructionsJson = is_string($data['instructions_json']) ? $data['instructions_json'] : json_encode($data['instructions_json'], JSON_UNESCAPED_UNICODE);
        $catId = !empty($data['cat_id']) ? (int)$data['cat_id'] : null;

        $stmt->bind_param(
            'sssssssssii',
            $data['recipe_title'],
            $data['prep_time'],
            $data['cook_time'],
            $totalTime,
            $data['servings'],
            $data['summary'],
            $ingredientsJson,
            $instructionsJson,
            $data['selected_image_url'],
            $catId,
            $importId
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Marks a draft as rejected.
     */
    public function rejectDraft(int $importId, string $reason = 'Ditolak oleh admin'): bool
    {
        $stmt = $this->db->prepare("UPDATE tbl_recipe_imports SET status = 'rejected', failure_reason = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Gagal menyiapkan query tolak resep: ' . $this->db->error);
        }
        $stmt->bind_param('si', $reason, $importId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Publishes a recipe into tbl_recipes and updates tbl_recipe_imports.
     * Transactional and ensures no partial publication.
     */
    public function publishRecipe(int $importId, array $data, string $uploadDir, ?array $uploadedFile = null): int
    {
        $record = $this->getImportById($importId);
        if (!$record) {
            throw new Exception('Draf resep #' . $importId . ' tidak ditemukan.');
        }

        if ($record['status'] === 'published' && !empty($record['recipe_id'])) {
            throw new Exception('Resep ini sudah pernah diterbitkan (Recipe ID #' . $record['recipe_id'] . ').');
        }

        // Server-side validations
        $title = trim($data['recipe_title'] ?? $record['recipe_title'] ?? '');
        if (empty($title)) {
            throw new Exception('Judul resep tidak boleh kosong.');
        }

        $catId = (int)($data['cat_id'] ?? $record['cat_id'] ?? 0);
        if ($catId <= 0 || !$this->categoryExists($catId)) {
            throw new Exception('Kategori resep wajib dipilih dan harus valid.');
        }

        // Parse ingredients and instructions
        $rawIngredients = $data['ingredients'] ?? null;
        if ($rawIngredients === null) {
            $ingJson = $data['ingredients_json'] ?? $record['ingredients_json'] ?? '[]';
            $rawIngredients = is_array($ingJson) ? $ingJson : (json_decode($ingJson, true) ?: []);
        } elseif (is_string($rawIngredients)) {
            $rawIngredients = json_decode($rawIngredients, true) ?: [];
        }

        $rawInstructions = $data['instructions'] ?? null;
        if ($rawInstructions === null) {
            $instJson = $data['instructions_json'] ?? $record['instructions_json'] ?? '[]';
            $rawInstructions = is_array($instJson) ? $instJson : (json_decode($instJson, true) ?: []);
        } elseif (is_string($rawInstructions)) {
            $rawInstructions = json_decode($rawInstructions, true) ?: [];
        }

        if (empty($rawIngredients)) {
            throw new Exception('Bahan resep tidak boleh kosong.');
        }
        if (empty($rawInstructions)) {
            throw new Exception('Langkah-langkah instruksi tidak boleh kosong.');
        }

        $summary = trim($data['summary'] ?? $record['summary'] ?? '');
        $prepTime = trim($data['prep_time'] ?? $record['prep_time'] ?? '');
        $cookTime = trim($data['cook_time'] ?? $record['cook_time'] ?? '');
        $recipeTime = '';
        if ($prepTime && $cookTime) {
            $recipeTime = $prepTime . ' prep | ' . $cookTime . ' cook';
        } elseif ($cookTime) {
            $recipeTime = $cookTime;
        } elseif ($prepTime) {
            $recipeTime = $prepTime;
        } else {
            $recipeTime = '0 Minutes';
        }

        // Build recipe description HTML
        $recipeDescription = HtmlSanitizer::buildRecipeDescription($summary, $rawIngredients, $rawInstructions);

        // Handle image
        $savedImageFilename = '';
        $downloadedFilePath = null;

        try {
            if ($uploadedFile && !empty($uploadedFile['tmp_name'])) {
                $savedImageFilename = ImageDownloader::saveUploadedFile($uploadedFile, $uploadDir);
                $downloadedFilePath = rtrim($uploadDir, '/') . '/' . $savedImageFilename;
            } else {
                $candidateImg = trim($data['selected_image_url'] ?? $record['selected_image_url'] ?? '');
                if (!empty($candidateImg)) {
                    $baseName = basename($candidateImg);
                    $localTarget = rtrim($uploadDir, '/') . '/' . $baseName;
                    if (file_exists($localTarget) && is_file($localTarget)) {
                        // Already stored in upload directory (e.g. generated by AI G-Labs or previously saved)
                        $savedImageFilename = $baseName;
                        $downloadedFilePath = $localTarget;
                    } else {
                        $savedImageFilename = ImageDownloader::download($candidateImg, $uploadDir);
                        $downloadedFilePath = rtrim($uploadDir, '/') . '/' . $savedImageFilename;
                    }
                } else {
                    $savedImageFilename = '';
                }
            }
        } catch (Exception $e) {
            throw new Exception('Gagal memproses gambar resep: ' . $e->getMessage());
        }

        // START DATABASE TRANSACTION
        $this->db->begin_transaction();

        try {
            // Check again inside transaction to prevent race conditions / duplicate publish
            $checkStmt = $this->db->prepare("SELECT status, recipe_id FROM tbl_recipe_imports WHERE id = ? FOR UPDATE");
            $checkStmt->bind_param('i', $importId);
            $checkStmt->execute();
            $currStatus = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($currStatus && $currStatus['status'] === 'published') {
                throw new Exception('Resep ini baru saja diterbitkan oleh proses lain.');
            }

            // Insert into tbl_recipes
            $insertSql = "INSERT INTO tbl_recipes (
                cat_id,
                recipe_title,
                recipe_time,
                recipe_description,
                recipe_image,
                video_url,
                video_id,
                content_type,
                size,
                featured,
                tags,
                total_views,
                last_update
            ) VALUES (?, ?, ?, ?, ?, '', 'cda11up', 'Post', '', 0, '', 0, NOW())";

            $stmt = $this->db->prepare($insertSql);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan query tambah resep: ' . $this->db->error);
            }

            $stmt->bind_param(
                'issss',
                $catId,
                $title,
                $recipeTime,
                $recipeDescription,
                $savedImageFilename
            );

            if (!$stmt->execute()) {
                throw new Exception('Gagal memasukkan resep ke tbl_recipes: ' . $stmt->error);
            }

            $publishedRecipeId = $this->db->insert_id;
            $stmt->close();

            // Update tbl_recipe_imports
            $upSql = "UPDATE tbl_recipe_imports SET
                recipe_id = ?,
                status = 'published',
                published_at = NOW(),
                failure_reason = NULL,
                cat_id = ?,
                recipe_title = ?,
                prep_time = ?,
                cook_time = ?,
                summary = ?,
                ingredients_json = ?,
                instructions_json = ?,
                selected_image_url = ?
                WHERE id = ?";

            $upStmt = $this->db->prepare($upSql);
            if (!$upStmt) {
                throw new Exception('Gagal menyiapkan query update importer: ' . $this->db->error);
            }

            $ingredientsJson = is_string($rawIngredients) ? $rawIngredients : json_encode($rawIngredients, JSON_UNESCAPED_UNICODE);
            $instructionsJson = is_string($rawInstructions) ? $rawInstructions : json_encode($rawInstructions, JSON_UNESCAPED_UNICODE);
            $selectedImageUrl = $data['selected_image_url'] ?? $record['selected_image_url'] ?? '';

            $upStmt->bind_param(
                'iisssssssi',
                $publishedRecipeId,
                $catId,
                $title,
                $prepTime,
                $cookTime,
                $summary,
                $ingredientsJson,
                $instructionsJson,
                $selectedImageUrl,
                $importId
            );

            if (!$upStmt->execute()) {
                throw new Exception('Gagal memperbarui riwayat impor: ' . $upStmt->error);
            }
            $upStmt->close();

            // Commit transaction
            $this->db->commit();

            return $publishedRecipeId;
        } catch (Exception $e) {
            $this->db->rollback();

            // Cleanup newly downloaded image if transaction failed
            if ($downloadedFilePath && file_exists($downloadedFilePath)) {
                @unlink($downloadedFilePath);
            }

            // Record failure in importer history
            $failStmt = $this->db->prepare("UPDATE tbl_recipe_imports SET failure_reason = ? WHERE id = ?");
            if ($failStmt) {
                $msg = 'Publikasi gagal: ' . $e->getMessage();
                $failStmt->bind_param('si', $msg, $importId);
                $failStmt->execute();
                $failStmt->close();
            }

            throw $e;
        }
    }

    /**
     * Retries importing a previously failed or draft URL.
     */
    public function retryImport(int $importId): array
    {
        $record = $this->getImportById($importId);
        if (!$record) {
            throw new Exception('Catatan impor #' . $importId . ' tidak ditemukan.');
        }

        return $this->importUrl($record['source_url']);
    }

    /**
     * Performs a real database test verifying access to tbl_recipes and tbl_category.
     */
    public function testDatabaseConnection(): array
    {
        try {
            // Check server connection
            $pingQry = $this->db->query("SELECT 1");
            if (!$pingQry) {
                return ['success' => false, 'message' => 'Database tidak merespons query uji.'];
            }

            // Test tbl_recipes
            $recipesQry = $this->db->query("SELECT COUNT(*) AS total FROM tbl_recipes");
            if (!$recipesQry) {
                return ['success' => false, 'message' => 'Akses ke tabel `tbl_recipes` gagal: ' . $this->db->error];
            }
            $recipesCount = $recipesQry->fetch_assoc()['total'];

            // Test tbl_category
            $categoryQry = $this->db->query("SELECT COUNT(*) AS total FROM tbl_category");
            if (!$categoryQry) {
                return ['success' => false, 'message' => 'Akses ke tabel `tbl_category` gagal: ' . $this->db->error];
            }
            $categoryCount = $categoryQry->fetch_assoc()['total'];

            // Check importer table
            $migration = new DatabaseMigration($this->db);
            $isMigrated = $migration->isMigrated();

            return [
                'success' => true,
                'message' => 'Koneksi database berhasil. Akses ke tabel resep dan kategori terverifikasi aktif.',
                'stats' => [
                    'database' => $this->getDatabaseName(),
                    'server_info' => $this->db->server_info,
                    'recipes_count' => (int)$recipesCount,
                    'categories_count' => (int)$categoryCount,
                    'importer_table_ready' => $isMigrated,
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Pemeriksaan database gagal: ' . $e->getMessage()];
        }
    }

    /**
     * Performs a real test of the upload directory.
     */
    public function testUploadFolder(string $uploadDir): array
    {
        if (!file_exists($uploadDir)) {
            return [
                'success' => false,
                'message' => 'Folder upload tidak ditemukan pada jalur: ' . htmlspecialchars($uploadDir, ENT_QUOTES, 'UTF-8'),
            ];
        }

        if (!is_dir($uploadDir)) {
            return [
                'success' => false,
                'message' => 'Jalur yang ditentukan bukan direktori: ' . htmlspecialchars($uploadDir, ENT_QUOTES, 'UTF-8'),
            ];
        }

        if (!is_readable($uploadDir)) {
            return [
                'success' => false,
                'message' => 'Folder upload tidak dapat dibaca (permission error).',
            ];
        }

        if (!is_writable($uploadDir)) {
            return [
                'success' => false,
                'message' => 'Folder upload tidak dapat ditulisi (permission error). Pastikan server memiliki izin tulis ke folder ini.',
            ];
        }

        // Test writing and unlinking a temporary file
        $testFile = rtrim($uploadDir, '/') . '/.test_write_' . uniqid() . '.tmp';
        if (@file_put_contents($testFile, 'test') === false) {
            return [
                'success' => false,
                'message' => 'Gagal menulis berkas uji coba ke folder upload.',
            ];
        }
        @unlink($testFile);

        $freeBytes = @disk_free_space($uploadDir);
        $freeMb = $freeBytes !== false ? round($freeBytes / 1048576, 1) . ' MB' : 'Tidak diketahui';

        return [
            'success' => true,
            'message' => 'Folder upload siap digunakan. Izin baca dan tulis terverifikasi aktif.',
            'details' => [
                'path' => realpath($uploadDir) ?: $uploadDir,
                'free_space' => $freeMb,
                'readable' => true,
                'writable' => true,
            ]
        ];
    }

    /**
     * Gets a list of available categories for dropdowns.
     */
    public function getCategories(): array
    {
        $list = [];
        $res = $this->db->query("SELECT cid, category_name FROM tbl_category ORDER BY category_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }

    /**
     * Gets import record by ID.
     */
    public function getImportById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM tbl_recipe_imports WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Gets import record by normalized URL.
     */
    public function getImportByNormalizedUrl(string $normalizedUrl): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM tbl_recipe_imports WHERE normalized_url = ?");
        $stmt->bind_param('s', $normalizedUrl);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Gets imports by status (e.g. 'draft', 'published', 'rejected', 'failed', or null for all).
     */
    public function getImports(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        if ($status) {
            $stmt = $this->db->prepare("SELECT * FROM tbl_recipe_imports WHERE status = ? ORDER BY id DESC LIMIT ? OFFSET ?");
            $stmt->bind_param('sii', $status, $limit, $offset);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM tbl_recipe_imports ORDER BY id DESC LIMIT ? OFFSET ?");
            $stmt->bind_param('ii', $limit, $offset);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
        $stmt->close();
        return $list;
    }

    /**
     * Counts imports by status.
     */
    public function countImports(?string $status = null): int
    {
        if ($status) {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM tbl_recipe_imports WHERE status = ?");
            $stmt->bind_param('s', $status);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM tbl_recipe_imports");
        }
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return (int)$cnt;
    }

    private function categoryExists(int $cid): bool
    {
        $stmt = $this->db->prepare("SELECT cid FROM tbl_category WHERE cid = ?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    private function guessCategoryId(string $title, string $summary): ?int
    {
        $cats = $this->getCategories();
        if (empty($cats)) {
            return null;
        }

        $text = strtolower($title . ' ' . $summary);
        foreach ($cats as $cat) {
            $catName = strtolower($cat['category_name']);
            if (str_contains($text, $catName)) {
                return (int)$cat['cid'];
            }
        }

        // Default to Main Dish or first category
        foreach ($cats as $cat) {
            if (strtolower($cat['category_name']) === 'main dish') {
                return (int)$cat['cid'];
            }
        }

        return (int)$cats[0]['cid'];
    }

    private function upsertDraft(array $d): int
    {
        if (!empty($d['id'])) {
            $stmt = $this->db->prepare("UPDATE tbl_recipe_imports SET
                source_url = ?,
                normalized_url = ?,
                source_domain = ?,
                recipe_title = ?,
                prep_time = ?,
                cook_time = ?,
                total_time = ?,
                servings = ?,
                summary = ?,
                ingredients_json = ?,
                instructions_json = ?,
                image_candidates_json = ?,
                selected_image_url = ?,
                cat_id = ?,
                status = 'draft',
                failure_reason = NULL,
                processing_time_ms = ?
                WHERE id = ?");

            $stmt->bind_param(
                'sssssssssssssiii',
                $d['source_url'],
                $d['normalized_url'],
                $d['source_domain'],
                $d['recipe_title'],
                $d['prep_time'],
                $d['cook_time'],
                $d['total_time'],
                $d['servings'],
                $d['summary'],
                $d['ingredients_json'],
                $d['instructions_json'],
                $d['image_candidates_json'],
                $d['selected_image_url'],
                $d['cat_id'],
                $d['processing_time_ms'],
                $d['id']
            );
            $stmt->execute();
            $stmt->close();
            return (int)$d['id'];
        }

        $stmt = $this->db->prepare("INSERT INTO tbl_recipe_imports (
            source_url,
            normalized_url,
            source_domain,
            recipe_title,
            prep_time,
            cook_time,
            total_time,
            servings,
            summary,
            ingredients_json,
            instructions_json,
            image_candidates_json,
            selected_image_url,
            cat_id,
            status,
            failure_reason,
            processing_time_ms
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NULL, ?)
        ON DUPLICATE KEY UPDATE
            recipe_title = VALUES(recipe_title),
            prep_time = VALUES(prep_time),
            cook_time = VALUES(cook_time),
            total_time = VALUES(total_time),
            servings = VALUES(servings),
            summary = VALUES(summary),
            ingredients_json = VALUES(ingredients_json),
            instructions_json = VALUES(instructions_json),
            image_candidates_json = VALUES(image_candidates_json),
            selected_image_url = VALUES(selected_image_url),
            cat_id = VALUES(cat_id),
            status = 'draft',
            failure_reason = NULL,
            processing_time_ms = VALUES(processing_time_ms)");

        $stmt->bind_param(
            'sssssssssssssii',
            $d['source_url'],
            $d['normalized_url'],
            $d['source_domain'],
            $d['recipe_title'],
            $d['prep_time'],
            $d['cook_time'],
            $d['total_time'],
            $d['servings'],
            $d['summary'],
            $d['ingredients_json'],
            $d['instructions_json'],
            $d['image_candidates_json'],
            $d['selected_image_url'],
            $d['cat_id'],
            $d['processing_time_ms']
        );
        $stmt->execute();
        $insertId = $stmt->insert_id ?: $this->getImportByNormalizedUrl($d['normalized_url'])['id'];
        $stmt->close();
        return (int)$insertId;
    }

    private function recordFailure(string $sourceUrl, string $normalizedUrl, string $domain, string $error, int $durationMs, ?int $existingId = null): void
    {
        if ($existingId) {
            $stmt = $this->db->prepare("UPDATE tbl_recipe_imports SET status = 'failed', failure_reason = ?, processing_time_ms = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sii', $error, $durationMs, $existingId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $stmt = $this->db->prepare("INSERT INTO tbl_recipe_imports (
            source_url, normalized_url, source_domain, status, failure_reason, processing_time_ms
        ) VALUES (?, ?, ?, 'failed', ?, ?)
        ON DUPLICATE KEY UPDATE
            status = 'failed', failure_reason = VALUES(failure_reason), processing_time_ms = VALUES(processing_time_ms)");

        if ($stmt) {
            $stmt->bind_param('ssssi', $sourceUrl, $normalizedUrl, $domain, $error, $durationMs);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function getDatabaseName(): string
    {
        $res = $this->db->query("SELECT DATABASE() AS db");
        return $res ? ($res->fetch_assoc()['db'] ?? '') : '';
    }
}
