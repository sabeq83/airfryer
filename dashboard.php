<?php include_once ('includes/header.php'); ?>

<?php

    $sql_category = "SELECT COUNT(*) as num FROM tbl_category";
    $total_category = mysqli_query($connect, $sql_category);
    $total_category = mysqli_fetch_array($total_category);
    $total_category = $total_category['num'];

    $sql_recipes = "SELECT COUNT(*) as num FROM tbl_recipes";
    $total_recipes = mysqli_query($connect, $sql_recipes);
    $total_recipes = mysqli_fetch_array($total_recipes);
    $total_recipes = $total_recipes['num'];

    $sql_featured = "SELECT COUNT(*) as num FROM tbl_recipes WHERE featured = '1'";
    $total_featured = mysqli_query($connect, $sql_featured);
    $total_featured = mysqli_fetch_array($total_featured);
    $total_featured = $total_featured['num'];

    $sql_fcm = "SELECT COUNT(*) as num FROM tbl_fcm_template";
    $total_fcm = mysqli_query($connect, $sql_fcm);
    $total_fcm = mysqli_fetch_array($total_fcm);
    $total_fcm = $total_fcm['num'];

    $total_ai = 0;
    $res_ai = @mysqli_query($connect, "SELECT COUNT(*) as num FROM tbl_ai_generations WHERE status = 'applied'");
    if ($res_ai && $row_ai = mysqli_fetch_array($res_ai)) {
        $total_ai = (int)$row_ai['num'];
    }
?>

<?php
    $sql_admin = "SELECT COUNT(*) as num FROM tbl_admin";
    $total_admin = mysqli_query($connect, $sql_admin);
    $total_admin = mysqli_fetch_array($total_admin);
    $total_admin = (int)($total_admin['num'] ?? 0);
?>

    <section class="content">
        <div class="container-fluid" id="fade-in">

            <div class="breadcrumb" style="display:flex; align-items:center; gap:8px; margin-bottom:20px; font-size:13px; color:#64748B;">
                <i class="material-icons" style="font-size:16px; color:var(--af-flame);">dashboard</i>
                <a href="dashboard.php" style="color:#64748B; text-decoration:none; font-weight:600;">Dashboard</a>
                <span style="color:#CBD5E1;">/</span>
                <span style="color:var(--af-dark); font-weight:700;">Overview &amp; Metrics</span>
            </div>

            <!-- 9 METRICS GRID (MATCHING MOCKUP 1:1) -->
            <div class="stat-grid-9">
                <!-- 1. Category -->
                <a href="category.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #3B82F6, #1D4ED8);">
                        <i class="material-icons">view_list</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Manage Category</div>
                        <div class="stat-info-val"><?php echo $total_category; ?> Kategori</div>
                        <div class="stat-info-sub">Total Kategori Resep</div>
                    </div>
                </a>

                <!-- 2. Featured -->
                <a href="featured.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #F59E0B, #D97706);">
                        <i class="material-icons">star</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Manage Featured</div>
                        <div class="stat-info-val"><?php echo $total_featured; ?> Resep Unggulan</div>
                        <div class="stat-info-sub">Ditampilkan di Carousel App</div>
                    </div>
                </a>

                <!-- 3. Recipes -->
                <a href="recipes.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #10B981, #059669);">
                        <i class="material-icons">restaurant</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Manage Recipes</div>
                        <div class="stat-info-val"><?php echo $total_recipes; ?> Resep</div>
                        <div class="stat-info-sub">Katalog Airfryer Lengkap</div>
                    </div>
                </a>

                <!-- 4. Ads -->
                <a href="ads.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #8B5CF6, #6D28D9);">
                        <i class="material-icons">monetization_on</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Manage Ads</div>
                        <div class="stat-info-val">AdMob &amp; MAX</div>
                        <div class="stat-info-sub">Monetisasi &amp; Interstitial</div>
                    </div>
                </a>

                <!-- 5. Notification -->
                <a href="notification.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #EC4899, #BE185D);">
                        <i class="material-icons">notifications</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Notification</div>
                        <div class="stat-info-val"><?php echo $total_fcm; ?> Template</div>
                        <div class="stat-info-sub">FCM / OneSignal Broadcast</div>
                    </div>
                </a>

                <!-- 6. Admin -->
                <a href="admin.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #0EA5E9, #0284C7);">
                        <i class="material-icons">people</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Administrators</div>
                        <div class="stat-info-val"><?php echo $total_admin; ?> Akun</div>
                        <div class="stat-info-sub">Hak Akses Admin Panel</div>
                    </div>
                </a>

                <!-- 7. Setting -->
                <a href="settings.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #64748B, #475569);">
                        <i class="material-icons">settings</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Setting</div>
                        <div class="stat-info-val">Sistem &amp; Privasi</div>
                        <div class="stat-info-sub">API Sync &amp; Privacy Policy</div>
                    </div>
                </a>

                <!-- 8. Impor Resep -->
                <a href="importer.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #14B8A6, #0D9488);">
                        <i class="material-icons">cloud_download</i>
                    </div>
                    <div>
                        <div class="stat-info-title">Impor Resep</div>
                        <div class="stat-info-val">Ekstraktor Web</div>
                        <div class="stat-info-sub">Impor Resep Otomatis &amp; Push</div>
                    </div>
                </a>

                <!-- 9. AI Studio Suite -->
                <a href="ai-generator.php" class="stat-card-item">
                    <div class="stat-icon-wrap" style="background:linear-gradient(135deg, #F97316, #EA580C);">
                        <i class="material-icons">auto_awesome</i>
                    </div>
                    <div>
                        <div class="stat-info-title">AI Studio Suite</div>
                        <div class="stat-info-val"><?php echo $total_ai; ?> Aset Terpasang</div>
                        <div class="stat-info-sub">Image Studio &amp; Video Studio</div>
                    </div>
                </a>
            </div>

        </div>
    </section>

<?php include_once('includes/footer.php'); ?>