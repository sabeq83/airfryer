<?php

namespace Ai;

use mysqli;
use Exception;

class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public const DEFAULT_CASCADE_ORDER = [
        'gemini-2.5-flash',
        'gemini-3.6-flash',
        'gemini-3.7-flash',
        'gemini-3.8-flash',
        'gemini-flash-latest'
    ];

    /**
     * Test connection to a single Google AI Studio API key.
     */
    public function testConnection(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Google Gemini API Key tidak boleh kosong.'];
        }

        $url = self::BASE_URL . '/models?key=' . urlencode($apiKey);

        $t0 = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'Koneksi cURL gagal: ' . $curlError];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($data['models'])) {
            return [
                'success' => true,
                'message' => 'Terhubung ke Google AI Studio! ' . count($data['models']) . ' model tersedia.',
                'model_count' => count($data['models'])
            ];
        }

        $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode . ' dari Google AI Studio');
        return ['success' => false, 'error' => 'Gagal validasi API Key: ' . $errMsg];
    }

    /* =========================================================================
     * API KEY POOL OPERATIONS
     * ========================================================================= */

    public static function ensureKeysTableExists(mysqli $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS `tbl_ai_keys` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `key_name` VARCHAR(100) NOT NULL,
            `api_key` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `daily_limit` INT(11) NOT NULL DEFAULT 1500,
            `used_today` INT(11) NOT NULL DEFAULT 0,
            `last_used_date` DATE DEFAULT NULL,
            `last_status` VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
            `cooldown_until` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_api_key` (`api_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    /**
     * Get all API keys from pool with masked display.
     */
    public function getAllKeys(mysqli $db): array
    {
        self::ensureKeysTableExists($db);
        $today = date('Y-m-d');
        // Reset used_today if day has changed
        $db->query("UPDATE `tbl_ai_keys` SET `used_today` = 0 WHERE `last_used_date` IS NOT NULL AND `last_used_date` < '{$today}'");
        // Clear cooldown if expired
        $db->query("UPDATE `tbl_ai_keys` SET `last_status` = 'ACTIVE', `cooldown_until` = NULL WHERE `last_status` = 'COOLDOWN_429' AND `cooldown_until` <= NOW()");

        $res = $db->query("SELECT * FROM `tbl_ai_keys` ORDER BY `id` ASC");
        $keys = [];
        $now = time();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rawKey = $row['api_key'] ?? '';
                $masked = strlen($rawKey) > 12 
                    ? substr($rawKey, 0, 8) . '...' . substr($rawKey, -4) 
                    : 'AIzaSy...';
                $row['masked_key'] = $masked;
                $row['api_key_masked'] = $masked;
                $row['label_name'] = $row['key_name'] ?? 'Google AI Studio';
                $isCooldown = (!empty($row['cooldown_until']) && strtotime($row['cooldown_until']) > $now);
                $row['is_in_cooldown'] = $isCooldown;
                if ($isCooldown) {
                    $diffSeconds = strtotime($row['cooldown_until']) - $now;
                    $hours = floor($diffSeconds / 3600);
                    $mins = floor(($diffSeconds % 3600) / 60);
                    $row['cooldown_remaining_formatted'] = ($hours > 0 ? "{$hours}j " : "") . "{$mins}m lagi";
                    $row['cooldown_until_formatted'] = date('d M Y, H:i', strtotime($row['cooldown_until']));
                } else {
                    $row['cooldown_remaining_formatted'] = null;
                    $row['cooldown_until_formatted'] = null;
                }
                $row['last_used_at'] = !empty($row['last_used_date']) ? $row['last_used_date'] : ($row['created_at'] ?? '-');
                $keys[] = $row;
            }
        }
        return $keys;
    }

    /**
     * Get next available API key from pool (lowest usage today, active, not cooling down).
     */
    public function getAvailableKey(mysqli $db, array $excludedIds = []): ?array
    {
        self::ensureKeysTableExists($db);
        $today = date('Y-m-d');
        // Maintenance updates
        $db->query("UPDATE `tbl_ai_keys` SET `used_today` = 0 WHERE `last_used_date` IS NOT NULL AND `last_used_date` < '{$today}'");
        $db->query("UPDATE `tbl_ai_keys` SET `last_status` = 'ACTIVE', `cooldown_until` = NULL WHERE `last_status` = 'COOLDOWN_429' AND `cooldown_until` <= NOW()");

        $excludeClause = '';
        if (!empty($excludedIds)) {
            $idsSanitized = implode(',', array_map('intval', $excludedIds));
            $excludeClause = " AND `id` NOT IN ({$idsSanitized})";
        }

        $sql = "SELECT * FROM `tbl_ai_keys` 
            WHERE `is_active` = 1 
              AND `last_status` != 'INVALID'
              AND (`cooldown_until` IS NULL OR `cooldown_until` <= NOW())
              AND (`daily_limit` <= 0 OR `used_today` < `daily_limit`)
              {$excludeClause}
            ORDER BY `used_today` ASC, `id` ASC 
            LIMIT 1";

        $res = $db->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }

        return null;
    }

    /**
     * Increment usage counter for a key.
     */
    public function recordKeyUsage(mysqli $db, int $keyId): void
    {
        $today = date('Y-m-d');
        $stmt = $db->prepare("UPDATE `tbl_ai_keys` 
            SET `used_today` = `used_today` + 1, 
                `last_used_date` = ?, 
                `last_status` = 'ACTIVE' 
            WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param('si', $today, $keyId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Mark a key as cooling down (due to 429 Rate Limit / 20 RPD Exceeded) or INVALID.
     * Default cooldown: 24 jam (86.400 detik) untuk batas 20 RPD Free Tier.
     */
    public function markKeyCooldown(mysqli $db, int $keyId, string $status = 'COOLDOWN_429', int $cooldownSeconds = 86400): void
    {
        if ($status === 'INVALID') {
            $stmt = $db->prepare("UPDATE `tbl_ai_keys` SET `last_status` = 'INVALID', `is_active` = 0 WHERE `id` = ?");
            if ($stmt) {
                $stmt->bind_param('i', $keyId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        // 24 jam cooldown (86400 detik) untuk reset kuota harian 20 RPD
        $today = date('Y-m-d');
        $cooldownUntil = date('Y-m-d H:i:s', time() + $cooldownSeconds);
        $stmt = $db->prepare("UPDATE `tbl_ai_keys` 
            SET `last_status` = 'COOLDOWN_429', 
                `cooldown_until` = ?,
                `last_used_date` = ?,
                `used_today` = GREATEST(COALESCE(`used_today`, 0), COALESCE(`daily_limit`, 20), 20)
            WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param('ssi', $cooldownUntil, $today, $keyId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Reset cooldown status for all keys in pool.
     */
    public function resetAllCooldowns(mysqli $db): array
    {
        self::ensureKeysTableExists($db);
        $db->query("UPDATE `tbl_ai_keys` 
            SET `last_status` = 'ACTIVE', `cooldown_until` = NULL 
            WHERE `last_status` = 'COOLDOWN_429' AND `is_active` = 1");
        $affected = $db->affected_rows;
        return [
            'success' => true,
            'affected' => $affected,
            'message' => "Berhasil mereset cooldown untuk {$affected} kunci di pool."
        ];
    }

    /**
     * Add a new API key to pool (Default daily_limit: 20 RPD for Gemini Free Tier).
     */
    public function addKey(mysqli $db, string $keyName, string $apiKey): array
    {
        self::ensureKeysTableExists($db);
        $keyName = trim($keyName);
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'API Key tidak boleh kosong.'];
        }
        if (empty($keyName)) {
            $keyName = 'Google AI Studio Key ' . date('d/m H:i');
        }

        // Validate key first
        $valTest = $this->testConnection($apiKey);
        if (!$valTest['success']) {
            return ['success' => false, 'error' => 'API Key gagal divalidasi oleh Google: ' . $valTest['error']];
        }

        $stmt = $db->prepare("INSERT INTO `tbl_ai_keys` (`key_name`, `api_key`, `is_active`, `daily_limit`) VALUES (?, ?, 1, 20)");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $db->error];
        }
        $stmt->bind_param('ss', $keyName, $apiKey);
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();
            return ['success' => true, 'id' => $newId, 'message' => "Kunci '{$keyName}' berhasil ditambahkan ke pool!"];
        } else {
            $err = $stmt->error;
            $stmt->close();
            if (strpos($err, 'Duplicate') !== false || strpos($err, 'uq_ai_api_key') !== false) {
                return ['success' => false, 'error' => 'API Key ini sudah terdaftar sebelumnya di pool.'];
            }
            return ['success' => false, 'error' => 'Gagal menyimpan kunci: ' . $err];
        }
    }

    /**
     * Delete a key from pool.
     */
    public function deleteKey(mysqli $db, int $keyId): bool
    {
        $stmt = $db->prepare("DELETE FROM `tbl_ai_keys` WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param('i', $keyId);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        }
        return false;
    }

    /**
     * Toggle active state of a key in pool.
     */
    public function toggleKey(mysqli $db, int $keyId, bool $isActive): bool
    {
        $val = $isActive ? 1 : 0;
        $status = $isActive ? 'ACTIVE' : 'DISABLED';
        $stmt = $db->prepare("UPDATE `tbl_ai_keys` SET `is_active` = ?, `last_status` = ? WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param('isi', $val, $status, $keyId);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        }
        return false;
    }

    /* =========================================================================
     * PROMPT CRAFTING ENGINE (WATERFALL CASCADE + KEY ROTATION)
     * ========================================================================= */

    /**
     * Resilient Waterfall Prompt Crafting (ContentFlow-Staging Pattern).
     * - Retries 503 up to $maxRetries with exponential backoff delay.
     * - Instant cascade on 404.
     * - Auto-rotates API Key from pool on 429 (Rate Limit).
     * - Cascades across models: primary -> fallback 1 -> fallback 2 -> etc.
     */
    public function craftFoodPromptsResilient(
        mysqli $db, 
        array $recipe, 
        int $count = 1, 
        string $stylePreset = 'Rustic Kitchen Table', 
        ?string $primaryModel = null, 
        array $customFallbacks = [], 
        int $maxRetries = 2, 
        int $baseDelayMs = 2000
    ): array {
        $count = max(1, min(4, $count));
        $primary = !empty($primaryModel) ? trim($primaryModel) : 'gemini-2.5-flash';

        // Build waterfall chain: primary first, then fallbacks
        $waterfallChain = [$primary];
        $fallbacks = !empty($customFallbacks) ? $customFallbacks : self::DEFAULT_CASCADE_ORDER;
        foreach ($fallbacks as $m) {
            $m = trim($m);
            if (!empty($m) && !in_array($m, $waterfallChain, true)) {
                $waterfallChain[] = $m;
            }
        }
        // Ensure default models are present in chain
        foreach (self::DEFAULT_CASCADE_ORDER as $dm) {
            if (!in_array($dm, $waterfallChain, true)) {
                $waterfallChain[] = $dm;
            }
        }

        $cascadeTrail = [];
        $excludedKeyIds = [];
        $totalKeysRes = $db->query("SELECT COUNT(*) as c FROM `tbl_ai_keys` WHERE `is_active` = 1");
        $activeKeyCount = ($totalKeysRes && $r = $totalKeysRes->fetch_assoc()) ? (int)$r['c'] : 0;
        $maxKeyRotations = max(5, $activeKeyCount);
        $keyRotationCount = 0;
        @set_time_limit(180);

        while ($keyRotationCount < $maxKeyRotations) {
            $keyEntry = $this->getAvailableKey($db, $excludedKeyIds);

            // Fallback: if pool is empty, try reading single key from tbl_ai_config
            if (!$keyEntry) {
                $cfgRes = $db->query("SELECT gemini_api_key FROM tbl_ai_config WHERE id=1 LIMIT 1");
                $fallbackApiKey = ($cfgRes && $r = $cfgRes->fetch_assoc()) ? trim($r['gemini_api_key'] ?? '') : '';
                if (!empty($fallbackApiKey)) {
                    $keyEntry = [
                        'id' => 0,
                        'key_name' => 'Single Config Key',
                        'api_key' => $fallbackApiKey
                    ];
                } else {
                    if (!empty($cascadeTrail)) {
                        return [
                            'success' => false,
                            'error' => 'Semua kunci API dan model di Pool Gemini gagal: ' . end($cascadeTrail),
                            'cascade_trail' => $cascadeTrail
                        ];
                    }
                    return [
                        'success' => false,
                        'error' => 'Tidak ada API Key Gemini yang aktif di pool. Silakan tambahkan API Key di tab Pengaturan.',
                        'cascade_trail' => $cascadeTrail
                    ];
                }
            }

            $activeKey = $keyEntry['api_key'];
            $keyName = $keyEntry['key_name'] ?? ('Key #' . $keyEntry['id']);
            $keyId = (int)($keyEntry['id'] ?? 0);

            // Loop through model waterfall chain
            foreach ($waterfallChain as $idx => $currentModel) {
                $hasNextModel = isset($waterfallChain[$idx + 1]);
                $nextModel = $hasNextModel ? $waterfallChain[$idx + 1] : null;

                // 1 initial attempt + up to $maxRetries attempts
                for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                    $singleCallRes = $this->craftFoodPrompts($activeKey, $recipe, $count, $stylePreset, $currentModel);

                    if ($singleCallRes['success']) {
                        // Success! Record usage
                        if ($keyId > 0) {
                            $this->recordKeyUsage($db, $keyId);
                        }
                        $cascadeTrail[] = "{$currentModel} [{$keyName}] -> SUKSES (Attempt " . ($attempt + 1) . ")";

                        return [
                            'success' => true,
                            'prompts' => $singleCallRes['prompts'],
                            'model_used' => $currentModel,
                            'key_used' => $keyName,
                            'attempts_needed' => $attempt + 1,
                            'cascade_trail' => $cascadeTrail
                        ];
                    }

                    // Classify error
                    $rawError = $singleCallRes['error'] ?? 'Unknown Error';
                    $errLower = strtolower($rawError);

                    // 1. HTTP 404 / Model Not Found / Deprecated -> Instant Cascade
                    $is404 = strpos($errLower, '404') !== false || 
                             strpos($errLower, 'not found') !== false || 
                             strpos($errLower, 'no longer available') !== false || 
                             strpos($errLower, 'is not supported') !== false;
                    if ($is404) {
                        $cascadeTrail[] = "{$currentModel} -> 404 Model Not Found/Deprecated -> Instant Cascade ke " . ($nextModel ?? 'None');
                        break; // Break inner retry loop to move to next model
                    }

                    // 2. HTTP 503 / High Demand / Service Unavailable / Overloaded
                    $is503 = strpos($errLower, '503') !== false || 
                             strpos($errLower, 'high demand') !== false || 
                             strpos($errLower, 'service unavailable') !== false || 
                             strpos($errLower, 'overloaded') !== false;
                    if ($is503) {
                        if ($attempt < $maxRetries) {
                            $delayMs = (int)($baseDelayMs * pow(2, $attempt)) + rand(100, 500);
                            $cascadeTrail[] = "{$currentModel} -> 503 High Demand (Attempt " . ($attempt + 1) . "/".($maxRetries + 1).") -> Retry in " . ($delayMs / 1000) . "s";
                            usleep($delayMs * 1000);
                            continue; // Retry same model
                        }
                        // Retry exhausted, cascade to next model if available
                        if ($hasNextModel) {
                            $cascadeTrail[] = "{$currentModel} -> 503 High Demand (Exhausted {$maxRetries} retries) -> Cascade ke {$nextModel}";
                            break; // Move to next model
                        }
                    }

                    // 3. HTTP 429 / Rate Limit / Quota Exceeded (Limit 20 RPD) -> Cascade to Next Model First!
                    $is429 = strpos($errLower, '429') !== false || 
                             strpos($errLower, 'quota') !== false || 
                             strpos($errLower, 'resource has been exhausted') !== false || 
                             strpos($errLower, 'rate limit') !== false;
                    if ($is429) {
                        if ($hasNextModel) {
                            $cascadeTrail[] = "{$currentModel} [{$keyName}] -> 429 Model Quota Exceeded -> Waterfall Cascade ke model {$nextModel}";
                            break; // Try next model on SAME key!
                        } else {
                            if ($keyId > 0) {
                                $this->markKeyCooldown($db, $keyId, 'COOLDOWN_429', 86400); // 24 jam cooldown (seluruh model exhausted)
                                $excludedKeyIds[] = $keyId;
                            }
                            $cascadeTrail[] = "Key [{$keyName}] -> Seluruh model 429 Quota Exceeded -> Cooldown 24 Jam -> Rotasi ke Kunci berikutnya di Pool";
                            break 2; // All models on this key exhausted, rotate key!
                        }
                    }

                    // 4. Timeout -> Instant Cascade
                    $isTimeout = strpos($errLower, 'timed out') !== false || strpos($errLower, 'timeout') !== false;
                    if ($isTimeout) {
                        $cascadeTrail[] = "{$currentModel} [{$keyName}] -> Timeout -> Instant Cascade ke " . ($nextModel ?? 'None');
                        break;
                    }

                    // 5. Invalid API Key (400/403 with API key invalid)
                    $isInvalidKey = strpos($errLower, 'api_key_invalid') !== false || 
                                    strpos($errLower, 'api key not valid') !== false;
                    if ($isInvalidKey && $keyId > 0) {
                        $this->markKeyCooldown($db, $keyId, 'INVALID');
                        $excludedKeyIds[] = $keyId;
                        $cascadeTrail[] = "Key [{$keyName}] -> INVALID API KEY -> Dinonaktifkan permanen di pool";
                        break 2;
                    }

                    // Unhandled or non-retryable error
                    $cascadeTrail[] = "{$currentModel} [{$keyName}] -> Error: {$rawError}";
                    if ($hasNextModel) {
                        break; // Try next model
                    }
                }
            }

            // Key rotation count incremented once per evaluated key
            $keyRotationCount++;
            if ($keyId > 0 && !in_array($keyId, $excludedKeyIds, true)) {
                $excludedKeyIds[] = $keyId;
            }
        }

        // Check if all active keys are cooling down
        $cdRes = $db->query("SELECT key_name, cooldown_until FROM `tbl_ai_keys` WHERE `is_active` = 1 AND `cooldown_until` > NOW() ORDER BY `cooldown_until` ASC");
        $coolingKeys = [];
        $earliestReset = null;
        if ($cdRes) {
            while ($cRow = $cdRes->fetch_assoc()) {
                $coolingKeys[] = $cRow['key_name'];
                if (!$earliestReset) {
                    $earliestReset = date('d M Y, H:i', strtotime($cRow['cooldown_until']));
                }
            }
        }

        if (!empty($coolingKeys)) {
            $errSummary = "Semua " . count($coolingKeys) . " API Key di Pool Gemini telah mencapai kuota harian (Limit 20 RPD habis) dan dalam masa cooldown 24 jam. Kunci tercepat aktif kembali: " . ($earliestReset ?: '24 jam lagi') . ". Tambahkan API Key baru di tab Pengaturan AI atau klik 'Reset Cooldown Pool'.";
        } else {
            $errSummary = 'Semua model dan kunci di Pool Gemini sementara tidak tersedia: ' . (end($cascadeTrail) ?: 'Unknown Error');
        }

        return [
            'success' => false,
            'error' => $errSummary,
            'cascade_trail' => $cascadeTrail
        ];
    }

    /**
     * Craft photorealistic food photography prompts based on recipe data (Single-call core).
     */
    public function craftFoodPrompts(string $apiKey, array $recipe, int $count = 1, string $stylePreset = 'Modern Fine Dining', string $model = 'gemini-2.5-flash'): array
    {
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'API Key Gemini belum dikonfigurasi di tab Pengaturan.'];
        }

        $count = max(1, min(4, $count));
        $model = !empty($model) ? trim($model) : 'gemini-2.5-flash';
        $url = self::BASE_URL . '/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        $title = $recipe['recipe_title'] ?? 'Air Fryer Dish';
        $summary = strip_tags($recipe['recipe_description'] ?? '');
        $tags = $recipe['tags'] ?? '';
        $time = $recipe['recipe_time'] ?? '';

        $countNotice = ($count === 1) 
            ? "Generate EXACTLY 1 single, best-possible photorealistic food photography Text-to-Image (T2I) prompt in English exploring the ultimate hero camera angle (e.g. 45-degree hero shot or close-up)."
            : "Generate EXACTLY {$count} distinct, highly descriptive, photorealistic food photography Text-to-Image (T2I) prompts in English, each exploring a different camera angle (e.g. hero shot, macro close-up, flatlay spread).";

        $systemPrompt = "You are an elite food photography director and prompt engineer for commercial culinary publications. "
            . "Analyze the given recipe title, ingredients, and cooking technique. "
            . "{$countNotice} "
            . "Visual style requested: '{$stylePreset}'. "
            . "Make the food look irresistibly delicious, featuring golden crispy textures, steam rising softly, appetizing sauce glaze, fresh herb garnish, realistic tableware, and professional lighting (e.g., 85mm f/1.8 lens, diffused soft window light, shallow depth of field, 8k resolution, no watermark). "
            . "CRITICAL RULE: The 'prompts' JSON array MUST contain EXACTLY {$count} item(s). Do NOT generate more or fewer than {$count} items. "
            . "Return ONLY valid JSON strictly matching this schema: "
            . "{\"prompts\": [{\"variation_number\": 1, \"title\": \"Short Title (Bahasa Indonesia)\", \"prompt\": \"Detailed English prompt for T2I\", \"tags\": [\"Ratio: 1:1\", \"Lighting: Soft\", \"Texture: Crisp\"]}]}";

        $userPrompt = "Recipe Title: {$title}\nDescription/Instructions: {$summary}\nCooking Time: {$time}\nTags: {$tags}\nStyle Preset: {$stylePreset}\nExact count required: {$count}";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\n" . $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.9,
                'responseMimeType' => 'application/json'
            ]
        ];

        $t0 = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'Koneksi ke Gemini gagal: ' . $curlError];
        }

        $resData = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $resData['error']['message'] ?? ('HTTP Error ' . $httpCode);
            return ['success' => false, 'error' => 'Gemini API Error (' . $httpCode . '): ' . $msg];
        }

        $rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (empty($rawText)) {
            return ['success' => false, 'error' => 'Gemini tidak mengembalikan teks prompt.'];
        }

        // Clean markdown codeblocks if returned
        $cleaned = trim($rawText);
        if (strncmp($cleaned, '```json', 7) === 0) {
            $cleaned = substr($cleaned, 7);
        } elseif (strncmp($cleaned, '```', 3) === 0) {
            $cleaned = substr($cleaned, 3);
        }
        if (substr($cleaned, -3) === '```') {
            $cleaned = substr($cleaned, 0, -3);
        }
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        $promptsList = $parsed['prompts'] ?? [];

        if (empty($promptsList) || !is_array($promptsList)) {
            // Fallback wrap if JSON structure was a direct array or unstructured
            if (is_array($parsed) && isset($parsed[0]['prompt'])) {
                $promptsList = $parsed;
            } else {
                $promptsList = [
                    [
                        'variation_number' => 1,
                        'title' => 'Variasi 1: Sajian Utama',
                        'prompt' => $cleaned,
                        'tags' => ['Ratio: 1:1', 'Style: ' . $stylePreset]
                    ]
                ];
            }
        }

        // Enforce strict count truncation
        if (is_array($promptsList)) {
            $promptsList = array_slice($promptsList, 0, $count);
        }

        return [
            'success' => true,
            'prompts' => $promptsList
        ];
    }

    /* =========================================================================
     * UNIFIED RECIPE ENHANCER (1-SINGLE-CALL GEMINI ENGINE)
     * ========================================================================= */

    /**
     * Resilient Waterfall Unified Recipe Enhancer:
     * Executes Title Generation, Paraphrase with Guardrails, and T2I Prompts in 1 single Gemini call.
     */
    public function enhanceRecipeUnifiedResilient(
        mysqli $db,
        array $recipeData,
        array $options = []
    ): array {
        $primary = !empty($options['primary_model']) ? trim($options['primary_model']) : 'gemini-2.5-flash';
        $fallbacks = !empty($options['custom_fallbacks']) ? $options['custom_fallbacks'] : self::DEFAULT_CASCADE_ORDER;
        $maxRetries = isset($options['max_retries']) ? max(1, min(5, (int)$options['max_retries'])) : 2;
        $baseDelayMs = isset($options['base_delay_ms']) ? max(500, min(10000, (int)$options['base_delay_ms'])) : 2000;

        // Build waterfall chain
        $waterfallChain = [$primary];
        foreach ($fallbacks as $m) {
            $m = trim($m);
            if (!empty($m) && !in_array($m, $waterfallChain, true)) {
                $waterfallChain[] = $m;
            }
        }
        foreach (self::DEFAULT_CASCADE_ORDER as $dm) {
            if (!in_array($dm, $waterfallChain, true)) {
                $waterfallChain[] = $dm;
            }
        }

        $cascadeTrail = [];
        $excludedKeyIds = [];
        $totalKeysRes = $db->query("SELECT COUNT(*) as c FROM `tbl_ai_keys` WHERE `is_active` = 1");
        $activeKeyCount = ($totalKeysRes && $r = $totalKeysRes->fetch_assoc()) ? (int)$r['c'] : 0;
        $maxKeyRotations = max(5, $activeKeyCount);
        $keyRotationCount = 0;
        @set_time_limit(180);

        while ($keyRotationCount < $maxKeyRotations) {
            $keyEntry = $this->getAvailableKey($db, $excludedKeyIds);

            if (!$keyEntry) {
                $cfgRes = $db->query("SELECT gemini_api_key FROM tbl_ai_config WHERE id=1 LIMIT 1");
                $fallbackApiKey = ($cfgRes && $r = $cfgRes->fetch_assoc()) ? trim($r['gemini_api_key'] ?? '') : '';
                if (!empty($fallbackApiKey)) {
                    $keyEntry = [
                        'id' => 0,
                        'key_name' => 'Single Config Key',
                        'api_key' => $fallbackApiKey
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'Tidak ada API Key Gemini yang aktif di pool. Silakan tambahkan API Key di tab Pengaturan.',
                        'cascade_trail' => $cascadeTrail
                    ];
                }
            }

            $activeKey = $keyEntry['api_key'];
            $keyName = $keyEntry['key_name'] ?? ('Key #' . $keyEntry['id']);
            $keyId = (int)($keyEntry['id'] ?? 0);

            foreach ($waterfallChain as $idx => $currentModel) {
                $hasNextModel = isset($waterfallChain[$idx + 1]);
                $nextModel = $hasNextModel ? $waterfallChain[$idx + 1] : null;

                for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                    $callOptions = array_merge($options, ['model' => $currentModel]);
                    $singleCallRes = $this->enhanceRecipeUnified($activeKey, $recipeData, $callOptions);

                    if ($singleCallRes['success']) {
                        if ($keyId > 0) {
                            $this->recordKeyUsage($db, $keyId);
                        }
                        $cascadeTrail[] = "{$currentModel} [{$keyName}] -> SUKSES (Attempt " . ($attempt + 1) . ")";

                        return [
                            'success' => true,
                            'data' => $singleCallRes['data'],
                            'model_used' => $currentModel,
                            'key_used' => $keyName,
                            'attempts_needed' => $attempt + 1,
                            'cascade_trail' => $cascadeTrail
                        ];
                    }

                    $rawError = $singleCallRes['error'] ?? 'Unknown Error';
                    $errLower = strtolower($rawError);

                    // 1. 404 Model Not Found / Deprecated
                    $is404 = strpos($errLower, '404') !== false || 
                             strpos($errLower, 'not found') !== false || 
                             strpos($errLower, 'no longer available') !== false || 
                             strpos($errLower, 'is not supported') !== false;
                    if ($is404) {
                        $cascadeTrail[] = "{$currentModel} -> 404 Not Found -> Instant Cascade ke " . ($nextModel ?? 'None');
                        break;
                    }

                    // 2. 503 Service Unavailable / High Demand
                    $is503 = strpos($errLower, '503') !== false || 
                             strpos($errLower, 'high demand') !== false || 
                             strpos($errLower, 'service unavailable') !== false || 
                             strpos($errLower, 'overloaded') !== false;
                    if ($is503) {
                        if ($attempt < $maxRetries) {
                            $delayMs = (int)($baseDelayMs * pow(2, $attempt)) + rand(100, 500);
                            $cascadeTrail[] = "{$currentModel} -> 503 High Demand (Attempt " . ($attempt + 1) . "/".($maxRetries + 1).") -> Retry in " . ($delayMs / 1000) . "s";
                            usleep($delayMs * 1000);
                            continue;
                        }
                        if ($hasNextModel) {
                            $cascadeTrail[] = "{$currentModel} -> 503 High Demand (Exhausted retries) -> Cascade ke {$nextModel}";
                            break;
                        }
                    }

                    // 3. HTTP 429 / Rate Limit / Quota Exceeded (Limit 20 RPD) -> Cascade to Next Model First!
                    $is429 = strpos($errLower, '429') !== false || 
                             strpos($errLower, 'quota') !== false || 
                             strpos($errLower, 'resource has been exhausted') !== false || 
                             strpos($errLower, 'rate limit') !== false;
                    if ($is429) {
                        if ($hasNextModel) {
                            $cascadeTrail[] = "{$currentModel} [{$keyName}] -> 429 Model Quota Exceeded -> Waterfall Cascade ke model {$nextModel}";
                            break; // Try next model on SAME key!
                        } else {
                            if ($keyId > 0) {
                                $this->markKeyCooldown($db, $keyId, 'COOLDOWN_429', 86400); // 24 jam cooldown (seluruh model exhausted)
                                $excludedKeyIds[] = $keyId;
                            }
                            $cascadeTrail[] = "Key [{$keyName}] -> Seluruh model 429 Quota Exceeded -> Cooldown 24 Jam -> Rotasi ke Kunci berikutnya di Pool";
                            break 2; // All models on this key exhausted, rotate key!
                        }
                    }

                    // 4. Timeout -> Instant Cascade
                    $isTimeout = strpos($errLower, 'timed out') !== false || strpos($errLower, 'timeout') !== false;
                    if ($isTimeout) {
                        $cascadeTrail[] = "{$currentModel} [{$keyName}] -> Timeout -> Instant Cascade ke " . ($nextModel ?? 'None');
                        break;
                    }

                    // 5. Invalid API Key
                    $isInvalidKey = strpos($errLower, 'api_key_invalid') !== false || 
                                    strpos($errLower, 'api key not valid') !== false;
                    if ($isInvalidKey && $keyId > 0) {
                        $this->markKeyCooldown($db, $keyId, 'INVALID');
                        $excludedKeyIds[] = $keyId;
                        $cascadeTrail[] = "Key [{$keyName}] -> INVALID API KEY -> Dinonaktifkan di pool";
                        break 2;
                    }

                    $cascadeTrail[] = "{$currentModel} [{$keyName}] -> Error: {$rawError}";
                    if ($hasNextModel) {
                        break;
                    }
                }
            }

            // Key rotation count incremented once per evaluated key
            $keyRotationCount++;
            if ($keyId > 0 && !in_array($keyId, $excludedKeyIds, true)) {
                $excludedKeyIds[] = $keyId;
            }
        }

        // Check if all active keys are cooling down
        $cdRes = $db->query("SELECT key_name, cooldown_until FROM `tbl_ai_keys` WHERE `is_active` = 1 AND `cooldown_until` > NOW() ORDER BY `cooldown_until` ASC");
        $coolingKeys = [];
        $earliestReset = null;
        if ($cdRes) {
            while ($cRow = $cdRes->fetch_assoc()) {
                $coolingKeys[] = $cRow['key_name'];
                if (!$earliestReset) {
                    $earliestReset = date('d M Y, H:i', strtotime($cRow['cooldown_until']));
                }
            }
        }

        if (!empty($coolingKeys)) {
            $errSummary = "Semua " . count($coolingKeys) . " API Key di Pool Gemini telah mencapai kuota harian (Limit 20 RPD habis) dan dalam masa cooldown 24 jam. Kunci tercepat aktif kembali: " . ($earliestReset ?: '24 jam lagi') . ". Tambahkan API Key baru di tab Pengaturan AI atau klik 'Reset Cooldown Pool'.";
        } else {
            $errSummary = 'Semua model dan kunci di Pool Gemini tidak tersedia: ' . (end($cascadeTrail) ?: 'Unknown Error');
        }

        return [
            'success' => false,
            'error' => $errSummary,
            'cascade_trail' => $cascadeTrail
        ];
    }

    /**
     * Single-pass Gemini API Call to enhance recipe text & craft image prompts.
     */
    public function enhanceRecipeUnified(string $apiKey, array $recipeData, array $options = []): array
    {
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'API Key Gemini belum dikonfigurasi.'];
        }

        $model = !empty($options['model']) ? trim($options['model']) : 'gemini-2.5-flash';
        $stylePreset = !empty($options['style_preset']) ? trim($options['style_preset']) : 'Modern Fine Dining';
        $aspectRatio = !empty($options['aspect_ratio']) ? trim($options['aspect_ratio']) : '4:3';
        $promptCount = max(1, min(4, (int)($options['prompt_count'] ?? 1)));

        $title = trim($recipeData['recipe_title'] ?? $recipeData['title'] ?? 'Air Fryer Recipe');
        $summary = trim($recipeData['summary'] ?? strip_tags($recipeData['recipe_description'] ?? ''));
        $prepTime = trim($recipeData['prep_time'] ?? '');
        $cookTime = trim($recipeData['cook_time'] ?? '');
        $servings = trim($recipeData['servings'] ?? '');

        // Format ingredients
        $rawIng = $recipeData['ingredients'] ?? ($recipeData['ingredients_json'] ?? []);
        if (is_string($rawIng)) {
            $decodedIng = json_decode($rawIng, true);
            $rawIng = is_array($decodedIng) ? $decodedIng : preg_split('/\r\n|\r|\n/', $rawIng);
        }
        $formattedIng = [];
        if (is_array($rawIng)) {
            foreach ($rawIng as $item) {
                if (is_array($item) && isset($item['items']) && is_array($item['items'])) {
                    $grp = trim($item['group'] ?? '');
                    if ($grp !== '' && strtolower($grp) !== 'ingredients') {
                        $formattedIng[] = "[$grp]";
                    }
                    foreach ($item['items'] as $sub) {
                        $s = trim((string)$sub);
                        if ($s !== '') $formattedIng[] = "- " . $s;
                    }
                } else {
                    $s = trim((string)$item);
                    if ($s !== '') $formattedIng[] = "- " . $s;
                }
            }
        }
        $ingredientsText = !empty($formattedIng) ? implode("\n", $formattedIng) : 'No ingredients provided';

        // Format instructions
        $rawInst = $recipeData['instructions'] ?? ($recipeData['instructions_json'] ?? []);
        if (is_string($rawInst)) {
            $decodedInst = json_decode($rawInst, true);
            $rawInst = is_array($decodedInst) ? $decodedInst : preg_split('/\r\n|\r|\n/', $rawInst);
        }
        $formattedInst = [];
        if (is_array($rawInst)) {
            foreach ($rawInst as $step) {
                $s = trim((string)$step);
                if ($s !== '') $formattedInst[] = (count($formattedInst) + 1) . ". " . $s;
            }
        }
        $instructionsText = !empty($formattedInst) ? implode("\n", $formattedInst) : 'No instructions provided';

        $systemPrompt = "You are an elite culinary author, recipe editor, and commercial food photography director specializing in premier Air Fryer culinary media.\n"
            . "TASK: In ONE SINGLE PASS, enhance this recipe and craft photorealistic T2I image prompts.\n"
            . "LANGUAGE: All generated text MUST be in professional, appetizing culinary English (do NOT translate to any other language).\n\n"
            . "CRITICAL RULES & STRICT GUARDRAILS:\n"
            . "1. CLICK-WORTHY TITLES (English):\n"
            . "   - Generate 1 irresistible, highly appetizing primary title and 2-3 catchy alternative titles.\n"
            . "   - Titles must be click-worthy, highlighting appetizing textures, aromas, or techniques (e.g., 'Ultra-Crispy Garlic Butter...', 'Golden...', 'Melt-in-Your-Mouth...').\n"
            . "   - Titles MUST be distinctly different and much more enticing than the original title, avoiding bland or generic names.\n\n"
            . "2. INGREDIENTS PRESERVATION (CRITICAL STRICT GUARDRAIL):\n"
            . "   - You MUST retain 100% of the exact ingredient quantities, numbers, measurements, and units (e.g., grams, ml, tbsp, tsp, cups, pieces) and ingredient types from the original recipe.\n"
            . "   - Absolutely DO NOT alter, scale, omit, or invent ingredient amounts. If original has '2 tbsp olive oil' and '100g cheddar', you must strictly keep '2 tbsp olive oil' and '100g cheddar'.\n"
            . "   - Only standardize culinary English formatting for clarity.\n\n"
            . "3. SUMMARY & NUTRITION FACTS PRESERVATION:\n"
            . "   - Write a rich, mouthwatering narrative summary (2-3 sentences) detailing the dish's flavor, crispy/succulent texture, and why it is a standout air fryer meal.\n"
            . "   - NUTRITION PRESERVATION GUARDRAIL: If the original summary/description contains nutrition information or calorie counts (e.g. 'Each serving provides 507 kcal, 18g protein...'), you MUST preserve and include that EXACT nutrition statement verbatim at the end of the summary on a new line. Do NOT alter nutritional numbers. If no nutrition was provided originally, do not fabricate numbers.\n\n"
            . "4. AIR FRYER INSTRUCTION PARAPHRASING:\n"
            . "   - Rewrite cooking instructions into clear, numbered, step-by-step directions.\n"
            . "   - Paraphrase creatively so the text is NOT an identical copy of the source website.\n"
            . "   - Explicitly integrate Air Fryer best practices: preheating the air fryer (specifying temp in °C and °F), placing food in a single layer without overlapping in the basket, flipping/shaking halfway through cooking, and clear visual doneness cues (e.g., golden crisp exterior, bubbling melted cheese, fork-tender).\n"
            . "   - Provide 1 actionable 'airfryer_tips' on achieving maximum crispiness or optimal flavor.\n\n"
            . "5. PHOTOREALISTIC FOOD PHOTOGRAPHY T2I PROMPTS:\n"
            . "   - Visual Style requested: '{$stylePreset}'\n"
            . "   - Aspect Ratio: '{$aspectRatio}'\n"
            . "   - Generate EXACTLY {$promptCount} commercial English image prompt(s) for Text-to-Image AI depicting the finished dish at its absolute most delicious (golden crispy textures, steam rising softly, herb garnish, authentic tableware, 85mm f/1.8 lens, diffused studio lighting, shallow depth of field, 8k resolution, no watermark).\n\n"
            . "Return ONLY valid JSON strictly matching this schema:\n"
            . "{\n"
            . "  \"titles\": {\n"
            . "    \"primary\": \"Ultra-Crispy Primary Title\",\n"
            . "    \"alternatives\": [\"Catchy Alternative 1\", \"Catchy Alternative 2\"]\n"
            . "  },\n"
            . "  \"summary\": \"Appetizing narrative hook...\\n\\nEach serving provides 520 kcal, 37g protein...\",\n"
            . "  \"ingredients\": [\"Exact ingredient 1 with measurement\", \"Exact ingredient 2 with measurement\"],\n"
            . "  \"instructions\": [\"Step 1 with air fryer temp/preheat\", \"Step 2 with single layer placement\", \"Step 3 with flip halfway\"],\n"
            . "  \"airfryer_tips\": \"Actionable pro tip for ultimate crispiness...\",\n"
            . "  \"image_prompts\": [\n"
            . "    {\"variation_number\": 1, \"title\": \"Hero Shot 45°\", \"prompt\": \"Detailed English T2I prompt...\", \"tags\": [\"Ratio: {$aspectRatio}\", \"Style: {$stylePreset}\"]}\n"
            . "  ]\n"
            . "}";

        $userPrompt = "Original Recipe Data:\n"
            . "Title: {$title}\n"
            . "Prep Time: {$prepTime} | Cook Time: {$cookTime} | Servings: {$servings}\n"
            . "Original Summary:\n{$summary}\n\n"
            . "Original Ingredients:\n{$ingredientsText}\n\n"
            . "Original Instructions:\n{$instructionsText}\n\n"
            . "Requested Style Preset: {$stylePreset}\n"
            . "Requested Aspect Ratio: {$aspectRatio}\n"
            . "Image Prompts Count: {$promptCount}";

        $url = self::BASE_URL . '/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\n" . $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.9,
                'responseMimeType' => 'application/json'
            ]
        ];

        $t0 = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'Koneksi ke Gemini gagal: ' . $curlError];
        }

        $resData = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $resData['error']['message'] ?? ('HTTP Error ' . $httpCode);
            return ['success' => false, 'error' => 'Gemini API Error (' . $httpCode . '): ' . $msg];
        }

        $rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (empty($rawText)) {
            return ['success' => false, 'error' => 'Gemini tidak mengembalikan teks output.'];
        }

        $cleaned = trim($rawText);
        if (strncmp($cleaned, '```json', 7) === 0) {
            $cleaned = substr($cleaned, 7);
        } elseif (strncmp($cleaned, '```', 3) === 0) {
            $cleaned = substr($cleaned, 3);
        }
        if (substr($cleaned, -3) === '```') {
            $cleaned = substr($cleaned, 0, -3);
        }
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed)) {
            return ['success' => false, 'error' => 'Format JSON tidak valid dari Gemini: ' . substr($cleaned, 0, 100)];
        }

        // Normalize titles
        $titles = $parsed['titles'] ?? [];
        if (is_string($titles)) {
            $titles = ['primary' => $titles, 'alternatives' => []];
        } elseif (!isset($titles['primary'])) {
            $titles = [
                'primary' => $titles[0] ?? $title,
                'alternatives' => array_slice(is_array($titles) ? $titles : [], 1)
            ];
        }
        if (!isset($titles['alternatives']) || !is_array($titles['alternatives'])) {
            $titles['alternatives'] = [];
        }

        // Normalize image prompts
        $imgPrompts = $parsed['image_prompts'] ?? ($parsed['prompts'] ?? []);
        if (is_array($imgPrompts)) {
            $imgPrompts = array_slice($imgPrompts, 0, $promptCount);
        } else {
            $imgPrompts = [];
        }

        return [
            'success' => true,
            'data' => [
                'titles' => $titles,
                'summary' => trim($parsed['summary'] ?? ''),
                'ingredients' => is_array($parsed['ingredients'] ?? null) ? $parsed['ingredients'] : [],
                'instructions' => is_array($parsed['instructions'] ?? null) ? $parsed['instructions'] : [],
                'airfryer_tips' => trim($parsed['airfryer_tips'] ?? ''),
                'image_prompts' => $imgPrompts
            ]
        ];
    }

    /* =========================================================================
     * VIDEO STORYBOARD GENERATOR ENGINE (GEMINI MULTI-SCENE)
     * ========================================================================= */

    public const KITCHEN_PRESETS = [
        'Modern Scandinavian Kitchen' => [
            'name' => 'Modern Scandinavian Kitchen',
            'desc' => 'Meja quartz putih bersih, kabinet light oak, air fryer matte black & copper, pencahayaan alami jendela samping.',
            'anchor' => 'Modern Scandinavian kitchen setting, pristine white quartz stone countertop, light oak wood cabinet accents, white subway tile backsplash, matte black digital 5.5L air fryer with copper handle, stainless steel tongs, minimalist clear glass prep bowls, soft diffused morning daylight from side window, clean airy aesthetics, ultra realistic 8k culinary photography, shallow depth of field.'
        ],
        'Rustic Industrial Chef Kitchen' => [
            'name' => 'Rustic Industrial Chef Kitchen',
            'desc' => 'Meja granit dark slate, talenan dark walnut tebal, air fryer brushed stainless steel, pencahayaan rim light sinematik.',
            'anchor' => 'Rustic industrial chef kitchen setting, textured dark slate granite countertop, thick dark walnut end-grain cutting board, subtle exposed red brick wall background, brushed stainless steel digital air fryer, Damascus steel chef knife with wooden handle, mini cast iron serving pan, warm cinematic 45-degree rim lighting, moody gourmet ambiance, dramatic culinary highlights, 8k.'
        ],
        'Cozy Warm Home Kitchen' => [
            'name' => 'Cozy Warm Home Kitchen',
            'desc' => 'Meja kayu butcher block hangat, toples bumbu kaca, air fryer charcoal bulat, nuansa hangat golden hour.',
            'anchor' => 'Cozy warm home kitchen setting, solid warm butcher block wooden countertop, glass spice jars lined up neatly, textured natural linen runner, rounded glossy charcoal air fryer, handcrafted artisan ceramic bowls and plates, pastel silicone-tipped cooking tongs, golden hour sunlight streaming in, authentic warm comfort food feeling, 8k.'
        ],
        'Futuristic Cyber Gourmet Studio' => [
            'name' => 'Futuristic Cyber Gourmet Studio',
            'desc' => 'Meja marmer hitam berkilau tinggi, air fryer display LED biru-putih modern, pencahayaan makro tajam dramatis.',
            'anchor' => 'Futuristic gourmet kitchen studio, high-gloss reflective black marble island, dark charcoal gradient background, modern air fryer with sleek cyan and white digital LED display, premium matte silicone spatulas, contemporary geometric plating dish, dramatic macro spotlight, high contrast, glossy crispy textures, magazine cover culinary aesthetic, 8k.'
        ],
        'Bright Parisian Cafe / Bistro' => [
            'name' => 'Bright Parisian Cafe / Bistro',
            'desc' => 'Meja marmer Carrara putih berurat abu-abu lembut, piring enamel bertepi biru, air fryer creamy beige retro-modern.',
            'anchor' => 'Bright Parisian bistro cafe kitchen setting, white Carrara marble tabletop with delicate gray veining, vintage enamel plates with classic blue rim, blue-checkered cotton kitchen napkin, retro-modern creamy beige air fryer with polished chrome rotary dials, vintage copper sauce pitcher, bright high-key airy illumination, French cookbook aesthetic, 8k.'
        ]
    ];

    /**
     * Resilient Waterfall Storyboard Generator:
     * Generates N Visual Actions, N T2I Start Frame Prompts (Pixel Locked), and N I2V Motion Prompts.
     */
    public function craftRecipeVideoStoryboardResilient(
        mysqli $db,
        array $recipeData,
        string $kitchenPresetKey = 'Modern Scandinavian Kitchen',
        string $aspectRatio = '9:16',
        string $mode = 'reels',
        array $options = []
    ): array {
        $primary = !empty($options['primary_model']) ? trim($options['primary_model']) : 'gemini-2.5-flash';
        $fallbacks = !empty($options['custom_fallbacks']) ? $options['custom_fallbacks'] : self::DEFAULT_CASCADE_ORDER;
        $maxRetries = isset($options['max_retries']) ? max(1, min(5, (int)$options['max_retries'])) : 2;
        $baseDelayMs = isset($options['base_delay_ms']) ? max(500, min(10000, (int)$options['base_delay_ms'])) : 2000;

        $waterfallChain = [$primary];
        foreach ($fallbacks as $m) {
            $m = trim($m);
            if (!empty($m) && !in_array($m, $waterfallChain, true)) {
                $waterfallChain[] = $m;
            }
        }
        foreach (self::DEFAULT_CASCADE_ORDER as $dm) {
            if (!in_array($dm, $waterfallChain, true)) {
                $waterfallChain[] = $dm;
            }
        }

        $cascadeTrail = [];
        $excludedKeyIds = [];
        $totalKeysRes = $db->query("SELECT COUNT(*) as c FROM `tbl_ai_keys` WHERE `is_active` = 1");
        $activeKeyCount = ($totalKeysRes && $r = $totalKeysRes->fetch_assoc()) ? (int)$r['c'] : 0;
        $maxKeyRotations = max(5, $activeKeyCount);
        $keyRotationCount = 0;
        @set_time_limit(300);

        while ($keyRotationCount < $maxKeyRotations) {
            $keyEntry = $this->getAvailableKey($db, $excludedKeyIds);

            if (!$keyEntry) {
                $cfgRes = $db->query("SELECT gemini_api_key FROM tbl_ai_config WHERE id=1 LIMIT 1");
                $fallbackApiKey = ($cfgRes && $r = $cfgRes->fetch_assoc()) ? trim($r['gemini_api_key'] ?? '') : '';
                if (!empty($fallbackApiKey)) {
                    $keyEntry = [
                        'id' => 0,
                        'key_name' => 'Single Config Key',
                        'api_key' => $fallbackApiKey
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'Tidak ada API Key Gemini yang aktif di pool. Silakan tambahkan API Key di tab Pengaturan.',
                        'cascade_trail' => $cascadeTrail
                    ];
                }
            }

            foreach ($waterfallChain as $modelName) {
                for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                    $cascadeTrail[] = [
                        'key_name' => $keyEntry['key_name'],
                        'model' => $modelName,
                        'attempt' => $attempt,
                        'time' => date('H:i:s')
                    ];

                    $targetLang = $options['target_language'] ?? 'auto';
                    $res = $this->craftRecipeVideoStoryboard(
                        $keyEntry['api_key'],
                        $recipeData,
                        $kitchenPresetKey,
                        $aspectRatio,
                        $mode,
                        $modelName,
                        $targetLang
                    );

                    if ($res['success']) {
                        if ($keyEntry['id'] > 0) {
                            $this->recordKeyUsage($db, $keyEntry['id']);
                        }
                        return [
                            'success' => true,
                            'data' => $res['data'],
                            'model_used' => $modelName,
                            'key_used' => $keyEntry['key_name'],
                            'cascade_trail' => $cascadeTrail
                        ];
                    }

                    $errCode = $res['error_code'] ?? 0;
                    $rawError = strtolower($res['error'] ?? '');

                    // 1. HTTP 404 / Model Not Found / Deprecated -> Instant Cascade to next model without retry delay
                    $is404 = ($errCode === 404) ||
                             strpos($rawError, '404') !== false || 
                             strpos($rawError, 'not found') !== false || 
                             strpos($rawError, 'no longer available') !== false || 
                             strpos($rawError, 'is not supported') !== false;
                    if ($is404) {
                        $cascadeTrail[] = [
                            'key_name' => $keyEntry['key_name'],
                            'model' => $modelName,
                            'status' => '404_NOT_FOUND',
                            'note' => 'Instant cascade ke model berikutnya'
                        ];
                        break; // Break inner retry loop instantly
                    }

                    if ($errCode === 429) {
                        $curIdx = array_search($modelName, $waterfallChain, true);
                        $hasNextModel = ($curIdx !== false && isset($waterfallChain[$curIdx + 1]));
                        if ($hasNextModel) {
                            break; // Try next model on same key!
                        } else {
                            if ($keyEntry['id'] > 0) {
                                $this->markKeyCooldown($db, $keyEntry['id'], 'COOLDOWN_429', 86400); // 24 jam cooldown (limit 20 RPD)
                                $excludedKeyIds[] = $keyEntry['id'];
                            }
                            break 2;
                        }
                    }

                    if ($attempt < $maxRetries) {
                        usleep($baseDelayMs * 1000);
                    }
                }
            }

            // Key rotation count incremented once per evaluated key
            $keyRotationCount++;
            if ($keyEntry['id'] > 0 && !in_array($keyEntry['id'], $excludedKeyIds, true)) {
                $excludedKeyIds[] = $keyEntry['id'];
            }
        }

        // Check if all active keys are cooling down
        $cdRes = $db->query("SELECT key_name, cooldown_until FROM `tbl_ai_keys` WHERE `is_active` = 1 AND `cooldown_until` > NOW() ORDER BY `cooldown_until` ASC");
        $coolingKeys = [];
        $earliestReset = null;
        if ($cdRes) {
            while ($cRow = $cdRes->fetch_assoc()) {
                $coolingKeys[] = $cRow['key_name'];
                if (!$earliestReset) {
                    $earliestReset = date('d M Y, H:i', strtotime($cRow['cooldown_until']));
                }
            }
        }

        if (!empty($coolingKeys)) {
            $errSummary = "Semua " . count($coolingKeys) . " API Key di Pool Gemini telah mencapai kuota harian (Limit 20 RPD habis) dan dalam masa cooldown 24 jam. Kunci tercepat aktif kembali: " . ($earliestReset ?: '24 jam lagi') . ". Tambahkan API Key baru di tab Pengaturan AI atau klik 'Reset Cooldown Pool'.";
        } else {
            $errSummary = 'Seluruh model dan API Key dalam waterfall cascade gagal merancang storyboard.';
        }

        return [
            'success' => false,
            'error' => $errSummary,
            'cascade_trail' => $cascadeTrail
        ];
    }

    /**
     * Single Gemini Call to generate video storyboard:
     * N Visual Actions (ID), N T2I Start Frame Prompts (EN Pixel Locked), N I2V Motion Prompts (EN).
     */
    public function craftRecipeVideoStoryboard(
        string $apiKey,
        array $recipeData,
        string $kitchenPresetKey = 'Modern Scandinavian Kitchen',
        string $aspectRatio = '9:16',
        string $mode = 'reels',
        string $model = 'gemini-2.5-flash',
        string $targetLanguage = 'auto'
    ): array {
        $presetInfo = self::KITCHEN_PRESETS[$kitchenPresetKey] ?? self::KITCHEN_PRESETS['Modern Scandinavian Kitchen'];
        $masterAnchor = $presetInfo['anchor'];

        $title = trim($recipeData['recipe_title'] ?? ($recipeData['title'] ?? 'Air Fryer Recipe'));
        $directions = $recipeData['directions'] ?? ($recipeData['instructions'] ?? []);
        $ingredients = $recipeData['ingredients'] ?? [];

        $directionsText = '';
        if (is_array($directions)) {
            foreach ($directions as $idx => $step) {
                $stepText = is_array($step) ? ($step['step'] ?? json_encode($step)) : (string)$step;
                $directionsText .= ($idx + 1) . ". " . trim($stepText) . "\n";
            }
        } else {
            $directionsText = (string)$directions;
        }

        $ingredientsText = '';
        if (is_array($ingredients)) {
            foreach ($ingredients as $ing) {
                $ingText = is_array($ing) ? ($ing['item'] ?? json_encode($ing)) : (string)$ing;
                $ingredientsText .= "- " . trim($ingText) . "\n";
            }
        } else {
            $ingredientsText = (string)$ingredients;
        }

        $sceneCountRule = ($mode === 'full')
            ? "STRICT 1:1 RULE: Generate exactly 1 scene for EACH cooking direction step in exact sequence. Do not skip, merge, or omit any step."
            : "Condense and synthesize the directions into 4 to 6 punchy, dynamic video scenes ideal for high-engagement vertical Reels/TikTok: Scene 1: Hook & Fresh Ingredients, Scene 2: Seasoning & Marination Action, Scene 3: Air Fryer Basket Placement & Sizzle Action, Scene 4: Golden Crisp Pull / Flip, Scene 5: Final Plating & Bite-Ready Garnish.";

        $langInstruction = "the SAME LANGUAGE as the recipe (English for English recipes, Indonesian for Indonesian recipes)";
        if ($targetLanguage === 'en') {
            $langInstruction = "ENGLISH language";
        } elseif ($targetLanguage === 'id') {
            $langInstruction = "INDONESIAN language";
        }

        $systemPrompt = <<<PROMPT
You are an award-winning culinary commercial video director and AI prompt engineer specializing in gourmet Air Fryer recipe videos.
Your task is to design a cohesive, cinematic multi-scene storyboard for a recipe video based strictly on the provided cooking directions.

RECIPE INFORMATION:
Title: {$title}
Ingredients:
{$ingredientsText}

Cooking Directions (Numbered in Chronological Order):
{$directionsText}

MASTER PIXEL-LOCK ANCHOR CONTEXT:
"{$masterAnchor}"
Aspect Ratio: {$aspectRatio}
Storyboard Mode: {$mode} ({$sceneCountRule})

CRITICAL CHRONOLOGY & STEP-FIDELITY RULES (MUST STRICTLY FOLLOW):
1. STRICT STEP-TO-SCENE ACTION FIDELITY:
   - For each scene, `visual_action` and `prompt_t2i` MUST capture the EXACT INITIAL PHYSICAL ACTION described in that specific step, NOT the final result after cooking!
   - DO NOT JUMP AHEAD IN TIME:
     * If the step says "Arrange raw wings/meat/items into the preheated air fryer basket in a single layer and cook for 25 mins", the visual action and Start Frame MUST depict RAW seasoned pieces being placed into the basket. It MUST NOT show already-cooked, golden-brown crispy food!
     * If the step says "Transfer wings to a bowl with glaze and toss until coated", the visual action and Start Frame MUST show food inside the bowl being tossed/coated with glistening glaze, NOT inside the air fryer!
     * If the step says "Preheat air fryer and pat chicken dry with paper towels", the visual action MUST show paper towels patting the raw food dry on a cutting board.
     * If the step says "Whisk honey, soy sauce, vinegar in a bowl", the visual action MUST show the whisking of the sauce in that bowl.
2. RAW vs COOKED STATE FIDELITY:
   - Early preparation & basket loading steps MUST depict RAW or freshly marinated uncooked ingredients.
   - Sizzling, bubbling, and browning only occur during intermediate cooking steps.
   - Crispy golden finish only appears at the flip/pull and final plating/garnishing steps.
3. INCLUDE SPECIFIC INGREDIENTS & UTENSILS:
   - Explicitly include ingredients and utensils mentioned in that step.

TASK REQUIREMENTS:
For each scene, you MUST generate:
1. `scene_order`: Integer index (1, 2, 3...)
2. `direction_source`: The exact cooking direction text this scene is based on.
3. `visual_action`: A concise, punchy on-screen cooking step instruction written in {$langInstruction} for the video subtitle/caption.
   - MUST be written as a DIRECT IMPERATIVE / ACTION STEP for the viewer (5 to 8 words maximum).
   - If English: e.g. "Fill pot with water & boil", "Whisk honey and mustard until smooth", "Add potatoes to boiling water", "Place salmon in air fryer & glaze", "Plate salmon and serve with salad".
   - If Indonesian: e.g. "Didihkan air dalam panci", "Campur madu & mustard, aduk rata", "Masukkan kentang ke air mendidih", "Tata salmon di keranjang air fryer", "Sajikan salmon bersama salad".
   - CRITICAL CONSTRAINT: NEVER write third-person camera observations such as "Tangan mengisi...", "Tangan mengaduk...", "Hands placing...", "A hand pours...", or camera angle descriptions! Write only direct cooking instructions.
4. `prompt_t2i`: An ultra-detailed photorealistic culinary image prompt in ENGLISH for the Start Frame.
   - Describe the exact food state at this stage (raw, marinated, sizzling in basket, golden crispy, glazed, garnished).
   - CRITICAL PIXEL LOCK RULE: You MUST append the exact Master Anchor Context ("{$masterAnchor}") at the end of every prompt_t2i so that the air fryer appliance, tabletop surface, lighting, and utensils stay 100% IDENTICAL across all scenes!
5. `prompt_i2v`: A dedicated motion prompt in ENGLISH tailored for Image-to-Video models (Veo Omni Flash / Kling AI).
   - Describe camera motion (e.g. "extreme macro push in", "slow pan left", "gentle low angle tilt up").
   - Describe active dynamic motion (e.g. "tongs carefully placing piece in slow motion", "glaze swirling smoothly", "steam rising gently").
   - Keep prompt_i2v concise and focused on MOTION, between 15 and 35 words.

OUTPUT FORMAT:
Respond ONLY with a valid, clean JSON object with this exact structure:
{
  "master_anchor": "{$masterAnchor}",
  "kitchen_preset": "{$kitchenPresetKey}",
  "total_scenes": <number of scenes>,
  "scenes": [
    {
      "scene_order": 1,
      "direction_source": "...",
      "visual_action": "...",
      "prompt_t2i": "...",
      "prompt_i2v": "..."
    }
  ]
}
PROMPT;

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'topP' => 0.85,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json'
            ]
        ];

        $t0 = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
        }

        if ($httpCode !== 200) {
            $errData = json_decode($response, true);
            $errMsg = $errData['error']['message'] ?? "HTTP {$httpCode}";
            return [
                'success' => false,
                'error' => "Gemini API Error ({$model}): {$errMsg}",
                'error_code' => $httpCode
            ];
        }

        $resJson = json_decode($response, true);
        $rawText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (empty($rawText)) {
            return ['success' => false, 'error' => 'Gemini tidak mengembalikan teks output.'];
        }

        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed)) {
            // Attempt auto-repair on trailing commas before closing braces/brackets
            $repaired = preg_replace('/,\s*([\]}])/m', '$1', $cleaned);
            $parsed = json_decode($repaired, true);
        }
        if (!is_array($parsed)) {
            // Strip rogue unprintable control characters except standard whitespace
            $repaired = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);
            $parsed = json_decode($repaired, true);
        }

        if (!is_array($parsed) || empty($parsed['scenes'])) {
            return ['success' => false, 'error' => 'Format JSON Storyboard tidak valid dari Gemini: ' . substr($cleaned, 0, 150)];
        }

        return [
            'success' => true,
            'data' => [
                'master_anchor' => $parsed['master_anchor'] ?? $masterAnchor,
                'kitchen_preset' => $parsed['kitchen_preset'] ?? $kitchenPresetKey,
                'total_scenes' => count($parsed['scenes']),
                'scenes' => $parsed['scenes']
            ]
        ];
    }
}


