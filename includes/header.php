<?php include_once ('session.php'); ?>
<?php include_once ('config.php'); ?>
<?php include_once ('constant.php'); ?>
<?php include_once ('strings.php'); ?>
<?php include_once ('functions.php'); ?>



<?php

    $sqlSettings = "SELECT * FROM tbl_settings WHERE id = '1'";
    $resultSettings = $connect->query($sqlSettings);
    $settings_row = $resultSettings->fetch_assoc();

    $username = $_SESSION['user'];
    $sqlUser = "SELECT id, username, email FROM tbl_admin WHERE username = '$username'";
    $userResult = $connect->query($sqlUser);
    $data = $userResult->fetch_assoc();
            
?>

<!DOCTYPE html>
<html>
 
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=Edge">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <title><?php echo $app_name; ?></title>

    <?php include_once ('assets/css.min.php'); ?>

</head>

<body class="theme-teal">

    <?php
        $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?>
    <!-- TOP MOCKUP NAVIGATOR BAR -->
    <div class="mockup-switcher-bar">
        <div class="mockup-switcher-title">
            <i class="material-icons" style="color:var(--af-flame); font-size:18px;">local_fire_department</i>
            <span>AirFry Eats Webadmin</span>
            <span class="badge-pill-top">v2.5 Modular Suite</span>
        </div>
        <div class="mockup-tabs">
            <a href="dashboard.php" class="mockup-tab-btn <?= in_array($current_script, ['dashboard.php', 'index.php']) ? 'active' : '' ?>">
                <i class="material-icons">dashboard</i> Dashboard
            </a>
            <a href="category.php" class="mockup-tab-btn <?= strpos($current_script, 'category') !== false ? 'active' : '' ?>">
                <i class="material-icons">view_list</i> Category
            </a>
            <a href="featured.php" class="mockup-tab-btn <?= strpos($current_script, 'featured') !== false ? 'active' : '' ?>">
                <i class="material-icons">star</i> Featured
            </a>
            <a href="recipes.php" class="mockup-tab-btn <?= (strpos($current_script, 'recipe') !== false && $current_script != 'api_sync_recipe.php') ? 'active' : '' ?>">
                <i class="material-icons">restaurant</i> Recipes
            </a>

            <div class="mockup-tab-divider"></div>

            <a href="ai-generator.php" class="mockup-tab-btn <?= in_array($current_script, ['ai-generator.php', 'importer.php']) ? 'active' : '' ?>">
                <i class="material-icons" style="color:#F59E0B;">auto_awesome</i> AI Studio Hub
            </a>
            <a href="ai-config.php" class="mockup-tab-btn <?= $current_script == 'ai-config.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#818CF8;">tune</i> Konfigurasi AI Pool
            </a>
            <a href="image-generator.php" class="mockup-tab-btn <?= $current_script == 'image-generator.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#60A5FA;">photo_camera</i> Image Studio
            </a>
            <a href="video-generator.php" class="mockup-tab-btn <?= $current_script == 'video-generator.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#F472B6;">videocam</i> Video Studio
            </a>

            <div class="mockup-tab-divider"></div>

            <a href="ads.php" class="mockup-tab-btn <?= $current_script == 'ads.php' ? 'active' : '' ?>">
                <i class="material-icons">monetization_on</i> Ads
            </a>
            <a href="notification.php" class="mockup-tab-btn <?= strpos($current_script, 'notification') !== false ? 'active' : '' ?>">
                <i class="material-icons">notifications</i> Notification
            </a>
            <a href="admin.php" class="mockup-tab-btn <?= strpos($current_script, 'admin') !== false ? 'active' : '' ?>">
                <i class="material-icons">people</i> Admin
            </a>
            <a href="settings.php" class="mockup-tab-btn <?= strpos($current_script, 'settings') !== false ? 'active' : '' ?>">
                <i class="material-icons">settings</i> Settings
            </a>
            <a href="apps.php" class="mockup-tab-btn <?= strpos($current_script, 'apps') !== false ? 'active' : '' ?>">
                <i class="material-icons">adb</i> Apps
            </a>
        </div>
    </div>

<?php include_once ('sidebar.php'); ?>

    