<?php
ob_start();
if (defined('E_DEPRECATED')) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
}
if (isset($_REQUEST['ajax_action'])) {
    ini_set('display_errors', '0');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Polyfills for PHP < 8.0 compatibility
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

function safe_json_encode($data): string {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    return $json !== false ? $json : json_encode(['success' => false, 'error' => 'Gagal encode JSON']);
}

require_once('includes/config.php');
require_once('includes/constant.php');
require_once('includes/strings.php');
require_once('functions.php');

// Session check
$currentTime = time() + 25200;
if (!isset($_SESSION['user']) || (isset($_SESSION['timeout']) && $currentTime > $_SESSION['timeout'])) {
    if (isset($_REQUEST['ajax_action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo safe_json_encode(['success' => false, 'error' => 'Sesi login telah berakhir. Silakan muat ulang halaman dan login kembali.']);
        exit;
    }
    session_destroy();
    header("location:index.php");
    exit();
}
unset($_SESSION['timeout']);
$_SESSION['timeout'] = $currentTime + 86400;

if (defined('ENABLE_STUDIO_FEATURES') && !ENABLE_STUDIO_FEATURES) {
    if (isset($_REQUEST['ajax_action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo safe_json_encode(['success' => false, 'error' => 'Fitur AI Generator hanya dapat dijalankan melalui Local Studio Mac Mini.']);
        exit;
    }
    include_once('includes/header.php');
    ?>
    <section class="content">
        <div class="container-fluid">
            <div class="card corner-radius" style="margin-top:20px; padding:40px 20px; text-align:center;">
                <div style="margin-bottom:15px;"><i class="material-icons" style="font-size:64px; color:#6366f1;">auto_awesome</i></div>
                <h3 style="color:#1e293b; margin-bottom:10px;">Fitur Local Studio Mac Mini</h3>
                <p style="color:#64748b; font-size:14px; max-width:600px; margin:0 auto 20px auto; line-height:1.6;">
                    Fitur <strong>AI Image Generator</strong> dinonaktifkan di server shared web hosting publik.<br>
                    Pembuatan foto makanan dengan akselerasi GPU dijalankan secara terpusat melalui <strong>Studio Server Mac Mini</strong>. Foto yang telah selesai dapat langsung diterbitkan ke hosting ini menggunakan tombol <strong>1-Click Push</strong>.
                </p>
                <a href="recipes.php" class="btn bg-blue waves-effect" style="border-radius:6px; font-weight:600;">Kembali ke Manajemen Resep</a>
            </div>
        </div>
    </section>
    <?php
    include_once('includes/footer.php');
    exit;
}

require_once('ai/AiDatabaseMigration.php');
require_once('ai/AiDatabaseMigrationV2.php');
require_once('ai/AiVideoDatabaseMigration.php');
require_once('ai/FFmpegVideoService.php');
require_once('ai/GeminiClient.php');
require_once('ai/GlabsClient.php');
require_once('ai/AiImageService.php');
require_once('ai/AiVideoService.php');
require_once('ai/SyncClient.php');

$aiService = new Ai\AiImageService($connect);
$videoService = new Ai\AiVideoService($connect);
$migration = new Ai\AiDatabaseMigration($connect);

if (!$migration->isMigrated()) {
    $migration->runMigration();
}

try {
    $migrationV2 = new Ai\AiDatabaseMigrationV2($connect);
    $migrationV2->run();
} catch (\Throwable $e) {
    error_log('AI Migration V2 error: ' . $e->getMessage());
}

try {
    $videoMigration = new Ai\AiVideoDatabaseMigration($connect);
    if (!$videoMigration->isMigrated()) {
        $videoMigration->run();
    }
} catch (\Throwable $e) {
    error_log('AI Video Migration error: ' . $e->getMessage());
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle AJAX actions
if (isset($_REQUEST['ajax_action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    // Verify CSRF
    $providedCsrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($csrfToken, $providedCsrf)) {
        echo safe_json_encode(['success' => false, 'error' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman.']);
        exit;
    }

    $action = $_REQUEST['ajax_action'];

    try {
        switch ($action) {
            case 'test_glabs':
                $host = trim($_POST['glabs_host'] ?? '127.0.0.1');
                $port = (int)($_POST['glabs_port'] ?? 8765);
                $res = $aiService->getGlabsClient()->checkHealth($host, $port);
                echo safe_json_encode($res);
                exit;

            case 'test_gemini':
                $key = trim($_POST['gemini_api_key'] ?? '');
                $res = $aiService->getGeminiClient()->testConnection($key);
                echo safe_json_encode($res);
                exit;

            case 'save_config':
                $res = $aiService->saveConfig($_POST);
                echo safe_json_encode($res);
                exit;

            case 'craft_prompts':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $count = max(1, min(4, (int)($_POST['count'] ?? 1)));
                $style = trim($_POST['style_preset'] ?? '');
                if ($recipeId <= 0) {
                    throw new Exception('ID Resep tidak valid.');
                }
                $res = $aiService->craftPromptsForRecipe($recipeId, $count, $style);
                echo safe_json_encode($res);
                exit;

            case 'enhance_recipe_full':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $count = max(1, min(4, (int)($_POST['count'] ?? 1)));
                $style = trim($_POST['style_preset'] ?? '');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '4:3');
                if ($recipeId <= 0) {
                    throw new Exception('ID Resep tidak valid.');
                }
                $options = [
                    'style_preset' => $style,
                    'aspect_ratio' => $aspectRatio,
                    'prompt_count' => $count
                ];
                $res = $aiService->enhanceRecipeById($recipeId, $options);
                echo safe_json_encode($res);
                exit;

            case 'apply_enhanced_text':
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $newTitle = trim($_POST['recipe_title'] ?? '');
                $newSummary = trim($_POST['summary'] ?? '');
                $rawInstructions = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInstructions) ? (json_decode($rawInstructions, true) ?: []) : (is_array($rawInstructions) ? $rawInstructions : []);
                $rawIngredients = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIngredients) ? (json_decode($rawIngredients, true) ?: []) : (is_array($rawIngredients) ? $rawIngredients : []);

                if ($recipeId <= 0 || empty($newTitle)) {
                    throw new Exception('ID Resep dan Judul tidak boleh kosong.');
                }

                require_once('importer/HtmlSanitizer.php');
                $newDescription = Importer\HtmlSanitizer::buildRecipeDescription($newSummary, $ingredients, $instructions);

                $stmt = $connect->prepare("UPDATE `tbl_recipes` SET `recipe_title` = ?, `recipe_description` = ? WHERE `recipe_id` = ?");
                if (!$stmt) {
                    throw new Exception('Gagal menyiapkan query update: ' . $connect->error);
                }
                $stmt->bind_param('ssi', $newTitle, $newDescription, $recipeId);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    echo safe_json_encode(['success' => true, 'message' => 'Judul dan konten resep #' . $recipeId . ' berhasil diperbarui!']);
                } else {
                    echo safe_json_encode(['success' => false, 'error' => 'Gagal memperbarui resep: ' . $connect->error]);
                }
                exit;

            case 'submit_glabs_task':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $prompt = trim($_POST['prompt'] ?? '');
                $style = trim($_POST['style_preset'] ?? '');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '4:3');
                $model = trim($_POST['model'] ?? '');

                if (empty($prompt)) {
                    throw new Exception('Prompt tidak boleh kosong.');
                }
                $res = $aiService->submitImageTask($recipeId, $prompt, $style, $aspectRatio, $model);
                echo safe_json_encode($res);
                exit;

            case 'poll_status':
                $taskId = trim($_POST['task_id'] ?? '');
                $genId = (int)($_POST['generation_id'] ?? 0);
                if (empty($taskId)) {
                    throw new Exception('Task ID tidak valid.');
                }
                $res = $aiService->pollAndDownload($taskId, $genId);
                echo safe_json_encode($res);
                exit;

            case 'apply_image':
                $genId = (int)($_POST['generation_id'] ?? 0);
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                if ($genId <= 0 || $recipeId <= 0) {
                    throw new Exception('Data tidak valid untuk menerapkan gambar.');
                }
                $res = $aiService->applyImageToRecipe($genId, $recipeId);
                echo safe_json_encode($res);
                exit;

            case 'apply_and_push_image':
                $genId = (int)($_POST['generation_id'] ?? 0);
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                if ($genId <= 0 || $recipeId <= 0) {
                    throw new Exception('Data tidak valid untuk menerapkan dan push gambar.');
                }
                $applyRes = $aiService->applyImageToRecipe($genId, $recipeId);
                if (empty($applyRes['success'])) {
                    throw new Exception($applyRes['error'] ?? 'Gagal menerapkan gambar secara lokal.');
                }
                $syncClient = new Ai\SyncClient($connect);
                $pushRes = $syncClient->pushRecipeToLive($recipeId);
                if (empty($pushRes['success'])) {
                    throw new Exception('Gambar berhasil diterapkan di lokal, namun gagal push ke hosting: ' . ($pushRes['error'] ?? ''));
                }
                echo safe_json_encode([
                    'success' => true,
                    'new_image' => $applyRes['new_image'] ?? '',
                    'live_recipe_id' => $pushRes['live_recipe_id'] ?? 0,
                    'message' => "Foto diterapkan ke resep lokal DAN berhasil dipublikasikan ke hosting live airfry.web.id (ID: #{$pushRes['live_recipe_id']})!"
                ]);
                exit;

            case 'test_sync_live':
                $syncClient = new Ai\SyncClient($connect);
                $res = $syncClient->pingLive();
                echo safe_json_encode($res);
                exit;

            case 'revert_image':
                $genId = (int)($_POST['generation_id'] ?? 0);
                if ($genId <= 0) {
                    throw new Exception('ID Riwayat tidak valid.');
                }
                $res = $aiService->revertRecipeImage($genId);
                echo safe_json_encode($res);
                exit;

            case 'get_history':
                $rows = $aiService->getHistory(50);
                echo safe_json_encode(['success' => true, 'history' => $rows]);
                exit;

            case 'get_api_keys':
                $keys = $aiService->getAllAiKeys();
                echo safe_json_encode(['success' => true, 'keys' => $keys]);
                exit;

            case 'add_api_key':
                $name = trim($_POST['key_name'] ?? '');
                $key = trim($_POST['api_key'] ?? '');
                $res = $aiService->addAiKey($name, $key);
                echo safe_json_encode($res);
                exit;

            case 'delete_api_key':
                $keyId = (int)($_POST['key_id'] ?? 0);
                if ($keyId <= 0) {
                    throw new Exception('ID Kunci tidak valid.');
                }
                $ok = $aiService->deleteAiKey($keyId);
                echo safe_json_encode(['success' => $ok, 'message' => $ok ? 'Kunci berhasil dihapus dari pool.' : 'Gagal menghapus kunci.']);
                exit;

            case 'toggle_api_key':
                $keyId = (int)($_POST['key_id'] ?? 0);
                $isActive = !empty($_POST['is_active']);
                $ok = $aiService->toggleAiKey($keyId, $isActive);
                echo safe_json_encode(['success' => $ok]);
                exit;

            case 'test_all_keys':
                $res = $aiService->testAllAiKeys();
                echo safe_json_encode($res);
                exit;

            case 'reset_api_keys_cooldown':
                $res = $aiService->resetAllAiKeysCooldown();
                echo safe_json_encode($res);
                exit;

            /* =========================================================================
             * VIDEO GENERATOR ACTIONS
             * ========================================================================= */
            case 'video_create_project_storyboard':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $kitchenPreset = trim($_POST['kitchen_preset'] ?? 'Modern Scandinavian Kitchen');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '9:16');
                $mode = trim($_POST['storyboard_mode'] ?? 'reels');
                if ($recipeId <= 0) {
                    throw new Exception('ID Resep wajib dipilih.');
                }
                $res = $videoService->createProjectWithStoryboard($recipeId, $kitchenPreset, $aspectRatio, $mode);
                echo safe_json_encode($res);
                exit;

            case 'video_get_project':
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new Exception('ID Proyek tidak valid.');
                }
                $proj = $videoService->getProject($projectId);
                echo safe_json_encode(['success' => (bool)$proj, 'project' => $proj]);
                exit;

            case 'video_submit_frame_task':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                $customPrompt = isset($_POST['prompt_t2i']) ? trim($_POST['prompt_t2i']) : null;
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $res = $videoService->submitFrameTask($sceneId, $customPrompt);
                echo safe_json_encode($res);
                exit;

            case 'video_poll_frame_task':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $res = $videoService->pollFrameTask($sceneId);
                echo safe_json_encode($res);
                exit;

            case 'video_submit_clip_task':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $res = $videoService->submitClipTask($sceneId, $_POST);
                echo safe_json_encode($res);
                exit;

            case 'video_poll_clip_task':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $res = $videoService->pollClipTask($sceneId);
                echo safe_json_encode($res);
                exit;

            case 'video_stitch_all_scenes':
                @set_time_limit(300);
                @ini_set('max_execution_time', '300');
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new Exception('ID Proyek tidak valid.');
                }
                $res = $videoService->stitchProject($projectId, $_POST);
                echo safe_json_encode($res);
                exit;

            case 'video_apply_to_recipe':
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new Exception('ID Proyek tidak valid.');
                }
                $res = $videoService->applyToRecipe($projectId);
                echo safe_json_encode($res);
                exit;

            default:
                throw new Exception('Aksi tidak dikenal: ' . htmlspecialchars($action));
        }
    } catch (Exception $e) {
        echo safe_json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Fetch recipes list for dropdown
$recipesQuery = "SELECT r.recipe_id, r.recipe_title, r.recipe_time, r.recipe_image, r.recipe_description, c.category_name 
    FROM `tbl_recipes` r 
    LEFT JOIN `tbl_category` c ON r.cat_id = c.cid 
    ORDER BY r.recipe_id DESC";
$recipesResult = $connect->query($recipesQuery);
$allRecipes = [];
while ($row = $recipesResult->fetch_assoc()) {
    $allRecipes[] = $row;
}

// Fetch current AI config
$aiConfig = $aiService->getConfig();

// Fetch recent history
$recentHistory = $aiService->getHistory(10);

include_once('includes/header.php');
include_once('includes/sidebar.php');
?>

<style>
    :root {
        --ai-primary: #6366f1;
        --ai-primary-hover: #4f46e5;
        --ai-secondary: #ec4899;
        --ai-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
        --card-border: #e2e8f0;
        --card-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.06);
        --card-hover-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.15);
    }

    body.poppins {
        font-family: 'Poppins', 'Roboto', sans-serif;
        background-color: #f1f5f9;
    }

    .ai-brand-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--ai-gradient);
        color: white;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-left: 8px;
        box-shadow: 0 2px 8px rgba(168, 85, 247, 0.4);
    }

    .page-banner {
        background: white;
        padding: 24px 30px;
        border-radius: 12px;
        margin-bottom: 25px;
        box-shadow: var(--card-shadow);
        border-left: 5px solid #6366f1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
    }
    .page-banner h2 {
        margin: 0 0 6px 0;
        font-size: 22px;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .page-banner p {
        margin: 0;
        color: #64748b;
        font-size: 13px;
    }

    .nav-tabs.ai-tabs {
        border-bottom: 2px solid #e2e8f0;
        background: white;
        padding: 8px 15px 0 15px;
        border-radius: 12px 12px 0 0;
        margin-bottom: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .nav-tabs.ai-tabs > li {
        margin-bottom: 0;
    }
    .nav-tabs.ai-tabs > li > a {
        font-weight: 600;
        font-size: 13px;
        color: #64748b;
        border: none;
        padding: 10px 18px;
        border-radius: 8px 8px 0 0;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .nav-tabs.ai-tabs > li > a .material-icons {
        font-size: 18px;
    }
    .nav-tabs.ai-tabs > li.active > a,
    .nav-tabs.ai-tabs > li.active > a:hover,
    .nav-tabs.ai-tabs > li.active > a:focus {
        color: var(--ai-primary);
        border: none;
        border-bottom: 3px solid var(--ai-primary);
        background: rgba(99, 102, 241, 0.06);
    }

    .tab-card {
        background: white;
        border-radius: 0 0 12px 12px;
        box-shadow: var(--card-shadow);
        padding: 30px;
        margin-bottom: 50px;
    }

    .btn-ai-count {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        font-weight: 600;
        font-size: 12px;
        padding: 8px 10px;
        transition: all 0.2s ease;
    }
    .btn-ai-count:hover {
        background: #e2e8f0;
        color: #1e293b;
    }
    .btn-ai-count.active {
        background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%) !important;
        color: #ffffff !important;
        border-color: #6366f1 !important;
        box-shadow: 0 4px 10px rgba(99, 102, 241, 0.35);
    }
    /* ================= HUB CARDS SELECTION ================= */
    .generator-hub-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 22px;
        margin-bottom: 25px;
    }
    .hub-card {
        background: white;
        border-radius: 14px;
        border: 2px solid #e2e8f0;
        padding: 24px;
        transition: all 0.25s ease;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: var(--card-shadow);
        cursor: pointer;
        position: relative;
    }
    .hub-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        border-color: #cbd5e1;
    }
    .hub-card.active-card {
        border-color: #c7d2fe;
        background: linear-gradient(180deg, #ffffff 0%, #faf5ff 100%);
    }
    .hub-card.active-card:hover {
        border-color: #818cf8;
    }
    .hub-card.selected-active {
        border-color: #6366f1 !important;
        box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.3), 0 8px 10px -6px rgba(99, 102, 241, 0.2) !important;
        background: linear-gradient(180deg, #ffffff 0%, #f5f3ff 100%) !important;
    }
    .hub-card.disabled-card {
        background: #f8fafc;
        border-color: #e2e8f0;
        opacity: 0.95;
    }

    /* Preset Kitchen Cards */
    .preset-kitchen-card {
        background: #ffffff;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 14px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .preset-kitchen-card:hover {
        border-color: #a5b4fc;
        background: #f8fafc;
        transform: translateY(-1px);
    }
    .preset-kitchen-card.selected {
        border-color: #6366f1 !important;
        background: #eef2ff !important;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.12) !important;
    }

    /* Video Select Cards (Storyboard Mode & Aspect Ratio) */
    .video-select-card {
        background: #f8fafc;
        border: 2px solid #cbd5e1;
        border-radius: 10px;
        padding: 10px 14px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        user-select: none;
    }
    .video-select-card:hover {
        border-color: #a5b4fc;
        background: #ffffff;
        transform: translateY(-1px);
    }
    .video-select-card.selected {
        border-color: #6366f1 !important;
        background: #eef2ff !important;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.12) !important;
    }
    .video-select-card .custom-radio-dot {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 2px solid #94a3b8;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: #ffffff;
        transition: all 0.2s ease;
    }
    .video-select-card.selected .custom-radio-dot {
        border-color: #6366f1;
        background: #6366f1;
    }
    .video-select-card .custom-radio-dot::after {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #ffffff;
        display: none;
    }
    .video-select-card.selected .custom-radio-dot::after {
        display: block;
    }

    /* Video Storyboard Cards */
    .video-scene-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.2s ease;
    }
    .video-scene-card:hover {
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
        border-color: #cbd5e1;
    }


    /* ================= WATERFALL VISUALIZER ================= */
    .waterfall-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px 20px;
        margin-top: 15px;
    }
    .waterfall-flow {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: 14px;
    }
    .waterfall-node {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 14px;
        transition: all 0.2s;
    }
    .waterfall-node.primary {
        border-left: 4px solid #6366f1;
        background: #f5f3ff;
    }
    .waterfall-node.fallback {
        border-left: 4px solid #f59e0b;
    }
    .waterfall-node.last {
        border-left: 4px solid #64748b;
    }
    .waterfall-step-badge {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
        margin-right: 10px;
    }
    .waterfall-step-badge.primary-badge {
        background: #6366f1;
        color: white;
    }
    .waterfall-step-badge.fallback-badge {
        background: #f59e0b;
        color: white;
    }
    .waterfall-connector {
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        font-size: 11px;
        font-weight: 600;
        padding: 2px 0;
    }
    .waterfall-connector i {
        font-size: 15px;
        margin-right: 4px;
    }

    /* ================= KEY POOL TABLE ================= */
    .key-pool-table th {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        border-bottom: 2px solid #e2e8f0 !important;
    }
    .key-pool-table td {
        vertical-align: middle !important;
        font-size: 12px;
    }
    .key-tag {
        font-family: monospace;
        background: #f1f5f9;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 12px;
        color: #0f172a;
        border: 1px solid #e2e8f0;
    }

    section.content {
        padding-bottom: 80px;
    }

    .step-stepper {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 35px;
        position: relative;
        padding: 0 10px;
    }
    .step-stepper::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 40px;
        right: 40px;
        height: 3px;
        background: #e2e8f0;
        z-index: 1;
    }
    .step-stepper .step-item {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        align-items: center;
        cursor: pointer;
        text-align: center;
    }
    .step-stepper .step-circle {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: white;
        border: 3px solid #cbd5e1;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 15px;
        margin-bottom: 8px;
        transition: all 0.3s ease;
    }
    .step-stepper .step-title {
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }
    .step-stepper .step-item.active .step-circle {
        background: var(--ai-gradient);
        border-color: #c084fc;
        color: white;
        box-shadow: 0 0 15px rgba(168, 85, 247, 0.4);
        transform: scale(1.08);
    }
    .step-stepper .step-item.active .step-title {
        color: #4338ca;
        font-weight: 700;
    }
    .step-stepper .step-item.completed .step-circle {
        background: #10b981;
        border-color: #10b981;
        color: white;
    }
    .step-stepper .step-item.completed .step-title {
        color: #059669;
    }

    .recipe-preview-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        margin-top: 15px;
        display: flex;
        gap: 20px;
        align-items: center;
        transition: all 0.3s;
    }
    .recipe-preview-thumb {
        width: 110px;
        height: 110px;
        border-radius: 10px;
        object-fit: cover;
        border: 2px solid white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        flex-shrink: 0;
    }
    .recipe-preview-info h4 {
        margin: 0 0 8px 0;
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
    }
    .recipe-preview-info p {
        margin: 0 0 10px 0;
        font-size: 12px;
        color: #64748b;
        line-height: 1.5;
    }
    .recipe-meta-badges {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .recipe-meta-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: white;
        border: 1px solid #e2e8f0;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        color: #475569;
        font-weight: 500;
    }
    .recipe-meta-badge .material-icons {
        font-size: 14px;
        color: #6366f1;
    }

    .preset-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 12px;
        margin-top: 10px;
    }
    .preset-card {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: white;
        position: relative;
    }
    .preset-card:hover {
        border-color: #a855f7;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(168, 85, 247, 0.1);
    }
    .preset-card.selected {
        border-color: #6366f1;
        background: rgba(99, 102, 241, 0.04);
        box-shadow: 0 0 0 1px #6366f1;
    }
    .preset-card.selected::after {
        content: '✓';
        position: absolute;
        top: 8px;
        right: 8px;
        width: 20px;
        height: 20px;
        background: #6366f1;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: bold;
    }
    .preset-card h5 {
        margin: 0 0 4px 0;
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
    }
    .preset-card small {
        font-size: 11px;
        color: #64748b;
        display: block;
        line-height: 1.3;
    }

    .prompt-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        transition: all 0.2s ease;
        position: relative;
    }
    .prompt-card:hover {
        border-color: #c084fc;
        box-shadow: var(--card-hover-shadow);
    }
    .prompt-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }
    .prompt-card-title {
        font-weight: 700;
        font-size: 14px;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .prompt-textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 12px;
        font-size: 13px;
        color: #334155;
        background: #f8fafc;
        resize: vertical;
        line-height: 1.6;
        transition: all 0.2s;
    }
    .prompt-textarea:focus {
        background: white;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        outline: none;
    }
    .prompt-tags {
        display: flex;
        gap: 8px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    .prompt-tag {
        background: #f1f5f9;
        border-radius: 4px;
        padding: 2px 8px;
        font-size: 10px;
        color: #64748b;
        font-family: monospace;
    }

    .comparison-panel {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
    }
    .comparison-header {
        font-weight: 700;
        font-size: 14px;
        color: #334155;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .comparison-grid {
        display: grid;
        grid-template-columns: 1fr 40px 1fr;
        align-items: center;
        gap: 15px;
    }
    .comparison-thumb-box {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px;
        text-align: center;
    }
    .comparison-thumb-box img {
        width: 100%;
        aspect-ratio: 1/1;
        object-fit: cover;
        border-radius: 8px;
        margin-bottom: 8px;
    }
    .comparison-arrow {
        text-align: center;
        color: #6366f1;
        font-size: 24px;
    }

    .output-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .image-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: var(--card-shadow);
        transition: all 0.3s ease;
        position: relative;
        display: flex;
        flex-direction: column;
    }
    .image-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.12);
    }
    .image-card.active-in-recipe {
        border: 2px solid #10b981;
        box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
    }
    .image-card-thumb-wrap {
        position: relative;
        aspect-ratio: 1/1;
        width: 100%;
        overflow: hidden;
        background: #f1f5f9;
    }
    .image-card-thumb {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }
    .image-card-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: rgba(15, 23, 42, 0.85);
        backdrop-filter: blur(4px);
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .image-card-badge.applied {
        background: #10b981;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
    }
    .image-card-zoom-btn {
        position: absolute;
        top: 12px;
        right: 12px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: rgba(255,255,255,0.9);
        border: none;
        color: #1e293b;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        opacity: 0;
        transform: scale(0.8);
        transition: all 0.2s ease;
    }
    .image-card:hover .image-card-zoom-btn {
        opacity: 1;
        transform: scale(1);
    }
    .image-card-body {
        padding: 16px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .image-card-title {
        font-weight: 700;
        font-size: 13px;
        color: #1e293b;
        margin: 0 0 6px 0;
    }
    .image-card-desc {
        font-size: 11px;
        color: #64748b;
        line-height: 1.4;
        margin: 0 0 14px 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .image-card-actions {
        display: flex;
        gap: 8px;
    }
    .image-card-actions .btn {
        flex: 1;
        font-size: 11px;
        font-weight: 600;
        padding: 8px 10px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }

    .btn-ai-primary {
        background: var(--ai-gradient);
        color: white !important;
        border: none;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.35);
        font-weight: 600;
        letter-spacing: 0.3px;
        padding: 12px 24px;
        border-radius: 8px;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-ai-primary:hover {
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
        transform: translateY(-1px);
    }

    #ai-toast {
        position: fixed;
        bottom: 25px;
        right: 25px;
        background: #1e293b;
        color: white;
        padding: 14px 22px;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 9999;
        transform: translateY(100px);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        font-size: 13px;
    }
    #ai-toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .table-ai th {
        background: #f8fafc;
        color: #475569;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        border-bottom: 2px solid #e2e8f0 !important;
        padding: 12px 15px !important;
    }
    .table-ai td {
        vertical-align: middle !important;
        padding: 14px 15px !important;
        font-size: 13px;
        border-bottom: 1px solid #f1f5f9;
    }
    .badge-status {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-status.active {
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    .badge-status.archived {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
    }
</style>

<section class="content">
    <div class="container-fluid">

        <!-- Banner Header -->
        <div class="page-banner">
            <div>
                <h2>
                    <i class="material-icons" style="color:#6366f1; font-size:28px;">auto_awesome</i>
                    AI Generator Hub
                </h2>
                <p>Studio Kreativitas Multimedia bertenaga AI untuk memproduksi fotografi & video promosi aplikasi <strong>Airfry Eats</strong>.</p>
            </div>
            <div style="display:flex; gap:10px;">
                <button class="btn btn-default" onclick="openTab('tab-settings')" style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; border-radius:6px;">
                    <i class="material-icons" style="font-size:16px; color:#6366f1;">dns</i> Pool API & Waterfall
                </button>
                <button class="btn btn-default" onclick="openTab('tab-history')" style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; border-radius:6px;">
                    <i class="material-icons" style="font-size:16px;">history</i> Riwayat Foto
                </button>
            </div>
        </div>

        <!-- HUB CARD SELECTION: IMAGE GENERATOR & VIDEO GENERATOR -->
        <div style="margin-bottom:15px;">
            <h4 style="margin:0 0 6px 0; font-size:14px; font-weight:700; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="material-icons" style="font-size:17px; vertical-align:middle; color:#6366f1;">apps</i>
                PILIH MODUL GENERATOR:
            </h4>
            <p style="margin:0; font-size:12px; color:#64748b;">Pilih jenis media yang ingin Anda rancang untuk meningkatkan daya tarik resep.</p>
        </div>

        <div class="generator-hub-grid">
            <!-- CARD 1: IMAGE GENERATOR -->
            <div class="hub-card active-card" id="card-image-gen" onclick="toggleImageStudio()">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                        <div style="width:52px; height:52px; border-radius:12px; background:linear-gradient(135deg, #6366f1 0%, #a855f7 100%); display:flex; align-items:center; justify-content:center; color:white; box-shadow:0 6px 15px rgba(99,102,241,0.25);">
                            <i class="material-icons" style="font-size:28px;">photo_camera</i>
                        </div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span class="label label-primary" id="badge-studio-status" style="font-size:10px; font-weight:700; padding:4px 8px; border-radius:12px; display:none;">
                                <i class="material-icons" style="font-size:11px; vertical-align:middle;">visibility</i> SEDANG DIBUKA
                            </span>
                            <span class="label label-success" style="font-size:11px; font-weight:700; padding:5px 10px; border-radius:20px;">
                                <i class="material-icons" style="font-size:11px; vertical-align:middle;">check_circle</i> SIAP DIGUNAKAN
                            </span>
                        </div>
                    </div>
                    <h3 style="margin:0 0 6px 0; font-size:17px; font-weight:700; color:#1e293b;">AI Recipe Image Generator</h3>
                    <p style="font-size:12px; color:#64748b; line-height:1.5; margin-bottom:16px;">
                        Hasilkan fotografi makanan fotorealistis beresolusi tinggi. Otak prompt membaca resep dengan <strong>Gemini Flash</strong> lalu dirender cepat oleh <strong>G-Labs Webhook</strong>.
                    </p>
                    <ul style="list-style:none; padding:0; margin:0 0 20px 0; font-size:12px; color:#334155; line-height:1.8;">
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> Perumusan Prompt Otomatis berbasis Bahan Resep</li>
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> 4 Preset Estetika (Rustic, Fine Dining, Macro, Flatlay)</li>
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> Komparasi Side-by-Side dengan Foto Asli Resep</li>
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> 1-Click Terapkan ke Resep + 1-Click Revert Backup</li>
                    </ul>
                </div>
                <button type="button" class="btn btn-primary" id="btn-toggle-image-studio" onclick="event.stopPropagation(); toggleImageStudio();" style="background:var(--ai-gradient); border:none; border-radius:8px; padding:10px 18px; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <span id="text-toggle-studio">Buka Image Generator Studio</span>
                    <i class="material-icons" id="icon-toggle-studio" style="font-size:16px;">arrow_forward</i>
                </button>
            </div>

            <!-- CARD 2: VIDEO GENERATOR -->
            <div class="hub-card active-card" id="card-video-gen" onclick="toggleVideoStudio()">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                        <div style="width:52px; height:52px; border-radius:12px; background:linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%); display:flex; align-items:center; justify-content:center; color:white; box-shadow:0 6px 15px rgba(236,72,153,0.25);">
                            <i class="material-icons" style="font-size:28px;">videocam</i>
                        </div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span class="label label-primary" id="badge-video-studio-status" style="font-size:10px; font-weight:700; padding:4px 8px; border-radius:12px; display:none;">
                                <i class="material-icons" style="font-size:11px; vertical-align:middle;">visibility</i> SEDANG DIBUKA
                            </span>
                            <span class="label label-success" style="font-size:11px; font-weight:700; padding:5px 10px; border-radius:20px;">
                                <i class="material-icons" style="font-size:11px; vertical-align:middle;">check_circle</i> SIAP DIGUNAKAN
                            </span>
                        </div>
                    </div>
                    <h3 style="margin:0 0 6px 0; font-size:17px; font-weight:700; color:#1e293b;">AI Recipe Video Generator</h3>
                    <p style="font-size:12px; color:#64748b; line-height:1.5; margin-bottom:16px;">
                        Generate video resep sinematik multi-scene untuk <strong>TikTok, Reels &amp; Shorts</strong> bertenaga <strong>Gemini Storyboard</strong>, <strong>G-Labs Omni Flash</strong>, dan <strong>FFmpeg Video Stitcher</strong>.
                    </p>
                    <ul style="list-style:none; padding:0; margin:0 0 20px 0; font-size:12px; color:#334155; line-height:1.8;">
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> Perumusan Storyboard Otomatis berbasis Direction Resep</li>
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> 5 Preset Visual Dapur &amp; Alat Masak (Pixel Lock Anchor)</li>
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> Render Start Frame T2I &amp; Video Klip I2V Omni Flash</li>
                        <li><i class="material-icons" style="font-size:15px; color:#10b981; vertical-align:middle;">check</i> 1-Click Penggabungan FFmpeg &amp; Simpan ke Data Resep</li>
                    </ul>
                </div>
                <button type="button" class="btn btn-primary" id="btn-toggle-video-studio" onclick="event.stopPropagation(); toggleVideoStudio();" style="background:linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%); border:none; border-radius:8px; padding:10px 18px; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <span id="text-toggle-video-studio">Buka Video Generator Studio</span>
                    <i class="material-icons" id="icon-toggle-video-studio" style="font-size:16px;">arrow_forward</i>
                </button>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- 4 TABS WORKSPACE (IMAGE GENERATOR STUDIO) - INITIALLY HIDDEN -->
        <!-- ==================================================================== -->
        <div id="image-studio-workspace" style="display:none; margin-top:25px;">

            <!-- Studio Control Bar -->
            <div style="display:flex; justify-content:space-between; align-items:center; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:14px 20px; margin-bottom:20px; box-shadow:var(--card-shadow); flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, #6366f1 0%, #a855f7 100%); display:flex; align-items:center; justify-content:center; color:white; box-shadow:0 4px 10px rgba(99,102,241,0.25);">
                        <i class="material-icons" style="font-size:22px;">photo_camera</i>
                    </div>
                    <div>
                        <h4 style="margin:0 0 2px 0; font-size:15px; font-weight:700; color:#1e293b;">
                            AI Recipe Image Studio
                            <span class="label label-success" style="font-size:10px; border-radius:4px; vertical-align:middle; margin-left:6px;">AKTIF</span>
                        </h4>
                        <div style="font-size:11px; color:#64748b;">Pilih tab di bawah untuk merancang foto resep, eksplorasi prompt bebas, riwayat galeri, atau kelola pool API.</div>
                    </div>
                </div>
                <button type="button" class="btn btn-default" onclick="closeImageStudio()" style="border-radius:6px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; background:#f8fafc;">
                    <i class="material-icons" style="font-size:16px; color:#64748b;">expand_less</i> Sembunyikan Studio / Kembali ke Pilihan
                </button>
            </div>

            <!-- Tab Navigation Header -->
            <ul class="nav nav-tabs ai-tabs" role="tablist" id="studio-tabs-nav">
                <li role="presentation" class="active" id="tab-nav-generator">
                    <a href="javascript:void(0);" onclick="switchActiveTab('tab-generator')">
                        <i class="material-icons">restaurant_menu</i>
                        1. Generator Resep (Recipe-to-Image)
                    </a>
                </li>
                <li role="presentation" id="tab-nav-custom">
                    <a href="javascript:void(0);" onclick="switchActiveTab('tab-custom')">
                    <i class="material-icons">palette</i>
                    2. Custom T2I Studio (Prompt Bebas)
                </a>
            </li>
            <li role="presentation" id="tab-nav-history">
                <a href="javascript:void(0);" onclick="switchActiveTab('tab-history')">
                    <i class="material-icons">photo_library</i>
                    3. Riwayat & Galeri AI
                </a>
            </li>
            <li role="presentation" id="tab-nav-settings">
                <a href="javascript:void(0);" onclick="switchActiveTab('tab-settings')">
                    <i class="material-icons">dns</i>
                    4. Pool API & Waterfall Engine
                </a>
            </li>
        </ul>

        <!-- Tab Contents -->
        <div class="tab-content">

            <!-- TAB 1: RECIPE TO IMAGE WORKFLOW -->
            <div role="tabpanel" class="tab-pane active" id="tab-generator">
                <div class="tab-card">

                    <!-- Stepper Indicator -->
                    <div class="step-stepper">
                        <div class="step-item active" id="stepper-1" onclick="jumpToStep(1)">
                            <div class="step-circle">1</div>
                            <div class="step-title">Pilih Resep & Gaya</div>
                        </div>
                        <div class="step-item" id="stepper-2" onclick="jumpToStep(2)">
                            <div class="step-circle">2</div>
                            <div class="step-title">Review Prompt T2I</div>
                        </div>
                        <div class="step-item" id="stepper-3" onclick="jumpToStep(3)">
                            <div class="step-circle">3</div>
                            <div class="step-title">Hasil Render & Terapkan</div>
                        </div>
                    </div>

                    <!-- ================= STEP 1: FORM SELEKSI RESEP (1 KOLOM) ================= -->
                    <div id="step-section-1">
                        <!-- 1.1 Pilih Resep -->
                        <div class="form-group" style="margin-bottom:20px;">
                            <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#6366f1;">search</i>
                                PILIH RESEP DARI DATABASE:
                            </label>
                            <select id="select-recipe" class="form-control" style="height:44px; font-size:13px; font-weight:600; border-radius:8px;" onchange="onRecipeSelected()">
                                <?php if (empty($allRecipes)): ?>
                                    <option value="">Belum ada resep di database</option>
                                <?php else: ?>
                                    <?php foreach ($allRecipes as $idx => $r): ?>
                                        <option value="<?php echo $r['recipe_id']; ?>" <?php echo $idx === 0 ? 'selected' : ''; ?>>
                                            #<?php echo $r['recipe_id']; ?> - <?php echo htmlspecialchars($r['recipe_title']); ?> (<?php echo htmlspecialchars($r['category_name'] ?? 'Resep'); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Selected Recipe Details Card (Full Width) -->
                        <div class="recipe-preview-box" id="recipe-preview-card" style="margin-bottom:25px;">
                            <img id="recipe-preview-img" src="assets/images/ic_launcher.png" class="recipe-preview-thumb" alt="Recipe Thumb" />
                            <div class="recipe-preview-info">
                                <span class="label label-primary" id="recipe-preview-cat" style="font-size:10px; border-radius:4px;">Kategori</span>
                                <h4 id="recipe-preview-title" style="margin-top:6px;">Judul Resep</h4>
                                <p id="recipe-preview-desc">Deskripsi resep akan dimuat otomatis di sini.</p>
                                <div class="recipe-meta-badges">
                                    <div class="recipe-meta-badge"><i class="material-icons">schedule</i> <span id="recipe-preview-time">Waktu</span></div>
                                    <div class="recipe-meta-badge"><i class="material-icons">check_circle</i> Siap Dianalisis AI</div>
                                </div>
                            </div>
                        </div>

                        <!-- 1.2 Preset Gaya Fotografi (Full Width Grid) -->
                        <div style="margin-bottom:25px;">
                            <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#6366f1;">photo_filter</i>
                                PILIH PRESET GAYA FOTOGRAFI:
                            </label>
                            <div class="preset-grid">
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? 'Modern Fine Dining') === 'Modern Fine Dining' ? 'selected' : ''; ?>" onclick="selectPreset(this, 'Modern Fine Dining')">
                                    <h5>Modern Fine Dining</h5>
                                    <small>Piring keramik slate abu-abu, hiasan microgreens, rim lighting dramatis.</small>
                                </div>
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? '') === 'Rustic Kitchen Table' ? 'selected' : ''; ?>" onclick="selectPreset(this, 'Rustic Kitchen Table')">
                                    <h5>Rustic Kitchen Table</h5>
                                    <small>Alas kayu gelap, rempah tersebar alami, pencahayaan natural hangat.</small>
                                </div>
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? '') === 'Crispy Macro Close-Up' ? 'selected' : ''; ?>" onclick="selectPreset(this, 'Crispy Macro Close-Up')">
                                    <h5>Crispy Macro Close-Up</h5>
                                    <small>Sudut 45 derajat, fokus tekstur renyah, uap hangat mengepul halus.</small>
                                </div>
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? '') === 'Top-Down Flatlay' ? 'selected' : ''; ?>" onclick="selectPreset(this, 'Top-Down Flatlay')">
                                    <h5>Top-Down Flatlay</h5>
                                    <small>Foto simetris dari atas meja marmer putih, gaya buku masak editorial modern.</small>
                                </div>
                            </div>
                        </div>

                        <!-- 1.3 Parameter Generator (Full Width Card) -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:22px; margin-bottom:25px;">
                            <h4 style="margin:0 0 16px 0; font-size:14px; font-weight:700; color:#1e293b;">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#6366f1;">tune</i>
                                PARAMETER GENERATOR & FORMAT GAMBAR
                            </h4>

                            <!-- 1 Kolom Parameter Layout -->
                            <!-- Image Count Picker -->
                            <div class="form-group" style="margin-bottom:18px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <label style="font-size:12px; font-weight:600; color:#475569; margin:0;">Jumlah Prompt yang Dibuat:</label>
                                    <span id="badge-count-info" class="badge" style="background:#ede9fe; color:#6d28d9; font-size:11px; font-weight:700;">1 Variasi Prompt</span>
                                </div>
                                <input type="hidden" id="current-count-val" value="1" />
                                <div class="btn-group btn-group-justified" role="group" id="btn-group-count">
                                    <div class="btn-group"><button type="button" class="btn btn-ai-count active" onclick="selectCount(1, this)">1 Variasi</button></div>
                                    <div class="btn-group"><button type="button" class="btn btn-ai-count" onclick="selectCount(2, this)">2 Variasi</button></div>
                                    <div class="btn-group"><button type="button" class="btn btn-ai-count" onclick="selectCount(3, this)">3 Variasi</button></div>
                                    <div class="btn-group"><button type="button" class="btn btn-ai-count" onclick="selectCount(4, this)">4 Variasi</button></div>
                                </div>
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Pilih berapa konsep prompt T2I yang ingin dirancang oleh Gemini.</small>
                            </div>

                            <!-- Aspect Ratio -->
                            <div class="form-group" style="margin-bottom:18px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">Aspek Rasio Gambar:</label>
                                <select class="form-control" id="select-aspect" style="font-size:12px; border-radius:6px; height:40px;">
                                    <option value="4:3" <?php echo ($aiConfig['default_aspect_ratio'] ?? '4:3') === '4:3' ? 'selected' : ''; ?>>4:3 Landscape (Default - Standar Foto Makanan)</option>
                                    <option value="1:1" <?php echo ($aiConfig['default_aspect_ratio'] ?? '') === '1:1' ? 'selected' : ''; ?>>1:1 Square (1024x1024 - Standar Feed Kotak)</option>
                                    <option value="16:9" <?php echo ($aiConfig['default_aspect_ratio'] ?? '') === '16:9' ? 'selected' : ''; ?>>16:9 Wide (Banner Resep)</option>
                                </select>
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Format proporsi foto yang akan diproduksi oleh G-Labs.</small>
                            </div>

                            <!-- Engine Info Row -->
                            <div style="background:white; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-top:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="material-icons" style="color:#6366f1; font-size:18px;">bolt</i>
                                    <span style="font-size:12px; font-weight:600; color:#334155;">Mesin Aktif:</span>
                                </div>
                                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                                    <span style="font-size:12px; color:#475569;">Prompt Brain: <strong class="label label-info" style="border-radius:4px;"><?php echo htmlspecialchars($aiConfig['gemini_model']); ?></strong></span>
                                    <span style="font-size:12px; color:#475569;">Image Generator: <strong class="label label-purple" style="background:#8b5cf6; color:white; border-radius:4px;">G-Labs (<?php echo htmlspecialchars($aiConfig['glabs_model']); ?>)</strong></span>
                                </div>
                            </div>
                        </div>

                        <!-- 1.4 Action Button (Full Width) -->
                        <button id="btn-start-prompt" type="button" class="btn btn-ai-primary" style="width:100%; justify-content:center; font-size:15px; padding:14px; font-weight:700; border-radius:10px;" onclick="generateRecipePrompts()">
                            <i class="material-icons">psychology</i>
                            Langkah 1: Rancang Prompt T2I dengan Gemini Flash
                        </button>
                    </div>

                    <!-- ================= STEP 2: PROMPT REVIEW & EDIT ================= -->
                    <div id="step-section-2" style="display:none;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                            <div>
                                <h3 style="margin:0 0 5px 0; font-size:18px; font-weight:700; color:#1e293b;">
                                    <i class="material-icons" style="color:#6366f1; vertical-align:middle;">rate_review</i>
                                    Review & Sesuaikan Prompt Text-to-Image (T2I)
                                </h3>
                                <p style="margin:0; font-size:13px; color:#64748b;">Gemini Flash telah menganalisis bahan resep. Anda dapat mengedit kata kunci di bawah sebelum dikirim ke G-Labs Webhook.</p>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <button class="btn btn-default" onclick="jumpToStep(1)" style="font-size:12px; border-radius:6px;">
                                    <i class="material-icons" style="font-size:15px; vertical-align:middle;">arrow_back</i> Kembali
                                </button>
                                <button class="btn btn-default" onclick="generateRecipePrompts()" style="font-size:12px; border-radius:6px;">
                                    <i class="material-icons" style="font-size:15px; vertical-align:middle;">refresh</i> Buat Ulang Prompt
                                </button>
                            </div>
                        </div>

                        <!-- Prompt Cards Container -->
                        <div id="prompt-cards-container">
                            <!-- Populated dynamically via JS -->
                        </div>

                        <!-- Generate Images Action Banner -->
                        <div style="background:#f1f5f9; border-radius:12px; padding:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-top:25px;">
                            <div>
                                <div style="font-weight:700; color:#1e293b; font-size:14px;">Prompt sudah sesuai dan siap di-render?</div>
                                <div style="color:#64748b; font-size:12px;">Task akan dikirimkan ke <strong>G-Labs Webhook</strong> (Model: <span id="lbl-glabs-model"><?php echo htmlspecialchars($aiConfig['glabs_model']); ?></span>).</div>
                            </div>
                            <button id="btn-start-render" type="button" class="btn btn-ai-primary" style="font-size:14px; padding:12px 28px;" onclick="renderImageWithGlabs()">
                                <i class="material-icons">brush</i>
                                Langkah 2: Render Gambar (G-Labs Webhook)
                            </button>
                        </div>
                    </div>

                    <!-- ================= STEP 3: PREVIEW & APPLY DECISION ================= -->
                    <div id="step-section-3" style="display:none;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                            <div>
                                <h3 style="margin:0 0 5px 0; font-size:18px; font-weight:700; color:#1e293b;">
                                    <i class="material-icons" style="color:#10b981; vertical-align:middle;">photo_size_select_actual</i>
                                    Hasil Gambar G-Labs & Keputusan Resep
                                </h3>
                                <p style="margin:0; font-size:13px; color:#64748b;">Periksa hasil generasi foto makanan. Klik "Terapkan ke Resep" untuk mengganti foto utama resep.</p>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <button class="btn btn-default" onclick="jumpToStep(2)" style="font-size:12px; border-radius:6px;">
                                    <i class="material-icons" style="font-size:15px; vertical-align:middle;">arrow_back</i> Edit Prompt
                                </button>
                                <button class="btn btn-default" onclick="jumpToStep(1)" style="font-size:12px; border-radius:6px;">
                                    <i class="material-icons" style="font-size:15px; vertical-align:middle;">restaurant</i> Ganti Resep
                                </button>
                            </div>
                        </div>

                        <!-- Progress Bar during G-Labs generation -->
                        <div id="glabs-progress-wrap" style="display:none; margin-bottom:25px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; text-align:center;">
                            <div style="display:flex; justify-content:center; align-items:center; gap:10px; margin-bottom:12px;">
                                <i class="material-icons" style="color:#6366f1; animation:spin 1.5s infinite linear; font-size:24px;">autorenew</i>
                                <span id="glabs-progress-text" style="font-weight:700; font-size:14px; color:#1e293b;">Mengirim task ke G-Labs Webhook...</span>
                            </div>
                            <div class="progress" style="height:12px; border-radius:6px; margin-bottom:6px;">
                                <div class="progress-bar progress-bar-striped active" role="progressbar" id="glabs-progress-bar" style="width: 25%; background:var(--ai-gradient);"></div>
                            </div>
                            <small style="color:#64748b; font-size:11px;" id="glabs-progress-sub">Task ID: <span id="glabs-current-task-id">-</span> • Polling status otomatis setiap 3 detik</small>
                        </div>

                        <!-- Side-by-Side Comparison Box -->
                        <div class="comparison-panel" id="comparison-box" style="display:none;">
                            <div class="comparison-header">
                                <i class="material-icons" style="color:#6366f1;">compare</i>
                                Komparasi Visual Foto Resep:
                            </div>
                            <div class="comparison-grid">
                                <!-- Current Image -->
                                <div class="comparison-thumb-box">
                                    <img id="comparison-current-img" src="assets/images/ic_launcher.png" alt="Foto Saat Ini" />
                                    <div style="font-weight:700; font-size:12px; color:#64748b;" id="comparison-current-label">Foto Saat Ini di Aplikasi (Original)</div>
                                    <small style="color:#94a3b8; font-size:11px;">Tersimpan di database</small>
                                </div>

                                <div class="comparison-arrow">
                                    <i class="material-icons" style="font-size:32px;">trending_flat</i>
                                </div>

                                <!-- New AI Image -->
                                <div class="comparison-thumb-box" style="border:2px solid #10b981; background:#f0fdf4;">
                                    <img id="comparison-ai-img" src="assets/images/ic_launcher.png" alt="Foto Hasil G-Labs" />
                                    <div style="font-weight:700; font-size:12px; color:#059669;" id="comparison-ai-label">Foto Baru (G-Labs AI)</div>
                                    <small style="color:#10b981; font-weight:600; font-size:11px;" id="comparison-ai-status">Siap Diterapkan</small>
                                </div>
                            </div>
                        </div>

                        <!-- Action Card -->
                        <div id="result-action-panel" style="display:none; margin-top:20px; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:var(--card-shadow);">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                                <div>
                                    <h4 style="margin:0 0 5px 0; font-size:15px; font-weight:700; color:#1e293b;">Puas dengan foto makanan ini?</h4>
                                    <p style="margin:0; font-size:12px; color:#64748b;">Foto lama Anda akan diarsipkan secara otomatis dan dapat dikembalikan sewaktu-waktu.</p>
                                </div>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-success" id="btn-apply-main" onclick="applyImageNow()" style="padding:10px 18px; font-weight:600; border-radius:8px;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">check_circle</i> Terapkan ke Resep
                                    </button>
                                    <button type="button" class="btn bg-cyan" id="btn-apply-and-push" onclick="applyAndPushNow()" style="padding:10px 18px; font-weight:600; border-radius:8px; color:#fff;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">cloud_upload</i> 🚀 Terapkan & Push ke Live Hosting
                                    </button>
                                    <button type="button" class="btn btn-default" onclick="openLightbox($('#comparison-ai-img').attr('src'), 'Foto Hasil G-Labs')" style="padding:10px 15px; font-weight:600; border-radius:8px;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">zoom_in</i> Perbesar
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

            <!-- TAB 2: CUSTOM T2I STUDIO (1 KOLOM) -->
            <div role="tabpanel" class="tab-pane" id="tab-custom">
                <div class="tab-card">
                    <div style="margin-bottom:20px;">
                        <h3 style="margin:0 0 6px 0; font-size:18px; font-weight:700; color:#1e293b;">
                            <i class="material-icons" style="color:#a855f7; vertical-align:middle;">palette</i>
                            Custom Text-to-Image Studio
                        </h3>
                        <p style="margin:0; font-size:13px; color:#64748b;">Ketik prompt makanan bebas tanpa harus memilih resep database. Cocok untuk gambar banner kategori, promosi musiman, atau eksplorasi ide kuliner.</p>
                    </div>

                    <!-- Tips Prompt Makanan Callout (Full Width) -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #a855f7; border-radius:8px; padding:14px 18px; margin-bottom:22px;">
                        <h5 style="margin:0 0 6px 0; font-size:13px; font-weight:700; color:#1e293b;">
                            <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#a855f7;">lightbulb</i>
                            Tips Perumusan Prompt Makanan Fotorealistis:
                        </h5>
                        <div style="font-size:12px; color:#64748b; line-height:1.6;">
                            • <strong>Tekstur & Keadaan:</strong> <i>"crispy blistered golden skin, steaming hot vapor, glistening honey glaze"</i><br>
                            • <strong>Pencahayaan & Suasana:</strong> <i>"warm afternoon window sunlight, rim lighting, atmospheric depth"</i><br>
                            • <strong>Alas & Perlengkapan:</strong> <i>"dark matte ceramic plate on rustic reclaimed wood, scattered herbs"</i><br>
                            • <strong>Kamera & Lensa:</strong> <i>"85mm macro lens, shallow depth of field, photorealistic 8k food photography"</i>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="font-weight:700; font-size:13px; color:#1e293b;">Tulis Prompt Lengkap (Bahasa Inggris disarankan):</label>
                        <textarea class="form-control" rows="5" id="custom-prompt-input" style="border-radius:8px; font-size:13px; padding:14px; line-height:1.5;" placeholder="Contoh: Modern air fryer appliance sitting on an elegant kitchen marble island, filled with golden crispy hand-cut french fries, soft natural morning sunlight shining through window, cinematic depth of field, 8k resolution..."></textarea>
                    </div>

                    <div style="display:flex; gap:8px; margin-bottom:22px; flex-wrap:wrap; align-items:center;">
                        <span style="font-size:12px; font-weight:600; color:#64748b;">Contoh Cepat:</span>
                        <button type="button" class="btn btn-xs btn-default" onclick="setCustomPrompt(1)" style="border-radius:4px; font-size:11px;">🍟 Airfryer French Fries</button>
                        <button type="button" class="btn btn-xs btn-default" onclick="setCustomPrompt(2)" style="border-radius:4px; font-size:11px;">🐟 Crispy Salmon Fillet</button>
                        <button type="button" class="btn btn-xs btn-default" onclick="setCustomPrompt(3)" style="border-radius:4px; font-size:11px;">🍫 Airfryer Chocolate Lava Cake</button>
                    </div>

                    <!-- Parameter Box (Desain 1 Kolom) -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:25px;">
                        <div class="form-group" style="margin-bottom:16px;">
                            <label style="font-size:12px; font-weight:700; color:#475569;">Aspek Rasio:</label>
                            <select class="form-control" id="custom-aspect" style="border-radius:6px; font-size:12px; height:40px;">
                                <option value="4:3" selected>4:3 Classic Photo (Landscape Standar)</option>
                                <option value="1:1">1:1 Square (Mobile App Feed)</option>
                                <option value="16:9">16:9 Landscape (Banner Slider)</option>
                            </select>
                            <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Pilih rasio aspek sesuai media penempatan gambar.</small>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="font-size:12px; font-weight:700; color:#475569;">Model Render G-Labs:</label>
                            <select class="form-control" id="custom-model" style="border-radius:6px; font-size:12px; height:40px;">
                                <option value="nano_banana_2" selected>nano_banana_2 (Default Flow - Cepat & Konsisten)</option>
                                <option value="nano_banana_pro">nano_banana_pro (Ultra Detail Photorealism)</option>
                                <option value="nano_pics">nano_pics (Google Pics via Google Accounts)</option>
                            </select>
                            <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Model generator yang bertugas merender prompt ke gambar.</small>
                        </div>
                    </div>

                    <!-- Action Button (Full Width) -->
                    <button class="btn btn-ai-primary" style="width:100%; justify-content:center; font-size:15px; padding:14px; font-weight:700; border-radius:10px;" onclick="renderCustomImage()">
                        <i class="material-icons">auto_awesome</i>
                        Render Gambar via G-Labs Webhook
                    </button>
                </div>
            </div>

            <!-- TAB 3: RIWAYAT & GALERI AI -->
            <div role="tabpanel" class="tab-pane" id="tab-history">
                <div class="tab-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h3 style="margin:0 0 5px 0; font-size:18px; font-weight:700; color:#1e293b;">
                                <i class="material-icons" style="color:#6366f1; vertical-align:middle;">photo_library</i>
                                Riwayat & Galeri Generasi AI
                            </h3>
                            <p style="margin:0; font-size:13px; color:#64748b;">Daftar seluruh generasi gambar. Anda dapat mengembalikan foto asli resep (*1-Click Revert*) kapan saja.</p>
                        </div>
                        <button class="btn btn-default" onclick="loadHistoryTable()" style="font-size:12px; border-radius:6px;">
                            <i class="material-icons" style="font-size:14px; vertical-align:middle;">refresh</i> Muat Ulang
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-ai" id="table-history">
                            <thead>
                                <tr>
                                    <th style="width:70px;">Thumbnail</th>
                                    <th>Target Resep</th>
                                    <th>Model & Task ID</th>
                                    <th>Tanggal</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="history-table-body">
                                <tr>
                                    <td colspan="6" style="text-align:center; color:#94a3b8; padding:30px;">Memuat riwayat...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: POOL API & WATERFALL ENGINE -->
            <div role="tabpanel" class="tab-pane" id="tab-settings">
                <div class="tab-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:15px;">
                        <div>
                            <h3 style="margin:0 0 6px 0; font-size:18px; font-weight:700; color:#1e293b;">
                                <i class="material-icons" style="color:#6366f1; vertical-align:middle;">dns</i>
                                Pool API Key & Rantai Eksekusi Berjenjang (Waterfall Fallback)
                            </h3>
                            <p style="margin:0; font-size:13px; color:#64748b;">
                                Kelola pool API Key Google Gemini (multi-key failover 429) dan orkestrasi berjenjang (waterfall cascade) saat model mengalami 503 high demand.
                            </p>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-warning" onclick="resetAllKeysCooldown()" id="btn-reset-cooldown" style="font-size:12px; border-radius:6px; font-weight:600; background:#f59e0b; color:#fff; border:none;">
                                <i class="material-icons" style="font-size:15px; vertical-align:middle;">refresh</i> Reset Cooldown Pool
                            </button>
                            <button type="button" class="btn btn-default" onclick="testAllKeysInPool()" id="btn-test-all-keys" style="font-size:12px; border-radius:6px; font-weight:600;">
                                <i class="material-icons" style="font-size:15px; vertical-align:middle; color:#10b981;">network_check</i> Tes Semua Kunci
                            </button>
                            <button type="button" class="btn btn-primary" onclick="openAddKeyModal()" style="background:#6366f1; border:none; font-size:12px; border-radius:6px; font-weight:600;">
                                <i class="material-icons" style="font-size:15px; vertical-align:middle;">add</i> Tambah Kunci Baru
                            </button>
                        </div>
                    </div>

                    <!-- SECTION 1: KEY POOL TABLE -->
                    <div class="table-responsive" style="margin-bottom:25px;">
                        <table class="table table-hover key-pool-table" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th>Nama Label</th>
                                    <th>API Key (Masked)</th>
                                    <th>Batas Harian (RPD)</th>
                                    <th>Status Saat Ini</th>
                                    <th>Terakhir Dipakai</th>
                                    <th style="text-align:right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="key-pool-tbody">
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:25px; color:#94a3b8;">
                                        <i class="material-icons" style="animation:spin 1s infinite linear; font-size:20px; vertical-align:middle;">autorenew</i> Memuat pool API Key...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <form id="form-ai-config" onsubmit="saveAiConfig(event)">
                        <!-- SECTION 2: WATERFALL FALLBACK ENGINE (1 KOLOM) -->
                        <div style="border-top:1px solid #e2e8f0; padding-top:25px; margin-top:20px;">
                            <div style="margin-bottom:18px;">
                                <h4 style="margin:0 0 6px 0; font-size:16px; font-weight:700; color:#1e293b;">
                                    <i class="material-icons" style="color:#6366f1; vertical-align:middle;">alt_route</i>
                                    2. Rantai Eksekusi Berjenjang (Waterfall Fallback & Retry)
                                </h4>
                                <p style="margin:0; font-size:12px; color:#64748b;">
                                    Persis arsitektur <em>ContentFlow-Staging</em>: Jika model mengalami <strong>503 High Demand</strong>, sistem otomatis <strong>retry hingga 2x</strong> lalu turun tingkat (*cascade*) ke model cadangan berikutnya.
                                </p>
                            </div>

                            <!-- Primary Model Selector (Full Width) -->
                            <div class="form-group" style="margin-bottom:16px;">
                                <label style="font-size:12px; font-weight:700; color:#475569;">PILIH MODEL UTAMA (PRIMARY):</label>
                                <select class="form-control" name="gemini_model" id="select-primary-model" onchange="updateWaterfallVisualizer()" style="border-radius:8px; height:44px; font-size:13px; font-weight:600;">
                                    <option value="gemini-2.5-flash" <?php echo ($aiConfig['gemini_model'] ?? '') === 'gemini-2.5-flash' || empty($aiConfig['gemini_model']) ? 'selected' : ''; ?>>gemini-2.5-flash (Direkomendasikan - Sangat Stabil & Cepat ~1.2s)</option>
                                    <option value="gemini-3.5-flash" <?php echo ($aiConfig['gemini_model'] ?? '') === 'gemini-3.5-flash' ? 'selected' : ''; ?>>gemini-3.5-flash (Generasi Terkini - Responsif & Tajam ~1.9s)</option>
                                    <option value="gemini-3.6-flash" <?php echo ($aiConfig['gemini_model'] ?? '') === 'gemini-3.6-flash' ? 'selected' : ''; ?>>gemini-3.6-flash (Sangat Cepat & Stabil ~2.2s)</option>
                                    <option value="gemini-3-flash-preview" <?php echo ($aiConfig['gemini_model'] ?? '') === 'gemini-3-flash-preview' ? 'selected' : ''; ?>>gemini-3-flash-preview (Pratinjau Ringan ~1.5s)</option>
                                    <option value="gemini-flash-lite-latest" <?php echo ($aiConfig['gemini_model'] ?? '') === 'gemini-flash-lite-latest' ? 'selected' : ''; ?>>gemini-flash-lite-latest (Versi Lite Terkini ~1.2s)</option>
                                    <option value="gemini-3.5-flash-lite" <?php echo ($aiConfig['gemini_model'] ?? '') === 'gemini-3.5-flash-lite' ? 'selected' : ''; ?>>gemini-3.5-flash-lite (Super Ringan & Tercepat ~1.0s)</option>
                                </select>
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Model ini akan dieksekusi pertama kali pada setiap pembuatan prompt.</small>
                            </div>

                            <input type="hidden" name="gemini_max_retries" value="2" />
                            <input type="hidden" name="gemini_retry_delay_ms" value="2000" />
                            <input type="hidden" name="gemini_api_key" id="cfg-gemini-key" value="<?php echo htmlspecialchars($aiConfig['gemini_api_key'] ?? ''); ?>" />

                            <!-- Mekanisme Ketahanan Box (Full Width) -->
                            <div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; padding:12px 16px; font-size:12px; color:#92400e; margin-bottom:18px;">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#d97706;">security</i>
                                <strong>Mekanisme Ketahanan 503, 404 & 429:</strong>
                                <ul style="margin:6px 0 0 16px; padding:0; line-height:1.5;">
                                    <li><strong>503 High Demand / Timeout</strong>: Langsung pindah ke model berikutnya tanpa membuang waktu.</li>
                                    <li><strong>404 Deprecated</strong>: Langsung pindah ke model berikutnya tanpa retry.</li>
                                    <li><strong>429 Model Quota</strong>: Kaskade otomatis ke model berikutnya pada kunci yang sama. Jika seluruh model habis kuota, rotasi ke API Key berikutnya di Pool (Cooldown 24 Jam).</li>
                                </ul>
                            </div>

                            <!-- Live Waterfall Cascade Flow (Full Width Stack) -->
                            <div class="waterfall-box" style="margin-top:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <label style="font-size:12px; font-weight:700; color:#1e293b; margin:0;">
                                        <i class="material-icons" style="font-size:15px; vertical-align:middle; color:#6366f1;">visibility</i>
                                        LIVE WATERFALL CASCADE FLOW:
                                    </label>
                                    <span class="badge" style="background:#ede9fe; color:#6d28d9; font-size:10px;">Simulasi Real-Time</span>
                                </div>

                                <div class="waterfall-flow" id="waterfall-container">
                                    <!-- Dynamically generated via updateWaterfallVisualizer() -->
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 3: G-LABS WEBHOOK CONFIG (1 KOLOM) -->
                        <div style="border-top:1px solid #e2e8f0; padding-top:25px; margin-top:30px;">
                            <h4 style="margin:0 0 15px 0; font-size:16px; font-weight:700; color:#1e293b;">
                                <i class="material-icons" style="color:#6366f1; vertical-align:middle;">router</i>
                                3. G-Labs Automation Webhook (Image Generator)
                            </h4>

                            <!-- Desain 1 Kolom untuk Pengaturan G-Labs -->
                            <div class="form-group" style="margin-bottom:16px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">G-Labs Host / URL:</label>
                                <input type="text" class="form-control" name="glabs_host" id="cfg-glabs-host" value="<?php echo htmlspecialchars($aiConfig['glabs_host'] ?? '127.0.0.1'); ?>" placeholder="127.0.0.1 atau IP LAN / Cloudflare Tunnel" style="border-radius:6px; font-size:12px; height:40px;" />
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Gunakan <code>127.0.0.1</code> jika di localhost, atau URL tunnel jika di server publik.</small>
                            </div>

                            <div class="form-group" style="margin-bottom:16px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">Port:</label>
                                <input type="number" class="form-control" name="glabs_port" id="cfg-glabs-port" value="<?php echo htmlspecialchars($aiConfig['glabs_port'] ?? '8765'); ?>" placeholder="8765" style="border-radius:6px; font-size:12px; height:40px;" />
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Port default aplikasi G-Labs adalah <code>8765</code>.</small>
                            </div>

                            <div class="form-group" style="margin-bottom:18px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">Model Render G-Labs:</label>
                                <select class="form-control" name="glabs_model" style="border-radius:6px; font-size:12px; height:40px;">
                                    <option value="nano_banana_2" <?php echo ($aiConfig['glabs_model'] ?? '') === 'nano_banana_2' ? 'selected' : ''; ?>>nano_banana_2 (Cepat, Stabil & Konsisten)</option>
                                    <option value="nano_banana_pro" <?php echo ($aiConfig['glabs_model'] ?? '') === 'nano_banana_pro' ? 'selected' : ''; ?>>nano_banana_pro (Ultra Detail Photorealism)</option>
                                    <option value="nano_pics" <?php echo ($aiConfig['glabs_model'] ?? '') === 'nano_pics' ? 'selected' : ''; ?>>nano_pics (Google Pics via Google Accounts)</option>
                                    <option value="meta_muse" <?php echo ($aiConfig['glabs_model'] ?? '') === 'meta_muse' ? 'selected' : ''; ?>>meta_muse (Meta Muse)</option>
                                </select>
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Pilih engine model image renderer yang diinginkan.</small>
                            </div>

                            <div class="form-group" style="margin-bottom:18px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">Default Aspek Rasio Gambar:</label>
                                <select class="form-control" name="default_aspect_ratio" style="border-radius:6px; font-size:12px; height:40px;">
                                    <option value="4:3" <?php echo ($aiConfig['default_aspect_ratio'] ?? '4:3') === '4:3' ? 'selected' : ''; ?>>4:3 Landscape (Standar Foto Makanan)</option>
                                    <option value="1:1" <?php echo ($aiConfig['default_aspect_ratio'] ?? '') === '1:1' ? 'selected' : ''; ?>>1:1 Square (1024x1024 - Feed Kotak)</option>
                                    <option value="16:9" <?php echo ($aiConfig['default_aspect_ratio'] ?? '') === '16:9' ? 'selected' : ''; ?>>16:9 Wide (Banner Resep)</option>
                                </select>
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Proporsi bawaan saat membuka menu pembuatan gambar.</small>
                            </div>

                            <div class="form-group" style="margin-bottom:18px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">Default Preset Gaya Fotografi:</label>
                                <select class="form-control" name="default_style" style="border-radius:6px; font-size:12px; height:40px;">
                                    <option value="Modern Fine Dining" <?php echo ($aiConfig['default_style'] ?? 'Modern Fine Dining') === 'Modern Fine Dining' ? 'selected' : ''; ?>>Modern Fine Dining (Piring keramik slate abu-abu, microgreens, rim lighting)</option>
                                    <option value="Rustic Kitchen Table" <?php echo ($aiConfig['default_style'] ?? '') === 'Rustic Kitchen Table' ? 'selected' : ''; ?>>Rustic Kitchen Table (Alas kayu gelap, rempah alami, hangat)</option>
                                    <option value="Crispy Macro Close-Up" <?php echo ($aiConfig['default_style'] ?? '') === 'Crispy Macro Close-Up' ? 'selected' : ''; ?>>Crispy Macro Close-Up (Sudut 45°, tekstur renyah, uap halus)</option>
                                    <option value="Top-Down Flatlay" <?php echo ($aiConfig['default_style'] ?? '') === 'Top-Down Flatlay' ? 'selected' : ''; ?>>Top-Down Flatlay (Simetris atas meja marmer putih)</option>
                                </select>
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Preset estetika default saat membuka generator gambar.</small>
                            </div>

                            <div class="form-group" style="margin-bottom:20px;">
                                <label style="font-weight:700; font-size:13px; color:#1e293b;">G-Labs Webhook API Key:</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="glabs_api_key" id="cfg-glabs-key" value="<?php echo htmlspecialchars($aiConfig['glabs_api_key'] ?? ''); ?>" placeholder="Token API dari tab Webhook aplikasi G-Labs" style="font-family:monospace; height:44px; border-radius:8px 0 0 8px;" />
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" onclick="toggleMask('cfg-glabs-key', 'icon-eye-glabs')" style="height:44px; border-radius:0 8px 8px 0;">
                                            <i class="material-icons" id="icon-eye-glabs" style="font-size:18px;">visibility</i>
                                        </button>
                                    </span>
                                </div>
                                <small style="color:#64748b; font-size:11px; margin-top:5px; display:block;">Buka aplikasi desktop G-Labs Automation → Tab <strong>Webhook</strong> → Salin API Key.</small>
                            </div>

                            <div style="display:flex; gap:12px; margin-top:20px; flex-wrap:wrap;">
                                <button type="submit" class="btn btn-ai-primary" id="btn-save-cfg">
                                    <i class="material-icons">save</i> Simpan Pengaturan
                                </button>
                                <button type="button" class="btn btn-default" onclick="testGlabsConnection()" style="border-radius:8px; font-weight:600;">
                                    <i class="material-icons" style="color:#10b981; font-size:16px;">wifi_tethering</i> Uji Koneksi G-Labs
                                </button>
                            </div>

                            <!-- Full Width G-Labs Guide Box -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px 20px; margin-top:25px;">
                                <h5 style="margin:0 0 10px 0; font-size:13px; font-weight:700; color:#1e293b;">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#10b981;">help_outline</i>
                                    Panduan Singkat G-Labs Webhook
                                </h5>
                                <div style="font-size:12px; color:#475569; line-height:1.6;">
                                    <strong>1. Jalankan G-Labs:</strong> Buka aplikasi desktop G-Labs di komputer Anda.<br>
                                    <strong>2. Aktifkan Webhook:</strong> Buka tab <strong>Webhook</strong>, klik tombol <strong>Start</strong> (port default 8765).<br>
                                    <strong>3. Masukkan Kunci:</strong> Salin API Key dari tab Webhook ke kolom di atas lalu simpan.<br>
                                    <strong>4. Tailscale LAN:</strong> Gunakan IP Tailscale NUC (<code>100.64.70.61:8765</code>) untuk koneksi langsung tanpa tunnel.
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 4: SINKRONISASI KE LIVE WEB HOSTING (AIRFRY.WEB.ID) -->
                        <div style="border-top:1px solid #e2e8f0; padding-top:25px; margin-top:30px;">
                            <h4 style="margin:0 0 15px 0; font-size:16px; font-weight:700; color:#1e293b;">
                                <i class="material-icons" style="color:#00bcd4; vertical-align:middle;">cloud_sync</i>
                                4. Sinkronisasi ke Web Hosting Live (airfry.web.id)
                            </h4>

                            <div class="form-group" style="margin-bottom:16px;">
                                <label style="font-size:12px; font-weight:600; color:#475569;">Target URL Receiver Hosting:</label>
                                <input type="text" class="form-control" name="sync_target_url" id="cfg-sync-url" value="<?php echo htmlspecialchars($aiConfig['sync_target_url'] ?? 'https://airfry.web.id/app/api_sync_recipe.php'); ?>" placeholder="https://airfry.web.id/app/api_sync_recipe.php" style="border-radius:6px; font-size:12px; height:40px;" />
                                <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">URL file endpoint <code>api_sync_recipe.php</code> di server live hosting Anda.</small>
                            </div>

                            <div class="form-group" style="margin-bottom:20px;">
                                <label style="font-weight:700; font-size:13px; color:#1e293b;">Secret Bearer Token:</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="sync_secret_token" id="cfg-sync-token" value="<?php echo htmlspecialchars($aiConfig['sync_secret_token'] ?? 'airfry_sync_sec_99a8b7c6d5e4f3a2b1'); ?>" placeholder="Token otentikasi sync" style="font-family:monospace; height:44px; border-radius:8px 0 0 8px;" />
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" onclick="toggleMask('cfg-sync-token', 'icon-eye-sync')" style="height:44px; border-radius:0 8px 8px 0;">
                                            <i class="material-icons" id="icon-eye-sync" style="font-size:18px;">visibility</i>
                                        </button>
                                    </span>
                                </div>
                                <small style="color:#64748b; font-size:11px; margin-top:5px; display:block;">Token rahasia ini melindungi endpoint dari akses publik tanpa izin.</small>
                            </div>

                            <div style="display:flex; gap:12px; margin-top:20px; flex-wrap:wrap;">
                                <button type="submit" class="btn btn-ai-primary">
                                    <i class="material-icons">save</i> Simpan Pengaturan
                                </button>
                                <button type="button" class="btn btn-default" onclick="testLiveSyncConnection()" style="border-radius:8px; font-weight:600;">
                                    <i class="material-icons" style="color:#00bcd4; font-size:16px;">network_check</i> Uji Koneksi Hosting Live
                                </button>
                            </div>

                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px 20px; margin-top:25px;">
                                <h5 style="margin:0 0 10px 0; font-size:13px; font-weight:700; color:#166534;">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#16a34a;">check_circle</i>
                                    Alur Kerja Local Studio
                                </h5>
                                <div style="font-size:12px; color:#15803d; line-height:1.6;">
                                    Semua proses generate foto AI via GPU Windows NUC berjalan langsung di jaringan lokal dengan kecepatan penuh tanpa hambatan timeout.<br>
                                    Klik tombol <strong>[ 🚀 Terapkan & Push ke Live Hosting ]</strong> untuk langsung mengirim foto dan resep ke website publik Anda secara instan.
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 5: IMAGE OPTIMIZER UTILITY -->
                        <div style="border-top:1px solid #e2e8f0; padding-top:25px; margin-top:30px;">
                            <h4 style="margin:0 0 15px 0; font-size:16px; font-weight:700; color:#1e293b;">
                                <i class="material-icons" style="color:#f59e0b; vertical-align:middle;">compress</i>
                                5. Utilitas Optimasi Gambar (Image Optimizer)
                            </h4>
                            <p style="margin:0 0 15px 0; font-size:12px; color:#64748b;">
                                Semua foto hasil AI, impor URL, dan upload baru otomatis dioptimasi menjadi <strong>Progressive JPEG 1080px @ 82%</strong> (~150 KB). Gunakan fitur di bawah untuk memindai dan mengecilkan foto-foto lama yang tersimpan di server.
                            </p>

                            <div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:10px; padding:16px 20px; margin-bottom:15px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                    <div id="batch-opt-status" style="font-size:12px; color:#92400e;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#d97706;">help_outline</i>
                                        Klik tombol di bawah untuk memindai berkas foto yang dapat dioptimasi.
                                    </div>
                                    <div style="display:flex; gap:10px;">
                                        <button type="button" class="btn btn-default" onclick="scanOptimizableImages()" style="border-radius:8px; font-size:12px; font-weight:600;">
                                            <i class="material-icons" style="font-size:15px; vertical-align:middle; color:#6366f1;">search</i> Pindai Berkas
                                        </button>
                                        <button type="button" class="btn btn-warning" id="btn-run-batch-opt" onclick="runBatchOptimizer()" disabled style="border-radius:8px; font-size:12px; font-weight:700; background:#f59e0b; border:none; color:white;">
                                            <i class="material-icons" style="font-size:15px; vertical-align:middle;">bolt</i> Jalankan Batch Optimize
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
            </div>

        </div>
        </div>
        <!-- End of #image-studio-workspace -->

        <!-- ==================================================================== -->
        <!-- AI RECIPE VIDEO STUDIO WORKSPACE - INITIALLY HIDDEN -->
        <!-- ==================================================================== -->
        <div id="video-studio-workspace" style="display:none; margin-top:25px;">

            <!-- Studio Control Bar -->
            <div style="display:flex; justify-content:space-between; align-items:center; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:14px 20px; margin-bottom:20px; box-shadow:var(--card-shadow); flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%); display:flex; align-items:center; justify-content:center; color:white; box-shadow:0 4px 10px rgba(236,72,153,0.25);">
                        <i class="material-icons" style="font-size:22px;">videocam</i>
                    </div>
                    <div>
                        <h4 style="margin:0 0 2px 0; font-size:15px; font-weight:700; color:#1e293b;">
                            AI Recipe Video Studio
                            <span class="label" style="background:#8b5cf6; font-size:10px; border-radius:4px; vertical-align:middle; margin-left:6px;">VEO OMNI FLASH &amp; FFMPEG</span>
                            <span class="label label-success" style="font-size:10px; border-radius:4px; vertical-align:middle; margin-left:4px;">AKTIF</span>
                        </h4>
                        <div style="font-size:11px; color:#64748b;">Rancang storyboard berbasis direction resep, render start-frame Pixel-Lock, klip motion I2V, dan satukan dengan FFmpeg.</div>
                    </div>
                </div>
                <button type="button" class="btn btn-default" onclick="closeVideoStudio()" style="border-radius:6px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; background:#f8fafc;">
                    <i class="material-icons" style="font-size:16px; color:#64748b;">expand_less</i> Sembunyikan Studio / Kembali ke Pilihan
                </button>
            </div>

            <div class="tab-card">
                <!-- Stepper Indicator -->
                <div class="step-stepper" style="margin-bottom:25px;">
                    <div class="step-item active" id="video-stepper-1" onclick="jumpToVideoStep(1)">
                        <div class="step-circle">1</div>
                        <div class="step-title">1. Resep &amp; Dapur</div>
                    </div>
                    <div class="step-item" id="video-stepper-2" onclick="jumpToVideoStep(2)">
                        <div class="step-circle">2</div>
                        <div class="step-title">2. Storyboard &amp; Start Frame</div>
                    </div>
                    <div class="step-item" id="video-stepper-3" onclick="jumpToVideoStep(3)">
                        <div class="step-circle">3</div>
                        <div class="step-title">3. Setting I2V &amp; Klip Video</div>
                    </div>
                    <div class="step-item" id="video-stepper-4" onclick="jumpToVideoStep(4)">
                        <div class="step-circle">4</div>
                        <div class="step-title">4. Video Utuh &amp; Simpan</div>
                    </div>
                </div>

                <!-- ================= STEP 1: FORM SELEKSI RESEP & PRESET DAPUR ================= -->
                <div id="video-step-section-1">
                    <div class="row clearfix">
                        <div class="col-md-7 col-xs-12">
                            <!-- 1.1 Pilih Resep -->
                            <div class="form-group" style="margin-bottom:20px;">
                                <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">
                                    Pilih Resep untuk Dibuatkan Video: <span class="text-danger">*</span>
                                </label>
                                <select id="videoRecipeSelect" class="form-control show-tick" data-live-search="true" onchange="onVideoRecipeSelectChange()">
                                    <?php foreach ($allRecipes as $r): ?>
                                        <option value="<?php echo $r['recipe_id']; ?>" data-subtext="<?php echo htmlspecialchars($r['category_name'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($r['recipe_title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 1.2 Storyboard Mode & Rasio -->
                            <div class="row clearfix" style="margin-bottom:15px;">
                                <div class="col-sm-6 col-xs-12">
                                    <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">
                                        Mode Pembuatan Scene:
                                    </label>
                                    <input type="hidden" id="selectedStoryboardMode" name="video_storyboard_mode" value="reels">
                                    <div style="display:flex; flex-direction:column; gap:8px;" id="storyboardModeOptions">
                                        <div class="video-select-card selected" onclick="selectStoryboardMode('reels', this)">
                                            <div class="custom-radio-dot"></div>
                                            <div>
                                                <strong style="color:#1e293b; font-size:12px;">Reels / Shorts (4–5 Klip Padat)</strong>
                                                <div style="font-size:11px; color:#64748b;">Cocok untuk video viral vertikal (Hook ➔ Cook ➔ Sizzle ➔ Plate)</div>
                                            </div>
                                        </div>
                                        <div class="video-select-card" onclick="selectStoryboardMode('full', this)">
                                            <div class="custom-radio-dot"></div>
                                            <div>
                                                <strong style="color:#1e293b; font-size:12px;">Full Steps (1:1 Semua Direction)</strong>
                                                <div style="font-size:11px; color:#64748b;">1 klip per setiap butir langkah memasak pada resep</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xs-12">
                                    <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">
                                        Rasio Aspek Video:
                                    </label>
                                    <input type="hidden" id="selectedAspectRatio" name="video_aspect_ratio" value="9:16">
                                    <div style="display:flex; flex-direction:column; gap:8px;" id="aspectRatioOptions">
                                        <div class="video-select-card selected" onclick="selectAspectRatio('9:16', this)">
                                            <div class="custom-radio-dot"></div>
                                            <div>
                                                <strong style="color:#1e293b; font-size:12px;">9:16 (Vertikal TikTok / Reels / Shorts)</strong>
                                                <div style="font-size:11px; color:#64748b;">Format vertikal optimal untuk mobile smartphone</div>
                                            </div>
                                        </div>
                                        <div class="video-select-card" onclick="selectAspectRatio('16:9', this)">
                                            <div class="custom-radio-dot"></div>
                                            <div>
                                                <strong style="color:#1e293b; font-size:12px;">16:9 (Horizontal YouTube / Landscape)</strong>
                                                <div style="font-size:11px; color:#64748b;">Format horizontal standar web &amp; desktop</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 1.3 Preset Dapur & Alat Masak -->
                            <div class="form-group" style="margin-bottom:20px;">
                                <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">
                                    Pilih Karakter Dapur &amp; Alat Masak (Pixel-Lock Anchor):
                                </label>
                                <div style="display:flex; flex-direction:column; gap:8px;" id="kitchenPresetOptions">
                                    <div class="preset-kitchen-card selected" onclick="selectKitchenPreset('Modern Scandinavian Kitchen', this)">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="font-size:13px; color:#1e1b4b;">1. Modern Scandinavian Kitchen</strong>
                                            <span class="badge" style="background:#6366f1;">DEFAULT REKOMENDASI</span>
                                        </div>
                                        <div style="font-size:11px; color:#475569; margin-top:4px;">
                                            Meja quartz stone putih bersih, kabinet light oak, air fryer matte black beraksen copper, capit stainless steel, pencahayaan alami jendela samping (*soft daylight*).
                                        </div>
                                    </div>
                                    <div class="preset-kitchen-card" onclick="selectKitchenPreset('Rustic Industrial Chef Kitchen', this)">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="font-size:13px; color:#1e1b4b;">2. Rustic Industrial Chef Kitchen</strong>
                                            <span class="badge" style="background:#64748b;">MOODY GOURMET</span>
                                        </div>
                                        <div style="font-size:11px; color:#475569; margin-top:4px;">
                                            Meja granit dark slate, talenan dark walnut tebal, air fryer brushed stainless steel, pisau chef baja Damaskus, pencahayaan rim light sinematik kontras tinggi.
                                        </div>
                                    </div>
                                    <div class="preset-kitchen-card" onclick="selectKitchenPreset('Cozy Warm Home Kitchen', this)">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="font-size:13px; color:#1e1b4b;">3. Cozy Warm Home Kitchen</strong>
                                            <span class="badge" style="background:#d97706;">HANGAT &amp; OTENTIK</span>
                                        </div>
                                        <div style="font-size:11px; color:#475569; margin-top:4px;">
                                            Meja kayu butcher block hangat, toples bumbu kaca, air fryer charcoal bulat, wadah saji keramik artisan, nuansa hangat golden hour sinar matahari pagi.
                                        </div>
                                    </div>
                                    <div class="preset-kitchen-card" onclick="selectKitchenPreset('Futuristic Cyber Gourmet Studio', this)">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="font-size:13px; color:#1e1b4b;">4. Futuristic Cyber Gourmet Studio</strong>
                                            <span class="badge" style="background:#0284c7;">MACRO SPOTLIGHT</span>
                                        </div>
                                        <div style="font-size:11px; color:#475569; margin-top:4px;">
                                            Meja marmer hitam mengkilap, air fryer display LED biru-putih modern, spatula matte, pencahayaan makro tajam dramatis, tekstur makanan garing terlihat detail.
                                        </div>
                                    </div>
                                    <div class="preset-kitchen-card" onclick="selectKitchenPreset('Bright Parisian Cafe / Bistro', this)">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="font-size:13px; color:#1e1b4b;">5. Bright Parisian Cafe / Bistro</strong>
                                            <span class="badge" style="background:#059669;">BUKU RESEP EROPA</span>
                                        </div>
                                        <div style="font-size:11px; color:#475569; margin-top:4px;">
                                            Meja marmer Carrara putih berurat abu-abu lembut, piring enamel tepi biru, air fryer creamy beige vintage-modern, pencahayaan terang high-key elegan.
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" id="selectedKitchenPreset" value="Modern Scandinavian Kitchen">
                            </div>

                            <!-- Tombol Rancang Storyboard -->
                            <button type="button" id="btnGenerateStoryboard" class="btn btn-primary btn-block waves-effect" onclick="handleGenerateStoryboard()" style="background:var(--ai-gradient); border:none; border-radius:8px; padding:12px; font-weight:700; font-size:14px; box-shadow:0 4px 15px rgba(99,102,241,0.3); display:flex; align-items:center; justify-content:center; gap:8px;">
                                <i class="material-icons" style="font-size:20px;">auto_awesome</i>
                                <span>RANCANG STORYBOARD VIDEO DENGAN GEMINI AI</span>
                            </button>

                            <!-- Loading State Box -->
                            <div id="videoStoryboardLoading" style="display:none; margin-top:15px; padding:16px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; text-align:center;">
                                <i class="material-icons" style="font-size:32px; color:#6366f1; animation:spin 1s infinite linear;">autorenew</i>
                                <div style="font-weight:700; color:#1e293b; margin-top:8px;">Gemini AI sedang merancang storyboard video resep...</div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Menganalisis langkah direction, mengunci anchor Pixel-Lock dapur, dan merumuskan prompt T2I &amp; I2V per adegan.</div>
                            </div>
                        </div>

                        <!-- 1.4 Recipe Preview Column -->
                        <div class="col-md-5 col-xs-12">
                            <div class="card corner-radius" style="border:1px solid #e2e8f0; box-shadow:none; background:#f8fafc; padding:16px;">
                                <label style="font-weight:700; color:#1e293b; font-size:13px; display:block; margin-bottom:8px;">Pratinjau Resep Terpilih:</label>
                                <div id="videoRecipePreviewCard">
                                    <img id="videoRecipePreviewImg" src="" style="width:100%; height:180px; object-fit:cover; border-radius:8px; margin-bottom:10px; display:none;" alt="Preview">
                                    <h4 id="videoRecipePreviewTitle" style="font-size:15px; font-weight:700; color:#1e293b; margin:0 0 4px 0;">Pilih Resep</h4>
                                    <div id="videoRecipePreviewMeta" style="font-size:11px; color:#64748b; margin-bottom:10px;">-</div>
                                    <div style="font-weight:600; font-size:12px; color:#475569; margin-bottom:4px;">Langkah Memasak Asli:</div>
                                    <div id="videoRecipePreviewDirections" style="font-size:11px; color:#64748b; max-height:220px; overflow-y:auto; line-height:1.6; background:white; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                                        Pilih resep di samping untuk melihat ringkasan langkah.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= STEP 2: STORYBOARD & START FRAME GALLERY ================= -->
                <div id="video-step-section-2" style="display:none;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:10px; padding:12px 16px; margin-bottom:20px;">
                        <div>
                            <div style="font-size:11px; color:#4338ca; font-weight:600;">PROYEK AKTIF:</div>
                            <h4 id="videoActiveProjectTitle" style="margin:2px 0 0 0; font-size:15px; font-weight:700; color:#1e1b4b;">-</h4>
                            <div id="videoActiveProjectMeta" style="font-size:11px; color:#6366f1; margin-top:2px;">-</div>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-default" onclick="jumpToVideoStep(1)" style="font-size:12px; border-radius:6px; font-weight:600;">
                                <i class="material-icons" style="font-size:14px; vertical-align:middle;">arrow_back</i> Ubah Resep
                            </button>
                            <button type="button" id="btnRunAllFrames" class="btn btn-primary waves-effect" onclick="runStaggeredFrameGeneration()" style="background:var(--ai-gradient); border:none; border-radius:6px; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:6px;">
                                <i class="material-icons" style="font-size:16px;">palette</i>
                                <span>GENERATE SEMUA START FRAME</span>
                            </button>
                        </div>
                    </div>

                    <!-- Frame Generation Progress Box -->
                    <div id="videoFramesProgressBox" style="display:none; background:white; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <strong style="color:#1e293b; font-size:13px;" id="videoFramesProgressTitle">Mengirim Task Start Frame ke G-Labs...</strong>
                            <span class="label label-primary" id="videoFramesProgressBadge">0%</span>
                        </div>
                        <div class="progress" style="height:10px; margin-bottom:8px; border-radius:5px; background:#e2e8f0;">
                            <div class="progress-bar progress-bar-striped active" id="videoFramesProgressBar" role="progressbar" style="width:0%; background:var(--ai-gradient);"></div>
                        </div>
                        <div style="font-size:11px; color:#64748b; display:flex; justify-content:space-between;" id="videoFramesProgressDesc">
                            <span>Jeda acak 10-15 detik antar pengiriman task untuk menjaga stabilitas GPU.</span>
                            <span id="videoFramesTimer">0s</span>
                        </div>
                    </div>

                    <!-- Storyboard Cards Grid -->
                    <div id="videoStoryboardGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:16px; margin-bottom:25px;">
                        <!-- Dynamically populated by renderVideoStoryboard() -->
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding-top:15px;">
                        <button type="button" class="btn btn-default" onclick="jumpToVideoStep(1)">
                            <i class="material-icons" style="font-size:14px; vertical-align:middle;">arrow_back</i> Kembali ke Langkah 1
                        </button>
                        <button type="button" id="btnNextToStep3" class="btn btn-success waves-effect" onclick="jumpToVideoStep(3)" style="font-weight:700;">
                            <span>Lanjut ke Langkah 3: Setting I2V &amp; Klip Video</span>
                            <i class="material-icons" style="font-size:16px; vertical-align:middle;">arrow_forward</i>
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 3: I2V CONFIGURATION & VIDEO CLIPS ================= -->
                <div id="video-step-section-3" style="display:none;">
                    <!-- I2V Config Box -->
                    <div style="background:#fdf2f8; border:1px solid #fbcfe8; border-radius:10px; padding:16px; margin-bottom:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                            <div>
                                <h4 style="margin:0; font-size:15px; font-weight:700; color:#831843;">Konfigurasi Engine Video (Image-to-Video)</h4>
                                <div style="font-size:11px; color:#9d174d;">Gambar start frame akan digerakkan menggunakan G-Labs AI Video Engine.</div>
                            </div>
                            <span class="label" style="background:#db2777; font-size:11px; padding:4px 8px; border-radius:4px;">MODEL: OMNI FLASH</span>
                        </div>

                        <div class="row clearfix" style="margin-bottom:0;">
                            <div class="col-sm-4 col-xs-12">
                                <label style="font-size:12px; font-weight:700; color:#1e293b;">Model Video AI:</label>
                                <input type="text" class="form-control" value="omni_flash (Veo Omni Flash)" readonly style="background:white; font-size:12px; font-weight:600;">
                            </div>
                            <div class="col-sm-4 col-xs-12">
                                <label style="font-size:12px; font-weight:700; color:#1e293b;">Durasi per Scene:</label>
                                <select id="videoClipDurationSelect" class="form-control show-tick">
                                    <option value="4" selected>4 Detik (Rekomendasi Reels / Cepat)</option>
                                    <option value="6">6 Detik</option>
                                    <option value="8">8 Detik</option>
                                    <option value="10">10 Detik (Maksimal)</option>
                                </select>
                            </div>
                            <div class="col-sm-4 col-xs-12">
                                <label style="font-size:12px; font-weight:700; color:#1e293b;">Gaya Visual / Motion Focus:</label>
                                <select id="videoMotionStyleSelect" class="form-control show-tick">
                                    <option value="Macrophotography" selected>Macrophotography (Desis Minyak &amp; Uap Panas)</option>
                                    <option value="Cinematic Gourmet">Cinematic Gourmet (Gerakan Kamera Halus)</option>
                                    <option value="Chef Action">Chef Action (Aktivitas Tangan Memasak)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Action Bar -->
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:15px;">
                        <div style="font-size:13px; font-weight:700; color:#1e293b;">Klip Video per Scene (I2V Motion):</div>
                        <button type="button" id="btnRunAllClips" class="btn btn-primary waves-effect" onclick="runStaggeredClipGeneration()" style="background:linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%); border:none; border-radius:6px; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:6px;">
                            <i class="material-icons" style="font-size:16px;">videocam</i>
                            <span>GENERATE SEMUA VIDEO KLIP (I2V)</span>
                        </button>
                    </div>

                    <!-- Clip Generation Progress Box -->
                    <div id="videoClipsProgressBox" style="display:none; background:white; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <strong style="color:#1e293b; font-size:13px;" id="videoClipsProgressTitle">Mengirim Task Video ke G-Labs...</strong>
                            <span class="label label-primary" id="videoClipsProgressBadge">0%</span>
                        </div>
                        <div class="progress" style="height:10px; margin-bottom:8px; border-radius:5px; background:#e2e8f0;">
                            <div class="progress-bar progress-bar-striped active" id="videoClipsProgressBar" role="progressbar" style="width:0%; background:linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%);"></div>
                        </div>
                        <div style="font-size:11px; color:#64748b; display:flex; justify-content:space-between;" id="videoClipsProgressDesc">
                            <span>Jeda acak 10-15 detik antar pengiriman klip.</span>
                            <span id="videoClipsTimer">0s</span>
                        </div>
                    </div>

                    <!-- Video Clips Grid -->
                    <div id="videoClipsGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:16px; margin-bottom:25px;">
                        <!-- Dynamically populated by renderVideoClips() -->
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding-top:15px;">
                        <button type="button" class="btn btn-default" onclick="jumpToVideoStep(2)">
                            <i class="material-icons" style="font-size:14px; vertical-align:middle;">arrow_back</i> Kembali ke Langkah 2
                        </button>
                        <button type="button" id="btnNextToStep4" class="btn btn-success waves-effect" onclick="jumpToVideoStep(4)" style="font-weight:700;">
                            <span>Lanjut ke Langkah 4: Penggabungan FFmpeg &amp; Simpan</span>
                            <i class="material-icons" style="font-size:16px; vertical-align:middle;">arrow_forward</i>
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 4: FFMPEG STITCHING & FINAL VIDEO ================= -->
                <div id="video-step-section-4" style="display:none;">
                    <div class="row clearfix">
                        <div class="col-md-6 col-xs-12">
                            <div class="card corner-radius" style="border:1px solid #e2e8f0; padding:20px; box-shadow:none;">
                                <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px;">
                                    <div style="width:36px; height:36px; border-radius:8px; background:#10b981; display:flex; align-items:center; justify-content:center; color:white;">
                                        <i class="material-icons">movie</i>
                                    </div>
                                    <div>
                                        <h4 style="margin:0; font-size:15px; font-weight:700; color:#1e293b;">FFmpeg Concatenation Engine</h4>
                                        <div style="font-size:11px; color:#64748b;">Penggabungan lokal instan via Mac Mini (/opt/homebrew/bin/ffmpeg).</div>
                                    </div>
                                </div>

                                <p style="font-size:12px; color:#475569; line-height:1.6; margin-bottom:16px;">
                                    Semua adegan klip MP4 yang telah dihasilkan akan disatukan secara berurutan menjadi <strong>1 video utuh berformat MP4 H.264 Faststart</strong> yang ringan dan siap diputar di browser maupun aplikasi mobile.
                                </p>

                                <button type="button" id="btnStitchVideo" class="btn btn-primary btn-block waves-effect" onclick="handleStitchProject()" style="background:#10b981; border:none; border-radius:8px; padding:12px; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; gap:8px;">
                                    <i class="material-icons" style="font-size:18px;">call_merge</i>
                                    <span>SATUKAN SELURUH SCENE MENJADI VIDEO UTUH</span>
                                </button>

                                <div id="videoStitchLoading" style="display:none; margin-top:15px; padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; text-align:center;">
                                    <i class="material-icons" style="font-size:24px; color:#10b981; animation:spin 1s infinite linear; vertical-align:middle;">autorenew</i>
                                    <span style="font-weight:700; color:#166534; margin-left:6px;">FFmpeg sedang menyatukan seluruh adegan video...</span>
                                </div>

                                <div id="videoApplySuccessBox" style="display:none; margin-top:15px; padding:14px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px;">
                                    <div style="font-weight:700; color:#065f46; font-size:13px;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">check_circle</i> Video Berhasil Disimpan ke Resep!
                                    </div>
                                    <div style="font-size:11px; color:#047857; margin-top:4px;">
                                        Kolom <code>video_url</code> pada <code>tbl_recipes</code> telah diperbarui. Video aktif dan siap ditonton di aplikasi.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-xs-12">
                            <div class="card corner-radius" style="border:1px solid #e2e8f0; padding:20px; box-shadow:none; text-align:center; background:#f8fafc;">
                                <div id="videoFinalPlayerWrap" style="display:none;">
                                    <video id="videoFinalPlayer" controls style="width:100%; max-height:440px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.15); background:#000; margin-bottom:12px;"></video>
                                    <div style="display:flex; justify-content:center; gap:6px; margin-bottom:15px; flex-wrap:wrap;">
                                        <span class="label label-success" id="videoFinalMetaBadge">MP4 H.264 Faststart</span>
                                        <span class="label label-info" id="videoFinalSizeBadge">-</span>
                                    </div>
                                    <button type="button" id="btnApplyVideoToRecipe" class="btn btn-success btn-block waves-effect" onclick="handleApplyVideoToRecipe()" style="font-weight:700; padding:12px; border-radius:8px;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">save</i> SIMPAN VIDEO KE RESEP INI
                                    </button>
                                </div>
                                <div id="videoFinalPlaceholder" style="padding:40px 10px; color:#94a3b8;">
                                    <i class="material-icons" style="font-size:64px; color:#cbd5e1; margin-bottom:10px;">videocam</i>
                                    <div style="font-weight:600; font-size:14px; color:#475569;">Video Utuh Belum Digabungkan</div>
                                    <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Klik tombol "Satukan Seluruh Scene" di samping untuk memproses dengan FFmpeg.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding-top:15px; margin-top:15px;">
                        <button type="button" class="btn btn-default" onclick="jumpToVideoStep(3)">
                            <i class="material-icons" style="font-size:14px; vertical-align:middle;">arrow_back</i> Kembali ke Langkah 3
                        </button>
                    </div>
                </div>

            </div>
        </div>
        <!-- End of #video-studio-workspace -->

    </div>
</section>

<!-- Lightbox Zoom Modal -->
<div class="modal fade" id="lightboxModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius:16px; overflow:hidden; border:none; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div style="padding:0; position:relative; background:#0f172a;">
                <button type="button" class="close" data-dismiss="modal" style="position:absolute; top:15px; right:15px; color:white; opacity:0.8; z-index:10; font-size:28px;">&times;</button>
                <img id="lightbox-img" src="" alt="Zoom Image" style="width:100%; max-height:80vh; object-fit:contain; display:block; margin:0 auto;" />
            </div>
            <div style="padding:20px 25px; background:white; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                <div>
                    <h4 id="lightbox-title" style="margin:0 0 4px 0; font-size:16px; font-weight:700; color:#1e293b;">Preview Foto</h4>
                    <div style="font-size:12px; color:#64748b;">Resolusi Asli • Format: JPG/PNG</div>
                </div>
                <div style="display:flex; gap:10px;">
                    <a id="lightbox-download-link" href="#" download="recipe_image.jpg" class="btn btn-default" style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; border-radius:6px;">
                        <i class="material-icons" style="font-size:16px;">file_download</i> Unduh Foto
                    </a>
                    <button type="button" class="btn btn-primary" data-dismiss="modal" style="border-radius:6px; font-size:12px; font-weight:600;">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="ai-toast">
    <i class="material-icons" style="color:#10b981; font-size:20px;">check_circle</i>
    <span id="ai-toast-message">Tindakan berhasil diselesaikan.</span>
</div>

<!-- MODAL: ADD API KEY TO POOL -->
<div class="modal fade" id="modalAddKey" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:12px; overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg, #6366f1 0%, #a855f7 100%); color:white; padding:16px 20px;">
                <button type="button" class="close" data-dismiss="modal" style="color:white; opacity:0.8;">&times;</button>
                <h4 class="modal-title" style="font-size:16px; font-weight:700;">
                    <i class="material-icons" style="vertical-align:middle;">add_circle</i> Tambah API Key ke Pool
                </h4>
            </div>
            <div class="modal-body" style="padding:22px;">
                <div class="form-group">
                    <label style="font-size:12px; font-weight:700; color:#334155;">Nama Label Kunci:</label>
                    <input type="text" id="new-key-name" class="form-control" placeholder="Contoh: Akun AI Studio Cadangan #2" style="border-radius:6px; font-size:12px;" />
                </div>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:700; color:#334155;">Google Gemini API Key:</label>
                    <input type="password" id="new-key-val" class="form-control" placeholder="AIzaSy..." style="border-radius:6px; font-size:12px;" />
                    <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">Dapatkan kunci gratis di <a href="https://aistudio.google.com/" target="_blank" style="color:#6366f1; font-weight:600;">Google AI Studio ↗</a>. Batas Free Tier adalah <strong>20 request/hari (RPD)</strong>. Jika kuota 20 RPD habis, kunci otomatis cooldown 24 jam.</small>
                </div>
            </div>
            <div class="modal-footer" style="padding:14px 20px; background:#f8fafc;">
                <button type="button" class="btn btn-default" data-dismiss="modal" style="border-radius:6px; font-size:12px;">Batal</button>
                <button type="button" class="btn btn-primary" onclick="saveNewKeyToPool()" id="btn-save-new-key" style="background:#6366f1; border:none; border-radius:6px; font-size:12px; font-weight:700;">
                    Simpan ke Pool
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const recipesData = <?php echo json_encode($allRecipes, JSON_UNESCAPED_UNICODE); ?>;
    const ALL_WATERFALL_MODELS = [
        'gemini-2.5-flash',
        'gemini-3.5-flash',
        'gemini-3.6-flash',
        'gemini-3-flash-preview',
        'gemini-flash-lite-latest',
        'gemini-3.5-flash-lite'
    ];
    
    let currentSelectedRecipe = null;
    let currentCount = 1;
    let selectedPreset = <?php echo json_encode($aiConfig['default_style'] ?? 'Modern Fine Dining'); ?>;
    let generatedPrompts = [];
    let currentGlabsTaskId = null;
    let currentGenerationId = null;
    let pollInterval = null;
    let lastGeneratedFile = null;

    let isStudioOpen = false;

    $(document).ready(function() {
        if (recipesData && recipesData.length > 0) {
            currentSelectedRecipe = recipesData[0];
            updateRecipePreview(currentSelectedRecipe);
        }
        loadHistoryTable();
        loadKeyPoolTable();
        updateWaterfallVisualizer();

        // Optional auto-open if requested via URL param (e.g. ?open=image, ?open=video or ?tab=settings)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('open') === 'image' || urlParams.get('type') === 'image') {
            openImageStudio('tab-generator');
        } else if (urlParams.get('open') === 'video' || urlParams.get('type') === 'video') {
            openVideoStudio();
        } else if (urlParams.get('tab')) {
            openImageStudio('tab-' + urlParams.get('tab'));
        }

        if (recipesData && recipesData.length > 0) {
            onVideoRecipeSelectChange();
        }
    });

    function openImageStudio(targetTabId) {
        if (typeof closeVideoStudio === 'function' && isVideoStudioOpen) {
            closeVideoStudio();
        }
        const workspace = $('#image-studio-workspace');
        const card = $('#card-image-gen');
        const text = $('#text-toggle-studio');
        const icon = $('#icon-toggle-studio');
        const badge = $('#badge-studio-status');

        if (!isStudioOpen) {
            isStudioOpen = true;
            card.addClass('selected-active');
            badge.fadeIn();
            text.text('Tutup Image Studio');
            icon.text('expand_less');
            workspace.slideDown(350, function() {
                if (targetTabId) {
                    switchActiveTab(targetTabId);
                }
                const el = document.getElementById('image-studio-workspace');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        } else {
            if (targetTabId) {
                switchActiveTab(targetTabId);
                const el = document.getElementById('image-studio-workspace');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function closeImageStudio() {
        const workspace = $('#image-studio-workspace');
        const card = $('#card-image-gen');
        const text = $('#text-toggle-studio');
        const icon = $('#icon-toggle-studio');
        const badge = $('#badge-studio-status');

        isStudioOpen = false;
        card.removeClass('selected-active');
        badge.hide();
        text.text('Buka Image Generator Studio');
        icon.text('arrow_forward');
        workspace.slideUp(300);

        $('html, body').animate({
            scrollTop: $('.generator-hub-grid').offset().top - 20
        }, 300);
    }

    function toggleImageStudio() {
        if (isStudioOpen) {
            closeImageStudio();
        } else {
            openImageStudio('tab-generator');
        }
    }

    function switchActiveTab(tabId) {
        $('.nav-tabs.ai-tabs li').removeClass('active');
        $('#tab-nav-' + tabId.replace('tab-', '')).addClass('active');
        $('.tab-pane').removeClass('active').hide();
        $('#' + tabId).addClass('active').fadeIn(200);

        if (tabId === 'tab-history') {
            loadHistoryTable();
        }
    }

    function openTab(tabId) {
        openImageStudio(tabId);
    }

    function scrollToStudio(type) {
        openImageStudio('tab-generator');
    }

    function jumpToStep(stepNum) {
        $('#stepper-1, #stepper-2, #stepper-3').removeClass('active completed');
        $('#step-section-1, #step-section-2, #step-section-3').hide();

        if (stepNum === 1) {
            $('#stepper-1').addClass('active');
            $('#step-section-1').fadeIn();
        } else if (stepNum === 2) {
            $('#stepper-1').addClass('completed');
            $('#stepper-2').addClass('active');
            $('#step-section-2').fadeIn();
        } else if (stepNum === 3) {
            $('#stepper-1, #stepper-2').addClass('completed');
            $('#stepper-3').addClass('active');
            $('#step-section-3').fadeIn();
        }
    }

    function onRecipeSelected() {
        const id = parseInt($('#select-recipe').val(), 10);
        const item = recipesData.find(r => parseInt(r.recipe_id, 10) === id);
        if (item) {
            currentSelectedRecipe = item;
            updateRecipePreview(item);
        }
    }

    function updateRecipePreview(recipe) {
        const imgPath = recipe.recipe_image ? ('upload/' + recipe.recipe_image) : 'assets/images/ic_launcher.png';
        $('#recipe-preview-img').attr('src', imgPath);
        $('#recipe-preview-title').text(recipe.recipe_title);
        $('#recipe-preview-cat').text(recipe.category_name || 'Resep Airfry');
        $('#recipe-preview-desc').text(recipe.recipe_description || 'Tidak ada deskripsi singkat.');
        $('#recipe-preview-time').text(recipe.recipe_time || 'Waktu tidak diset');

        $('#comparison-current-img').attr('src', imgPath);
    }

    function selectPreset(el, name) {
        $('.preset-card').removeClass('selected');
        $(el).addClass('selected');
        selectedPreset = name;
        showToast('Preset dipilih: ' + name);
    }

    function selectCount(cnt, btn) {
        currentCount = parseInt(cnt, 10);
        $('#current-count-val').val(currentCount);
        $('#btn-group-count .btn-ai-count').removeClass('active');
        $(btn).addClass('active');
        $('#badge-count-info').text(currentCount + ' Variasi Prompt');
        showToast(currentCount + ' variasi prompt dipilih.');
    }

    // Step 1 -> Step 2: Craft Prompts via Gemini Flash
    function generateRecipePrompts() {
        if (!currentSelectedRecipe) {
            showToast('Pilih resep terlebih dahulu.');
            return;
        }

        const countToGenerate = parseInt($('#current-count-val').val() || currentCount || 1, 10);
        const btn = $('#btn-start-prompt');
        const origText = btn.html();
        btn.html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Menganalisis resep dengan Gemini Flash...').prop('disabled', true);

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'craft_prompts',
                csrf_token: csrfToken,
                recipe_id: currentSelectedRecipe.recipe_id,
                count: countToGenerate,
                style_preset: selectedPreset
            },
            success: function(res) {
                btn.html(origText).prop('disabled', false);
                if (res.success && res.prompts && res.prompts.length > 0) {
                    generatedPrompts = res.prompts;
                    renderPromptCards(res.prompts);
                    jumpToStep(2);
                    showToast('✓ ' + res.prompts.length + ' prompt fotorealistis berhasil dirancang oleh Gemini!');
                } else {
                    alert('Gagal merancang prompt: ' + (res.error || 'Terjadi kesalahan'));
                }
            },
            error: function(xhr, status, err) {
                btn.html(origText).prop('disabled', false);
                let msg = err || '';
                if (xhr.status === 0) {
                    msg = 'Server membutuhkan waktu lebih lama untuk memproses AI atau koneksi terputus. Silakan coba kembali atau gunakan 1 variasi prompt.';
                } else if (xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                } else if (xhr.responseText) {
                    try {
                        const parsed = JSON.parse(xhr.responseText);
                        if (parsed.error) msg = parsed.error;
                    } catch(e) {
                        msg = (xhr.status ? 'HTTP ' + xhr.status + ': ' : '') + (err || 'Terjadi gangguan pada server.');
                    }
                }
                alert('Gagal merancang prompt: ' + msg);
            }
        });
    }

    function renderPromptCards(prompts) {
        const container = $('#prompt-cards-container');
        container.empty();

        prompts.forEach((p, idx) => {
            const cardId = 'prompt-text-' + idx;
            const tagsHtml = (p.tags || ['Ratio: 1:1', 'Preset: ' + selectedPreset]).map(t => `<span class="prompt-tag">${escapeHtml(t)}</span>`).join(' ');
            
            const html = `
                <div class="prompt-card">
                    <div class="prompt-card-header">
                        <div class="prompt-card-title">
                            <i class="material-icons" style="color:#6366f1;">photo_camera</i>
                            ${escapeHtml(p.title || ('Variasi ' + (idx + 1)))}
                            <span class="badge" style="background:#ede9fe; color:#6d28d9; font-size:11px;">Pilihan Utama</span>
                        </div>
                        <span style="font-size:11px; color:#94a3b8;"><i class="material-icons" style="font-size:12px;">edit</i> Klik untuk menyesuaikan teks</span>
                    </div>
                    <textarea class="prompt-textarea" rows="3" id="${cardId}">${escapeHtml(p.prompt)}</textarea>
                    <div class="prompt-tags">${tagsHtml}</div>
                </div>
            `;
            container.append(html);
        });
    }

    // Step 2 -> Step 3: Render Image via G-Labs Webhook
    function renderImageWithGlabs() {
        const promptText = $('#prompt-text-0').val() || '';
        if (!promptText.trim()) {
            alert('Prompt tidak boleh kosong.');
            return;
        }

        const aspect = $('#select-aspect').val() || '4:3';
        const btn = $('#btn-start-render');
        const origText = btn.html();
        btn.html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Mengirim task ke G-Labs...').prop('disabled', true);

        jumpToStep(3);
        $('#glabs-progress-wrap').slideDown();
        $('#comparison-box').hide();
        $('#result-action-panel').hide();
        $('#glabs-progress-bar').css('width', '15%');
        $('#glabs-progress-text').text('Mengirim task ke antrean G-Labs...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'submit_glabs_task',
                csrf_token: csrfToken,
                recipe_id: currentSelectedRecipe.recipe_id,
                prompt: promptText,
                style_preset: selectedPreset,
                aspect_ratio: aspect
            },
            success: function(res) {
                btn.html(origText).prop('disabled', false);
                if (res.success && res.task_id) {
                    currentGlabsTaskId = res.task_id;
                    currentGenerationId = res.generation_id;
                    $('#glabs-current-task-id').text(res.task_id);
                    startPollingGlabsStatus(res.task_id, res.generation_id);
                } else {
                    $('#glabs-progress-wrap').hide();
                    alert('Gagal mengirim task ke G-Labs: ' + (res.error || 'Server tidak merespons'));
                    jumpToStep(2);
                }
            },
            error: function(xhr, status, err) {
                btn.html(origText).prop('disabled', false);
                $('#glabs-progress-wrap').hide();
                let msg = err || '';
                if (xhr.status === 0) {
                    msg = 'Koneksi ke server terputus atau timeout.';
                } else if (xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                }
                alert('Koneksi ke server gagal: ' + (msg || err || 'Terjadi gangguan koneksi.'));
                jumpToStep(2);
            }
        });
    }

    // Polling G-Labs status every 3 seconds
    function startPollingGlabsStatus(taskId, genId) {
        if (pollInterval) clearInterval(pollInterval);

        let pollCount = 0;
        $('#glabs-progress-bar').css('width', '35%');
        $('#glabs-progress-text').text('Task berhasil diantrekan (pending)...');

        pollInterval = setInterval(function() {
            pollCount++;
            $.ajax({
                url: 'ai-generator.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'poll_status',
                    csrf_token: csrfToken,
                    task_id: taskId,
                    generation_id: genId
                },
                success: function(res) {
                    if (res.status === 'running') {
                        $('#glabs-progress-bar').css('width', '65%');
                        $('#glabs-progress-text').text('G-Labs sedang merender piksel fotografi makanan (running)...');
                    } else if (res.status === 'completed') {
                        clearInterval(pollInterval);
                        $('#glabs-progress-bar').css('width', '100%');
                        $('#glabs-progress-text').text('✓ Generasi selesai! Mengunduh gambar...');

                        setTimeout(function() {
                            $('#glabs-progress-wrap').slideUp();
                            displayCompletedImage(res.image_url, res.image_filename);
                        }, 800);
                    } else if (res.status === 'failed') {
                        clearInterval(pollInterval);
                        $('#glabs-progress-wrap').hide();
                        alert('G-Labs gagal memproses task: ' + (res.error || 'Unknown error'));
                    } else if (res.success === false) {
                        clearInterval(pollInterval);
                        $('#glabs-progress-wrap').hide();
                        alert('G-Labs error: ' + (res.error || 'Gagal mengambil status task.'));
                    }
                },
                error: function(xhr, status, err) {
                    console.error('Poll AJAX error:', xhr.status, xhr.responseText);
                    if (pollCount > 10) {
                        clearInterval(pollInterval);
                        $('#glabs-progress-wrap').hide();
                        alert('Koneksi webadmin error (' + xhr.status + '): ' + (xhr.responseText ? xhr.responseText.substring(0, 250) : err));
                    }
                }
            });

            // Safeguard timeout (120 seconds)
            if (pollCount > 40) {
                clearInterval(pollInterval);
                $('#glabs-progress-text').text('Waktu tunggu habis. Periksa tab riwayat untuk melihat hasil jika sudah selesai.');
            }
        }, 3000);
    }

    function displayCompletedImage(imgUrl, filename) {
        lastGeneratedFile = filename;
        $('#comparison-ai-img').attr('src', imgUrl);
        $('#comparison-box').slideDown();
        $('#result-action-panel').slideDown();
        showToast('✓ Gambar makanan berhasil di-render oleh G-Labs!');
    }

    // Apply Image to Recipe
    function applyImageNow() {
        if (!currentGenerationId || !currentSelectedRecipe) {
            alert('Tidak ada gambar aktif untuk diterapkan.');
            return;
        }

        const btn = $('#btn-apply-main');
        btn.html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Menyimpan...').prop('disabled', true);

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'apply_image',
                csrf_token: csrfToken,
                generation_id: currentGenerationId,
                recipe_id: currentSelectedRecipe.recipe_id
            },
            success: function(res) {
                btn.html('<i class="material-icons">done_all</i> Terpasang').removeClass('btn-success').addClass('btn-default');
                if (res.success) {
                    $('#comparison-ai-status').text('✓ Foto Aktif di Resep & Aplikasi Android!');
                    $('#recipe-preview-img').attr('src', 'upload/' + res.new_image);
                    showToast('✓ Berhasil! Foto resep telah diperbarui. Foto lama tersimpan di arsip.');
                } else {
                    alert('Gagal menerapkan: ' + res.error);
                }
            },
            error: function(xhr, status, err) {
                btn.html('<i class="material-icons">check_circle</i> Terapkan ke Resep').prop('disabled', false);
                alert('Koneksi gagal: ' + err);
            }
        });
    }

    // Apply Image to Recipe AND Push to Live Web Hosting
    function applyAndPushNow() {
        if (!currentGenerationId || !currentSelectedRecipe) {
            alert('Tidak ada gambar aktif untuk diterapkan.');
            return;
        }

        const btn = $('#btn-apply-and-push');
        btn.html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Mengunggah ke Live Hosting...').prop('disabled', true);

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'apply_and_push_image',
                csrf_token: csrfToken,
                generation_id: currentGenerationId,
                recipe_id: currentSelectedRecipe.recipe_id
            },
            success: function(res) {
                if (res.success) {
                    btn.html('<i class="material-icons">cloud_done</i> Live di Web Hosting!').prop('disabled', true);
                    $('#btn-apply-main').html('<i class="material-icons">done_all</i> Terpasang').removeClass('btn-success').addClass('btn-default');
                    $('#comparison-ai-status').text('✓ Live di airfry.web.id (#' + res.live_recipe_id + ')!');
                    if (res.new_image) {
                        $('#recipe-preview-img').attr('src', 'upload/' + res.new_image);
                    }
                    showToast('✓ Berhasil! Foto diterapkan ke resep & langsung live di hosting!');
                    alert(res.message || 'Resep dan foto sukses terbit di hosting live!');
                } else {
                    btn.html('<i class="material-icons">cloud_upload</i> 🚀 Terapkan & Push ke Live Hosting').prop('disabled', false);
                    alert('Gagal push ke hosting: ' + res.error);
                }
            },
            error: function(xhr, status, err) {
                btn.html('<i class="material-icons">cloud_upload</i> 🚀 Terapkan & Push ke Live Hosting').prop('disabled', false);
                alert('Koneksi server gagal: ' + err);
            }
        });
    }

    // Custom T2I Studio
    function renderCustomImage() {
        const prompt = $('#custom-prompt-input').val() || '';
        if (!prompt.trim()) {
            alert('Tulis prompt terlebih dahulu.');
            return;
        }
        const aspect = $('#custom-aspect').val() || '4:3';
        const model = $('#custom-model').val();

        showToast('Mengirim prompt ke G-Labs...');
        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'submit_glabs_task',
                csrf_token: csrfToken,
                recipe_id: 0,
                prompt: prompt,
                style_preset: 'Custom Studio',
                aspect_ratio: aspect,
                model: model
            },
            success: function(res) {
                if (res.success && res.task_id) {
                    alert('Task berhasil dikirim ke G-Labs (Task ID: ' + res.task_id + '). Periksa tab Riwayat untuk melihat status progres.');
                    openTab('tab-history');
                } else {
                    alert('Gagal: ' + res.error);
                }
            },
            error: function(xhr, status, err) {
                let msg = err || '';
                if (xhr.status === 0) {
                    msg = 'Koneksi ke server terputus atau timeout.';
                } else if (xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                }
                alert('Koneksi gagal: ' + (msg || err || 'Terjadi gangguan koneksi.'));
            }
        });
    }

    function setCustomPrompt(idx) {
        const prompts = {
            1: "Golden crispy homemade air-fried french fries in a vintage wire basket with sea salt flakes, parchment paper, side of artisan smoky ketchup, natural kitchen daylight, 8k macro culinary shot.",
            2: "Pan-seared seasoned salmon steak on a cedar wood board with lemon slices, asparagus, fresh herbs, glistening olive oil, professional culinary magazine photo.",
            3: "Air fryer warm chocolate lava cake dusting of powdered sugar, molten dark chocolate oozing out onto a white porcelain plate, vanilla bean ice cream scoop, close up."
        };
        $('#custom-prompt-input').val(prompts[idx]);
    }

    // Load History Table
    function loadHistoryTable() {
        const tbody = $('#history-table-body');
        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'get_history',
                csrf_token: csrfToken
            },
            success: function(res) {
                if (res.success && res.history && res.history.length > 0) {
                    tbody.empty();
                    res.history.forEach(item => {
                        const thumb = item.image_filename ? ('upload/' + item.image_filename) : 'assets/images/ic_launcher.png';
                        let statusBadge = '<span class="badge-status archived">Selesai</span>';
                        if (item.status === 'applied') {
                            statusBadge = '<span class="badge-status active"><i class="material-icons" style="font-size:12px;">check</i> Aktif di Resep</span>';
                        } else if (item.status === 'reverted') {
                            statusBadge = '<span class="badge-status archived">Foto Asli Dipulihkan</span>';
                        } else if (item.status === 'failed') {
                            statusBadge = '<span class="label label-danger">Gagal</span>';
                        }

                        let revertBtn = '';
                        if (item.status === 'applied' && item.previous_image) {
                            revertBtn = `<button class="btn btn-xs btn-default" onclick="revertImageNow(${item.id})" title="Kembalikan foto asli resep"><i class="material-icons" style="font-size:13px; color:#d97706;">undo</i> Revert Asli</button>`;
                        }

                        const tr = `
                            <tr>
                                <td><img src="${thumb}" width="50" height="50" style="object-fit:cover; border-radius:6px; cursor:pointer;" onclick="openLightbox('${thumb}', '${escapeHtml(item.recipe_title || 'Foto AI')}')" /></td>
                                <td>
                                    <div style="font-weight:700; color:#1e293b;">${escapeHtml(item.recipe_title || 'Custom T2I (Tanpa Resep)')}</div>
                                    <small style="color:#64748b;">ID #${item.recipe_id || '-'} • Preset: ${escapeHtml(item.style_preset || '-')}</small>
                                </td>
                                <td><span class="label label-purple" style="background:#8b5cf6; color:white; border-radius:4px;">${escapeHtml(item.model_used || 'nano_banana_2')}</span><br><small style="font-family:monospace; color:#94a3b8;">${item.task_id || '-'}</small></td>
                                <td><small>${escapeHtml(item.created_at)}</small></td>
                                <td>${statusBadge}</td>
                                <td style="text-align:right;">
                                    ${revertBtn}
                                    <button class="btn btn-xs btn-default" onclick="openLightbox('${thumb}', 'Preview')"><i class="material-icons" style="font-size:13px;">zoom_in</i></button>
                                </td>
                            </tr>
                        `;
                        tbody.append(tr);
                    });
                } else {
                    tbody.html('<tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:30px;">Belum ada riwayat generasi gambar.</td></tr>');
                }
            }
        });
    }

    function revertImageNow(genId) {
        if (!confirm('Kembalikan foto resep ke foto asli sebelum generasi AI?')) return;

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'revert_image',
                csrf_token: csrfToken,
                generation_id: genId
            },
            success: function(res) {
                if (res.success) {
                    showToast('✓ ' + res.message);
                    loadHistoryTable();
                } else {
                    alert('Gagal revert: ' + res.error);
                }
            }
        });
    }

    // Save Settings
    function saveAiConfig(e) {
        e.preventDefault();
        const btn = $('#btn-save-cfg');
        btn.html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Menyimpan...').prop('disabled', true);

        const formData = $('#form-ai-config').serialize() + '&ajax_action=save_config&csrf_token=' + encodeURIComponent(csrfToken);

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: formData,
            success: function(res) {
                btn.html('<i class="material-icons">save</i> Simpan Pengaturan').prop('disabled', false);
                if (res.success) {
                    showToast('✓ ' + res.message);
                } else {
                    alert('Gagal menyimpan: ' + res.error);
                }
            },
            error: function(xhr, status, err) {
                btn.html('<i class="material-icons">save</i> Simpan Pengaturan').prop('disabled', false);
                alert('Gagal simpan: ' + err);
            }
        });
    }

    function testGlabsConnection() {
        const host = $('#cfg-glabs-host').val();
        const port = $('#cfg-glabs-port').val();
        showToast('Menguji koneksi G-Labs Webhook...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'test_glabs',
                csrf_token: csrfToken,
                glabs_host: host,
                glabs_port: port
            },
            success: function(res) {
                if (res.success) {
                    alert('✓ ' + res.message);
                } else {
                    alert('✗ ' + res.error);
                }
            }
        });
    }

    function testLiveSyncConnection() {
        showToast('Menguji koneksi ke Live Hosting airfry.web.id...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'test_sync_live',
                csrf_token: csrfToken
            },
            success: function(res) {
                if (res.success) {
                    alert('✓ Koneksi Live Hosting Sukses!\n' + (res.message || 'Endpoint aktif.') + '\nWaktu server: ' + (res.server_time || '-'));
                } else {
                    alert('✗ Gagal terhubung ke Live Hosting:\n' + (res.error || 'Terjadi kesalahan. Pastikan api_sync_recipe.php sudah diupload ke server hosting.'));
                }
            },
            error: function(xhr, status, err) {
                alert('Koneksi server gagal: ' + err);
            }
        });
    }

    function testGeminiConnection() {
        const key = $('#cfg-gemini-key').val();
        showToast('Menguji koneksi Google AI Studio...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'test_gemini',
                csrf_token: csrfToken,
                gemini_api_key: key
            },
            success: function(res) {
                if (res.success) {
                    alert('✓ ' + res.message);
                } else {
                    alert('✗ ' + res.error);
                }
            }
        });
    }

    function toggleMask(inputId, iconId) {
        const inp = $('#' + inputId);
        const icon = $('#' + iconId);
        if (inp.attr('type') === 'password') {
            inp.attr('type', 'text');
            icon.text('visibility_off');
        } else {
            inp.attr('type', 'password');
            icon.text('visibility');
        }
    }


    function openVideoDevModal() {
        openVideoStudio();
    }

    function openAddKeyModal() {
        $('#new-key-name').val('');
        $('#new-key-val').val('');
        $('#modalAddKey').modal('show');
    }

    function loadKeyPoolTable() {
        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'get_api_keys',
                csrf_token: csrfToken
            },
            success: function(res) {
                if (res.success && res.keys) {
                    renderKeyPoolTable(res.keys);
                }
            }
        });
    }

    function renderKeyPoolTable(keys) {
        const tbody = $('#key-pool-tbody');
        tbody.empty();

        if (!keys || keys.length === 0) {
            tbody.append('<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">Belum ada API Key di pool. Silakan klik "Tambah Kunci Baru".</td></tr>');
            return;
        }

        keys.forEach(k => {
            const masked = k.api_key_masked || (k.api_key ? (k.api_key.substring(0, 8) + '...' + k.api_key.substring(k.api_key.length - 4)) : '-');
            const dailyLimit = k.daily_limit ? Number(k.daily_limit).toLocaleString() : '20';
            const usedToday = k.used_today || 0;
            const isLimitFull = (usedToday >= (k.daily_limit || 20));
            
            let statusBadge = '<span class="label label-success" style="border-radius:4px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">check</i> Aktif & Sehat</span>';
            if (parseInt(k.is_active, 10) === 0) {
                statusBadge = '<span class="label label-default" style="border-radius:4px;">Nonaktif</span>';
            } else if (k.last_status === 'INVALID') {
                statusBadge = '<span class="label label-danger" style="border-radius:4px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">error</i> Kunci Tidak Valid</span>';
            } else if (k.is_in_cooldown || k.last_status === 'COOLDOWN_429') {
                const rem = k.cooldown_remaining_formatted ? ` (Sisa ${escapeHtml(k.cooldown_remaining_formatted)})` : '';
                const until = k.cooldown_until_formatted ? `<br><small style="color:#b45309; font-size:10px; font-weight:600; display:inline-block; margin-top:3px;">Aktif: ${escapeHtml(k.cooldown_until_formatted)}${rem}</small>` : '';
                statusBadge = `<span class="label label-warning" style="border-radius:4px; background:#f59e0b;" title="Limit 20 RPD Habis - Cooldown 24 Jam"><i class="material-icons" style="font-size:11px; vertical-align:middle;">hourglass_empty</i> Cooldown 24 Jam</span>${until}`;
            }

            const usageCol = isLimitFull 
                ? `<span style="color:#ef4444; font-weight:700;">${usedToday} / ${dailyLimit} req/hari <span class="label label-danger" style="font-size:9px; border-radius:3px; padding:1px 4px;">Penuh</span></span>`
                : `<span>${usedToday} / ${dailyLimit} req/hari</span>`;

            const lastUsed = k.last_used_at ? k.last_used_at : '-';

            const tr = `
                <tr>
                    <td><strong>${escapeHtml(k.label_name || 'Google AI Studio')}</strong></td>
                    <td><span class="key-tag">${escapeHtml(masked)}</span></td>
                    <td>${usageCol}</td>
                    <td>${statusBadge}</td>
                    <td>${escapeHtml(lastUsed)}</td>
                    <td style="text-align:right;">
                        <button type="button" class="btn btn-xs btn-danger" onclick="deleteKeyFromPool(${k.id}, '${escapeHtml(k.label_name)}')">
                            <i class="material-icons" style="font-size:12px; vertical-align:middle;">delete</i> Hapus
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(tr);
        });
    }

    function saveNewKeyToPool() {
        const name = $('#new-key-name').val().trim() || 'Akun Cadangan';
        const key = $('#new-key-val').val().trim();
        if (!key) {
            alert('Masukkan Google Gemini API Key terlebih dahulu.');
            return;
        }

        const btn = $('#btn-save-new-key');
        btn.prop('disabled', true).text('Menyimpan...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'add_api_key',
                csrf_token: csrfToken,
                key_name: name,
                api_key: key
            },
            success: function(res) {
                btn.prop('disabled', false).text('Simpan ke Pool');
                if (res.success) {
                    $('#modalAddKey').modal('hide');
                    showToast('✓ ' + (res.message || 'Kunci berhasil ditambahkan ke pool!'));
                    loadKeyPoolTable();
                } else {
                    alert('Gagal menambahkan kunci: ' + (res.error || 'Terjadi kesalahan'));
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false).text('Simpan ke Pool');
                alert('Kesalahan jaringan: ' + err);
            }
        });
    }

    function deleteKeyFromPool(id, name) {
        if (!confirm('Yakin ingin menghapus kunci "' + name + '" dari pool?')) {
            return;
        }

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'delete_api_key',
                csrf_token: csrfToken,
                key_id: id
            },
            success: function(res) {
                if (res.success) {
                    showToast('✓ ' + (res.message || 'Kunci berhasil dihapus.'));
                    loadKeyPoolTable();
                } else {
                    alert('Gagal menghapus: ' + (res.error || 'Terjadi kesalahan'));
                }
            }
        });
    }

    function testAllKeysInPool() {
        const btn = $('#btn-test-all-keys');
        const origText = btn.html();
        btn.html('<i class="material-icons" style="font-size:15px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Menguji...').prop('disabled', true);

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'test_all_keys',
                csrf_token: csrfToken
            },
            success: function(res) {
                btn.html(origText).prop('disabled', false);
                if (res.success) {
                    let msg = res.message || ('Pengujian selesai: ' + (res.healthy_count || 0) + ' dari ' + (res.total || 0) + ' kunci sehat.');
                    if (res.results && res.results.length > 0) {
                        msg += '\n\nRincian Kunci:';
                        res.results.forEach(r => {
                            msg += '\n• ' + (r.key_name || 'Kunci') + ': ' + (r.success ? '✓ Aktif & Sehat' : ('✗ ' + (r.message || 'Error')));
                        });
                    }
                    alert(msg);
                    loadKeyPoolTable();
                } else {
                    alert('Pengujian gagal: ' + (res.error || 'Terjadi kesalahan'));
                }
            },
            error: function(xhr, status, err) {
                btn.html(origText).prop('disabled', false);
                alert('Kesalahan jaringan saat pengujian: ' + err);
            }
        });
    }

    function resetAllKeysCooldown() {
        if (!confirm('Apakah Anda yakin ingin mereset status cooldown untuk semua kunci di pool? Gunakan tombol ini jika kuota harian telah di-reset Google atau akun telah di-upgrade.')) {
            return;
        }
        const btn = $('#btn-reset-cooldown');
        const origHtml = btn.html();
        btn.prop('disabled', true).html('<i class="material-icons" style="font-size:15px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mereset...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'reset_api_keys_cooldown',
                csrf_token: csrfToken
            },
            success: function(res) {
                btn.prop('disabled', false).html(origHtml);
                alert(res.message || 'Status cooldown berhasil di-reset.');
                loadKeyPoolTable();
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false).html(origHtml);
                alert('Gagal mereset status cooldown: ' + err);
            }
        });
    }

    function updateWaterfallVisualizer() {
        const primary = $('#select-primary-model').val() || 'gemini-3.8-flash';
        const container = $('#waterfall-container');
        if (!container.length) return;
        container.empty();

        const cascade = [primary];
        ALL_WATERFALL_MODELS.forEach(m => {
            if (!cascade.includes(m)) cascade.push(m);
        });

        cascade.forEach((modelName, idx) => {
            const isPrimary = (idx === 0);
            const isLast = (idx === cascade.length - 1);
            const badgeClass = isPrimary ? 'primary-badge' : 'fallback-badge';
            const nodeClass = isPrimary ? 'primary' : (isLast ? 'last' : 'fallback');
            const roleLabel = isPrimary 
                ? '<span class="label label-primary" style="font-size:10px;">PRIMARY</span>' 
                : `<span class="label label-warning" style="font-size:10px;">FALLBACK #${idx}</span>`;
            const retryRule = !isLast 
                ? '<small style="color:#64748b; font-size:11px;">Jika 503: Retry 2x (delay 2s, 4s) ➔ Cascade</small>' 
                : '<small style="color:#64748b; font-size:11px;">Benteng Terakhir (Fallback Final)</small>';

            const nodeHtml = `
                <div class="waterfall-node ${nodeClass}">
                    <div style="display:flex; align-items:center;">
                        <span class="waterfall-step-badge ${badgeClass}">${idx + 1}</span>
                        <div>
                            <div style="font-size:13px; font-weight:700; color:#1e293b;">
                                ${modelName} ${roleLabel}
                            </div>
                            ${retryRule}
                        </div>
                    </div>
                    <span class="label label-default" style="font-size:10px; border-radius:4px;">1.500 req/hari</span>
                </div>
            `;
            container.append(nodeHtml);

            if (!isLast) {
                const connectorHtml = `
                    <div class="waterfall-connector">
                        <i class="material-icons">arrow_downward</i>
                        <span>Jika 503 setelah 2x retry / 404 instant dead</span>
                    </div>
                `;
                container.append(connectorHtml);
            }
        });
    }

    function openLightbox(src, title) {
        $('#lightbox-img').attr('src', src);
        $('#lightbox-title').text(title || 'Preview Foto');
        $('#lightbox-download-link').attr('href', src);
        $('#lightboxModal').modal('show');
    }

    function showToast(msg) {
        $('#ai-toast-message').text(msg);
        $('#ai-toast').addClass('show');
        setTimeout(function() {
            $('#ai-toast').removeClass('show');
        }, 3500);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Batch Image Optimizer
    function scanOptimizableImages() {
        $('#batch-opt-status').html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:16px; vertical-align:middle;">autorenew</i> Memindai folder upload/...');
        $.get('tools/batch_optimize.php?action=scan', function(res) {
            if (res.success) {
                if (res.count === 0) {
                    $('#batch-opt-status').html('<span style="color:#10b981;"><i class="material-icons" style="font-size:16px; vertical-align:middle;">check_circle</i> Seluruh foto sudah teroptimasi dengan baik (' + res.count + ' berkas perlu diproses).</span>');
                    $('#btn-run-batch-opt').prop('disabled', true);
                } else {
                    $('#batch-opt-status').html('<span style="color:#d97706;"><i class="material-icons" style="font-size:16px; vertical-align:middle;">info</i> Ditemukan <strong>' + res.count + ' berkas</strong> berukuran total <strong>' + res.total_size_human + '</strong> yang dapat dioptimasi.</span>');
                    $('#btn-run-batch-opt').prop('disabled', false);
                }
            } else {
                $('#batch-opt-status').html('<span style="color:#ef4444;">Gagal memindai: ' + res.error + '</span>');
            }
        }).fail(function(xhr, status, err) {
            $('#batch-opt-status').html('<span style="color:#ef4444;">Koneksi gagal: ' + err + '</span>');
        });
    }

    function runBatchOptimizer() {
        if (!confirm('Jalankan optimasi otomatis pada semua berkas gambar yang ditemukan? File akan diubah menjadi Progressive JPEG (max 1080px @ 82%).')) {
            return;
        }
        const btn = $('#btn-run-batch-opt');
        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:16px; vertical-align:middle;">autorenew</i> Sedang mengompresi gambar...');
        $('#batch-opt-status').html('Memproses kompresi gambar...');

        $.post('tools/batch_optimize.php', { action: 'run' }, function(res) {
            btn.prop('disabled', false).html('<i class="material-icons" style="font-size:15px; vertical-align:middle;">bolt</i> Jalankan Batch Optimize');
            if (res.success) {
                alert('Sukses! Sebanyak ' + res.succeeded + ' berkas berhasil dioptimasi. Total kapasitas disk dihemat: ' + res.total_saved_mb + ' MB!');
                $('#batch-opt-status').html('<span style="color:#10b981;"><i class="material-icons" style="font-size:16px; vertical-align:middle;">check_circle</i> Selesai! ' + res.succeeded + ' berkas dioptimasi, menghemat <strong>' + res.total_saved_mb + ' MB</strong> ruang disk.</span>');
                scanOptimizableImages();
            } else {
                alert('Gagal: ' + res.error);
            }
        }).fail(function(xhr, status, err) {
            btn.prop('disabled', false).html('<i class="material-icons" style="font-size:15px; vertical-align:middle;">bolt</i> Jalankan Batch Optimize');
            alert('Koneksi gagal: ' + err);
        });
    }

    /* =========================================================================
     * AI RECIPE VIDEO GENERATOR STUDIO JAVASCRIPT ENGINE
     * ========================================================================= */
    let isVideoStudioOpen = false;
    let currentVideoProject = null;
    let currentVideoStep = 1;
    let isFramesBatchRunning = false;
    let isClipsBatchRunning = false;
    const videoScenePrompts = {};

    function openVideoStudio() {
        const workspace = $('#video-studio-workspace');
        const card = $('#card-video-gen');
        const text = $('#text-toggle-video-studio');
        const icon = $('#icon-toggle-video-studio');
        const badge = $('#badge-video-studio-status');

        if (typeof closeImageStudio === 'function' && isStudioOpen) {
            closeImageStudio();
        }

        isVideoStudioOpen = true;
        card.addClass('selected-active');
        badge.show();
        text.text('Tutup Video Studio');
        icon.text('expand_less');

        workspace.show();

        if (!currentVideoProject) {
            jumpToVideoStep(1);
        }

        setTimeout(function() {
            const el = document.getElementById('video-studio-workspace');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 80);

        if (typeof $.fn.selectpicker === 'function') {
            $('#videoRecipeSelect').selectpicker('refresh');
        }
        onVideoRecipeSelectChange();
    }

    function closeVideoStudio() {
        const workspace = $('#video-studio-workspace');
        const card = $('#card-video-gen');
        const text = $('#text-toggle-video-studio');
        const icon = $('#icon-toggle-video-studio');
        const badge = $('#badge-video-studio-status');

        isVideoStudioOpen = false;
        card.removeClass('selected-active');
        badge.hide();
        text.text('Buka Video Generator Studio');
        icon.text('arrow_forward');
        workspace.hide();
    }

    function toggleVideoStudio() {
        if (isVideoStudioOpen) {
            closeVideoStudio();
        } else {
            openVideoStudio();
        }
    }

    function onVideoRecipeSelectChange() {
        const selVal = $('#videoRecipeSelect').val();
        const recipeId = parseInt(selVal, 10);
        if (!recipeId || !recipesData || recipesData.length === 0) return;

        const r = recipesData.find(item => parseInt(item.recipe_id, 10) === recipeId);
        if (!r) return;

        $('#videoRecipePreviewTitle').text(r.recipe_title || 'Resep Tanpa Judul');
        const metaParts = [];
        if (r.category_name) metaParts.push(r.category_name);
        if (r.recipe_time) metaParts.push(r.recipe_time);
        if (r.recipe_type) metaParts.push(r.recipe_type);
        $('#videoRecipePreviewMeta').text(metaParts.join(' • ') || 'Kategori Umum');

        if (r.recipe_image) {
            let imgUrl = r.recipe_image;
            if (!imgUrl.startsWith('http') && !imgUrl.startsWith('upload/')) {
                imgUrl = 'upload/' + imgUrl;
            }
            $('#videoRecipePreviewImg').attr('src', imgUrl).show();
        } else {
            $('#videoRecipePreviewImg').hide();
        }

        let directions = [];
        try {
            if (r.recipe_description && (r.recipe_description.startsWith('[') || r.recipe_description.startsWith('{'))) {
                directions = JSON.parse(r.recipe_description);
            }
        } catch(e) {}

        if (!Array.isArray(directions) || directions.length === 0) {
            const raw = (r.recipe_description || '').replace(/<[^>]*>/g, '');
            directions = raw.split(/\r\n|\r|\n/).map(s => s.replace(/^\d+[\.\)]\s*/, '').trim()).filter(s => s.length > 3);
        }

        let dirHtml = '';
        if (directions.length > 0) {
            dirHtml = '<ol style="padding-left:18px; margin:0;">';
            directions.forEach(step => {
                dirHtml += '<li style="margin-bottom:6px;">' + escapeHtml(step) + '</li>';
            });
            dirHtml += '</ol>';
        } else {
            dirHtml = '<p style="color:#94a3b8; font-style:italic; margin:0;">Tidak ada deskripsi langkah terdeteksi. AI akan merancang langkah memasak standar otomatis.</p>';
        }
        $('#videoRecipePreviewDirections').html(dirHtml);
    }

    function selectKitchenPreset(presetKey, el) {
        $('#kitchenPresetOptions .preset-kitchen-card').removeClass('selected');
        $(el).addClass('selected');
        $('#selectedKitchenPreset').val(presetKey);
    }

    function selectStoryboardMode(mode, el) {
        $('#storyboardModeOptions .video-select-card').removeClass('selected');
        $(el).addClass('selected');
        $('#selectedStoryboardMode').val(mode);
    }

    function selectAspectRatio(ratio, el) {
        $('#aspectRatioOptions .video-select-card').removeClass('selected');
        $(el).addClass('selected');
        $('#selectedAspectRatio').val(ratio);
    }

    function jumpToVideoStep(stepNum) {
        if (stepNum > 1 && !currentVideoProject) {
            alert('Silakan rancang storyboard video terlebih dahulu pada Langkah 1.');
            return;
        }
        currentVideoStep = stepNum;

        for (let i = 1; i <= 4; i++) {
            const stepEl = $('#video-stepper-' + i);
            const sectionEl = $('#video-step-section-' + i);
            stepEl.removeClass('active completed');
            if (i < stepNum) {
                stepEl.addClass('completed');
                sectionEl.hide();
            } else if (i === stepNum) {
                stepEl.addClass('active');
                sectionEl.show();
            } else {
                sectionEl.hide();
            }
        }

        const el = document.getElementById('video-studio-workspace');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function handleGenerateStoryboard() {
        const recipeId = parseInt($('#videoRecipeSelect').val(), 10);
        if (!recipeId) {
            alert('Silakan pilih resep terlebih dahulu.');
            return;
        }
        const kitchenPreset = $('#selectedKitchenPreset').val() || 'Modern Scandinavian Kitchen';
        const aspectRatio = $('#selectedAspectRatio').val() || '9:16';
        const mode = $('#selectedStoryboardMode').val() || 'reels';

        $('#btnGenerateStoryboard').prop('disabled', true);
        $('#videoStoryboardLoading').slideDown();

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_create_project_storyboard',
                csrf_token: csrfToken,
                recipe_id: recipeId,
                kitchen_preset: kitchenPreset,
                aspect_ratio: aspectRatio,
                storyboard_mode: mode
            },
            success: function(res) {
                $('#videoStoryboardLoading').slideUp();
                $('#btnGenerateStoryboard').prop('disabled', false);

                if (res.success && res.project_id) {
                    loadVideoProject(res.project_id, function() {
                        jumpToVideoStep(2);
                    });
                } else {
                    alert('Gagal merancang storyboard: ' + (res.error || 'Terjadi kesalahan sistem.'));
                }
            },
            error: function(xhr, status, err) {
                $('#videoStoryboardLoading').slideUp();
                $('#btnGenerateStoryboard').prop('disabled', false);
                alert('Koneksi AJAX gagal (' + xhr.status + '): ' + (xhr.responseText ? xhr.responseText.substring(0, 300) : err));
            }
        });
    }

    function loadVideoProject(projectId, callback) {
        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_get_project',
                csrf_token: csrfToken,
                project_id: projectId
            },
            success: function(res) {
                if (res.success && res.project) {
                    currentVideoProject = res.project;
                    renderVideoStoryboard(currentVideoProject);
                    renderVideoClips(currentVideoProject);
                    checkFinalVideoState(currentVideoProject);
                    if (typeof callback === 'function') callback(currentVideoProject);
                }
            }
        });
    }

    function renderVideoStoryboard(project) {
        if (!project) return;
        $('#videoActiveProjectTitle').text(project.recipe_title || project.project_title || 'Proyek Video');
        const totalScenes = project.scenes ? project.scenes.length : 0;
        $('#videoActiveProjectMeta').html(
            'Preset Dapur: <strong>' + escapeHtml(project.kitchen_preset) + '</strong> • ' +
            'Rasio: <strong>' + escapeHtml(project.aspect_ratio) + '</strong> • ' +
            '<strong>' + totalScenes + ' Adegan Visual</strong>'
        );

        let html = '';
        let completedCount = 0;

        (project.scenes || []).forEach(scene => {
            videoScenePrompts[scene.id] = {
                t2i: scene.prompt_t2i || '',
                i2v: scene.prompt_i2v || ''
            };

            const isCompleted = scene.frame_status === 'completed' && scene.frame_image_path;
            const isRunning = scene.frame_status === 'running';
            const isFailed = scene.frame_status === 'failed';
            if (isCompleted) completedCount++;

            let badgeHtml = '<span class="label label-default" id="frame-badge-' + scene.id + '">Belum Dirender</span>';
            if (isCompleted) {
                badgeHtml = '<span class="label label-success" id="frame-badge-' + scene.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle;">check</i> Selesai</span>';
            } else if (isRunning) {
                badgeHtml = '<span class="label label-warning" id="frame-badge-' + scene.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Memproses...</span>';
            } else if (isFailed) {
                badgeHtml = '<span class="label label-danger" id="frame-badge-' + scene.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle;">error</i> Gagal</span>';
            }

            let mediaHtml = '';
            if (isCompleted) {
                mediaHtml = '<div style="position:relative; width:100%; height:200px; background:#000; border-radius:8px; overflow:hidden; margin-bottom:12px;">' +
                    '<img id="frame-img-' + scene.id + '" src="' + escapeHtml(scene.frame_image_path) + '" style="width:100%; height:100%; object-fit:cover;" alt="Start Frame">' +
                    '<span style="position:absolute; bottom:6px; right:6px; background:rgba(0,0,0,0.65); color:white; font-size:10px; padding:2px 6px; border-radius:4px;">Start Frame</span>' +
                    '</div>';
            } else {
                mediaHtml = '<div id="frame-placeholder-' + scene.id + '" style="width:100%; height:180px; background:#f1f5f9; border:2px dashed #cbd5e1; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; margin-bottom:12px; padding:10px; text-align:center;">' +
                    '<i class="material-icons" style="font-size:36px; margin-bottom:6px; color:#cbd5e1;">image</i>' +
                    '<div style="font-size:11px; font-weight:600; color:#64748b;">Start Frame Siap Dirender</div>' +
                    '<div style="font-size:10px; color:#94a3b8; margin-top:2px;">Pixel-Lock konsisten dengan preset ' + escapeHtml(project.kitchen_preset) + '</div>' +
                    '</div>';
            }

            html += '<div class="card corner-radius video-scene-card" id="scene-card-' + scene.id + '" style="background:white; border:1px solid #e2e8f0; padding:16px; margin:0; display:flex; flex-direction:column; justify-content:space-between;">' +
                '<div>' +
                    '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">' +
                        '<strong style="font-size:13px; color:#1e1b4b;">Adegan #' + scene.scene_order + '</strong>' +
                        badgeHtml +
                    '</div>' +
                    mediaHtml +
                    (scene.direction_source_text ? 
                        '<div style="background:#f8fafc; border-left:3px solid #6366f1; border-radius:4px; padding:6px 10px; margin-bottom:10px;">' +
                            '<div style="font-size:9px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Langkah Resep Asli (Direction):</div>' +
                            '<div style="font-size:11px; color:#334155; line-height:1.4; margin-top:2px;">' + escapeHtml(scene.direction_source_text) + '</div>' +
                        '</div>' : '') +
                    '<div style="margin-bottom:8px;">' +
                        '<div style="font-size:10px; font-weight:700; color:#6366f1; text-transform:uppercase; letter-spacing:0.5px;">Aksi Visual:</div>' +
                        '<div style="font-size:12px; font-weight:600; color:#1e293b; line-height:1.4; margin-top:2px;">' + escapeHtml(scene.visual_action_desc) + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom:12px;">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                            '<span style="font-size:10px; font-weight:700; color:#64748b;">PROMPT T2I (PIXEL-LOCK):</span>' +
                            '<a href="javascript:void(0);" onclick="copyScenePrompt(' + scene.id + ', \'t2i\')" style="font-size:10px; color:#6366f1; font-weight:600;">Salin</a>' +
                        '</div>' +
                        '<div style="font-size:11px; color:#475569; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px; margin-top:4px; max-height:80px; overflow-y:auto; line-height:1.4;">' +
                            escapeHtml(scene.prompt_t2i) +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div>' +
                    '<button type="button" class="btn btn-default btn-block btn-render-frame" id="btn-render-frame-' + scene.id + '" onclick="retryFrameGeneration(' + scene.id + ')" style="border-radius:6px; font-size:11px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:5px;">' +
                        '<i class="material-icons" style="font-size:14px; vertical-align:middle;">' + (isCompleted ? 'refresh' : 'palette') + '</i> ' +
                        (isCompleted ? 'Render Ulang Frame' : 'Generate Frame Ini') +
                    '</button>' +
                '</div>' +
            '</div>';
        });

        $('#videoStoryboardGrid').html(html);

        if (completedCount === totalScenes && totalScenes > 0) {
            $('#btnNextToStep3').prop('disabled', false).removeClass('btn-default').addClass('btn-success');
        }
    }

    function copyScenePrompt(sceneId, type) {
        const item = videoScenePrompts[sceneId];
        if (!item || !item[type]) {
            alert('Prompt tidak ditemukan.');
            return;
        }
        copyToClipboardText(item[type]);
    }

    function copyToClipboardText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Prompt berhasil disalin ke clipboard!');
            }).catch(function() {
                fallbackCopyText(text);
            });
        } else {
            fallbackCopyText(text);
        }
    }

    function fallbackCopyText(text) {
        const temp = $('<textarea>');
        $('body').append(temp);
        temp.val(text).select();
        document.execCommand('copy');
        temp.remove();
        alert('Prompt berhasil disalin ke clipboard!');
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function countdownDelay(timerElemId, descText, seconds) {
        return new Promise(function(resolve) {
            let remaining = seconds;
            $('#' + timerElemId).text(remaining + 's');
            if (timerElemId === 'videoFramesTimer') {
                $('#videoFramesProgressDesc span:first-child').text(descText);
            } else if (timerElemId === 'videoClipsTimer') {
                $('#videoClipsProgressDesc span:first-child').text(descText);
            }

            const cdInterval = setInterval(function() {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(cdInterval);
                    $('#' + timerElemId).text('0s');
                    resolve();
                } else {
                    $('#' + timerElemId).text(remaining + 's');
                }
            }, 1000);
        });
    }

    function showVideoBatchError(kind, sceneId, error, errors) {
        const message = error || 'Kesalahan tidak diketahui';
        errors.push('Scene #' + sceneId + ': ' + message);
        const prefix = kind === 'frame' ? 'videoFrames' : 'videoClips';
        $('#' + prefix + 'ProgressDesc span:first-child').text('Gagal scene #' + sceneId + ': ' + message);
        $('#' + kind + '-badge-' + sceneId)
            .replaceWith('<span class="label label-danger" id="' + kind + '-badge-' + sceneId + '">Gagal</span>');
        $('#' + kind + '-badge-' + sceneId).attr('title', message);
    }

    async function runStaggeredFrameGeneration() {
        if (!currentVideoProject || !currentVideoProject.scenes || currentVideoProject.scenes.length === 0) {
            alert('Tidak ada adegan untuk digenerate.');
            return;
        }

        if (isFramesBatchRunning) {
            alert('Proses generasi Start Frame sedang berjalan.');
            return;
        }

        const scenesToProcess = currentVideoProject.scenes.filter(s => s.frame_status !== 'completed');
        if (scenesToProcess.length === 0) {
            if (!confirm('Semua start frame sudah selesai. Apakah Anda ingin meng-generate ulang semuanya?')) {
                return;
            }
        }

        const scenes = scenesToProcess.length > 0 ? scenesToProcess : currentVideoProject.scenes;
        isFramesBatchRunning = true;
        $('#btnRunAllFrames').prop('disabled', true);
        $('#videoFramesProgressBox').slideDown();

        const total = scenes.length;
        const submittedTaskScenes = [];
        const errors = [];
        const completedSceneIds = [];

        // PHASE 1: SUBMISSION WITH 10-15s RANDOM DELAY
        for (let i = 0; i < scenes.length; i++) {
            const sc = scenes[i];
            const sceneNum = sc.scene_order || (i + 1);

            $('#videoFramesProgressTitle').text('Mengirim Task Start Frame Scene #' + sceneNum + ' (' + (i + 1) + ' dari ' + total + ')...');
            const submitPercent = Math.round(((i) / (total * 2)) * 100);
            $('#videoFramesProgressBadge').text(submitPercent + '%');
            $('#videoFramesProgressBar').css('width', submitPercent + '%');

            $('#frame-badge-' + sc.id).replaceWith('<span class="label label-warning" id="frame-badge-' + sc.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mengantrekan...</span>');

            try {
                const submitRes = await submitFrameTaskPromise(sc.id);
                if (submitRes.success && submitRes.task_id) {
                    submittedTaskScenes.push(sc.id);
                    $('#frame-badge-' + sc.id).replaceWith('<span class="label label-info" id="frame-badge-' + sc.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Task: ' + submitRes.task_id.substring(0, 8) + '...</span>');
                } else {
                    showVideoBatchError('frame', sc.id, submitRes.error || 'G-Labs tidak mengembalikan task ID.', errors);
                }
            } catch (err) {
                console.error('Submit error scene ' + sc.id, err);
                showVideoBatchError('frame', sc.id, err.statusText || 'Koneksi ke webadmin gagal.', errors);
            }

            if (i < scenes.length - 1) {
                const delaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoFramesTimer', 'Jeda acak ' + delaySec + ' detik antar pengiriman task G-Labs...', delaySec);
            }
        }

        // PHASE 2: POLLING EACH TASK UNTIL COMPLETE WITH 10-15s DELAYS
        $('#videoFramesProgressTitle').text(submittedTaskScenes.length + ' dari ' + total + ' task terkirim. Memeriksa status G-Labs...');
        let pendingSceneIds = [...submittedTaskScenes];
        let pollPass = 0;

        while (pendingSceneIds.length > 0 && pollPass < 30) {
            pollPass++;
            const nextPending = [];

            for (let j = 0; j < pendingSceneIds.length; j++) {
                const scId = pendingSceneIds[j];
                $('#videoFramesProgressTitle').text('Memeriksa status hasil render scene #' + scId + '...');

                try {
                    const pollRes = await pollFrameTaskPromise(scId);
                    if (pollRes.status === 'completed' && pollRes.image_url) {
                        updateSceneFrameCompleted(scId, pollRes.image_url);
                        completedSceneIds.push(scId);
                    } else if (pollRes.status === 'failed' || pollRes.success === false) {
                        showVideoBatchError('frame', scId, pollRes.error || 'Render Start Frame gagal.', errors);
                    } else {
                        nextPending.push(scId);
                    }
                } catch (pollErr) {
                    console.error('Poll error scene ' + scId, pollErr);
                    nextPending.push(scId);
                }

                if (j < pendingSceneIds.length - 1) {
                    await sleep(2000);
                }
            }

            pendingSceneIds = nextPending;
            const totalProgressPercent = Math.min(95, 50 + Math.round(((total - pendingSceneIds.length) / total) * 50));
            $('#videoFramesProgressBadge').text(totalProgressPercent + '%');
            $('#videoFramesProgressBar').css('width', totalProgressPercent + '%');

            if (pendingSceneIds.length > 0) {
                const pollDelaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoFramesTimer', 'Memeriksa ulang ' + pendingSceneIds.length + ' scene tersisa dalam ' + pollDelaySec + ' detik...', pollDelaySec);
            }
        }

        pendingSceneIds.forEach(function(scId) {
            $('#frame-badge-' + scId).replaceWith('<span class="label label-warning" id="frame-badge-' + scId + '">Masih Diproses</span>');
        });

        loadVideoProject(currentVideoProject.id, function(updatedProj) {
            isFramesBatchRunning = false;
            $('#btnRunAllFrames').prop('disabled', false);
            $('#videoFramesProgressBar').css('width', '100%');
            $('#videoFramesProgressBadge').text('100%');
            $('#videoFramesProgressTitle').text('Start Frame: ' + completedSceneIds.length + ' selesai, ' + errors.length + ' gagal, ' + pendingSceneIds.length + ' masih diproses.');
            $('#videoFramesProgressDesc span:first-child').text(errors.length ? errors.join(' | ') : (pendingSceneIds.length ? 'Task yang belum selesai dapat diperiksa kembali nanti.' : 'Semua Start Frame berhasil dibuat.'));
            $('#videoFramesTimer').text(errors.length || pendingSceneIds.length ? 'Periksa status' : 'Selesai');

            if (!errors.length && !pendingSceneIds.length) {
                setTimeout(function() { $('#videoFramesProgressBox').slideUp(); }, 3000);
            }
        });
    }

    function submitFrameTaskPromise(sceneId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'ai-generator.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'video_submit_frame_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: resolve,
                error: reject
            });
        });
    }

    function pollFrameTaskPromise(sceneId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'ai-generator.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'video_poll_frame_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: resolve,
                error: reject
            });
        });
    }

    function updateSceneFrameCompleted(sceneId, imageUrl) {
        $('#frame-badge-' + sceneId).replaceWith('<span class="label label-success" id="frame-badge-' + sceneId + '"><i class="material-icons" style="font-size:10px; vertical-align:middle;">check</i> Selesai</span>');
        const mediaContainer = $('#frame-placeholder-' + sceneId);
        const completedHtml = '<div style="position:relative; width:100%; height:200px; background:#000; border-radius:8px; overflow:hidden; margin-bottom:12px;">' +
            '<img id="frame-img-' + sceneId + '" src="' + escapeHtml(imageUrl) + '" style="width:100%; height:100%; object-fit:cover;" alt="Start Frame">' +
            '<span style="position:absolute; bottom:6px; right:6px; background:rgba(0,0,0,0.65); color:white; font-size:10px; padding:2px 6px; border-radius:4px;">Start Frame</span>' +
            '</div>';
        if (mediaContainer.length > 0) {
            mediaContainer.replaceWith(completedHtml);
        } else {
            $('#frame-img-' + sceneId).attr('src', imageUrl);
        }
        $('#btn-render-frame-' + sceneId).html('<i class="material-icons" style="font-size:14px; vertical-align:middle;">refresh</i> Render Ulang Frame');
    }

    function retryFrameGeneration(sceneId) {
        const btn = $('#btn-render-frame-' + sceneId);
        btn.prop('disabled', true).html('<i class="material-icons" style="font-size:14px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mengirim...');
        $('#frame-badge-' + sceneId).replaceWith('<span class="label label-warning" id="frame-badge-' + sceneId + '">Memproses...</span>');

        submitFrameTaskPromise(sceneId).then(function(res) {
            if (!res.success) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px; vertical-align:middle;">palette</i> Generate Frame Ini');
                $('#frame-badge-' + sceneId).replaceWith('<span class="label label-danger" id="frame-badge-' + sceneId + '">Gagal</span>');
                alert('Gagal submit frame: ' + (res.error || 'Error'));
                return;
            }

            let pollCount = 0;
            const interval = setInterval(function() {
                pollCount++;
                pollFrameTaskPromise(sceneId).then(function(pollRes) {
                    if (pollRes.status === 'completed' && pollRes.image_url) {
                        clearInterval(interval);
                        btn.prop('disabled', false);
                        updateSceneFrameCompleted(sceneId, pollRes.image_url);
                    } else if (pollRes.status === 'failed') {
                        clearInterval(interval);
                        btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px; vertical-align:middle;">palette</i> Coba Lagi');
                        $('#frame-badge-' + sceneId).replaceWith('<span class="label label-danger" id="frame-badge-' + sceneId + '">Gagal</span>');
                        alert('Render frame gagal: ' + (pollRes.error || 'Unknown error'));
                    }
                }).catch(function() {
                    if (pollCount > 15) clearInterval(interval);
                });

                if (pollCount > 40) {
                    clearInterval(interval);
                    btn.prop('disabled', false);
                }
            }, 3000);
        }).catch(function(err) {
            btn.prop('disabled', false);
            alert('Koneksi gagal: ' + err);
        });
    }

    function renderVideoClips(project) {
        if (!project) return;
        let html = '';
        let completedClips = 0;
        const totalScenes = project.scenes ? project.scenes.length : 0;

        (project.scenes || []).forEach(scene => {
            const isCompleted = scene.video_status === 'completed' && scene.video_clip_path;
            const isRunning = scene.video_status === 'running';
            const isFailed = scene.video_status === 'failed';
            if (isCompleted) completedClips++;

            let badgeHtml = '<span class="label label-default" id="clip-badge-' + scene.id + '">Belum Ada Klip</span>';
            if (isCompleted) {
                badgeHtml = '<span class="label label-success" id="clip-badge-' + scene.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle;">check</i> Video Siap</span>';
            } else if (isRunning) {
                badgeHtml = '<span class="label label-warning" id="clip-badge-' + scene.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Merender Video...</span>';
            } else if (isFailed) {
                badgeHtml = '<span class="label label-danger" id="clip-badge-' + scene.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle;">error</i> Gagal</span>';
            }

            let playerHtml = '';
            if (isCompleted) {
                playerHtml = '<div style="position:relative; width:100%; height:220px; background:#000; border-radius:8px; overflow:hidden; margin-bottom:12px;">' +
                    '<video id="clip-video-' + scene.id + '" src="' + escapeHtml(scene.video_clip_path) + '" controls style="width:100%; height:100%; object-fit:contain;"></video>' +
                    '</div>';
            } else {
                const startFrameImg = scene.frame_image_path ? '<img src="' + escapeHtml(scene.frame_image_path) + '" style="position:absolute; width:100%; height:100%; object-fit:cover; opacity:0.35;">' : '';
                playerHtml = '<div id="clip-placeholder-' + scene.id + '" style="position:relative; width:100%; height:200px; background:#0f172a; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; margin-bottom:12px; overflow:hidden; text-align:center; padding:10px;">' +
                    startFrameImg +
                    '<div style="position:relative; z-index:2;">' +
                    '<i class="material-icons" style="font-size:36px; margin-bottom:6px; color:#cbd5e1;">videocam</i>' +
                    '<div style="font-size:11px; font-weight:600; color:white;">Klip Video I2V Belum Digenerate</div>' +
                    '<div style="font-size:10px; color:#94a3b8; margin-top:2px;">Akan digerakkan dengan model Omni Flash</div>' +
                    '</div>' +
                    '</div>';
            }

            html += '<div class="card corner-radius video-scene-card" id="clip-card-' + scene.id + '" style="background:white; border:1px solid #e2e8f0; padding:16px; margin:0; display:flex; flex-direction:column; justify-content:space-between;">' +
                '<div>' +
                    '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">' +
                        '<strong style="font-size:13px; color:#831843;">Klip Adegan #' + scene.scene_order + '</strong>' +
                        badgeHtml +
                    '</div>' +
                    playerHtml +
                    (scene.direction_source_text ? 
                        '<div style="background:#fdf2f8; border-left:3px solid #db2777; border-radius:4px; padding:6px 10px; margin-bottom:10px;">' +
                            '<div style="font-size:9px; font-weight:700; color:#9d174d; text-transform:uppercase; letter-spacing:0.5px;">Langkah Resep Asli (Direction):</div>' +
                            '<div style="font-size:11px; color:#334155; line-height:1.4; margin-top:2px;">' + escapeHtml(scene.direction_source_text) + '</div>' +
                        '</div>' : '') +
                    '<div style="margin-bottom:8px;">' +
                        '<div style="font-size:10px; font-weight:700; color:#db2777; text-transform:uppercase; letter-spacing:0.5px;">Aksi Visual:</div>' +
                        '<div style="font-size:12px; font-weight:600; color:#1e293b; line-height:1.4; margin-top:2px;">' + escapeHtml(scene.visual_action_desc) + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom:12px;">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                            '<span style="font-size:10px; font-weight:700; color:#64748b;">MOTION PROMPT (I2V):</span>' +
                            '<a href="javascript:void(0);" onclick="copyScenePrompt(' + scene.id + ', \'i2v\')" style="font-size:10px; color:#db2777; font-weight:600;">Salin</a>' +
                        '</div>' +
                        '<div style="font-size:11px; color:#475569; background:#fff1f2; border:1px solid #fecdd3; border-radius:6px; padding:8px; margin-top:4px; max-height:80px; overflow-y:auto; line-height:1.4;">' +
                            escapeHtml(scene.prompt_i2v) +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div>' +
                    '<button type="button" class="btn btn-default btn-block btn-render-clip" id="btn-render-clip-' + scene.id + '" onclick="retryClipGeneration(' + scene.id + ')" style="border-radius:6px; font-size:11px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:5px;">' +
                        '<i class="material-icons" style="font-size:14px; vertical-align:middle;">' + (isCompleted ? 'refresh' : 'videocam') + '</i> ' +
                        (isCompleted ? 'Render Ulang Klip' : 'Generate Klip Ini') +
                    '</button>' +
                '</div>' +
            '</div>';
        });

        $('#videoClipsGrid').html(html);

        if (completedClips === totalScenes && totalScenes > 0) {
            $('#btnNextToStep4').prop('disabled', false).removeClass('btn-default').addClass('btn-success');
        }
    }

    async function runStaggeredClipGeneration() {
        if (!currentVideoProject || !currentVideoProject.scenes || currentVideoProject.scenes.length === 0) {
            alert('Tidak ada adegan video.');
            return;
        }

        if (isClipsBatchRunning) {
            alert('Proses generasi klip video sedang berjalan.');
            return;
        }

        const missingFrames = currentVideoProject.scenes.filter(s => !s.frame_image_path || s.frame_status !== 'completed');
        if (missingFrames.length > 0) {
            alert('Peringatan: Masih ada ' + missingFrames.length + ' adegan yang belum memiliki Start Frame. Harap lengkapi semua Start Frame pada Langkah 2 terlebih dahulu.');
            return;
        }

        const duration = parseInt($('#videoClipDurationSelect').val(), 10) || 4;
        const motionStyle = $('#videoMotionStyleSelect').val() || 'Macrophotography';

        const scenesToProcess = currentVideoProject.scenes.filter(s => s.video_status !== 'completed');
        if (scenesToProcess.length === 0) {
            if (!confirm('Semua klip video sudah selesai digenerate. Apakah Anda ingin meng-generate ulang seluruh klip?')) {
                return;
            }
        }

        const scenes = scenesToProcess.length > 0 ? scenesToProcess : currentVideoProject.scenes;
        isClipsBatchRunning = true;
        $('#btnRunAllClips').prop('disabled', true);
        $('#videoClipsProgressBox').slideDown();

        const total = scenes.length;
        const submittedTaskScenes = [];
        const errors = [];
        const completedSceneIds = [];

        // PHASE 1: SUBMISSION WITH 10-15s RANDOM DELAY
        for (let i = 0; i < scenes.length; i++) {
            const sc = scenes[i];
            const sceneNum = sc.scene_order || (i + 1);

            $('#videoClipsProgressTitle').text('Mengirim Task Video Klip Scene #' + sceneNum + ' (' + (i + 1) + ' dari ' + total + ')...');
            const submitPercent = Math.round(((i) / (total * 2)) * 100);
            $('#videoClipsProgressBadge').text(submitPercent + '%');
            $('#videoClipsProgressBar').css('width', submitPercent + '%');

            $('#clip-badge-' + sc.id).replaceWith('<span class="label label-warning" id="clip-badge-' + sc.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mengirim ke G-Labs...</span>');

            try {
                const submitRes = await submitClipTaskPromise(sc.id, duration, motionStyle);
                if (submitRes.success && submitRes.task_id) {
                    submittedTaskScenes.push(sc.id);
                    $('#clip-badge-' + sc.id).replaceWith('<span class="label label-info" id="clip-badge-' + sc.id + '"><i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Task: ' + submitRes.task_id.substring(0, 8) + '...</span>');
                } else {
                    showVideoBatchError('clip', sc.id, submitRes.error || 'G-Labs tidak mengembalikan task ID.', errors);
                }
            } catch (err) {
                console.error('Submit clip error scene ' + sc.id, err);
                showVideoBatchError('clip', sc.id, err.statusText || 'Koneksi ke webadmin gagal.', errors);
            }

            if (i < scenes.length - 1) {
                const delaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoClipsTimer', 'Jeda acak ' + delaySec + ' detik antar pengiriman klip video G-Labs...', delaySec);
            }
        }

        // PHASE 2: POLLING EACH VIDEO TASK UNTIL COMPLETE
        $('#videoClipsProgressTitle').text(submittedTaskScenes.length + ' dari ' + total + ' task klip terkirim. Memeriksa status G-Labs...');
        let pendingSceneIds = [...submittedTaskScenes];
        let pollPass = 0;

        while (pendingSceneIds.length > 0 && pollPass < 40) {
            pollPass++;
            const nextPending = [];

            for (let j = 0; j < pendingSceneIds.length; j++) {
                const scId = pendingSceneIds[j];
                $('#videoClipsProgressTitle').text('Memeriksa render klip scene #' + scId + '...');

                try {
                    const pollRes = await pollClipTaskPromise(scId);
                    if (pollRes.status === 'completed' && pollRes.video_url) {
                        updateSceneClipCompleted(scId, pollRes.video_url);
                        completedSceneIds.push(scId);
                    } else if (pollRes.status === 'failed' || pollRes.success === false) {
                        showVideoBatchError('clip', scId, pollRes.error || 'Render klip gagal.', errors);
                    } else {
                        nextPending.push(scId);
                    }
                } catch (pollErr) {
                    console.error('Poll clip error scene ' + scId, pollErr);
                    nextPending.push(scId);
                }

                if (j < pendingSceneIds.length - 1) {
                    await sleep(2000);
                }
            }

            pendingSceneIds = nextPending;
            const totalProgressPercent = Math.min(95, 50 + Math.round(((total - pendingSceneIds.length) / total) * 50));
            $('#videoClipsProgressBadge').text(totalProgressPercent + '%');
            $('#videoClipsProgressBar').css('width', totalProgressPercent + '%');

            if (pendingSceneIds.length > 0) {
                const pollDelaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoClipsTimer', 'Memeriksa ulang ' + pendingSceneIds.length + ' video klip tersisa dalam ' + pollDelaySec + ' detik...', pollDelaySec);
            }
        }

        pendingSceneIds.forEach(function(scId) {
            $('#clip-badge-' + scId).replaceWith('<span class="label label-warning" id="clip-badge-' + scId + '">Masih Diproses</span>');
        });

        loadVideoProject(currentVideoProject.id, function(updatedProj) {
            isClipsBatchRunning = false;
            $('#btnRunAllClips').prop('disabled', false);
            $('#videoClipsProgressBar').css('width', '100%');
            $('#videoClipsProgressBadge').text('100%');
            $('#videoClipsProgressTitle').text('Klip I2V: ' + completedSceneIds.length + ' selesai, ' + errors.length + ' gagal, ' + pendingSceneIds.length + ' masih diproses.');
            $('#videoClipsProgressDesc span:first-child').text(errors.length ? errors.join(' | ') : (pendingSceneIds.length ? 'Task yang belum selesai dapat diperiksa kembali nanti.' : 'Semua klip berhasil dibuat.'));
            $('#videoClipsTimer').text(errors.length || pendingSceneIds.length ? 'Periksa status' : 'Selesai');

            if (!errors.length && !pendingSceneIds.length) {
                setTimeout(function() { $('#videoClipsProgressBox').slideUp(); }, 3000);
            }
        });
    }

    function submitClipTaskPromise(sceneId, duration, motionStyle) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'ai-generator.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'video_submit_clip_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId,
                    clip_duration: duration,
                    motion_style: motionStyle
                },
                success: resolve,
                error: reject
            });
        });
    }

    function pollClipTaskPromise(sceneId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'ai-generator.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'video_poll_clip_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: resolve,
                error: reject
            });
        });
    }

    function updateSceneClipCompleted(sceneId, videoUrl) {
        $('#clip-badge-' + sceneId).replaceWith('<span class="label label-success" id="clip-badge-' + sceneId + '"><i class="material-icons" style="font-size:10px; vertical-align:middle;">check</i> Video Siap</span>');
        const placeholder = $('#clip-placeholder-' + sceneId);
        const playerHtml = '<div style="position:relative; width:100%; height:220px; background:#000; border-radius:8px; overflow:hidden; margin-bottom:12px;">' +
            '<video id="clip-video-' + sceneId + '" src="' + escapeHtml(videoUrl) + '" controls style="width:100%; height:100%; object-fit:contain;"></video>' +
            '</div>';
        if (placeholder.length > 0) {
            placeholder.replaceWith(playerHtml);
        } else {
            $('#clip-video-' + sceneId).attr('src', videoUrl);
        }
        $('#btn-render-clip-' + sceneId).html('<i class="material-icons" style="font-size:14px; vertical-align:middle;">refresh</i> Render Ulang Klip');
    }

    function retryClipGeneration(sceneId) {
        const btn = $('#btn-render-clip-' + sceneId);
        btn.prop('disabled', true).html('<i class="material-icons" style="font-size:14px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mengirim...');
        $('#clip-badge-' + sceneId).replaceWith('<span class="label label-warning" id="clip-badge-' + sceneId + '">Memproses...</span>');

        const duration = parseInt($('#videoClipDurationSelect').val(), 10) || 4;
        const motionStyle = $('#videoMotionStyleSelect').val() || 'Macrophotography';

        submitClipTaskPromise(sceneId, duration, motionStyle).then(function(res) {
            if (!res.success) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px; vertical-align:middle;">videocam</i> Generate Klip Ini');
                $('#clip-badge-' + sceneId).replaceWith('<span class="label label-danger" id="clip-badge-' + sceneId + '">Gagal</span>');
                alert('Gagal submit klip video: ' + (res.error || 'Error'));
                return;
            }

            let pollCount = 0;
            const interval = setInterval(function() {
                pollCount++;
                pollClipTaskPromise(sceneId).then(function(pollRes) {
                    if (pollRes.status === 'completed' && pollRes.video_url) {
                        clearInterval(interval);
                        btn.prop('disabled', false);
                        updateSceneClipCompleted(sceneId, pollRes.video_url);
                    } else if (pollRes.status === 'failed') {
                        clearInterval(interval);
                        btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px; vertical-align:middle;">videocam</i> Coba Lagi');
                        $('#clip-badge-' + sceneId).replaceWith('<span class="label label-danger" id="clip-badge-' + sceneId + '">Gagal</span>');
                        alert('Render klip video gagal: ' + (pollRes.error || 'Unknown error'));
                    }
                }).catch(function() {
                    if (pollCount > 25) clearInterval(interval);
                });

                if (pollCount > 50) {
                    clearInterval(interval);
                    btn.prop('disabled', false);
                }
            }, 3000);
        }).catch(function(err) {
            btn.prop('disabled', false);
            alert('Koneksi gagal: ' + err);
        });
    }

    function checkFinalVideoState(project) {
        if (!project) return;
        if (project.stitched_video_path) {
            $('#videoFinalPlaceholder').hide();
            $('#videoFinalPlayerWrap').show();
            $('#videoFinalPlayer').attr('src', project.stitched_video_path);
            if (project.stitched_file_size) {
                const mb = (project.stitched_file_size / (1024 * 1024)).toFixed(2);
                $('#videoFinalSizeBadge').text(mb + ' MB');
            }
        }
    }

    function handleStitchProject() {
        if (!currentVideoProject) {
            alert('Proyek video belum aktif.');
            return;
        }

        const btn = $('#btnStitchVideo');
        btn.prop('disabled', true);
        $('#videoStitchLoading').slideDown();

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_stitch_all_scenes',
                csrf_token: csrfToken,
                project_id: currentVideoProject.id
            },
            success: function(res) {
                btn.prop('disabled', false);
                $('#videoStitchLoading').slideUp();

                if (res.success && res.video_url) {
                    $('#videoFinalPlaceholder').hide();
                    $('#videoFinalPlayerWrap').fadeIn();
                    $('#videoFinalPlayer').attr('src', res.video_url + '?t=' + Date.now());
                    if (res.file_size) {
                        const mb = (res.file_size / (1024 * 1024)).toFixed(2);
                        $('#videoFinalSizeBadge').text(mb + ' MB');
                    }
                    alert('Berhasil! Video resep utuh telah selesai digabungkan dengan FFmpeg.');
                } else {
                    alert('Gagal menyatukan video: ' + (res.error || 'Terjadi kesalahan sistem.'));
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false);
                $('#videoStitchLoading').slideUp();
                alert('Koneksi FFmpeg stitch gagal: ' + (xhr.responseText ? xhr.responseText.substring(0, 300) : err));
            }
        });
    }

    function handleApplyVideoToRecipe() {
        if (!currentVideoProject) {
            alert('Proyek video belum aktif.');
            return;
        }

        const btn = $('#btnApplyVideoToRecipe');
        btn.prop('disabled', true).html('<i class="material-icons" style="font-size:16px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Menyimpan...');

        $.ajax({
            url: 'ai-generator.php',
            method: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_apply_to_recipe',
                csrf_token: csrfToken,
                project_id: currentVideoProject.id
            },
            success: function(res) {
                if (res.success) {
                    $('#videoApplySuccessBox').slideDown();
                    btn.html('<i class="material-icons" style="font-size:16px; vertical-align:middle;">check_circle</i> VIDEO TELAH AKTIF PADA RESEP').addClass('btn-default').removeClass('btn-success');
                    alert('Sukses! Video resep telah berhasil disimpan ke tabel resep.');
                } else {
                    btn.prop('disabled', false).html('<i class="material-icons" style="font-size:16px; vertical-align:middle;">save</i> SIMPAN VIDEO KE RESEP INI');
                    alert('Gagal menyimpan video ke resep: ' + (res.error || 'Error'));
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:16px; vertical-align:middle;">save</i> SIMPAN VIDEO KE RESEP INI');
                alert('Koneksi gagal: ' + err);
            }
        });
    }
</script>

<style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<?php include_once('includes/footer.php'); ?>
