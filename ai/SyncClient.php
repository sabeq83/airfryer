<?php

namespace Ai;

use mysqli;
use CURLFile;
use Exception;

class SyncClient
{
    private mysqli $db;
    public const DEFAULT_TARGET_URL = 'https://airfry.web.id/app/api_sync_recipe.php';
    public const DEFAULT_SECRET_TOKEN = 'airfry_sync_sec_99a8b7c6d5e4f3a2b1';

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->ensureConfigColumns();
    }

    private function ensureConfigColumns(): void
    {
        $cols = [
            'sync_target_url' => "VARCHAR(255) NOT NULL DEFAULT '" . self::DEFAULT_TARGET_URL . "'",
            'sync_secret_token' => "VARCHAR(255) NOT NULL DEFAULT '" . self::DEFAULT_SECRET_TOKEN . "'"
        ];
        foreach ($cols as $col => $def) {
            $check = $this->db->query("SHOW COLUMNS FROM `tbl_ai_config` LIKE '{$col}'");
            if ($check && $check->num_rows === 0) {
                $this->db->query("ALTER TABLE `tbl_ai_config` ADD COLUMN `{$col}` {$def}");
            }
        }
    }

    public function getTargetUrl(): string
    {
        $res = $this->db->query("SELECT sync_target_url FROM tbl_ai_config WHERE id = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $url = trim($row['sync_target_url'] ?? '');
            if (!empty($url)) {
                return $url;
            }
        }
        return self::DEFAULT_TARGET_URL;
    }

    public function getSecretToken(): string
    {
        $res = $this->db->query("SELECT sync_secret_token FROM tbl_ai_config WHERE id = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $tok = trim($row['sync_secret_token'] ?? '');
            if (!empty($tok)) {
                return $tok;
            }
        }
        return self::DEFAULT_SECRET_TOKEN;
    }

    public function setConfig(string $targetUrl, string $secretToken): bool
    {
        $targetUrl = trim($targetUrl);
        $secretToken = trim($secretToken);
        $stmt = $this->db->prepare("UPDATE tbl_ai_config SET sync_target_url = ?, sync_secret_token = ? WHERE id = 1");
        if ($stmt) {
            $stmt->bind_param('ss', $targetUrl, $secretToken);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        }
        return false;
    }

    /**
     * Test ping to live receiver endpoint.
     */
    public function pingLive(): array
    {
        $url = $this->getTargetUrl();
        $token = $this->getSecretToken();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'action' => 'ping',
                'sync_token' => $token
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => "Gagal terhubung ke live hosting ({$url}): {$err}"];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['success'])) {
            return $data;
        }

        $msg = $data['error'] ?? ("HTTP {$httpCode}");
        return ['success' => false, 'error' => "Live hosting menolak koneksi: {$msg}"];
    }

    /**
     * Push a local recipe + image to live shared hosting.
     */
    public function pushRecipeToLive(int $recipeId): array
    {
        if ($recipeId <= 0) {
            return ['success' => false, 'error' => 'ID resep tidak valid.'];
        }

        // Fetch local recipe details
        $stmt = $this->db->prepare("SELECT r.*, c.category_name 
            FROM `tbl_recipes` r 
            LEFT JOIN `tbl_category` c ON r.cat_id = c.cid 
            WHERE r.recipe_id = ? LIMIT 1");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $this->db->error];
        }

        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $recipe = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$recipe) {
            return ['success' => false, 'error' => "Resep dengan ID #{$recipeId} tidak ditemukan di database lokal."];
        }

        $url = $this->getTargetUrl();
        $token = $this->getSecretToken();

        $postFields = [
            'action' => 'push_recipe',
            'sync_token' => $token,
            'local_recipe_id' => $recipeId,
            'recipe_title' => $recipe['recipe_title'] ?? '',
            'recipe_time' => $recipe['recipe_time'] ?? '',
            'recipe_description' => $recipe['recipe_description'] ?? '',
            'category_name' => $recipe['category_name'] ?? 'Air Fryer',
            'content_type' => $recipe['content_type'] ?? 'Post',
            'video_url' => $recipe['video_url'] ?? '',
            'video_id' => $recipe['video_id'] ?? '',
            'tags' => $recipe['tags'] ?? '',
            'size' => $recipe['size'] ?? ''
        ];

        // Attach image if exists locally
        $imgName = trim($recipe['recipe_image'] ?? '');
        $imagePath = dirname(__DIR__) . '/upload/' . $imgName;
        if (!empty($imgName) && file_exists($imagePath) && is_file($imagePath)) {
            $mime = 'image/jpeg';
            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($imagePath) ?: 'image/jpeg';
            }
            $postFields['image_file'] = new CURLFile($imagePath, $mime, basename($imagePath));
        }

        // Attach video if exists locally
        $videoField = trim($recipe['video_url'] ?? '');
        $videoFilename = basename($videoField);
        $videoPath = dirname(__DIR__) . '/upload/video/' . $videoFilename;
        $hasVideoFile = false;

        if (!empty($videoFilename) && !filter_var($videoField, FILTER_VALIDATE_URL) && file_exists($videoPath) && is_file($videoPath)) {
            $vMime = 'video/mp4';
            if (function_exists('mime_content_type')) {
                $vMime = mime_content_type($videoPath) ?: 'video/mp4';
            }
            $postFields['video_file'] = new CURLFile($videoPath, $vMime, $videoFilename);
            $postFields['video_url'] = $videoFilename; // Ensure clean filename without path prefix
            $hasVideoFile = true;

            // Compute size if not already set
            if (empty($postFields['size'])) {
                $bytes = @filesize($videoPath) ?: 0;
                if ($bytes >= 1073741824) {
                    $postFields['size'] = number_format($bytes / 1073741824, 2) . ' GB';
                } else if ($bytes >= 1048576) {
                    $postFields['size'] = number_format($bytes / 1048576, 2) . ' MB';
                } else if ($bytes >= 1024) {
                    $postFields['size'] = number_format($bytes / 1024, 2) . ' KB';
                }
            }
        }

        // Dynamically adjust timeout based on whether video file is attached
        $curlTimeout = $hasVideoFile ? 300 : 60;
        @set_time_limit($curlTimeout + 60);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $curlTimeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => "Gagal mengirim data ke live hosting: {$err}"];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['success'])) {
            $liveId = (int)($data['live_recipe_id'] ?? 0);

            // Update local tracking
            $now = date('Y-m-d H:i:s');
            $upStmt = $this->db->prepare("UPDATE `tbl_recipes` SET `live_recipe_id` = ?, `last_synced_at` = ? WHERE `recipe_id` = ?");
            if ($upStmt) {
                $upStmt->bind_param('isi', $liveId, $now, $recipeId);
                $upStmt->execute();
                $upStmt->close();
            }

            return [
                'success' => true,
                'action' => $data['action'] ?? 'synced',
                'live_recipe_id' => $liveId,
                'recipe_title' => $recipe['recipe_title'],
                'video_uploaded' => !empty($data['video_uploaded']) || $hasVideoFile,
                'video_filename' => $data['video_filename'] ?? ($hasVideoFile ? $videoFilename : ''),
                'message' => $data['message'] ?? "Resep berhasil diterbitkan ke live hosting (ID: #{$liveId})!"
            ];
        }

        $msg = $data['error'] ?? ("HTTP {$httpCode}");
        return ['success' => false, 'error' => "Live hosting menolak: {$msg}"];
    }
}
