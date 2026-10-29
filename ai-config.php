<?php
/**
 * Airfryer Webadmin - Konfigurasi AI Engine & Pool API Key
 * Standalone configuration page for Gemini Key Pool, Waterfall Fallback,
 * Local GPU Renderer, Video Studio, and Frontend Live Hosting Sync.
 * 
 * Strict rule: All styling lives in assets/css/airfry-theme.css
 */

require_once 'includes/config.php';
require_once 'includes/constant.php';
require_once 'includes/strings.php';
require_once 'functions.php';
require_once 'includes/session.php';
require_once 'ai/AiBootstrap.php';
require_once 'ai/GeminiClient.php';
require_once 'ai/GlabsClient.php';
require_once 'ai/SyncClient.php';
require_once 'ai/R2Client.php';
require_once 'ai/MiniMaxClient.php';

use Ai\AiBootstrap;
use Ai\GeminiClient;
use Ai\GlabsClient;
use Ai\SyncClient;
use Ai\R2Client;
use Ai\MiniMaxClient;

// Ensure tables are migrated and ready
if (!AiBootstrap::isMigrated($connect)) {
    AiBootstrap::runAllMigrations($connect);
}

$geminiClient = new GeminiClient();
$glabsClient = new GlabsClient();
$syncClient = new SyncClient($connect);

// =========================================================================
// AJAX ACTIONS HANDLER
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = trim($_POST['action']);

    // 1. ADD KEY TO POOL
    if ($action === 'add_key') {
        $keyName = trim($_POST['key_name'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $rpdLimit = (int)($_POST['rpd_limit'] ?? 20);

        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'error' => 'API Key wajib diisi.']);
            exit;
        }

        $res = $geminiClient->addKey($connect, $keyName, $apiKey, $rpdLimit);
        echo json_encode($res);
        exit;
    }

    // 2. TEST SINGLE API KEY
    if ($action === 'test_key') {
        $apiKey = trim($_POST['api_key'] ?? '');
        $keyId = (int)($_POST['key_id'] ?? 0);

        if (empty($apiKey) && $keyId > 0) {
            $stmt = $connect->prepare("SELECT api_key FROM `tbl_ai_keys` WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $keyId);
                $stmt->execute();
                $resKey = $stmt->get_result();
                if ($r = $resKey->fetch_assoc()) {
                    $apiKey = trim($r['api_key']);
                }
                $stmt->close();
            }
        }

        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'error' => 'API Key tidak boleh kosong.']);
            exit;
        }

        $testRes = $geminiClient->testConnection($apiKey);

        if ($keyId > 0) {
            $newStatus = $testRes['success'] ? 'ACTIVE' : 'INVALID';
            $connect->query("UPDATE `tbl_ai_keys` SET `last_status` = '{$newStatus}', `cooldown_until` = NULL WHERE id = {$keyId}");
            $testRes['key_id'] = $keyId;
            $testRes['new_status'] = $newStatus;
        }

        echo json_encode($testRes);
        exit;
    }

    // 2B. TEST ALL API KEYS IN POOL
    if ($action === 'test_all_keys') {
        $keys = $geminiClient->getAllKeys($connect);
        $results = [];
        $validCount = 0;

        foreach ($keys as $k) {
            $test = $geminiClient->testConnection($k['api_key']);
            $newStatus = $test['success'] ? 'ACTIVE' : 'INVALID';
            $connect->query("UPDATE `tbl_ai_keys` SET `last_status` = '{$newStatus}', `cooldown_until` = NULL WHERE id = {$k['id']}");
            if ($test['success']) {
                $validCount++;
            }
            $results[] = [
                'id' => (int)$k['id'],
                'name' => $k['key_name'],
                'success' => $test['success'],
                'latency_ms' => $test['latency_ms'] ?? 0,
                'status' => $newStatus,
                'error' => $test['error'] ?? null
            ];
        }

        echo json_encode([
            'success' => true,
            'total' => count($keys),
            'valid_count' => $validCount,
            'details' => $results
        ]);
        exit;
    }

    // 2C. TEST G-LABS LOCAL GPU CONNECTION
    if ($action === 'test_glabs') {
        $host = trim($_POST['host'] ?? '127.0.0.1');
        $port = (int)($_POST['port'] ?? 8765);
        $apiKey = trim($_POST['api_key'] ?? '');
        $testRes = $glabsClient->checkHealth($host, $port, $apiKey);
        echo json_encode($testRes);
        exit;
    }

    // 3. DELETE KEY
    if ($action === 'delete_key') {
        $keyId = (int)($_POST['id'] ?? 0);
        if ($keyId > 0 && $geminiClient->deleteKey($connect, $keyId)) {
            echo json_encode(['success' => true, 'message' => 'Kunci berhasil dihapus dari pool.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Gagal menghapus kunci atau ID tidak valid.']);
        }
        exit;
    }

    // 4. TOGGLE KEY ACTIVE STATE
    if ($action === 'toggle_key') {
        $keyId = (int)($_POST['id'] ?? 0);
        $active = !empty($_POST['active']);
        if ($keyId > 0 && $geminiClient->toggleKey($connect, $keyId, $active)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Gagal memperbarui status kunci.']);
        }
        exit;
    }

    // 5. TEST SYNC LIVE CONNECTION
    if ($action === 'test_sync') {
        $pingResult = $syncClient->pingLive();
        echo json_encode($pingResult);
        exit;
    }

    // 6. SAVE ALL CONFIG
    if ($action === 'save_all_config') {
        $primaryModel = trim($_POST['gemini_model'] ?? 'gemini-2.5-flash');
        $approvedModels = ['gemini-2.5-flash', 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-flash-latest'];
        if (!in_array($primaryModel, $approvedModels, true)) {
            $primaryModel = 'gemini-2.5-flash';
        }
        $fallbackList = array_values(array_filter($approvedModels, function($m) use ($primaryModel) {
            return $m !== $primaryModel;
        }));
        $fallbackJson = json_encode($fallbackList);

        $glabsHost = trim($_POST['glabs_host'] ?? '127.0.0.1');
        $glabsPort = (int)($_POST['glabs_port'] ?? 8765);
        $glabsApiKey = trim($_POST['glabs_api_key'] ?? '');
        $glabsModel = trim($_POST['glabs_model'] ?? 'nano_banana_2');
        $defaultStyle = trim($_POST['default_style'] ?? 'Modern Fine Dining');
        
        $syncUrl = trim($_POST['sync_target_url'] ?? '');
        $syncToken = trim($_POST['sync_secret_token'] ?? '');

        // Update tbl_ai_config
        $stmt = $connect->prepare("UPDATE tbl_ai_config SET 
            gemini_model = ?, 
            gemini_fallback_models = ?,
            glabs_host = ?, 
            glabs_port = ?, 
            glabs_api_key = ?,
            glabs_model = ?, 
            default_style = ?,
            sync_target_url = ?,
            sync_secret_token = ?,
            r2_enabled = ?,
            r2_account_id = ?,
            r2_access_key_id = ?,
            r2_secret_access_key = ?,
            r2_bucket_name = ?,
            r2_public_domain = ?
            WHERE id = 1");
        
        $r2Enabled = isset($_POST['r2_enabled']) && ($_POST['r2_enabled'] === '1' || $_POST['r2_enabled'] === 'true') ? 1 : 0;
        $r2AccountId = trim($_POST['r2_account_id'] ?? '');
        $r2AccessKeyId = trim($_POST['r2_access_key_id'] ?? '');
        $r2SecretAccessKey = trim($_POST['r2_secret_access_key'] ?? '');
        $r2BucketName = trim($_POST['r2_bucket_name'] ?? '');
        $r2PublicDomain = trim($_POST['r2_public_domain'] ?? '');

        if ($stmt) {
            $stmt->bind_param('sssisssssisssss', 
                $primaryModel, $fallbackJson, $glabsHost, $glabsPort, $glabsApiKey, 
                $glabsModel, $defaultStyle, $syncUrl, $syncToken,
                $r2Enabled, $r2AccountId, $r2AccessKeyId, $r2SecretAccessKey, $r2BucketName, $r2PublicDomain
            );
            $saved = $stmt->execute();
            $stmt->close();
            if ($saved) {
                // Also save MiniMax config if provided
                MiniMaxClient::saveConfig($connect, $_POST);
                echo json_encode(['success' => true, 'message' => 'Seluruh pengaturan AI Engine, Sinkronisasi, Cloudflare R2, dan MiniMax TTS berhasil disimpan!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Gagal menyimpan ke database: ' . $connect->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Prepare statement error: ' . $connect->error]);
        }
        exit;
    }

    // 7. SAVE R2 CONFIG ONLY
    if ($action === 'save_r2_config') {
        $r2Enabled = isset($_POST['r2_enabled']) && ($_POST['r2_enabled'] === '1' || $_POST['r2_enabled'] === 'true') ? 1 : 0;
        $r2AccountId = trim($_POST['r2_account_id'] ?? '');
        $r2AccessKeyId = trim($_POST['r2_access_key_id'] ?? '');
        $r2SecretAccessKey = trim($_POST['r2_secret_access_key'] ?? '');
        $r2BucketName = trim($_POST['r2_bucket_name'] ?? '');
        $r2PublicDomain = trim($_POST['r2_public_domain'] ?? '');

        $stmt = $connect->prepare("UPDATE tbl_ai_config SET 
            r2_enabled = ?, 
            r2_account_id = ?, 
            r2_access_key_id = ?, 
            r2_secret_access_key = ?, 
            r2_bucket_name = ?, 
            r2_public_domain = ?
            WHERE id = 1");
        
        if ($stmt) {
            $stmt->bind_param('isssss', $r2Enabled, $r2AccountId, $r2AccessKeyId, $r2SecretAccessKey, $r2BucketName, $r2PublicDomain);
            $saved = $stmt->execute();
            $stmt->close();
            if ($saved) {
                echo json_encode(['success' => true, 'message' => 'Pengaturan Cloudflare R2 berhasil disimpan!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Gagal menyimpan ke database: ' . $connect->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Prepare statement error: ' . $connect->error]);
        }
        exit;
    }

    // 8. TEST R2 CONNECTION
    if ($action === 'test_r2_connection') {
        $r2AccountId = trim($_POST['r2_account_id'] ?? '');
        $r2AccessKeyId = trim($_POST['r2_access_key_id'] ?? '');
        $r2SecretAccessKey = trim($_POST['r2_secret_access_key'] ?? '');
        $r2BucketName = trim($_POST['r2_bucket_name'] ?? '');
        $r2PublicDomain = trim($_POST['r2_public_domain'] ?? '');

        if (!empty($r2AccountId) && !empty($r2AccessKeyId) && !empty($r2SecretAccessKey) && !empty($r2BucketName)) {
            $r2 = new R2Client($r2AccountId, $r2AccessKeyId, $r2SecretAccessKey, $r2BucketName, $r2PublicDomain);
        } else {
            $r2 = R2Client::fromDatabase($connect);
        }

        if (!$r2) {
            echo json_encode(['success' => false, 'error' => 'Kredensial Cloudflare R2 belum lengkap. Mohon isi Account ID, Access Key, Secret Key, dan Bucket Name.']);
            exit;
        }

        $res = $r2->testConnection();
        echo json_encode($res);
        exit;
    }

    // 9. SAVE MINIMAX CONFIG ONLY
    if ($action === 'save_minimax_config') {
        $saved = MiniMaxClient::saveConfig($connect, $_POST);
        if ($saved) {
            echo json_encode(['success' => true, 'message' => 'Pengaturan MiniMax TTS berhasil disimpan!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Gagal menyimpan pengaturan MiniMax ke database.']);
        }
        exit;
    }

    // 10. TEST MINIMAX TTS CONNECTION & SAMPLE AUDIO
    if ($action === 'test_minimax_tts') {
        $apiKey = trim($_POST['minimax_api_key'] ?? '');
        $voicePersona = trim($_POST['minimax_default_voice'] ?? '');
        $sampleText = trim($_POST['sample_text'] ?? '');

        if (empty($apiKey)) {
            $conf = MiniMaxClient::getConfig($connect);
            $apiKey = $conf['minimax_api_key'] ?? '';
        }

        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'error' => 'MiniMax API Key belum diisi.']);
            exit;
        }

        $minimax = new MiniMaxClient();
        $testRes = $minimax->testConnection($apiKey, $voicePersona, $sampleText);
        echo json_encode($testRes);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Aksi tidak dikenal.']);
    exit;
}

// =========================================================================
// LOAD EXISTING CONFIG & DATA
// =========================================================================
$configRes = $connect->query("SELECT * FROM tbl_ai_config WHERE id = 1 LIMIT 1");
$aiConfig = ($configRes && $configRes->num_rows > 0) ? $configRes->fetch_assoc() : [];

$primaryModel = !empty($aiConfig['gemini_model']) ? trim($aiConfig['gemini_model']) : 'gemini-2.5-flash';
$glabsHost = $aiConfig['glabs_host'] ?? '127.0.0.1';
$glabsPort = $aiConfig['glabs_port'] ?? 8765;
$glabsApiKey = $aiConfig['glabs_api_key'] ?? '';
$glabsModel = $aiConfig['glabs_model'] ?? 'nano_banana_2';
$defaultStyle = $aiConfig['default_style'] ?? 'Modern Fine Dining';
$syncTargetUrl = $syncClient->getTargetUrl();
$syncSecretToken = $syncClient->getSecretToken();

$r2Enabled = (int)($aiConfig['r2_enabled'] ?? 0);
$r2AccountId = $aiConfig['r2_account_id'] ?? '';
$r2AccessKeyId = $aiConfig['r2_access_key_id'] ?? '';
$r2SecretAccessKey = $aiConfig['r2_secret_access_key'] ?? '';
$r2BucketName = $aiConfig['r2_bucket_name'] ?? '';
$r2PublicDomain = $aiConfig['r2_public_domain'] ?? '';

// Load MiniMax TTS Config
$minimaxConfig = MiniMaxClient::getConfig($connect);
$minimaxApiKey = $minimaxConfig['minimax_api_key'] ?? '';
$minimaxGroupId = $minimaxConfig['minimax_group_id'] ?? '';
$minimaxModel = $minimaxConfig['minimax_model'] ?? 'speech-2.8-turbo';
$minimaxDefaultLang = $minimaxConfig['minimax_default_language'] ?? 'id-ID';
$minimaxDefaultVoice = $minimaxConfig['minimax_default_voice'] ?? 'Indonesian_energetic_streamer_vv2';
$minimaxDefaultSpeed = (float)($minimaxConfig['minimax_default_speed'] ?? 1.00);
$minimaxDefaultVolume = (float)($minimaxConfig['minimax_default_volume'] ?? 1.00);

// Load Gemini Keys
$keysList = $geminiClient->getAllKeys($connect);
$totalKeys = count($keysList);
$activeKeys = 0;
$totalCapacityRpd = 0;
foreach ($keysList as $k) {
    if (!empty($k['is_active']) && $k['last_status'] === 'ACTIVE') {
        $activeKeys++;
        $totalCapacityRpd += (int)($k['daily_limit'] ?? 20);
    }
}

// User data for topbar/sidebar
$username = $_SESSION['user'] ?? 'admin';
$sqlUser = "SELECT id, username, email FROM tbl_admin WHERE username = '$username'";
$userResult = $connect->query($sqlUser);
$data = ($userResult && $userResult->num_rows > 0) ? $userResult->fetch_assoc() : ['username' => 'Admin Studio', 'email' => 'admin@airfry.internal', 'id' => 1];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=Edge">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <title>Konfigurasi AI Engine & Pool API Key — Airfryer</title>
    
    <!-- Centralized CSS Assets -->
    <?php include 'assets/css.min.php'; ?>
</head>
<body class="theme-teal">
    <!-- Top Bar -->
    <nav class="navbar">
        <div class="container-fluid">
            <div class="navbar-header">
                <a href="javascript:void(0);" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar-collapse" aria-expanded="false"></a>
                <a href="javascript:void(0);" class="bars"></a>
                <a class="navbar-brand" href="dashboard.php" style="display:flex; align-items:center; gap:8px;">
                    <img src="assets/images/logo.png" width="28" height="28" alt="Logo" style="border-radius:6px; object-fit:cover;">
                    <strong>AirFry Eats Studio</strong>
                </a>
            </div>
            <div class="collapse navbar-collapse" id="navbar-collapse">
                <ul class="nav navbar-nav navbar-right">
                    <li>
                        <span class="topbar-badge" style="margin-top:14px; display:inline-flex;">
                            <i class="material-icons" style="font-size:14px;">dns</i> Mac Mini Studio Node
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Sidebar Navigation -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content Section -->
    <section class="content">
        <div class="container-fluid">
            
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">dashboard</i> Dashboard</a>
                <span>/</span>
                <a href="ai-generator.php">Studio & Otomasi</a>
                <span>/</span>
                <span style="color:var(--pp-navy); font-weight:700;">Konfigurasi AI Engine</span>
            </div>

            <!-- Page Header Row -->
            <div class="page-header-row">
                <div class="page-header-title">
                    <div class="header-icon-box">
                        <i class="material-icons">tune</i>
                    </div>
                    <div>
                        <h1>Konfigurasi AI Engine & Pool API Key</h1>
                        <p>Kelola multi-key failover Google Gemini, alur cascade retry, rendering GPU lokal, dan sinkronisasi hosting live.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="ai-generator.php" class="btn-back-hub">
                        <i class="material-icons" style="font-size:16px;">arrow_back</i> Kembali ke Studio Hub
                    </a>
                    <button type="button" class="btn-brand-primary" onclick="saveAllConfigurations()">
                        <i class="material-icons" style="font-size:16px;">save</i> Simpan Perubahan
                    </button>
                </div>
            </div>

            <!-- Metrics Overview Cards -->
            <div class="metric-row">
                <div class="metric-card teal">
                    <div class="metric-icon teal">
                        <i class="material-icons">vpn_key</i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-label">Total API Key Pool</div>
                        <div class="metric-value" id="disp-total-keys"><?php echo $totalKeys; ?> Kunci</div>
                        <div class="metric-sub"><?php echo $activeKeys; ?> aktif &bull; <?php echo number_format($totalCapacityRpd); ?> RPD Kuota</div>
                    </div>
                </div>

                <div class="metric-card green">
                    <div class="metric-icon green">
                        <i class="material-icons">health_and_safety</i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-label">Status Failover</div>
                        <div class="metric-value">Otomatis</div>
                        <div class="metric-sub">Resilient Waterfall 429/503</div>
                    </div>
                </div>

                <div class="metric-card orange">
                    <div class="metric-icon orange">
                        <i class="material-icons">speed</i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-label">GPU G-Labs Local</div>
                        <div class="metric-value"><?php echo htmlspecialchars($glabsHost . ':' . $glabsPort); ?></div>
                        <div class="metric-sub">Engine Image Nano Banana</div>
                    </div>
                </div>

                <div class="metric-card purple">
                    <div class="metric-icon purple">
                        <i class="material-icons">cloud_sync</i>
                    </div>
                    <div class="metric-info">
                        <div class="metric-label">Dual Deployment</div>
                        <div class="metric-value">Aktif</div>
                        <div class="metric-sub">1-Click Live Hosting Sync</div>
                    </div>
                </div>
            </div>

            <!-- SECTION 1: POOL API KEY GEMINI -->
            <section class="config-card">
                <div class="config-card-header">
                    <div class="config-card-header-left">
                        <div class="config-header-icon icon-gemini">
                            <i class="material-icons">dns</i>
                        </div>
                        <div class="config-card-title">
                            <h2>1. Pool API Key Google Gemini (Multi-Key Failover)</h2>
                            <p>Rotasi kunci otomatis saat kuota akun habis atau mengalami HTTP 429 Too Many Requests.</p>
                        </div>
                    </div>
                    <div class="config-card-actions" style="display:flex; gap:10px; align-items:center;">
                        <button type="button" class="btn-brand-outline" id="btn-test-all-keys" onclick="testAllPoolKeys(this)" title="Uji validasi seluruh kunci dalam pool sekaligus">
                            <i class="material-icons" style="font-size:16px;">checklist_rtl</i> Uji Semua Kunci
                        </button>
                        <button type="button" class="btn-brand-primary" onclick="openAddKeyModal()">
                            <i class="material-icons" style="font-size:16px;">add</i> Tambah Kunci Baru
                        </button>
                    </div>
                </div>

                <!-- Keys Table -->
                <div class="table-responsive">
                    <table class="key-table">
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
                            <?php if (empty($keysList)): ?>
                            <tr id="row-empty-keys">
                                <td colspan="6" style="text-align:center; padding:32px; color:var(--pp-slate-muted);">
                                    <i class="material-icons" style="font-size:36px; color:#CBD5E1; display:block; margin-bottom:8px;">vpn_key_off</i>
                                    Belum ada API Key di dalam pool. Klik tombol <strong>Tambah Kunci Baru</strong> untuk mendaftarkan akun Google AI Studio.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($keysList as $k): 
                                    $masked = substr($k['api_key'], 0, 7) . '...' . substr($k['api_key'], -4);
                                    $used = (int)($k['used_today'] ?? 0);
                                    $limit = (int)($k['daily_limit'] ?? 20);
                                    $pct = ($limit > 0) ? min(100, round(($used / $limit) * 100)) : 0;
                                    $statusClass = ($k['last_status'] === 'ACTIVE' && $k['is_active']) ? 'healthy' : (($k['last_status'] === 'COOLDOWN_429') ? 'cooldown' : 'standby');
                                    $statusLabel = ($k['last_status'] === 'ACTIVE' && $k['is_active']) ? 'Aktif' : (($k['last_status'] === 'COOLDOWN_429') ? 'Cooldown 429' : 'Non-aktif');
                                ?>
                                <tr id="key-row-<?php echo $k['id']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($k['key_name']); ?></strong>
                                        <div style="font-size:11px; color:#64748B;">ID #<?php echo $k['id']; ?></div>
                                    </td>
                                    <td>
                                        <span class="key-code">
                                             <?php echo htmlspecialchars($masked); ?>
                                             <i class="material-icons" style="font-size:13px; cursor:pointer;" onclick="copyToClipboard('<?php echo htmlspecialchars($k['api_key'], ENT_QUOTES); ?>')" title="Salin">content_copy</i>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size:12px; font-weight:700;"><?php echo $used; ?> / <?php echo number_format($limit); ?> RPD</div>
                                        <div class="progress-bar-wrap">
                                            <div class="progress-bar-fill <?php echo ($pct > 80) ? 'orange' : 'teal'; ?>" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>" id="status-badge-<?php echo $k['id']; ?>">
                                            <i class="material-icons" style="font-size:12px;">check_circle</i> <?php echo $statusLabel; ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px; color:#64748B;">
                                        <?php echo !empty($k['last_used_date']) ? htmlspecialchars($k['last_used_date']) : 'Belum pernah'; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <button class="action-icon-btn" id="btn-test-key-<?php echo $k['id']; ?>" onclick="testPoolKey(<?php echo $k['id']; ?>, '<?php echo htmlspecialchars($k['api_key'], ENT_QUOTES); ?>')" title="Uji Validasi Kunci ke Google">
                                            <i class="material-icons" style="font-size:16px;">refresh</i>
                                        </button>
                                        <button class="action-icon-btn danger" onclick="deletePoolKey(<?php echo $k['id']; ?>)" title="Hapus">
                                            <i class="material-icons" style="font-size:16px;">delete_outline</i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SECTION 2: WATERFALL FALLBACK ENGINE -->
            <section class="config-card">
                <div class="config-card-header">
                    <div class="config-card-header-left">
                        <div class="config-header-icon icon-waterfall">
                            <i class="material-icons">alt_route</i>
                        </div>
                        <div class="config-card-title">
                            <h2>2. Rantai Eksekusi Berjenjang (Waterfall Fallback & Retry)</h2>
                            <p>Orkestrasi model AI otomatis jika server Google mengalami 503 High Demand atau kehabisan token.</p>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="select-primary-model">PILIH MODEL UTAMA (PRIMARY EXECUTOR):</label>
                    <select class="form-control" id="select-primary-model" onchange="changePrimaryModel(this.value)">
                        <option value="gemini-2.5-flash" <?php if ($primaryModel === 'gemini-2.5-flash') echo 'selected'; ?>>gemini-2.5-flash (Rekomendasi Utama — Cerdas &amp; Cepat)</option>
                        <option value="gemini-3.6-flash" <?php if ($primaryModel === 'gemini-3.6-flash') echo 'selected'; ?>>gemini-3.6-flash (Sangat Stabil &amp; Ringan)</option>
                        <option value="gemini-3.7-flash" <?php if ($primaryModel === 'gemini-3.7-flash') echo 'selected'; ?>>gemini-3.7-flash (Multimodal Cerdas)</option>
                        <option value="gemini-3.8-flash" <?php if ($primaryModel === 'gemini-3.8-flash') echo 'selected'; ?>>gemini-3.8-flash (Generasi Terkini)</option>
                        <option value="gemini-flash-latest" <?php if ($primaryModel === 'gemini-flash-latest') echo 'selected'; ?>>gemini-flash-latest (Auto-update Versi Stabil Google)</option>
                    </select>
                    <span class="form-helper">Model ini dieksekusi pertama kali saat formulasi resep mingguan atau penulisan prompt foto.</span>
                </div>

                <!-- LIVE CASCADE VISUALIZER -->
                <div class="cascade-visualizer">
                    <div class="cascade-visualizer-header">
                        <strong style="font-size:12.5px; color:var(--pp-navy); display:flex; align-items:center; gap:6px;">
                            <i class="material-icons" style="font-size:16px; color:var(--pp-teal);">visibility</i>
                            SIMULASI ALUR CASCADE OTOMATIS:
                        </strong>
                        <span style="font-size:11px; background:#EDE9FE; color:#6D28D9; font-weight:700; padding:2px 8px; border-radius:12px;">Real-Time Flow</span>
                    </div>

                    <div class="cascade-steps-container" id="cascade-steps-container">
                        <?php
                        $approvedModelsList = [
                            ['id' => 'gemini-2.5-flash', 'label' => 'gemini-2.5-flash', 'desc' => 'Rekomendasi Utama — Cerdas & Cepat'],
                            ['id' => 'gemini-3.6-flash', 'label' => 'gemini-3.6-flash', 'desc' => 'Sangat Stabil & Ringan'],
                            ['id' => 'gemini-3.7-flash', 'label' => 'gemini-3.7-flash', 'desc' => 'Multimodal Cerdas'],
                            ['id' => 'gemini-3.8-flash', 'label' => 'gemini-3.8-flash', 'desc' => 'Generasi Terkini'],
                            ['id' => 'gemini-flash-latest', 'label' => 'gemini-flash-latest', 'desc' => 'Auto-update Versi Stabil Google']
                        ];
                        $chain = [$primaryModel];
                        foreach ($approvedModelsList as $am) {
                            if ($am['id'] !== $primaryModel && !in_array($am['id'], $chain, true)) {
                                $chain[] = $am['id'];
                            }
                        }
                        foreach ($chain as $idx => $mId):
                            $isPrimary = ($idx === 0);
                            $found = null;
                            foreach ($approvedModelsList as $am) {
                                if ($am['id'] === $mId) { $found = $am; break; }
                            }
                            $desc = $found ? $found['desc'] : 'Model Cadangan';
                        ?>
                        <div class="cascade-node <?php if ($isPrimary) echo 'primary'; ?>">
                            <span class="node-tag <?php echo $isPrimary ? 'tag-primary' : 'tag-fallback'; ?>">
                                <?php echo $isPrimary ? 'Tingkat 1 • Utama' : ('Tingkat ' . ($idx + 1) . ' • Fallback'); ?>
                            </span>
                            <div class="node-title"><?php echo htmlspecialchars($mId); ?></div>
                            <div class="node-desc"><?php echo $isPrimary ? 'Eksekusi awal (Retry 2x jika 503)' : htmlspecialchars($desc) . ' (Retry 2x)'; ?></div>
                        </div>
                        <div class="cascade-connector">
                            <span class="connector-badge">503 / 404</span>
                            <i class="material-icons connector-arrow">arrow_forward</i>
                        </div>
                        <?php endforeach; ?>
                        <!-- ROTATION NODE -->
                        <div class="cascade-node" style="border-color:var(--pp-orange);">
                            <span class="node-tag tag-rotation">Rotasi Key</span>
                            <div class="node-title">Next Pool Key</div>
                            <div class="node-desc">Ganti akun API Key instan (429 Limit)</div>
                        </div>
                    </div>
                </div>

                <div class="resilience-notice">
                    <i class="material-icons">security</i>
                    <div>
                        <strong>Standar Ketahanan Pipeline Airfryer:</strong>
                        <ul>
                            <li><strong>Error 503 High Demand:</strong> Sistem menahan jeda eksponensial (2 detik & 4 detik) lalu melanjutkan ke model cadangan tanpa membatalkan batch resep.</li>
                            <li><strong>Error 404 Model Deprecated:</strong> Sistem langsung melompati model tersebut tanpa retry untuk menghemat waktu komputasi.</li>
                            <li><strong>Error 429 Rate Limit Exceeded:</strong> Kunci ditandai <em>Cooldown</em> dan permintaan berikutnya otomatis dialihkan ke API Key lain dalam pool.</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- SECTION 3 & 4: RENDERER & HOSTING SYNC (2 COLUMNS) -->
            <div class="two-col-grid">
                
                <!-- G-LABS / LOCAL GPU CONFIG -->
                <section class="config-card" style="margin-bottom:0;">
                    <div class="config-card-header">
                        <div class="config-card-header-left">
                            <div class="config-header-icon icon-glabs">
                                <i class="material-icons">router</i>
                            </div>
                            <div class="config-card-title">
                                <h2>3. G-Labs Image Renderer</h2>
                                <p>Koneksi ke GPU Mac Mini lokal &amp; autentikasi API.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-brand-outline" onclick="testGlabsConnection(event)">
                            <i class="material-icons" style="font-size:14px;">cable</i> Tes Koneksi
                        </button>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <div class="form-group">
                            <label class="form-label" for="glabs-host">Host / Endpoint URL:</label>
                            <input type="text" id="glabs-host" class="form-control" value="<?php echo htmlspecialchars($glabsHost); ?>" placeholder="127.0.0.1">
                            <span class="form-helper">IP lokal Mac Mini atau tunnel Cloudflare untuk render jarak jauh.</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="glabs-port">Port WebSocket / HTTP:</label>
                            <input type="number" id="glabs-port" class="form-control" value="<?php echo htmlspecialchars($glabsPort); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="glabs-api-key">
                                <span>G-Labs API Key:</span>
                                <span style="font-size:11px; color:#64748B; font-weight:normal;">(Wajib untuk autentikasi T2I &amp; I2V)</span>
                            </label>
                            <div class="key-input-wrapper">
                                <input type="password" id="glabs-api-key" class="form-control" value="<?php echo htmlspecialchars($glabsApiKey); ?>" placeholder="Masukkan API Key G-Labs...">
                                <button type="button" class="key-toggle-btn" onclick="toggleTokenVisibility('glabs-api-key', this)" title="Tampilkan/Sembunyikan">
                                    <i class="material-icons" style="font-size:18px;">visibility</i>
                                </button>
                            </div>
                            <span class="form-helper">Disertakan otomatis via header <code>X-API-Key</code> saat mengirimkan task render foto &amp; video.</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="glabs-model">Model Image Render Default:</label>
                            <select class="form-control" id="glabs-model">
                                <option value="nano_banana_2" <?php if ($glabsModel === 'nano_banana_2') echo 'selected'; ?>>nano_banana_2 (Cepat, Konsisten &amp; Realistis)</option>
                                <option value="nano_banana_pro" <?php if ($glabsModel === 'nano_banana_pro') echo 'selected'; ?>>nano_banana_pro (Ultra Detail Gourmet Lighting)</option>
                                <option value="nano_pics" <?php if ($glabsModel === 'nano_pics') echo 'selected'; ?>>nano_pics (Google Pics Diffusion)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="default-style">Default Preset Fotografi:</label>
                            <select class="form-control" id="default-style">
                                <option value="Modern Fine Dining" <?php if ($defaultStyle === 'Modern Fine Dining') echo 'selected'; ?>>Modern Fine Dining (Piring slate abu-abu, rim lighting)</option>
                                <option value="Rustic Kitchen Table" <?php if ($defaultStyle === 'Rustic Kitchen Table') echo 'selected'; ?>>Rustic Kitchen Table (Alas kayu, rempah alami)</option>
                                <option value="Crispy Macro Close-Up" <?php if ($defaultStyle === 'Crispy Macro Close-Up') echo 'selected'; ?>>Crispy Macro Close-Up (Sudut 45°, tekstur renyah)</option>
                                <option value="Top-Down Flatlay" <?php if ($defaultStyle === 'Top-Down Flatlay') echo 'selected'; ?>>Top-Down Flatlay (Simetris meja marmer putih)</option>
                            </select>
                        </div>
                    </div>
                </section>

                <!-- SECTION 4: SINKRONISASI KE WEB HOSTING LIVE -->
                <section class="config-card" style="margin-bottom:0;">
                    <div class="config-card-header">
                        <div class="config-card-header-left">
                            <div class="config-header-icon" style="background:var(--pp-teal-soft); color:var(--pp-teal);">
                                <i class="material-icons">cloud_sync</i>
                            </div>
                            <div class="config-card-title">
                                <h2>4. Sinkronisasi ke Web Hosting Live</h2>
                                <p>Mekanisme 1-Click Push untuk publikasi ke server hosting live.</p>
                            </div>
                        </div>
                        <div class="config-card-actions">
                            <button type="button" class="btn-brand-outline" onclick="testLiveSyncConnection()">
                                <i class="material-icons" style="font-size:14px;">network_check</i> Uji Koneksi
                            </button>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <!-- Target URL -->
                        <div class="form-group">
                            <label class="form-label" for="sync-target-url">
                                <span>Target URL Receiver Hosting:</span>
                                <span style="font-size:11px; color:var(--pp-teal); font-weight:normal;">(Domain Server Live)</span>
                            </label>
                            <input type="text" id="sync-target-url" class="form-control" value="<?php echo htmlspecialchars($syncTargetUrl); ?>" placeholder="https://domain-anda.com/api_sync_recipe.php" style="font-family:monospace; font-size:13px;">
                            <span class="form-helper">Alamat file receiver endpoint pada live web server (misal: <code>https://api.airfry.com/api_sync_recipe.php</code>).</span>
                        </div>

                        <!-- Secret Bearer Token -->
                        <div class="form-group">
                            <label class="form-label" for="sync-secret-token">
                                <span>Secret Bearer Token (Autentikasi Aman):</span>
                                <span style="font-size:11px; color:#64748B; font-weight:normal;">Wajib sama antara Mac Mini &amp; Server Live</span>
                            </label>
                            <div style="display:flex; gap:10px;">
                                <div class="key-input-wrapper" style="flex:1;">
                                    <input type="password" id="sync-secret-token" class="form-control" value="<?php echo htmlspecialchars($syncSecretToken); ?>" placeholder="Token rahasia sinkronisasi...">
                                    <button type="button" class="key-toggle-btn" onclick="toggleTokenVisibility('sync-secret-token', this)" title="Tampilkan/Sembunyikan">
                                        <i class="material-icons" style="font-size:18px;">visibility</i>
                                    </button>
                                </div>
                                <button type="button" class="btn-secondary" onclick="generateRandomToken()" title="Buat Token Acak Baru" style="white-space:nowrap;">
                                    <i class="material-icons" style="font-size:16px; color:var(--pp-teal);">autorenew</i>
                                    <span>Token Acak</span>
                                </button>
                            </div>
                            <span class="form-helper">Disertakan via header HTTP <code>Authorization: Bearer &lt;token&gt;</code> saat sinkronisasi.</span>
                        </div>

                        <!-- Live Sync Status Banner -->
                        <div id="sync-status-box" style="display:none; padding:12px 16px; border-radius:8px; font-size:13px;"></div>
                    </div>
                </section>

                <!-- SECTION 5: CLOUDFLARE R2 OBJECT STORAGE -->
                <section class="config-card" id="sec-cloudflare-r2" style="margin-top:24px; margin-bottom:0;">
                    <div class="config-card-header">
                        <div class="config-card-header-left">
                            <div class="config-header-icon" style="background:rgba(249, 115, 22, 0.12); color:#F97316;">
                                <i class="material-icons">cloud_upload</i>
                            </div>
                            <div class="config-card-title">
                                <h2>5. Cloudflare R2 Object Storage (Penyimpanan Video)</h2>
                                <p>Simpan dan streaming video vertikal 9:16 hasil FFmpeg ke CDN Cloudflare R2 tanpa biaya egress.</p>
                            </div>
                        </div>
                        <div class="config-card-actions">
                            <button type="button" class="btn-brand-outline" onclick="testR2Connection()" id="btn-test-r2">
                                <i class="material-icons" style="font-size:14px;">network_check</i> Uji Koneksi R2
                            </button>
                            <button type="button" class="btn-brand-primary" onclick="saveR2Configuration()" id="btn-save-r2" style="padding:8px 16px; font-size:12.5px;">
                                <i class="material-icons" style="font-size:15px;">save</i> Simpan Pengaturan R2
                            </button>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <!-- Toggle Switch -->
                        <div style="display:flex; align-items:center; justify-content:space-between; background:#F8FAFC; padding:14px 18px; border-radius:10px; border:1px solid #E2E8F0;">
                            <div>
                                <label for="r2-enabled" style="font-weight:700; color:#1E293B; margin-bottom:2px; display:block; cursor:pointer;">
                                    Aktifkan Penyimpanan Video di Cloudflare R2
                                </label>
                                <span style="font-size:12px; color:#64748B;">
                                    Saat aktif, video hasil stitching di Video Studio dapat langsung diterbitkan ke CDN publik Cloudflare R2.
                                </span>
                            </div>
                            <div class="form-check form-switch" style="padding-left:0; margin:0;">
                                <input class="form-check-input" type="checkbox" id="r2-enabled" style="width:44px; height:22px; cursor:pointer;" <?php echo ($r2Enabled ? 'checked' : ''); ?>>
                            </div>
                        </div>

                        <!-- 2 columns for Account ID & Bucket Name -->
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="r2-account-id">
                                    <span>Cloudflare Account ID:</span>
                                    <span style="font-size:11px; color:#64748B; font-weight:normal;">(Dashboard Cloudflare &gt; R2)</span>
                                </label>
                                <input type="text" id="r2-account-id" class="form-control" value="<?php echo htmlspecialchars($r2AccountId); ?>" placeholder="Contoh: 9f8e7d6c5b4a321..." style="font-family:monospace; font-size:13px;">
                                <span class="form-helper">ID Akun 32-karakter heksadesimal dari Cloudflare Dashboard.</span>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="r2-bucket-name">
                                    <span>Nama Bucket R2:</span>
                                    <span style="font-size:11px; color:#64748B; font-weight:normal;">(Wajib dibuat di R2)</span>
                                </label>
                                <input type="text" id="r2-bucket-name" class="form-control" value="<?php echo htmlspecialchars($r2BucketName); ?>" placeholder="Contoh: airfryer-recipes-video" style="font-family:monospace; font-size:13px;">
                                <span class="form-helper">Nama bucket tempat menyimpan MP4 video resep.</span>
                            </div>
                        </div>

                        <!-- 2 columns for Access Key ID & Secret Access Key -->
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="r2-access-key-id">
                                    <span>R2 Access Key ID:</span>
                                    <span style="font-size:11px; color:#64748B; font-weight:normal;">(R2 API Tokens)</span>
                                </label>
                                <input type="text" id="r2-access-key-id" class="form-control" value="<?php echo htmlspecialchars($r2AccessKeyId); ?>" placeholder="Access Key ID S3..." style="font-family:monospace; font-size:13px;">
                                <span class="form-helper">Dibuat dari menu "Manage R2 API Tokens" dengan izin Admin Read &amp; Write.</span>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="r2-secret-access-key">
                                    <span>R2 Secret Access Key:</span>
                                    <span style="font-size:11px; color:#64748B; font-weight:normal;">(Rahasia)</span>
                                </label>
                                <div class="key-input-wrapper">
                                    <input type="password" id="r2-secret-access-key" class="form-control" value="<?php echo htmlspecialchars($r2SecretAccessKey); ?>" placeholder="Secret Access Key S3..." style="font-family:monospace; font-size:13px;">
                                    <button type="button" class="key-toggle-btn" onclick="toggleTokenVisibility('r2-secret-access-key', this)" title="Tampilkan/Sembunyikan">
                                        <i class="material-icons" style="font-size:18px;">visibility</i>
                                    </button>
                                </div>
                                <span class="form-helper">Digunakan untuk penandatanganan kriptografis AWS SigV4 (HMAC-SHA256).</span>
                            </div>
                        </div>

                        <!-- Public CDN Domain -->
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="r2-public-domain">
                                <span>Public Domain / Custom CDN URL:</span>
                                <span style="font-size:11px; color:#64748B; font-weight:normal;">(Opsional jika r2.dev diaktifkan)</span>
                            </label>
                            <input type="text" id="r2-public-domain" class="form-control" value="<?php echo htmlspecialchars($r2PublicDomain); ?>" placeholder="https://pub-xxxx.r2.dev atau https://cdn.domainanda.com" style="font-family:monospace; font-size:13px;">
                            <span class="form-helper">Domain publik untuk streaming video di aplikasi mobile / frontend (tanpa slash di akhir).</span>
                        </div>

                        <!-- R2 Status Banner -->
                        <div id="r2-status-box" style="display:none; padding:12px 16px; border-radius:8px; font-size:13px; line-height:1.5;"></div>

                        <!-- Info Callout -->
                        <div style="background:rgba(249, 115, 22, 0.05); border:1px solid rgba(249, 115, 22, 0.2); border-radius:8px; padding:14px 18px; font-size:12.5px; color:#9A3412; line-height:1.5;">
                            <strong style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <i class="material-icons" style="font-size:16px;">verified</i> Keunggulan Cloudflare R2 untuk Video Resep:
                            </strong>
                            <ul style="margin:4px 0 0 18px; padding:0;">
                                <li><strong>Bebas Biaya Egress (Zero Egress Fees):</strong> Bandwidth video streaming tidak dikenakan biaya transfer keluar.</li>
                                <li><strong>CDN Global:</strong> Video diputar instan dengan latensi rendah di seluruh jaringan edge Cloudflare.</li>
                                <li><strong>Kompatibel S3:</strong> Terhubung otomatis menggunakan native PHP SigV4 tanpa paket pihak ketiga.</li>
                            </ul>
                        </div>
                    </div>
                </section>

            </div>

            <!-- SECTION 6: MINIMAX TEXT-TO-SPEECH (TTS) VOICEOVER ENGINE -->
            <section class="config-card" id="sec-minimax-tts" style="margin-top:24px; margin-bottom:0;">
                <div class="config-card-header">
                    <div class="config-card-header-left">
                        <div class="config-header-icon" style="background:rgba(16, 185, 129, 0.12); color:#10B981;">
                            <i class="material-icons">record_voice_over</i>
                        </div>
                        <div class="config-card-title">
                            <h2>6. MiniMax AI Voiceover Engine (Text-to-Speech)</h2>
                            <p>Sintesis suara narator profesional multibahasa (ID &amp; EN) dengan failover gateway otomatis.</p>
                        </div>
                    </div>
                    <div class="config-card-actions">
                        <button type="button" class="btn-brand-outline" onclick="testMiniMaxVoiceConnection()" id="btn-test-minimax">
                            <i class="material-icons" style="font-size:14px;">play_circle</i> Uji Koneksi &amp; Suara
                        </button>
                        <button type="button" class="btn-brand-primary" onclick="saveMiniMaxConfiguration()" id="btn-save-minimax" style="padding:8px 16px; font-size:12.5px;">
                            <i class="material-icons" style="font-size:15px;">save</i> Simpan Pengaturan TTS
                        </button>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:20px;">
                    <!-- API Key & Group ID -->
                    <div class="row clearfix" style="margin-bottom:0;">
                        <div class="col-sm-8 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="minimax-api-key">
                                    <span>MiniMax API Key:</span>
                                    <span style="font-size:11px; color:#64748B; font-weight:normal;">(Wajib untuk akses API t2a_v2)</span>
                                </label>
                                <div class="key-input-wrapper">
                                    <input type="password" id="minimax-api-key" class="form-control" value="<?php echo htmlspecialchars($minimaxApiKey); ?>" placeholder="Masukkan API Key MiniMax Anda...">
                                    <button type="button" class="key-toggle-btn" onclick="toggleTokenVisibility('minimax-api-key', this)" title="Tampilkan/Sembunyikan">
                                        <i class="material-icons" style="font-size:18px;">visibility</i>
                                    </button>
                                </div>
                                <span class="form-helper">Kunci API dari dasbor pengembang MiniMax AI (platform.minimaxi.com atau api.minimax.io).</span>
                            </div>
                        </div>
                        <div class="col-sm-4 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="minimax-group-id">
                                    <span>Group ID (Opsional):</span>
                                </label>
                                <input type="text" id="minimax-group-id" class="form-control" value="<?php echo htmlspecialchars($minimaxGroupId); ?>" placeholder="Contoh: 1827493...">
                                <span class="form-helper">Disertakan jika akun Anda menggunakan isolasi group/organization.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Model, Language, Default Voice Persona -->
                    <div class="row clearfix" style="margin-bottom:0;">
                        <div class="col-sm-4 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="minimax-model">Model Engine TTS:</label>
                                <select class="form-control" id="minimax-model">
                                    <option value="speech-2.8-turbo" <?php if ($minimaxModel === 'speech-2.8-turbo') echo 'selected'; ?>>speech-2.8-turbo (Rekomendasi &mdash; Cepat &amp; Alami)</option>
                                    <option value="speech-02-turbo" <?php if ($minimaxModel === 'speech-02-turbo') echo 'selected'; ?>>speech-02-turbo (Model Ringan)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-4 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="minimax-default-lang">Default Bahasa:</label>
                                <select class="form-control" id="minimax-default-lang" onchange="onMiniMaxLanguageChange(this.value)">
                                    <option value="id-ID" <?php if ($minimaxDefaultLang === 'id-ID') echo 'selected'; ?>>Bahasa Indonesia (id-ID)</option>
                                    <option value="en-US" <?php if ($minimaxDefaultLang === 'en-US') echo 'selected'; ?>>English (en-US)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-4 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="minimax-default-voice">Default Voice Persona:</label>
                                <select class="form-control" id="minimax-default-voice">
                                    <!-- Populated dynamically via JS -->
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Speed & Volume Sliders -->
                    <div class="row clearfix" style="margin-bottom:0;">
                        <div class="col-sm-6 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <label class="form-label" style="margin:0;">Kecepatan Bicara (Speed):</label>
                                    <span id="txt-minimax-speed" style="font-size:12px; font-weight:700; color:#10B981;"><?php echo number_format($minimaxDefaultSpeed, 2); ?>x</span>
                                </div>
                                <input type="range" id="minimax-default-speed" min="0.5" max="2.0" step="0.05" value="<?php echo $minimaxDefaultSpeed; ?>" class="form-range" style="width:100%; accent-color:#10B981;" oninput="document.getElementById('txt-minimax-speed').textContent = parseFloat(this.value).toFixed(2) + 'x';">
                                <span class="form-helper">Rentang: 0.5x (Lambat) &mdash; 1.0x (Normal) &mdash; 2.0x (Cepat).</span>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xs-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <label class="form-label" style="margin:0;">Volume Suara:</label>
                                    <span id="txt-minimax-volume" style="font-size:12px; font-weight:700; color:#10B981;"><?php echo number_format($minimaxDefaultVolume, 2); ?>x</span>
                                </div>
                                <input type="range" id="minimax-default-volume" min="0.2" max="2.0" step="0.05" value="<?php echo $minimaxDefaultVolume; ?>" class="form-range" style="width:100%; accent-color:#10B981;" oninput="document.getElementById('txt-minimax-volume').textContent = parseFloat(this.value).toFixed(2) + 'x';">
                                <span class="form-helper">Rentang volume audio narator (default 1.00x).</span>
                            </div>
                        </div>
                    </div>

                    <!-- LIVE TEST SOUND BOX -->
                    <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <strong style="font-size:13px; color:#0F172A; display:flex; align-items:center; gap:6px;">
                                <i class="material-icons" style="font-size:16px; color:#10B981;">volume_up</i>
                                Uji Suara Sampel MiniMax (Audio Sandbox):
                            </strong>
                            <span style="font-size:11px; background:#DCFCE7; color:#15803D; font-weight:700; padding:2px 8px; border-radius:12px;">Instant Player</span>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="text" id="minimax-test-text" class="form-control" value="Halo, ini uji coba suara narator AI MiniMax untuk resep masakan air fryer." style="font-size:13px;">
                            <button type="button" class="btn-brand-primary" onclick="testMiniMaxVoiceConnection()" style="white-space:nowrap; padding:8px 16px;">
                                <i class="material-icons" style="font-size:16px;">play_arrow</i> Uji Suara
                            </button>
                        </div>
                        <div id="minimax-test-player-wrap" style="margin-top:12px; display:none;">
                            <audio id="minimax-test-audio-player" controls style="width:100%; height:36px; border-radius:6px;"></audio>
                            <div id="minimax-test-meta" style="font-size:11px; color:#64748B; margin-top:4px;"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Bottom Floating Action Bar -->
            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px; margin-bottom:40px;">
                <a href="ai-generator.php" class="btn-secondary">Batal / Kembali</a>
                <button type="button" class="btn-brand-primary" onclick="saveAllConfigurations()" style="padding:12px 28px; font-size:14px;">
                    <i class="material-icons" style="font-size:18px;">save</i> Simpan Seluruh Konfigurasi
                </button>
            </div>

        </div>
    </section>

    <!-- MODAL TAMBAH API KEY -->
    <div class="modal-overlay" id="addKeyModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="material-icons" style="color:var(--pp-teal);">key</i> Tambah API Key Google Gemini
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeAddKeyModal()">
                    <i class="material-icons">close</i>
                </button>
            </div>
            <div class="modal-body">
                <form id="form-add-key" onsubmit="submitNewKey(event)">
                    <div class="form-group">
                        <label class="form-label" for="input-key-label">Nama Label Kunci:</label>
                        <input type="text" id="input-key-label" class="form-control" placeholder="Contoh: Gemini Primary #1, Workspace Pro" required>
                        <span class="form-helper">Nama identifikasi akun Google AI Studio Anda.</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="input-api-key">Google Gemini API Key:</label>
                        <div class="key-input-wrapper">
                            <input type="password" id="input-api-key" class="form-control" placeholder="AIzaSy..." required>
                            <button type="button" class="key-toggle-btn" onclick="toggleTokenVisibility('input-api-key', this)">
                                <i class="material-icons" style="font-size:18px;">visibility</i>
                            </button>
                        </div>
                        <span class="form-helper">Dapatkan kunci gratis dari <a href="https://aistudio.google.com/" target="_blank" style="color:var(--pp-teal); text-decoration:underline;">Google AI Studio</a>.</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="input-rpd-limit">Batas Kuota Harian (RPD - Request Per Day):</label>
                        <input type="number" id="input-rpd-limit" class="form-control" value="20" min="1" max="10000">
                        <span class="form-helper">Standar Free Tier Google AI Studio: 20 RPD per key.</span>
                    </div>

                    <div id="modal-test-status" style="display:none; padding:10px 14px; border-radius:8px; font-size:12.5px; margin-bottom:16px;"></div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                        <button type="button" class="btn-brand-outline" onclick="validateKeyBeforeAdd()">
                            <i class="material-icons" style="font-size:15px;">network_check</i> Uji Validasi Kunci
                        </button>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="btn-secondary" onclick="closeAddKeyModal()">Batal</button>
                            <button type="submit" class="btn-brand-primary" id="btn-save-key">
                                <i class="material-icons" style="font-size:16px;">add</i> Simpan ke Pool
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/plugins/bootstrap/js/bootstrap.js"></script>
    <script src="assets/js/admin.js"></script>

    <script>
    // Visibility toggle helper
    function toggleTokenVisibility(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon = btn.querySelector('.material-icons');
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility_off';
        } else {
            input.type = 'password';
            icon.textContent = 'visibility';
        }
    }

    // Copy to clipboard helper
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('API Key berhasil disalin ke clipboard!');
        });
    }

    // Modal controls
    function openAddKeyModal() {
        document.getElementById('input-key-label').value = '';
        document.getElementById('input-api-key').value = '';
        document.getElementById('input-rpd-limit').value = '20';
        document.getElementById('modal-test-status').style.display = 'none';
        document.getElementById('addKeyModal').classList.add('active');
    }

    function closeAddKeyModal() {
        document.getElementById('addKeyModal').classList.remove('active');
    }

    // Dynamic Cascade Visualizer
    var APPROVED_MODELS = [
        { id: 'gemini-2.5-flash', label: 'gemini-2.5-flash', desc: 'Rekomendasi Utama — Cerdas & Cepat' },
        { id: 'gemini-3.6-flash', label: 'gemini-3.6-flash', desc: 'Sangat Stabil & Ringan' },
        { id: 'gemini-3.7-flash', label: 'gemini-3.7-flash', desc: 'Multimodal Cerdas' },
        { id: 'gemini-3.8-flash', label: 'gemini-3.8-flash', desc: 'Generasi Terkini' },
        { id: 'gemini-flash-latest', label: 'gemini-flash-latest', desc: 'Auto-update Versi Stabil Google' }
    ];

    function changePrimaryModel(val) {
        renderCascadeSteps(val);
    }

    function renderCascadeSteps(primary) {
        var container = document.getElementById('cascade-steps-container');
        if (!container) return;

        var chain = [primary];
        APPROVED_MODELS.forEach(function(m) {
            if (m.id !== primary && chain.indexOf(m.id) === -1) {
                chain.push(m.id);
            }
        });

        var html = '';
        chain.forEach(function(modelId, idx) {
            var modelObj = APPROVED_MODELS.find(function(m) { return m.id === modelId; }) || { id: modelId, label: modelId, desc: 'Model Cadangan' };
            var isPrimary = (idx === 0);
            var tagClass = isPrimary ? 'tag-primary' : 'tag-fallback';
            var tagText = isPrimary ? 'Tingkat 1 • Utama' : ('Tingkat ' + (idx + 1) + ' • Fallback');
            var nodeClass = isPrimary ? 'cascade-node primary' : 'cascade-node';
            var note = isPrimary ? 'Eksekusi awal (Retry 2x jika 503)' : (modelObj.desc + ' (Retry 2x)');

            html += '<div class="' + nodeClass + '">';
            html += '  <span class="node-tag ' + tagClass + '">' + tagText + '</span>';
            html += '  <div class="node-title">' + modelObj.label + '</div>';
            html += '  <div class="node-desc">' + note + '</div>';
            html += '</div>';

            html += '<div class="cascade-connector">';
            html += '  <span class="connector-badge">503 / 404</span>';
            html += '  <i class="material-icons connector-arrow">arrow_forward</i>';
            html += '</div>';
        });

        // Key Rotation node
        html += '<div class="cascade-node" style="border-color:var(--pp-orange);">';
        html += '  <span class="node-tag tag-rotation">Rotasi Key</span>';
        html += '  <div class="node-title">Next Pool Key</div>';
        html += '  <div class="node-desc">Ganti akun API Key instan (429 Limit)</div>';
        html += '</div>';

        container.innerHTML = html;
    }

    // Generate random secret token (64 hex characters)
    function generateRandomToken() {
        var array = new Uint8Array(32);
        window.crypto.getRandomValues(array);
        var hex = Array.from(array, function(b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
        document.getElementById('sync-secret-token').value = 'pp_sync_sec_' + hex;
        alert('Bearer Token acak berhasil dibuat!');
    }

    // Validate key via AJAX before adding
    function validateKeyBeforeAdd() {
        var apiKey = document.getElementById('input-api-key').value.trim();
        var statusBox = document.getElementById('modal-test-status');

        if (!apiKey) {
            alert('Masukkan API Key terlebih dahulu.');
            return;
        }

        statusBox.style.display = 'block';
        statusBox.style.background = '#EFF6FF';
        statusBox.style.color = '#1D4ED8';
        statusBox.style.border = '1px solid #BFDBFE';
        statusBox.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">sync</i> Menghubungi Google AI Studio...';

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'test_key', api_key: apiKey },
            success: function(resp) {
                if (resp && resp.success) {
                    statusBox.style.background = '#D1FAE5';
                    statusBox.style.color = '#047857';
                    statusBox.style.border = '1px solid #A7F3D0';
                    statusBox.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">check_circle</i> <strong>Valid & Aktif!</strong> Latensi: ' + (resp.latency_ms || 280) + 'ms';
                } else {
                    statusBox.style.background = '#FEE2E2';
                    statusBox.style.color = '#DC2626';
                    statusBox.style.border = '1px solid #FCA5A5';
                    statusBox.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">error</i> ' + (resp.error || 'Validasi gagal.');
                }
            },
            error: function() {
                statusBox.style.background = '#FEE2E2';
                statusBox.style.color = '#DC2626';
                statusBox.style.border = '1px solid #FCA5A5';
                statusBox.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">error</i> Gagal menghubungi server lokal.';
            }
        });
    }

    // Submit new key to pool
    function submitNewKey(e) {
        e.preventDefault();
        var label = document.getElementById('input-key-label').value.trim();
        var apiKey = document.getElementById('input-api-key').value.trim();
        var rpd = document.getElementById('input-rpd-limit').value;
        var btn = document.getElementById('btn-save-key');

        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px;">sync</i> Memvalidasi...';

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'add_key',
                key_name: label,
                api_key: apiKey,
                rpd_limit: rpd
            },
            success: function(resp) {
                btn.disabled = false;
                btn.innerHTML = '<i class="material-icons" style="font-size:16px;">add</i> Simpan ke Pool';
                if (resp && resp.success) {
                    alert(resp.message || 'Kunci berhasil ditambahkan ke pool!');
                    closeAddKeyModal();
                    location.reload();
                } else {
                    alert('Gagal: ' + (resp.error || 'Terjadi kesalahan.'));
                }
            },
            error: function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="material-icons" style="font-size:16px;">add</i> Simpan ke Pool';
                alert('Koneksi AJAX gagal.');
            }
        });
    }

    // Test a single key from table
    function testPoolKey(id, apiKey) {
        var btn = document.getElementById('btn-test-key-' + id);
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; animation:spin 1s infinite linear;">sync</i>';
        }

        var badge = document.getElementById('status-badge-' + id);

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'test_key', key_id: id, api_key: apiKey },
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    if (badge) {
                        badge.className = 'status-badge healthy';
                        badge.innerHTML = '<i class="material-icons" style="font-size:12px;">check_circle</i> Aktif (' + (resp.latency_ms || 250) + 'ms)';
                    }
                    alert('Kunci ID #' + id + ' Valid & Aktif!\n' +
                          '• Status Google: ' + (resp.message || 'Terhubung') + '\n' +
                          '• Latensi Riil: ' + (resp.latency_ms || 0) + ' ms\n' +
                          '• Status Database: ACTIVE (Disimpan)');
                } else {
                    if (badge) {
                        badge.className = 'status-badge standby';
                        badge.innerHTML = '<i class="material-icons" style="font-size:12px;">error</i> Invalid';
                    }
                    alert('Kunci ID #' + id + ' GAGAL / INVALID:\n' + (resp.error || 'Tidak dapat terhubung ke Google AI Studio.') + '\nStatus Database: INVALID (Ditandai)');
                }
            },
            error: function() {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                alert('Gagal menghubungi server lokal.');
            }
        });
    }

    // Test all keys in the pool sequentially
    function testAllPoolKeys(btnEl) {
        var btn = btnEl || document.getElementById('btn-test-all-keys');
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; animation:spin 1s infinite linear;">sync</i> Menguji Pool...';
        }

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'test_all_keys' },
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    if (resp.details && resp.details.length) {
                        resp.details.forEach(function(item) {
                            var b = document.getElementById('status-badge-' + item.id);
                            if (b) {
                                if (item.success) {
                                    b.className = 'status-badge healthy';
                                    b.innerHTML = '<i class="material-icons" style="font-size:12px;">check_circle</i> Aktif (' + item.latency_ms + 'ms)';
                                } else {
                                    b.className = 'status-badge standby';
                                    b.innerHTML = '<i class="material-icons" style="font-size:12px;">error</i> Invalid';
                                }
                            }
                        });
                    }
                    alert('Hasil Pengujian Pool API Key Gemini:\n' +
                          '✓ ' + resp.valid_count + ' dari ' + resp.total + ' kunci VALID & AKTIF!\n' +
                          'Seluruh status dan latensi telah diperbarui secara otomatis di database.');
                } else {
                    alert('Gagal menguji pool: ' + (resp.error || 'Terjadi kesalahan sistem.'));
                }
            },
            error: function() {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                alert('Gagal menghubungi server pengujian pool.');
            }
        });
    }

    // Delete a key
    function deletePoolKey(id) {
        if (!confirm('Yakin ingin menghapus kunci ID #' + id + ' dari pool?')) return;
        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_key', id: id },
            success: function(resp) {
                if (resp && resp.success) {
                    $('#key-row-' + id).fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert('Gagal: ' + (resp.error || 'Tidak dapat menghapus.'));
                }
            }
        });
    }

    // Test G-Labs GPU
    function testGlabsConnection(e) {
        var host = document.getElementById('glabs-host').value.trim();
        var port = document.getElementById('glabs-port').value.trim();
        var apiKey = document.getElementById('glabs-api-key') ? document.getElementById('glabs-api-key').value.trim() : '';
        
        var btn = e ? (e.currentTarget || e.target) : null;
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:14px; animation:spin 1s infinite linear;">sync</i> Menghubungi...';
        }

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'test_glabs', host: host, port: port, api_key: apiKey },
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    alert('Sukses Terhubung ke G-Labs!\n' + (resp.message || 'Server G-Labs aktif di ' + host + ':' + port));
                } else {
                    alert('Status Koneksi G-Labs:\n' + (resp.error || 'Tidak dapat terhubung ke G-Labs di ' + host + ':' + port + '. Pastikan aplikasi G-Labs desktop aktif.'));
                }
            },
            error: function() {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                alert('Gagal menghubungi endpoint G-Labs di ' + host + ':' + port);
            }
        });
    }

    // Test Live Sync
    function testLiveSyncConnection() {
        var box = document.getElementById('sync-status-box');
        box.style.display = 'block';
        box.style.background = '#EFF6FF';
        box.style.color = '#1D4ED8';
        box.style.border = '1px solid #BFDBFE';
        box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">sync</i> Melakukan handshake ke target URL receiver...';

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'test_sync' },
            success: function(resp) {
                if (resp && resp.success) {
                    box.style.background = '#D1FAE5';
                    box.style.color = '#047857';
                    box.style.border = '1px solid #A7F3D0';
                    box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">check_circle</i> <strong>Koneksi Berhasil!</strong> Receiver server live merespons dengan kode HTTP 200.';
                } else {
                    box.style.background = '#FFFBEB';
                    box.style.color = '#B45309';
                    box.style.border = '1px solid #FCD34D';
                    box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">info</i> Respons Server: ' + (resp.error || resp.message || 'Target URL belum aktif di hosting live (normal jika belum dideploy).');
                }
            },
            error: function() {
                box.style.background = '#FFFBEB';
                box.style.color = '#B45309';
                box.style.border = '1px solid #FCD34D';
                box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">info</i> Target URL receiver belum dapat dijangkau dari server lokal ini.';
            }
        });
    }

    // Test Cloudflare R2 Connection
    function testR2Connection(e) {
        var box = document.getElementById('r2-status-box');
        box.style.display = 'block';
        box.style.background = '#EFF6FF';
        box.style.color = '#1D4ED8';
        box.style.border = '1px solid #BFDBFE';
        box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom; animation:spin 1s infinite linear;">sync</i> Menghubungi Cloudflare R2 via AWS SigV4...';

        var btn = document.getElementById('btn-test-r2');
        if (btn) btn.disabled = true;

        var data = {
            action: 'test_r2_connection',
            r2_account_id: document.getElementById('r2-account-id').value.trim(),
            r2_access_key_id: document.getElementById('r2-access-key-id').value.trim(),
            r2_secret_access_key: document.getElementById('r2-secret-access-key').value.trim(),
            r2_bucket_name: document.getElementById('r2-bucket-name').value.trim(),
            r2_public_domain: document.getElementById('r2-public-domain').value.trim()
        };

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(resp) {
                if (btn) btn.disabled = false;
                if (resp && resp.success) {
                    box.style.background = '#D1FAE5';
                    box.style.color = '#047857';
                    box.style.border = '1px solid #A7F3D0';
                    var detailsHtml = '';
                    if (resp.details && resp.details.public_sample_url) {
                        detailsHtml = '<br><span style="font-size:11.5px; opacity:0.9;">CDN Sample URL: <code>' + resp.details.public_sample_url + '</code></span>';
                    }
                    box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">check_circle</i> <strong>Koneksi R2 Berhasil!</strong> ' + (resp.message || 'Bucket dan kredensial valid.') + detailsHtml;
                } else {
                    box.style.background = '#FEE2E2';
                    box.style.color = '#B91C1C';
                    box.style.border = '1px solid #FECACA';
                    box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">error</i> <strong>Koneksi R2 Gagal:</strong> ' + (resp.error || 'Tidak dapat terhubung ke bucket R2.');
                }
            },
            error: function(xhr, status, error) {
                if (btn) btn.disabled = false;
                box.style.background = '#FEE2E2';
                box.style.color = '#B91C1C';
                box.style.border = '1px solid #FECACA';
                var errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                box.innerHTML = '<i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">error</i> <strong>Koneksi Gagal:</strong> ' + errDetail;
            }
        });
    }

    // Save Cloudflare R2 Configuration Only
    function saveR2Configuration(e) {
        var btn = document.getElementById('btn-save-r2');
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:15px; animation:spin 1s infinite linear;">sync</i> Menyimpan...';
        }

        var data = {
            action: 'save_r2_config',
            r2_enabled: document.getElementById('r2-enabled').checked ? 1 : 0,
            r2_account_id: document.getElementById('r2-account-id').value.trim(),
            r2_access_key_id: document.getElementById('r2-access-key-id').value.trim(),
            r2_secret_access_key: document.getElementById('r2-secret-access-key').value.trim(),
            r2_bucket_name: document.getElementById('r2-bucket-name').value.trim(),
            r2_public_domain: document.getElementById('r2-public-domain').value.trim()
        };

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    alert(resp.message || 'Pengaturan Cloudflare R2 berhasil disimpan!');
                } else {
                    alert('Gagal: ' + (resp.error || 'Terjadi kesalahan saat menyimpan pengaturan R2.'));
                }
            },
            error: function(xhr, status, error) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                var errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                alert('Gagal menghubungi server: ' + errDetail);
            }
        });
    }

    // MiniMax Voice Catalog
    var MINIMAX_VOICES_ID = <?php echo json_encode(array_values(MiniMaxClient::VOICES_ID)); ?>;
    var MINIMAX_VOICES_EN = <?php echo json_encode(array_values(MiniMaxClient::VOICES_EN)); ?>;
    var SAVED_DEFAULT_VOICE = <?php echo json_encode($minimaxDefaultVoice); ?>;

    function populateMiniMaxVoices(lang, selectedVoice) {
        var sel = document.getElementById('minimax-default-voice');
        if (!sel) return;
        sel.innerHTML = '';
        var list = (lang === 'en-US' || (lang && lang.indexOf('en') === 0)) ? MINIMAX_VOICES_EN : MINIMAX_VOICES_ID;
        var targetVoice = selectedVoice || SAVED_DEFAULT_VOICE;

        list.forEach(function(v) {
            var opt = document.createElement('option');
            opt.value = v.id;
            opt.textContent = v.label;
            if (v.id === targetVoice) {
                opt.selected = true;
            }
            sel.appendChild(opt);
        });
    }

    function onMiniMaxLanguageChange(lang) {
        populateMiniMaxVoices(lang);
    }

    // Save MiniMax Configuration
    function saveMiniMaxConfiguration() {
        var btn = document.getElementById('btn-save-minimax');
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:15px; animation:spin 1s infinite linear;">sync</i> Menyimpan...';
        }

        var data = {
            action: 'save_minimax_config',
            minimax_api_key: document.getElementById('minimax-api-key').value.trim(),
            minimax_group_id: document.getElementById('minimax-group-id').value.trim(),
            minimax_model: document.getElementById('minimax-model').value,
            minimax_default_language: document.getElementById('minimax-default-lang').value,
            minimax_default_voice: document.getElementById('minimax-default-voice').value,
            minimax_default_speed: document.getElementById('minimax-default-speed').value,
            minimax_default_volume: document.getElementById('minimax-default-volume').value
        };

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    alert(resp.message || 'Pengaturan MiniMax TTS berhasil disimpan!');
                } else {
                    alert('Gagal: ' + (resp.error || 'Terjadi kesalahan saat menyimpan pengaturan MiniMax.'));
                }
            },
            error: function(xhr, status, error) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                var errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                alert('Gagal menghubungi server: ' + errDetail);
            }
        });
    }

    // Test MiniMax Voice Connection
    function testMiniMaxVoiceConnection() {
        var btn = document.getElementById('btn-test-minimax');
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:14px; animation:spin 1s infinite linear;">sync</i> Menghubungi MiniMax...';
        }

        var wrap = document.getElementById('minimax-test-player-wrap');
        var player = document.getElementById('minimax-test-audio-player');
        var meta = document.getElementById('minimax-test-meta');

        var data = {
            action: 'test_minimax_tts',
            minimax_api_key: document.getElementById('minimax-api-key').value.trim(),
            minimax_default_voice: document.getElementById('minimax-default-voice').value,
            sample_text: document.getElementById('minimax-test-text').value.trim()
        };

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    wrap.style.display = 'block';
                    player.src = resp.audio_url + '?t=' + Date.now();
                    player.play();
                    meta.innerHTML = '<span style="color:#059669; font-weight:700;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check_circle</i> Sukses!</span> ' +
                        resp.message + ' (Durasi: ' + resp.duration + ' detik, Voice: ' + resp.voice_persona + ')';
                } else {
                    wrap.style.display = 'block';
                    meta.innerHTML = '<span style="color:#DC2626; font-weight:700;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">error</i> Gagal:</span> ' +
                        (resp.error || 'Terjadi kesalahan saat menguji suara MiniMax.');
                }
            },
            error: function(xhr, status, error) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                var errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : error;
                wrap.style.display = 'block';
                meta.innerHTML = '<span style="color:#DC2626; font-weight:700;"><i class="material-icons" style="font-size:12px; vertical-align:middle;">error</i> Error:</span> ' + errDetail;
            }
        });
    }

    // Initialize voices on load
    $(document).ready(function() {
        var currentLang = document.getElementById('minimax-default-lang') ? document.getElementById('minimax-default-lang').value : 'id-ID';
        populateMiniMaxVoices(currentLang, SAVED_DEFAULT_VOICE);
    });

    // Save all configurations
    function saveAllConfigurations(e) {
        e = e || window.event;
        var data = {
            action: 'save_all_config',
            gemini_model: document.getElementById('select-primary-model').value,
            glabs_host: document.getElementById('glabs-host').value.trim(),
            glabs_port: document.getElementById('glabs-port').value.trim(),
            glabs_api_key: document.getElementById('glabs-api-key').value.trim(),
            glabs_model: document.getElementById('glabs-model').value,
            default_style: document.getElementById('default-style').value,
            sync_target_url: document.getElementById('sync-target-url').value.trim(),
            sync_secret_token: document.getElementById('sync-secret-token').value.trim(),
            r2_enabled: document.getElementById('r2-enabled') ? (document.getElementById('r2-enabled').checked ? 1 : 0) : 0,
            r2_account_id: document.getElementById('r2-account-id') ? document.getElementById('r2-account-id').value.trim() : '',
            r2_access_key_id: document.getElementById('r2-access-key-id') ? document.getElementById('r2-access-key-id').value.trim() : '',
            r2_secret_access_key: document.getElementById('r2-secret-access-key') ? document.getElementById('r2-secret-access-key').value.trim() : '',
            r2_bucket_name: document.getElementById('r2-bucket-name') ? document.getElementById('r2-bucket-name').value.trim() : '',
            r2_public_domain: document.getElementById('r2-public-domain') ? document.getElementById('r2-public-domain').value.trim() : '',
            minimax_api_key: document.getElementById('minimax-api-key') ? document.getElementById('minimax-api-key').value.trim() : '',
            minimax_group_id: document.getElementById('minimax-group-id') ? document.getElementById('minimax-group-id').value.trim() : '',
            minimax_model: document.getElementById('minimax-model') ? document.getElementById('minimax-model').value : 'speech-2.8-turbo',
            minimax_default_language: document.getElementById('minimax-default-lang') ? document.getElementById('minimax-default-lang').value : 'id-ID',
            minimax_default_voice: document.getElementById('minimax-default-voice') ? document.getElementById('minimax-default-voice').value : '',
            minimax_default_speed: document.getElementById('minimax-default-speed') ? document.getElementById('minimax-default-speed').value : '1.0',
            minimax_default_volume: document.getElementById('minimax-default-volume') ? document.getElementById('minimax-default-volume').value : '1.0'
        };

        var btn = e && e.target ? e.target.closest('button') : null;
        var oldHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">sync</i> Menyimpan...';
        }

        $.ajax({
            url: 'ai-config.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(resp) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                if (resp && resp.success) {
                    alert(resp.message || 'Seluruh konfigurasi berhasil disimpan!');
                } else {
                    alert('Gagal: ' + (resp.error || 'Terjadi kesalahan saat menyimpan.'));
                }
            },
            error: function(xhr, status, error) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
                var errDetail = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : (xhr.responseText ? xhr.responseText.substring(0, 150) : error);
                alert('Gagal menghubungi server: ' + errDetail);
            }
        });
    }
    </script>
</body>
</html>
