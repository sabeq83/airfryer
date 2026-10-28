<?php
/**
 * Airfryer Webadmin - AI Studio Hub
 * Central command center with rectangular configuration gateway card on top,
 * and 3 dedicated studio cards: Recipe Importer & AI Polish, Image Studio, and Video Studio.
 * 
 * Strict rule: All styling lives in assets/css/airfry-suite.css
 */

ob_start();
if (defined('E_DEPRECATED')) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/constant.php';
require_once 'includes/strings.php';
require_once 'functions.php';
require_once 'ai/AiBootstrap.php';
require_once 'ai/GeminiClient.php';
require_once 'ai/SyncClient.php';

// Session check
$currentTime = time() + 25200;
if (!isset($_SESSION['user']) || (isset($_SESSION['timeout']) && $currentTime > $_SESSION['timeout'])) {
    session_destroy();
    header("location:index.php");
    exit();
}
unset($_SESSION['timeout']);
$_SESSION['timeout'] = $currentTime + 86400;

// Ensure migrations are run safely
if (!Ai\AiBootstrap::isMigrated($connect)) {
    Ai\AiBootstrap::runAllMigrations($connect);
}

$geminiClient = new Ai\GeminiClient();
$syncClient = new Ai\SyncClient($connect);

// Load stats for Hub Gateway
$configRes = $connect->query("SELECT * FROM tbl_ai_config WHERE id = 1 LIMIT 1");
$aiConfig = ($configRes && $configRes->num_rows > 0) ? $configRes->fetch_assoc() : [];

$primaryModel = !empty($aiConfig['gemini_model']) ? trim($aiConfig['gemini_model']) : 'gemini-2.5-flash';
$glabsHost = $aiConfig['glabs_host'] ?? '100.64.70.61';
$glabsPort = $aiConfig['glabs_port'] ?? 8765;
$syncTargetUrl = $syncClient->getTargetUrl();

// Gemini Keys count
$keysList = $geminiClient->getAllKeys($connect);
$totalKeys = count($keysList);
$activeKeys = 0;
foreach ($keysList as $k) {
    if (!empty($k['is_active']) && $k['last_status'] === 'ACTIVE') {
        $activeKeys++;
    }
}

// Recipes count
$recipeCountRes = $connect->query("SELECT COUNT(*) as cnt FROM tbl_recipes");
$totalRecipes = ($recipeCountRes && $row = $recipeCountRes->fetch_assoc()) ? (int)$row['cnt'] : 0;

$username = $_SESSION['user'] ?? 'admin';
$sqlUser = "SELECT id, username, email FROM tbl_admin WHERE username = '$username'";
$userResult = $connect->query($sqlUser);
$data = ($userResult && $userResult->num_rows > 0) ? $userResult->fetch_assoc() : ['username' => 'Admin Airfry', 'email' => 'admin@airfry.internal', 'id' => 1];

include_once('includes/header.php');
?>

<section class="content">
    <div class="container-fluid">

        <!-- BREADCRUMB NAVIGATION -->
        <div class="page-breadcrumb">
            <i class="material-icons" style="font-size:16px; color:#F97316;">dashboard</i>
            <a href="dashboard.php">Dashboard</a>
            <span class="sep">/</span>
            <span class="active" style="color:var(--af-dark); font-weight:700;">AI Studio Hub</span>
        </div>

        <!-- HERO BANNER -->
        <div class="hero-banner">
            <div class="hero-tag">
                <i class="material-icons" style="font-size:14px;">auto_awesome</i> Airfry Content Suite • Engine v2.5
            </div>
            <h1 class="hero-title">Pusat Produksi Resep &amp; Media Visual Air Fryer</h1>
            <p class="hero-desc">
                Otomasi konten gourmet mulai dari resep terstruktur, rendering foto masakan fotorealistis menggunakan akselerasi GPU lokal Mac Mini, hingga sintesis video sinematik vertikal (9:16) untuk Reels, TikTok, dan YouTube Shorts.
            </p>
        </div>

        <!-- RECTANGULAR CONFIGURATION GATEWAY BANNER CARD -->
        <div class="config-gateway-card">
            <div class="config-card-left">
                <div class="config-icon-box">
                    <i class="material-icons">tune</i>
                </div>
                <div class="config-info">
                    <h3>
                        Konfigurasi AI Engine &amp; Multi-Key Failover
                        <span class="status-badge-ready">
                            <i class="material-icons" style="font-size:12px;">check_circle</i> Pool Kunci Siap (<?php echo $totalKeys; ?> Kunci)
                        </span>
                    </h3>
                    <p>
                        Pusat manajemen rotasi pool API Key Google Gemini, alur waterfall cascade fallback otomatis jika 429/503, endpoint GPU lokal Mac Mini (G-Labs), serta sinkronisasi instan ke Live Hosting.
                    </p>
                    <div class="config-chips">
                        <span class="config-chip">
                            <i class="material-icons">bolt</i> Primary: <strong><?php echo htmlspecialchars($primaryModel); ?></strong>
                        </span>
                        <span class="config-chip">
                            <i class="material-icons">vpn_key</i> <?php echo $activeKeys; ?> / <?php echo $totalKeys; ?> Kunci Aktif
                        </span>
                        <span class="config-chip">
                            <i class="material-icons">memory</i> GPU: <?php echo htmlspecialchars($glabsHost . ':' . $glabsPort); ?>
                        </span>
                        <span class="config-chip">
                            <i class="material-icons">cloud_done</i> Live Sync: <?php echo !empty($syncTargetUrl) ? htmlspecialchars($syncTargetUrl) : 'Terkoneksi'; ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="config-card-right">
                <a href="ai-config.php" class="btn-config-access" style="text-decoration:none;">
                    <span>Buka Konfigurasi Pool</span>
                    <i class="material-icons">arrow_forward</i>
                </a>
                <span style="font-size:11px; color:#94A3B8;">Pengaturan terpusat sistem</span>
            </div>
        </div>

        <!-- 3 STUDIO CARDS GRID -->
        <div class="studio-cards-grid">

            <!-- CARD 1: IMPORTER & AI POLISH -->
            <div class="studio-card">
                <div class="studio-card-top">
                    <div class="studio-card-icon icon-importer">
                        <i class="material-icons">cloud_download</i>
                    </div>
                    <span class="studio-card-badge badge-importer">Impor &amp; Polish</span>
                </div>
                <h3 class="studio-card-title">Recipe Importer &amp; AI Polish</h3>
                <p class="studio-card-desc">
                    Ambil resep dari URL website kuliner atau teks mentah, lalu poles secara cerdas dengan Gemini AI: restrukturisasi bahan, takaran gram/ml, langkah memasak, dan estimasi nutrisi akurat.
                </p>
                <ul class="studio-card-features">
                    <li><i class="material-icons">check_circle</i> JSON Restructuring dengan Gemini 2.5 Flash</li>
                    <li><i class="material-icons">check_circle</i> Pemisahan Bahan Baku &amp; Langkah Teratur</li>
                    <li><i class="material-icons">check_circle</i> Estimasi Suhu &amp; Waktu Panggang Air Fryer</li>
                </ul>
                <a href="importer.php" class="btn-card-launch" style="text-decoration:none;">
                    <span>Buka Menu Importer</span>
                    <i class="material-icons" style="font-size:16px;">arrow_forward</i>
                </a>
            </div>

            <!-- CARD 2: IMAGE STUDIO -->
            <div class="studio-card">
                <div class="studio-card-top">
                    <div class="studio-card-icon icon-image">
                        <i class="material-icons">photo_camera</i>
                    </div>
                    <span class="studio-card-badge badge-image">G-Labs GPU</span>
                </div>
                <h3 class="studio-card-title">Image Studio</h3>
                <p class="studio-card-desc">
                    Studio fotografi resep masakan otomatis dengan akselerasi GPU lokal Mac Mini (G-Labs). Menghasilkan pencahayaan fotorealistis bintang lima tanpa biaya per-gambar.
                </p>
                <ul class="studio-card-features">
                    <li><i class="material-icons">check_circle</i> Model GPU Lokal (Nano Banana / Flux)</li>
                    <li><i class="material-icons">check_circle</i> 6 Preset Gaya Fotografi Kuliner Airfryer</li>
                    <li><i class="material-icons">check_circle</i> 1-Click Push Sync ke Hosting Live</li>
                </ul>
                <a href="image-generator.php" class="btn-card-launch" style="text-decoration:none;">
                    <span>Buka Image Generator</span>
                    <i class="material-icons" style="font-size:16px;">arrow_forward</i>
                </a>
            </div>

            <!-- CARD 3: VIDEO STUDIO -->
            <div class="studio-card">
                <div class="studio-card-top">
                    <div class="studio-card-icon icon-video">
                        <i class="material-icons">videocam</i>
                    </div>
                    <span class="studio-card-badge badge-video">Format 9:16</span>
                </div>
                <h3 class="studio-card-title">Video Studio</h3>
                <p class="studio-card-desc">
                    Sintesis video sinematik vertikal (1080x1920) untuk YouTube Shorts, Instagram Reels, dan TikTok. Menggabungkan visual foto masakan, teks highlight langkah, dan audio tempo cepat.
                </p>
                <ul class="studio-card-features">
                    <li><i class="material-icons">check_circle</i> FFmpeg Engine Langsung di Server Mac Mini</li>
                    <li><i class="material-icons">check_circle</i> Format Vertikal 9:16 Otomatis dengan Logo Brand</li>
                    <li><i class="material-icons">check_circle</i> Siap Stream di ExoPlayer Android App</li>
                </ul>
                <a href="video-generator.php" class="btn-card-launch" style="text-decoration:none;">
                    <span>Buka Video Studio</span>
                    <i class="material-icons" style="font-size:16px;">arrow_forward</i>
                </a>
            </div>

        </div>

    </div>
</section>

<?php include_once('includes/footer.php'); ?>
