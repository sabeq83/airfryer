# Changelog

## V2.0.0 — Inisialisasi AirFry Studio V2, Standar Versioning & Repositori GitHub (28/10/2026)
- Restrukturisasi folder lokal menjadi airfry_studio_v2 dan sinkronisasi penamaan dengan server Mac Mini
- Setup repositori GitHub resmi https://github.com/sabeq83/airfryer.git (branch main)
- Konfigurasi .gitignore ketat untuk memproteksi direktori upload (~114MB) dan menjaga struktur folder via .gitkeep
- Arsitektur video generator modular: AiVideoService, GeminiClient, FFmpegVideoService, dan video-generator.php
- Dynamic Sizing Motion Graphics Overlay dengan 5 Preset (Pro Glass, TikTok Frosted White, Warm Amber, Floating Pill, Cyber Neon)
- Fitur dynamic brand watermark (default kosong untuk tampilan minimalis)
- Dual-layer sanitizer instruksi memasak langsung (Indonesia & English) dengan penanganan kata kerja imperatif
- Adopsi sistem rilis otomatis, semantic versioning, dan Single Source of Truth changelog ala contentflow-staging
