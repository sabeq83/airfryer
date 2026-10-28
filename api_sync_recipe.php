<?php
/**
 * Recipe Sync Receiver Endpoint
 * Endpoint penerima data resep dan file gambar dari Local Studio (MacBook / Mac Mini).
 * Compatible with PHP 7.x & 8.x
 */

// Error suppression for clean JSON response
if (defined('E_DEPRECATED')) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
}
ini_set('display_errors', '0');

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

// Polyfills
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return (string)$needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return (string)$needle === '' || substr($haystack, -strlen($needle)) === (string)$needle;
    }
}

function send_json($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Secret Sync Key Definition
// Token ini harus sama persis antara Local Studio (Mac) dan Live Hosting
define('AIRFRY_SYNC_SECRET_TOKEN', 'airfry_sync_sec_99a8b7c6d5e4f3a2b1');

// 2. Authentication Check
$providedToken = $_POST['sync_token'] ?? $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
if (empty($providedToken) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $providedToken = $matches[1];
    }
}

if (!hash_equals(AIRFRY_SYNC_SECRET_TOKEN, (string)$providedToken)) {
    send_json([
        'success' => false,
        'error' => 'Akses ditolak: Token sinkronisasi (sync_token) tidak valid.'
    ], 401);
}

// 3. Database Connection
require_once('includes/config.php');
if (!isset($connect) || mysqli_connect_errno()) {
    send_json([
        'success' => false,
        'error' => 'Koneksi database hosting gagal.'
    ], 500);
}

@set_time_limit(300);

// Quick Ping / Test Action
$action = $_POST['action'] ?? 'push_recipe';
if ($action === 'ping') {
    $uploadDir = __DIR__ . '/upload';
    $videoDir = __DIR__ . '/upload/video';
    if (!is_dir($videoDir)) {
        @mkdir($videoDir, 0755, true);
    }
    send_json([
        'success' => true,
        'message' => 'Endpoint penerima hosting siap & terautentikasi.',
        'server_time' => date('Y-m-d H:i:s'),
        'upload_dir_writable' => is_writable($uploadDir),
        'video_dir_writable' => is_writable($videoDir),
        'max_upload_size' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time')
    ]);
}

// 4. Validate Recipe Data
$title = trim($_POST['recipe_title'] ?? '');
if (empty($title)) {
    send_json(['success' => false, 'error' => 'Judul resep (recipe_title) tidak boleh kosong.'], 400);
}

$time = trim($_POST['recipe_time'] ?? '30 mins');
$description = trim($_POST['recipe_description'] ?? '');
$contentType = trim($_POST['content_type'] ?? 'Post');
$videoUrl = trim($_POST['video_url'] ?? '');
$videoId = trim($_POST['video_id'] ?? '');
$categoryName = trim($_POST['category_name'] ?? 'Air Fryer');
$tags = trim($_POST['tags'] ?? '');
$size = trim($_POST['size'] ?? '');

// 5. Category Resolution (Auto-match or Auto-create in tbl_category)
$catId = 1;
if (!empty($categoryName)) {
    $stmtCat = $connect->prepare("SELECT cid FROM `tbl_category` WHERE `category_name` = ? LIMIT 1");
    if ($stmtCat) {
        $stmtCat->bind_param('s', $categoryName);
        $stmtCat->execute();
        $resCat = $stmtCat->get_result();
        if ($resCat && $rowCat = $resCat->fetch_assoc()) {
            $catId = (int)$rowCat['cid'];
        } else {
            // Auto create category
            $stmtNewCat = $connect->prepare("INSERT INTO `tbl_category` (`category_name`, `category_image`) VALUES (?, '')");
            if ($stmtNewCat) {
                $stmtNewCat->bind_param('s', $categoryName);
                if ($stmtNewCat->execute()) {
                    $catId = (int)$connect->insert_id;
                }
                $stmtNewCat->close();
            }
        }
        $stmtCat->close();
    }
}

// 6. Image File Upload Handler
$uploadDir = __DIR__ . '/upload';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$imageFileName = '';
if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['image_file']['tmp_name'];
    $origName = $_FILES['image_file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    
    // Allowed extensions
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        send_json(['success' => false, 'error' => 'Format gambar tidak didukung (harus JPG/PNG/WebP).'], 400);
    }
    
    // Normalize jpg
    if ($ext === 'jpeg') $ext = 'jpg';
    
    // Generate clean destination filename
    $imageFileName = 'sync_' . time() . '_' . substr(md5($title . microtime()), 0, 8) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $imageFileName;
    
    if (!@move_uploaded_file($fileTmp, $targetPath)) {
        // Fallback copy
        if (!@copy($fileTmp, $targetPath)) {
            send_json(['success' => false, 'error' => 'Gagal menyimpan file gambar ke folder /upload/ hosting.'], 500);
        }
    }

    // Safety check: Pastikan berkas yang diterima selalu optimal
    if (class_exists('ImageOptimizer')) {
        ImageOptimizer::optimize($targetPath, 1080, 82);
    }
}

// 6b. Video File Upload Handler
$videoUploadDir = __DIR__ . '/upload/video';
if (!is_dir($videoUploadDir)) {
    @mkdir($videoUploadDir, 0755, true);
}

$uploadedVideoName = '';
if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['video_file']['tmp_name'];
    $origName = $_FILES['video_file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    
    $allowedVideo = ['mp4', 'mov', 'm4v', 'webm', 'mkv', '3gp'];
    if (!in_array($ext, $allowedVideo, true)) {
        send_json(['success' => false, 'error' => 'Format video tidak didukung (harus MP4/MOV/WebM/MKV/3GP).'], 400);
    }
    
    // Clean filename: preserve sanitized name
    $rawBase = pathinfo($origName, PATHINFO_FILENAME);
    $cleanBase = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $rawBase);
    if (empty($cleanBase)) {
        $cleanBase = 'sync_video_' . time();
    }
    $uploadedVideoName = $cleanBase . '.' . $ext;
    $targetVideoPath = $videoUploadDir . '/' . $uploadedVideoName;
    
    if (!@move_uploaded_file($fileTmp, $targetVideoPath)) {
        if (!@copy($fileTmp, $targetVideoPath)) {
            send_json(['success' => false, 'error' => 'Gagal menyimpan file video ke folder /upload/video/ hosting.'], 500);
        }
    }
} else if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $errCode = $_FILES['video_file']['error'];
    $errMsg = "Error upload file video (Kode {$errCode})";
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        $maxUp = ini_get('upload_max_filesize');
        $maxPost = ini_get('post_max_size');
        $errMsg = "Ukuran file video melebihi batas konfigurasi web hosting (upload_max_filesize: {$maxUp}, post_max_size: {$maxPost}). Silakan naikkan limit di cPanel MultiPHP INI Editor.";
    }
    send_json(['success' => false, 'error' => $errMsg], 400);
}

// Determine final video URL (filename for upload type, or external URL)
if (!empty($uploadedVideoName)) {
    $finalVideoUrl = $uploadedVideoName;
} else if (!empty($videoUrl)) {
    if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        $finalVideoUrl = basename($videoUrl);
    } else {
        $finalVideoUrl = $videoUrl;
    }
} else {
    $finalVideoUrl = '';
}

// 7. Smart Upsert (Check if recipe already exists on hosting)
$existingId = 0;
$oldImage = '';
$oldVideo = '';
$oldSize = '';
$stmtCheck = $connect->prepare("SELECT `recipe_id`, `recipe_image`, `video_url`, `size` FROM `tbl_recipes` WHERE `recipe_title` = ? LIMIT 1");
if ($stmtCheck) {
    $stmtCheck->bind_param('s', $title);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck && $rowCheck = $resCheck->fetch_assoc()) {
        $existingId = (int)$rowCheck['recipe_id'];
        $oldImage = $rowCheck['recipe_image'] ?? '';
        $oldVideo = $rowCheck['video_url'] ?? '';
        $oldSize = $rowCheck['size'] ?? '';
    }
    $stmtCheck->close();
}

$actionTaken = 'created';
$finalId = 0;

if ($existingId > 0) {
    // UPDATE existing recipe
    $actionTaken = 'updated';
    $finalId = $existingId;
    
    // Retain existing assets if not replaced
    $imgToSave = !empty($imageFileName) ? $imageFileName : $oldImage;
    $videoToSave = !empty($uploadedVideoName) ? $uploadedVideoName : (!empty($finalVideoUrl) ? $finalVideoUrl : $oldVideo);
    $sizeToSave = !empty($size) ? $size : $oldSize;
    
    $stmtUp = $connect->prepare("UPDATE `tbl_recipes` 
        SET `cat_id` = ?, 
            `recipe_time` = ?, 
            `recipe_description` = ?, 
            `recipe_image` = ?, 
            `content_type` = ?, 
            `video_url` = ?, 
            `video_id` = ?,
            `size` = ? 
        WHERE `recipe_id` = ?");
    if ($stmtUp) {
        $stmtUp->bind_param('isssssssi', $catId, $time, $description, $imgToSave, $contentType, $videoToSave, $videoId, $sizeToSave, $existingId);
        $stmtUp->execute();
        $stmtUp->close();
    }
    
    // Clean up old image if replaced
    if (!empty($imageFileName) && !empty($oldImage) && $imageFileName !== $oldImage && file_exists($uploadDir . '/' . $oldImage)) {
        @unlink($uploadDir . '/' . $oldImage);
    }

    // Clean up old video file if replaced
    if (!empty($uploadedVideoName) && !empty($oldVideo) && $uploadedVideoName !== $oldVideo && file_exists($videoUploadDir . '/' . $oldVideo)) {
        @unlink($videoUploadDir . '/' . $oldVideo);
    }
} else {
    // INSERT new recipe
    $actionTaken = 'created';
    $videoToSave = !empty($uploadedVideoName) ? $uploadedVideoName : $finalVideoUrl;
    $stmtIns = $connect->prepare("INSERT INTO `tbl_recipes` 
        (`cat_id`, `recipe_title`, `recipe_time`, `recipe_description`, `recipe_image`, `content_type`, `video_url`, `video_id`, `size`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmtIns) {
        $stmtIns->bind_param('issssssss', $catId, $title, $time, $description, $imageFileName, $contentType, $videoToSave, $videoId, $size);
        if ($stmtIns->execute()) {
            $finalId = (int)$connect->insert_id;
        } else {
            send_json(['success' => false, 'error' => 'Gagal insert resep ke database: ' . $stmtIns->error], 500);
        }
        $stmtIns->close();
    }
}

send_json([
    'success' => true,
    'action' => $actionTaken,
    'live_recipe_id' => $finalId,
    'recipe_title' => $title,
    'category_id' => $catId,
    'category_name' => $categoryName,
    'image_filename' => $imageFileName,
    'video_filename' => $uploadedVideoName ?: $finalVideoUrl,
    'video_uploaded' => !empty($uploadedVideoName),
    'message' => "Resep '{$title}' berhasil " . ($actionTaken === 'created' ? 'diterbitkan' : 'diperbarui') . " di web hosting (ID: #{$finalId})" . (!empty($uploadedVideoName) ? " beserta file video ({$uploadedVideoName})" : "") . "!"
]);
