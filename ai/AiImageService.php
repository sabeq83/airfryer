<?php

namespace Ai;

require_once __DIR__ . '/GeminiClient.php';
require_once __DIR__ . '/GlabsClient.php';

use mysqli;
use Exception;

class AiImageService
{
    private mysqli $db;
    private GeminiClient $gemini;
    private GlabsClient $glabs;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->gemini = new GeminiClient();
        $this->glabs = new GlabsClient();
    }

    public function getGeminiClient(): GeminiClient
    {
        return $this->gemini;
    }

    public function getGlabsClient(): GlabsClient
    {
        return $this->glabs;
    }

    /**
     * Get global AI configuration.
     */
    public function getConfig(): array
    {
        $res = $this->db->query("SELECT * FROM `tbl_ai_config` WHERE id = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }

        return [
            'id' => 1,
            'gemini_api_key' => '',
            'glabs_host' => '127.0.0.1',
            'glabs_port' => 8765,
            'glabs_api_key' => '',
            'glabs_model' => 'nano_banana_2',
            'gemini_model' => 'gemini-2.5-flash',
            'default_aspect_ratio' => '4:3',
            'default_style' => 'Modern Fine Dining',
            'negative_prompts' => 'blurry, distorted food, artificial plastic look, bad lighting, watermark, text, raw meat',
            'gemini_fallback_models' => '["gemini-2.5-flash","gemini-3.6-flash","gemini-3.7-flash","gemini-3.8-flash","gemini-flash-latest"]',
            'gemini_max_retries' => 2,
            'gemini_retry_delay_ms' => 2000
        ];
    }

    /**
     * Save/update global AI configuration.
     */
    public function saveConfig(array $data): array
    {
        $geminiKey = trim($data['gemini_api_key'] ?? '');
        $glabsHost = trim($data['glabs_host'] ?? '127.0.0.1');
        $glabsPort = (int)($data['glabs_port'] ?? 8765);
        $glabsKey = trim($data['glabs_api_key'] ?? '');
        $glabsModel = trim($data['glabs_model'] ?? 'nano_banana_2');
        $geminiModel = trim($data['gemini_model'] ?? 'gemini-2.5-flash');
        $aspectRatio = trim($data['default_aspect_ratio'] ?? '4:3');
        $defaultStyle = trim($data['default_style'] ?? 'Modern Fine Dining');
        $negative = trim($data['negative_prompts'] ?? '');

        $fallbackModels = isset($data['gemini_fallback_models'])
            ? (is_array($data['gemini_fallback_models']) ? json_encode($data['gemini_fallback_models']) : trim($data['gemini_fallback_models']))
            : json_encode(['gemini-2.5-flash', 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-flash-latest']);
        $maxRetries = isset($data['gemini_max_retries']) ? max(1, min(5, (int)$data['gemini_max_retries'])) : 2;
        $retryDelay = isset($data['gemini_retry_delay_ms']) ? max(500, min(10000, (int)$data['gemini_retry_delay_ms'])) : 2000;

        if ($glabsPort <= 0 || $glabsPort > 65535) {
            $glabsPort = 8765;
        }

        // If a single key was passed, ensure it is also saved to tbl_ai_keys pool
        if (!empty($geminiKey)) {
            $check = $this->db->prepare("SELECT id FROM `tbl_ai_keys` WHERE `api_key` = ?");
            if ($check) {
                $check->bind_param('s', $geminiKey);
                $check->execute();
                $check->store_result();
                if ($check->num_rows === 0) {
                    $this->gemini->addKey($this->db, 'Google AI Studio Utama', $geminiKey);
                }
                $check->close();
            }
        }

        $syncTargetUrl = trim($data['sync_target_url'] ?? '');
        $syncSecretToken = trim($data['sync_secret_token'] ?? '');
        $currentCfg = $this->getConfig();
        if (empty($syncTargetUrl)) {
            $syncTargetUrl = $currentCfg['sync_target_url'] ?? 'https://airfry.web.id/app/api_sync_recipe.php';
        }
        if (empty($syncSecretToken)) {
            $syncSecretToken = $currentCfg['sync_secret_token'] ?? 'airfry_sync_sec_99a8b7c6d5e4f3a2b1';
        }

        $stmt = $this->db->prepare("REPLACE INTO `tbl_ai_config` 
            (id, gemini_api_key, glabs_host, glabs_port, glabs_api_key, glabs_model, gemini_model, default_aspect_ratio, default_style, negative_prompts, gemini_fallback_models, gemini_max_retries, gemini_retry_delay_ms, sync_target_url, sync_secret_token) 
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $this->db->error];
        }

        $stmt->bind_param('ssisssssssiiss', $geminiKey, $glabsHost, $glabsPort, $glabsKey, $glabsModel, $geminiModel, $aspectRatio, $defaultStyle, $negative, $fallbackModels, $maxRetries, $retryDelay, $syncTargetUrl, $syncSecretToken);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            return ['success' => true, 'message' => 'Pengaturan AI Generator & Sinkronisasi berhasil disimpan.'];
        }

        return ['success' => false, 'error' => 'Gagal menyimpan: ' . $this->db->error];
    }

    /**
     * Get recipe data for prompt analysis.
     */
    public function getRecipe(int $recipeId): ?array
    {
        $stmt = $this->db->prepare("SELECT r.*, c.category_name 
            FROM `tbl_recipes` r 
            LEFT JOIN `tbl_category` c ON r.cat_id = c.cid 
            WHERE r.recipe_id = ? LIMIT 1");
        if (!$stmt) return null;

        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $recipe = $res->fetch_assoc();
        $stmt->close();

        return $recipe ?: null;
    }

    /**
     * Craft T2I prompts using Gemini Waterfall Resilient Engine.
     */
    public function craftPromptsForRecipe(int $recipeId, int $count = 1, string $stylePreset = ''): array
    {
        $config = $this->getConfig();
        $recipe = $this->getRecipe($recipeId);
        if (!$recipe) {
            return ['success' => false, 'error' => 'Resep ID #' . $recipeId . ' tidak ditemukan di database.'];
        }

        $style = !empty($stylePreset) ? $stylePreset : ($config['default_style'] ?? 'Modern Fine Dining');
        $primaryModel = !empty($config['gemini_model']) ? $config['gemini_model'] : 'gemini-2.5-flash';

        $fallbackModels = [];
        if (!empty($config['gemini_fallback_models'])) {
            $decoded = json_decode($config['gemini_fallback_models'], true);
            if (is_array($decoded)) {
                $fallbackModels = $decoded;
            }
        }
        $maxRetries = isset($config['gemini_max_retries']) ? (int)$config['gemini_max_retries'] : 2;
        $delayMs = isset($config['gemini_retry_delay_ms']) ? (int)$config['gemini_retry_delay_ms'] : 2000;

        return $this->gemini->craftFoodPromptsResilient(
            $this->db,
            $recipe,
            $count,
            $style,
            $primaryModel,
            $fallbackModels,
            $maxRetries,
            $delayMs
        );
    }

    /**
     * Unified 1-Single-Call Recipe Enhancer:
     * Generates click-worthy titles, paraphrased content with guardrails, and T2I prompts.
     */
    public function enhanceRecipeData(array $recipeData, array $options = []): array
    {
        $config = $this->getConfig();

        $style = !empty($options['style_preset']) ? $options['style_preset'] : ($config['default_style'] ?? 'Modern Fine Dining');
        $aspectRatio = !empty($options['aspect_ratio']) ? $options['aspect_ratio'] : ($config['default_aspect_ratio'] ?? '4:3');
        $primaryModel = !empty($options['primary_model']) ? $options['primary_model'] : (!empty($config['gemini_model']) ? $config['gemini_model'] : 'gemini-2.5-flash');

        $fallbackModels = [];
        if (!empty($config['gemini_fallback_models'])) {
            $decoded = json_decode($config['gemini_fallback_models'], true);
            if (is_array($decoded)) {
                $fallbackModels = $decoded;
            }
        }
        $maxRetries = isset($config['gemini_max_retries']) ? (int)$config['gemini_max_retries'] : 2;
        $delayMs = isset($config['gemini_retry_delay_ms']) ? (int)$config['gemini_retry_delay_ms'] : 2000;
        $promptCount = max(1, min(4, (int)($options['prompt_count'] ?? 1)));

        $mergedOptions = array_merge([
            'primary_model' => $primaryModel,
            'custom_fallbacks' => $fallbackModels,
            'max_retries' => $maxRetries,
            'base_delay_ms' => $delayMs,
            'style_preset' => $style,
            'aspect_ratio' => $aspectRatio,
            'prompt_count' => $promptCount
        ], $options);

        return $this->gemini->enhanceRecipeUnifiedResilient($this->db, $recipeData, $mergedOptions);
    }

    /**
     * Enhance an existing recipe from tbl_recipes by ID.
     */
    public function enhanceRecipeById(int $recipeId, array $options = []): array
    {
        $recipe = $this->getRecipe($recipeId);
        if (!$recipe) {
            return ['success' => false, 'error' => 'Resep ID #' . $recipeId . ' tidak ditemukan di database.'];
        }

        $parsed = $this->parseRecipeDescription($recipe['recipe_description'] ?? '');
        $recipeData = [
            'recipe_id' => $recipeId,
            'recipe_title' => $recipe['recipe_title'],
            'summary' => $parsed['summary'],
            'ingredients' => $parsed['ingredients'],
            'instructions' => $parsed['instructions'],
            'prep_time' => '',
            'cook_time' => $recipe['recipe_time'] ?? '',
            'servings' => '',
            'category_name' => $recipe['category_name'] ?? ''
        ];

        return $this->enhanceRecipeData($recipeData, $options);
    }

    /**
     * Parse structured recipe description HTML into summary, ingredients, and instructions.
     */
    public function parseRecipeDescription(string $html): array
    {
        $summary = '';
        $ingredients = [];
        $instructions = [];

        if (preg_match('/<strong>\s*SUMMARY\s*:?\s*<\/strong>\s*<\/p>(.*?)(?:<hr|<p><strong>\s*INGREDIENTS)/is', $html, $m)) {
            $summary = trim(strip_tags($m[1]));
        } elseif (preg_match('/^(.*?)(?:<h[23]|<strong>\s*INGREDIENTS|<hr)/is', $html, $m)) {
            $summary = trim(strip_tags($m[1]));
        }

        if (preg_match('/<strong>\s*INGREDIENTS\s*:?\s*<\/strong>\s*<\/p>(.*?)(?:<hr|<p><strong>\s*DIRECTION)/is', $html, $m)) {
            if (preg_match_all('/<li>(.*?)<\/li>/is', $m[1], $liMatches)) {
                foreach ($liMatches[1] as $item) {
                    $c = trim(strip_tags(html_entity_decode($item, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($c !== '') $ingredients[] = $c;
                }
            }
        }

        if (preg_match('/<strong>\s*DIRECTION\s*:?\s*<\/strong>\s*<\/p>(.*?)$/is', $html, $m)) {
            if (preg_match_all('/<li>(.*?)<\/li>/is', $m[1], $liMatches)) {
                foreach ($liMatches[1] as $step) {
                    $c = trim(strip_tags(html_entity_decode($step, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($c !== '') $instructions[] = $c;
                }
            }
        }

        if (empty($summary) && empty($ingredients) && empty($instructions)) {
            $summary = trim(strip_tags($html));
        }

        return [
            'summary' => $summary,
            'ingredients' => $ingredients,
            'instructions' => $instructions
        ];
    }

    /**
     * Key Pool Methods
     */
    public function getAllAiKeys(): array
    {
        return $this->gemini->getAllKeys($this->db);
    }

    public function addAiKey(string $keyName, string $apiKey): array
    {
        return $this->gemini->addKey($this->db, $keyName, $apiKey);
    }

    public function deleteAiKey(int $keyId): bool
    {
        return $this->gemini->deleteKey($this->db, $keyId);
    }

    public function toggleAiKey(int $keyId, bool $isActive): bool
    {
        return $this->gemini->toggleKey($this->db, $keyId, $isActive);
    }

    public function resetAllAiKeysCooldown(): array
    {
        return $this->gemini->resetAllCooldowns($this->db);
    }

    public function testAiKey(string $apiKey): array
    {
        return $this->gemini->testConnection($apiKey);
    }

    public function testAllAiKeys(): array
    {
        $keys = $this->getAllAiKeys();
        $results = [];
        $healthyCount = 0;
        foreach ($keys as $k) {
            $test = $this->gemini->testConnection($k['api_key']);
            $isHealthy = !empty($test['success']);

            // Auto-heal key in DB: if valid, set ACTIVE and clear cooldown
            $newStatus = $isHealthy ? 'ACTIVE' : 'INVALID';
            $stmt = $this->db->prepare("UPDATE `tbl_ai_keys` SET `last_status` = ?, `is_active` = ?, `cooldown_until` = NULL WHERE `id` = ?");
            if ($stmt) {
                $activeVal = $isHealthy ? 1 : 0;
                $keyId = (int)$k['id'];
                $stmt->bind_param('sii', $newStatus, $activeVal, $keyId);
                $stmt->execute();
                $stmt->close();
            }

            $results[] = [
                'id' => $k['id'],
                'key_name' => $k['key_name'],
                'masked_key' => $k['masked_key'],
                'success' => $isHealthy,
                'message' => $isHealthy ? 'Aktif & Sehat' : ($test['error'] ?? 'Gagal validasi')
            ];
            if ($isHealthy) {
                $healthyCount++;
            }
        }
        $total = count($keys);
        return [
            'success' => true,
            'total' => $total,
            'healthy_count' => $healthyCount,
            'message' => "Pengujian Selesai: {$healthyCount} dari {$total} kunci Google AI Studio aktif dan sehat.",
            'results' => $results
        ];
    }

    /**
     * Submit an image generation task to G-Labs Webhook.
     */
    public function submitImageTask(int $recipeId, string $prompt, string $stylePreset = '', string $aspectRatio = '4:3', string $modelOverride = ''): array
    {
        $config = $this->getConfig();
        $host = $config['glabs_host'] ?? '127.0.0.1';
        $port = (int)($config['glabs_port'] ?? 8765);
        $apiKey = $config['glabs_api_key'] ?? '';
        $model = !empty($modelOverride) ? $modelOverride : ($config['glabs_model'] ?? 'nano_banana_2');

        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'G-Labs API Key belum dikonfigurasi. Buka tab Pengaturan untuk memasukkan token API dari G-Labs Automation.'];
        }

        $params = [
            'prompt' => trim($prompt),
            'model' => $model,
            'aspect_ratio' => $aspectRatio ?: ($config['default_aspect_ratio'] ?? '4:3'),
        ];

        $res = $this->glabs->submitImageTask($host, $port, $apiKey, $params);
        if (!$res['success']) {
            return $res;
        }

        $taskId = $res['task_id'];

        // Record in tbl_ai_generations
        $stmt = $this->db->prepare("INSERT INTO `tbl_ai_generations` 
            (recipe_id, task_id, prompt, style_preset, model_used, aspect_ratio, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')");

        if ($stmt) {
            $stmt->bind_param('isssss', $recipeId, $taskId, $prompt, $stylePreset, $model, $aspectRatio);
            $stmt->execute();
            $genId = $stmt->insert_id;
            $stmt->close();
            $res['generation_id'] = $genId;
        }

        return $res;
    }

    /**
     * Poll G-Labs task status, and automatically download file upon completion.
     */
    public function pollAndDownload(string $taskId, ?int $generationId = null): array
    {
        $config = $this->getConfig();
        $host = $config['glabs_host'] ?? '127.0.0.1';
        $port = (int)($config['glabs_port'] ?? 8765);
        $apiKey = $config['glabs_api_key'] ?? '';

        // 1. Check if already completed and file exists locally
        $genIdVal = $generationId ?? 0;
        $chkStmt = $this->db->prepare("SELECT * FROM `tbl_ai_generations` WHERE task_id = ? OR (id > 0 AND id = ?) LIMIT 1");
        if ($chkStmt) {
            $chkStmt->bind_param('si', $taskId, $genIdVal);
            $chkStmt->execute();
            $existingGen = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();

            if ($existingGen && !empty($existingGen['image_filename'])) {
                $localFile = __DIR__ . '/../upload/' . $existingGen['image_filename'];
                if (file_exists($localFile) && filesize($localFile) > 0) {
                    return [
                        'success' => true,
                        'status' => 'completed',
                        'task_id' => $taskId,
                        'generation_id' => (int)$existingGen['id'],
                        'recipe_id' => (int)$existingGen['recipe_id'],
                        'image_filename' => $existingGen['image_filename'],
                        'image_file' => $existingGen['image_filename'],
                        'image_url' => 'upload/' . $existingGen['image_filename'],
                        'file_size' => filesize($localFile)
                    ];
                }
            }
        }

        // 2. Poll status from G-Labs server
        $statusRes = $this->glabs->getTaskStatus($host, $port, $apiKey, $taskId);
        if (!$statusRes['success']) {
            return $statusRes;
        }

        $status = strtolower($statusRes['status'] ?? 'pending');

        if ($status === 'completed') {
            $results = $statusRes['results'] ?? [];
            if (empty($results)) {
                return ['success' => false, 'error' => 'G-Labs task selesai tetapi tidak mengembalikan file gambar.'];
            }

            $firstFileUrl = $results[0];
            $ext = pathinfo(parse_url($firstFileUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (empty($ext)) $ext = 'jpg';
            if ($ext === 'jpeg') $ext = 'jpg';

            $newFileName = 'ai_' . time() . '_' . substr(md5($taskId . microtime()), 0, 8) . '.' . $ext;
            $destPath = __DIR__ . '/../upload/' . $newFileName;

            $dlRes = $this->glabs->downloadFile($host, $port, $firstFileUrl, $destPath, $apiKey);
            if (!$dlRes['success']) {
                return $dlRes;
            }

            // Otomatis optimasi berkas hasil generate G-Labs (Progressive JPEG 1080px @ 82%)
            if (!class_exists('ImageOptimizer') && file_exists(__DIR__ . '/../includes/ImageOptimizer.php')) {
                require_once __DIR__ . '/../includes/ImageOptimizer.php';
            }
            if (class_exists('ImageOptimizer')) {
                \ImageOptimizer::optimize($destPath, 1080, 82);
            }
            $finalFileSize = file_exists($destPath) ? filesize($destPath) : $dlRes['file_size'];

            // Update generation record
            $sql = "UPDATE `tbl_ai_generations` SET status = 'completed', image_filename = ? WHERE task_id = ?";
            if ($generationId && $generationId > 0) {
                $sql = "UPDATE `tbl_ai_generations` SET status = 'completed', image_filename = ? WHERE id = " . (int)$generationId;
            }
            $stmt = $this->db->prepare($sql);
            if ($stmt) {
                if ($generationId && $generationId > 0) {
                    $stmt->bind_param('s', $newFileName);
                } else {
                    $stmt->bind_param('ss', $newFileName, $taskId);
                }
                $stmt->execute();
                $stmt->close();
            }

            return [
                'success' => true,
                'status' => 'completed',
                'task_id' => $taskId,
                'generation_id' => $generationId ?: ($existingGen ? (int)$existingGen['id'] : null),
                'image_filename' => $newFileName,
                'image_file' => $newFileName,
                'image_url' => 'upload/' . $newFileName,
                'file_size' => $finalFileSize
            ];
        }

        if ($status === 'failed') {
            $err = $statusRes['error'] ?? 'Task G-Labs gagal diproses.';
            $stmt = $this->db->prepare("UPDATE `tbl_ai_generations` SET status = 'failed', error_message = ? WHERE task_id = ?");
            if ($stmt) {
                $stmt->bind_param('ss', $err, $taskId);
                $stmt->execute();
                $stmt->close();
            }

            return [
                'success' => false,
                'status' => 'failed',
                'error' => $err
            ];
        }

        return [
            'success' => true,
            'status' => $status,
            'message' => 'Task sedang diproses oleh G-Labs (' . $status . ')...'
        ];
    }

    /**
     * Apply generated image to recipe as main photo, and backup previous image.
     */
    public function applyImageToRecipe(int $generationId, int $recipeId): array
    {
        // 1. Get generation record
        $stmt = $this->db->prepare("SELECT * FROM `tbl_ai_generations` WHERE id = ? LIMIT 1");
        if (!$stmt) return ['success' => false, 'error' => 'DB error: ' . $this->db->error];
        $stmt->bind_param('i', $generationId);
        $stmt->execute();
        $gen = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$gen || empty($gen['image_filename'])) {
            return ['success' => false, 'error' => 'Data gambar AI tidak ditemukan atau file belum selesai diunduh.'];
        }

        $newImage = $gen['image_filename'];

        // 2. Get current recipe image
        $stmtRec = $this->db->prepare("SELECT recipe_image FROM `tbl_recipes` WHERE recipe_id = ? LIMIT 1");
        if (!$stmtRec) return ['success' => false, 'error' => 'DB error: ' . $this->db->error];
        $stmtRec->bind_param('i', $recipeId);
        $stmtRec->execute();
        $recipeRow = $stmtRec->get_result()->fetch_assoc();
        $stmtRec->close();

        if (!$recipeRow) {
            return ['success' => false, 'error' => 'Resep ID #' . $recipeId . ' tidak ditemukan.'];
        }

        $previousImage = $recipeRow['recipe_image'] ?? '';

        // 3. Update recipe_image
        $stmtUp = $this->db->prepare("UPDATE `tbl_recipes` SET recipe_image = ? WHERE recipe_id = ?");
        if (!$stmtUp) return ['success' => false, 'error' => 'DB update failed: ' . $this->db->error];
        $stmtUp->bind_param('si', $newImage, $recipeId);
        $stmtUp->execute();
        $stmtUp->close();

        // 4. Update tbl_ai_generations (record backup and status)
        $stmtGenUp = $this->db->prepare("UPDATE `tbl_ai_generations` SET status = 'applied', previous_image = ? WHERE id = ?");
        if ($stmtGenUp) {
            $stmtGenUp->bind_param('si', $previousImage, $generationId);
            $stmtGenUp->execute();
            $stmtGenUp->close();
        }

        return [
            'success' => true,
            'message' => 'Gambar berhasil dijadikan foto utama resep! Foto asli tersimpan aman di arsip cadangan.',
            'new_image' => $newImage,
            'previous_image' => $previousImage
        ];
    }

    /**
     * Revert recipe image back to original image.
     */
    public function revertRecipeImage(int $generationId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `tbl_ai_generations` WHERE id = ? LIMIT 1");
        if (!$stmt) return ['success' => false, 'error' => 'DB error: ' . $this->db->error];
        $stmt->bind_param('i', $generationId);
        $stmt->execute();
        $gen = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$gen) {
            return ['success' => false, 'error' => 'Data riwayat tidak ditemukan.'];
        }

        $prevImage = $gen['previous_image'] ?? '';
        $recipeId = (int)($gen['recipe_id'] ?? 0);

        if (empty($prevImage) || $recipeId <= 0) {
            return ['success' => false, 'error' => 'Tidak ada file cadangan foto asli untuk resep ini.'];
        }

        // Restore in tbl_recipes
        $stmtRec = $this->db->prepare("UPDATE `tbl_recipes` SET recipe_image = ? WHERE recipe_id = ?");
        if (!$stmtRec) return ['success' => false, 'error' => 'DB error: ' . $this->db->error];
        $stmtRec->bind_param('si', $prevImage, $recipeId);
        $stmtRec->execute();
        $stmtRec->close();

        // Mark generation as reverted
        $stmtUp = $this->db->prepare("UPDATE `tbl_ai_generations` SET status = 'reverted' WHERE id = ?");
        if ($stmtUp) {
            $stmtUp->bind_param('i', $generationId);
            $stmtUp->execute();
            $stmtUp->close();
        }

        return [
            'success' => true,
            'message' => 'Foto resep berhasil dipulihkan ke versi foto awal!',
            'restored_image' => $prevImage
        ];
    }

    /**
     * Get recent generation history.
     */
    public function getHistory(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = "SELECT g.*, r.recipe_title, r.recipe_image as current_recipe_image 
            FROM `tbl_ai_generations` g 
            LEFT JOIN `tbl_recipes` r ON g.recipe_id = r.recipe_id 
            ORDER BY g.id DESC LIMIT {$limit}";

        $res = $this->db->query($sql);
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
