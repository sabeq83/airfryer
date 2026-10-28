    <section>
        <!-- Left Sidebar (Mockup Style 1:1) -->
        <aside id="leftsidebar" class="sidebar">
            
            <!-- Brand -->
            <div class="sidebar-brand">
                <img src="assets/images/logo.png" alt="AirFry Eats" style="width:36px; height:36px; border-radius:8px; object-fit:cover; box-shadow:0 2px 6px rgba(0,0,0,0.25);">
                <div>
                    <div class="sidebar-brand-text">AirFry Eats</div>
                    <div class="sidebar-brand-sub">Studio &amp; Recipe Admin</div>
                </div>
            </div>

            <!-- User Info -->
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?php echo strtoupper(substr($data['username'] ?? 'AS', 0, 2)); ?></div>
                <div style="flex:1; min-width:0;">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($data['username'] ?? 'Admin Airfry'); ?></div>
                    <div class="sidebar-user-role">Super Administrator</div>
                </div>
                <a href="logout.php" onclick="return confirm('Are you sure want to logout?')" title="Logout" style="color:#94A3B8; text-decoration:none; display:flex; align-items:center;">
                    <i class="material-icons" style="font-size:18px;">power_settings_new</i>
                </a>
            </div>

            <!-- Menu -->
            <div class="sidebar-menu">
                <?php 
                    $page = $_SERVER['REQUEST_URI'] ?? ''; 
                    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
                ?>

                <div class="menu-header">MENU UTAMA</div>
                
                <a href="dashboard.php" class="menu-item <?php if ($script == 'dashboard.php' || $script == 'index.php') echo 'active'; ?>">
                    <i class="material-icons">dashboard</i>
                    <span>Dashboard</span>
                </a>

                <a href="category.php" class="menu-item <?php if (strpos($script, 'category') !== false) echo 'active'; ?>">
                    <i class="material-icons">view_list</i>
                    <span>Manage Category</span>
                </a>

                <a href="featured.php" class="menu-item <?php if (strpos($script, 'featured') !== false) echo 'active'; ?>">
                    <i class="material-icons">star</i>
                    <span>Manage Featured</span>
                </a>

                <a href="recipes.php" class="menu-item <?php if (strpos($script, 'recipe') !== false && $script != 'api_sync_recipe.php') echo 'active'; ?>">
                    <i class="material-icons">restaurant</i>
                    <span>Manage Recipes</span>
                </a>

                <?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
                <div class="menu-header" style="margin-top:10px;">STUDIO &amp; OTOMASI</div>

                <a href="ai-generator.php" class="menu-item <?php if ($script == 'ai-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:var(--af-flame);">auto_awesome</i>
                    <span>AI Studio Hub</span>
                    <span class="menu-badge">3 Tools</span>
                </a>

                <a href="ai-config.php" class="menu-item <?php if ($script == 'ai-config.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:var(--af-indigo);">tune</i>
                    <span>Konfigurasi AI Pool</span>
                </a>

                <a href="image-generator.php" class="menu-item <?php if ($script == 'image-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#6366f1;">photo_camera</i>
                    <span>Image Studio</span>
                </a>

                <a href="video-generator.php" class="menu-item <?php if ($script == 'video-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#ec4899;">videocam</i>
                    <span>Video Studio</span>
                </a>

                <a href="importer.php" class="menu-item <?php if ($script == 'importer.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#10b981;">cloud_download</i>
                    <span>Impor Resep</span>
                </a>
                <?php endif; ?>

                <div class="menu-header" style="margin-top:10px;">MONETISASI &amp; SISTEM</div>

                <a href="ads.php" class="menu-item <?php if ($script == 'ads.php') echo 'active'; ?>">
                    <i class="material-icons">monetization_on</i>
                    <span>Manage Ads</span>
                </a>

                <a href="notification.php" class="menu-item <?php if (strpos($script, 'notification') !== false) echo 'active'; ?>">
                    <i class="material-icons">notifications</i>
                    <span>Notification</span>
                </a>

                <a href="admin.php" class="menu-item <?php if (strpos($script, 'admin') !== false) echo 'active'; ?>">
                    <i class="material-icons">people</i>
                    <span>Administrators</span>
                </a>

                <a href="settings.php" class="menu-item <?php if (strpos($script, 'settings') !== false || strpos($script, 'api-key') !== false) echo 'active'; ?>">
                    <i class="material-icons">settings</i>
                    <span>Setting</span>
                </a>

                <a href="apps.php" class="menu-item <?php if (strpos($script, 'apps') !== false) echo 'active'; ?>">
                    <i class="material-icons">adb</i>
                    <span>Apps</span>
                </a>

                <div class="menu-header" style="margin-top:10px;">SESSION</div>

                <a href="logout.php" onclick="return confirm('Are you sure want to logout?')" class="menu-item">
                    <i class="material-icons" style="color:#EF4444;">power_settings_new</i>
                    <span style="color:#EF4444; font-weight:700;">Logout</span>
                </a>
            </div>

            <!-- Footer -->
            <div style="padding:14px 18px; border-top:1px solid #E2E8F0; font-size:11px; color:#94A3B8; margin-top:auto;">
                <div><?php echo $copyright; ?></div>
                <div style="margin-top:2px;"><b>Version: </b> <?php echo $app_version; ?></div>
            </div>
        </aside>
    </section>