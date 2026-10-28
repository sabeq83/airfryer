<?php
/**
 * Airfryer Webadmin - AI Recipe Image Generator Studio
 * Formulasi fotografi makanan fotorealistis dengan Gemini 3.8 Flash & G-Labs GPU Lokal Mac Mini.
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
require_once('ai/GeminiClient.php');
require_once('ai/GlabsClient.php');
require_once('ai/AiImageService.php');
require_once('ai/SyncClient.php');

// Ensure all migrations run safely BEFORE services are initialized
if (!Ai\AiBootstrap::isMigrated($connect)) {
    $bootstrapResult = Ai\AiBootstrap::runAllMigrations($connect);
    if (!$bootstrapResult['success']) {
        error_log("[AI Image Generator Bootstrap Notice] " . json_encode($bootstrapResult['messages']));
    }
}

$aiService = new Ai\AiImageService($connect);

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
            case 'test_glabs':
                $host = trim($_POST['glabs_host'] ?? '127.0.0.1');
                $port = (int)($_POST['glabs_port'] ?? 8765);
                $res = $aiService->getGlabsClient()->checkHealth($host, $port);
                echo safe_json_encode($res);
                exit;

            case 'craft_prompts':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $recipeId = (int)($_POST['recipe_id'] ?? 0);
                $count = max(1, min(4, (int)($_POST['count'] ?? 1)));
                $style = trim($_POST['style_preset'] ?? 'Modern Fine Dining');
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
                $style = trim($_POST['style_preset'] ?? 'Modern Fine Dining');
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
                $style = trim($_POST['style_preset'] ?? 'Modern Fine Dining');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '4:3');
                $model = trim($_POST['model'] ?? 'nano_banana_2');

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
                    'message' => "Foto diterapkan ke resep lokal DAN berhasil dipublikasikan ke hosting live (ID: #{$pushRes['live_recipe_id']})!"
                ]);
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

            case 'delete_history':
                $genId = (int)($_POST['generation_id'] ?? 0);
                if ($genId <= 0) {
                    throw new Exception('ID Riwayat tidak valid.');
                }
                $connect->query("DELETE FROM `tbl_ai_generations` WHERE `id` = $genId");
                echo safe_json_encode(['success' => true, 'message' => 'Riwayat berhasil dihapus.']);
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
if ($recipesResult) {
    while ($row = $recipesResult->fetch_assoc()) {
        $allRecipes[] = $row;
    }
}

$primaryModel = !empty($aiConfig['gemini_model']) ? trim($aiConfig['gemini_model']) : 'gemini-2.5-flash';

// Fetch recent history
$recentHistory = $aiService->getHistory(10);

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
            <span style="color:var(--af-dark); font-weight:700;">Image Studio</span>
        </div>

        <!-- PAGE HEADER BANNER (CLEAN - NO REDUNDANT SHORTCUT BUTTONS) -->
        <div class="page-header-banner">
            <div class="banner-left">
                <div class="banner-icon-box">
                    <i class="material-icons" style="font-size:30px;">photo_camera</i>
                </div>
                <div>
                    <h1 class="banner-title">
                        Image Studio
                        <span style="background:var(--af-flame); color:white; font-size:11px; padding:3px 9px; border-radius:12px; font-weight:700;">
                            Akselerasi Mac Mini GPU
                        </span>
                    </h1>
                    <p class="banner-desc">
                        Studio fotografi gourmet makanan otomatis. Analisis bahan masakan dengan <strong><?php echo htmlspecialchars($primaryModel); ?></strong> lalu dirender cepat oleh engine lokal <strong>G-Labs GPU</strong> tanpa biaya per-gambar.
                    </p>
                </div>
            </div>
        </div>

        <!-- STUDIO TAB BAR (3 TABS) -->
        <div class="studio-tab-bar">
            <button class="tab-btn active" id="tab-nav-generator" onclick="switchActiveTab('tab-generator')">
                <i class="material-icons" style="font-size:18px;">restaurant_menu</i>
                1. Generator Foto Resep (Recipe-to-Image)
            </button>
            <button class="tab-btn" id="tab-nav-custom" onclick="switchActiveTab('tab-custom')">
                <i class="material-icons" style="font-size:18px;">palette</i>
                2. Custom T2I Studio (Prompt Bebas)
            </button>
            <button class="tab-btn" id="tab-nav-history" onclick="switchActiveTab('tab-history')">
                <i class="material-icons" style="font-size:18px;">photo_library</i>
                3. Riwayat &amp; Galeri Foto AI
            </button>
        </div>

        <!-- TAB CONTENTS -->
        <div class="tab-content" style="margin-top:0;">

            <!-- ==================================================================== -->
            <!-- TAB 1: RECIPE TO IMAGE WORKFLOW -->
            <!-- ==================================================================== -->
            <div role="tabpanel" class="tab-pane active" id="tab-generator">
                <div class="tab-card">

                    <!-- Stepper Indicator -->
                    <div class="stepper-nav">
                        <div class="stepper-step active" id="stepper-1" onclick="jumpToStep(1)">
                            <div class="stepper-num">1</div>
                            <div>
                                <div class="stepper-label">Pilih Resep &amp; Gaya</div>
                                <div class="stepper-sub">Formula estetika makanan</div>
                            </div>
                        </div>
                        <div class="stepper-divider"></div>
                        <div class="stepper-step" id="stepper-2" onclick="jumpToStep(2)">
                            <div class="stepper-num">2</div>
                            <div>
                                <div class="stepper-label">Review Prompt AI</div>
                                <div class="stepper-sub">Formulasi Prompt Terstruktur</div>
                            </div>
                        </div>
                        <div class="stepper-divider"></div>
                        <div class="stepper-step" id="stepper-3" onclick="jumpToStep(3)">
                            <div class="stepper-num">3</div>
                            <div>
                                <div class="stepper-label">Hasil Render &amp; Terapkan</div>
                                <div class="stepper-sub">Side-by-side comparison</div>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 1: FORM SELEKSI RESEP -->
                    <div id="step-section-1">
                        <!-- 1.1 Pilih Resep -->
                        <div class="form-group" style="margin-bottom:20px;">
                            <label class="form-label">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle; color:var(--af-flame);">search</i>
                                PILIH RESEP DARI DATABASE:
                            </label>
                            <select id="select-recipe" class="form-control" style="height:44px; font-size:13px; font-weight:600; border-radius:8px;" onchange="onRecipeSelected()">
                                <?php if (empty($allRecipes)): ?>
                                    <option value="">Belum ada resep di database</option>
                                <?php else: ?>
                                    <?php 
                                        $targetRecipeId = (int)($_GET['recipe_id'] ?? 0);
                                        foreach ($allRecipes as $idx => $r): 
                                            $isSelected = ($targetRecipeId > 0 && (int)$r['recipe_id'] === $targetRecipeId) || ($targetRecipeId === 0 && $idx === 0);
                                    ?>
                                        <option value="<?php echo $r['recipe_id']; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                            #<?php echo $r['recipe_id']; ?> - <?php echo htmlspecialchars($r['recipe_title']); ?> (<?php echo htmlspecialchars($r['category_name'] ?? 'Resep'); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Selected Recipe Details Card -->
                        <div class="recipe-preview-box" id="recipe-preview-card" style="margin-bottom:25px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px; display:flex; gap:16px; align-items:center;">
                            <img id="recipe-preview-img" src="assets/images/ic_launcher.png" class="recipe-preview-thumb" alt="Recipe Thumb" style="width:72px; height:72px; border-radius:8px; object-fit:cover; border:1px solid #CBD5E1;" />
                            <div class="recipe-preview-info" style="flex:1;">
                                <span class="label" id="recipe-preview-cat" style="background:var(--af-flame); color:white; font-size:10px; border-radius:4px; padding:2px 8px; font-weight:700;">Kategori</span>
                                <h4 id="recipe-preview-title" style="margin:4px 0; font-size:15px; font-weight:700; color:var(--af-dark);">Judul Resep</h4>
                                <p id="recipe-preview-desc" style="font-size:12px; color:#64748B; margin:0;">Deskripsi resep akan dimuat otomatis di sini.</p>
                            </div>
                        </div>

                        <!-- 1.2 Preset Gaya Fotografi -->
                        <div style="margin-bottom:25px;">
                            <label class="form-label">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle; color:var(--af-flame);">photo_filter</i>
                                PILIH PRESET GAYA FOTOGRAFI:
                            </label>
                            <div class="preset-grid">
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? 'Modern Fine Dining') === 'Modern Fine Dining' ? 'active' : ''; ?>" onclick="selectPreset(this, 'Modern Fine Dining')">
                                    <div class="preset-icon"><i class="material-icons">dinner_dining</i></div>
                                    <div class="preset-name">Modern Fine Dining</div>
                                    <div class="preset-desc">Piring keramik slate abu-abu, hiasan microgreens, rim lighting dramatis.</div>
                                </div>
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? '') === 'Rustic Kitchen Table' ? 'active' : ''; ?>" onclick="selectPreset(this, 'Rustic Kitchen Table')">
                                    <div class="preset-icon"><i class="material-icons">table_restaurant</i></div>
                                    <div class="preset-name">Rustic Kitchen Table</div>
                                    <div class="preset-desc">Alas meja kayu oak alami, rempah tersebar rapi, pencahayaan natural hangat.</div>
                                </div>
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? '') === 'Studio Macro Close-Up' ? 'active' : ''; ?>" onclick="selectPreset(this, 'Studio Macro Close-Up')">
                                    <div class="preset-icon"><i class="material-icons">center_focus_strong</i></div>
                                    <div class="preset-name">Studio Macro Close-Up</div>
                                    <div class="preset-desc">Sudut 45 derajat, fokus serat makanan empuk renyah airfryer, bokeh tajam.</div>
                                </div>
                                <div class="preset-card <?php echo ($aiConfig['default_style'] ?? '') === 'Minimalist Flatlay Top-Down' ? 'active' : ''; ?>" onclick="selectPreset(this, 'Minimalist Flatlay Top-Down')">
                                    <div class="preset-icon"><i class="material-icons">layers</i></div>
                                    <div class="preset-name">Minimalist Flatlay Top-Down</div>
                                    <div class="preset-desc">Foto simetris 90 derajat dari atas meja marmer putih, editorial majalah kuliner.</div>
                                </div>
                            </div>
                        </div>

                        <!-- 1.3 Parameter Generator -->
                        <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:20px; margin-bottom:25px;">
                            <h4 style="margin:0 0 16px 0; font-size:14px; font-weight:700; color:var(--af-dark); display:flex; align-items:center; gap:8px;">
                                <i class="material-icons" style="font-size:18px; color:var(--af-flame);">tune</i>
                                PARAMETER GENERATOR &amp; FORMAT GAMBAR
                            </h4>

                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" style="font-size:12px;">Aspek Rasio Gambar:</label>
                                <select class="form-control" id="select-aspect" style="font-size:12.5px; border-radius:8px; height:42px;">
                                    <option value="4:3" selected>4:3 Landscape (Foto Resep Klasik — Default Rekomendasi)</option>
                                    <option value="1:1">1:1 Square (1024x1024 — Standar Feed Aplikasi Android)</option>
                                    <option value="16:9">16:9 Wide (Banner Featured &amp; Web)</option>
                                </select>
                            </div>
                        </div>

                        <!-- 1.4 Action Button -->
                        <button id="btn-start-prompt" type="button" class="btn-action-primary" style="width:100%; justify-content:center; padding:14px; font-size:15px; border-radius:10px;" onclick="generateRecipePrompts()">
                            <i class="material-icons">psychology</i>
                            <span>Formulasikan Prompt T2I dengan AI</span>
                        </button>
                    </div>

                    <!-- STEP 2: PROMPT REVIEW & EDIT -->
                    <div id="step-section-2" style="display:none;">
                        <h3 style="font-size:16px; font-weight:700; color:var(--pp-navy); margin-bottom:16px; display:flex; align-items:center; gap:8px;">
                            <i class="material-icons" style="color:var(--pp-teal);">psychology</i>
                            Langkah 2: Review &amp; Kustomisasi Prompt T2I
                        </h3>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label class="form-label">Positive Prompt Terstruktur:</label>
                            <textarea id="prompt-positive-val" class="form-control" style="height:120px; resize:vertical; font-family:monospace; font-size:12.5px; line-height:1.5;"></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label class="form-label">Negative Prompt (Penyaring Kualitas):</label>
                            <input type="text" id="prompt-negative-val" class="form-control" value="blurry, distorted, oversaturated, raw uncooked chicken, plastic look, watermark, text, signature, low quality, deformed vegetables" style="height:40px; font-size:12px;">
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--pp-slate-border); padding-top:20px;">
                            <button type="button" class="btn-action-secondary" onclick="jumpToStep(1)">
                                <i class="material-icons">arrow_back</i> Kembali
                            </button>
                            <div style="display:flex; gap:10px;">
                                <button type="button" class="btn-action-secondary" onclick="openManualCheckTaskModal()" title="Cek status Task ID yang pernah dikirim">
                                    <i class="material-icons">search</i> Cek Task ID
                                </button>
                                <button type="button" id="btn-start-render" class="btn-action-primary" onclick="submitGlabsTask()">
                                    <i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)
                                </button>
                            </div>
                        </div>

                        <!-- Progress Bar during G-Labs generation -->
                        <div id="glabs-render-progress" style="display:none; margin-top:20px; padding:18px; background:#F8FAFC; border:1px solid #CBD5E1; border-radius:10px; text-align:center;">
                            <div style="display:flex; justify-content:center; align-items:center; gap:8px; margin-bottom:8px;">
                                <i class="material-icons" style="font-size:24px; color:var(--af-flame); animation:spin 1s infinite linear;">autorenew</i>
                                <strong id="glabs-progress-title" style="font-size:14px; color:var(--pp-navy);">Mengirim task ke G-Labs GPU...</strong>
                            </div>
                            <div class="progress" style="height:10px; border-radius:5px; margin-bottom:8px; background:#E2E8F0;">
                                <div id="glabs-progress-bar" class="progress-bar progress-bar-striped active" style="width:25%; background:var(--af-flame);"></div>
                            </div>
                            <div style="font-size:12px; color:var(--pp-slate-muted);">
                                Task ID: <code id="glabs-task-badge" style="font-weight:700; color:var(--pp-navy); background:#E2E8F0; padding:2px 6px; border-radius:4px;">-</code> • Polling status otomatis setiap 2 detik
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: HASIL RENDER & KOMPARASI -->
                    <div id="step-section-3" style="display:none;">
                        <h3 style="font-size:16px; font-weight:700; color:var(--pp-navy); margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                            <i class="material-icons" style="color:var(--pp-success);">check_circle</i>
                            Langkah 3: Hasil Render GPU Selesai — Komparasi Side-by-Side
                        </h3>
                        <p style="font-size:13px; color:var(--pp-slate-muted); margin-bottom:16px;">
                            Bandingkan foto hasil generasi AI dengan foto resep saat ini sebelum diterapkan ke database katalog live.
                        </p>

                        <div class="comparison-grid">
                            <div class="comparison-box">
                                <div class="comparison-header">
                                    <span style="color:#64748B;">FOTO ASLI SAAT INI</span>
                                    <span style="font-size:10px; background:#E2E8F0; padding:2px 6px; border-radius:4px; font-weight:700;">Original</span>
                                </div>
                                <img id="comp-img-original" src="assets/images/ic_launcher.png" class="comparison-img" alt="Foto Asli" style="background:#EEE;">
                                <div class="comparison-footer">
                                    <span id="comp-meta-original" style="font-size:12px; color:#64748B;">Foto dari database</span>
                                    <span style="font-size:11px; color:#64748B;">Status: Aktif</span>
                                </div>
                            </div>

                            <div class="comparison-box" style="border-color:var(--pp-teal);">
                                <div class="comparison-header" style="background:var(--pp-teal-soft); color:var(--pp-teal-dark);">
                                    <span style="font-weight:700;">HASIL GENERASI AI (G-LABS GPU)</span>
                                    <span style="font-size:10px; background:var(--pp-teal); color:white; padding:2px 6px; border-radius:4px; font-weight:700;">HD Quality</span>
                                </div>
                                <img id="comp-img-ai" src="assets/images/ic_launcher.png" class="comparison-img" alt="Foto AI">
                                <div class="comparison-footer" style="background:var(--pp-teal-light);">
                                    <span id="comp-meta-ai" style="font-size:12px; color:var(--pp-teal-dark); font-weight:600;">Waktu Render: ~4.5s • G-Labs</span>
                                    <span style="font-size:11px; background:#D1FAE5; color:#065F46; padding:2px 8px; border-radius:10px; font-weight:700;">Siap Diterapkan</span>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--pp-slate-border); padding-top:20px; margin-top:24px; flex-wrap:wrap; gap:12px;">
                            <button type="button" class="btn-action-secondary" onclick="jumpToStep(2)">
                                <i class="material-icons">refresh</i> Render Ulang Prompt
                            </button>
                            <div style="display:flex; gap:12px;">
                                <button type="button" class="btn-action-secondary" onclick="applyAndPushImage()">
                                    <i class="material-icons" style="color:var(--pp-teal);">cloud_upload</i> Terapkan &amp; Push Live
                                </button>
                                <button type="button" class="btn-action-primary" style="background:var(--pp-success);" onclick="applyImageToRecipeLocal()">
                                    <i class="material-icons">check</i> Terapkan Menjadi Foto Utama
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ==================================================================== -->
            <!-- TAB 2: CUSTOM T2I STUDIO (PROMPT BEBAS) -->
            <!-- ==================================================================== -->
            <div role="tabpanel" class="tab-pane" id="tab-custom" style="display:none;">
                <div class="tab-card">
                    <h3 style="margin:0 0 8px 0; font-size:18px; font-weight:700; color:var(--pp-navy);">
                        <i class="material-icons" style="color:var(--pp-teal); vertical-align:middle;">palette</i>
                        Custom T2I Studio (Prompt Bebas)
                    </h3>
                    <p style="margin:0 0 20px 0; font-size:13px; color:var(--pp-slate-muted);">
                        Eksplorasi prompt fotografi makanan bebas tanpa terikat database resep. Gambar yang dihasilkan otomatis tersimpan di riwayat studio.
                    </p>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label">Deskripsi Prompt Makanan:</label>
                        <textarea class="form-control" id="custom-prompt-text" style="height:100px; resize:vertical; font-size:13px;" placeholder="Contoh: Crispy airfried chicken tenders with homemade dipping sauce, wooden platter, warm rim light..."></textarea>
                    </div>

                    <div class="row clearfix" style="margin-bottom:20px;">
                        <div class="col-sm-6 col-xs-12">
                            <label class="form-label">Aspek Rasio:</label>
                            <select class="form-control" id="custom-aspect" style="border-radius:8px; height:40px;">
                                <option value="4:3" selected>4:3 Classic Landscape (Default Rekomendasi)</option>
                                <option value="1:1">1:1 Square (Standar Mobile Feed)</option>
                                <option value="16:9">16:9 Wide Banner</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-xs-12">
                            <label class="form-label">Model Render G-Labs:</label>
                            <select class="form-control" id="custom-model" style="border-radius:8px; height:40px;">
                                <option value="nano_banana_2" selected>nano_banana_2 (Cepat &amp; Konsisten)</option>
                                <option value="nano_banana_pro">nano_banana_pro (Ultra Detail Photorealism)</option>
                            </select>
                        </div>
                    </div>

                    <button class="btn-action-primary" style="width:100%; justify-content:center; padding:14px; font-size:14px; border-radius:10px;" onclick="renderCustomImage()">
                        <i class="material-icons">auto_awesome</i>
                        <span>Render Gambar Bebas via G-Labs GPU</span>
                    </button>
                </div>
            </div>

            <!-- ==================================================================== -->
            <!-- TAB 3: RIWAYAT & GALERI AI -->
            <!-- ==================================================================== -->
            <div role="tabpanel" class="tab-pane" id="tab-history" style="display:none;">
                <div class="tab-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h3 style="margin:0 0 4px 0; font-size:18px; font-weight:700; color:var(--pp-navy);">
                                <i class="material-icons" style="color:var(--pp-teal); vertical-align:middle;">photo_library</i>
                                Riwayat &amp; Galeri Generasi Foto AI
                            </h3>
                            <p style="margin:0; font-size:13px; color:var(--pp-slate-muted);">Daftar seluruh generasi gambar. Anda dapat mengembalikan foto asli (*1-Click Revert*) kapan saja.</p>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="btn-action-primary" onclick="openManualCheckTaskModal()" style="padding:8px 16px; font-size:12px;">
                                <i class="material-icons" style="font-size:16px;">search</i> Cek Task ID Manual
                            </button>
                            <button class="btn-action-secondary" onclick="loadHistoryTable()" style="padding:8px 16px; font-size:12px;">
                                <i class="material-icons" style="font-size:16px;">refresh</i> Muat Ulang
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover key-pool-table" id="table-history">
                            <thead>
                                <tr>
                                    <th style="width:80px;">Thumbnail</th>
                                    <th>Target Resep</th>
                                    <th>Model &amp; Task ID</th>
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

        </div>

    </div>
</section>

<!-- SCRIPTS -->
<script src="assets/plugins/bootstrap/js/bootstrap.js"></script>
<script src="assets/js/admin.js"></script>
<script>
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const recipesData = <?php echo json_encode($allRecipes, JSON_UNESCAPED_UNICODE); ?>;
    let selectedPreset = <?php echo json_encode($aiConfig['default_style'] ?? 'Modern Fine Dining'); ?>;
    let currentSelectedRecipe = null;
    let currentGlabsTaskId = null;
    let currentGenerationId = null;
    let pollInterval = null;

    $(document).ready(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const targetRecipeId = parseInt(urlParams.get('recipe_id'), 10);
        let initialRecipe = null;
        if (targetRecipeId && recipesData && recipesData.length > 0) {
            initialRecipe = recipesData.find(r => parseInt(r.recipe_id, 10) === targetRecipeId);
            if (initialRecipe) {
                $('#select-recipe').val(targetRecipeId);
            }
        }
        if (!initialRecipe && recipesData && recipesData.length > 0) {
            initialRecipe = recipesData[0];
        }
        if (initialRecipe) {
            currentSelectedRecipe = initialRecipe;
            updateRecipePreview(currentSelectedRecipe);
        }
        loadHistoryTable();
    });

    function switchActiveTab(tabId) {
        $('.tab-btn').removeClass('active');
        $('#tab-nav-' + tabId.replace('tab-', '')).addClass('active');
        $('.tab-pane').hide();
        $('#' + tabId).fadeIn(200);

        if (tabId === 'tab-history') {
            loadHistoryTable();
        }
    }

    function jumpToStep(stepNum) {
        $('#stepper-1, #stepper-2, #stepper-3').removeClass('active done');
        $('#step-section-1, #step-section-2, #step-section-3').hide();

        if (stepNum === 1) {
            $('#stepper-1').addClass('active');
            $('#step-section-1').fadeIn();
        } else if (stepNum === 2) {
            $('#stepper-1').addClass('done');
            $('#stepper-2').addClass('active');
            $('#step-section-2').fadeIn();
        } else if (stepNum === 3) {
            $('#stepper-1, #stepper-2').addClass('done');
            $('#stepper-3').addClass('active');
            $('#step-section-3').fadeIn();
        }
    }

    function onRecipeSelected() {
        const recipeId = parseInt($('#select-recipe').val(), 10);
        const recipe = recipesData.find(r => parseInt(r.recipe_id, 10) === recipeId);
        if (recipe) {
            currentSelectedRecipe = recipe;
            updateRecipePreview(recipe);
        }
    }

    function updateRecipePreview(recipe) {
        $('#recipe-preview-title').text(recipe.recipe_title);
        $('#recipe-preview-cat').text(recipe.category_name || 'Resep Protein');
        
        let desc = recipe.recipe_description ? recipe.recipe_description.replace(/<[^>]*>?/gm, '').trim() : '';
        if (desc.length > 120) desc = desc.substring(0, 120) + '...';
        $('#recipe-preview-desc').text(desc || 'Resep siap diformulasikan ke prompt gourmet.');

        const imgPath = recipe.recipe_image ? 'upload/' + recipe.recipe_image : 'assets/images/ic_launcher.png';
        $('#recipe-preview-img').attr('src', imgPath);
        $('#comp-img-original').attr('src', imgPath);
        $('#comp-meta-original').text('Resep #' + recipe.recipe_id + ' • ' + (recipe.recipe_time || '30 Min'));
    }

    function selectPreset(el, presetName) {
        $('.preset-card').removeClass('active');
        $(el).addClass('active');
        selectedPreset = presetName;
    }

    function generateRecipePrompts() {
        if (!currentSelectedRecipe) {
            alert('Pilih resep terlebih dahulu.');
            return;
        }

        const btn = $('#btn-start-prompt');
        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Memformulasikan prompt AI...');

        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'craft_prompts',
                csrf_token: csrfToken,
                recipe_id: currentSelectedRecipe.recipe_id,
                count: 1,
                style_preset: selectedPreset
            },
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="material-icons">psychology</i> <span>Formulasikan Prompt T2I dengan AI</span>');
                if (resp.success && resp.prompts && resp.prompts.length > 0) {
                    const promptItem = resp.prompts[0];
                    const promptText = (typeof promptItem === 'object' && promptItem !== null)
                        ? (promptItem.prompt || promptItem.text || JSON.stringify(promptItem))
                        : String(promptItem);
                    $('#prompt-positive-val').val(promptText);
                    jumpToStep(2);
                } else {
                    alert('Gagal membuat prompt: ' + (resp.error || 'Terjadi kesalahan sistem.'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons">psychology</i> <span>Formulasikan Prompt T2I dengan AI</span>');
                alert('Koneksi server gagal: ' + error);
            }
        });
    }

    function submitGlabsTask() {
        const prompt = $('#prompt-positive-val').val().trim();
        if (!prompt) {
            alert('Prompt tidak boleh kosong.');
            return;
        }

        const btn = $('#btn-start-render');
        btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Mengirim ke G-Labs GPU...');

        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'submit_glabs_task',
                csrf_token: csrfToken,
                recipe_id: currentSelectedRecipe.recipe_id,
                prompt: prompt,
                style_preset: selectedPreset,
                aspect_ratio: $('#select-aspect').val(),
                model: 'nano_banana_2'
            },
            success: function(resp) {
                if (resp.success && resp.task_id) {
                    currentGlabsTaskId = resp.task_id;
                    currentGenerationId = resp.generation_id;
                    $('#glabs-task-badge').text(resp.task_id);
                    $('#glabs-render-progress').slideDown();
                    $('#glabs-progress-bar').css('width', '35%');
                    $('#glabs-progress-title').text('Task berhasil dikirim ke GPU! Sedang merender...');
                    pollGlabsStatus(resp.task_id, resp.generation_id, btn);
                } else {
                    btn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                    alert('Gagal memulai render G-Labs: ' + (resp.error || 'Respon tidak valid.'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                alert('Koneksi G-Labs gagal: ' + error);
            }
        });
    }

    function pollGlabsStatus(taskId, genId, btn) {
        if (pollInterval) clearInterval(pollInterval);

        let elapsed = 0;
        if (btn) {
            btn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Merender GPU (0s)...');
        }

        pollInterval = setInterval(function() {
            elapsed += 2;
            if (btn) {
                btn.html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Merender GPU (' + elapsed + 's)...');
            }
            $('#glabs-progress-title').text('Merender piksel AI di G-Labs GPU (' + elapsed + 's)...');
            const progressPct = Math.min(90, 35 + (elapsed * 3));
            $('#glabs-progress-bar').css('width', progressPct + '%');

            $.ajax({
                url: 'image-generator.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax_action: 'poll_status',
                    csrf_token: csrfToken,
                    task_id: taskId,
                    generation_id: genId
                },
                success: function(resp) {
                    const statusLower = String(resp.status || '').toLowerCase();
                    if (resp.success && (statusLower === 'completed' || resp.image_url || resp.image_filename)) {
                        clearInterval(pollInterval);
                        if (btn) {
                            btn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                        }
                        $('#glabs-progress-bar').css('width', '100%');
                        $('#glabs-progress-title').text('✓ Render Selesai! Mengalihkan ke hasil...');
                        
                        setTimeout(function() {
                            $('#glabs-render-progress').slideUp();
                            const imgUrl = resp.image_url || ('upload/' + (resp.image_filename || resp.image_file));
                            $('#comp-img-ai').attr('src', imgUrl);
                            $('#comp-meta-ai').text('Waktu: ' + (resp.render_time || elapsed) + 's • Resolusi: 1024x1024 (HD Quality)');
                            currentGenerationId = resp.generation_id || genId;
                            jumpToStep(3);
                        }, 500);
                    } else if (statusLower === 'failed') {
                        clearInterval(pollInterval);
                        if (btn) {
                            btn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                        }
                        $('#glabs-render-progress').hide();
                        alert('Render GPU gagal: ' + (resp.error || 'Unknown error'));
                    }
                },
                error: function() {
                    // Continue polling unless timeout
                    if (elapsed > 180) {
                        clearInterval(pollInterval);
                        if (btn) {
                            btn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                        }
                        $('#glabs-render-progress').hide();
                        alert('Waktu render habis (Timeout). Anda dapat memeriksa Task ID secara manual.');
                    }
                }
            });
        }, 2000);
    }

    function openManualCheckTaskModal() {
        const taskId = prompt('Masukkan Task ID dari G-Labs (contoh: d28357c1):', currentGlabsTaskId || '');
        if (!taskId || !taskId.trim()) return;

        checkAndLoadTask(taskId.trim());
    }

    function checkAndLoadTask(taskId, genId) {
        taskId = taskId.trim();
        const checkBtn = $('#btn-start-render');
        if (checkBtn.length) {
            checkBtn.prop('disabled', true).html('<i class="material-icons" style="animation:spin 1s infinite linear;">autorenew</i> Memeriksa Task...');
        }

        $('#glabs-task-badge').text(taskId);
        $('#glabs-render-progress').slideDown();
        $('#glabs-progress-bar').css('width', '50%');
        $('#glabs-progress-title').text('Memeriksa status Task ID: ' + taskId + '...');

        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'poll_status',
                csrf_token: csrfToken,
                task_id: taskId,
                generation_id: genId || 0
            },
            success: function(resp) {
                if (checkBtn.length) {
                    checkBtn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                }
                const statusLower = String(resp.status || '').toLowerCase();
                if (resp.success && (statusLower === 'completed' || resp.image_url || resp.image_filename)) {
                    $('#glabs-progress-bar').css('width', '100%');
                    $('#glabs-progress-title').text('✓ Task selesai & gambar siap!');

                    setTimeout(function() {
                        $('#glabs-render-progress').slideUp();
                        const imgUrl = resp.image_url || ('upload/' + (resp.image_filename || resp.image_file));
                        $('#comp-img-ai').attr('src', imgUrl);
                        $('#comp-meta-ai').text('Task ID: #' + taskId + ' • Siap Diterapkan');
                        currentGenerationId = resp.generation_id || genId;
                        jumpToStep(3);
                    }, 500);
                } else if (statusLower === 'running' || statusLower === 'pending') {
                    alert('Task #' + taskId + ' masih dalam proses render di GPU (' + statusLower + '). Melanjutkan polling otomatis...');
                    pollGlabsStatus(taskId, genId || resp.generation_id, checkBtn);
                } else {
                    $('#glabs-render-progress').hide();
                    alert('Status Task: ' + (resp.error || statusLower || 'Tidak ditemukan'));
                }
            },
            error: function(xhr, status, error) {
                if (checkBtn.length) {
                    checkBtn.prop('disabled', false).html('<i class="material-icons">bolt</i> Generate Foto (G-Labs GPU)');
                }
                $('#glabs-render-progress').hide();
                alert('Gagal menghubungi server untuk cek task: ' + error);
            }
        });
    }

    function applyImageToRecipeLocal() {
        if (!currentGenerationId || !currentSelectedRecipe) return;

        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'apply_image',
                csrf_token: csrfToken,
                generation_id: currentGenerationId,
                recipe_id: currentSelectedRecipe.recipe_id
            },
            success: function(resp) {
                if (resp.success) {
                    alert('Foto berhasil diterapkan menjadi gambar utama resesp #' + currentSelectedRecipe.recipe_id + '!');
                    location.reload();
                } else {
                    alert('Gagal menerapkan: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    }

    function applyAndPushImage() {
        if (!currentGenerationId || !currentSelectedRecipe) return;

        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'apply_and_push_image',
                csrf_token: csrfToken,
                generation_id: currentGenerationId,
                recipe_id: currentSelectedRecipe.recipe_id
            },
            success: function(resp) {
                if (resp.success) {
                    alert(resp.message || 'Foto berhasil diterapkan dan disinkronkan ke hosting live!');
                    location.reload();
                } else {
                    alert('Gagal: ' + (resp.error || 'Unknown error'));
                }
            }
        });
    }

    function renderCustomImage() {
        const prompt = $('#custom-prompt-text').val().trim();
        if (!prompt) {
            alert('Masukkan deskripsi prompt makanan terlebih dahulu.');
            return;
        }

        const aspect = $('#custom-aspect').val();
        const model = $('#custom-model').val();

        alert('Memulai render custom prompt via G-Labs (Simulasi Pengiriman Task)...');
    }

    function loadHistoryTable() {
        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'get_history',
                csrf_token: csrfToken
            },
            success: function(resp) {
                if (resp.success && resp.history) {
                    renderHistoryTable(resp.history);
                }
            }
        });
    }

    function renderHistoryTable(items) {
        const tbody = $('#history-table-body');
        tbody.empty();

        if (items.length === 0) {
            tbody.append('<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">Belum ada riwayat render.</td></tr>');
            return;
        }

        items.forEach(function(item) {
            const imgFile = item.image_filename || item.image_path || '';
            const thumb = imgFile ? 'upload/' + imgFile : 'assets/images/ic_launcher.png';
            let statusBadge = '';
            const st = (item.status || '').toLowerCase();
            if (st === 'completed' || st === 'applied') {
                statusBadge = item.is_applied == 1 
                    ? '<span style="font-size:11px; background:#D1FAE5; color:#065F46; padding:3px 8px; border-radius:12px; font-weight:700;">Diterapkan</span>'
                    : '<span style="font-size:11px; background:#E2E8F0; color:#475569; padding:3px 8px; border-radius:12px; font-weight:700;">Tersimpan</span>';
            } else if (st === 'running' || st === 'pending') {
                statusBadge = '<span style="font-size:11px; background:#FEF3C7; color:#92400E; padding:3px 8px; border-radius:12px; font-weight:700;">' + (st === 'running' ? 'Merender' : 'Antre') + '</span>';
            } else {
                statusBadge = '<span style="font-size:11px; background:#FEE2E2; color:#B91C1C; padding:3px 8px; border-radius:12px; font-weight:700;">' + (item.status || 'Gagal') + '</span>';
            }

            const checkBtn = (item.task_id) 
                ? `<button type="button" class="btn btn-default btn-xs" onclick="checkAndLoadTask('${item.task_id}', ${item.id})" title="Cek status / unduh task ini" style="border-radius:4px; margin-right:4px;">
                     <i class="material-icons" style="font-size:13px; vertical-align:text-bottom;">sync</i> Cek Task
                   </button>` 
                : '';

            const row = `
                <tr>
                    <td><img src="${thumb}" style="width:48px; height:48px; border-radius:6px; object-fit:cover; border:1px solid #CBD5E1;"></td>
                    <td><strong>#${item.recipe_id}</strong> - ${item.recipe_title || 'Custom Render'}</td>
                    <td>
                        <span style="font-size:12px; color:#64748B;">${item.model_used || 'nano_banana_2'}</span><br>
                        <code style="font-size:10.5px; background:#F1F5F9; color:#0F172A; padding:1px 5px; border-radius:3px;">${item.task_id || '-'}</code>
                    </td>
                    <td><span style="font-size:12px; color:#64748B;">${item.created_at || '-'}</span></td>
                    <td>${statusBadge}</td>
                    <td style="text-align:right;">
                        ${checkBtn}
                        <button type="button" class="btn btn-default btn-xs" onclick="deleteHistory(${item.id})" style="border-radius:4px; color:#DC2626;">
                            <i class="material-icons" style="font-size:14px;">delete</i>
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    }

    function deleteHistory(id) {
        if (!confirm('Hapus riwayat ini?')) return;

        $.ajax({
            url: 'image-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'delete_history',
                csrf_token: csrfToken,
                generation_id: id
            },
            success: function(resp) {
                if (resp.success) {
                    loadHistoryTable();
                } else {
                    alert('Gagal menghapus: ' + (resp.error || ''));
                }
            }
        });
    }
</script>
</body>
</html>
