# Source of Truth — Git-Based Atomic Deployment AirFry Studio V2

**Status**: Authoritative & Active  
**Effective date**: 28 Oktober 2026  
**Repository**: `https://github.com/sabeq83/airfryer.git`  
**Server Target**: Mac Mini `masbenu@100.95.245.55`  
**Service PM2**: `airfry-studio-v2` (Port `8085`)

---

## 1. Tujuan & Filosofi

Dokumen ini adalah sumber kebenaran (SOT) arsitektur deployment berbasis Git dan rilis atomik untuk AirFry Studio V2. Standar ini diadopsi dari arsitektur teruji `_contentflow-staging` untuk memastikan:
1. **Dapat Dilacak (Traceable)**: Setiap versi yang berjalan di server terikat langsung pada immutable Git Commit SHA dan Tag di GitHub.
2. **Zero Downtime**: Pergantian versi dilakukan secara atomik melalui manipulasi symlink `current`, sehingga pengguna tidak mengalami request terputus.
3. **Integritas Data Media**: Folder media `upload/` terisolasi permanen di `shared/upload/`, sehingga tidak akan pernah terhapus atau tertimpa oleh rilis kode baru.
4. **Verifikasi Otomatis**: Syntax check PHP (`php -l`) dilakukan sebelum symlink dialihkan, dan HTTP health check dijalankan setelah service di-reload.
5. **Rollback Instan 1-Detik**: Jika terjadi kendala pada rilis baru, sistem dapat dikembalikan ke rilis sebelumnya dalam 1 detik tanpa perlu build/transfer ulang.

---

## 2. Canonical Directory Layout di Mac Mini

```text
/Users/masbenu/airfry_studio_v2/
├── source/                         # Git clone cache (fetch git commits dari GitHub)
├── releases/                       # Direktori immutable releases
│   ├── 20261028T114035Z-0bc1a8818f4e/ # Release v2.0.0
│   └── <UTC-TIMESTAMP>-<GIT-SHA>/     # Release berikutnya
├── shared/                         # Mutable runtime persistent storage
│   ├── upload/                     # Direktori upload utama (gambar, clip, frame)
│   └── logs/                       # Server error & access logs
├── current -> releases/<active>    # Symlink atomik yang dieksekusi oleh PM2
├── deployment-manifest.json        # Manifest status rilis aktif
└── app/                            # Direktori legacy lama (sebagai fallback cadangan)
```

---

## 3. Deployment Invariants (Aturan Baku)

1. **Immutable Git SHA**: Deployment selalu menggunakan commit SHA yang sudah ter-push ke GitHub. Dilarang mengubah file langsung di dalam folder rilis aktif.
2. **Pre-Activation Syntax Guard**: File PHP utama (`video-generator.php`, `ai/AiVideoService.php`, `ai/GeminiClient.php`, `ai/FFmpegVideoService.php`) wajib lolos `php -l` sebelum symlink `current` dialihkan.
3. **Deployment Lock**: File `.deploy-lock` mencegah dua proses deployment berjalan secara bersamaan.
4. **Shared Upload Persistence**: Folder `release/upload` wajib merupakan symlink yang mengarah ke `shared/upload`. Dilarang membuat folder upload lokal di dalam direktori release.
5. **PM2 Process Health**: PM2 `airfry-studio-v2` wajib berjalan dengan target direktori `-t /Users/masbenu/airfry_studio_v2/current`.
6. **Automatic Rollback**: Jika setelah aktivasi HTTP health check port 8085 tidak merespon `200` atau `302`, sistem otomatis memindahkan symlink `current` ke rilis sebelumnya dan me-restart PM2.
7. **Retention Policy**: Server menyimpan 5 rilis terakhir di folder `releases/`. Rilis di luar batas retention otomatis dibersihkan untuk menghemat ruang disk.

---

## 4. Perintah Operasional Resmi

```bash
# 1. Periksa status rilis aktif di Mac Mini
npm run deploy:macmini:status

# 2. Uji coba deployment (dry-run tanpa eksekusi di server)
npm run deploy:macmini:dry-run

# 3. Eksekusi deployment atomik rilis terbaru
npm run deploy:macmini

# 4. Rollback instan ke rilis sebelumnya
npm run deploy:macmini:rollback

# 5. Inisialisasi awal server Mac Mini (hanya dijalankan saat setup baru)
npm run deploy:macmini:bootstrap
```
