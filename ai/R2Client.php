<?php

namespace Ai;

use mysqli;
use Throwable;

class R2Client
{
    private ?string $accountId;
    private ?string $accessKeyId;
    private ?string $secretAccessKey;
    private string $bucketName;
    private string $publicDomain;
    private bool $isEnabled;

    public function __construct(
        ?string $accountId = null,
        ?string $accessKeyId = null,
        ?string $secretAccessKey = null,
        string $bucketName = 'airfry-videos',
        string $publicDomain = 'https://video.airfry.web.id',
        bool $isEnabled = false
    ) {
        $this->accountId = $accountId;
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        $this->bucketName = trim($bucketName);
        $this->publicDomain = rtrim(trim($publicDomain), '/');
        $this->isEnabled = $isEnabled;
    }

    /**
     * Factory loader from database tbl_ai_config.
     */
    public static function fromDatabase(mysqli $db): self
    {
        try {
            $res = $db->query("SELECT r2_enabled, r2_account_id, r2_access_key_id, r2_secret_access_key, r2_bucket_name, r2_public_domain FROM tbl_ai_config WHERE id = 1 LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                return new self(
                    $row['r2_account_id'] ?? null,
                    $row['r2_access_key_id'] ?? null,
                    $row['r2_secret_access_key'] ?? null,
                    $row['r2_bucket_name'] ?: 'airfry-videos',
                    $row['r2_public_domain'] ?: 'https://video.airfry.web.id',
                    (bool)($row['r2_enabled'] ?? false)
                );
            }
        } catch (Throwable $e) {
            error_log("[R2Client::fromDatabase] " . $e->getMessage());
        }

        return new self();
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled && !empty($this->accountId) && !empty($this->accessKeyId) && !empty($this->secretAccessKey);
    }

    public function getBucketName(): string
    {
        return $this->bucketName;
    }

    public function getPublicDomain(): string
    {
        return $this->publicDomain;
    }

    /**
     * Test connection to Cloudflare R2 bucket.
     */
    public function testConnection(): array
    {
        if (empty($this->accountId) || empty($this->accessKeyId) || empty($this->secretAccessKey)) {
            return [
                'success' => false,
                'error' => 'Kredensial Cloudflare R2 belum lengkap. Mohon isi Account ID, Access Key ID, dan Secret Access Key.'
            ];
        }

        if (empty($this->bucketName)) {
            return [
                'success' => false,
                'error' => 'Nama R2 Bucket wajib diisi.'
            ];
        }

        $startTime = microtime(true);
        $host = "{$this->accountId}.r2.cloudflarestorage.com";
        $uri = "/{$this->bucketName}";
        $queryString = "max-keys=1";

        try {
            $headers = $this->signV4('GET', $uri, $queryString, '', ['host' => $host]);

            $url = "https://{$host}{$uri}?{$queryString}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $latency = round((microtime(true) - $startTime) * 1000);

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'latency_ms' => $latency,
                    'message' => "Koneksi ke Cloudflare R2 Sukses! Bucket '{$this->bucketName}' siap digunakan ({$latency}ms)."
                ];
            }

            if ($httpCode === 403) {
                return [
                    'success' => false,
                    'http_code' => $httpCode,
                    'error' => "Autentikasi Cloudflare R2 ditolak (HTTP 403). Periksa Access Key ID & Secret Access Key Anda."
                ];
            }

            if ($httpCode === 404) {
                return [
                    'success' => false,
                    'http_code' => $httpCode,
                    'error' => "Bucket '{$this->bucketName}' tidak ditemukan di akun R2 Anda (HTTP 404)."
                ];
            }

            return [
                'success' => false,
                'http_code' => $httpCode,
                'error' => $curlError ?: "Gagal terhubung ke Cloudflare R2 (HTTP {$httpCode}): " . strip_tags($response)
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Exception R2 test: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload video MP4 file to Cloudflare R2 bucket.
     */
    public function uploadVideo(string $localFilePath, string $remoteFileName, string $mimeType = 'video/mp4'): array
    {
        if (!file_exists($localFilePath)) {
            return ['success' => false, 'error' => "File lokal tidak ditemukan: {$localFilePath}"];
        }

        if (empty($this->accountId) || empty($this->accessKeyId) || empty($this->secretAccessKey)) {
            return ['success' => false, 'error' => 'Kredensial Cloudflare R2 belum dikonfigurasi.'];
        }

        $remoteKey = ltrim($remoteFileName, '/');
        $host = "{$this->accountId}.r2.cloudflarestorage.com";
        $uri = "/{$this->bucketName}/{$remoteKey}";

        $fileSize = filesize($localFilePath);
        $payloadHash = hash_file('sha256', $localFilePath);

        $extraHeaders = [
            'host' => $host,
            'content-type' => $mimeType,
            'content-length' => (string)$fileSize
        ];

        $headers = $this->signV4('PUT', $uri, '', $payloadHash, $extraHeaders, true);

        $fp = fopen($localFilePath, 'rb');
        if (!$fp) {
            return ['success' => false, 'error' => 'Gagal membaca berkas video lokal.'];
        }

        $url = "https://{$host}{$uri}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode === 200 || $httpCode === 204) {
            $publicUrl = "{$this->publicDomain}/{$remoteKey}";
            return [
                'success' => true,
                'remote_key' => $remoteKey,
                'public_url' => $publicUrl,
                'message' => 'Video berhasil diunggah ke Cloudflare R2 CDN.'
            ];
        }

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $curlError ?: "Gagal upload video ke R2 (HTTP {$httpCode}): " . strip_tags($response)
        ];
    }

    /**
     * Implementation of AWS SigV4 authorization for Cloudflare R2.
     */
    private function signV4(
        string $method,
        string $uri,
        string $queryString,
        string $payloadHashOrContent,
        array $headersMap,
        bool $isPrecalculatedHash = false
    ): array {
        $region = 'auto';
        $service = 's3';

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $payloadHash = $isPrecalculatedHash ? $payloadHashOrContent : hash('sha256', $payloadHashOrContent);

        $headersMap['x-amz-date'] = $amzDate;
        $headersMap['x-amz-content-sha256'] = $payloadHash;

        // Canonical headers (lowercase, sorted by name)
        ksort($headersMap);
        $canonicalHeaders = '';
        $signedHeadersList = [];

        foreach ($headersMap as $k => $v) {
            $lowerKey = strtolower($k);
            $canonicalHeaders .= $lowerKey . ':' . trim($v) . "\n";
            $signedHeadersList[] = $lowerKey;
        }

        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        ]);

        // Signing key calculation
        $kSecret = 'AWS4' . $this->secretAccessKey;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $outHeaders = [];
        foreach ($headersMap as $k => $v) {
            $outHeaders[] = "{$k}: {$v}";
        }
        $outHeaders[] = "Authorization: {$authorization}";

        return $outHeaders;
    }
}
