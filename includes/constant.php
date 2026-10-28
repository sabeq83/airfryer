<?php 

    $postPerPage = 10;

    // Mode Studio Konten:
    // TRUE  = Local Studio (MacBook / Mac Mini): Impor Resep, AI Generator, dan 1-Click Push AKTIF.
    // FALSE = Shared Web Hosting: Impor Resep & AI Generator DINONAKTIFKAN agar antarmuka bersih & bebas error timeout.
    if (!defined('ENABLE_STUDIO_FEATURES')) {
        define('ENABLE_STUDIO_FEATURES', true);
    }

?>