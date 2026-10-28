<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// PHP 7 Compatibility Polyfills
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || $needle === substr($haystack, -strlen($needle));
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

require_once('includes/config.php');
require_once('includes/constant.php');
require_once('ai/GlabsClient.php');
require_once('ai/GeminiClient.php');
require_once('ai/AiImageService.php');

echo "=== DIAGNOSTIK G-LABS DOWNLOAD ===\n\n";
echo "0. SYSTEM INFO:\n";
echo "   - PHP Version: " . PHP_VERSION . "\n\n";

$ai = new \Ai\AiImageService($connect);
$cfg = $ai->getConfig();
echo "1. DATABASE CONFIG:\n";
echo "   - Host: " . ($cfg['glabs_host'] ?? '(empty)') . "\n";
echo "   - Port: " . ($cfg['glabs_port'] ?? '(empty)') . "\n";
echo "   - Key:  " . substr($cfg['glabs_api_key'] ?? '', 0, 10) . "...\n\n";

$uploadDir = __DIR__ . '/upload';
echo "2. UPLOAD FOLDER PERMISSION:\n";
echo "   - Path: " . $uploadDir . "\n";
echo "   - Exists: " . (is_dir($uploadDir) ? "YES" : "NO") . "\n";
echo "   - Writable: " . (is_writable($uploadDir) ? "YES" : "NO (Perlu chmod 755/777)") . "\n\n";

$taskId = $_GET['task_id'] ?? '5577732b';
echo "3. TESTING TASK: " . $taskId . "\n";
$res = $ai->pollAndDownload($taskId);

echo "\n4. RESULT G-LABS DOWNLOAD:\n";
print_r($res);

echo "\n=== DIAGNOSTIK GEMINI API KEY POOL ===\n\n";
$keyRows = $connect->query("SELECT id, key_name, api_key, is_active, last_status, cooldown_until, used_today FROM `tbl_ai_keys` ORDER BY id ASC");
if (!$keyRows || $keyRows->num_rows === 0) {
    echo "5. KEY POOL STATUS: KOSONG! Belum ada kunci di tbl_ai_keys.\n";
} else {
    echo "5. KEY POOL STATUS (" . $keyRows->num_rows . " Kunci Terdaftar):\n";
    $gemini = new \Ai\GeminiClient();
    while ($k = $keyRows->fetch_assoc()) {
        $rawK = trim($k['api_key']);
        $masked = strlen($rawK) > 12 ? substr($rawK, 0, 8) . '...' . substr($rawK, -4) : 'AIzaSy...';
        echo "   • [ID: {$k['id']}] {$k['key_name']} ({$masked})\n";
        echo "     DB State: is_active={$k['is_active']}, last_status={$k['last_status']}, used_today={$k['used_today']}\n";

        // Validate directly with Google
        $test = $gemini->testConnection($rawK);
        if ($test['success']) {
            echo "     Google API: VALID & AKTIF! (" . ($test['message'] ?? 'OK') . ")\n";
            // Auto repair key in database
            $connect->query("UPDATE `tbl_ai_keys` SET `is_active` = 1, `last_status` = 'ACTIVE', `cooldown_until` = NULL WHERE `id` = " . (int)$k['id']);
            echo "     Auto-Repair: ✓ Status di-reset ke ACTIVE & Sehat!\n\n";
        } else {
            echo "     Google API: GAGAL! (" . ($test['error'] ?? 'Unknown Error') . ")\n\n";
        }
    }
}

echo "6. TEST GEMINI WATERFALL ENGINE (START: gemini-3.8-flash):\n";
$firstActive = $connect->query("SELECT api_key FROM `tbl_ai_keys` WHERE `is_active` = 1 AND `last_status` = 'ACTIVE' LIMIT 1");
if ($firstActive && $rowA = $firstActive->fetch_assoc()) {
    $craftRes = $ai->craftPromptsForRecipe(1, 1, 'Rustic Kitchen Table');
    print_r($craftRes);
} else {
    echo "   Tidak ada kunci yang aktif di pool untuk ditest.\n";
}

