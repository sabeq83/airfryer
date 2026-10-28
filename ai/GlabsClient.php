<?php

namespace Ai;

class GlabsClient
{
    /**
     * Build base URL cleanly handling http/https, hostnames, and ports.
     */
    public function buildBaseUrl(string $host, int $port = 8765): string
    {
        $host = trim($host);
        if (empty($host)) {
            $host = '127.0.0.1';
        }

        $scheme = null;
        if (strncmp(strtolower($host), 'https://', 8) === 0) {
            $scheme = 'https';
            $host = substr($host, 8);
        } elseif (strncmp(strtolower($host), 'http://', 7) === 0) {
            $scheme = 'http';
            $host = substr($host, 7);
        }

        $host = trim($host, '/');

        // Check if port was included in host string (e.g. 192.168.1.50:8765 or domain.com:443)
        if (strpos($host, ':') !== false) {
            $parts = explode(':', $host, 2);
            $host = $parts[0];
            $customPort = (int)$parts[1];
            if ($customPort > 0) {
                $port = $customPort;
            }
        }

        // Auto-detect scheme and port for cloud tunnels (Cloudflare & Tailscale Funnel)
        $isCloudTunnel = strpos(strtolower($host), '.trycloudflare.com') !== false
                      || strpos(strtolower($host), '.ts.net') !== false
                      || strpos(strtolower($host), 'cloudflare') !== false;

        if ($isCloudTunnel) {
            $scheme = 'https';
            $port = 443;
        }

        if ($scheme === null) {
            if ($port === 443) {
                $scheme = 'https';
            } else {
                $scheme = 'http';
            }
        }

        // Port 443 is universally HTTPS
        if ($port === 443) {
            $scheme = 'https';
        }

        // Standard ports omit port suffix in URL
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return "{$scheme}://{$host}";
        }

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * Check if the G-Labs Webhook server is alive and reachable.
     */
    public function checkHealth(string $host, int $port = 8765): array
    {
        $baseUrl = $this->buildBaseUrl($host, $port);
        $url = "{$baseUrl}/api/health";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => "Tidak dapat terhubung ke G-Labs ({$baseUrl}): {$curlError}. Pastikan aplikasi desktop G-Labs aktif dan tombol Start di tab Webhook sudah ditekan."
            ];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true) ?: [];
            return [
                'success' => true,
                'message' => "Server G-Labs aktif ({$baseUrl})!",
                'data' => $data
            ];
        }

        if ($httpCode === 502) {
            return [
                'success' => false,
                'error' => "Tailscale Funnel aktif ({$baseUrl}), tetapi merespons HTTP 502 Bad Gateway. Pastikan proxy Tailscale di server Windows diarahkan ke http://127.0.0.1:8765 dengan menjalankan: tailscale funnel reset && tailscale funnel --bg http://127.0.0.1:8765"
            ];
        }

        return [
            'success' => false,
            'error' => "G-Labs merespons dengan HTTP {$httpCode}"
        ];
    }

    /**
     * Queue an image generation task. Returns task_id immediately (HTTP 202).
     */
    public function submitImageTask(string $host, int $port, string $apiKey, array $params): array
    {
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'G-Labs API Key belum diatur di tab Pengaturan.'];
        }

        $prompt = trim($params['prompt'] ?? '');
        if (empty($prompt)) {
            return ['success' => false, 'error' => 'Prompt tidak boleh kosong.'];
        }

        $model = $params['model'] ?? 'nano_banana_2';
        $aspectRatio = $params['aspect_ratio'] ?? '4:3';

        $body = [
            'prompt' => $prompt,
            'model' => $model,
            'aspect_ratio' => $aspectRatio,
        ];

        if (!empty($params['reference_images']) && is_array($params['reference_images'])) {
            $body['reference_images'] = $params['reference_images'];
        }
        if (!empty($params['upscale']) && is_array($params['upscale'])) {
            $body['upscale'] = $params['upscale'];
        }

        $baseUrl = $this->buildBaseUrl($host, $port);
        $url = "{$baseUrl}/api/image/generate";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => "Gagal kirim task ke G-Labs ({$baseUrl}): {$curlError}"];
        }

        $data = json_decode($response, true) ?: [];

        if ($httpCode === 202 || ($httpCode >= 200 && $httpCode < 300)) {
            if (!empty($data['task_id'])) {
                return [
                    'success' => true,
                    'task_id' => $data['task_id'],
                    'status' => $data['status'] ?? 'pending',
                    'message' => $data['message'] ?? 'Task berhasil diantrekan di G-Labs.',
                    'poll_url' => $data['poll_url'] ?? ("/api/status/" . $data['task_id'])
                ];
            }
        }

        $errMsg = $data['error'] ?? ($data['message'] ?? "HTTP {$httpCode} dari G-Labs");
        return ['success' => false, 'error' => "G-Labs menolak task: {$errMsg}"];
    }

    /**
     * Poll task status (pending, running, completed, failed).
     */
    public function getTaskStatus(string $host, int $port, string $apiKey, string $taskId): array
    {
        $baseUrl = $this->buildBaseUrl($host, $port);
        $url = "{$baseUrl}/api/status/" . urlencode($taskId);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . trim($apiKey)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => "Gagal cek status task ({$taskId}): {$curlError}"];
        }

        $data = json_decode($response, true) ?: [];

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'task_id' => $data['task_id'] ?? $taskId,
                'status' => strtolower($data['status'] ?? 'pending'),
                'results' => $data['results'] ?? ($data['files'] ?? []),
                'error' => $data['error'] ?? null,
                'error_detail' => $data['error_detail'] ?? null,
                'created_at' => $data['created_at'] ?? null,
                'completed_at' => $data['completed_at'] ?? null
            ];
        }

        $errMsg = $data['error'] ?? ("HTTP {$httpCode}");
        return ['success' => false, 'error' => "Error cek status: {$errMsg}"];
    }

    /**
     * Download generated file from G-Labs and save locally into target path.
     */
    public function downloadFile(string $host, int $port, string $fileUrlOrName, string $destPath, string $apiKey = ''): array
    {
        $baseUrl = $this->buildBaseUrl($host, $port);

        // If G-Labs returned an absolute URL (e.g. http://127.0.0.1:8765/api/files/... or http://100.64.70.61:8765/api/files/...) rewrite to our baseUrl
        if (strncmp($fileUrlOrName, 'http://', 7) === 0 || strncmp($fileUrlOrName, 'https://', 8) === 0) {
            $parsed = parse_url($fileUrlOrName);
            $path = $parsed['path'] ?? '';
            if (empty($path)) {
                $path = '/api/files/' . basename($fileUrlOrName);
            }
            $fileUrl = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
            if (!empty($parsed['query'])) {
                $fileUrl .= '?' . $parsed['query'];
            }
        } else {
            $fileName = basename($fileUrlOrName);
            $fileUrl = rtrim($baseUrl, '/') . '/api/files/' . rawurlencode($fileName);
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $headers = [];
        if (!empty($apiKey)) {
            $headers[] = 'X-API-Key: ' . trim($apiKey);
        }

        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $fileUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        if (!ini_get('open_basedir')) {
            $curlOpts[CURLOPT_FOLLOWLOCATION] = true;
        }
        curl_setopt_array($ch, $curlOpts);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($imageData === false || $httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'error' => "Gagal download file ({$fileUrl}): " . ($curlError ?: "HTTP {$httpCode}")
            ];
        }

        if (empty($imageData)) {
            return [
                'success' => false,
                'error' => "File gambar yang diunduh kosong (0 bytes) dari {$fileUrl}."
            ];
        }

        $written = @file_put_contents($destPath, $imageData);
        if ($written === false) {
            return [
                'success' => false,
                'error' => "Gagal menyimpan file ke: {$destPath}. Periksa permission folder upload (chmod 755 / 777)."
            ];
        }

        return [
            'success' => true,
            'file_path' => $destPath,
            'file_size' => $written
        ];
    }

    /**
     * Queue a video generation task (Image-to-Video / Text-to-Video).
     * Sesuai spesifikasi G-Labs POST /api/video/generate
     * Model default: omni_flash
     * Mode default: start_image
     * Video length: 4, 6, 8, 10 (omni_flash supports 4s, 6s, 8s, 10s)
     * Aspect ratio: 9:16, 16:9
     * Resolution: ["720p"] (or ["1080p"])
     */
    public function submitVideoTask(string $host, int $port, string $apiKey, array $params): array
    {
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'G-Labs API Key belum diatur di tab Pengaturan.'];
        }

        $prompt = trim($params['prompt'] ?? '');
        if (empty($prompt)) {
            return ['success' => false, 'error' => 'Prompt video tidak boleh kosong.'];
        }

        $model = $params['model'] ?? 'omni_flash';
        $aspectRatio = $params['aspect_ratio'] ?? '9:16';
        $mode = $params['mode'] ?? 'start_image';
        $videoLength = (int)($params['video_length'] ?? 4);
        if (!in_array($videoLength, [4, 6, 8, 10])) {
            $videoLength = 4;
        }

        $body = [
            'prompt' => $prompt,
            'model' => $model,
            'aspect_ratio' => $aspectRatio,
            'mode' => $mode,
            'video_length' => $videoLength,
            'resolution' => $params['resolution'] ?? ['720p']
        ];

        if (!empty($params['reference_images']) && is_array($params['reference_images'])) {
            $body['reference_images'] = $params['reference_images'];
        }

        $baseUrl = $this->buildBaseUrl($host, $port);
        $url = "{$baseUrl}/api/video/generate";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => "Gagal kirim task video ke G-Labs ({$baseUrl}): {$curlError}"];
        }

        $data = json_decode($response, true) ?: [];

        if ($httpCode === 202 || ($httpCode >= 200 && $httpCode < 300)) {
            if (!empty($data['task_id'])) {
                return [
                    'success' => true,
                    'task_id' => $data['task_id'],
                    'status' => $data['status'] ?? 'pending',
                    'message' => $data['message'] ?? 'Task video berhasil diantrekan di G-Labs.',
                    'poll_url' => $data['poll_url'] ?? ("/api/status/" . $data['task_id'])
                ];
            }
        }

        $errMsg = $data['error'] ?? ($data['message'] ?? "HTTP {$httpCode} dari G-Labs");
        return ['success' => false, 'error' => "G-Labs menolak task video: {$errMsg}"];
    }
}

