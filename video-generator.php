<?php
/**
 * Airfryer Webadmin - AI Recipe Video Studio (9:16)
 * Sintesis video vertikal sinematik (1080x1920) untuk Reels, TikTok, dan Shorts bertenaga Gemini 3.8 Flash & FFmpeg.
 * 
 * Strict rule: All styling lives in assets/css/airfry-theme.css (No inline hardcoded <style>)
 */

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

require_once('ai/AiBootstrap.php');
require_once('ai/FFmpegVideoService.php');
require_once('ai/GeminiClient.php');
require_once('ai/GlabsClient.php');
require_once('ai/AiVideoService.php');
require_once('ai/SyncClient.php');
require_once('ai/R2Client.php');
require_once('ai/MiniMaxClient.php');

// Ensure all migrations run safely BEFORE services are initialized
if (!Ai\AiBootstrap::isMigrated($connect)) {
    $bootstrapResult = Ai\AiBootstrap::runAllMigrations($connect);
    if (!$bootstrapResult['success']) {
        error_log("[AI Video Generator Bootstrap Notice] " . json_encode($bootstrapResult['messages']));
    }
}

$videoService = new Ai\AiVideoService($connect);
$minimaxConfig = \Ai\MiniMaxClient::getConfig($connect);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// =========================================================================
// AJAX ACTIONS HANDLER
// =========================================================================
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
            case 'video_create_project_storyboard':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $kitchenPreset = trim($_POST['kitchen_preset'] ?? 'Modern Scandinavian Kitchen');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '9:16');
                $mode = trim($_POST['storyboard_mode'] ?? 'reels');
                $targetLanguage = trim($_POST['target_language'] ?? 'auto');
                if ($recipeId <= 0) {
                    throw new Exception('ID Resep wajib dipilih.');
                }
                $options = ['target_language' => $targetLanguage];
                $res = $videoService->createProjectWithStoryboard($recipeId, $kitchenPreset, $aspectRatio, $mode, $options);
                if (!empty($res['success']) && empty($res['project'])) {
                    $res['project'] = $res;
                }
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

            case 'video_reorder_scenes':
                $projectId = (int)($_POST['project_id'] ?? 0);
                $sceneIds = $_POST['scene_ids'] ?? [];
                if ($projectId <= 0 || empty($sceneIds) || !is_array($sceneIds)) {
                    throw new Exception('Data reorder adegan tidak valid.');
                }
                $res = $videoService->reorderScenes($projectId, $sceneIds);
                echo safe_json_encode($res);
                exit;

            case 'video_update_scene_text':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $res = $videoService->updateSceneText($sceneId, $_POST);
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

            case 'video_generate_scene_tts':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $res = $videoService->generateSceneTts($sceneId, $_POST);
                echo safe_json_encode($res);
                exit;

            case 'video_generate_project_tts_batch':
                @set_time_limit(300);
                @ini_set('max_execution_time', '300');
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new Exception('ID Proyek tidak valid.');
                }
                $res = $videoService->generateProjectTtsBatch($projectId, $_POST);
                echo safe_json_encode($res);
                exit;

            case 'video_update_project_tts_settings':
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new Exception('ID Proyek tidak valid.');
                }
                $ok = $videoService->updateProjectTtsSettings($projectId, $_POST);
                echo safe_json_encode(['success' => $ok]);
                exit;

            case 'video_update_scene_tts_text':
                $sceneId = (int)($_POST['scene_id'] ?? 0);
                $text = trim($_POST['tts_text'] ?? '');
                if ($sceneId <= 0) {
                    throw new Exception('ID Scene tidak valid.');
                }
                $ok = $videoService->updateSceneTtsText($sceneId, $text);
                echo safe_json_encode(['success' => $ok]);
                exit;

            case 'video_upload_to_r2':
                @set_time_limit(300);
                @ini_set('max_execution_time', '300');
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new Exception('ID Proyek tidak valid.');
                }
                $proj = $videoService->getProject($projectId);
                if (!$proj) {
                    throw new Exception('Proyek video tidak ditemukan.');
                }
                // Check stitched_video_path first, then video_output_path, then POST fallback
                $videoPath = $proj['stitched_video_path'] ?? $proj['video_output_path'] ?? '';
                if (empty($videoPath) && !empty($_POST['video_url'])) {
                    $videoPath = trim($_POST['video_url']);
                }
                if (empty($videoPath)) {
                    throw new Exception('Video akhir belum selesai digabungkan (stitching). Silakan jalankan proses stitching terlebih dahulu.');
                }
                $absVideoPath = __DIR__ . '/' . ltrim($videoPath, '/');
                if (!file_exists($absVideoPath)) {
                    // Check in upload/video/ folder
                    $fallback = __DIR__ . '/upload/video/' . basename($videoPath);
                    if (file_exists($fallback)) {
                        $absVideoPath = $fallback;
                    } else {
                        throw new Exception('File video lokal tidak ditemukan di: ' . $videoPath);
                    }
                }

                $r2Client = \Ai\R2Client::fromDatabase($connect);
                if (!$r2Client) {
                    throw new Exception('Kredensial Cloudflare R2 belum lengkap atau belum diaktifkan. Silakan periksa di menu Konfigurasi AI Engine (Bagian 5).');
                }

                $recipeId = (int)($proj['recipe_id'] ?? 0);
                $filename = basename($absVideoPath);
                $remoteKey = 'videos/recipes/' . ($recipeId > 0 ? "recipe_{$recipeId}/" : '') . $filename;

                $uploadRes = $r2Client->uploadVideo($absVideoPath, $remoteKey);
                if (!$uploadRes['success']) {
                    throw new Exception('Gagal mengunggah video ke Cloudflare R2: ' . ($uploadRes['error'] ?? 'Terjadi kegagalan transmisi'));
                }

                $publicUrl = $uploadRes['public_url'];

                // Automatically update tbl_recipes.video_url and set content_type to 'Url'
                if ($recipeId > 0) {
                    $stmt = $connect->prepare("UPDATE tbl_recipes SET video_url = ?, content_type = 'Url' WHERE recipe_id = ?");
                    if ($stmt) {
                        $stmt->bind_param('si', $publicUrl, $recipeId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                echo safe_json_encode([
                    'success' => true,
                    'public_url' => $publicUrl,
                    'remote_key' => $remoteKey,
                    'message' => 'Video resep berhasil diunggah ke Cloudflare R2 CDN dan ditautkan ke database resep!'
                ]);
                exit;

            default:
                throw new Exception('Aksi tidak dikenal: ' . htmlspecialchars($action));
        }
    } catch (\Throwable $e) {
        error_log("[Video Generator Error] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
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
if ($recipesResult) {
    while ($row = $recipesResult->fetch_assoc()) {
        $allRecipes[] = $row;
    }
}

// Fetch recent projects for quick jump & continuation
$recentProjectsQuery = "SELECT p.id, p.recipe_id, p.project_title, p.status, p.total_scenes, p.created_at, r.recipe_title,
    (SELECT COUNT(*) FROM tbl_ai_video_scenes s WHERE s.project_id = p.id AND s.video_status = 'completed') as completed_clips
    FROM tbl_ai_video_projects p 
    LEFT JOIN tbl_recipes r ON p.recipe_id = r.recipe_id 
    ORDER BY p.id DESC LIMIT 15";
$recentProjectsRes = $connect->query($recentProjectsQuery);
$recentProjects = [];
if ($recentProjectsRes) {
    while ($row = $recentProjectsRes->fetch_assoc()) {
        $recentProjects[] = $row;
    }
}

$initialProjectId = (int)($_GET['project_id'] ?? 0);
$initialStep = (int)($_GET['step'] ?? 1);
$initialProjectData = null;
if ($initialProjectId > 0) {
    try {
        $initialProjectData = $videoService->getProject($initialProjectId);
    } catch (\Throwable $e) {
        $initialProjectData = null;
    }
}

include_once('includes/header.php');
include_once('includes/sidebar.php');
?>

<section class="content">
    <div class="container-fluid">

        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb">
            <a href="dashboard.php"><i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">dashboard</i> Dashboard</a>
            <span>/</span>
            <a href="ai-generator.php">AI Studio Hub</a>
            <span>/</span>
            <span style="color:var(--af-dark); font-weight:700;">Video Studio</span>
        </div>

        <!-- PAGE HEADER BANNER (CLEAN - NO REDUNDANT SHORTCUT BUTTONS) -->
        <div class="page-header-banner">
            <div class="banner-left">
                <div class="banner-icon-box orange">
                    <i class="material-icons" style="font-size:30px;">videocam</i>
                </div>
                <div>
                    <h1 class="banner-title">
                        AI Recipe Video Studio
                        <span style="background:var(--af-flame); color:white; font-size:11px; padding:3px 9px; border-radius:12px; font-weight:700;">
                            Format Reels &amp; Shorts
                        </span>
                    </h1>
                    <p class="banner-desc">
                        Sintesis video vertikal multi-scene untuk TikTok, Instagram Reels, dan YouTube Shorts. Storyboard dirumuskan dengan <strong>Gemini 3.8 Flash</strong>, dirender per-scene dengan <strong>Pixel-Lock Anchor</strong>, dan disatukan via <strong>FFmpeg</strong> lokal Mac Mini.
                    </p>
                </div>
            </div>
        </div>

        <!-- QUICK LOAD EXISTING PROJECT BAR -->
        <?php if (!empty($recentProjects)): ?>
        <div style="background:#FFF; border:1px solid #CBD5E1; border-radius:12px; padding:12px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="background:#0284C7; color:#FFF; width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center;">
                    <i class="material-icons" style="font-size:18px;">folder_open</i>
                </span>
                <div>
                    <strong style="font-size:13px; color:var(--pp-navy); display:block;">Lanjutkan Proyek Video yang Sudah Ada:</strong>
                    <span style="font-size:11px; color:var(--pp-slate-muted);">Pilih proyek untuk langsung meninjau scene, klip, atau render Langkah 4</span>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex:1; max-width:540px;">
                <select id="selQuickLoadProject" class="form-control" style="height:38px; font-size:12px; border-radius:8px; border:1px solid #94A3B8;" onchange="handleQuickLoadProject(this.value, 4)">
                    <option value="">-- Pilih Proyek Sebelumnya untuk Dilanjutkan --</option>
                    <?php foreach ($recentProjects as $rp): ?>
                        <option value="<?php echo $rp['id']; ?>" <?php echo ($initialProjectId === (int)$rp['id']) ? 'selected' : ''; ?>>
                            #<?php echo $rp['id']; ?> - <?php echo htmlspecialchars($rp['project_title']); ?> (<?php echo $rp['completed_clips']; ?>/<?php echo $rp['total_scenes']; ?> klip siap)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-default btn-sm" onclick="handleQuickLoadProject($('#selQuickLoadProject').val(), 4)" style="height:38px; padding:0 14px; border-radius:8px; font-weight:700;">
                    Buka &rarr;
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- 5-STEP STEPPER -->
        <div class="stepper-nav">
            <div class="stepper-step <?php echo ($initialStep === 1) ? 'active' : (($initialStep > 1 && $initialProjectData) ? 'done' : ''); ?>" id="vstep-btn-1" onclick="jumpToVideoStep(1)">
                <div class="stepper-num">1</div>
                <div>
                    <div class="stepper-label">1. Resep &amp; Dapur</div>
                    <div class="stepper-sub">Format &amp; Anchor Visual</div>
                </div>
            </div>
            <div class="stepper-divider"></div>
            <div class="stepper-step <?php echo ($initialStep === 2) ? 'active' : (($initialStep > 2 && $initialProjectData) ? 'done' : ''); ?>" id="vstep-btn-2" onclick="jumpToVideoStep(2)">
                <div class="stepper-num">2</div>
                <div>
                    <div class="stepper-label">2. Storyboard &amp; Scene</div>
                    <div class="stepper-sub">Gemini 3.8 Flash Breakdown</div>
                </div>
            </div>
            <div class="stepper-divider"></div>
            <div class="stepper-step <?php echo ($initialStep === 3) ? 'active' : (($initialStep > 3 && $initialProjectData) ? 'done' : ''); ?>" id="vstep-btn-3" onclick="jumpToVideoStep(3)">
                <div class="stepper-num">3</div>
                <div>
                    <div class="stepper-label">3. Render Klip Video</div>
                    <div class="stepper-sub">Omni Flash I2V Engine</div>
                </div>
            </div>
            <div class="stepper-divider"></div>
            <div class="stepper-step <?php echo ($initialStep === 4) ? 'active' : (($initialStep > 4 && $initialProjectData) ? 'done' : ''); ?>" id="vstep-btn-4" onclick="jumpToVideoStep(4)">
                <div class="stepper-num">4</div>
                <div>
                    <div class="stepper-label">4. Narasi Suara AI</div>
                    <div class="stepper-sub">MiniMax TTS Studio</div>
                </div>
            </div>
            <div class="stepper-divider"></div>
            <div class="stepper-step <?php echo ($initialStep === 5) ? 'active' : ''; ?>" id="vstep-btn-5" onclick="jumpToVideoStep(5)">
                <div class="stepper-num">5</div>
                <div>
                    <div class="stepper-label">5. Video Utuh &amp; Simpan</div>
                    <div class="stepper-sub">FFmpeg Stitching 9:16</div>
                </div>
            </div>
        </div>

        <div class="tab-card">

            <!-- ================= STEP 1: RESEP & PRESET DAPUR ================= -->
            <div id="vstep-content-1" <?php echo ($initialStep > 1 && $initialProjectData) ? 'style="display:none;"' : ''; ?>>
                <div class="row clearfix">
                    <div class="col-md-7 col-xs-12">
                        <!-- 1.1 Pilih Resep -->
                        <div class="form-group" style="margin-bottom:20px;">
                            <label class="form-label">
                                Pilih Resep untuk Dibuatkan Video: <span class="text-danger">*</span>
                            </label>
                            <select id="videoRecipeSelect" class="form-control" style="height:44px; font-size:13px; font-weight:600; border-radius:8px;" onchange="onVideoRecipeSelectChange()">
                                <?php if (empty($allRecipes)): ?>
                                    <option value="">Belum ada resep di database</option>
                                <?php else: ?>
                                    <?php foreach ($allRecipes as $r): ?>
                                        <option value="<?php echo $r['recipe_id']; ?>">
                                            #<?php echo $r['recipe_id']; ?> - <?php echo htmlspecialchars($r['recipe_title']); ?> (<?php echo htmlspecialchars($r['category_name'] ?? 'Resep'); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- 1.2 Storyboard Mode & Rasio -->
                        <div class="row clearfix" style="margin-bottom:15px;">
                            <div class="col-sm-6 col-xs-12">
                                <label class="form-label">Mode Pembuatan Scene:</label>
                                <input type="hidden" id="selectedStoryboardMode" name="video_storyboard_mode" value="reels">
                                <div class="mode-grid" style="grid-template-columns:1fr; gap:8px;" id="storyboardModeOptions">
                                    <div class="mode-card active" onclick="selectStoryboardMode('reels', this)">
                                        <div class="mode-radio"></div>
                                        <div>
                                            <strong style="font-size:12px; color:var(--pp-navy); display:block;">Reels / Shorts (4–5 Klip Padat)</strong>
                                            <span style="font-size:11px; color:var(--pp-slate-muted);">Format viral vertikal (Hook ➔ Cook ➔ Sizzle ➔ Plate)</span>
                                        </div>
                                    </div>
                                    <div class="mode-card" onclick="selectStoryboardMode('full', this)">
                                        <div class="mode-radio"></div>
                                        <div>
                                            <strong style="font-size:12px; color:var(--pp-navy); display:block;">Full Steps (Semua Langkah Resep)</strong>
                                            <span style="font-size:11px; color:var(--pp-slate-muted);">1 klip per setiap butir langkah instruksi</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-xs-12">
                                <label class="form-label">Rasio Aspek Video:</label>
                                <input type="hidden" id="selectedAspectRatio" name="video_aspect_ratio" value="9:16">
                                <div class="mode-grid" style="grid-template-columns:1fr; gap:8px;" id="aspectRatioOptions">
                                    <div class="mode-card active" onclick="selectAspectRatio('9:16', this)">
                                        <div class="mode-radio"></div>
                                        <div>
                                            <strong style="font-size:12px; color:var(--pp-navy); display:block;">9:16 Vertikal (Rekomendasi)</strong>
                                            <span style="font-size:11px; color:var(--pp-slate-muted);">Format optimal TikTok, Reels, &amp; Shorts</span>
                                        </div>
                                    </div>
                                    <div class="mode-card" onclick="selectAspectRatio('16:9', this)">
                                        <div class="mode-radio"></div>
                                        <div>
                                            <strong style="font-size:12px; color:var(--pp-navy); display:block;">16:9 Horizontal</strong>
                                            <span style="font-size:11px; color:var(--pp-slate-muted);">Format standar desktop YouTube</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 1.3 Bahasa Teks Video & Subtitle -->
                        <div class="form-group" style="margin-bottom:15px;">
                            <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
                                <span>Bahasa Teks Video &amp; Subtitle:</span>
                                <span style="font-size:11px; font-weight:400; color:var(--pp-slate-muted);">Disesuaikan dengan resep &amp; audiens</span>
                            </label>
                            <select id="selectedVideoLanguage" name="video_target_language" class="form-control" style="height:38px; font-size:13px; border-radius:8px;">
                                <option value="auto" selected>Otomatis (Ikuti Bahasa Resep: English / Indonesia) — Rekomendasi</option>
                                <option value="en">English (Instruksi Masak Bahasa Inggris: e.g. "Step 1: Toss wings in sauce")</option>
                                <option value="id">Bahasa Indonesia (Instruksi Masak Indonesia: e.g. "Langkah 1: Masukkan ayam")</option>
                            </select>
                        </div>

                        <!-- 1.4 Preset Dapur & Alat Masak -->
                        <div class="form-group" style="margin-bottom:20px;">
                            <label class="form-label">
                                Pilih Karakter Dapur &amp; Alat Masak (Pixel-Lock Anchor):
                            </label>
                            <input type="hidden" id="selectedKitchenPreset" value="Modern Scandinavian Kitchen">
                            <div style="display:flex; flex-direction:column; gap:8px;" id="kitchenPresetOptions">
                                <div class="mode-card active" onclick="selectKitchenPreset('Modern Scandinavian Kitchen', this)">
                                    <div class="mode-radio"></div>
                                    <div>
                                        <strong style="font-size:13px; color:var(--pp-navy); display:block;">1. Modern Scandinavian Kitchen</strong>
                                        <span style="font-size:11px; color:var(--pp-slate-muted);">Meja quartz stone putih bersih, wajan cast iron hitam, pencahayaan alami jendela samping (*soft daylight*).</span>
                                    </div>
                                </div>
                                <div class="mode-card" onclick="selectKitchenPreset('Rustic Industrial Chef Kitchen', this)">
                                    <div class="mode-radio"></div>
                                    <div>
                                        <strong style="font-size:13px; color:var(--pp-navy); display:block;">2. Rustic Industrial Chef Kitchen</strong>
                                        <span style="font-size:11px; color:var(--pp-slate-muted);">Meja granit dark slate, talenan kayu oak tebal, wajan stainless steel, pencahayaan rim-light sinematik.</span>
                                    </div>
                                </div>
                                <div class="mode-card" onclick="selectKitchenPreset('Cozy Warm Home Kitchen', this)">
                                    <div class="mode-radio"></div>
                                    <div>
                                        <strong style="font-size:13px; color:var(--pp-navy); display:block;">3. Cozy Warm Home Kitchen</strong>
                                        <span style="font-size:11px; color:var(--pp-slate-muted);">Meja kayu butcher block hangat, wadah saji keramik artisan, nuansa hangat golden hour.</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Rancang Storyboard -->
                        <button type="button" id="btnGenerateStoryboard" class="btn-action-primary" onclick="handleGenerateStoryboard()" style="width:100%; justify-content:center; padding:14px; font-size:14px; border-radius:10px;">
                            <i class="material-icons">auto_awesome</i>
                            <span>RANCANG STORYBOARD VIDEO DENGAN AI</span>
                        </button>

                        <div id="videoStoryboardLoading" style="display:none; margin-top:15px; padding:16px; background:#F8FAFC; border:1px solid #CBD5E1; border-radius:10px; text-align:center;">
                            <i class="material-icons" style="font-size:28px; color:var(--pp-orange); animation:spin 1s infinite linear;">autorenew</i>
                            <div style="font-weight:700; color:var(--pp-navy); margin-top:8px; font-size:14px;">Merancang Storyboard...</div>
                        </div>
                    </div>

                    <!-- 1.4 Recipe Preview Column -->
                    <div class="col-md-5 col-xs-12">
                        <div style="border:1px solid var(--pp-slate-border); border-radius:12px; background:#F8FAFC; padding:18px;">
                            <label class="form-label">Pratinjau Resep Terpilih:</label>
                            <div id="videoRecipePreviewCard">
                                <img id="videoRecipePreviewImg" src="assets/images/ic_launcher.png" style="width:100%; height:180px; object-fit:cover; border-radius:8px; margin-bottom:12px;" alt="Preview">
                                <h4 id="videoRecipePreviewTitle" style="font-size:15px; font-weight:700; color:var(--pp-navy); margin:0 0 4px 0;">Pilih Resep</h4>
                                <div id="videoRecipePreviewMeta" style="font-size:11px; color:var(--pp-slate-muted); margin-bottom:12px;">-</div>
                                <div style="font-weight:700; font-size:12px; color:var(--pp-navy); margin-bottom:6px;">Langkah Memasak Asli:</div>
                                <div id="videoRecipePreviewDirections" style="font-size:11.5px; color:var(--pp-slate-muted); max-height:220px; overflow-y:auto; line-height:1.6; background:white; padding:12px; border-radius:8px; border:1px solid var(--pp-slate-border);">
                                    Pilih resep di samping untuk melihat ringkasan langkah.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= STEP 2: STORYBOARD & START FRAME GALLERY ================= -->
            <div id="vstep-content-2" class="video-step-section" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:var(--pp-teal-soft); border:1px solid #A7F3D0; border-radius:10px; padding:14px 18px; margin-bottom:20px;">
                    <div>
                        <div style="font-size:11px; color:var(--pp-teal-dark); font-weight:700;">PROYEK AKTIF:</div>
                        <h4 id="videoActiveProjectTitle" style="margin:2px 0 0 0; font-size:16px; font-weight:800; color:var(--pp-navy);">-</h4>
                        <div id="videoActiveProjectMeta" style="font-size:11px; color:var(--pp-teal-dark); margin-top:2px;">-</div>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-action-secondary" onclick="jumpToVideoStep(1)" style="padding:8px 14px; font-size:12px;">
                            <i class="material-icons" style="font-size:16px;">arrow_back</i> Ubah Resep
                        </button>
                        <button type="button" id="btnRunAllFrames" class="btn-action-primary" onclick="runStaggeredFrameGeneration()" style="padding:8px 16px; font-size:12px;">
                            <i class="material-icons" style="font-size:16px;">palette</i>
                            <span>GENERATE SEMUA START FRAME</span>
                        </button>
                    </div>
                </div>

                <!-- Video Frames Progress & Countdown Box -->
                <div id="videoFramesProgressBox" style="display:none; background:#FFF7ED; border:1px solid #FFEDD5; border-radius:10px; padding:16px; margin-bottom:20px; box-shadow:0 2px 8px rgba(249,115,22,0.08);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <strong style="color:var(--pp-navy); font-size:13px;" id="videoFramesProgressTitle">Mengirim Task Start Frame ke G-Labs...</strong>
                        <span class="label label-primary" id="videoFramesProgressBadge" style="background:#F97316;">0%</span>
                    </div>
                    <div class="progress" style="height:10px; margin-bottom:8px; border-radius:5px; background:#FED7AA;">
                        <div class="progress-bar progress-bar-striped active" id="videoFramesProgressBar" role="progressbar" style="width:0%; background:#F97316;"></div>
                    </div>
                    <div style="font-size:11px; color:#64748B; display:flex; justify-content:space-between;" id="videoFramesProgressDesc">
                        <span>Jeda acak 10-15 detik antar pengiriman prompt untuk menjaga stabilitas GPU G-Labs.</span>
                        <span id="videoFramesTimer" style="font-weight:700; color:#EA580C;">0s</span>
                    </div>
                </div>

                <!-- Storyboard Cards Grid -->
                <div id="videoStoryboardGrid" class="grid-scenes-landscape">
                    <!-- Dynamically populated by renderVideoStoryboard() -->
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--pp-slate-border); padding-top:20px;">
                    <button type="button" class="btn-action-secondary" onclick="jumpToVideoStep(1)">
                        <i class="material-icons">arrow_back</i> Kembali ke Langkah 1
                    </button>
                    <button type="button" id="btnNextToStep3" class="btn-action-primary" onclick="jumpToVideoStep(3)">
                        <span>Lanjut ke Langkah 3: Setting I2V &amp; Klip Video</span>
                        <i class="material-icons">arrow_forward</i>
                    </button>
                </div>
            </div>

            <!-- ================= STEP 3: SETTING I2V & KLIP VIDEO ================= -->
            <div id="vstep-content-3" class="video-step-section" style="display:none;">
                <div style="background:#FFF1EB; border:1px solid #FED7AA; border-radius:10px; padding:16px 20px; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                        <div>
                            <h4 style="margin:0; font-size:15px; font-weight:700; color:var(--pp-navy);">Konfigurasi Engine Video (Image-to-Video)</h4>
                            <div style="font-size:11px; color:var(--pp-slate-muted);">Gambar start frame akan digerakkan menggunakan G-Labs AI Video Engine.</div>
                        </div>
                        <span class="label" style="background:var(--pp-orange); color:white; font-size:11px; padding:4px 8px; border-radius:4px; font-weight:700;">MODEL: OMNI FLASH</span>
                    </div>

                    <div class="row clearfix" style="margin-bottom:0;">
                        <div class="col-sm-6 col-xs-12">
                            <label class="form-label" style="font-size:12px;">Durasi per Scene:</label>
                            <select id="videoClipDurationSelect" class="form-control" style="border-radius:6px; height:40px; font-size:12px;">
                                <option value="4" selected>4 Detik (Rekomendasi Reels / Cepat)</option>
                                <option value="6">6 Detik</option>
                                <option value="8">8 Detik</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-xs-12">
                            <label class="form-label" style="font-size:12px;">Gaya Visual / Motion Focus:</label>
                            <select id="videoMotionStyleSelect" class="form-control" style="border-radius:6px; height:40px; font-size:12px;">
                                <option value="Macrophotography" selected>Macrophotography (Desis Minyak &amp; Uap Panas)</option>
                                <option value="Cinematic Gourmet">Cinematic Gourmet (Gerakan Kamera Halus)</option>
                                <option value="Chef Action">Chef Action (Aktivitas Tangan Memasak)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div style="font-size:14px; font-weight:700; color:var(--pp-navy);">Klip Video per Scene (I2V Motion):</div>
                    <button type="button" id="btnRunAllClips" class="btn-action-primary" onclick="runStaggeredClipGeneration()" style="padding:9px 16px; font-size:12px;">
                        <i class="material-icons" style="font-size:16px;">videocam</i>
                        <span>GENERATE SEMUA VIDEO KLIP (I2V)</span>
                    </button>
                </div>

                <!-- Video Clips Progress & Countdown Box -->
                <div id="videoClipsProgressBox" style="display:none; background:#FFF7ED; border:1px solid #FFEDD5; border-radius:10px; padding:16px; margin-bottom:20px; box-shadow:0 2px 8px rgba(249,115,22,0.08);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <strong style="color:var(--pp-navy); font-size:13px;" id="videoClipsProgressTitle">Mengirim Task Klip Video ke G-Labs...</strong>
                        <span class="label label-primary" id="videoClipsProgressBadge" style="background:#F97316;">0%</span>
                    </div>
                    <div class="progress" style="height:10px; margin-bottom:8px; border-radius:5px; background:#FED7AA;">
                        <div class="progress-bar progress-bar-striped active" id="videoClipsProgressBar" role="progressbar" style="width:0%; background:#F97316;"></div>
                    </div>
                    <div style="font-size:11px; color:#64748B; display:flex; justify-content:space-between;" id="videoClipsProgressDesc">
                        <span>Jeda acak 10-15 detik antar pengiriman prompt I2V untuk menjaga stabilitas GPU G-Labs.</span>
                        <span id="videoClipsTimer" style="font-weight:700; color:#EA580C;">0s</span>
                    </div>
                </div>

                <!-- Video Clips Grid -->
                <div id="videoClipsGrid" class="grid-scenes-landscape">
                    <!-- Dynamically populated by renderVideoClips() -->
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--pp-slate-border); padding-top:20px;">
                    <button type="button" class="btn-action-secondary" onclick="jumpToVideoStep(2)">
                        <i class="material-icons">arrow_back</i> Kembali ke Langkah 2
                    </button>
                    <button type="button" id="btnNextToStep4" class="btn-action-primary" onclick="jumpToVideoStep(4)">
                        <span>Lanjut ke Langkah 4: Narasi Suara AI (MiniMax TTS)</span>
                        <i class="material-icons">arrow_forward</i>
                    </button>
                </div>
            </div>

            <!-- ================= STEP 4: MINI MAX TTS VOICEOVER STUDIO ================= -->
            <div id="vstep-content-4" class="video-step-section" <?php echo ($initialStep === 4 && $initialProjectData) ? 'style="display:block;"' : 'style="display:none;"'; ?>>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                    <div>
                        <h3 style="font-size:16px; font-weight:700; color:var(--pp-navy); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                            <i class="material-icons" style="color:#10B981;">record_voice_over</i>
                            Langkah 4: Studio Narasi Suara AI (MiniMax TTS)
                        </h3>
                        <p style="font-size:13px; color:var(--pp-slate-muted); margin:0;">
                            Sintesis suara narator profesional yang membaca instruksi resep per adegan. Durasi suara otomatis disinkronkan ke video klip via FFmpeg audio padding.
                        </p>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn-brand-primary" id="btnBatchGenerateTts" onclick="handleGenerateAllTts()" style="padding:10px 18px; font-weight:700;">
                            <i class="material-icons" style="font-size:16px;">campaign</i> Generate Semua Narasi TTS
                        </button>
                    </div>
                </div>

                <!-- TTS SETTINGS TOOLBAR CARD -->
                <div style="background:#0F172A; border-radius:14px; padding:18px 20px; color:#F8FAFC; margin-bottom:20px; border:1px solid #334155;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.1);">
                        <strong style="font-size:13px; display:flex; align-items:center; gap:6px;">
                            <i class="material-icons" style="color:#10B981; font-size:18px;">tune</i>
                            Pengaturan Karakter Suara &amp; Musik Latar Proyek:
                        </strong>
                        <span id="ttsEngineBadge" style="font-size:11px; background:rgba(16,185,129,0.2); color:#34D399; font-weight:700; padding:3px 10px; border-radius:12px;">
                            MiniMax speech-2.8-turbo
                        </span>
                    </div>

                    <div class="row clearfix" style="margin-bottom:10px;">
                        <!-- Language -->
                        <div class="col-sm-3 col-xs-12">
                            <label class="form-label" style="font-size:11px; color:#94A3B8; margin-bottom:4px;">Bahasa Narasi:</label>
                            <?php
                            $projLang = $initialProjectData['tts_language'] ?? ($minimaxConfig['minimax_default_language'] ?? 'id-ID');
                            $isProjEn = ($projLang === 'en-US' || (is_string($projLang) && str_starts_with(strtolower($projLang), 'en')));
                            ?>
                            <select id="ttsProjectLanguage" class="form-control" style="height:38px; background:#1E293B; color:#FFF; border-color:#334155; font-size:12px; border-radius:8px;" onchange="onTtsProjectLanguageChange(this.value)">
                                <option value="id-ID" style="background:#1E293B; color:#FFF;" <?php echo (!$isProjEn) ? 'selected' : ''; ?>>Bahasa Indonesia (id-ID)</option>
                                <option value="en-US" style="background:#1E293B; color:#FFF;" <?php echo ($isProjEn) ? 'selected' : ''; ?>>English (en-US)</option>
                            </select>
                        </div>
                        <!-- Voice Persona -->
                        <div class="col-sm-4 col-xs-12">
                            <label class="form-label" style="font-size:11px; color:#94A3B8; margin-bottom:4px;">Karakter Persona Suara:</label>
                            <?php
                            $projVoice = $initialProjectData['tts_voice_persona'] ?? '';
                            $voiceList = $isProjEn ? \Ai\MiniMaxClient::VOICES_EN : \Ai\MiniMaxClient::VOICES_ID;
                            $defaultVoice = $isProjEn ? 'English_causual_narrator_vv1' : ($minimaxConfig['minimax_default_voice'] ?? 'Indonesian_energetic_streamer_vv2');
                            if (empty($projVoice) || !isset($voiceList[$projVoice])) {
                                $projVoice = $defaultVoice;
                            }
                            ?>
                            <select id="ttsProjectVoice" class="form-control" style="height:38px; background:#1E293B; color:#FFF; border-color:#334155; font-size:12px; border-radius:8px;" onchange="if(window.currentVideoProject) window.currentVideoProject.tts_voice_persona = this.value;">
                                <?php foreach ($voiceList as $v): ?>
                                    <option value="<?php echo htmlspecialchars($v['id']); ?>" <?php echo ($v['id'] === $projVoice) ? 'selected' : ''; ?> style="background:#1E293B; color:#FFF;">
                                        <?php echo htmlspecialchars($v['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Speed Slider -->
                        <div class="col-sm-2 col-xs-6">
                            <label class="form-label" style="font-size:11px; color:#94A3B8; margin-bottom:4px; display:flex; justify-content:space-between;">
                                <span>Speed:</span>
                                <span id="txtTtsSpeedVal" style="color:#10B981; font-weight:700;">1.00x</span>
                            </label>
                            <input type="range" id="ttsProjectSpeed" min="0.6" max="1.5" step="0.05" value="1.0" class="form-range" style="width:100%; accent-color:#10B981; margin-top:8px;" oninput="$('#txtTtsSpeedVal').text(parseFloat(this.value).toFixed(2) + 'x')">
                        </div>
                        <!-- Volume Slider -->
                        <div class="col-sm-3 col-xs-6">
                            <label class="form-label" style="font-size:11px; color:#94A3B8; margin-bottom:4px; display:flex; justify-content:space-between;">
                                <span>Volume Suara:</span>
                                <span id="txtTtsVolVal" style="color:#10B981; font-weight:700;">1.00x</span>
                            </label>
                            <input type="range" id="ttsProjectVolume" min="0.3" max="2.0" step="0.05" value="1.0" class="form-range" style="width:100%; accent-color:#10B981; margin-top:8px;" oninput="$('#txtTtsVolVal').text(parseFloat(this.value).toFixed(2) + 'x')">
                        </div>
                    </div>

                    <!-- BGM (Background Music) Options -->
                    <div style="background:#1E293B; border-radius:10px; padding:12px 16px; margin-top:10px; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px;">
                        <label style="margin:0; font-size:12px; color:#F8FAFC; display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="chkEnableBgm" checked style="width:16px; height:16px; accent-color:#10B981;">
                            <span>Aktifkan Musik Latar Upbeat (BGM) &mdash; <span style="color:#94A3B8; font-size:11px;">Mengisi jeda hening jika audio narasi singkat</span></span>
                        </label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="font-size:11px; color:#94A3B8;">Volume BGM:</span>
                            <input type="range" id="rngBgmVolume" min="0.05" max="0.50" step="0.02" value="0.18" style="width:100px; accent-color:#10B981;" oninput="$('#txtBgmVolVal').text(Math.round(this.value * 100) + '%')">
                            <span id="txtBgmVolVal" style="font-size:11px; font-weight:700; color:#34D399; width:35px;">18%</span>
                        </div>
                    </div>
                </div>

                <!-- MASTER AUDIO READINESS & SEQUENTIAL PLAYER DECK -->
                <div id="ttsMasterDeck" class="tts-master-deck">
                    <div style="display:flex; align-items:center; gap:14px;">
                        <div class="tts-readiness-icon">
                            <i class="material-icons">headset</i>
                        </div>
                        <div>
                            <h4 id="ttsMasterReadinessTitle" style="font-size:14px; font-weight:800; color:#0F172A; margin:0 0 2px 0;">Status Narasi: Memuat status adegan...</h4>
                            <p id="ttsMasterReadinessDesc" style="font-size:12px; color:var(--pp-slate-muted); margin:0;">Audio narasi yang telah di-generate dapat Anda dengarkan per adegan atau putar berurutan.</p>
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <button type="button" class="btn-action-secondary" onclick="handleResetAllNaskahToTyping()" style="padding:8px 14px; font-size:12px; font-weight:700;">
                            <i class="material-icons" style="font-size:15px; color:#10B981; vertical-align:middle;">subtitles</i>
                            <span>Gunakan Teks Typing untuk Semua</span>
                        </button>
                        <button type="button" class="btn-action-secondary" id="btnTogglePlayAll" onclick="handleTogglePlayAllScenes()" style="padding:8px 14px; font-size:12px; font-weight:700;">
                            <i class="material-icons" id="iconPlayAllMaster" style="font-size:16px; color:#10B981; vertical-align:middle;">play_circle_filled</i>
                            <span id="txtPlayAllMaster">Putar Semua Narasi Berurutan</span>
                        </button>
                    </div>
                </div>

                <!-- BATCH TTS PROGRESS BAR -->
                <div id="ttsBatchProgressWrap" style="display:none; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:14px 18px; margin-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span id="ttsBatchProgressStatus" style="font-size:12.5px; font-weight:700; color:#0F172A;">Memproses sintesis suara narasi...</span>
                        <span id="ttsBatchProgressPercent" style="font-size:12px; font-weight:800; color:#10B981;">0%</span>
                    </div>
                    <div class="progress" style="height:8px; margin-bottom:0; background:#E2E8F0; border-radius:4px; overflow:hidden;">
                        <div id="ttsBatchProgressBar" class="progress-bar" style="width:0%; background:#10B981; transition:width 0.3s ease;"></div>
                    </div>
                </div>

                <!-- SCENES AUDIO LIST -->
                <div id="ttsScenesContainer" style="display:flex; flex-direction:column; gap:14px; margin-bottom:24px;">
                    <?php if ($initialProjectData && !empty($initialProjectData['scenes'])): ?>
                        <?php foreach ($initialProjectData['scenes'] as $idx => $sc): 
                            $sceneNum = $sc['scene_order'] ?? ($idx + 1);
                            $typingText = $sc['visual_action_desc'] ?? '';
                            $recipeOriginalText = $sc['direction_source_text'] ?? '';
                            $ttsText = $sc['tts_text'] ?? ($typingText ?: $recipeOriginalText);
                            $audioPath = $sc['tts_audio_path'] ?? '';
                            $hasAudio = !empty($audioPath) && ($sc['tts_status'] ?? '') === 'completed';
                            $duration = (float)($sc['tts_duration'] ?? 0);
                            $visualDur = (float)($initialProjectData['clip_duration'] ?? 4.0);
                            $remainingPadding = number_format(max(0, $visualDur - $duration), 1);
                            $wordsCount = !empty(trim($ttsText)) ? count(preg_split('/\s+/', trim($ttsText))) : 0;
                            $charsCount = mb_strlen($ttsText);
                            $durFormatted = sprintf('%d:%02d', floor($duration / 60), floor(fmod($duration, 60)));
                            $thumbUrl = !empty($sc['frame_image_path']) ? $sc['frame_image_path'] : '';
                        ?>
                            <div class="tts-scene-card" id="ttsSceneCard_<?php echo $sc['id']; ?>">
                                <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
                                    <?php if (!empty($thumbUrl)): ?>
                                        <img src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="Scene <?php echo $sceneNum; ?>" style="width:96px; height:160px; object-fit:cover; border-radius:10px; border:1px solid #CBD5E1; flex-shrink:0; background:#1E293B;">
                                    <?php else: ?>
                                        <div style="width:96px; height:160px; background:#1E293B; border-radius:10px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94A3B8; font-size:10px; flex-shrink:0;">
                                            <i class="material-icons" style="font-size:28px; color:#64748B;">movie</i>
                                            <span style="margin-top:4px;">Scene <?php echo $sceneNum; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <span style="background:#0F172A; color:#FFF; font-size:10px; font-weight:800; padding:3px 8px; border-radius:6px; text-align:center; width:100%;">
                                        SCENE <?php echo $sceneNum; ?>
                                    </span>
                                </div>

                                <div style="flex:1; min-width:0; display:flex; flex-direction:column; gap:12px;">
                                    <!-- HEADER BARIS CARD -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:8px; border-bottom:1px solid #F1F5F9;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <strong style="font-size:13.5px; color:#0F172A;">Adegan <?php echo $sceneNum; ?>: <?php echo htmlspecialchars($typingText ?: 'Instruksi Masak'); ?></strong>
                                            <span style="font-size:11px; color:#64748B; background:#F1F5F9; padding:2px 8px; border-radius:6px;">
                                                <i class="material-icons" style="font-size:12px; vertical-align:middle;">videocam</i> Klip: <?php echo number_format($visualDur, 1); ?>s
                                            </span>
                                        </div>
                                        <div id="ttsStatusBadge_<?php echo $sc['id']; ?>">
                                            <?php if ($hasAudio): ?>
                                                <span class="label label-success" style="font-size:11px; font-weight:700;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check_circle</i> Audio Siap (<?php echo number_format($duration, 1); ?>s)</span>
                                            <?php else: ?>
                                                <span class="label label-default" style="font-size:11px; background:#E2E8F0; color:#475569;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">mic_none</i> Belum Ada Suara</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- BLOK 1: ACUAN TEKS (TYPING & RESEP LENGKAP) -->
                                    <div class="tts-recipe-box" style="display:flex; flex-direction:column; gap:8px;">
                                        <!-- Sub-block A: Teks Typing Animasi (Rekomendasi Utama) -->
                                        <div style="background:#FFFFFF; border:1px solid #BBF7D0; border-radius:6px; padding:8px 12px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; flex-wrap:wrap; gap:6px;">
                                                <span style="font-size:11px; font-weight:800; color:#166534; display:flex; align-items:center; gap:5px; text-transform:uppercase;">
                                                    <i class="material-icons" style="font-size:15px; color:#10B981;">subtitles</i>
                                                    Teks Typing Animasi (Motion Graphics 9:16) &bull; <span style="color:#059669; font-weight:600; text-transform:none;">Rekomendasi Narasi</span>
                                                </span>
                                                <button type="button" class="btn-copy-tts-script" onclick="handleCopyNaskahText(<?php echo $sc['id']; ?>, 'typing', this)" style="color:#166534; border-color:#86EFAC; background:#F0FDF4;">
                                                    <i class="material-icons" style="font-size:12px;">content_copy</i> Gunakan Teks Typing
                                                </button>
                                            </div>
                                            <div style="font-size:12.5px; font-weight:700; color:#0F172A;" id="typingText_<?php echo $sc['id']; ?>">
                                                <?php echo htmlspecialchars($typingText); ?>
                                            </div>
                                        </div>

                                        <!-- Sub-block B: Instruksi Resep Asli (Lengkap) -->
                                        <div style="padding:2px 4px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px; flex-wrap:wrap; gap:6px;">
                                                <span style="font-size:10.5px; font-weight:700; color:#92400E; display:flex; align-items:center; gap:4px; text-transform:uppercase;">
                                                    <i class="material-icons" style="font-size:13px;">menu_book</i>
                                                    Instruksi Resep Lengkap
                                                </span>
                                                <button type="button" class="btn-copy-tts-script" onclick="handleCopyNaskahText(<?php echo $sc['id']; ?>, 'recipe', this)">
                                                    <i class="material-icons" style="font-size:12px;">content_copy</i> Gunakan Resep Lengkap
                                                </button>
                                            </div>
                                            <div style="font-size:11.5px; line-height:1.4; color:#78350F;" id="recipeOriginalText_<?php echo $sc['id']; ?>">
                                                <?php echo htmlspecialchars($recipeOriginalText); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- BLOK 2: TEKS NASKAH NARASI TTS (EDITABLE) -->
                                    <div>
                                        <div class="tts-script-label-row">
                                            <label style="font-size:12px; font-weight:800; color:#0F172A; display:flex; align-items:center; gap:6px; margin:0;">
                                                <i class="material-icons" style="font-size:15px; color:#10B981;">mic</i>
                                                Naskah Narasi AI (MiniMax TTS yang Akan Dibacakan):
                                            </label>
                                            <span class="tts-char-counter" id="charCounter_<?php echo $sc['id']; ?>"><?php echo $charsCount; ?> karakter &bull; ~<?php echo $wordsCount; ?> kata</span>
                                        </div>
                                        <textarea id="txtTtsText_<?php echo $sc['id']; ?>" class="form-control" rows="2" style="font-size:12.5px; border-radius:8px; line-height:1.5; resize:vertical;" placeholder="Tuliskan naskah yang dibaca narator..." oninput="handleUpdateCharCounter(<?php echo $sc['id']; ?>)" onchange="handleSaveSceneTtsText(<?php echo $sc['id']; ?>, this.value)"><?php echo htmlspecialchars($ttsText); ?></textarea>
                                    </div>

                                    <!-- BLOK 3: HASIL AUDIO & PREVIEW -->
                                    <div id="ttsAudioBoxWrap_<?php echo $sc['id']; ?>">
                                        <?php if ($hasAudio): ?>
                                            <div class="tts-preview-box ready" id="audioPreviewBox_<?php echo $sc['id']; ?>">
                                                <div style="flex:1; min-width:180px;">
                                                    <div style="font-size:11px; font-weight:800; color:#166534; display:flex; align-items:center; gap:5px; margin-bottom:2px;">
                                                        <i class="material-icons" style="font-size:14px;">volume_up</i> HASIL GENERATE TTS
                                                    </div>
                                                    <div style="font-size:11.5px; color:#475569;">
                                                        Durasi Audio: <strong><?php echo number_format($duration, 1); ?>s</strong> &bull; Sisa <strong><?php echo $remainingPadding; ?>s</strong> jeda disinkronkan via BGM.
                                                    </div>
                                                </div>

                                                <div style="display:flex; align-items:center; gap:12px; flex:1.6; min-width:220px;">
                                                    <button type="button" class="tts-play-btn" id="btnPlayScene_<?php echo $sc['id']; ?>" onclick="handleTogglePlayScene(<?php echo $sc['id']; ?>)" title="Putar / Jeda Audio">
                                                        <i class="material-icons" id="iconPlayBtn_<?php echo $sc['id']; ?>" style="font-size:20px;">play_arrow</i>
                                                    </button>
                                                    <div class="tts-waveform-bar" onclick="seekSceneAudio(<?php echo $sc['id']; ?>, event)">
                                                        <div class="tts-waveform-fill" id="waveFill_<?php echo $sc['id']; ?>"></div>
                                                    </div>
                                                    <span id="playerTimer_<?php echo $sc['id']; ?>" style="font-size:11.5px; font-weight:700; color:#166534; min-width:45px; text-align:right;">
                                                        0:00 / <?php echo $durFormatted; ?>
                                                    </span>
                                                    <audio id="audioPlayer_<?php echo $sc['id']; ?>" preload="none">
                                                        <source src="<?php echo htmlspecialchars($audioPath); ?>?t=<?php echo time(); ?>" type="audio/mpeg">
                                                    </audio>
                                                </div>

                                                <button type="button" class="btn btn-default btn-xs" id="btnGenTts_<?php echo $sc['id']; ?>" onclick="handleGenerateSceneTts(<?php echo $sc['id']; ?>, this)" style="border-radius:6px; font-weight:700; font-size:11px; height:34px; border-color:#CBD5E1; color:#334155;">
                                                    <i class="material-icons" style="font-size:13px; color:#10B981; vertical-align:middle;">refresh</i> Generate Ulang
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="tts-preview-box empty" id="audioPreviewBox_<?php echo $sc['id']; ?>">
                                                <div style="flex:1;">
                                                    <div style="font-size:11px; font-weight:800; color:#64748B; display:flex; align-items:center; gap:5px; margin-bottom:2px;">
                                                        <i class="material-icons" style="font-size:14px;">info</i> BELUM ADA AUDIO NARASI
                                                    </div>
                                                    <div style="font-size:11.5px; color:#64748B;">
                                                        Klik tombol di samping untuk men-generate suara narasi adegan ini via MiniMax AI.
                                                    </div>
                                                </div>

                                                <button type="button" class="btn btn-primary btn-sm" id="btnGenTts_<?php echo $sc['id']; ?>" onclick="handleGenerateSceneTts(<?php echo $sc['id']; ?>, this)" style="background:#10B981; border-color:#059669; border-radius:8px; font-weight:700; font-size:11.5px; padding:6px 14px;">
                                                    <i class="material-icons" style="font-size:14px; vertical-align:middle;">mic</i> Generate Suara Klip Ini
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- FOOTER NAVIGATION -->
                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--pp-slate-border); padding-top:20px;">
                    <button type="button" class="btn-action-secondary" onclick="jumpToVideoStep(3)">
                        <i class="material-icons">arrow_back</i> Kembali ke Langkah 3: Klip Video
                    </button>
                    <button type="button" id="btnNextToStep5" class="btn-action-primary" onclick="jumpToVideoStep(5)">
                        <span>Lanjut ke Langkah 5: Video Utuh &amp; FFmpeg Stitching</span>
                        <i class="material-icons">arrow_forward</i>
                    </button>
                </div>
            </div>

            <!-- ================= STEP 5: FFMPEG STITCHING & FINAL VIDEO ================= -->
            <div id="vstep-content-5" class="video-step-section" style="display:none;">
                <h3 style="font-size:16px; font-weight:700; color:var(--pp-navy); margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                    <i class="material-icons" style="color:var(--pp-success);">check_circle</i>
                    Langkah 5: Video Vertikal 9:16 Siap Diterbitkan
                </h3>
                <p style="font-size:13px; color:var(--pp-slate-muted); margin-bottom:20px;">
                    FFmpeg menggabungkan klip video adegan, audio narasi TTS MiniMax, musik latar upbeat (BGM), dan motion graphics teks mengetik.
                </p>

                <div class="video-preview-wrapper">
                    <!-- PHONE MOCKUP WITH MOTION GRAPHICS LIVE OVERLAY -->
                    <div style="display:flex; flex-direction:column; align-items:center;">
                        <!-- Mode Switcher: Live Simulator vs Video Final -->
                        <div id="step4PhoneModeTabs" style="display:inline-flex; background:#0F172A; border-radius:20px; padding:3px; margin-bottom:12px; border:1px solid #334155;">
                            <button type="button" id="btnPhoneModeSim" class="btn btn-xs" onclick="switchPhoneScreenMode('simulator')" style="background:#10B981; color:#FFF; font-weight:700; border-radius:16px; padding:4px 12px; font-size:11px; border:none;">
                                <i class="material-icons" style="font-size:13px; vertical-align:middle;">tune</i> Simulasi Preset
                            </button>
                            <button type="button" id="btnPhoneModeFinal" class="btn btn-xs" onclick="switchPhoneScreenMode('final')" style="background:transparent; color:#94A3B8; font-weight:600; border-radius:16px; padding:4px 12px; font-size:11px; border:none;">
                                <i class="material-icons" style="font-size:13px; vertical-align:middle;">play_circle_outline</i> Video Final
                            </button>
                        </div>

                        <div class="video-phone-mockup">
                            <div class="phone-screen" style="position:relative; background:#000;">
                                <video id="videoFinalPlayer" controls style="width:100%; height:100%; object-fit:cover; display:none;"></video>
                                <img id="videoFinalPlaceholderImg" src="assets/images/ic_launcher.png" alt="Preview Video" style="width:100%; height:100%; object-fit:cover;">
                                
                                <!-- Safe Zone Lines (Toggleable) -->
                                <div id="step4SafeZone" style="display:none; position:absolute; inset:0; border:2px dashed rgba(245, 158, 11, 0.6); pointer-events:none; z-index:12;">
                                    <div style="position:absolute; bottom:140px; left:0; right:0; border-top:1px dashed rgba(239, 68, 68, 0.7); font-size:9px; color:#FCA5A5; padding-left:6px;">Batas Aman Caption TikTok (Safe Zone)</div>
                                </div>

                                <!-- Live Step 4 Motion Graphics Preview Overlay -->
                                <div id="step4OverlayWrap" style="position:absolute; left:50%; transform:translateX(-50%); width:86%; bottom:85px; z-index:15; pointer-events:none; transition:all 0.25s ease;">
                                    <div id="step4BadgeBox" style="width:100%; border-radius:12px; padding:10px 14px; box-shadow:0 12px 30px rgba(0,0,0,0.55); background:rgba(15,23,42,0.85); backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.18); color:#FFF; font-size:12px; transition:all 0.2s ease;">
                                        <div id="step4HeaderRow" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                            <span id="step4Tag" style="background:#10B981; color:#FFF; font-size:9px; font-weight:800; padding:2px 8px; border-radius:12px; text-transform:uppercase; letter-spacing:0.5px;">STEP 1</span>
                                            <span id="step4Brand" style="font-size:9px; color:#A7F3D0; font-weight:600; opacity:0.85; text-transform:uppercase; letter-spacing:0.4px; display:none;"></span>
                                        </div>
                                        <div id="step4TextWrap" style="font-weight:700; line-height:1.35; min-height:28px;">
                                            <span id="step4TypewriterText"></span><span id="step4Cursor" style="display:inline-block; width:2px; height:12px; background:#FFF; margin-left:2px; vertical-align:-1px; animation:blink 0.8s infinite;">|</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Mini Controls below Phone -->
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; padding:0 4px;">
                                <button type="button" class="btn btn-default btn-xs" onclick="replayStep4Typing()" style="font-size:10px; border-radius:4px; font-weight:600; background:#1E293B; color:#CBD5E1; border:1px solid #334155;">
                                    <i class="material-icons" style="font-size:12px; vertical-align:middle;">replay</i> Ulangi Animasi
                                </button>
                                <button type="button" class="btn btn-default btn-xs" onclick="$('#step4SafeZone').toggle(); $(this).toggleClass('active');" style="font-size:10px; border-radius:4px; font-weight:600; background:#1E293B; color:#CBD5E1; border:1px solid #334155;">
                                    <i class="material-icons" style="font-size:12px; vertical-align:middle;">layers</i> Safe Zone
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- METADATA & ACTIONS -->
                    <div style="flex:1;">
                        <div style="background:#F8FAFC; border:1px solid var(--pp-slate-border); border-radius:12px; padding:20px; margin-bottom:20px;">
                            <h4 style="font-size:15px; font-weight:700; color:var(--pp-navy); margin-bottom:12px;">Rincian Berkas Video Hasil Sintesis:</h4>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; font-size:13px;">
                                <div><strong>Format:</strong> MP4 (H.264 / AAC)</div>
                                <div><strong>Resolusi:</strong> 1080 x 1920 (9:16 Vertikal)</div>
                                <div><strong>Status Stitching:</strong> <span id="videoStitchStatusBadge" class="label label-info">Siap Diproses</span></div>
                                <div><strong>FFmpeg Engine:</strong> /opt/homebrew/bin/ffmpeg</div>
                            </div>
                        </div>

                        <!-- Motion Graphics & Text Overlay Options -->
                        <div style="background:#FFFFFF; border:1px solid var(--pp-slate-border); border-radius:14px; padding:20px; margin-bottom:20px; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #E2E8F0;">
                                <label style="margin:0; font-size:14px; font-weight:700; color:#0F172A; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="chkEnableTextOverlay" checked style="width:18px; height:18px; accent-color:#10B981;" onchange="$('#textOverlayOptionsWrap').slideToggle(this.checked); $('#step4OverlayWrap').toggle(this.checked);">
                                    <span>Aktifkan Motion Graphics Teks Mengetik (Typewriter)</span>
                                </label>
                                <div>
                                    <span class="label" style="background:#10B981; color:white; font-size:10px; font-weight:700; padding:4px 8px; border-radius:4px;">PHP GD + FFMPEG</span>
                                </div>
                            </div>

                            <div id="textOverlayOptionsWrap">
                                <!-- SECTION 1: PRESET GAYA MOTION GRAPHICS (GALLERY GRID) -->
                                <div style="background:#0F172A; border-radius:12px; padding:16px; margin-bottom:16px; color:#F8FAFC;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                        <div style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:6px; color:#F8FAFC;">
                                            <i class="material-icons" style="font-size:16px; color:#10B981;">style</i>
                                            <span>1. Pilihan Preset Gaya Motion Graphics:</span>
                                        </div>
                                        <span style="font-size:10px; font-weight:700; background:rgba(16,185,129,0.2); color:#34D399; padding:2px 8px; border-radius:10px;">5 Preset Motion</span>
                                    </div>

                                    <input type="hidden" id="selOverlayTheme" value="dark_glass">
                                    <div class="preset-theme-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:10px;">
                                        <!-- Dark Glass -->
                                        <div class="preset-card-item active" data-theme="dark_glass" onclick="selectStep4Preset('dark_glass', this)" style="background:#1E293B; border:2px solid #10B981; border-radius:8px; padding:10px; cursor:pointer; transition:all 0.2s;">
                                            <div style="height:32px; background:#0F172A; border-radius:6px; border:1px solid rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; gap:4px; margin-bottom:6px;">
                                                <span style="background:#10B981; color:#FFF; font-size:8px; font-weight:800; padding:1px 5px; border-radius:8px;">STEP 1</span>
                                            </div>
                                            <div style="font-size:11px; font-weight:700; color:#FFF;">Dark Glass</div>
                                            <div style="font-size:9px; color:#94A3B8; line-height:1.2;">Default Pro Glass</div>
                                        </div>

                                        <!-- Minimalist White -->
                                        <div class="preset-card-item" data-theme="minimal_white" onclick="selectStep4Preset('minimal_white', this)" style="background:#1E293B; border:2px solid transparent; border-radius:8px; padding:10px; cursor:pointer; transition:all 0.2s;">
                                            <div style="height:32px; background:#FFF; border-radius:6px; border:1px solid #CBD5E1; display:flex; align-items:center; justify-content:center; gap:4px; margin-bottom:6px;">
                                                <span style="background:#0F172A; color:#FFF; font-size:8px; font-weight:800; padding:1px 5px; border-radius:8px;">STEP 1</span>
                                            </div>
                                            <div style="font-size:11px; font-weight:700; color:#FFF;">Clean White</div>
                                            <div style="font-size:9px; color:#94A3B8; line-height:1.2;">TikTok Aesthetic</div>
                                        </div>

                                        <!-- Amber Chef -->
                                        <div class="preset-card-item" data-theme="amber" onclick="selectStep4Preset('amber', this)" style="background:#1E293B; border:2px solid transparent; border-radius:8px; padding:10px; cursor:pointer; transition:all 0.2s;">
                                            <div style="height:32px; background:#1A1008; border-radius:6px; border:1px solid rgba(245,158,11,0.4); display:flex; align-items:center; justify-content:center; gap:4px; margin-bottom:6px;">
                                                <span style="background:#F59E0B; color:#29170A; font-size:8px; font-weight:800; padding:1px 5px; border-radius:8px;">STEP 1</span>
                                            </div>
                                            <div style="font-size:11px; font-weight:700; color:#FFF;">Warm Amber</div>
                                            <div style="font-size:9px; color:#94A3B8; line-height:1.2;">Gourmet Chef</div>
                                        </div>

                                        <!-- Floating Pill -->
                                        <div class="preset-card-item" data-theme="floating_pill" onclick="selectStep4Preset('floating_pill', this)" style="background:#1E293B; border:2px solid transparent; border-radius:8px; padding:10px; cursor:pointer; transition:all 0.2s;">
                                            <div style="height:32px; background:#0F172A; border-radius:16px; border:1px solid rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center; gap:4px; margin-bottom:6px;">
                                                <span style="background:#10B981; color:#FFF; font-size:8px; font-weight:800; padding:1px 5px; border-radius:8px;">S1</span>
                                            </div>
                                            <div style="font-size:11px; font-weight:700; color:#FFF;">Slim Pill</div>
                                            <div style="font-size:9px; color:#94A3B8; line-height:1.2;">Ultra-Compact</div>
                                        </div>

                                        <!-- Cyber Neon -->
                                        <div class="preset-card-item" data-theme="neon" onclick="selectStep4Preset('neon', this)" style="background:#1E293B; border:2px solid transparent; border-radius:8px; padding:10px; cursor:pointer; transition:all 0.2s;">
                                            <div style="height:32px; background:#0A0F1E; border-radius:6px; border:1px solid #06B6D4; display:flex; align-items:center; justify-content:center; gap:4px; margin-bottom:6px;">
                                                <span style="background:#06B6D4; color:#000; font-size:8px; font-weight:800; padding:1px 5px; border-radius:8px;">S1</span>
                                            </div>
                                            <div style="font-size:11px; font-weight:700; color:#FFF;">Cyber Neon</div>
                                            <div style="font-size:9px; color:#94A3B8; line-height:1.2;">Reels Glow Accent</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- SECTION 2: PROPORSI UKURAN BACKGROUND & POSISI LAYAR -->
                                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px; margin-bottom:16px;">
                                    <div style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                        <i class="material-icons" style="font-size:16px; color:#0284C7;">straighten</i>
                                        <span>2. Proporsi Ukuran Background &amp; Posisi Layar:</span>
                                    </div>
                                    <div class="row clearfix" style="margin-bottom:8px;">
                                        <div class="col-sm-6 col-xs-12">
                                            <label class="form-label" style="font-size:11px; font-weight:600; color:#475569; margin-bottom:4px; display:flex; justify-content:space-between;">
                                                <span>Lebar Background (Badge Width):</span>
                                                <span id="txtBadgeWidthHint" style="color:#059669; font-weight:700;">86% (620px pada 720p)</span>
                                            </label>
                                            <select id="selOverlayWidthRatio" class="form-control" style="height:36px; font-size:12px; border-radius:6px;" onchange="updateStep4LivePreview()">
                                                <option value="0.76">Kompak / Ramping (76% — 545px)</option>
                                                <option value="0.86" selected>Proporsional (86% — 620px) — Rekomendasi</option>
                                                <option value="0.92">Lebar Penuh (92% — 662px)</option>
                                            </select>
                                            <div style="font-size:10px; color:#64748B; margin-top:3px;">
                                                <i class="material-icons" style="font-size:11px; vertical-align:middle; color:#10B981;">check_circle</i> Symmetrical 50px margin (bebas dari pemotongan teks ke kiri).
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-xs-12">
                                            <label class="form-label" style="font-size:11px; font-weight:600; color:#475569; margin-bottom:4px; display:flex; justify-content:space-between;">
                                                <span>Posisi Vertikal di Layar:</span>
                                                <span id="txtBadgePosHint" style="color:#0284C7; font-weight:700;">Bawah (Safe Zone)</span>
                                            </label>
                                            <select id="selOverlayPosition" class="form-control" style="height:36px; font-size:12px; border-radius:6px;" onchange="updateStep4LivePreview()">
                                                <option value="bottom" selected>Bawah Layar (Bottom Safe Zone — 140px dari bawah)</option>
                                                <option value="center">Tengah Layar (Center Focus)</option>
                                                <option value="top">Atas Layar (Top Banner — 60px)</option>
                                            </select>
                                            <div style="font-size:10px; color:#64748B; margin-top:3px;">
                                                Posisi bawah berada aman di atas area interaksi &amp; caption TikTok / Reels.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- SECTION 3: SIMULASI TEKS INSTRUKSI & EFEK ANIMASI -->
                                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px;">
                                    <div style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                        <i class="material-icons" style="font-size:16px; color:#EA580C;">keyboard</i>
                                        <span>3. Simulasi Teks Instruksi &amp; Kustomisasi Brand:</span>
                                    </div>
                                    
                                    <div class="row clearfix" style="margin-bottom:10px;">
                                        <div class="col-sm-4 col-xs-12">
                                            <label class="form-label" style="font-size:11px; font-weight:600; color:#475569; margin-bottom:4px;">Awalan Label Langkah:</label>
                                            <select id="selOverlayPrefix" class="form-control" style="height:36px; font-size:12px; border-radius:6px;" onchange="updateStep4LivePreview()">
                                                <option value="Step " selected>Step (Contoh: STEP 1)</option>
                                                <option value="Langkah ">Langkah (Contoh: LANGKAH 1)</option>
                                                <option value="Tahap ">Tahap (Contoh: TAHAP 1)</option>
                                                <option value="PREP">PREP</option>
                                            </select>
                                        </div>
                                        <div class="col-sm-4 col-xs-12">
                                            <label class="form-label" style="font-size:11px; font-weight:600; color:#475569; margin-bottom:4px;">Nama Brand / Channel:</label>
                                            <input type="text" id="txtOverlayBrandName" class="form-control" value="" placeholder="e.g. @brand.anda / kosongkan" style="height:36px; font-size:12px; border-radius:6px;" oninput="updateStep4LivePreview()">
                                        </div>
                                        <div class="col-sm-4 col-xs-12">
                                            <label class="form-label" style="font-size:11px; font-weight:600; color:#475569; margin-bottom:4px;">Pilih Klip untuk Ditinjau:</label>
                                            <select id="selStep4PreviewClip" class="form-control" style="height:36px; font-size:12px; border-radius:6px;" onchange="switchStep4PhoneClip(this.value)">
                                                <option value="">(Memuat klip video proyek...)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label class="form-label" style="font-size:11px; font-weight:600; color:#475569; margin-bottom:4px;">Uji Teks Mengetik di Mockup HP (Live Preview):</label>
                                        <textarea id="txtStep4SampleInstruction" class="form-control" rows="2" style="font-size:12px; border-radius:6px;" oninput="updateStep4LivePreview(true)">Isi panci besar dengan air di atas kompor.</textarea>
                                        
                                        <!-- Quick Sample Chips -->
                                        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
                                            <button type="button" class="btn btn-default btn-xs" style="font-size:10px; border-radius:4px;" onclick="setStep4SampleText('Isi panci besar dengan air di atas kompor.')">Sample 1: Isi panci...</button>
                                            <button type="button" class="btn btn-default btn-xs" style="font-size:10px; border-radius:4px;" onclick="setStep4SampleText('Aduk madu dan mustard dalam mangkuk kaca dengan whisk.')">Sample 2: Aduk saus...</button>
                                            <button type="button" class="btn btn-default btn-xs" style="font-size:10px; border-radius:4px;" onclick="setStep4SampleText('Tata salmon mentah di keranjang air fryer dan oleskan glasir.')">Sample 3: Tata salmon...</button>
                                            <button type="button" class="btn btn-default btn-xs" style="font-size:10px; border-radius:4px; border-color:#10B981; color:#047857; font-weight:700;" onclick="setStep4SampleText('Serve salmon with warm potato salad.')">★ Scene 7: Serve salmon...</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <button type="button" id="btnStitchVideo" class="btn-action-primary" onclick="handleStitchProject()" style="justify-content:center;">
                                <i class="material-icons">movie_creation</i> Satukan Seluruh Scene Menjadi Video Utuh (FFmpeg)
                            </button>
                            <button type="button" id="btnApplyVideoToRecipe" class="btn-action-primary" onclick="handleApplyVideoToRecipe()" style="justify-content:center; background:var(--pp-success); display:none;">
                                <i class="material-icons">save</i> Simpan Video ke Resep Ini di Database
                            </button>
                            <button type="button" id="btnUploadR2Video" class="btn-action-primary" onclick="handleUploadVideoToR2()" style="justify-content:center; background:#F97316; display:none;">
                                <i class="material-icons">cloud_upload</i> Terbitkan Video ke Cloudflare R2 CDN
                            </button>
                            <a id="btnDownloadVideo" class="btn-action-secondary" href="#" download style="justify-content:center; text-decoration:none; display:none;">
                                <i class="material-icons">file_download</i> Unduh Berkas Video (MP4)
                            </a>
                            <div id="videoR2StatusBox" style="display:none; padding:12px 16px; border-radius:8px; font-size:13px; line-height:1.5;"></div>
                        </div>
                    </div>
                </div>

                <div style="border-top:1px solid var(--pp-slate-border); padding-top:20px; margin-top:24px;">
                    <button type="button" class="btn-action-secondary" onclick="jumpToVideoStep(4)">
                        <i class="material-icons">arrow_back</i> Kembali ke Langkah 4: Narasi Suara AI
                    </button>
                </div>
            </div>

        </div>

    </div>
</section>

<!-- SCRIPTS -->
<script src="assets/plugins/bootstrap/js/bootstrap.js"></script>
<script src="assets/js/admin.js"></script>
<script>
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const recipesData = <?php echo json_encode($allRecipes, JSON_UNESCAPED_UNICODE); ?>;
    const minimaxConfig = <?php echo json_encode($minimaxConfig, JSON_UNESCAPED_UNICODE); ?>;
    const minimaxVoicesId = <?php echo json_encode(array_values(\Ai\MiniMaxClient::VOICES_ID), JSON_UNESCAPED_UNICODE); ?>;
    const minimaxVoicesEn = <?php echo json_encode(array_values(\Ai\MiniMaxClient::VOICES_EN), JSON_UNESCAPED_UNICODE); ?>;
    let currentVideoProject = <?php echo safe_json_encode($initialProjectData); ?>;

    $(document).ready(function() {
        if (recipesData && recipesData.length > 0) {
            onVideoRecipeSelectChange();
        }

        if (currentVideoProject) {
            $('#selQuickLoadProject').val(currentVideoProject.id);
            renderStoryboardScenes(currentVideoProject);
            jumpToVideoStep(<?php echo $initialStep; ?>);
            if (<?php echo $initialStep; ?> === 4) {
                renderStep4TtsDeck();
            }
        } else {
            // Check URL parameters for direct project jump (e.g. ?project_id=13&step=4)
            const urlParams = new URLSearchParams(window.location.search);
            const pId = parseInt(urlParams.get('project_id'), 10);
            const pStep = parseInt(urlParams.get('step'), 10) || 4;
            if (pId > 0) {
                handleQuickLoadProject(pId, pStep);
            }
        }
    });

    function handleQuickLoadProject(projectId, targetStep = 4) {
        projectId = parseInt(projectId, 10);
        if (!projectId) return;

        $('#selQuickLoadProject').val(projectId);
        
        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_get_project',
                csrf_token: csrfToken,
                project_id: projectId
            },
            success: function(resp) {
                if (resp.success && resp.project) {
                    currentVideoProject = resp.project;
                    renderStoryboardScenes(resp.project);
                    jumpToVideoStep(targetStep);
                } else {
                    alert('Gagal memuat proyek #' + projectId + ': ' + (resp.error || 'Proyek tidak ditemukan'));
                }
            },
            error: function() {
                alert('Gagal menghubungi server untuk memuat proyek #' + projectId);
            }
        });
    }

    function jumpToVideoStep(stepNum) {
        if (stepNum > 1 && !currentVideoProject) {
            alert('Silakan rancang storyboard video terlebih dahulu pada Langkah 1.');
            return;
        }

        for (let i = 1; i <= 5; i++) {
            const btn = $('#vstep-btn-' + i);
            const content = $('#vstep-content-' + i);

            if (i < stepNum) {
                btn.removeClass('active').addClass('done');
                content.hide();
            } else if (i === stepNum) {
                btn.removeClass('done').addClass('active');
                content.show().css('display', 'block');
            } else {
                btn.removeClass('active done');
                content.hide();
            }
        }

        if (stepNum === 4) {
            $('#vstep-content-4').show().css('display', 'block');
            if (currentVideoProject) {
                renderStep4TtsDeck();
            }
        }

        if (stepNum === 5 && currentVideoProject) {
            populateStep4ClipsDropdown();
            const vidPath = currentVideoProject.stitched_video_path || currentVideoProject.video_output_path;
            if (vidPath) {
                const fullVidUrl = vidPath.startsWith('http') || vidPath.startsWith('upload/') ? vidPath : 'upload/' + vidPath;
                $('#videoStitchStatusBadge').removeClass('label-info').addClass('label-success').text('Selesai Digabungkan');
                $('#btnApplyVideoToRecipe').show();
                $('#btnUploadR2Video').show();
                $('#btnDownloadVideo').attr('href', fullVidUrl).attr('download', fullVidUrl.split('/').pop()).show();
                $('#btnPhoneModeFinal').show();
            } else {
                $('#btnPhoneModeFinal').hide();
            }
            // Always default to interactive simulator so user sees preview with correct position
            switchPhoneScreenMode('simulator');
        }
    }

    function onVideoRecipeSelectChange() {
        const recipeId = parseInt($('#videoRecipeSelect').val(), 10);
        const r = recipesData.find(item => parseInt(item.recipe_id, 10) === recipeId);
        if (!r) return;

        $('#videoRecipePreviewTitle').text(r.recipe_title);
        $('#videoRecipePreviewMeta').text((r.category_name || 'Resep') + ' • ' + (r.recipe_time || '30 Min'));
        
        const imgPath = r.recipe_image ? 'upload/' + r.recipe_image : 'assets/images/ic_launcher.png';
        $('#videoRecipePreviewImg').attr('src', imgPath);
        $('#videoFinalPlaceholderImg').attr('src', imgPath);
        $('#videoOverlayTitle').text(r.recipe_title);

        let desc = r.recipe_description ? r.recipe_description.replace(/<[^>]*>?/gm, '').trim() : '';
        $('#videoRecipePreviewDirections').text(desc || 'Langkah memasak otomatis.');
    }

    function selectStoryboardMode(mode, el) {
        $('#storyboardModeOptions .mode-card').removeClass('active');
        $(el).addClass('active');
        $('#selectedStoryboardMode').val(mode);
    }

    function selectAspectRatio(ratio, el) {
        $('#aspectRatioOptions .mode-card').removeClass('active');
        $(el).addClass('active');
        $('#selectedAspectRatio').val(ratio);
    }

    function selectKitchenPreset(presetKey, el) {
        $('#kitchenPresetOptions .mode-card').removeClass('active');
        $(el).addClass('active');
        $('#selectedKitchenPreset').val(presetKey);
    }

    function handleGenerateStoryboard() {
        const recipeId = parseInt($('#videoRecipeSelect').val(), 10);
        if (!recipeId) {
            alert('Pilih resep terlebih dahulu.');
            return;
        }

        const btn = $('#btnGenerateStoryboard');
        const loading = $('#videoStoryboardLoading');

        btn.prop('disabled', true);
        loading.show();

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            timeout: 180000,
            data: {
                ajax_action: 'video_create_project_storyboard',
                csrf_token: csrfToken,
                recipe_id: recipeId,
                kitchen_preset: $('#selectedKitchenPreset').val(),
                aspect_ratio: $('#selectedAspectRatio').val(),
                storyboard_mode: $('#selectedStoryboardMode').val(),
                target_language: $('#selectedVideoLanguage').val() || 'auto'
            },
            success: function(resp) {
                btn.prop('disabled', false);
                loading.hide();

                const projectData = resp.project || (resp.success && resp.scenes ? resp : null);
                if (resp.success && projectData) {
                    currentVideoProject = projectData;
                    renderStoryboardScenes(projectData);
                    jumpToVideoStep(2);
                } else {
                    alert('Gagal membuat storyboard: ' + (resp.error || 'Terjadi kesalahan sistem.'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false);
                loading.hide();

                let errMsg = 'Terjadi gangguan komunikasi dengan server.';
                if (status === 'timeout') {
                    errMsg = 'Batas waktu terlampaui (timeout 3 menit). Server AI membutuhkan waktu lebih lama untuk merumuskan adegan storyboard. Silakan coba kembali.';
                } else if (xhr.responseJSON && xhr.responseJSON.error) {
                    errMsg = xhr.responseJSON.error;
                } else if (xhr.status === 0) {
                    errMsg = 'Koneksi server terputus (HTTP status 0). Pastikan web server lokal tetap berjalan.';
                } else if (xhr.responseText) {
                    try {
                        const parsed = JSON.parse(xhr.responseText);
                        if (parsed.error) errMsg = parsed.error;
                    } catch(e) {
                        errMsg = 'Error (' + xhr.status + '): ' + (error || status || 'Server tidak merespons');
                    }
                } else if (error) {
                    errMsg = error;
                }
                alert('Koneksi server gagal: ' + errMsg);
            }
        });
    }

    let isFramesBatchRunning = false;
    let isClipsBatchRunning = false;

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

    function submitFrameTaskPromise(sceneId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                timeout: 60000,
                data: {
                    ajax_action: 'video_submit_frame_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: resolve,
                error: function(xhr, status, error) {
                    reject({ status: status, statusText: error || 'HTTP error ' + xhr.status });
                }
            });
        });
    }

    function pollFrameTaskPromise(sceneId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    ajax_action: 'video_poll_frame_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: resolve,
                error: function(xhr, status, error) {
                    reject({ status: status, statusText: error || 'HTTP error ' + xhr.status });
                }
            });
        });
    }

    function submitClipTaskPromise(sceneId, duration, motionStyle) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                timeout: 60000,
                data: {
                    ajax_action: 'video_submit_clip_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId,
                    clip_duration: duration,
                    motion_style: motionStyle
                },
                success: resolve,
                error: function(xhr, status, error) {
                    reject({ status: status, statusText: error || 'HTTP error ' + xhr.status });
                }
            });
        });
    }

    function pollClipTaskPromise(sceneId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    ajax_action: 'video_poll_clip_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: resolve,
                error: function(xhr, status, error) {
                    reject({ status: status, statusText: error || 'HTTP error ' + xhr.status });
                }
            });
        });
    }

    function updateSceneFrameCompleted(sceneId, imgPath) {
        const fullSrc = (imgPath.startsWith('upload/') ? imgPath : 'upload/' + imgPath) + '?t=' + Date.now();
        $('#scene-frame-img-' + sceneId).attr('src', fullSrc);
        $('#scene-frame-badge-' + sceneId).removeClass('label-warning label-info label-danger label-default').addClass('label-success').text('Selesai');
        $('#scene-frame-status-text-' + sceneId).text('Siap');
        $('#btn-render-frame-' + sceneId).prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">replay</i> <span>Render Ulang</span>');

        if (currentVideoProject && currentVideoProject.scenes) {
            const sc = currentVideoProject.scenes.find(s => parseInt(s.id, 10) === parseInt(sceneId, 10));
            if (sc) {
                sc.frame_status = 'completed';
                sc.frame_image_path = imgPath;
            }
        }
        $('#clip-thumb-img-' + sceneId).attr('src', fullSrc);
    }

    function updateSceneClipCompleted(sceneId, clipPath) {
        const fullClipUrl = (clipPath.startsWith('upload/') ? clipPath : 'upload/' + clipPath) + '?t=' + Date.now();
        const isVertical = currentVideoProject && (currentVideoProject.aspect_ratio || '').indexOf('9:16') !== -1;
        const isSquare = currentVideoProject && (currentVideoProject.aspect_ratio || '').indexOf('1:1') !== -1;
        const mediaClass = isVertical ? 'aspect-frame-vertical' : (isSquare ? 'aspect-frame-square' : 'aspect-frame-landscape');

        $('#clip-media-wrap-' + sceneId).html('<video src="' + fullClipUrl + '" controls class="' + mediaClass + '"></video>');
        $('#clip-badge-' + sceneId).removeClass('label-warning label-info label-danger label-default').addClass('label-success').text('Video Siap');
        $('#clip-status-text-' + sceneId).text('Klip siap disatukan');
        $('#btn-render-clip-' + sceneId).prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">replay</i> <span>Render Ulang Klip</span>');

        if (currentVideoProject && currentVideoProject.scenes) {
            const sc = currentVideoProject.scenes.find(s => parseInt(s.id, 10) === parseInt(sceneId, 10));
            if (sc) {
                sc.video_status = 'completed';
                sc.video_clip_path = clipPath;
            }
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function handleMoveScene(idx, direction) {
        if (!currentVideoProject || !currentVideoProject.scenes) return;
        const scenes = currentVideoProject.scenes;
        const targetIdx = idx + direction;
        if (targetIdx < 0 || targetIdx >= scenes.length) return;

        // Swap in local array
        const temp = scenes[idx];
        scenes[idx] = scenes[targetIdx];
        scenes[targetIdx] = temp;

        // Update sequential scene_order property
        scenes.forEach((sc, i) => { sc.scene_order = i + 1; });

        // Re-render UI immediately for instant feedback
        renderStoryboardScenes(currentVideoProject);

        // Sync new order to server
        const sceneIds = scenes.map(sc => sc.id);
        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_reorder_scenes',
                csrf_token: csrfToken,
                project_id: currentVideoProject.id,
                scene_ids: sceneIds
            },
            success: function(resp) {
                if (!resp.success) {
                    alert('Gagal memperbarui urutan adegan di server: ' + (resp.error || 'Terjadi kesalahan'));
                }
            },
            error: function() {
                console.warn('Gagal sinkronisasi urutan adegan ke server.');
            }
        });
    }

    function promptEditSceneText(sceneId) {
        if (!currentVideoProject || !currentVideoProject.scenes) return;
        const sc = currentVideoProject.scenes.find(s => parseInt(s.id, 10) === parseInt(sceneId, 10));
        if (!sc) return;

        const currentText = sc.visual_action_desc || sc.visual_action || '';
        const newText = prompt('Ubah deskripsi aksi visual untuk Scene ini:', currentText);
        if (newText !== null && newText.trim() !== '' && newText.trim() !== currentText) {
            const cleanText = newText.trim();
            sc.visual_action_desc = cleanText;
            $('#scene-action-text-' + sceneId).text(cleanText);

            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'video_update_scene_text',
                    csrf_token: csrfToken,
                    scene_id: sceneId,
                    visual_action_desc: cleanText
                },
                success: function(resp) {
                    if (resp.success) {
                        renderStoryboardScenes(currentVideoProject);
                    } else {
                        alert('Gagal menyimpan perubahan teks: ' + (resp.error || 'Unknown error'));
                    }
                }
            });
        }
    }

    function cleanCookingInstructionCaptionJS(text) {
        if (!text) return '';
        let s = String(text).trim().replace(/^["'`]+|["'`]+$/g, '');
        // Strip Indonesian third-person prefixes
        s = s.replace(/^(?:(?:tangan|jari)(?:\s+koki|\s+chef)?|koki|chef|seseorang)\s+/i, '');
        // Strip English third-person prefixes
        s = s.replace(/^(?:(?:the|a)\s+)?(?:hands?|fingers?|chef|cook|person|someone)\s+(?:are\s+|is\s+)?/i, '');
        // Strip camera angles
        s = s.replace(/^(?:(?:close[\s-]*up|overhead|macro|wide|cinematic|camera)\s+)?(?:shot|view|angle)\s+of\s+/i, '');
        
        // Indonesian me- replacements
        const meMap = {
            'menekan': 'Tekan',
            'menjatuhkan': 'Masukkan', 'mengisi': 'Isi', 'mengaduk': 'Aduk', 'menata': 'Tata',
            'memotong': 'Potong', 'menumis': 'Tumis', 'merebus': 'Rebus', 'memanaskan': 'Panaskan',
            'menuangkan': 'Tuangkan', 'menuang': 'Tuang', 'mencampurkan': 'Campurkan', 'mencampur': 'Campur',
            'memanggang': 'Panggang', 'menambahkan': 'Tambahkan', 'menyajikan': 'Sajikan', 'mengupas': 'Kupas',
            'merendam': 'Rendam', 'mengocok': 'Kocok', 'mengoleskan': 'Oleskan', 'mengoles': 'Oles',
            'menggiling': 'Giling', 'menaburkan': 'Taburkan', 'menabur': 'Tabur', 'membalik': 'Balik',
            'memeras': 'Peras', 'meniriskan': 'Tiriskan', 'mencincang': 'Cincang', 'memarut': 'Parut',
            'mengukus': 'Kukus', 'menyiapkan': 'Siapkan', 'mengangkat': 'Angkat',
            'mengeringkan': 'Keringkan', 'membumbui': 'Bumbui', 'mendinginkan': 'Dinginkan'
        };
        for (const [me, rep] of Object.entries(meMap)) {
            const re = new RegExp('^' + me + '\\b', 'i');
            if (re.test(s)) {
                s = s.replace(re, rep);
                break;
            }
        }

        // English -ing / -s replacements
        const ingMap = {
            'pressing': 'Press', 'presses': 'Press',
            'filling': 'Fill', 'stirring': 'Stir', 'adding': 'Add', 'pouring': 'Pour',
            'chopping': 'Chop', 'cutting': 'Cut', 'mixing': 'Mix', 'heating': 'Heat',
            'boiling': 'Boil', 'baking': 'Bake', 'roasting': 'Roast', 'frying': 'Fry',
            'whisking': 'Whisk', 'placing': 'Place', 'arranging': 'Arrange', 'seasoning': 'Season',
            'draining': 'Drain', 'serving': 'Serve', 'tossing': 'Toss', 'dropping': 'Drop',
            'preheating': 'Preheat', 'peeling': 'Peel', 'grating': 'Grate', 'slicing': 'Slice',
            'garnishing': 'Garnish', 'spreading': 'Spread', 'brushing': 'Brush', 'flipping': 'Flip',
            'simmering': 'Simmer', 'adds': 'Add', 'stirs': 'Stir', 'pours': 'Pour',
            'places': 'Place', 'drops': 'Drop', 'chops': 'Chop', 'cuts': 'Cut', 'mixes': 'Mix',
            'heats': 'Heat', 'boils': 'Boil', 'bakes': 'Bake', 'whisks': 'Whisk', 'arranges': 'Arrange',
            'seasons': 'Season', 'serves': 'Serve', 'tosses': 'Toss'
        };
        for (const [ing, rep] of Object.entries(ingMap)) {
            const re = new RegExp('^' + ing + '\\b', 'i');
            if (re.test(s)) {
                s = s.replace(re, rep);
                break;
            }
        }

        if (s.length > 0) {
            s = s.charAt(0).toUpperCase() + s.slice(1);
        }
        return s;
    }

    function renderStoryboardScenes(proj) {
        $('#videoActiveProjectTitle').text(proj.recipe_title || 'Video Resep');
        $('#videoActiveProjectMeta').text('Proyek #' + (proj.id || proj.project_id) + ' • ' + (proj.aspect_ratio || '9:16') + ' • ' + (proj.kitchen_preset || 'Modern Minimalist'));

        const isVertical = (proj.aspect_ratio || '').indexOf('9:16') !== -1;
        const isSquare = (proj.aspect_ratio || '').indexOf('1:1') !== -1;
        const mediaClass = isVertical ? 'aspect-frame-vertical' : (isSquare ? 'aspect-frame-square' : 'aspect-frame-landscape');
        const gridClass = isVertical ? 'grid-scenes-vertical' : 'grid-scenes-landscape';

        // Detect project language for step labels & defaults
        const isEn = /^[a-zA-Z0-9\s,\.\-&']+$/.test(proj.recipe_title || '') && !/(sayap|ayam|goreng|rebus|bumbu|pedas|manis|nasi|tahu|tempe|sambal|ikan|udang|daging|sop|kuah|tumis|porsi|hangat)/i.test(proj.recipe_title || '');
        const stepPrefix = isEn ? 'Step' : 'Langkah';
        if ($('#selOverlayPrefix').length) {
            $('#selOverlayPrefix').val(isEn ? 'Step ' : 'Langkah ');
        }

        const grid = $('#videoStoryboardGrid');
        grid.empty();
        grid.removeClass('grid-scenes-vertical grid-scenes-landscape').addClass(gridClass);

        if (!proj.scenes || proj.scenes.length === 0) {
            grid.append('<div style="grid-column:1/-1; text-align:center; padding:30px; color:#94a3b8;">Tidak ada adegan terdeteksi.</div>');
            return;
        }

        proj.scenes.forEach(function(scene, idx) {
            const thumb = scene.frame_image_path ? (scene.frame_image_path.startsWith('upload/') ? scene.frame_image_path : 'upload/' + scene.frame_image_path) : 'assets/images/ic_launcher.png';
            const rawAction = scene.visual_action_desc || scene.visual_action || scene.visual_focus || ('Adegan ' + (idx + 1));
            const actionText = cleanCookingInstructionCaptionJS(rawAction);
            const promptT2i = scene.prompt_t2i || '-';
            const promptI2v = scene.prompt_i2v || '';
            const isCompleted = scene.frame_status === 'completed';
            const isRunning = scene.frame_status === 'running';
            const badgeClass = isCompleted ? 'label-success' : (isRunning ? 'label-warning' : (scene.frame_status === 'failed' ? 'label-danger' : 'label-default'));
            const badgeText = isCompleted ? 'Selesai' : (isRunning ? 'Merender...' : (scene.frame_status === 'failed' ? 'Gagal' : 'Pending'));
            const btnLabel = isCompleted ? 'Render Ulang' : 'Render Frame Ini';
            const btnIcon = isCompleted ? 'replay' : 'palette';

            const card = `
                <div class="workspace-card" style="margin-bottom:0; display:flex; flex-direction:column; justify-content:space-between;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span class="scene-badge">SCENE ${idx + 1} (${scene.duration_seconds || 4}s)</span>
                                ${idx > 0 ? `<button type="button" class="btn btn-default btn-xs" onclick="handleMoveScene(${idx}, -1)" title="Geser ke atas" style="padding:1px 5px; border-radius:4px; line-height:1;"><i class="material-icons" style="font-size:13px; vertical-align:middle;">arrow_upward</i></button>` : ''}
                                ${idx < proj.scenes.length - 1 ? `<button type="button" class="btn btn-default btn-xs" onclick="handleMoveScene(${idx}, 1)" title="Geser ke bawah" style="padding:1px 5px; border-radius:4px; line-height:1;"><i class="material-icons" style="font-size:13px; vertical-align:middle;">arrow_downward</i></button>` : ''}
                            </div>
                            <span id="scene-frame-badge-${scene.id}" class="label ${badgeClass}">${badgeText}</span>
                        </div>
                        <img id="scene-frame-img-${scene.id}" src="${thumb}" class="${mediaClass}" alt="Scene ${idx + 1}">
                        
                        ${scene.direction_source_text ? `
                            <div style="font-size:11px; color:#065F46; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:6px; padding:5px 8px; margin-top:8px; margin-bottom:8px; line-height:1.4;">
                                <strong style="display:block; font-size:10px; color:#047857; text-transform:uppercase; letter-spacing:0.5px;">Instruksi Resep Asli:</strong>
                                ${escapeHtml(scene.direction_source_text)}
                            </div>
                        ` : ''}

                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-top:6px; margin-bottom:6px; gap:4px;">
                            <div id="scene-action-text-${scene.id}" style="font-size:13px; font-weight:700; color:var(--pp-navy); line-height:1.4;">${escapeHtml(actionText)}</div>
                            <button type="button" class="btn btn-link btn-xs" onclick="promptEditSceneText(${scene.id})" style="padding:0 4px; color:#64748B; flex-shrink:0;" title="Edit teks aksi"><i class="material-icons" style="font-size:15px;">edit</i></button>
                        </div>

                        <div style="font-size:11px; color:var(--pp-slate-muted); line-height:1.4; margin-bottom:8px;">
                            <strong style="color:#475569;">Prompt T2I:</strong> ${promptT2i.length > 150 ? escapeHtml(promptT2i.substring(0, 150)) + '...' : escapeHtml(promptT2i)}
                        </div>
                        ${promptI2v ? `<div style="font-size:11px; color:#1D4ED8; line-height:1.4; background:#EFF6FF; padding:6px 8px; border-radius:6px; border:1px solid #DBEAFE; margin-bottom:8px;"><strong>Motion I2V:</strong> ${escapeHtml(promptI2v)}</div>` : ''}
                    </div>
                    <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #E2E8F0; padding-top:10px;">
                        <span id="scene-frame-status-text-${scene.id}" style="font-size:11px; color:var(--pp-slate-muted); font-weight:600;">${isCompleted ? 'Frame siap' : (isRunning ? 'Sedang render GPU' : 'Belum dirender')}</span>
                        <button type="button" id="btn-render-frame-${scene.id}" class="btn-action-primary" onclick="generateSingleFrame(${scene.id})" style="padding:6px 12px; font-size:11px; border-radius:6px;">
                            <i class="material-icons" style="font-size:14px;">${btnIcon}</i>
                            <span>${btnLabel}</span>
                        </button>
                    </div>
                </div>
            `;
            grid.append(card);
        });

        // Populate Step 3 Video Clips Grid
        const clipsGrid = $('#videoClipsGrid');
        if (clipsGrid.length) {
            clipsGrid.empty();
            clipsGrid.removeClass('grid-scenes-vertical grid-scenes-landscape').addClass(gridClass);

            proj.scenes.forEach(function(scene, idx) {
                const thumb = scene.frame_image_path ? (scene.frame_image_path.startsWith('upload/') ? scene.frame_image_path : 'upload/' + scene.frame_image_path) : 'assets/images/ic_launcher.png';
                const rawAction = scene.visual_action_desc || scene.visual_action || scene.visual_focus || ('Adegan ' + (idx + 1));
                const actionText = cleanCookingInstructionCaptionJS(rawAction);
                const clipPath = scene.video_clip_path ? (scene.video_clip_path.startsWith('upload/') ? scene.video_clip_path : 'upload/' + scene.video_clip_path) : '';
                const isReady = !!clipPath;
                const isRunning = scene.video_status === 'running';
                const badgeClass = isReady ? 'label-success' : (isRunning ? 'label-warning' : (scene.video_status === 'failed' ? 'label-danger' : 'label-default'));
                const badgeText = isReady ? 'Video Siap' : (isRunning ? 'Merender I2V...' : (scene.video_status === 'failed' ? 'Gagal' : 'Pending'));
                const btnLabel = isReady ? 'Render Ulang Klip' : 'Generate Klip Ini';
                const btnIcon = isReady ? 'replay' : 'videocam';

                const clipCard = `
                    <div class="workspace-card" style="margin-bottom:0; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span class="scene-badge">KLIP ${idx + 1} (${scene.duration_seconds || 4}s)</span>
                                <span id="clip-badge-${scene.id}" class="label ${badgeClass}">${badgeText}</span>
                            </div>
                            <div id="clip-media-wrap-${scene.id}">
                                ${isReady 
                                    ? `<video src="${clipPath}?t=${Date.now()}" controls class="${mediaClass}"></video>` 
                                    : `<img id="clip-thumb-img-${scene.id}" src="${thumb}" class="${mediaClass}" style="opacity:0.9;">`
                                }
                            </div>
                            <div style="font-size:13px; font-weight:700; color:var(--pp-navy); margin-top:8px; margin-bottom:4px; line-height:1.4;">${escapeHtml(actionText)}</div>
                            
                            <!-- Custom Typing Text Overlay Input -->
                            <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:6px; padding:6px 8px; margin-top:8px; margin-bottom:8px;">
                                <label style="font-size:11px; font-weight:700; color:#334155; margin-bottom:3px; display:flex; justify-content:space-between; align-items:center;">
                                    <span>Teks Typing ${stepPrefix} ${idx + 1}:</span>
                                    <i class="material-icons" style="font-size:14px; color:#10B981;" title="Teks animasi ketik yang akan dibakar ke video">keyboard</i>
                                </label>
                                <input type="text" class="form-control clip-overlay-custom-text" data-scene-id="${scene.id}" value="${escapeHtml(actionText)}" style="height:30px; font-size:11px; border-radius:4px; border:1px solid #CBD5E1;" placeholder="Teks ringkas untuk animasi...">
                            </div>

                            <div style="font-size:11px; color:#1D4ED8; line-height:1.4; background:#EFF6FF; padding:6px 8px; border-radius:6px; border:1px solid #DBEAFE; margin-bottom:8px;">
                                <strong>Motion I2V:</strong> ${escapeHtml(scene.prompt_i2v || 'Motion camera dinamis')}
                            </div>
                        </div>
                        <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #E2E8F0; padding-top:10px;">
                            <span id="clip-status-text-${scene.id}" style="font-size:11px; color:var(--pp-slate-muted); font-weight:600;">${isReady ? 'Klip siap disatukan' : (isRunning ? 'Sedang render I2V' : 'Belum digenerate')}</span>
                            <button type="button" id="btn-render-clip-${scene.id}" class="btn-action-primary" onclick="generateSingleClip(${scene.id})" style="padding:6px 12px; font-size:11px; border-radius:6px;">
                                <i class="material-icons" style="font-size:14px;">${btnIcon}</i>
                                <span>${btnLabel}</span>
                            </button>
                        </div>
                    </div>
                `;
                clipsGrid.append(clipCard);
            });
        }
    }

    function generateSingleFrame(sceneId, onDone) {
        const btn = $('#btn-render-frame-' + sceneId);
        const badge = $('#scene-frame-badge-' + sceneId);
        const statusText = $('#scene-frame-status-text-' + sceneId);
        
        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:14px;">autorenew</i> <span>Mengirim...</span>');
        badge.removeClass('label-default label-success label-danger label-info').addClass('label-warning').text('Mengirim...');
        statusText.text('Mengantrekan ke G-Labs...');

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            timeout: 60000,
            data: {
                ajax_action: 'video_submit_frame_task',
                csrf_token: csrfToken,
                scene_id: sceneId
            },
            success: function(resp) {
                if (resp.success && resp.task_id) {
                    badge.removeClass('label-warning').addClass('label-info').text('GPU Queue');
                    statusText.text('Task #' + resp.task_id.substring(0, 8) + '...');
                    btn.html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:14px;">autorenew</i> <span>Merender...</span>');
                    pollSingleFrame(sceneId, resp.task_id, onDone);
                } else {
                    btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">palette</i> <span>Render Frame Ini</span>');
                    badge.removeClass('label-warning label-info').addClass('label-danger').text('Gagal');
                    statusText.text('Gagal submit');
                    alert('Gagal mengirim task ke G-Labs: ' + (resp.error || 'Terjadi kesalahan sistem'));
                    if (typeof onDone === 'function') onDone(false);
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">palette</i> <span>Render Frame Ini</span>');
                badge.removeClass('label-warning label-info').addClass('label-danger').text('Error');
                statusText.text('Koneksi gagal');
                alert('Gagal menghubungi server untuk adegan #' + sceneId + ': ' + error);
                if (typeof onDone === 'function') onDone(false);
            }
        });
    }

    function pollSingleFrame(sceneId, taskId, onDone) {
        let elapsed = 0;
        const btn = $('#btn-render-frame-' + sceneId);
        const badge = $('#scene-frame-badge-' + sceneId);
        const statusText = $('#scene-frame-status-text-' + sceneId);

        const timer = setInterval(function() {
            elapsed += 2;
            btn.html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:14px;">autorenew</i> <span>GPU (' + elapsed + 's)</span>');
            statusText.text('Merender (' + elapsed + 's)...');

            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    ajax_action: 'video_poll_frame_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: function(resp) {
                    const imgPath = resp.image_path || resp.image_url;
                    if (resp.success && (resp.status === 'completed' || imgPath)) {
                        clearInterval(timer);
                        updateSceneFrameCompleted(sceneId, imgPath);
                        if (typeof onDone === 'function') onDone(true);
                    } else if (!resp.success || resp.status === 'failed') {
                        clearInterval(timer);
                        badge.removeClass('label-warning label-info label-success').addClass('label-danger').text('Gagal');
                        statusText.text(resp.error || 'Gagal');
                        btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">replay</i> <span>Coba Lagi</span>');
                        if (typeof onDone === 'function') onDone(false);
                    }
                },
                error: function() {
                    if (elapsed >= 120) {
                        clearInterval(timer);
                        badge.removeClass('label-warning label-info').addClass('label-danger').text('Timeout');
                        statusText.text('Poll timeout');
                        btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">replay</i> <span>Coba Lagi</span>');
                        if (typeof onDone === 'function') onDone(false);
                    }
                }
            });
        }, 2000);
    }

    async function runStaggeredFrameGeneration() {
        if (!currentVideoProject || !currentVideoProject.scenes || currentVideoProject.scenes.length === 0) {
            alert('Tidak ada adegan yang dapat dirender. Rancang storyboard terlebih dahulu.');
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
        const mainBtn = $('#btnRunAllFrames');
        mainBtn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> <span>Memproses...</span>');
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

            $('#scene-frame-badge-' + sc.id).removeClass('label-default label-success label-danger').addClass('label-warning').html('<i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mengirim...');
            $('#scene-frame-status-text-' + sc.id).text('Mengantrekan ke G-Labs...');
            $('#btn-render-frame-' + sc.id).prop('disabled', true);

            try {
                const submitRes = await submitFrameTaskPromise(sc.id);
                if (submitRes.success && submitRes.task_id) {
                    submittedTaskScenes.push(sc.id);
                    $('#scene-frame-badge-' + sc.id).removeClass('label-warning').addClass('label-info').html('<i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Task: ' + submitRes.task_id.substring(0, 8) + '...');
                    $('#scene-frame-status-text-' + sc.id).text('Antrean GPU diterima');
                } else {
                    const err = submitRes.error || 'G-Labs tidak mengembalikan task ID.';
                    errors.push('Scene #' + sceneNum + ': ' + err);
                    $('#scene-frame-badge-' + sc.id).removeClass('label-warning label-info').addClass('label-danger').text('Gagal');
                    $('#scene-frame-status-text-' + sc.id).text(err);
                    $('#btn-render-frame-' + sc.id).prop('disabled', false);
                }
            } catch (err) {
                console.error('Submit error scene ' + sc.id, err);
                const errMsg = err.statusText || 'Koneksi ke server gagal.';
                errors.push('Scene #' + sceneNum + ': ' + errMsg);
                $('#scene-frame-badge-' + sc.id).removeClass('label-warning label-info').addClass('label-danger').text('Error');
                $('#scene-frame-status-text-' + sc.id).text(errMsg);
                $('#btn-render-frame-' + sc.id).prop('disabled', false);
            }

            // Staggered Delay 10-15 seconds before dispatching next prompt
            if (i < scenes.length - 1) {
                const delaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoFramesTimer', 'Jeda acak ' + delaySec + ' detik antar pengiriman prompt G-Labs...', delaySec);
            }
        }

        // PHASE 2: POLLING EACH TASK UNTIL COMPLETE WITH 10-15s DELAYS
        $('#videoFramesProgressTitle').text(submittedTaskScenes.length + ' dari ' + total + ' task terkirim. Memeriksa status G-Labs...');
        let pendingSceneIds = [...submittedTaskScenes];
        let pollPass = 0;

        while (pendingSceneIds.length > 0 && pollPass < 35) {
            pollPass++;
            const nextPending = [];

            for (let j = 0; j < pendingSceneIds.length; j++) {
                const scId = pendingSceneIds[j];
                $('#videoFramesProgressTitle').text('Memeriksa status hasil render scene #' + scId + ' (Pass ' + pollPass + ')...');

                try {
                    const pollRes = await pollFrameTaskPromise(scId);
                    const imgPath = pollRes.image_path || pollRes.image_url;
                    if (pollRes.status === 'completed' && imgPath) {
                        updateSceneFrameCompleted(scId, imgPath);
                        completedSceneIds.push(scId);
                    } else if (pollRes.status === 'failed' || pollRes.success === false) {
                        const err = pollRes.error || 'Render Start Frame gagal.';
                        errors.push('Scene #' + scId + ': ' + err);
                        $('#scene-frame-badge-' + scId).removeClass('label-warning label-info').addClass('label-danger').text('Gagal');
                        $('#scene-frame-status-text-' + scId).text(err);
                        $('#btn-render-frame-' + scId).prop('disabled', false);
                    } else {
                        $('#scene-frame-status-text-' + scId).text('Merender di GPU...');
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
            const totalProgressPercent = Math.min(98, 50 + Math.round(((total - pendingSceneIds.length) / total) * 50));
            $('#videoFramesProgressBadge').text(totalProgressPercent + '%');
            $('#videoFramesProgressBar').css('width', totalProgressPercent + '%');

            if (pendingSceneIds.length > 0) {
                const pollDelaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoFramesTimer', 'Menunggu ' + pollDelaySec + ' detik sebelum polling berikutnya (' + pendingSceneIds.length + ' scene pending)...', pollDelaySec);
            }
        }

        // FINISH
        isFramesBatchRunning = false;
        mainBtn.prop('disabled', false).html('<i class="material-icons">palette</i> <span>GENERATE SEMUA START FRAME</span>');
        $('#videoFramesProgressBadge').text('100%');
        $('#videoFramesProgressBar').css('width', '100%');
        $('#videoFramesProgressTitle').text('Generasi Start Frame Selesai! ' + completedSceneIds.length + ' dari ' + total + ' berhasil.');
        $('#videoFramesTimer').text('0s');

        let summaryMsg = 'Proses Start Frame selesai! ' + completedSceneIds.length + ' dari ' + total + ' adegan berhasil dirender.';
        if (errors.length > 0) {
            summaryMsg += '\n\nCatatan kendala:\n' + errors.join('\n');
        }
        alert(summaryMsg);
    }

    function generateSingleClip(sceneId, onDone) {
        if (!currentVideoProject || !currentVideoProject.scenes) {
            alert('Proyek video belum aktif.');
            return;
        }

        const sc = currentVideoProject.scenes.find(s => parseInt(s.id, 10) === parseInt(sceneId, 10));
        if (!sc || !sc.frame_image_path) {
            alert('Start frame untuk adegan ini belum digenerate. Silakan generate start frame pada Langkah 2 terlebih dahulu.');
            return;
        }

        const btn = $('#btn-render-clip-' + sceneId);
        const badge = $('#clip-badge-' + sceneId);
        const statusText = $('#clip-status-text-' + sceneId);
        const duration = parseInt($('#videoClipDurationSelect').val(), 10) || 4;
        const motionStyle = $('#videoMotionStyleSelect').val() || 'Macrophotography';

        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:14px;">autorenew</i> <span>Mengirim...</span>');
        badge.removeClass('label-default label-success label-danger label-info').addClass('label-warning').text('Mengirim...');
        statusText.text('Mengantrekan I2V ke G-Labs...');

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            timeout: 60000,
            data: {
                ajax_action: 'video_submit_clip_task',
                csrf_token: csrfToken,
                scene_id: sceneId,
                clip_duration: duration,
                motion_style: motionStyle
            },
            success: function(resp) {
                if (resp.success && resp.task_id) {
                    badge.removeClass('label-warning').addClass('label-info').text('I2V Queue');
                    statusText.text('Task #' + resp.task_id.substring(0, 8) + '...');
                    btn.html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:14px;">autorenew</i> <span>Merender...</span>');
                    pollSingleClip(sceneId, resp.task_id, onDone);
                } else {
                    btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">videocam</i> <span>Generate Klip Ini</span>');
                    badge.removeClass('label-warning label-info').addClass('label-danger').text('Gagal');
                    statusText.text('Gagal submit');
                    alert('Gagal mengirim task I2V ke G-Labs: ' + (resp.error || 'Terjadi kesalahan sistem'));
                    if (typeof onDone === 'function') onDone(false);
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">videocam</i> <span>Generate Klip Ini</span>');
                badge.removeClass('label-warning label-info').addClass('label-danger').text('Error');
                statusText.text('Koneksi gagal');
                alert('Gagal menghubungi server untuk klip #' + sceneId + ': ' + error);
                if (typeof onDone === 'function') onDone(false);
            }
        });
    }

    function pollSingleClip(sceneId, taskId, onDone) {
        let elapsed = 0;
        const btn = $('#btn-render-clip-' + sceneId);
        const badge = $('#clip-badge-' + sceneId);
        const statusText = $('#clip-status-text-' + sceneId);

        const timer = setInterval(function() {
            elapsed += 3;
            btn.html('<i class="material-icons" style="animation:spin 1s infinite linear; font-size:14px;">autorenew</i> <span>I2V (' + elapsed + 's)</span>');
            statusText.text('Rendering gerak (' + elapsed + 's)...');

            $.ajax({
                url: 'video-generator.php',
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    ajax_action: 'video_poll_clip_task',
                    csrf_token: csrfToken,
                    scene_id: sceneId
                },
                success: function(resp) {
                    const clipPath = resp.video_clip_path || resp.video_url;
                    if (resp.success && (resp.status === 'completed' || clipPath)) {
                        clearInterval(timer);
                        updateSceneClipCompleted(sceneId, clipPath);
                        if (typeof onDone === 'function') onDone(true);
                    } else if (!resp.success || resp.status === 'failed') {
                        clearInterval(timer);
                        badge.removeClass('label-warning label-info label-success').addClass('label-danger').text('Gagal');
                        statusText.text(resp.error || 'Gagal');
                        btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">replay</i> <span>Coba Lagi</span>');
                        if (typeof onDone === 'function') onDone(false);
                    }
                },
                error: function() {
                    if (elapsed >= 180) {
                        clearInterval(timer);
                        badge.removeClass('label-warning label-info').addClass('label-danger').text('Timeout');
                        statusText.text('I2V timeout');
                        btn.prop('disabled', false).html('<i class="material-icons" style="font-size:14px;">replay</i> <span>Coba Lagi</span>');
                        if (typeof onDone === 'function') onDone(false);
                    }
                }
            });
        }, 3000);
    }

    async function runStaggeredClipGeneration() {
        if (!currentVideoProject || !currentVideoProject.scenes || currentVideoProject.scenes.length === 0) {
            alert('Tidak ada adegan yang dapat dibuatkan klip video.');
            return;
        }

        if (isClipsBatchRunning) {
            alert('Proses generasi klip video sedang berjalan.');
            return;
        }

        const unready = currentVideoProject.scenes.filter(s => !s.frame_image_path);
        if (unready.length > 0) {
            alert('Terdapat ' + unready.length + ' adegan yang belum memiliki Start Frame. Harap generate Start Frame pada Langkah 2 terlebih dahulu.');
            return;
        }

        const scenesToProcess = currentVideoProject.scenes.filter(s => s.video_status !== 'completed');
        if (scenesToProcess.length === 0) {
            if (!confirm('Semua klip video sudah selesai. Apakah Anda ingin meng-generate ulang semuanya?')) {
                return;
            }
        }

        const scenes = scenesToProcess.length > 0 ? scenesToProcess : currentVideoProject.scenes;
        isClipsBatchRunning = true;
        const mainBtn = $('#btnRunAllClips');
        mainBtn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> <span>Memproses Klip...</span>');
        $('#videoClipsProgressBox').slideDown();

        const duration = parseInt($('#videoClipDurationSelect').val(), 10) || 4;
        const motionStyle = $('#videoMotionStyleSelect').val() || 'Macrophotography';

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

            $('#clip-badge-' + sc.id).removeClass('label-default label-success label-danger').addClass('label-warning').html('<i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Mengirim...');
            $('#clip-status-text-' + sc.id).text('Mengantrekan I2V ke G-Labs...');
            $('#btn-render-clip-' + sc.id).prop('disabled', true);

            try {
                const submitRes = await submitClipTaskPromise(sc.id, duration, motionStyle);
                if (submitRes.success && submitRes.task_id) {
                    submittedTaskScenes.push(sc.id);
                    $('#clip-badge-' + sc.id).removeClass('label-warning').addClass('label-info').html('<i class="material-icons" style="font-size:10px; vertical-align:middle; animation:spin 1s infinite linear;">autorenew</i> Task: ' + submitRes.task_id.substring(0, 8) + '...');
                    $('#clip-status-text-' + sc.id).text('Antrean I2V diterima');
                } else {
                    const err = submitRes.error || 'G-Labs tidak mengembalikan task ID.';
                    errors.push('Scene #' + sceneNum + ': ' + err);
                    $('#clip-badge-' + sc.id).removeClass('label-warning label-info').addClass('label-danger').text('Gagal');
                    $('#clip-status-text-' + sc.id).text(err);
                    $('#btn-render-clip-' + sc.id).prop('disabled', false);
                }
            } catch (err) {
                console.error('Submit clip error scene ' + sc.id, err);
                const errMsg = err.statusText || 'Koneksi ke server gagal.';
                errors.push('Scene #' + sceneNum + ': ' + errMsg);
                $('#clip-badge-' + sc.id).removeClass('label-warning label-info').addClass('label-danger').text('Error');
                $('#clip-status-text-' + sc.id).text(errMsg);
                $('#btn-render-clip-' + sc.id).prop('disabled', false);
            }

            // Staggered Delay 10-15 seconds before dispatching next clip prompt
            if (i < scenes.length - 1) {
                const delaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoClipsTimer', 'Jeda acak ' + delaySec + ' detik antar pengiriman klip video G-Labs...', delaySec);
            }
        }

        // PHASE 2: POLLING EACH VIDEO TASK UNTIL COMPLETE WITH 10-15s DELAYS
        $('#videoClipsProgressTitle').text(submittedTaskScenes.length + ' dari ' + total + ' task klip terkirim. Memeriksa status G-Labs...');
        let pendingSceneIds = [...submittedTaskScenes];
        let pollPass = 0;

        while (pendingSceneIds.length > 0 && pollPass < 45) {
            pollPass++;
            const nextPending = [];

            for (let j = 0; j < pendingSceneIds.length; j++) {
                const scId = pendingSceneIds[j];
                $('#videoClipsProgressTitle').text('Memeriksa render klip scene #' + scId + ' (Pass ' + pollPass + ')...');

                try {
                    const pollRes = await pollClipTaskPromise(scId);
                    const clipPath = pollRes.video_clip_path || pollRes.video_url;
                    if (pollRes.status === 'completed' && clipPath) {
                        updateSceneClipCompleted(scId, clipPath);
                        completedSceneIds.push(scId);
                    } else if (pollRes.status === 'failed' || pollRes.success === false) {
                        const err = pollRes.error || 'Render video klip gagal.';
                        errors.push('Scene #' + scId + ': ' + err);
                        $('#clip-badge-' + scId).removeClass('label-warning label-info').addClass('label-danger').text('Gagal');
                        $('#clip-status-text-' + scId).text(err);
                        $('#btn-render-clip-' + scId).prop('disabled', false);
                    } else {
                        $('#clip-status-text-' + scId).text('Rendering gerak GPU...');
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
            const totalProgressPercent = Math.min(98, 50 + Math.round(((total - pendingSceneIds.length) / total) * 50));
            $('#videoClipsProgressBadge').text(totalProgressPercent + '%');
            $('#videoClipsProgressBar').css('width', totalProgressPercent + '%');

            if (pendingSceneIds.length > 0) {
                const pollDelaySec = 10 + Math.floor(Math.random() * 6);
                await countdownDelay('videoClipsTimer', 'Menunggu ' + pollDelaySec + ' detik sebelum polling klip berikutnya (' + pendingSceneIds.length + ' pending)...', pollDelaySec);
            }
        }

        // FINISH
        isClipsBatchRunning = false;
        mainBtn.prop('disabled', false).html('<i class="material-icons">videocam</i> <span>GENERATE SEMUA VIDEO KLIP (I2V)</span>');
        $('#videoClipsProgressBadge').text('100%');
        $('#videoClipsProgressBar').css('width', '100%');
        $('#videoClipsProgressTitle').text('Generasi Klip Selesai! ' + completedSceneIds.length + ' dari ' + total + ' klip berhasil.');
        $('#videoClipsTimer').text('0s');

        let summaryMsg = 'Proses generasi klip video selesai! ' + completedSceneIds.length + ' dari ' + total + ' klip berhasil dibuat.';
        if (errors.length > 0) {
            summaryMsg += '\n\nCatatan kendala:\n' + errors.join('\n');
        }
        alert(summaryMsg);
    }

    function handleStitchProject() {
        if (!currentVideoProject) return;

        const btn = $('#btnStitchVideo');
        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> FFmpeg sedang memproses overlay teks &amp; stitching...');

        const customTexts = {};
        $('.clip-overlay-custom-text').each(function() {
            const sid = $(this).data('scene-id');
            const txt = $(this).val();
            if (sid && txt) {
                customTexts[sid] = txt;
            }
        });

        const stitchData = {
            ajax_action: 'video_stitch_all_scenes',
            csrf_token: csrfToken,
            project_id: currentVideoProject.id,
            enable_text_overlay: $('#chkEnableTextOverlay').is(':checked') ? 1 : 0,
            overlay_theme: $('#selOverlayTheme').val() || 'dark_glass',
            overlay_position: $('#selOverlayPosition').val() || 'bottom',
            overlay_width_ratio: $('#selOverlayWidthRatio').val() || '0.86',
            overlay_brand_name: $('#txtOverlayBrandName').val() || '',
            overlay_step_prefix: $('#selOverlayPrefix').val() || 'Step ',
            overlay_custom_texts: customTexts,
            enable_bgm: $('#chkEnableBgm').is(':checked') ? 1 : 0,
            bgm_volume: $('#rngBgmVolume').val() || 0.18
        };

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            timeout: 300000,
            data: stitchData,
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="material-icons">movie_creation</i> Satukan Seluruh Scene Menjadi Video Utuh (FFmpeg)');
                if (resp.success && resp.video_url) {
                    currentVideoProject.stitched_video_path = resp.video_url;
                    currentVideoProject.video_output_path = resp.video_url;
                    $('#videoStitchStatusBadge').removeClass('label-info').addClass('label-success').text('Selesai Digabungkan');
                    $('#videoFinalPlaceholderImg').hide();
                    $('#videoFinalPlayer').attr('src', resp.video_url).show();
                    $('#btnApplyVideoToRecipe').show();
                    $('#btnUploadR2Video').show();
                    $('#btnDownloadVideo').attr('href', resp.video_url).attr('download', resp.video_url.split('/').pop()).show();
                    alert('Video berhasil disatukan via FFmpeg!');
                } else {
                    alert('Gagal menyatukan video: ' + (resp.error || 'Terjadi kesalahan sistem.'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons">movie_creation</i> Satukan Seluruh Scene Menjadi Video Utuh (FFmpeg)');
                let errMsg = error;
                if (status === 'timeout') {
                    errMsg = 'Proses stitching FFmpeg melebihi batas waktu (timeout 5 menit).';
                } else if (xhr.responseJSON && xhr.responseJSON.error) {
                    errMsg = xhr.responseJSON.error;
                } else if (xhr.status === 0) {
                    errMsg = 'Koneksi server terputus saat proses render FFmpeg.';
                }
                alert('Koneksi FFmpeg gagal: ' + errMsg);
            }
        });
    }

    function handleApplyVideoToRecipe() {
        if (!currentVideoProject) return;

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_apply_to_recipe',
                csrf_token: csrfToken,
                project_id: currentVideoProject.id
            },
            success: function(resp) {
                if (resp.success) {
                    alert('Video berhasil disimpan ke resep #' + currentVideoProject.recipe_id + '!');
                    location.reload();
                } else {
                    alert('Gagal: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    }

    function handleUploadVideoToR2() {
        if (!currentVideoProject) {
            alert('Proyek video belum aktif.');
            return;
        }

        const btn = $('#btnUploadR2Video');
        const statusBox = $('#videoR2StatusBox');
        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Mengunggah ke Cloudflare R2 CDN...');
        statusBox.show().css({
            'background': '#FFF7ED',
            'color': '#C2410C',
            'border': '1px solid #FFEDD5'
        }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom; animation:spin 1s infinite linear;">sync</i> Mengirim file video ke bucket Cloudflare R2...');

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            timeout: 300000,
            data: {
                ajax_action: 'video_upload_to_r2',
                csrf_token: csrfToken,
                project_id: currentVideoProject.id,
                video_url: currentVideoProject.stitched_video_path || currentVideoProject.video_output_path || ''
            },
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="material-icons">cloud_upload</i> Terbitkan Video ke Cloudflare R2 CDN');
                if (resp && resp.success) {
                    statusBox.css({
                        'background': '#D1FAE5',
                        'color': '#047857',
                        'border': '1px solid #A7F3D0'
                    }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">check_circle</i> <strong>Upload Berhasil!</strong> Video telah tersedia di CDN:<br><a href="' + resp.public_url + '" target="_blank" style="color:#047857; text-decoration:underline; font-weight:700; word-break:break-all;">' + resp.public_url + '</a>');
                    alert('Video resep berhasil diterbitkan ke Cloudflare R2 CDN!');
                } else {
                    statusBox.css({
                        'background': '#FEE2E2',
                        'color': '#B91C1C',
                        'border': '1px solid #FECACA'
                    }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">error</i> Gagal upload: ' + (resp.error || 'Terjadi kesalahan sistem'));
                    alert('Gagal mengunggah video ke R2: ' + (resp.error || 'Terjadi kesalahan sistem'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons">cloud_upload</i> Terbitkan Video ke Cloudflare R2 CDN');
                let errMsg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                statusBox.css({
                    'background': '#FEE2E2',
                    'color': '#B91C1C',
                    'border': '1px solid #FECACA'
                }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">error</i> Koneksi gagal: ' + errMsg);
                alert('Koneksi ke server gagal: ' + errMsg);
            }
        });
    }

    let step4TypingTimer = null;
    let step4CurrentScreenMode = 'simulator';

    function switchPhoneScreenMode(mode) {
        step4CurrentScreenMode = mode;
        const btnSim = $('#btnPhoneModeSim');
        const btnFinal = $('#btnPhoneModeFinal');
        const player = $('#videoFinalPlayer');
        const overlay = $('#step4OverlayWrap');
        const img = $('#videoFinalPlaceholderImg');

        if (mode === 'final') {
            btnFinal.css({ background: '#10B981', color: '#FFF' });
            btnSim.css({ background: 'transparent', color: '#94A3B8' });

            const vidPath = currentVideoProject ? (currentVideoProject.stitched_video_path || currentVideoProject.video_output_path) : '';
            if (vidPath) {
                const fullVidUrl = vidPath.startsWith('http') || vidPath.startsWith('upload/') ? vidPath : 'upload/' + vidPath;
                img.hide();
                overlay.hide();
                player.attr('src', fullVidUrl);
                player[0].muted = false;
                player[0].loop = false;
                player.show();
                player[0].play().catch(function() {});
            } else {
                alert('Video final belum digabungkan. Silakan klik tombol "Satukan Seluruh Scene Menjadi Video Utuh" terlebih dahulu.');
                switchPhoneScreenMode('simulator');
            }
        } else {
            // Simulator mode: shows live typing badge on selected scene clip
            btnSim.css({ background: '#10B981', color: '#FFF' });
            btnFinal.css({ background: 'transparent', color: '#94A3B8' });

            overlay.show();
            const curClip = $('#selStep4PreviewClip').val();
            if (curClip) {
                switchStep4PhoneClip(curClip);
            } else {
                updateStep4LivePreview(true);
            }
        }
    }

    function selectStep4Preset(themeKey, el) {
        $('#selOverlayTheme').val(themeKey);
        $('.preset-card-item').css({ 'border-color': 'transparent' }).removeClass('active');
        if (el) {
            $(el).css({ 'border-color': '#10B981' }).addClass('active');
        }
        updateStep4LivePreview(false);
    }

    function setStep4SampleText(txt) {
        $('#txtStep4SampleInstruction').val(txt);
        updateStep4LivePreview(true);
    }

    function replayStep4Typing() {
        updateStep4LivePreview(true);
    }

    function populateStep4ClipsDropdown() {
        const sel = $('#selStep4PreviewClip');
        if (!sel.length) return;
        sel.empty();

        if (!currentVideoProject || !currentVideoProject.scenes || currentVideoProject.scenes.length === 0) {
            sel.append('<option value="">(Belum ada scene)</option>');
            return;
        }

        let readyCount = 0;
        currentVideoProject.scenes.forEach(function(sc, idx) {
            const clipPath = sc.video_clip_path ? (sc.video_clip_path.startsWith('upload/') ? sc.video_clip_path : 'upload/' + sc.video_clip_path) : '';
            const actionText = sc.visual_action_desc || sc.visual_action || ('Scene ' + (idx + 1));
            const shortText = actionText.length > 28 ? actionText.substring(0, 28) + '...' : actionText;
            
            if (clipPath) {
                readyCount++;
                const isSelected = readyCount === 1 ? 'selected' : '';
                sel.append(`<option value="${clipPath}" data-step="${idx + 1}" data-text="${escapeHtml(actionText)}" ${isSelected}>Scene ${idx + 1}: ${escapeHtml(shortText)}</option>`);
            }
        });

        if (readyCount === 0) {
            sel.append('<option value="">(Belum ada klip yang selesai digenerate)</option>');
        } else {
            // Automatically switch phone mockup background to the first ready clip
            const firstClip = sel.val();
            if (firstClip) {
                switchStep4PhoneClip(firstClip);
            }
        }
    }

    function switchStep4PhoneClip(clipUrl) {
        if (!clipUrl) return;
        const player = $('#videoFinalPlayer');
        const img = $('#videoFinalPlaceholderImg');

        img.hide();
        player.attr('src', clipUrl);
        player[0].muted = true;
        player[0].loop = true;
        player[0].playsInline = true;
        player.show();
        player[0].play().catch(function() {});

        // Sync sample text and step prefix with selected scene
        const selOpt = $('#selStep4PreviewClip option:selected');
        if (selOpt.length) {
            const scText = selOpt.data('text');
            const scStep = selOpt.data('step');
            if (scText) {
                $('#txtStep4SampleInstruction').val(scText);
            }
            if (scStep) {
                const prefix = $('#selOverlayPrefix').val() || 'Step ';
                $('#step4Tag').text(prefix.trim() + ' ' + scStep);
            }
        }
        updateStep4LivePreview(true);
    }

    function updateStep4LivePreview(restartTyping) {
        if (!$('#step4OverlayWrap').length) return;

        const theme = $('#selOverlayTheme').val() || 'dark_glass';
        const widthRatio = parseFloat($('#selOverlayWidthRatio').val() || 0.86);
        const pos = $('#selOverlayPosition').val() || 'bottom';
        const prefix = $('#selOverlayPrefix').val() || 'Step ';
        const brand = $('#txtOverlayBrandName').val() || '';

        // Dynamic Hints
        if (widthRatio <= 0.78) {
            $('#txtBadgeWidthHint').text('76% (545px pada 720p)');
        } else if (widthRatio >= 0.90) {
            $('#txtBadgeWidthHint').text('92% (662px pada 720p)');
        } else {
            $('#txtBadgeWidthHint').text('86% (620px pada 720p) — Rekomendasi');
        }

        if (pos === 'top') {
            $('#txtBadgePosHint').text('Atas Layar (Top Banner)');
            $('#step4OverlayWrap').css({ top: '30px', bottom: 'auto', transform: 'translateX(-50%)' });
        } else if (pos === 'center') {
            $('#txtBadgePosHint').text('Tengah Layar (Center Focus)');
            $('#step4OverlayWrap').css({ top: '50%', bottom: 'auto', transform: 'translate(-50%, -50%)' });
        } else {
            $('#txtBadgePosHint').text('Bawah (Safe Zone)');
            $('#step4OverlayWrap').css({ top: 'auto', bottom: '85px', transform: 'translateX(-50%)' });
        }

        // Width
        $('#step4OverlayWrap').css('width', (widthRatio * 100) + '%');

        // Tag & Brand
        const selOpt = $('#selStep4PreviewClip option:selected');
        const curStepNum = selOpt.length && selOpt.data('step') ? selOpt.data('step') : 1;
        $('#step4Tag').text(prefix.trim() + ' ' + curStepNum);

        if (brand.trim() === '') {
            $('#step4Brand').hide();
        } else {
            $('#step4Brand').text(brand.trim()).show();
        }

        // Theme Styling
        const box = $('#step4BadgeBox');
        const tag = $('#step4Tag');
        const brandEl = $('#step4Brand');

        box.css({ border: 'none', 'border-radius': '12px', padding: '10px 14px' });
        tag.css({ display: 'inline-block' });
        $('#step4HeaderRow').css({ display: 'flex', 'margin-bottom': '6px' });

        if (theme === 'minimal_white') {
            box.css({ background: 'rgba(255, 255, 255, 0.92)', color: '#0F172A', border: '1px solid #CBD5E1', 'box-shadow': '0 10px 25px rgba(0,0,0,0.25)' });
            tag.css({ background: '#0F172A', color: '#FFF' });
            brandEl.css({ color: '#64748B' });
        } else if (theme === 'amber') {
            box.css({ background: 'rgba(26, 16, 8, 0.90)', color: '#FFFBEB', border: '1px solid rgba(245, 158, 11, 0.45)', 'box-shadow': '0 10px 25px rgba(0,0,0,0.45)' });
            tag.css({ background: '#F59E0B', color: '#29170A' });
            brandEl.css({ color: '#FDE68A' });
        } else if (theme === 'neon') {
            box.css({ background: 'rgba(10, 15, 30, 0.90)', color: '#FFF', border: '1.5px solid #06B6D4', 'box-shadow': '0 0 16px rgba(6, 182, 212, 0.35)' });
            tag.css({ background: '#06B6D4', color: '#000' });
            brandEl.css({ color: '#67E8F9' });
        } else if (theme === 'floating_pill') {
            box.css({ background: 'rgba(15, 23, 42, 0.90)', color: '#FFF', border: '1px solid rgba(255,255,255,0.25)', 'border-radius': '24px', padding: '6px 14px', display: 'flex', 'align-items': 'center', gap: '8px', 'box-shadow': '0 8px 20px rgba(0,0,0,0.4)' });
            tag.css({ background: '#10B981', color: '#FFF', 'font-size': '8px', padding: '2px 6px' });
            brandEl.hide();
            $('#step4HeaderRow').css({ 'margin-bottom': '0' });
        } else { // dark_glass (default)
            box.css({ background: 'rgba(15, 23, 42, 0.85)', color: '#FFF', border: '1px solid rgba(255, 255, 255, 0.18)', 'box-shadow': '0 12px 30px rgba(0,0,0,0.55)' });
            tag.css({ background: '#10B981', color: '#FFF' });
            brandEl.css({ color: '#A7F3D0' });
        }

        // Text Content
        let sampleText = $('#txtStep4SampleInstruction').val() || 'Isi panci besar dengan air di atas kompor.';
        if (typeof cleanCookingInstructionCaptionJS === 'function') {
            sampleText = cleanCookingInstructionCaptionJS(sampleText);
        }

        // Run or maintain typewriter animation in step 4
        if (restartTyping || !$('#step4TypewriterText').text()) {
            if (step4TypingTimer) clearInterval(step4TypingTimer);
            $('#step4TypewriterText').text('');
            const chars = Array.from(sampleText);
            let charIdx = 0;
            step4TypingTimer = setInterval(function() {
                if (charIdx < chars.length) {
                    $('#step4TypewriterText').text($('#step4TypewriterText').text() + chars[charIdx]);
                    charIdx++;
                } else {
                    clearInterval(step4TypingTimer);
                }
            }, 40);
        }
    }

    // =========================================================================
    // STEP 4: MINI MAX TTS STUDIO FUNCTIONS
    // =========================================================================
    function populateTtsProjectVoices(lang, selectedVoice) {
        const sel = $('#ttsProjectVoice');
        if (!sel.length) return;
        sel.empty();

        const isEn = (lang === 'en-US' || (lang && lang.startsWith('en')));
        const list = isEn ? minimaxVoicesEn : minimaxVoicesId;
        const defaultVoice = isEn ? 'English_causual_narrator_vv1' : ((minimaxConfig && minimaxConfig.minimax_default_voice) || 'Indonesian_energetic_streamer_vv2');

        let target = selectedVoice;
        if (!target) {
            const projectVoice = (currentVideoProject && currentVideoProject.tts_voice_persona) ? currentVideoProject.tts_voice_persona : '';
            if (isEn && !projectVoice.startsWith('Indonesian_') && projectVoice) {
                target = projectVoice;
            } else if (!isEn && !projectVoice.startsWith('English_') && projectVoice) {
                target = projectVoice;
            } else {
                target = defaultVoice;
            }
        }

        // Pastikan target benar-benar ada di daftar suara aktif
        const exists = list.some(v => v.id === target);
        if (!exists) {
            target = defaultVoice;
        }

        list.forEach(v => {
            const isSel = (v.id === target) ? 'selected' : '';
            sel.append(`<option value="${v.id}" ${isSel} style="background:#1E293B; color:#FFF;">${escapeHtml(v.label)}</option>`);
        });

        sel.val(target);
        if (currentVideoProject) {
            currentVideoProject.tts_voice_persona = target;
        }
    }

    let currentlyPlayingSceneId = null;
    let isPlaylistPlaying = false;
    let playlistQueue = [];
    let playlistIndex = 0;

    function onTtsProjectLanguageChange(lang) {
        if (currentVideoProject) {
            currentVideoProject.tts_language = lang;
        }
        populateTtsProjectVoices(lang);
    }

    function formatAudioTime(secs) {
        if (!secs || isNaN(secs)) return '0:00';
        const m = Math.floor(secs / 60);
        const s = Math.floor(secs % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function handleUpdateCharCounter(sceneId) {
        const val = $('#txtTtsText_' + sceneId).val() || '';
        const words = val.trim() ? val.trim().split(/\s+/).length : 0;
        $('#charCounter_' + sceneId).html(`${val.length} karakter &bull; ~${words} kata`);
    }

    function handleCopyNaskahText(sceneId, type, btnElement) {
        let text = '';
        if (type === 'typing') {
            text = $('#typingText_' + sceneId).text().trim();
        } else if (type === 'recipe') {
            text = $('#recipeOriginalText_' + sceneId).text().trim();
        }
        if (!text) return;

        $('#txtTtsText_' + sceneId).val(text);
        handleUpdateCharCounter(sceneId);
        handleSaveSceneTtsText(sceneId, text);
        const sc = (currentVideoProject.scenes || []).find(s => parseInt(s.id, 10) === parseInt(sceneId, 10));
        if (sc) sc.tts_text = text;
        const $btn = $(btnElement);
        const oldHtml = $btn.html();
        $btn.html('<i class="material-icons" style="font-size:12px; color:#10B981;">check</i> Disalin!');
        setTimeout(() => $btn.html(oldHtml), 1800);
    }

    function handleResetAllNaskahToTyping() {
        if (!currentVideoProject || !currentVideoProject.scenes) return;
        if (!confirm('Apakah Anda yakin ingin menyetel semua naskah narasi ke Teks Typing animasi yang ringkas & sinkron?')) {
            return;
        }
        currentVideoProject.scenes.forEach(sc => {
            const typingText = sc.visual_action_desc || sc.direction_source_text || '';
            $('#txtTtsText_' + sc.id).val(typingText);
            handleUpdateCharCounter(sc.id);
            handleSaveSceneTtsText(sc.id, typingText);
            sc.tts_text = typingText;
        });
        alert('Seluruh naskah narasi berhasil disetel ke Teks Typing!');
    }

    function handleCopyRecipeText(sceneId, recipeText) {
        handleCopyNaskahText(sceneId, 'recipe', '#btnCopy_' + sceneId);
    }

    function seekSceneAudio(sceneId, event) {
        const audio = document.getElementById('audioPlayer_' + sceneId);
        if (!audio || !audio.duration) return;
        const bar = event.currentTarget;
        const rect = bar.getBoundingClientRect();
        const clickX = event.clientX - rect.left;
        const pct = Math.max(0, Math.min(1, clickX / rect.width));
        audio.currentTime = pct * audio.duration;
    }

    function updateMasterReadinessStatus() {
        if (!currentVideoProject || !currentVideoProject.scenes) return;
        const scenes = currentVideoProject.scenes;
        const total = scenes.length;
        const readyScenes = scenes.filter(s => s.tts_status === 'completed' && s.tts_audio_path);
        const readyCount = readyScenes.length;
        const pct = total > 0 ? Math.round((readyCount / total) * 100) : 0;

        $('#ttsMasterReadinessTitle').text(`Status Narasi: ${readyCount} dari ${total} Adegan Siap (${pct}%)`);
        if (readyCount === total && total > 0) {
            $('#ttsMasterReadinessDesc').html('<span style="color:#10B981; font-weight:700;"><i class="material-icons" style="font-size:13px; vertical-align:middle;">check_circle</i> Seluruh adegan telah memiliki suara narasi.</span> Anda dapat memutar semua atau lanjut ke Langkah 5.');
        } else {
            $('#ttsMasterReadinessDesc').text(`${total - readyCount} adegan belum memiliki suara narasi. Silakan generate suara per adegan atau generate sekaligus.`);
        }

        playlistQueue = readyScenes.map(s => parseInt(s.id, 10));
        if (playlistQueue.length === 0) {
            $('#btnTogglePlayAll').prop('disabled', true);
        } else {
            $('#btnTogglePlayAll').prop('disabled', false);
        }
    }

    function handleTogglePlayScene(sceneId) {
        const audio = document.getElementById('audioPlayer_' + sceneId);
        const icon = $('#iconPlayBtn_' + sceneId);
        const card = $('#ttsSceneCard_' + sceneId);

        if (!audio) return;

        if (currentlyPlayingSceneId && currentlyPlayingSceneId !== sceneId) {
            stopSceneAudio(currentlyPlayingSceneId);
        }

        if (audio.paused) {
            audio.play().then(() => {
                currentlyPlayingSceneId = sceneId;
                icon.text('pause');
                card.addClass('active-playing');
                monitorAudioProgress(sceneId);
            }).catch(err => {
                console.log('Play error:', err);
            });
        } else {
            audio.pause();
            icon.text('play_arrow');
            card.removeClass('active-playing');
            currentlyPlayingSceneId = null;
        }
    }

    function stopSceneAudio(sceneId) {
        const audio = document.getElementById('audioPlayer_' + sceneId);
        const icon = $('#iconPlayBtn_' + sceneId);
        const card = $('#ttsSceneCard_' + sceneId);
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
        }
        if (icon.length) icon.text('play_arrow');
        if (card.length) card.removeClass('active-playing');
    }

    function monitorAudioProgress(sceneId) {
        const audio = document.getElementById('audioPlayer_' + sceneId);
        const fill = $('#waveFill_' + sceneId);
        const timer = $('#playerTimer_' + sceneId);

        if (!audio) return;

        audio.ontimeupdate = function() {
            if (audio.duration) {
                const pct = (audio.currentTime / audio.duration) * 100;
                fill.css('width', pct + '%');
                timer.text(formatAudioTime(audio.currentTime) + ' / ' + formatAudioTime(audio.duration));
            }
        };

        audio.onended = function() {
            stopSceneAudio(sceneId);
            currentlyPlayingSceneId = null;
            if (isPlaylistPlaying) {
                playNextInPlaylist();
            }
        };
    }

    function handleTogglePlayAllScenes() {
        if (isPlaylistPlaying) {
            isPlaylistPlaying = false;
            if (currentlyPlayingSceneId) stopSceneAudio(currentlyPlayingSceneId);
            $('#iconPlayAllMaster').text('play_circle_filled');
            $('#txtPlayAllMaster').text('Putar Semua Narasi Berurutan');
        } else {
            if (playlistQueue.length === 0) {
                alert('Belum ada adegan yang memiliki audio suara narasi.');
                return;
            }
            isPlaylistPlaying = true;
            playlistIndex = 0;
            $('#iconPlayAllMaster').text('pause_circle_filled');
            $('#txtPlayAllMaster').text('Jeda Pemutaran Berurutan');
            playNextInPlaylist();
        }
    }

    function playNextInPlaylist() {
        if (!isPlaylistPlaying) return;
        if (playlistIndex < playlistQueue.length) {
            const scId = playlistQueue[playlistIndex];
            playlistIndex++;
            handleTogglePlayScene(scId);
        } else {
            isPlaylistPlaying = false;
            $('#iconPlayAllMaster').text('play_circle_filled');
            $('#txtPlayAllMaster').text('Putar Semua Narasi Berurutan');
        }
    }

    function updateSceneAudioBox(sceneId, audioPath, duration) {
        if (!sceneId) return;
        const dur = parseFloat(duration || 0);
        const visualDur = parseFloat((currentVideoProject && currentVideoProject.clip_duration) || 4.0);
        const remainingPadding = Math.max(0, visualDur - dur).toFixed(1);

        $('#ttsStatusBadge_' + sceneId).html(
            `<span class="label label-success" style="font-size:11px; font-weight:700;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check_circle</i> Audio Siap (${dur.toFixed(1)}s)</span>`
        );

        $('#ttsAudioBoxWrap_' + sceneId).html(`
            <div class="tts-preview-box ready" id="audioPreviewBox_${sceneId}">
                <div style="flex:1; min-width:180px;">
                    <div style="font-size:11px; font-weight:800; color:#166534; display:flex; align-items:center; gap:5px; margin-bottom:2px;">
                        <i class="material-icons" style="font-size:14px;">volume_up</i> HASIL GENERATE TTS
                    </div>
                    <div style="font-size:11.5px; color:#475569;">
                        Durasi Audio: <strong>${dur.toFixed(1)}s</strong> &bull; Sisa <strong>${remainingPadding}s</strong> jeda disinkronkan via BGM.
                    </div>
                </div>

                <div style="display:flex; align-items:center; gap:12px; flex:1.6; min-width:220px;">
                    <button type="button" class="tts-play-btn" id="btnPlayScene_${sceneId}" onclick="handleTogglePlayScene(${sceneId})" title="Putar / Jeda Audio">
                        <i class="material-icons" id="iconPlayBtn_${sceneId}" style="font-size:20px;">play_arrow</i>
                    </button>
                    <div class="tts-waveform-bar" onclick="seekSceneAudio(${sceneId}, event)">
                        <div class="tts-waveform-fill" id="waveFill_${sceneId}"></div>
                    </div>
                    <span id="playerTimer_${sceneId}" style="font-size:11.5px; font-weight:700; color:#166534; min-width:45px; text-align:right;">
                        0:00 / ${formatAudioTime(dur)}
                    </span>
                    <audio id="audioPlayer_${sceneId}" preload="none">
                        <source src="${audioPath}?t=${Date.now()}" type="audio/mpeg">
                    </audio>
                </div>

                <button type="button" class="btn btn-default btn-xs" id="btnGenTts_${sceneId}" onclick="handleGenerateSceneTts(${sceneId}, this)" style="border-radius:6px; font-weight:700; font-size:11px; height:34px; border-color:#CBD5E1; color:#334155;">
                    <i class="material-icons" style="font-size:13px; color:#10B981; vertical-align:middle;">refresh</i> Generate Ulang
                </button>
            </div>
        `);
    }

    function renderStep4TtsDeck() {
        if (!currentVideoProject) return;

        const lang = currentVideoProject.tts_language || minimaxConfig.minimax_default_language || 'id-ID';
        $('#ttsProjectLanguage').val(lang);
        populateTtsProjectVoices(lang, currentVideoProject.tts_voice_persona);

        const speed = parseFloat(currentVideoProject.tts_speed || minimaxConfig.minimax_default_speed || 1.0);
        $('#ttsProjectSpeed').val(speed);
        $('#txtTtsSpeedVal').text(speed.toFixed(2) + 'x');

        const vol = parseFloat(currentVideoProject.tts_volume || minimaxConfig.minimax_default_volume || 1.0);
        $('#ttsProjectVolume').val(vol);
        $('#txtTtsVolVal').text(vol.toFixed(2) + 'x');

        const enableBgm = currentVideoProject.tts_enable_bgm !== 0 && currentVideoProject.tts_enable_bgm !== '0';
        $('#chkEnableBgm').prop('checked', enableBgm);

        const bgmVol = parseFloat(currentVideoProject.tts_bgm_volume || 0.18);
        $('#rngBgmVolume').val(bgmVol);
        $('#txtBgmVolVal').text(Math.round(bgmVol * 100) + '%');

        const container = $('#ttsScenesContainer');
        const scenes = currentVideoProject.scenes || [];
        if (scenes.length === 0) {
            container.html('<div class="alert alert-warning" style="border-radius:8px;">Belum ada adegan pada proyek ini. Silakan buat storyboard di Langkah 2 terlebih dahulu.</div>');
            return;
        }

        // USULAN A: Anti-Empty Protection. Jika card sudah ada di DOM (dari SSR PHP), perbarui in-place tanpa menghapus DOM
        const existingCards = $('#ttsScenesContainer .tts-scene-card');
        if (existingCards.length > 0) {
            scenes.forEach((sc) => {
                const audioPath = sc.tts_audio_path || '';
                const hasAudio = audioPath && sc.tts_status === 'completed';
                const duration = parseFloat(sc.tts_duration || 0);
                if (hasAudio) {
                    updateSceneAudioBox(sc.id, audioPath, duration);
                }
                handleUpdateCharCounter(sc.id);
            });
            updateMasterReadinessStatus();
            return;
        }

        container.empty();

        scenes.forEach((sc, idx) => {
            const sceneNum = sc.scene_order || (idx + 1);
            const typingText = sc.visual_action_desc || '';
            const recipeOriginalText = sc.direction_source_text || '';
            const ttsText = sc.tts_text || typingText || recipeOriginalText;
            const audioPath = sc.tts_audio_path || '';
            const hasAudio = audioPath && sc.tts_status === 'completed';
            const duration = parseFloat(sc.tts_duration || 0);

            // Visual duration: default 4.0s
            const visualDur = parseFloat(currentVideoProject.clip_duration || 4.0);
            const remainingPadding = Math.max(0, visualDur - duration).toFixed(1);

            let thumbHtml = '';
            if (sc.frame_image_path) {
                const thumbUrl = sc.frame_image_path;
                thumbHtml = `<img src="${thumbUrl}" alt="Scene ${sceneNum}" style="width:96px; height:160px; object-fit:cover; border-radius:10px; border:1px solid #CBD5E1; flex-shrink:0; background:#1E293B;">`;
            } else {
                thumbHtml = `<div style="width:96px; height:160px; background:#1E293B; border-radius:10px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94A3B8; font-size:10px; flex-shrink:0;">
                    <i class="material-icons" style="font-size:28px; color:#64748B;">movie</i>
                    <span style="margin-top:4px;">Scene ${sceneNum}</span>
                </div>`;
            }

            let statusBadge = hasAudio 
                ? `<span class="label label-success" style="font-size:11px; font-weight:700;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check_circle</i> Audio Siap (${duration.toFixed(1)}s)</span>`
                : `<span class="label label-default" style="font-size:11px; background:#E2E8F0; color:#475569;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">mic_none</i> Belum Ada Suara</span>`;

            // Words and chars count
            const wordsCount = ttsText.trim() ? ttsText.trim().split(/\s+/).length : 0;
            const charsCount = ttsText.length;

            // Audio Preview Box HTML
            let previewBoxHtml = '';
            if (hasAudio) {
                previewBoxHtml = `
                    <div class="tts-preview-box ready" id="audioPreviewBox_${sc.id}">
                        <div style="flex:1; min-width:180px;">
                            <div style="font-size:11px; font-weight:800; color:#166534; display:flex; align-items:center; gap:5px; margin-bottom:2px;">
                                <i class="material-icons" style="font-size:14px;">volume_up</i> HASIL GENERATE TTS
                            </div>
                            <div style="font-size:11.5px; color:#475569;">
                                Durasi Audio: <strong>${duration.toFixed(1)}s</strong> &bull; Sisa <strong>${remainingPadding}s</strong> jeda disinkronkan via BGM.
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:12px; flex:1.6; min-width:220px;">
                            <button type="button" class="tts-play-btn" id="btnPlayScene_${sc.id}" onclick="handleTogglePlayScene(${sc.id})" title="Putar / Jeda Audio">
                                <i class="material-icons" id="iconPlayBtn_${sc.id}" style="font-size:20px;">play_arrow</i>
                            </button>
                            <div class="tts-waveform-bar" onclick="seekSceneAudio(${sc.id}, event)">
                                <div class="tts-waveform-fill" id="waveFill_${sc.id}"></div>
                            </div>
                            <span id="playerTimer_${sc.id}" style="font-size:11.5px; font-weight:700; color:#166534; min-width:45px; text-align:right;">
                                0:00 / ${formatAudioTime(duration)}
                            </span>
                            <audio id="audioPlayer_${sc.id}" preload="none">
                                <source src="${audioPath}?t=${Date.now()}" type="audio/mpeg">
                            </audio>
                        </div>

                        <button type="button" class="btn btn-default btn-xs" id="btnGenTts_${sc.id}" onclick="handleGenerateSceneTts(${sc.id}, this)" style="border-radius:6px; font-weight:700; font-size:11px; height:34px; border-color:#CBD5E1; color:#334155;">
                            <i class="material-icons" style="font-size:13px; color:#10B981; vertical-align:middle;">refresh</i> Generate Ulang
                        </button>
                    </div>
                `;
            } else {
                previewBoxHtml = `
                    <div class="tts-preview-box empty" id="audioPreviewBox_${sc.id}">
                        <div style="flex:1;">
                            <div style="font-size:11px; font-weight:800; color:#64748B; display:flex; align-items:center; gap:5px; margin-bottom:2px;">
                                <i class="material-icons" style="font-size:14px;">info</i> BELUM ADA AUDIO NARASI
                            </div>
                            <div style="font-size:11.5px; color:#64748B;">
                                Klik tombol di samping untuk men-generate suara narasi adegan ini via MiniMax AI.
                            </div>
                        </div>

                        <button type="button" class="btn btn-primary btn-sm" id="btnGenTts_${sc.id}" onclick="handleGenerateSceneTts(${sc.id}, this)" style="background:#10B981; border-color:#059669; border-radius:8px; font-weight:700; font-size:11.5px; padding:6px 14px;">
                            <i class="material-icons" style="font-size:14px; vertical-align:middle;">mic</i> Generate Suara Klip Ini
                        </button>
                    </div>
                `;
            }

            const card = $(`
                <div class="tts-scene-card" id="ttsSceneCard_${sc.id}">
                    <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
                        ${thumbHtml}
                        <span style="background:#0F172A; color:#FFF; font-size:10px; font-weight:800; padding:3px 8px; border-radius:6px; text-align:center; width:100%;">
                            SCENE ${sceneNum}
                        </span>
                    </div>

                    <div style="flex:1; min-width:0; display:flex; flex-direction:column; gap:12px;">
                        <!-- HEADER BARIS CARD -->
                        <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:8px; border-bottom:1px solid #F1F5F9;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <strong style="font-size:13.5px; color:#0F172A;">Adegan ${sceneNum}: ${escapeHtml(typingText || 'Instruksi Masak')}</strong>
                                <span style="font-size:11px; color:#64748B; background:#F1F5F9; padding:2px 8px; border-radius:6px;">
                                    <i class="material-icons" style="font-size:12px; vertical-align:middle;">videocam</i> Klip: ${visualDur.toFixed(1)}s
                                </span>
                            </div>
                            <div id="ttsStatusBadge_${sc.id}">
                                ${statusBadge}
                            </div>
                        </div>

                        <!-- BLOK 1: ACUAN TEKS (TYPING & RESEP LENGKAP) -->
                        <div class="tts-recipe-box" style="display:flex; flex-direction:column; gap:8px;">
                            <!-- Sub-block A: Teks Typing Animasi (Rekomendasi Utama) -->
                            <div style="background:#FFFFFF; border:1px solid #BBF7D0; border-radius:6px; padding:8px 12px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; flex-wrap:wrap; gap:6px;">
                                    <span style="font-size:11px; font-weight:800; color:#166534; display:flex; align-items:center; gap:5px; text-transform:uppercase;">
                                        <i class="material-icons" style="font-size:15px; color:#10B981;">subtitles</i>
                                        Teks Typing Animasi (Motion Graphics 9:16) &bull; <span style="color:#059669; font-weight:600; text-transform:none;">Rekomendasi Narasi</span>
                                    </span>
                                    <button type="button" class="btn-copy-tts-script" onclick="handleCopyNaskahText(${sc.id}, 'typing', this)" style="color:#166534; border-color:#86EFAC; background:#F0FDF4;">
                                        <i class="material-icons" style="font-size:12px;">content_copy</i> Gunakan Teks Typing
                                    </button>
                                </div>
                                <div style="font-size:12.5px; font-weight:700; color:#0F172A;" id="typingText_${sc.id}">
                                    ${escapeHtml(typingText)}
                                </div>
                            </div>

                            <!-- Sub-block B: Instruksi Resep Asli (Lengkap) -->
                            <div style="padding:2px 4px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px; flex-wrap:wrap; gap:6px;">
                                    <span style="font-size:10.5px; font-weight:700; color:#92400E; display:flex; align-items:center; gap:4px; text-transform:uppercase;">
                                        <i class="material-icons" style="font-size:13px;">menu_book</i>
                                        Instruksi Resep Lengkap
                                    </span>
                                    <button type="button" class="btn-copy-tts-script" onclick="handleCopyNaskahText(${sc.id}, 'recipe', this)">
                                        <i class="material-icons" style="font-size:12px;">content_copy</i> Gunakan Resep Lengkap
                                    </button>
                                </div>
                                <div style="font-size:11.5px; line-height:1.4; color:#78350F;" id="recipeOriginalText_${sc.id}">
                                    ${escapeHtml(recipeOriginalText)}
                                </div>
                            </div>
                        </div>

                        <!-- BLOK 2: TEKS NASKAH NARASI TTS (EDITABLE) -->
                        <div>
                            <div class="tts-script-label-row">
                                <label style="font-size:12px; font-weight:800; color:#0F172A; display:flex; align-items:center; gap:6px; margin:0;">
                                    <i class="material-icons" style="font-size:15px; color:#10B981;">mic</i>
                                    Naskah Narasi AI (MiniMax TTS yang Akan Dibacakan):
                                </label>
                                <span class="tts-char-counter" id="charCounter_${sc.id}">${charsCount} karakter &bull; ~${wordsCount} kata</span>
                            </div>
                            <textarea id="txtTtsText_${sc.id}" class="form-control" rows="2" style="font-size:12.5px; border-radius:8px; line-height:1.5; resize:vertical;" placeholder="Tuliskan naskah yang dibaca narator..." oninput="handleUpdateCharCounter(${sc.id})" onchange="handleSaveSceneTtsText(${sc.id}, this.value)">${escapeHtml(ttsText)}</textarea>
                        </div>

                        <!-- BLOK 3: HASIL AUDIO & PREVIEW -->
                        <div id="ttsAudioBoxWrap_${sc.id}">
                            ${previewBoxHtml}
                        </div>
                    </div>
                </div>
            `);

            container.append(card);
        });

        updateMasterReadinessStatus();
    }

    function handleSaveSceneTtsText(sceneId, text) {
        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_update_scene_tts_text',
                csrf_token: csrfToken,
                scene_id: sceneId,
                tts_text: text
            }
        });
    }

    function handleGenerateSceneTts(sceneId, btn) {
        const text = $('#txtTtsText_' + sceneId).val();
        if (!text || text.trim() === '') {
            alert('Naskah narasi untuk adegan ini tidak boleh kosong.');
            return;
        }

        const lang = $('#ttsProjectLanguage').val();
        const voice = $('#ttsProjectVoice').val();
        const speed = $('#ttsProjectSpeed').val();
        const volume = $('#ttsProjectVolume').val();

        const $btn = $(btn);
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="material-icons" style="font-size:14px; animation:spin 1s infinite linear;">sync</i> Memproses...');

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'video_generate_scene_tts',
                csrf_token: csrfToken,
                scene_id: sceneId,
                text: text,
                language: lang,
                voice_persona: voice,
                speed: speed,
                volume: volume
            },
            success: function(resp) {
                $btn.prop('disabled', false).html(oldHtml);
                if (resp && resp.success) {
                    updateSceneAudioBox(sceneId, resp.audio_url || resp.audio_path, resp.duration);

                    // Update scene in currentVideoProject
                    const sc = (currentVideoProject.scenes || []).find(s => parseInt(s.id, 10) === parseInt(sceneId, 10));
                    if (sc) {
                        sc.tts_audio_path = resp.audio_path || resp.audio_url;
                        sc.tts_duration = resp.duration;
                        sc.tts_status = 'completed';
                        sc.tts_text = text;
                    }
                    updateMasterReadinessStatus();

                    // Auto play preview for immediate listening
                    handleTogglePlayScene(sceneId);
                } else {
                    alert('Gagal membuat suara: ' + (resp.error || 'Terjadi kesalahan pada server.'));
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(oldHtml);
                const errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                alert('Gagal menghubungi server MiniMax: ' + errDetail);
            }
        });
    }

    function handleGenerateAllTts() {
        if (!currentVideoProject) return;

        const lang = $('#ttsProjectLanguage').val();
        const voice = $('#ttsProjectVoice').val();
        const speed = $('#ttsProjectSpeed').val();
        const volume = $('#ttsProjectVolume').val();
        const enableBgm = $('#chkEnableBgm').is(':checked') ? 1 : 0;
        const bgmVolume = $('#rngBgmVolume').val();

        const btn = $('#btnBatchGenerateTts');
        btn.prop('disabled', true).html('<i class="material-icons" style="font-size:16px; animation:spin 1s infinite linear;">sync</i> Sedang Memproses Semua Suara...');

        $('#ttsBatchProgressWrap').fadeIn(200);
        $('#ttsBatchProgressStatus').text('Menghubungi MiniMax AI t2a_v2...');
        $('#ttsBatchProgressBar').css('width', '15%');
        $('#ttsBatchProgressPercent').text('15%');

        $.ajax({
            url: 'video-generator.php',
            type: 'POST',
            dataType: 'json',
            timeout: 300000,
            data: {
                ajax_action: 'video_generate_project_tts_batch',
                csrf_token: csrfToken,
                project_id: currentVideoProject.id,
                language: lang,
                voice_persona: voice,
                speed: speed,
                volume: volume,
                enable_bgm: enableBgm,
                bgm_volume: bgmVolume
            },
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:16px;">campaign</i> Generate Semua Narasi TTS');
                $('#ttsBatchProgressBar').css('width', '100%');
                $('#ttsBatchProgressPercent').text('100%');

                if (resp && resp.success) {
                    $('#ttsBatchProgressStatus').text('Selesai! Seluruh suara narasi berhasil dibuat.');
                    setTimeout(function() {
                        $('#ttsBatchProgressWrap').fadeOut(300);
                    }, 2500);

                    // USULAN B: In-place update langsung dari resp.results tanpa reload round-trip
                    if (resp.results && resp.results.length > 0) {
                        resp.results.forEach(item => {
                            if (item.success && item.scene_id) {
                                const sc = (currentVideoProject.scenes || []).find(s => parseInt(s.id, 10) === parseInt(item.scene_id, 10));
                                if (sc) {
                                    sc.tts_audio_path = item.audio_path || item.audio_url;
                                    sc.tts_duration = item.duration;
                                    sc.tts_status = 'completed';
                                }
                                updateSceneAudioBox(item.scene_id, item.audio_url || item.audio_path, item.duration);
                            }
                        });
                    }
                    updateMasterReadinessStatus();
                } else {
                    $('#ttsBatchProgressStatus').text('Gagal sebagian: ' + (resp.error || 'Terjadi kesalahan'));
                    alert('Gagal: ' + (resp.error || 'Terjadi kesalahan saat memproses suara.'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:16px;">campaign</i> Generate Semua Narasi TTS');
                $('#ttsBatchProgressWrap').hide();
                const errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                alert('Gagal menghubungi server MiniMax: ' + errDetail);
            }
        });
    }
</script>
</body>
</html>
