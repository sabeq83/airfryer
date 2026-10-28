<?php

/**
 * ImageOptimizer Helper
 * 
 * Standar optimasi gambar untuk Your Recipes App (Studio & Webadmin):
 * - Format: Progressive JPEG (.jpg)
 * - Maksimal Dimensi: 1080px (sisi terpanjang, proporsional tanpa distorsi)
 * - Kualitas Kompresi: 82% (keseimbangan visual tajam & ukuran berkas sangat ringan)
 * - Menghilangkan metadata EXIF tak perlu (kamera, GPS, software, AI prompt)
 * - Menangani transparansi PNG dengan latar putih (#FFFFFF)
 * - Safe atomic file replace (menghindari berkas corrupt/0 byte)
 */
class ImageOptimizer
{
    /**
     * Ekstensi berkas yang didukung untuk optimasi.
     */
    public const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Memeriksa apakah sebuah berkas gambar dapat dioptimasi.
     */
    public static function isOptimizable(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Mengoptimasi berkas gambar secara in-place.
     *
     * @param string $filePath Path absolut atau relatif ke berkas gambar
     * @param int $maxDimension Maksimal lebar atau tinggi (default 1080px)
     * @param int $quality Tingkat kualitas kompresi JPEG 1-100 (default 82%)
     * @return array Status dan metrik penghematan
     */
    public static function optimize(string $filePath, int $maxDimension = 1080, int $quality = 82): array
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return [
                'success' => false,
                'error' => 'Berkas gambar tidak ditemukan: ' . $filePath
            ];
        }

        if (!is_writable($filePath)) {
            return [
                'success' => false,
                'error' => 'Berkas gambar tidak memiliki izin tulis: ' . $filePath
            ];
        }

        $origSize = filesize($filePath);
        if ($origSize === false || $origSize <= 0) {
            return [
                'success' => false,
                'error' => 'Ukuran berkas asli tidak valid atau kosong.'
            ];
        }

        // Dapatkan informasi gambar
        $imgInfo = @getimagesize($filePath);
        if (!$imgInfo) {
            return [
                'success' => false,
                'error' => 'Format berkas bukan gambar valid atau rusak.'
            ];
        }

        $width = $imgInfo[0];
        $height = $imgInfo[1];
        $mime = strtolower($imgInfo['mime'] ?? '');

        // Buat resource gambar GD berdasarkan MIME type
        $srcImage = null;
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                if (function_exists('imagecreatefromjpeg')) {
                    $srcImage = @imagecreatefromjpeg($filePath);
                }
                break;

            case 'image/png':
                if (function_exists('imagecreatefrompng')) {
                    $srcImage = @imagecreatefrompng($filePath);
                }
                break;

            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $srcImage = @imagecreatefromwebp($filePath);
                }
                break;

            case 'image/gif':
                if (function_exists('imagecreatefromgif')) {
                    $srcImage = @imagecreatefromgif($filePath);
                }
                break;

            default:
                break;
        }

        // Fallback jika create from mime gagal: gunakan imagecreatefromstring
        if (!$srcImage && function_exists('imagecreatefromstring')) {
            $rawBytes = @file_get_contents($filePath);
            if ($rawBytes !== false && strlen($rawBytes) > 0) {
                $srcImage = @imagecreatefromstring($rawBytes);
                unset($rawBytes);
            }
        }

        if (!$srcImage) {
            return [
                'success' => false,
                'error' => 'Gagal memproses gambar dengan library PHP GD (MIME: ' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . ').'
            ];
        }

        // Hitung dimensi baru proporsional (tanpa distorsi, jangan upscale jika sudah kecil)
        $scale = 1.0;
        $maxCurrentDim = max($width, $height);
        if ($maxCurrentDim > $maxDimension && $maxDimension > 0) {
            $scale = $maxDimension / $maxCurrentDim;
        }

        $newWidth = (int)round($width * $scale);
        $newHeight = (int)round($height * $scale);

        // Buat kanvas truecolor baru
        $dstImage = imagecreatetruecolor($newWidth, $newHeight);
        if (!$dstImage) {
            if (PHP_VERSION_ID < 80000 && is_resource($srcImage)) @imagedestroy($srcImage);
            unset($srcImage);
            return [
                'success' => false,
                'error' => 'Gagal mengalokasikan memori GD canvas.'
            ];
        }

        // Siapkan latar belakang putih solid (#FFFFFF) untuk menangani transparansi PNG
        $whiteColor = imagecolorallocate($dstImage, 255, 255, 255);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $whiteColor);

        // Resample dengan antialiasing halus
        imagecopyresampled(
            $dstImage,
            $srcImage,
            0, 0, 0, 0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        // Aktifkan Progressive Interlace untuk JPEG
        imageinterlace($dstImage, true);

        // Tulis ke berkas sementara terlebih dahulu (Atomic Safe Replacement)
        $tmpTarget = $filePath . '.opt_' . uniqid() . '.tmp';
        $saveOk = @imagejpeg($dstImage, $tmpTarget, $quality);

        // Bebaskan memori GD sesegera mungkin
        if (PHP_VERSION_ID < 80000) {
            if (is_resource($srcImage)) @imagedestroy($srcImage);
            if (is_resource($dstImage)) @imagedestroy($dstImage);
        }
        unset($srcImage, $dstImage);

        if (!$saveOk || !file_exists($tmpTarget)) {
            if (file_exists($tmpTarget)) {
                @unlink($tmpTarget);
            }
            return [
                'success' => false,
                'error' => 'Gagal menyimpan berkas JPEG teroptimasi sementara.'
            ];
        }

        $newSize = filesize($tmpTarget);
        if ($newSize === false || $newSize <= 0) {
            @unlink($tmpTarget);
            return [
                'success' => false,
                'error' => 'Ukuran berkas terkompresi tidak valid (0 bytes).'
            ];
        }

        // Jika ukuran baru justru lebih besar (jarang sekali, tapi mungkin pada foto yang sudah sangat terkompresi & kecil):
        // Jika file asli sudah < 1080px dan sudah lebih kecil dari hasil kompresi 82%, tetap pertahankan jika perlu,
        // namun untuk konsistensi EXIF stripping dan progressive encoding, timpa berkas.
        if (!@rename($tmpTarget, $filePath)) {
            // Fallback jika rename gagal di filesystem tertentu
            if (@copy($tmpTarget, $filePath)) {
                @unlink($tmpTarget);
            } else {
                @unlink($tmpTarget);
                return [
                    'success' => false,
                    'error' => 'Gagal menimpa berkas asli dengan berkas teroptimasi.'
                ];
            }
        }

        // Set permission agar tetap bisa dibaca oleh webserver
        @chmod($filePath, 0664);

        $savedBytes = max(0, $origSize - $newSize);
        $savedPercent = $origSize > 0 ? round(($savedBytes / $origSize) * 100, 1) : 0;

        return [
            'success' => true,
            'file_path' => $filePath,
            'original_size' => $origSize,
            'optimized_size' => $newSize,
            'saved_bytes' => $savedBytes,
            'saving_percent' => $savedPercent,
            'old_dimensions' => "{$width}x{$height}",
            'new_dimensions' => "{$newWidth}x{$newHeight}"
        ];
    }
}
