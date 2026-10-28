<?php

    //database configuration
    $host       = 'localhost';
    $user       = 'root';
    $pass       = '';
    $database   = 'your_recipes_app_db';

    $connect = new mysqli($host, $user, $pass, $database);

    if (mysqli_connect_errno()) {
        die ('Whoops! failed to connect to database : ' . mysqli_connect_error());
    } else {
        $connect->set_charset('utf8mb4');
    }

    $GLOBALS['config'] = $connect;

    $ENABLE_RTL_MODE = 'false';

    // Mode Studio Konten:
    // TRUE  = Local Studio (MacBook / Mac Mini): Impor Resep, AI Generator, dan 1-Click Push AKTIF.
    // FALSE = Shared Web Hosting: Impor Resep & AI Generator DINONAKTIFKAN agar antarmuka bersih & bebas error timeout.
    if (!defined('ENABLE_STUDIO_FEATURES')) {
        define('ENABLE_STUDIO_FEATURES', true);
    }

    // Helper Global Optimasi Gambar
    if (file_exists(__DIR__ . '/ImageOptimizer.php')) {
        require_once __DIR__ . '/ImageOptimizer.php';
    }

?>