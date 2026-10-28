<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Polyfills for PHP < 8.0 compatibility on shared hosting
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}

// Safe JSON encoder that handles invalid UTF-8 and never produces blank output
function safe_json_encode($data): string {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        $json = json_encode([
            'success' => false,
            'error' => 'Gagal encode JSON: ' . json_last_error_msg()
        ]);
    }
    return $json;
}

// Catch fatal errors during AJAX calls and return JSON instead of 0-byte blank page
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (isset($_REQUEST['ajax_action'])) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Fatal Error Server (' . $error['message'] . ') di ' . basename($error['file']) . ' baris ' . $error['line']
            ]);
        }
    }
});

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

if (defined('ENABLE_STUDIO_FEATURES') && !ENABLE_STUDIO_FEATURES) {
    if (isset($_REQUEST['ajax_action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo safe_json_encode(['success' => false, 'error' => 'Fitur Impor Resep hanya dapat dijalankan melalui Local Studio Mac Mini.']);
        exit;
    }
    include_once('includes/header.php');
    ?>
    <section class="content">
        <div class="container-fluid">
            <div class="card corner-radius" style="margin-top:20px; padding:40px 20px; text-align:center;">
                <div style="margin-bottom:15px;"><i class="material-icons" style="font-size:64px; color:#6366f1;">cloud_off</i></div>
                <h3 style="color:#1e293b; margin-bottom:10px;">Fitur Local Studio Mac Mini</h3>
                <p style="color:#64748b; font-size:14px; max-width:600px; margin:0 auto 20px auto; line-height:1.6;">
                    Fitur <strong>Impor Resep</strong> dinonaktifkan di server shared web hosting publik.<br>
                    Silakan gunakan webadmin di <strong>Server Mac Mini (Local Studio)</strong> untuk mengimpor resep, lalu gunakan tombol <strong>1-Click Push</strong> untuk menerbitkan resep ke hosting ini.
                </p>
                <a href="recipes.php" class="btn bg-blue waves-effect" style="border-radius:6px; font-weight:600;">Kembali ke Manajemen Resep</a>
            </div>
        </div>
    </section>
    <?php
    include_once('includes/footer.php');
    exit;
}

require_once('importer/UrlValidator.php');
require_once('importer/parsers/ParserInterface.php');
require_once('importer/parsers/BbcFoodParser.php');
require_once('importer/HtmlSanitizer.php');
require_once('importer/ImageDownloader.php');
require_once('importer/DatabaseMigration.php');
require_once('importer/RecipeImporterService.php');
require_once('ai/AiDatabaseMigration.php');
require_once('ai/GeminiClient.php');
require_once('ai/GlabsClient.php');
require_once('ai/AiImageService.php');

$importerService = new Importer\RecipeImporterService($connect);
$migration = new Importer\DatabaseMigration($connect);

// Ensure migration is run
if (!$migration->isMigrated()) {
    $migration->runMigration();
}

// Generate CSRF token if not set
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
            case 'fetch_url':
                $url = trim($_POST['url'] ?? '');
                if (empty($url)) {
                    throw new Exception('URL tidak boleh kosong.');
                }
                $res = $importerService->importUrl($url);
                echo safe_json_encode($res);
                exit;

            case 'save_draft':
                $id = (int)($_POST['import_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('ID draf tidak valid.');
                }
                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: []) : (is_array($rawIng) ? $rawIng : []);
                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: []) : (is_array($rawInst) ? $rawInst : []);

                $saveData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients_json' => $ingredients,
                    'instructions_json' => $instructions,
                    'selected_image_url' => trim($_POST['selected_image_url'] ?? ''),
                    'cat_id' => (int)($_POST['cat_id'] ?? 0),
                ];
                $importerService->saveDraft($id, $saveData);
                echo safe_json_encode(['success' => true, 'message' => 'Draf berhasil disimpan.']);
                exit;

            case 'reject_draft':
                $id = (int)($_POST['import_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? 'Ditolak oleh admin');
                $importerService->rejectDraft($id, $reason);
                echo safe_json_encode(['success' => true, 'message' => 'Hasil resep berhasil ditolak.']);
                exit;

            case 'publish_recipe':
                $id = (int)($_POST['import_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('ID draf tidak valid.');
                }

                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: []) : (is_array($rawIng) ? $rawIng : []);
                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: []) : (is_array($rawInst) ? $rawInst : []);

                $publishData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients_json' => $ingredients,
                    'instructions_json' => $instructions,
                    'selected_image_url' => trim($_POST['selected_image_url'] ?? ''),
                    'cat_id' => (int)($_POST['cat_id'] ?? 0),
                ];

                $filePost = (!empty($_FILES['custom_image']['name']) && $_FILES['custom_image']['error'] === UPLOAD_ERR_OK) ? $_FILES['custom_image'] : null;

                $recipeId = $importerService->publishRecipe($id, $publishData, 'upload', $filePost);
                echo safe_json_encode([
                    'success' => true,
                    'recipe_id' => $recipeId,
                    'message' => 'Resep berhasil dipublikasikan! Recipe ID #' . $recipeId . '.',
                ]);
                exit;

            case 'retry_import':
                $id = (int)($_POST['import_id'] ?? 0);
                $res = $importerService->retryImport($id);
                echo safe_json_encode($res);
                exit;

            case 'test_db':
                $res = $importerService->testDatabaseConnection();
                echo safe_json_encode($res);
                exit;

            case 'test_upload':
                $res = $importerService->testUploadFolder('upload');
                echo safe_json_encode($res);
                exit;

            case 'ai_enhance_draft':
                @set_time_limit(300);
                @ini_set('max_execution_time', '300');
                $aiService = new Ai\AiImageService($connect);

                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: preg_split('/\r\n|\r|\n/', $rawIng)) : (is_array($rawIng) ? $rawIng : []);
                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: preg_split('/\r\n|\r|\n/', $rawInst)) : (is_array($rawInst) ? $rawInst : []);

                $recipeData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients' => $ingredients,
                    'instructions' => $instructions,
                ];

                $options = [
                    'style_preset' => trim($_POST['style_preset'] ?? 'Modern Fine Dining'),
                    'aspect_ratio' => trim($_POST['aspect_ratio'] ?? '4:3'),
                    'prompt_count' => max(1, min(4, (int)($_POST['prompt_count'] ?? 1))),
                ];

                $res = $aiService->enhanceRecipeData($recipeData, $options);
                echo safe_json_encode($res);
                exit;

            case 'submit_glabs_task':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $aiService = new Ai\AiImageService($connect);
                $importId = (int)($_POST['import_id'] ?? 0);
                $prompt = trim($_POST['prompt'] ?? '');
                $style = trim($_POST['style_preset'] ?? 'Modern Fine Dining');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '4:3');
                $model = trim($_POST['model'] ?? '');

                if (empty($prompt)) {
                    throw new Exception('Prompt foto tidak boleh kosong.');
                }
                $res = $aiService->submitImageTask($importId, $prompt, $style, $aspectRatio, $model);
                echo safe_json_encode($res);
                exit;

            case 'poll_glabs_task':
                $aiService = new Ai\AiImageService($connect);
                $taskId = trim($_POST['task_id'] ?? '');
                $genId = (int)($_POST['generation_id'] ?? 0);
                if (empty($taskId)) {
                    throw new Exception('Task ID tidak valid.');
                }
                $res = $aiService->pollAndDownload($taskId, $genId);
                echo safe_json_encode($res);
                exit;

            case 'apply_ai_image_draft':
                $importId = (int)($_POST['import_id'] ?? 0);
                $imageFilename = trim($_POST['image_filename'] ?? '');
                if ($importId <= 0 || empty($imageFilename)) {
                    throw new Exception('Data draf atau nama file gambar tidak valid.');
                }
                // Update tbl_recipe_imports selected_image_url
                $stmt = $connect->prepare("UPDATE tbl_recipe_imports SET selected_image_url = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $imageFilename, $importId);
                    $stmt->execute();
                    $stmt->close();
                }
                echo safe_json_encode([
                    'success' => true,
                    'message' => 'Gambar AI berhasil dipasang ke draf resep.',
                    'selected_image_url' => $imageFilename
                ]);
                exit;

            case 'run_migration':
                $res = $migration->runMigration();
                echo safe_json_encode($res);
                exit;

            default:
                echo safe_json_encode(['success' => false, 'error' => 'Aksi tidak dikenal.']);
                exit;
        }
    } catch (Exception $e) {
        echo safe_json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$tab = $_GET['tab'] ?? 'import';
$importId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$currentDraft = null;

if ($importId > 0) {
    $currentDraft = $importerService->getImportById($importId);
}

// Counts for badges
$queueCount = $importerService->countImports('draft');
$historyCount = $importerService->countImports();
$categories = $importerService->getCategories();

include_once('includes/header.php');
?>

<style>
    /* Styling for Recipe Importer module */
    .importer-nav {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 10px;
    }
    .importer-nav .nav-link-btn {
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        text-decoration: none;
        color: #555;
        background: #f5f5f5;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .importer-nav .nav-link-btn:hover {
        background: #e9ecef;
        color: #1e88e5;
    }
    .importer-nav .nav-link-btn.active {
        background: #1e88e5;
        color: #fff;
    }
    .importer-nav .nav-link-btn .badge {
        background: rgba(0,0,0,0.15);
        color: inherit;
        border-radius: 10px;
        padding: 2px 8px;
        font-size: 11px;
    }
    .importer-nav .nav-link-btn.active .badge {
        background: rgba(255,255,255,0.3);
    }

    .step-circle {
        display: inline-flex;
        width: 26px;
        height: 26px;
        background: #1e88e5;
        color: #fff;
        border-radius: 50%;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 13px;
        margin-right: 8px;
    }
    .card-step-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 10px;
        border-bottom: 1px solid #f0f0f0;
        margin-bottom: 16px;
    }
    .card-step-header h4 {
        margin: 0;
        display: inline-flex;
        align-items: center;
        font-size: 16px;
        font-weight: 600;
    }

    /* Live Preview Sheet */
    .preview-phone-frame {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .preview-header-bar {
        background: #f8fafc;
        padding: 10px 14px;
        border-bottom: 1px solid #edf2f7;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: #64748b;
        text-transform: uppercase;
        display: flex;
        justify-content: space-between;
    }
    .preview-image-wrap {
        width: 100%;
        height: 220px;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    .preview-image-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .preview-image-wrap .no-image-text {
        color: #94a3b8;
        font-size: 13px;
        text-align: center;
        padding: 20px;
    }
    .preview-content {
        padding: 18px;
    }
    .preview-content h3 {
        font-size: 18px;
        font-weight: 700;
        margin-top: 0;
        margin-bottom: 8px;
        color: #1e293b;
        line-height: 1.3;
    }
    .preview-meta-chips {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .preview-chip {
        background: #f1f5f9;
        color: #475569;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    .preview-summary {
        font-size: 13px;
        color: #475569;
        line-height: 1.5;
        margin-bottom: 14px;
        font-style: italic;
    }
    .preview-section-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin-top: 14px;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border-bottom: 1px dashed #e2e8f0;
        padding-bottom: 4px;
    }
    .preview-list {
        margin: 0;
        padding-left: 20px;
        font-size: 12.5px;
        color: #334155;
        line-height: 1.55;
    }
    .preview-source-link {
        display: inline-block;
        margin-top: 16px;
        font-size: 12px;
        color: #1e88e5;
        text-decoration: underline;
    }

    /* Candidate Image Picker */
    .image-candidate-thumb {
        width: 80px;
        height: 60px;
        border-radius: 6px;
        object-fit: cover;
        cursor: pointer;
        border: 2px solid transparent;
        transition: transform 0.15s ease, border-color 0.15s ease;
    }
    .image-candidate-thumb:hover {
        transform: scale(1.05);
    }
    .image-candidate-thumb.selected {
        border-color: #1e88e5;
        box-shadow: 0 0 0 2px rgba(30,136,229,0.3);
    }

    .badge-status {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .badge-status.draft { background: #fff3cd; color: #856404; }
    .badge-status.published { background: #d4edda; color: #155724; }
    .badge-status.rejected { background: #f8d7da; color: #721c24; }
    .badge-status.failed { background: #f8d7da; color: #721c24; }
</style>

<section class="content">
    <ol class="breadcrumb">
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="recipes.php">Manage Recipes</a></li>
        <li class="active">Impor Resep</li>
    </ol>

    <div class="container-fluid">

        <!-- Importer Sub Navigation -->
        <div class="importer-nav">
            <a href="importer.php?tab=import" class="nav-link-btn <?php echo ($tab === 'import') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">cloud_download</i>
                <span>Impor Manual</span>
            </a>
            <a href="importer.php?tab=queue" class="nav-link-btn <?php echo ($tab === 'queue') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">rate_review</i>
                <span>Antrean Tinjauan</span>
                <?php if ($queueCount > 0): ?>
                    <span class="badge"><?php echo $queueCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="importer.php?tab=history" class="nav-link-btn <?php echo ($tab === 'history') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">history</i>
                <span>Riwayat Impor</span>
                <span class="badge"><?php echo $historyCount; ?></span>
            </a>
            <a href="importer.php?tab=settings" class="nav-link-btn <?php echo ($tab === 'settings') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">tune</i>
                <span>Pengaturan &amp; Status</span>
            </a>
        </div>

        <?php if ($tab === 'import'): ?>
            <!-- TAB: IMPOR RESEP (MANUAL) -->
            <div class="row clearfix">

                <!-- Step 1: Input URL -->
                <div class="col-xs-12">
                    <div class="card corner-radius">
                        <div class="body">
                            <div class="card-step-header">
                                <h4><span class="step-circle">1</span> Masukkan URL Resep</h4>
                                <span class="label label-primary">BBC FOOD SUPPORTED</span>
                            </div>
                            <p class="text-muted" style="margin-bottom: 12px;">
                                Masukkan alamat URL halaman resep publik (misalnya dari BBC Food). Sistem akan memvalidasi, mengekstrak data resep, dan menyiapkannya untuk tinjauan.
                            </p>

                            <form id="fetchForm" onsubmit="handleFetchUrl(event);">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <div class="row clearfix" style="margin-bottom: 0;">
                                    <div class="col-md-9 col-sm-8 col-xs-12">
                                        <div class="form-group form-float" style="margin-bottom: 8px;">
                                            <div class="form-line">
                                                <input type="url" id="sourceUrl" name="url" class="form-control"
                                                       placeholder="https://www.bbc.co.uk/food/recipes/..."
                                                       value="<?php echo htmlspecialchars($currentDraft['source_url'] ?? 'https://www.bbc.co.uk/food/recipes/loaded_jacket_potatoes_15248'); ?>" required>
                                            </div>
                                            <small class="help-block">Contoh teruji: <code>https://www.bbc.co.uk/food/recipes/loaded_jacket_potatoes_15248</code></small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-4 col-xs-12">
                                        <button type="submit" id="btnFetch" class="btn btn-primary btn-block waves-effect" style="height: 40px; font-weight:600;">
                                            <i class="material-icons" style="font-size:18px; vertical-align:middle;">arrow_forward</i>
                                            <span>AMBIL DATA</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <div id="fetchAlert" style="display:none; margin-top:12px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Editor & Live Preview Container -->
                <div id="editorWorkspace" class="col-xs-12" style="<?php echo $currentDraft ? '' : 'display:none;'; ?>">
                    <div class="row clearfix">

                        <!-- Step 2: Editor Card -->
                        <div class="col-md-7 col-xs-12">
                            <div class="card corner-radius">
                                <div class="body">
                                    <div class="card-step-header">
                                        <h4><span class="step-circle">2</span> Periksa &amp; Susun</h4>
                                        <span id="draftStatusBadge" class="badge-status draft">DRAF</span>
                                    </div>

                                    <!-- AI Magic Polish & Photo Prompts Component (1-Single-Call) -->
                                    <div class="ai-polish-banner" style="background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); border: 1px solid #c7d2fe; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(99,102,241,0.08);">
                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                            <div>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; font-size:16px;">
                                                        <i class="material-icons" style="font-size:18px;">auto_awesome</i>
                                                    </span>
                                                    <strong style="color:#1e1b4b; font-size:14px;">AI Magic Polish (1-Call Title, Paraphrase &amp; Photo)</strong>
                                                    <span class="badge" style="background:#4338ca; font-size:10px; padding:3px 7px;">Gemini Unified</span>
                                                </div>
                                                <p style="margin:4px 0 0 36px; font-size:12px; color:#475569; line-height:1.4;">
                                                    Sempurnakan judul menjadi <em>click-worthy</em> unik, parafrase instruksi &amp; summary (nutrisi &amp; takaran bahan tetap 100% terjaga), serta buatkan prompt foto T2I fotorealistik.
                                                </p>
                                            </div>
                                            <div>
                                                <button type="button" id="btnToggleAiPanel" class="btn btn-primary waves-effect" onclick="toggleAiPolishPanel();" style="border-radius:6px; font-weight:600; background:linear-gradient(135deg, #4f46e5, #7c3aed); border:none; box-shadow:0 2px 5px rgba(79,70,229,0.3);">
                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">tune</i> <span id="toggleAiText">Buka Studio AI</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Collapsible Polish Controls & Results -->
                                        <div id="aiPolishContainer" style="display:none; margin-top:14px; padding-top:14px; border-top:1px dashed #cbd5e1;">
                                            <div class="row clearfix" style="margin-bottom:10px;">
                                                <div class="col-sm-5 col-xs-12">
                                                    <label style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Preset Gaya Foto</label>
                                                    <select id="aiStylePreset" class="form-control" style="border-radius:6px; border:1px solid #cbd5e1; height:36px; font-size:12px;">
                                                        <option value="Modern Fine Dining" selected>Modern Fine Dining (Default)</option>
                                                        <option value="Rustic Kitchen Table">Rustic Kitchen Table</option>
                                                        <option value="Street Food Close-up">Street Food Close-up</option>
                                                        <option value="Editorial Culinary Magazine">Editorial Culinary Magazine</option>
                                                        <option value="Air Fryer Crisp Focus">Air Fryer Crisp Focus</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-4 col-xs-12">
                                                    <label style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Aspek Rasio</label>
                                                    <select id="aiAspectRatio" class="form-control" style="border-radius:6px; border:1px solid #cbd5e1; height:36px; font-size:12px;">
                                                        <option value="4:3" selected>4:3 (Default Standar)</option>
                                                        <option value="1:1">1:1 (Square)</option>
                                                        <option value="16:9">16:9 (Landscape)</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-3 col-xs-12" style="padding-top:22px;">
                                                    <button type="button" id="btnRunAiPolish" class="btn btn-success btn-block waves-effect" onclick="runAiPolish();" style="border-radius:6px; font-weight:700; height:36px; background:#059669; border:none; box-shadow:0 2px 5px rgba(5,150,105,0.3);">
                                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">bolt</i> <span>MULAI POLISH</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Loading Progress Indicator -->
                                            <div id="aiPolishLoading" style="display:none; text-align:center; padding:15px; background:#fff; border-radius:8px; border:1px solid #e2e8f0; margin-top:10px;">
                                                <div class="preloader pl-size-xs" style="margin-bottom:8px;">
                                                    <div class="spinner-layer pl-indigo"><div class="circle-clipper left"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div></div>
                                                </div>
                                                <div style="font-weight:600; color:#334155; font-size:13px;" id="aiPolishStatusText">Gemini sedang meracik judul unik, memparafrase resep &amp; merancang prompt foto...</div>
                                                <small style="color:#64748b;">1-Single-Call: Menghemat kuota API Gemini dengan waterfall multi-kunci</small>
                                            </div>

                                            <!-- Results Box -->
                                            <div id="aiPolishResults" style="display:none; margin-top:12px; background:#fff; border-radius:8px; border:1px solid #c7d2fe; padding:14px;">
                                                <!-- Title Alternatives -->
                                                <div style="margin-bottom:14px;">
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                        <label style="font-size:12px; font-weight:700; color:#1e1b4b; margin:0;"><i class="material-icons" style="font-size:15px; vertical-align:middle; color:#6366f1;">stars</i> Rekomendasi Judul Click-Worthy:</label>
                                                        <small style="color:#64748b;">(Klik tombol judul di bawah untuk langsung mengganti judul)</small>
                                                    </div>
                                                    <div id="aiTitleList" style="display:flex; flex-direction:column; gap:6px;">
                                                        <!-- Dynamic Title Buttons -->
                                                    </div>
                                                </div>

                                                <!-- Paraphrase Overview -->
                                                <div style="margin-bottom:14px; padding:12px; background:#f8fafc; border-radius:6px; border:1px solid #e2e8f0;">
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                        <span style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase;">Hasil Parafrase Summary (Termasuk Nutrisi) &amp; Instruksi</span>
                                                        <button type="button" id="btnApplyAiText" class="btn btn-info btn-xs waves-effect" onclick="applyAiTextToForm();" style="border-radius:4px; font-weight:600;">
                                                            <i class="material-icons" style="font-size:13px; vertical-align:middle;">assignment_turned_in</i> Terapkan Teks Hasil AI ke Form Resep
                                                        </button>
                                                    </div>
                                                    <div id="aiSummaryPreview" style="font-size:12px; color:#1e293b; line-height:1.5; margin-bottom:8px; white-space:pre-line; background:#fff; padding:8px 10px; border-radius:4px; border:1px solid #cbd5e1;"></div>
                                                    <div id="aiTipsBadge" style="display:none; font-size:11px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:6px 10px; border-radius:4px; margin-bottom:8px;"></div>
                                                    <div id="aiInstructionsCount" style="font-size:11px; color:#64748b;"></div>
                                                </div>

                                                <!-- AI Image Prompts -->
                                                <div>
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                                        <label style="font-size:12px; font-weight:700; color:#1e1b4b; margin:0;"><i class="material-icons" style="font-size:15px; vertical-align:middle; color:#ec4899;">camera_alt</i> Prompt Foto AI T2I (4:3 Modern Fine Dining):</label>
                                                        <div style="display:flex; gap:6px;">
                                                            <button type="button" class="btn btn-default btn-xs waves-effect" onclick="copyAiPrompt();" title="Salin Prompt ke Clipboard">
                                                                <i class="material-icons" style="font-size:13px; vertical-align:middle;">content_copy</i> Salin
                                                            </button>
                                                            <a href="ai-generator.php" target="_blank" class="btn btn-primary btn-xs waves-effect" style="border-radius:4px;">
                                                                <i class="material-icons" style="font-size:13px; vertical-align:middle;">open_in_new</i> Buka AI Studio
                                                            </a>
                                                        </div>
                                                    </div>
                                                    <textarea id="aiPromptPreview" rows="3" class="form-control" style="font-size:11px; font-family:monospace; background:#fff; border:1px solid #cbd5e1; border-radius:4px; line-height:1.4;" readonly></textarea>

                                                    <!-- Direct G-Labs Image Generation Action Container -->
                                                    <div style="margin-top:10px; padding:12px 14px; background:#fff5f7; border:1px solid #fbcfe8; border-radius:6px;">
                                                        <!-- Trigger Bar -->
                                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                                            <div>
                                                                <span style="font-size:12px; font-weight:700; color:#9d174d;">
                                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#db2777;">camera_enhance</i> Generasi Foto Menggunakan AI
                                                                </span>
                                                                <div style="font-size:11px; color:#9f1239; margin-top:2px;">
                                                                    Model: <strong>Nano Banana</strong>
                                                                </div>
                                                            </div>
                                                            <button type="button" id="btnGenerateGlabsDirect" class="btn bg-pink waves-effect" onclick="runDirectGlabsGeneration();" style="border-radius:4px; font-weight:700; padding:7px 14px; box-shadow:0 2px 6px rgba(233,30,99,0.35);">
                                                                <i class="material-icons" style="font-size:15px; vertical-align:middle;">bolt</i> GENERATE GAMBAR SEKARANG
                                                            </button>
                                                        </div>

                                                        <!-- 1. Progress Bar Polling Status (Muncul saat proses render) -->
                                                        <div id="glabsDirectProgressBox" style="display:none; margin-top:12px; padding:12px; background:#fff; border:1px solid #f472b6; border-radius:6px;">
                                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                                <strong id="glabsDirectStatusTitle" style="font-size:12px; color:#1e293b;">Menyiapkan proses render foto...</strong>
                                                                <span id="glabsDirectTaskId" class="badge bg-grey" style="font-family:monospace; font-size:10px;">Task #pending</span>
                                                            </div>
                                                            <div class="progress" style="height:10px; margin-bottom:6px; border-radius:4px; background:#f1f5f9;">
                                                                <div id="glabsDirectProgressBar" class="progress-bar progress-bar-striped active bg-pink" style="width: 20%; transition: width 0.4s ease;"></div>
                                                            </div>
                                                            <div style="display:flex; justify-content:space-between; font-size:11px; color:#64748b;">
                                                                <span id="glabsDirectStatusDesc">Menghubungkan ke sistem AI...</span>
                                                                <span><i class="material-icons" style="font-size:12px; vertical-align:middle;">timer</i> <span id="glabsDirectTimer">0s</span></span>
                                                            </div>
                                                        </div>

                                                        <!-- 2. Showcase Hasil Foto & Aksi Terapkan ke Resep -->
                                                        <div id="glabsDirectResultBox" style="display:none; margin-top:12px; padding:14px; background:#fff; border:2px solid #10b981; border-radius:6px; box-shadow:0 3px 10px rgba(16,185,129,0.12);">
                                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                                                                <span style="font-size:12px; font-weight:700; color:#065f46;">
                                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#10b981;">check_circle</i> FOTO BERHASIL DI-RENDER &amp; DIOPTIMASI (1080px)
                                                                </span>
                                                                <span id="glabsDirectMetaBadge" class="label label-success">1080x810 • Optimized</span>
                                                            </div>
                                                            <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                                                                <!-- Thumbnail Foto (Tanpa Label AI GPU) -->
                                                                <div style="position:relative; width:130px; height:98px; border-radius:6px; overflow:hidden; border:1px solid #cbd5e1; box-shadow:0 2px 4px rgba(0,0,0,0.1); background:#f1f5f9;">
                                                                    <img id="glabsDirectResultImg" src="" style="width:100%; height:100%; object-fit:cover;" alt="AI Generated Recipe">
                                                                </div>
                                                                <!-- Detail & Tombol Aksi -->
                                                                <div style="flex:1; min-width:240px;">
                                                                    <div id="glabsDirectFilenameText" style="font-family:monospace; font-size:11px; font-weight:700; color:#1e293b; margin-bottom:4px;">ai_xxxx.jpg</div>
                                                                    <p style="font-size:11px; color:#64748b; margin-bottom:10px; line-height:1.4;">
                                                                        Foto telah dioptimasi otomatis (1080px, ringan &amp; cepat dimuat di aplikasi) dan siap digunakan sebagai gambar utama resep ini.
                                                                    </p>
                                                                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                                                        <button type="button" id="btnApplyAiImageToRecipe" class="btn btn-success waves-effect" onclick="applyAiImageToRecipeForm();" style="border-radius:4px; font-weight:700;">
                                                                            <i class="material-icons" style="font-size:15px; vertical-align:middle;">star</i> GUNAKAN GAMBAR INI UNTUK RESEP
                                                                        </button>
                                                                        <button type="button" class="btn btn-default btn-xs waves-effect" onclick="viewAiImageFull();" style="border-radius:4px;">
                                                                            <i class="material-icons" style="font-size:13px; vertical-align:middle;">zoom_in</i> Lihat Detail
                                                                        </button>
                                                                        <button type="button" class="btn btn-default btn-xs waves-effect" onclick="runDirectGlabsGeneration();" style="border-radius:4px;">
                                                                            <i class="material-icons" style="font-size:13px; vertical-align:middle;">refresh</i> Render Ulang
                                                                        </button>
                                                                    </div>

                                                                    <!-- Notifikasi Sukses Terapkan -->
                                                                    <div id="glabsDirectAppliedNotice" style="display:none; margin-top:8px; font-size:11px; color:#065f46; background:#ecfdf5; padding:6px 10px; border-radius:4px; border:1px solid #a7f3d0;">
                                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">verified</i> <strong>Foto AI Aktif:</strong> Gambar telah terpasang ke form draf dan tampil di Pratinjau HP!
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <form id="recipeEditForm" onsubmit="handlePublish(event);">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" id="importId" name="import_id" value="<?php echo (int)($currentDraft['id'] ?? 0); ?>">
                                        <input type="hidden" id="selectedImageUrl" name="selected_image_url" value="<?php echo htmlspecialchars($currentDraft['selected_image_url'] ?? ''); ?>">

                                        <!-- Title -->
                                        <div class="form-group">
                                            <label for="recipeTitle">Judul Resep <span class="text-danger">*</span></label>
                                            <div class="form-line">
                                                <input type="text" id="recipeTitle" name="recipe_title" class="form-control"
                                                       value="<?php echo htmlspecialchars($currentDraft['recipe_title'] ?? ''); ?>" required oninput="syncPreview();">
                                            </div>
                                        </div>

                                        <!-- Times & Servings -->
                                        <div class="row clearfix">
                                            <div class="col-sm-4 col-xs-12">
                                                <div class="form-group">
                                                    <label for="prepTime">Waktu Persiapan</label>
                                                    <div class="form-line">
                                                        <input type="text" id="prepTime" name="prep_time" class="form-control"
                                                               value="<?php echo htmlspecialchars($currentDraft['prep_time'] ?? ''); ?>" oninput="syncPreview();">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4 col-xs-12">
                                                <div class="form-group">
                                                    <label for="cookTime">Waktu Memasak</label>
                                                    <div class="form-line">
                                                        <input type="text" id="cookTime" name="cook_time" class="form-control"
                                                               value="<?php echo htmlspecialchars($currentDraft['cook_time'] ?? ''); ?>" oninput="syncPreview();">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4 col-xs-12">
                                                <div class="form-group">
                                                    <label for="servings">Porsi</label>
                                                    <div class="form-line">
                                                        <input type="text" id="servings" name="servings" class="form-control"
                                                               value="<?php echo htmlspecialchars($currentDraft['servings'] ?? ''); ?>" oninput="syncPreview();">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Category -->
                                        <div class="form-group">
                                            <label for="catId">Kategori Webadmin <span class="text-danger">*</span></label>
                                            <select id="catId" name="cat_id" class="form-control show-tick" required onchange="syncPreview();">
                                                <option value="">-- Pilih Kategori --</option>
                                                <?php foreach ($categories as $c): ?>
                                                    <option value="<?php echo $c['cid']; ?>" <?php echo (($currentDraft['cat_id'] ?? 0) == $c['cid']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c['category_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Summary -->
                                        <div class="form-group">
                                            <label for="summary">Ringkasan Resep</label>
                                            <div class="form-line">
                                                <textarea id="summary" name="summary" rows="3" class="form-control" oninput="syncPreview();"><?php echo htmlspecialchars($currentDraft['summary'] ?? ''); ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Ingredients Grouped/List -->
                                        <div class="form-group">
                                            <label for="ingredientsRaw">Daftar Bahan (JSON atau Baris Baru per Bahan) <span class="text-danger">*</span></label>
                                            <div class="form-line">
                                                <textarea id="ingredientsRaw" rows="6" class="form-control" style="font-family:monospace; font-size:12px;" oninput="syncPreview();"><?php
                                                    if (!empty($currentDraft['ingredients_json'])) {
                                                        echo htmlspecialchars($currentDraft['ingredients_json']);
                                                    }
                                                ?></textarea>
                                            </div>
                                            <small class="help-block">Mendukung format JSON terstruktur (kelompok bahan) atau satu bahan per baris.</small>
                                        </div>

                                        <!-- Instructions -->
                                        <div class="form-group">
                                            <label for="instructionsRaw">Langkah-langkah Memasak (JSON atau Satu Langkah per Baris) <span class="text-danger">*</span></label>
                                            <div class="form-line">
                                                <textarea id="instructionsRaw" rows="6" class="form-control" style="font-family:monospace; font-size:12px;" oninput="syncPreview();"><?php
                                                    if (!empty($currentDraft['instructions_json'])) {
                                                        echo htmlspecialchars($currentDraft['instructions_json']);
                                                    }
                                                ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Image Candidates / Custom Upload -->
                                        <div class="form-group">
                                            <label>Kandidat Gambar Sumber</label>
                                            <div id="imageCandidatesList" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                                                <!-- Dynamic candidate thumbs -->
                                            </div>
                                            <div style="margin-top:6px;">
                                                <label for="customImage" style="font-weight:normal; font-size:12px;">Atau unggah gambar alternatif milik sendiri:</label>
                                                <input type="file" id="customImage" name="custom_image" accept="image/*" class="form-control" onchange="handleCustomImage(event);">
                                            </div>
                                        </div>


                                        <!-- Action Buttons -->
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:10px;">
                                            <button type="button" class="btn btn-default waves-effect" onclick="handleReject();">
                                                <i class="material-icons" style="font-size:16px; vertical-align:middle;">close</i> Tolak Hasil
                                            </button>
                                            <div style="display:flex; gap:10px;">
                                                <button type="button" class="btn btn-warning waves-effect" onclick="handleSaveDraft();">
                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">save</i> Simpan Draf
                                                </button>
                                                <button type="submit" id="btnPublish" class="btn btn-success waves-effect" style="font-weight:700;">
                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">publish</i> PUBLIKASIKAN RESEP
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Live Preview Column -->
                        <div class="col-md-5 col-xs-12">
                            <div class="card corner-radius" style="position: sticky; top: 80px;">
                                <div class="body" style="padding: 16px;">
                                    <div class="card-step-header">
                                        <h4><span class="step-circle">3</span> Pratinjau Aplikasi</h4>
                                        <span class="label label-success">POST TYPE</span>
                                    </div>

                                    <div class="preview-phone-frame">
                                        <div class="preview-header-bar">
                                            <span id="previewCategory">Main Dish</span>
                                            <span>Pratinjau Mobile</span>
                                        </div>
                                        <div class="preview-image-wrap" id="previewImageWrap">
                                            <img id="previewImg" src="" style="display:none;" alt="Preview Resep">
                                            <div id="previewNoImg" class="no-image-text">
                                                <i class="material-icons" style="font-size:48px; display:block; margin-bottom:4px;">image</i>
                                                <span>Gambar belum dipilih</span>
                                            </div>
                                        </div>
                                        <div class="preview-content">
                                            <h3 id="previewTitle">Judul Resep</h3>
                                            <div class="preview-meta-chips">
                                                <span class="preview-chip" id="previewPrep">Prep: —</span>
                                                <span class="preview-chip" id="previewCook">Cook: —</span>
                                                <span class="preview-chip" id="previewServings">Porsi: —</span>
                                            </div>
                                            <div class="preview-summary" id="previewSummary">Ringkasan resep akan muncul di sini...</div>

                                            <div class="preview-section-title">Susunan Bahan</div>
                                            <div id="previewIngredientsContainer">
                                                <ul class="preview-list">
                                                    <li>Bahan belum diisi</li>
                                                </ul>
                                            </div>

                                            <div class="preview-section-title">Susunan Langkah</div>
                                            <div id="previewInstructionsContainer">
                                                <ol class="preview-list">
                                                    <li>Langkah belum diisi</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        <?php elseif ($tab === 'queue'): ?>
            <!-- TAB: ANTREAN TINJAUAN -->
            <?php
            $drafts = $importerService->getImports('draft', 100);
            ?>
            <div class="row clearfix">
                <div class="col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>ANTREAN TINJAUAN RESEP (DRAF)</h2>
                            <small>Daftar resep hasil ekstraksi yang tersimpan sebagai draf dan menunggu tinjauan admin sebelum dipublikasikan.</small>
                        </div>
                        <div class="body table-responsive">
                            <?php if (empty($drafts)): ?>
                                <div class="alert alert-info">
                                    <i class="material-icons" style="vertical-align:middle;">info</i>
                                    Tidak ada resep dalam antrean tinjauan saat ini. <a href="importer.php?tab=import" class="alert-link">Impor resep baru &rarr;</a>
                                </div>
                            <?php else: ?>
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Judul Resep</th>
                                            <th>Sumber Domain</th>
                                            <th>Waktu Ekstraksi</th>
                                            <th>Status</th>
                                            <th style="text-align:right;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($drafts as $idx => $d): ?>
                                            <tr>
                                                <td><?php echo $idx + 1; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($d['recipe_title'] ?: 'Tanpa Judul'); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><a href="<?php echo htmlspecialchars($d['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(substr($d['source_url'], 0, 70)); ?>...</a></small>
                                                </td>
                                                <td><span class="label label-info"><?php echo htmlspecialchars($d['source_domain']); ?></span></td>
                                                <td><?php echo date('d M Y H:i', strtotime($d['created_at'])); ?></td>
                                                <td><span class="badge-status draft">DRAF</span></td>
                                                <td style="text-align:right;">
                                                    <a href="importer.php?tab=import&id=<?php echo $d['id']; ?>" class="btn btn-sm btn-primary waves-effect">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">edit</i> Tinjau &amp; Edit
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-default waves-effect" onclick="quickReject(<?php echo $d['id']; ?>)">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">close</i> Tolak
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'history'): ?>
            <!-- TAB: RIWAYAT IMPOR -->
            <?php
            $history = $importerService->getImports(null, 100);
            ?>
            <div class="row clearfix">
                <div class="col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>RIWAYAT IMPOR RESEP</h2>
                            <small>Catatan semua proses impor resep: status, waktu proses, alasan kegagalan, dan tautan ke resep terbit.</small>
                        </div>
                        <div class="body table-responsive">
                            <?php if (empty($history)): ?>
                                <div class="alert alert-info">Belum ada riwayat proses impor.</div>
                            <?php else: ?>
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Resep / URL Sumber</th>
                                            <th>Domain</th>
                                            <th>Status</th>
                                            <th>Waktu Proses</th>
                                            <th>Catatan / Alasan</th>
                                            <th>Tanggal</th>
                                            <th style="text-align:right;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($history as $h): ?>
                                            <tr>
                                                <td>#<?php echo $h['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($h['recipe_title'] ?: '—'); ?></strong>
                                                    <br>
                                                    <small><a href="<?php echo htmlspecialchars($h['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(substr($h['source_url'], 0, 60)); ?>...</a></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($h['source_domain']); ?></td>
                                                <td>
                                                    <span class="badge-status <?php echo htmlspecialchars($h['status']); ?>">
                                                        <?php echo htmlspecialchars(strtoupper($h['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $h['processing_time_ms'] > 0 ? $h['processing_time_ms'] . ' ms' : '—'; ?></td>
                                                <td>
                                                    <?php if (!empty($h['failure_reason'])): ?>
                                                        <span class="text-danger" style="font-size:12px;"><?php echo htmlspecialchars($h['failure_reason']); ?></span>
                                                    <?php elseif ($h['status'] === 'published'): ?>
                                                        <span class="text-success" style="font-size:12px;">Terbit (Recipe ID #<?php echo $h['recipe_id']; ?>)</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                                                <td style="text-align:right;">
                                                    <?php if ($h['status'] === 'published'): ?>
                                                        <a href="recipes-detail.php?id=<?php echo $h['recipe_id']; ?>" class="btn btn-xs btn-success waves-effect" title="Lihat detail resep">
                                                            <i class="material-icons" style="font-size:14px;">visibility</i>
                                                        </a>
                                                        <a href="recipes-edit.php?id=<?php echo $h['recipe_id']; ?>" class="btn btn-xs btn-primary waves-effect" title="Edit resep">
                                                            <i class="material-icons" style="font-size:14px;">edit</i>
                                                        </a>
                                                    <?php elseif ($h['status'] === 'draft'): ?>
                                                        <a href="importer.php?tab=import&id=<?php echo $h['id']; ?>" class="btn btn-xs btn-warning waves-effect" title="Lanjutkan draf">
                                                            <i class="material-icons" style="font-size:14px;">edit</i> Lanjutkan
                                                        </a>
                                                    <?php elseif ($h['status'] === 'failed' || $h['status'] === 'rejected'): ?>
                                                        <button type="button" class="btn btn-xs btn-default waves-effect" onclick="quickRetry(<?php echo $h['id']; ?>)" title="Coba ulang proses impor">
                                                            <i class="material-icons" style="font-size:14px;">refresh</i> Coba Ulang
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'settings'): ?>
            <!-- TAB: PENGATURAN & STATUS -->
            <div class="row clearfix">
                <div class="col-md-6 col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>STATUS KONEKSI DATABASE</h2>
                            <small>Pemeriksaan server database dan ketersediaan tabel resep webadmin.</small>
                        </div>
                        <div class="body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width:35%;">Host Server</th>
                                    <td><code><?php echo htmlspecialchars($host ?? 'localhost'); ?></code></td>
                                </tr>
                                <tr>
                                    <th>Nama Database</th>
                                    <td><code><?php echo htmlspecialchars($database ?? 'your_recipes_app_db'); ?></code></td>
                                </tr>
                                <tr>
                                    <th>User Database</th>
                                    <td><code><?php echo htmlspecialchars($user ?? 'root'); ?></code></td>
                                </tr>
                                <tr>
                                    <th>Password</th>
                                    <td><em>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; (Tersimpan aman di config.php server)</em></td>
                                </tr>
                                <tr>
                                    <th>Tabel Importer</th>
                                    <td>
                                        <?php if ($migration->isMigrated()): ?>
                                            <span class="label label-success">TERSEDIA (tbl_recipe_imports)</span>
                                        <?php else: ?>
                                            <span class="label label-danger">BELUM DIBUAT</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top: 15px;">
                                <button type="button" id="btnTestDb" class="btn btn-primary waves-effect" onclick="handleTestDb();">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">speed</i>
                                    TES KONEKSI DATABASE
                                </button>
                                <?php if (!$migration->isMigrated()): ?>
                                    <button type="button" class="btn btn-warning waves-effect" onclick="handleRunMigration();">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">build</i>
                                        JALANKAN MIGRASI TABEL
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="dbTestResult" style="margin-top:15px; display:none;"></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>STATUS FOLDER PENYIMPANAN GAMBAR</h2>
                            <small>Pemeriksaan akses direktori <code>upload/</code> untuk gambar resep.</small>
                        </div>
                        <div class="body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width:35%;">Jalur Folder</th>
                                    <td><code>upload/</code> (<?php echo htmlspecialchars(realpath('upload') ?: 'upload'); ?>)</td>
                                </tr>
                                <tr>
                                    <th>Status Direktori</th>
                                    <td>
                                        <?php if (is_dir('upload')): ?>
                                            <span class="label label-success">DIREKTORI TERSEDIA</span>
                                        <?php else: ?>
                                            <span class="label label-danger">TIDAK DITEMUKAN</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Izin Tulis (Writable)</th>
                                    <td>
                                        <?php if (is_writable('upload')): ?>
                                            <span class="label label-success">DAPAT DITULISI (WRITABLE)</span>
                                        <?php else: ?>
                                            <span class="label label-danger">READ-ONLY (TIDAK BISA TULIS)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Integrasi Android</th>
                                    <td><span class="text-muted">Aplikasi Android membaca gambar dari URL API + <code>/upload/{recipe_image}</code></span></td>
                                </tr>
                            </table>

                            <div style="margin-top: 15px;">
                                <button type="button" id="btnTestUpload" class="btn btn-info waves-effect" onclick="handleTestUpload();">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">folder_special</i>
                                    TES FOLDER GAMBAR
                                </button>
                            </div>
                            <div id="uploadTestResult" style="margin-top:15px; display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>

<!-- Importer Interactive Script -->
<script type="text/javascript">
    const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
    let currentImageCandidates = [];
    let currentSelectedImageUrl = <?php echo json_encode($currentDraft['selected_image_url'] ?? ''); ?>;

    <?php if ($currentDraft): ?>
        // Pre-populate candidate thumbs if draft exists
        try {
            const rawCandidates = <?php echo json_encode($currentDraft['image_candidates_json'] ?? '[]'); ?>;
            currentImageCandidates = typeof rawCandidates === 'string' ? JSON.parse(rawCandidates) : (rawCandidates || []);
        } catch (e) {
            currentImageCandidates = [];
        }
    <?php endif; ?>

    document.addEventListener('DOMContentLoaded', function() {
        if (currentImageCandidates && currentImageCandidates.length > 0) {
            renderImageCandidates(currentImageCandidates, currentSelectedImageUrl);
        }
        syncPreview();
    });

    // Helper: Show bootstrap alert in element
    function showAlert(elementId, type, message) {
        const el = document.getElementById(elementId);
        if (!el) return;
        el.className = 'alert alert-' + type + ' alert-dismissible';
        el.innerHTML = '<button type="button" class="close" data-dismiss="alert">&times;</button>' + message;
        el.style.display = 'block';
    }

    // Safe fetch helper that reads text first and handles 403/500/empty responses gracefully
    async function safeFetchJson(url, options) {
        const res = await fetch(url, options);
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            if (!res.ok) {
                let hint = '';
                if (res.status === 403 || res.status === 406) {
                    hint = ' (Kemungkinan permintaan diblokir oleh ModSecurity firewall cPanel).';
                } else if (res.status === 500) {
                    hint = ' (Terjadi PHP Fatal Error di hosting. Cek menu Errors di cPanel).';
                }
                throw new Error('HTTP ' + res.status + ' ' + res.statusText + hint + (text ? ' Respons: ' + text.substring(0, 200) : ' (Respons server kosong / 0 bytes).'));
            }
            throw new Error('Server mengembalikan respons bukan JSON: ' + (text ? text.substring(0, 200) : '(respons kosong / 0 bytes). Cek error_log hosting atau nonaktifkan ModSecurity.'));
        }
        return data;
    }

    // Step 1: Fetch URL
    function handleFetchUrl(e) {
        e.preventDefault();
        const urlInput = document.getElementById('sourceUrl');
        const url = urlInput.value.trim();
        if (!url) return;

        const btn = document.getElementById('btnFetch');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:18px; vertical-align:middle;">hourglass_empty</i> Memproses...';
        showAlert('fetchAlert', 'info', 'Menghubungi server sumber dan mengekstrak data resep...');

        const formData = new FormData();
        formData.append('ajax_action', 'fetch_url');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('url', url);

        safeFetchJson('importer.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = originalText;

            if (res.already_published) {
                showAlert('fetchAlert', 'warning', res.message + ' <a href="recipes-detail.php?id=' + res.recipe_id + '" class="alert-link" target="_blank">Lihat Resep Terbit &rarr;</a>');
                return;
            }

            if (!res.success) {
                showAlert('fetchAlert', 'danger', '<strong>Gagal mengambil data:</strong> ' + (res.error || res.message || 'Terjadi kesalahan.'));
                return;
            }

            showAlert('fetchAlert', 'success', '<strong>Berhasil!</strong> ' + res.message);

            // Populate form with extracted data
            const d = res.data;
            document.getElementById('importId').value = d.id;
            document.getElementById('recipeTitle').value = d.recipe_title || '';
            document.getElementById('prepTime').value = d.prep_time || '';
            document.getElementById('cookTime').value = d.cook_time || '';
            document.getElementById('servings').value = d.servings || '';
            document.getElementById('summary').value = d.summary || '';
            document.getElementById('catId').value = d.cat_id || '';
            $('.show-tick').selectpicker('refresh');

            document.getElementById('ingredientsRaw').value = d.ingredients_json || '';
            document.getElementById('instructionsRaw').value = d.instructions_json || '';
            document.getElementById('selectedImageUrl').value = d.selected_image_url || '';
            currentSelectedImageUrl = d.selected_image_url || '';

            // Render candidate images
            let candidates = [];
            try {
                candidates = typeof d.image_candidates_json === 'string' ? JSON.parse(d.image_candidates_json) : (d.image_candidates_json || []);
            } catch(e) {}
            currentImageCandidates = candidates;
            renderImageCandidates(candidates, currentSelectedImageUrl);

            // Open workspace & sync preview
            document.getElementById('editorWorkspace').style.display = 'block';
            syncPreview();

            // Scroll smoothly to editor
            document.getElementById('editorWorkspace').scrollIntoView({ behavior: 'smooth' });
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            showAlert('fetchAlert', 'danger', 'Terjadi kesalahan jaringan atau server: ' + err.message);
        });
    }

    // Render image candidate thumbnails
    function renderImageCandidates(candidates, selected) {
        const wrap = document.getElementById('imageCandidatesList');
        if (!wrap) return;
        wrap.innerHTML = '';

        if (!candidates || candidates.length === 0) {
            wrap.innerHTML = '<span class="text-muted" style="font-size:12px;">Tidak ada kandidat gambar dari sumber. Anda dapat mengunggah gambar sendiri di bawah.</span>';
            return;
        }

        candidates.forEach((imgUrl, i) => {
            const img = document.createElement('img');
            img.src = imgUrl;
            img.className = 'image-candidate-thumb' + (imgUrl === selected ? ' selected' : '');
            img.title = 'Pilih gambar #' + (i + 1);
            img.onclick = function() {
                document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
                img.classList.add('selected');
                document.getElementById('selectedImageUrl').value = imgUrl;
                currentSelectedImageUrl = imgUrl;
                syncPreview();
            };
            wrap.appendChild(img);
        });
    }

    // Handle custom image file selection
    function handleCustomImage(e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(evt) {
            const previewImg = document.getElementById('previewImg');
            const previewNoImg = document.getElementById('previewNoImg');
            previewImg.src = evt.target.result;
            previewImg.style.display = 'block';
            previewNoImg.style.display = 'none';

            // Deselect candidate thumbnails
            document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
            document.getElementById('selectedImageUrl').value = '';
            currentSelectedImageUrl = '';
        };
        reader.readAsDataURL(file);
    }

    // Synchronize Live Preview
    function syncPreview() {
        const title = document.getElementById('recipeTitle')?.value.trim() || 'Judul Resep';
        const prep = document.getElementById('prepTime')?.value.trim();
        const cook = document.getElementById('cookTime')?.value.trim();
        const servings = document.getElementById('servings')?.value.trim();
        const summary = document.getElementById('summary')?.value.trim();
        const catSelect = document.getElementById('catId');
        const catText = catSelect && catSelect.selectedIndex >= 0 && catSelect.options[catSelect.selectedIndex].text !== '-- Pilih Kategori --'
            ? catSelect.options[catSelect.selectedIndex].text : 'Main Dish';


        // Title and Chips
        document.getElementById('previewTitle').textContent = title;
        document.getElementById('previewPrep').textContent = 'Prep: ' + (prep || '—');
        document.getElementById('previewCook').textContent = 'Cook: ' + (cook || '—');
        document.getElementById('previewServings').textContent = 'Porsi: ' + (servings || '—');
        document.getElementById('previewCategory').textContent = catText;
        document.getElementById('previewSummary').textContent = summary || 'Ringkasan belum diisi...';

        // Image Preview
        const previewImg = document.getElementById('previewImg');
        const previewNoImg = document.getElementById('previewNoImg');
        const customFileInput = document.getElementById('customImage');
        if (customFileInput && customFileInput.files.length > 0) {
            // keep custom image
        } else if (currentSelectedImageUrl) {
            previewImg.src = currentSelectedImageUrl;
            previewImg.style.display = 'block';
            previewNoImg.style.display = 'none';
        } else {
            previewImg.style.display = 'none';
            previewNoImg.style.display = 'block';
        }

        // Ingredients
        const ingRaw = document.getElementById('ingredientsRaw')?.value.trim() || '';
        const ingContainer = document.getElementById('previewIngredientsContainer');
        if (ingContainer) {
            let parsedIng = [];
            try {
                if (ingRaw.startsWith('[') || ingRaw.startsWith('{')) {
                    parsedIng = JSON.parse(ingRaw);
                }
            } catch(e) {}

            if (Array.isArray(parsedIng) && parsedIng.length > 0 && typeof parsedIng[0] === 'object' && parsedIng[0].items) {
                let html = '';
                parsedIng.forEach(g => {
                    const gName = g.group && g.group.toLowerCase() !== 'ingredients' ? g.group : '';
                    if (gName) {
                        html += '<div style="font-weight:600; font-size:12px; margin-top:6px; color:#475569;">' + escapeHtml(gName) + ':</div>';
                    }
                    html += '<ul class="preview-list">';
                    (g.items || []).forEach(item => {
                        html += '<li>' + escapeHtml(item) + '</li>';
                    });
                    html += '</ul>';
                });
                ingContainer.innerHTML = html;
            } else {
                const lines = ingRaw.split('\n').map(s => s.trim()).filter(s => s.length > 0);
                if (lines.length > 0) {
                    let html = '<ul class="preview-list">';
                    lines.forEach(l => {
                        html += '<li>' + escapeHtml(l.replace(/^[*\-•\d.]+\s*/, '')) + '</li>';
                    });
                    html += '</ul>';
                    ingContainer.innerHTML = html;
                } else {
                    ingContainer.innerHTML = '<ul class="preview-list"><li>Bahan belum diisi</li></ul>';
                }
            }
        }

        // Instructions
        const instRaw = document.getElementById('instructionsRaw')?.value.trim() || '';
        const instContainer = document.getElementById('previewInstructionsContainer');
        if (instContainer) {
            let parsedInst = [];
            try {
                if (instRaw.startsWith('[')) {
                    parsedInst = JSON.parse(instRaw);
                }
            } catch(e) {}

            const steps = (Array.isArray(parsedInst) && parsedInst.length > 0)
                ? parsedInst
                : instRaw.split('\n').map(s => s.trim()).filter(s => s.length > 0);

            if (steps.length > 0) {
                let html = '<ol class="preview-list">';
                steps.forEach(st => {
                    const cleanStep = typeof st === 'string' ? st : (st.text || JSON.stringify(st));
                    html += '<li>' + escapeHtml(cleanStep.replace(/^[\d.)\-]+\s*/, '')) + '</li>';
                });
                html += '</ol>';
                instContainer.innerHTML = html;
            } else {
                instContainer.innerHTML = '<ol class="preview-list"><li>Langkah belum diisi</li></ol>';
            }
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Parse ingredients from textarea safely to array structure
    function getParsedIngredients() {
        const raw = document.getElementById('ingredientsRaw').value.trim();
        try {
            if (raw.startsWith('[') || raw.startsWith('{')) {
                return JSON.parse(raw);
            }
        } catch(e) {}

        const lines = raw.split('\n').map(s => s.trim()).filter(s => s.length > 0);
        return [{ group: 'Ingredients', items: lines }];
    }

    // Parse instructions from textarea safely to string array
    function getParsedInstructions() {
        const raw = document.getElementById('instructionsRaw').value.trim();
        try {
            if (raw.startsWith('[')) {
                return JSON.parse(raw);
            }
        } catch(e) {}

        return raw.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    }

    // Save Draft
    function handleSaveDraft() {
        const importId = document.getElementById('importId').value;
        if (!importId || importId === '0') {
            alert('Belum ada draf yang dimuat.');
            return;
        }

        const formData = new FormData();
        formData.append('ajax_action', 'save_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('recipe_title', document.getElementById('recipeTitle').value);
        formData.append('prep_time', document.getElementById('prepTime').value);
        formData.append('cook_time', document.getElementById('cookTime').value);
        formData.append('servings', document.getElementById('servings').value);
        formData.append('summary', document.getElementById('summary').value);
        formData.append('cat_id', document.getElementById('catId').value);
        formData.append('selected_image_url', document.getElementById('selectedImageUrl').value);
        formData.append('ingredients', JSON.stringify(getParsedIngredients()));
        formData.append('instructions', JSON.stringify(getParsedInstructions()));

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                alert('Draf berhasil disimpan!');
            } else {
                alert('Gagal menyimpan draf: ' + (res.error || res.message));
            }
        })
        .catch(err => alert('Kesalahan jaringan: ' + err.message));
    }

    // Reject Draft
    function handleReject() {
        const importId = document.getElementById('importId').value;
        if (!importId || importId === '0') return;

        const reason = prompt('Masukkan alasan penolakan resep ini (opsional):', 'Ditolak oleh admin');
        if (reason === null) return;

        const formData = new FormData();
        formData.append('ajax_action', 'reject_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('reason', reason);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                alert('Resep ditandai sebagai Ditolak.');
                window.location.href = 'importer.php?tab=queue';
            } else {
                alert('Gagal: ' + res.error);
            }
        })
        .catch(err => alert('Kesalahan: ' + err.message));
    }

    function quickReject(id) {
        if (!confirm('Tolak draf resep #' + id + '?')) return;
        const formData = new FormData();
        formData.append('ajax_action', 'reject_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', id);
        formData.append('reason', 'Ditolak dari antrean');

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert('Gagal: ' + res.error);
            }
        })
        .catch(err => alert('Kesalahan: ' + err.message));
    }

    function quickRetry(id) {
        if (!confirm('Coba ulang ekstraksi untuk catatan #' + id + '?')) return;
        const formData = new FormData();
        formData.append('ajax_action', 'retry_import');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', id);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                alert('Berhasil diekstrak ulang!');
                window.location.href = 'importer.php?tab=import&id=' + res.import_id;
            } else {
                alert('Gagal mencoba ulang: ' + (res.error || res.message));
            }
        })
        .catch(err => alert('Kesalahan: ' + err.message));
    }

    // Publish Recipe
    function handlePublish(e) {
        e.preventDefault();
        const importId = document.getElementById('importId').value;
        if (!importId || importId === '0') {
            alert('Belum ada resep yang siap dipublikasikan.');
            return;
        }

        const catId = document.getElementById('catId').value;
        if (!catId) {
            alert('Kategori resep wajib dipilih.');
            document.getElementById('catId').focus();
            return;
        }

        const btn = document.getElementById('btnPublish');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Mempublikasikan...';

        const formData = new FormData();
        formData.append('ajax_action', 'publish_recipe');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('rights_confirmed', '1');
        formData.append('recipe_title', document.getElementById('recipeTitle').value);
        formData.append('prep_time', document.getElementById('prepTime').value);
        formData.append('cook_time', document.getElementById('cookTime').value);
        formData.append('servings', document.getElementById('servings').value);
        formData.append('summary', document.getElementById('summary').value);
        formData.append('cat_id', catId);
        formData.append('selected_image_url', document.getElementById('selectedImageUrl').value);
        formData.append('ingredients', JSON.stringify(getParsedIngredients()));
        formData.append('instructions', JSON.stringify(getParsedInstructions()));

        const customFile = document.getElementById('customImage');
        if (customFile && customFile.files.length > 0) {
            formData.append('custom_image', customFile.files[0]);
        }

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (res.success) {
                alert(res.message);
                window.location.href = 'recipes-detail.php?id=' + res.recipe_id;
            } else {
                alert('Gagal mempublikasikan: ' + (res.error || res.message));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origText;
            alert('Kesalahan jaringan atau server: ' + err.message);
        });
    }

    // Test Database Connection
    function handleTestDb() {
        const btn = document.getElementById('btnTestDb');
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Memeriksa...';

        const formData = new FormData();
        formData.append('ajax_action', 'test_db');
        formData.append('csrf_token', CSRF_TOKEN);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">speed</i> TES KONEKSI DATABASE';
            const el = document.getElementById('dbTestResult');
            el.style.display = 'block';

            if (res.success) {
                el.className = 'alert alert-success';
                el.innerHTML = '<strong>' + res.message + '</strong><br>' +
                    'Server: <code>' + escapeHtml(res.stats.server_info) + '</code> | ' +
                    'Tabel Resep: <b>' + res.stats.recipes_count + '</b> resep | ' +
                    'Tabel Kategori: <b>' + res.stats.categories_count + '</b> kategori | ' +
                    'Tabel Importer: <b>' + (res.stats.importer_table_ready ? 'Siap' : 'Perlu Migrasi') + '</b>';
            } else {
                el.className = 'alert alert-danger';
                el.innerHTML = '<strong>Uji koneksi gagal:</strong> ' + escapeHtml(res.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">speed</i> TES KONEKSI DATABASE';
            alert('Gagal menghubungi server: ' + err.message);
        });
    }

    // Test Upload Folder
    function handleTestUpload() {
        const btn = document.getElementById('btnTestUpload');
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Memeriksa...';

        const formData = new FormData();
        formData.append('ajax_action', 'test_upload');
        formData.append('csrf_token', CSRF_TOKEN);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">folder_special</i> TES FOLDER GAMBAR';
            const el = document.getElementById('uploadTestResult');
            el.style.display = 'block';

            if (res.success) {
                el.className = 'alert alert-success';
                el.innerHTML = '<strong>' + res.message + '</strong><br>' +
                    'Jalur: <code>' + escapeHtml(res.details.path) + '</code><br>' +
                    'Ruang bebas: <b>' + res.details.free_space + '</b> | Uji tulis &amp; hapus berkas sementara: <b>Lolos</b>';
            } else {
                el.className = 'alert alert-danger';
                el.innerHTML = '<strong>Uji folder gagal:</strong> ' + escapeHtml(res.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">folder_special</i> TES FOLDER GAMBAR';
            alert('Gagal: ' + err.message);
        });
    }

    // Run Migration
    function handleRunMigration() {
        const formData = new FormData();
        formData.append('ajax_action', 'run_migration');
        formData.append('csrf_token', CSRF_TOKEN);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            alert(res.message);
            location.reload();
        })
        .catch(err => alert('Gagal: ' + err.message));
    }

    /* =========================================================================
     * AI MAGIC POLISH JAVASCRIPT HANDLERS (1-Single-Call)
     * ========================================================================= */
    let currentAiResult = null;

    function toggleAiPolishPanel() {
        const container = document.getElementById('aiPolishContainer');
        const textSpan = document.getElementById('toggleAiText');
        if (!container) return;
        if (container.style.display === 'none' || container.style.display === '') {
            container.style.display = 'block';
            if (textSpan) textSpan.innerText = 'Tutup Studio AI';
        } else {
            container.style.display = 'none';
            if (textSpan) textSpan.innerText = 'Buka Studio AI';
        }
    }

    function runAiPolish() {
        const title = document.getElementById('recipeTitle').value.trim();
        if (!title) {
            alert('Silakan ambil data resep atau isi judul resep terlebih dahulu.');
            return;
        }

        const btn = document.getElementById('btnRunAiPolish');
        const origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Memproses...';

        const loadingBox = document.getElementById('aiPolishLoading');
        const resultsBox = document.getElementById('aiPolishResults');
        if (loadingBox) loadingBox.style.display = 'block';
        if (resultsBox) resultsBox.style.display = 'none';

        const formData = new FormData();
        formData.append('ajax_action', 'ai_enhance_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('recipe_title', title);
        formData.append('prep_time', document.getElementById('prepTime').value);
        formData.append('cook_time', document.getElementById('cookTime').value);
        formData.append('servings', document.getElementById('servings').value);
        formData.append('summary', document.getElementById('summary').value);
        formData.append('ingredients', JSON.stringify(getParsedIngredients()));
        formData.append('instructions', JSON.stringify(getParsedInstructions()));
        formData.append('style_preset', document.getElementById('aiStylePreset').value);
        formData.append('aspect_ratio', document.getElementById('aiAspectRatio').value);
        formData.append('prompt_count', '1');

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (loadingBox) loadingBox.style.display = 'none';

            if (!res.success) {
                let errMsg = res.error || 'Terjadi kesalahan tidak diketahui.';
                if (res.cascade_trail && Array.isArray(res.cascade_trail) && res.cascade_trail.length > 0) {
                    errMsg += '\n\nJejak Rotasi Pool & Model:\n• ' + res.cascade_trail.join('\n• ');
                }
                alert('Gagal memproses AI Polish:\n\n' + errMsg);
                return;
            }

            currentAiResult = res.data;
            renderAiPolishResults(res.data, res.model_used, res.key_used);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (loadingBox) loadingBox.style.display = 'none';
            alert('Kesalahan saat memproses AI Polish: ' + err.message);
        });
    }

    function renderAiPolishResults(data, modelUsed, keyUsed) {
        const resultsBox = document.getElementById('aiPolishResults');
        if (!resultsBox) return;

        // Render Title List
        const titleListEl = document.getElementById('aiTitleList');
        titleListEl.innerHTML = '';

        const primaryTitle = data.titles && data.titles.primary ? data.titles.primary : '';
        const altTitles = data.titles && Array.isArray(data.titles.alternatives) ? data.titles.alternatives : [];

        if (primaryTitle) {
            const primaryBtn = document.createElement('button');
            primaryBtn.type = 'button';
            primaryBtn.className = 'btn btn-block waves-effect ai-title-btn selected-title';
            primaryBtn.style.textAlign = 'left';
            primaryBtn.style.borderRadius = '6px';
            primaryBtn.style.padding = '8px 12px';
            primaryBtn.style.background = '#e0e7ff';
            primaryBtn.style.color = '#3730a3';
            primaryBtn.style.border = '2px solid #6366f1';
            primaryBtn.style.fontWeight = '600';
            primaryBtn.style.fontSize = '12px';
            primaryBtn.innerHTML = '<span class="badge" style="background:#4338ca; margin-right:6px;">UTAMA</span> ' + escapeHtml(primaryTitle);
            primaryBtn.onclick = function() { selectAiTitle(primaryTitle, this); };
            titleListEl.appendChild(primaryBtn);
        }

        altTitles.forEach((alt, idx) => {
            const altBtn = document.createElement('button');
            altBtn.type = 'button';
            altBtn.className = 'btn btn-block waves-effect ai-title-btn';
            altBtn.style.textAlign = 'left';
            altBtn.style.borderRadius = '6px';
            altBtn.style.padding = '8px 12px';
            altBtn.style.background = '#f8fafc';
            altBtn.style.color = '#1e293b';
            altBtn.style.border = '1px solid #cbd5e1';
            altBtn.style.fontWeight = '500';
            altBtn.style.fontSize = '12px';
            altBtn.innerHTML = '<span class="badge" style="background:#64748b; margin-right:6px;">OPSI ' + (idx + 1) + '</span> ' + escapeHtml(alt);
            altBtn.onclick = function() { selectAiTitle(alt, this); };
            titleListEl.appendChild(altBtn);
        });

        // Summary Preview
        const summaryEl = document.getElementById('aiSummaryPreview');
        if (summaryEl) {
            summaryEl.textContent = data.summary || '';
        }

        // Air Fryer Tips
        const tipsEl = document.getElementById('aiTipsBadge');
        if (tipsEl) {
            if (data.airfryer_tips) {
                tipsEl.style.display = 'block';
                tipsEl.innerHTML = '<strong>💡 Air Fryer Pro Tip:</strong> ' + escapeHtml(data.airfryer_tips);
            } else {
                tipsEl.style.display = 'none';
            }
        }

        // Instructions Count
        const countEl = document.getElementById('aiInstructionsCount');
        if (countEl) {
            const stepCount = (data.instructions && Array.isArray(data.instructions)) ? data.instructions.length : 0;
            countEl.innerHTML = '<i class="material-icons" style="font-size:13px; vertical-align:middle;">done_all</i> ' + stepCount + ' langkah memasak terstruktur &amp; takaran bahan terjaga 100%. Model: <code>' + escapeHtml(modelUsed || 'Gemini') + '</code>';
        }

        // Prompt Preview
        const promptEl = document.getElementById('aiPromptPreview');
        if (promptEl) {
            const p = (data.image_prompts && data.image_prompts[0] && data.image_prompts[0].prompt) ? data.image_prompts[0].prompt : '';
            promptEl.value = p;
        }

        resultsBox.style.display = 'block';
        resultsBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function selectAiTitle(titleText, btnElement) {
        if (!titleText) return;
        document.getElementById('recipeTitle').value = titleText;
        syncPreview();

        const buttons = document.querySelectorAll('.ai-title-btn');
        buttons.forEach(b => {
            b.style.border = '1px solid #cbd5e1';
            b.style.background = '#f8fafc';
            b.style.color = '#1e293b';
            b.style.fontWeight = '500';
        });
        if (btnElement) {
            btnElement.style.border = '2px solid #6366f1';
            btnElement.style.background = '#e0e7ff';
            btnElement.style.color = '#3730a3';
            btnElement.style.fontWeight = '700';
        }
    }

    function applyAiTextToForm() {
        if (!currentAiResult) return;

        if (currentAiResult.titles && currentAiResult.titles.primary) {
            const currVal = document.getElementById('recipeTitle').value.trim();
            if (currVal === '' || !currentAiResult.titles.alternatives.includes(currVal)) {
                document.getElementById('recipeTitle').value = currentAiResult.titles.primary;
            }
        }

        if (currentAiResult.summary) {
            document.getElementById('summary').value = currentAiResult.summary;
        }

        if (currentAiResult.instructions && Array.isArray(currentAiResult.instructions)) {
            document.getElementById('instructionsRaw').value = JSON.stringify(currentAiResult.instructions, null, 2);
        }

        syncPreview();
        alert('Teks hasil AI (Judul, Summary & Instruksi) berhasil diterapkan ke form draf!');
    }

    function copyAiPrompt() {
        const promptEl = document.getElementById('aiPromptPreview');
        if (!promptEl || !promptEl.value) {
            alert('Belum ada prompt foto yang dibuat.');
            return;
        }
        promptEl.select();
        promptEl.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(promptEl.value).then(() => {
            alert('Prompt foto AI berhasil disalin ke clipboard!');
        }).catch(() => {
            document.execCommand('copy');
            alert('Prompt foto AI disalin ke clipboard!');
        });
    }

    // ==========================================
    // DIRECT G-LABS GENERATION & POLLING ENGINE
    // ==========================================
    let glabsDirectPollInterval = null;
    let lastGeneratedAiImage = null;

    function runDirectGlabsGeneration() {
        const promptEl = document.getElementById('aiPromptPreview');
        let promptText = promptEl ? promptEl.value.trim() : '';
        if (!promptText) {
            const title = document.getElementById('recipeTitle')?.value.trim();
            if (!title) {
                alert('Silakan isi judul resep atau jalankan AI Magic Polish terlebih dahulu untuk menghasilkan prompt foto.');
                return;
            }
            promptText = 'A high-end culinary food photograph of ' + title + ', 4:3 aspect ratio, professional studio lighting, 8k resolution, food magazine cover aesthetic.';
            if (promptEl) promptEl.value = promptText;
        }

        const importId = document.getElementById('importId')?.value || 0;
        const stylePreset = document.getElementById('aiStylePreset')?.value || 'Modern Fine Dining';
        const aspectRatio = document.getElementById('aiAspectRatio')?.value || '4:3';

        const btn = document.getElementById('btnGenerateGlabsDirect');
        const origBtnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">hourglass_empty</i> Mengirim Task...';

        const progressBox = document.getElementById('glabsDirectProgressBox');
        const resultBox = document.getElementById('glabsDirectResultBox');
        const progressBar = document.getElementById('glabsDirectProgressBar');
        const statusTitle = document.getElementById('glabsDirectStatusTitle');
        const statusDesc = document.getElementById('glabsDirectStatusDesc');
        const taskIdBadge = document.getElementById('glabsDirectTaskId');
        const timerEl = document.getElementById('glabsDirectTimer');

        if (resultBox) resultBox.style.display = 'none';
        if (progressBox) progressBox.style.display = 'block';
        if (progressBar) progressBar.style.width = '20%';
        if (statusTitle) statusTitle.textContent = 'Menyiapkan proses render foto...';
        if (statusDesc) statusDesc.textContent = 'Menghubungkan ke sistem AI...';
        if (taskIdBadge) taskIdBadge.textContent = 'Task #connecting';

        const formData = new FormData();
        formData.append('ajax_action', 'submit_glabs_task');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('prompt', promptText);
        formData.append('style_preset', stylePreset);
        formData.append('aspect_ratio', aspectRatio);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (!res.success || !res.task_id) {
                btn.disabled = false;
                btn.innerHTML = origBtnText;
                if (progressBox) progressBox.style.display = 'none';
                alert('Gagal mengirim task ke sistem AI: ' + (res.error || 'Server tidak merespons'));
                return;
            }

            if (taskIdBadge) taskIdBadge.textContent = 'Task #' + res.task_id;
            startDirectGlabsPolling(res.task_id, res.generation_id, btn, origBtnText);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnText;
            if (progressBox) progressBox.style.display = 'none';
            alert('Kesalahan koneksi ke server: ' + err.message);
        });
    }

    function startDirectGlabsPolling(taskId, genId, btnElement, origBtnText) {
        if (glabsDirectPollInterval) clearInterval(glabsDirectPollInterval);

        const progressBox = document.getElementById('glabsDirectProgressBox');
        const progressBar = document.getElementById('glabsDirectProgressBar');
        const statusTitle = document.getElementById('glabsDirectStatusTitle');
        const statusDesc = document.getElementById('glabsDirectStatusDesc');
        const timerEl = document.getElementById('glabsDirectTimer');

        let pollCount = 0;
        let seconds = 0;
        if (progressBar) progressBar.style.width = '35%';
        if (statusTitle) statusTitle.textContent = 'Task dalam antrean pemrosesan AI...';
        if (statusDesc) statusDesc.textContent = 'Menginisialisasi model Nano Banana...';

        glabsDirectPollInterval = setInterval(() => {
            pollCount++;
            seconds += 3;
            if (timerEl) timerEl.textContent = seconds + 's';

            const formData = new FormData();
            formData.append('ajax_action', 'poll_glabs_task');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('task_id', taskId);
            formData.append('generation_id', genId);

            safeFetchJson('importer.php', { method: 'POST', body: formData })
            .then(res => {
                if (res.status === 'running') {
                    if (progressBar) progressBar.style.width = '70%';
                    if (statusTitle) statusTitle.textContent = 'AI sedang me-render foto makanan (running)...';
                    if (statusDesc) statusDesc.textContent = 'Memproses detail tekstur masakan & pencahayaan...';
                } else if (res.status === 'completed') {
                    clearInterval(glabsDirectPollInterval);
                    if (progressBar) progressBar.style.width = '100%';
                    if (statusTitle) statusTitle.textContent = '✓ Foto selesai dibuat! Mengunduh & mengoptimasi...';
                    if (statusDesc) statusDesc.textContent = 'Menyelesaikan optimasi 1080px untuk aplikasi...';

                    setTimeout(() => {
                        if (progressBox) progressBox.style.display = 'none';
                        displayDirectGlabsResult(res);
                        if (btnElement) {
                            btnElement.disabled = false;
                            btnElement.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">refresh</i> GENERATE VARIASI BARU';
                        }
                    }, 800);
                } else if (res.status === 'failed' || res.success === false) {
                    clearInterval(glabsDirectPollInterval);
                    if (progressBox) progressBox.style.display = 'none';
                    if (btnElement) {
                        btnElement.disabled = false;
                        btnElement.innerHTML = origBtnText;
                    }
                    alert('G-Labs gagal memproses task: ' + (res.error || 'Terjadi kesalahan'));
                }
            })
            .catch(err => {
                console.error('Poll error:', err);
                if (pollCount > 10) {
                    clearInterval(glabsDirectPollInterval);
                    if (progressBox) progressBox.style.display = 'none';
                    if (btnElement) {
                        btnElement.disabled = false;
                        btnElement.innerHTML = origBtnText;
                    }
                    alert('Gangguan koneksi saat memeriksa status task: ' + err.message);
                }
            });

            // Safeguard timeout (120 detik)
            if (pollCount > 40) {
                clearInterval(glabsDirectPollInterval);
                if (statusDesc) statusDesc.textContent = 'Waktu tunggu habis (timeout). Periksa tab riwayat AI Studio.';
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = origBtnText;
                }
            }
        }, 3000);
    }

    function displayDirectGlabsResult(res) {
        lastGeneratedAiImage = res;
        const resultBox = document.getElementById('glabsDirectResultBox');
        const imgEl = document.getElementById('glabsDirectResultImg');
        const filenameEl = document.getElementById('glabsDirectFilenameText');
        const badgeEl = document.getElementById('glabsDirectMetaBadge');
        const noticeEl = document.getElementById('glabsDirectAppliedNotice');
        const btnApply = document.getElementById('btnApplyAiImageToRecipe');

        if (imgEl && res.image_url) imgEl.src = res.image_url;
        if (filenameEl) filenameEl.textContent = res.image_filename || 'ai_result.jpg';
        if (badgeEl) {
            const kb = res.file_size ? Math.round(res.file_size / 1024) + ' KB' : 'Optimized';
            badgeEl.textContent = '1080px • ' + kb;
        }
        if (noticeEl) noticeEl.style.display = 'none';
        if (btnApply) {
            btnApply.disabled = false;
            btnApply.className = 'btn btn-success waves-effect';
            btnApply.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">star</i> GUNAKAN GAMBAR INI UNTUK RESEP';
        }

        if (resultBox) {
            resultBox.style.display = 'block';
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function applyAiImageToRecipeForm() {
        if (!lastGeneratedAiImage || !lastGeneratedAiImage.image_filename) {
            alert('Belum ada gambar AI yang siap diterapkan.');
            return;
        }

        const imgUrl = lastGeneratedAiImage.image_url || ('upload/' + lastGeneratedAiImage.image_filename);
        
        // 1. Update hidden input
        document.getElementById('selectedImageUrl').value = imgUrl;
        currentSelectedImageUrl = imgUrl;

        // 2. Update emulator phone preview
        syncPreview();

        // 3. Update candidates thumbnail wrap
        const candidateWrap = document.getElementById('imageCandidatesList');
        if (candidateWrap) {
            document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
            let existingAiThumb = document.getElementById('aiThumbCandidate');
            if (!existingAiThumb) {
                existingAiThumb = document.createElement('img');
                existingAiThumb.id = 'aiThumbCandidate';
                existingAiThumb.className = 'image-candidate-thumb selected';
                existingAiThumb.title = 'Foto Hasil AI G-Labs (1080px)';
                existingAiThumb.onclick = function() {
                    document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
                    existingAiThumb.classList.add('selected');
                    document.getElementById('selectedImageUrl').value = imgUrl;
                    currentSelectedImageUrl = imgUrl;
                    syncPreview();
                };
                candidateWrap.insertBefore(existingAiThumb, candidateWrap.firstChild);
            }
            existingAiThumb.src = imgUrl;
            existingAiThumb.classList.add('selected');
        }

        // 4. Update UI button and notice
        const btnApply = document.getElementById('btnApplyAiImageToRecipe');
        const noticeEl = document.getElementById('glabsDirectAppliedNotice');
        if (btnApply) {
            btnApply.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">check</i> GAMBAR AI SUDAH DIGUNAKAN';
            btnApply.className = 'btn btn-success waves-effect disabled';
            btnApply.disabled = true;
        }
        if (noticeEl) noticeEl.style.display = 'block';

        // 5. Asynchronously persist to draft in database
        const importId = document.getElementById('importId')?.value || 0;
        if (importId > 0) {
            const formData = new FormData();
            formData.append('ajax_action', 'apply_ai_image_draft');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('import_id', importId);
            formData.append('image_filename', imgUrl);
            safeFetchJson('importer.php', { method: 'POST', body: formData }).catch(e => console.warn('Draft save notice:', e));
        }
    }

    function viewAiImageFull() {
        if (lastGeneratedAiImage && lastGeneratedAiImage.image_url) {
            window.open(lastGeneratedAiImage.image_url, '_blank');
        }
    }
</script>

<?php include_once('includes/footer.php'); ?>
