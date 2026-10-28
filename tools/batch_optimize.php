<?php

/**
 * Utilitas Batch Optimizer Gambar
 * 
 * Memindai seluruh berkas gambar di folder upload/ dan mengoptimasinya:
 * - Mengubah resolusi melebihi 1080px menjadi max 1080px
 * - Mengompresi dengan Progressive JPEG 82%
 * - Membersihkan metadata EXIF
 * 
 * Dapat dijalankan via CLI:
 *   php tools/batch_optimize.php
 * 
 * Atau dipanggil via AJAX / Web:
 *   GET /tools/batch_optimize.php?action=scan
 *   POST /tools/batch_optimize.php?action=run
 */

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ImageOptimizer.php';

$uploadDir = realpath(__DIR__ . '/../upload');

if (!$uploadDir || !is_dir($uploadDir)) {
    $err = 'Folder upload tidak ditemukan di: ' . __DIR__ . '/../upload';
    if ($isCli) {
        fwrite(STDERR, "Error: $err\n");
        exit(1);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

// Fungsi scan berkas gambar yang perlu dioptimasi
function scanOptimizableImages(string $dir, int $sizeThresholdBytes = 153600): array
{
    $items = scandir($dir);
    $files = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === 'video' || $item === 'category' || $item === 'notification' || $item === 'index.php') {
            continue;
        }

        $fullPath = $dir . '/' . $item;
        if (!is_file($fullPath)) {
            continue;
        }

        if (!ImageOptimizer::isOptimizable($fullPath)) {
            continue;
        }

        $size = filesize($fullPath);
        // Pertimbangkan file jika > threshold (150 KB) atau memiliki dimensi besar
        $imgInfo = @getimagesize($fullPath);
        $w = $imgInfo[0] ?? 0;
        $h = $imgInfo[1] ?? 0;

        // Berkas perlu dioptimasi jika resolusinya melebihi 1080px atau ukurannya masih di atas 250 KB
        $needsOpt = false;
        if ($w > 1080 || $h > 1080) {
            $needsOpt = true;
        } elseif ($size > 256000) {
            $needsOpt = true;
        }

        if ($needsOpt) {
            $files[] = [
                'filename' => $item,
                'path' => $fullPath,
                'size' => $size,
                'size_human' => round($size / 1024, 1) . ' KB',
                'dimensions' => "{$w}x{$h}"
            ];
        }
    }

    return $files;
}

// Mode CLI
if ($isCli) {
    echo "========================================================\n";
    echo "  Your Recipes App - Batch Image Optimizer\n";
    echo "  Folder: {$uploadDir}\n";
    echo "========================================================\n\n";

    echo "Memindai folder upload/...\n";
    $candidates = scanOptimizableImages($uploadDir);
    $totalFound = count($candidates);

    if ($totalFound === 0) {
        echo "Semua gambar di folder upload/ sudah optimal! Tidak ada berkas yang perlu diproses.\n";
        exit(0);
    }

    echo "Ditemukan {$totalFound} berkas gambar yang perlu dioptimasi:\n\n";
    printf("%-35s %-12s %-12s\n", "Nama Berkas", "Ukuran", "Dimensi");
    echo str_repeat("-", 62) . "\n";
    foreach ($candidates as $c) {
        printf("%-35s %-12s %-12s\n", substr($c['filename'], 0, 34), $c['size_human'], $c['dimensions']);
    }
    echo "\nMemulai optimasi otomatis...\n\n";

    $totalSavedBytes = 0;
    $successCount = 0;
    $failCount = 0;

    foreach ($candidates as $idx => $c) {
        $num = $idx + 1;
        echo "[{$num}/{$totalFound}] Mengoptimasi {$c['filename']}... ";
        $res = ImageOptimizer::optimize($c['path'], 1080, 82);

        if ($res['success']) {
            $saved = $res['saved_bytes'];
            $totalSavedBytes += $saved;
            $successCount++;
            echo "SUKSES! " . round($res['original_size'] / 1024, 1) . " KB -> " . round($res['optimized_size'] / 1024, 1) . " KB (Hemat {$res['saving_percent']}%)\n";
        } else {
            $failCount++;
            echo "GAGAL: {$res['error']}\n";
        }
    }

    echo "\n========================================================\n";
    echo "  Laporan Akhir Optimasi:\n";
    echo "  - Berhasil: {$successCount} berkas\n";
    echo "  - Gagal: {$failCount} berkas\n";
    echo "  - Total Ruang Disk Dihemat: " . round($totalSavedBytes / 1048576, 2) . " MB (" . number_format($totalSavedBytes) . " bytes)\n";
    echo "========================================================\n";
    exit(0);
}

// Mode Web / AJAX
header('Content-Type: application/json; charset=utf-8');

// Basic session check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Akses ditolak: Anda harus login ke webadmin terlebih dahulu.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'scan';

if ($action === 'scan') {
    $candidates = scanOptimizableImages($uploadDir);
    $totalBytes = array_sum(array_column($candidates, 'size'));
    echo json_encode([
        'success' => true,
        'count' => count($candidates),
        'total_size' => $totalBytes,
        'total_size_human' => round($totalBytes / 1048576, 2) . ' MB',
        'files' => $candidates
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'run') {
    $candidates = scanOptimizableImages($uploadDir);
    $results = [];
    $totalSaved = 0;
    $success = 0;
    $failed = 0;

    foreach ($candidates as $c) {
        $res = ImageOptimizer::optimize($c['path'], 1080, 82);
        if ($res['success']) {
            $success++;
            $totalSaved += $res['saved_bytes'];
            $results[] = [
                'filename' => $c['filename'],
                'status' => 'success',
                'old_size' => $res['original_size'],
                'new_size' => $res['optimized_size'],
                'saving_percent' => $res['saving_percent']
            ];
        } else {
            $failed++;
            $results[] = [
                'filename' => $c['filename'],
                'status' => 'failed',
                'error' => $res['error']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'processed' => count($candidates),
        'succeeded' => $success,
        'failed' => $failed,
        'total_saved_bytes' => $totalSaved,
        'total_saved_mb' => round($totalSaved / 1048576, 2),
        'details' => $results
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Action tidak valid.']);
