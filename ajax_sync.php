<?php
/**
 * AJAX Handler for Sync to Live Hosting
 * Dipanggil dari recipes.php, recipes-detail.php, atau komponen admin lainnya.
 */

ob_start();
if (defined('E_DEPRECATED')) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
}
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(360);

// Session check
$currentTime = time() + 25200;
if (!isset($_SESSION['user']) || (isset($_SESSION['timeout']) && $currentTime > $_SESSION['timeout'])) {
    echo json_encode(['success' => false, 'error' => 'Sesi login telah berakhir. Silakan muat ulang halaman.']);
    exit;
}

require_once('includes/config.php');
require_once('functions.php');
require_once('ai/SyncClient.php');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$syncClient = new \Ai\SyncClient($connect);

try {
    switch ($action) {
        case 'ping':
            $res = $syncClient->pingLive();
            echo json_encode($res);
            exit;

        case 'push_single':
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            if ($recipeId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID resep tidak valid.']);
                exit;
            }
            $res = $syncClient->pushRecipeToLive($recipeId);
            echo json_encode($res);
            exit;

        case 'push_batch':
            $ids = $_POST['recipe_ids'] ?? [];
            if (is_string($ids)) {
                $ids = array_filter(array_map('intval', explode(',', $ids)));
            }
            if (empty($ids) || !is_array($ids)) {
                echo json_encode(['success' => false, 'error' => 'Tidak ada resep yang dipilih untuk di-push.']);
                exit;
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($ids as $rid) {
                $rid = (int)$rid;
                if ($rid <= 0) continue;
                $res = $syncClient->pushRecipeToLive($rid);
                if (!empty($res['success'])) {
                    $successCount++;
                } else {
                    $failCount++;
                }
                $results[] = [
                    'recipe_id' => $rid,
                    'result' => $res
                ];
            }

            echo json_encode([
                'success' => true,
                'total' => count($ids),
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $results,
                'message' => "Proses sync selesai: {$successCount} berhasil, {$failCount} gagal."
            ]);
            exit;

        case 'save_config':
            $targetUrl = trim($_POST['sync_target_url'] ?? '');
            $secretToken = trim($_POST['sync_secret_token'] ?? '');
            if (empty($targetUrl) || empty($secretToken)) {
                echo json_encode(['success' => false, 'error' => 'Target URL dan Secret Token wajib diisi.']);
                exit;
            }
            $ok = $syncClient->setConfig($targetUrl, $secretToken);
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Pengaturan sinkronisasi berhasil disimpan.' : 'Gagal menyimpan konfigurasi.']);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Aksi tidak dikenal: ' . htmlspecialchars($action)]);
            exit;
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
