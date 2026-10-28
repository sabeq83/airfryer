<?php

namespace Importer;

use Exception;

class ImageDownloader
{
    private static array $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Downloads an image from an HTTP(S) URL and saves it into the upload directory.
     * Returns the generated unique filename.
     *
     * @param string $imageUrl
     * @param string $uploadDir
     * @param int $maxBytes
     * @return string Filename inside uploadDir
     * @throws Exception
     */
    public static function download(string $imageUrl, string $uploadDir, int $maxBytes = 10485760): string
    {
        $imageUrl = trim($imageUrl);
        if (empty($imageUrl)) {
            throw new Exception('URL gambar kosong.');
        }

        // Basic validation of URL
        $scheme = strtolower(parse_url($imageUrl, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception('Protokol gambar tidak valid (hanya http dan https).');
        }

        // Check SSRF
        $host = parse_url($imageUrl, PHP_URL_HOST);
        if (!$host) {
            throw new Exception('Host gambar tidak valid.');
        }

        $ips = gethostbynamel($host);
        if ($ips) {
            foreach ($ips as $ip) {
                if (UrlValidator::isPrivateOrReservedIp($ip)) {
                    throw new Exception('Alamat IP gambar terlarang (SSRF protection).');
                }
            }
        }

        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            throw new Exception('Folder tujuan upload (' . htmlspecialchars($uploadDir, ENT_QUOTES, 'UTF-8') . ') tidak ditemukan atau tidak dapat ditulisi.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 (RecipeImporter/1.0)',
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower(trim(explode(';', curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '')[0]));
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($data === false || $httpCode !== 200) {
            throw new Exception('Gagal mengunduh gambar dari URL (HTTP ' . $httpCode . '): ' . $curlError);
        }

        if (strlen($data) > $maxBytes) {
            throw new Exception('Ukuran gambar melebihi batas maksimum (' . round($maxBytes / 1048576, 1) . ' MB).');
        }

        // Detect MIME type safely using finfo or getimagesizefromstring
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower($finfo->buffer($data));

        if (!isset(self::$allowedMimes[$detectedMime])) {
            // Check fallback with getimagesize
            $imageInfo = @getimagesizefromstring($data);
            if ($imageInfo && isset($imageInfo['mime']) && isset(self::$allowedMimes[$imageInfo['mime']])) {
                $detectedMime = $imageInfo['mime'];
            } else {
                throw new Exception('Tipe berkas gambar tidak didukung atau bukan gambar valid (terdeteksi: ' . htmlspecialchars($detectedMime, ENT_QUOTES, 'UTF-8') . ').');
            }
        }

        $extension = self::$allowedMimes[$detectedMime];
        $filename = time() . '_' . uniqid() . '.' . $extension;
        $targetPath = rtrim($uploadDir, '/') . '/' . $filename;

        if (file_put_contents($targetPath, $data) === false) {
            throw new Exception('Gagal menyimpan file gambar ke: ' . $targetPath);
        }

        // Otomatis optimasi gambar impor (Progressive JPEG max 1080px @ 82%)
        if (!class_exists('ImageOptimizer') && file_exists(__DIR__ . '/../includes/ImageOptimizer.php')) {
            require_once __DIR__ . '/../includes/ImageOptimizer.php';
        }
        if (class_exists('ImageOptimizer')) {
            \ImageOptimizer::optimize($targetPath, 1080, 82);
        }

        return $filename;
    }

    /**
     * Safely processes an uploaded $_FILES entry and saves it to uploadDir.
     */
    public static function saveUploadedFile(array $filePost, string $uploadDir): string
    {
        if (!isset($filePost['tmp_name']) || empty($filePost['tmp_name']) || $filePost['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload berkas gagal (kode error: ' . ($filePost['error'] ?? 'tidak diketahui') . ').');
        }

        $tmpPath = $filePost['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            throw new Exception('Berkas yang diunggah tidak valid.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower($finfo->file($tmpPath));

        if (!isset(self::$allowedMimes[$detectedMime])) {
            throw new Exception('Tipe berkas yang diunggah tidak diizinkan.');
        }

        $extension = self::$allowedMimes[$detectedMime];
        $filename = time() . '_' . uniqid() . '.' . $extension;
        $targetPath = rtrim($uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new Exception('Gagal memindahkan berkas yang diunggah ke folder upload.');
        }

        // Otomatis optimasi berkas yang diunggah
        if (!class_exists('ImageOptimizer') && file_exists(__DIR__ . '/../includes/ImageOptimizer.php')) {
            require_once __DIR__ . '/../includes/ImageOptimizer.php';
        }
        if (class_exists('ImageOptimizer')) {
            \ImageOptimizer::optimize($targetPath, 1080, 82);
        }

        return $filename;
    }
}
