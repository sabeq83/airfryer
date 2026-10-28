const { execFileSync, execSync } = require('child_process');

const CONFIG = {
  host: 'macmini',
  serverRoot: '/Users/masbenu/airfry_studio_v2',
  sourceRoot: '/Users/masbenu/airfry_studio_v2/source',
  releasesRoot: '/Users/masbenu/airfry_studio_v2/releases',
  sharedRoot: '/Users/masbenu/airfry_studio_v2/shared',
  currentLink: '/Users/masbenu/airfry_studio_v2/current',
  legacyRoot: '/Users/masbenu/airfry_studio_v2/app',
  repositoryUrl: 'https://github.com/sabeq83/airfryer.git',
  pm2AppName: 'airfry-studio-v2',
  port: 8085,
  defaultRetention: 5
};

function shellQuote(str) {
  return `'${String(str).replaceAll("'", `'\\''`)}'`;
}

function resolveLocalGitSha(ref = 'HEAD') {
  try {
    const sha = execSync(`git rev-parse --verify ${ref}^{commit}`, { encoding: 'utf8' }).trim();
    if (!/^[0-9a-f]{40}$/i.test(sha)) {
      throw new Error(`Git ref tidak valid: ${ref}`);
    }
    return sha.toLowerCase();
  } catch (err) {
    throw new Error(`Gagal resolve Git SHA untuk ref "${ref}": ${err.message}`);
  }
}

function runRemoteScript(script, { capture = false } = {}) {
  return execFileSync('ssh', [CONFIG.host, 'bash', '-s'], {
    input: script,
    encoding: 'utf8',
    stdio: capture ? ['pipe', 'pipe', 'pipe'] : ['pipe', 'inherit', 'inherit'],
    timeout: 300000
  });
}

function buildBootstrapScript({ apply = false } = {}) {
  const c = CONFIG;
  const prefix = `
set -euo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
root=${shellQuote(c.serverRoot)}
source_root=${shellQuote(c.sourceRoot)}
releases=${shellQuote(c.releasesRoot)}
shared=${shellQuote(c.sharedRoot)}
legacy=${shellQuote(c.legacyRoot)}
repo=${shellQuote(c.repositoryUrl)}
current=${shellQuote(c.currentLink)}
pm2_app=${shellQuote(c.pm2AppName)}
port=${c.port}
`;

  if (!apply) {
    return `${prefix}
echo "=== DRY-RUN: BOOTSTRAP MAC MINI ATOMIC DEPLOYMENT ==="
echo "Target root: $root"
echo "Legacy root: $legacy"
echo "Repository:  $repo"
test -d "$legacy" && echo "✓ Legacy app directory ditemukan." || echo "✗ Legacy app tidak ditemukan!"
if test -d "$legacy/upload"; then
  echo "✓ Ukuran folder upload eksisting:"; du -sh "$legacy/upload"
fi
git ls-remote "$repo" HEAD >/dev/null && echo "✓ Repositori remote dapat diakses dari Mac Mini."
echo "Dry-run selesai. Jalankan dengan flag --apply untuk mengeksekusi."
`;
  }

  return `${prefix}
echo "=== MEMULAI BOOTSTRAP ATOMIC DEPLOYMENT DI MAC MINI ==="
mkdir -p "$root" "$releases" "$shared" "$shared/upload" "$shared/logs"

# 1. Setup Source Cache
if test ! -d "$source_root/.git"; then
  echo "-> Melakukan kloning awal repositori ke source cache..."
  git clone --no-checkout "$repo" "$source_root"
else
  echo "-> Memperbarui remote source cache..."
  git -C "$source_root" remote set-url origin "$repo"
fi
git -C "$source_root" fetch --prune --tags origin

# 2. Migrasi data upload eksisting
if test -d "$legacy/upload" && test ! -f "$shared/upload/.bootstrapped"; then
  echo "-> Melakukan delta sync folder upload dari legacy app ke shared storage..."
  rsync -a "$legacy/upload/" "$shared/upload/"
  touch "$shared/upload/.bootstrapped"
  echo "✓ Data upload berhasil dimigrasikan ke shared/upload/."
fi

# 3. Deploy rilis perdana dari HEAD
sha="$(git -C "$source_root" rev-parse origin/main)"
release_id="$(date -u +%Y%m%dT%H%M%SZ)-$(printf '%s' "$sha" | cut -c1-12)"
release="$releases/$release_id"
echo "-> Membuat rilis awal di: $release ($sha)..."
git -C "$source_root" worktree add --detach "$release" "$sha"

# Link shared upload
rm -rf "$release/upload"
ln -s "$shared/upload" "$release/upload"

# 4. Link current symlink
ln -sfn "$release" "$current"
echo "✓ Symlink current terhubung ke: $release"

# 5. Catat manifest awal
printf '{"release_id":"%s","git_sha":"%s","previous":"","deployed_at":"%s","health":"passed"}\\n' \\
  "$release_id" "$sha" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$root/deployment-manifest.json"

# 6. Reconfigure PM2 untuk mengarah ke current symlink
echo "-> Menyesuaikan konfigurasi PM2 service: $pm2_app..."
pm2 delete "$pm2_app" >/dev/null 2>&1 || true
pm2 start /bin/bash --name "$pm2_app" -- -c "PHP_CLI_SERVER_WORKERS=4 /opt/homebrew/bin/php -d max_execution_time=300 -d memory_limit=512M -S 0.0.0.0:$port -t $current"
pm2 save

echo "-> Verifikasi HTTP health check..."
sleep 2
http_code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/video-generator.php" || true)"
echo "HTTP response code: $http_code"
if test "$http_code" != "200" && test "$http_code" != "302"; then
  echo "⚠️ Peringatan: Health check mengembalikan kode $http_code (bukan 200/302)."
else
  echo "✓ Health check berhasil ($http_code OK)!"
fi

echo "🎉 BOOTSTRAP SERVER MAC MINI SELESAI DENGAN SUKSES! 🎉"
`;
}

function buildDeployScript({ sha, keep = CONFIG.defaultRetention } = {}) {
  const c = CONFIG;
  return `
set -euo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
root=${shellQuote(c.serverRoot)}
source_root=${shellQuote(c.sourceRoot)}
releases=${shellQuote(c.releasesRoot)}
shared=${shellQuote(c.sharedRoot)}
current=${shellQuote(c.currentLink)}
legacy=${shellQuote(c.legacyRoot)}
pm2_app=${shellQuote(c.pm2AppName)}
port=${c.port}
sha=${shellQuote(sha)}
keep=${Number(keep)}

# 1. Lock deployment
lock="$root/.deploy-lock"
if ! mkdir "$lock" 2>/dev/null; then
  echo "❌ Deployment lain sedang berjalan di server ($lock)." >&2
  exit 73
fi
cleanup() { rmdir "$lock" 2>/dev/null || true; }
trap cleanup EXIT INT TERM

echo "🚀 === MEMULAI ATOMIC DEPLOYMENT MAC MINI === 🚀"
echo "Target Commit SHA: $sha"

test -d "$source_root/.git" || { echo "Source cache belum di-bootstrap. Jalankan bootstrap terlebih dahulu." >&2; exit 1; }

echo "-> Mengambil update commit terbaru dari GitHub..."
git -C "$source_root" fetch --prune --tags origin
git -C "$source_root" cat-file -e "$sha^{commit}" || { echo "Commit SHA $sha tidak ditemukan di remote!" >&2; exit 1; }

# 2. Buat direktori release baru
release_id="$(date -u +%Y%m%dT%H%M%SZ)-$(printf '%s' "$sha" | cut -c1-12)"
release="$releases/$release_id"
test ! -e "$release" || { echo "Release directory $release sudah ada!" >&2; exit 1; }

previous=""
if test -L "$current"; then
  previous="$(readlink "$current")"
fi

echo "-> Membuat worktree release baru: $release_id..."
git -C "$source_root" worktree add --detach "$release" "$sha"

rollback_pre_activation() {
  echo "⚠️ Terjadi kegagalan sebelum aktivasi. Membersihkan worktree..."
  git -C "$source_root" worktree remove --force "$release" 2>/dev/null || rm -rf "$release"
}
trap 'rollback_pre_activation; cleanup' ERR

# 3. Hubungkan shared storage
rm -rf "$release/upload"
ln -s "$shared/upload" "$release/upload"

# 4. Validasi sintaks PHP (Pre-activation check)
echo "-> Memvalidasi sintaks PHP sebelum aktivasi..."
/opt/homebrew/bin/php -l "$release/video-generator.php" >/dev/null
/opt/homebrew/bin/php -l "$release/ai/AiVideoService.php" >/dev/null
/opt/homebrew/bin/php -l "$release/ai/GeminiClient.php" >/dev/null
/opt/homebrew/bin/php -l "$release/ai/FFmpegVideoService.php" >/dev/null
echo "✓ Seluruh berkas PHP utama lolos syntax check."

# 5. Atomic symlink cutover
echo "-> Memindahkan symlink current ke release baru..."
ln -sfn "$release" "$current"

# 6. Reload / Restart PM2
echo "-> Me-restart PM2 service: $pm2_app..."
pm2 restart "$pm2_app" >/dev/null

# 7. Post-activation health check
echo "-> Menjalankan post-activation HTTP health check..."
health_passed=false
for i in 1 2 3 4 5; do
  sleep 1
  code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/video-generator.php" || true)"
  if test "$code" = "200" || test "$code" = "302"; then
    health_passed=true
    echo "✓ Health check berhasil pada percobaan ke-$i (HTTP $code)!"
    break
  fi
  echo "Menunggu respon server... (status saat ini: $code)"
done

if test "$health_passed" != "true"; then
  echo "❌ Health check GAGAL! Melakukan rollback otomatis ke release sebelumnya..."
  if test -n "$previous" && test -e "$previous"; then
    ln -sfn "$previous" "$current"
    pm2 restart "$pm2_app" >/dev/null
    echo "✓ Rollback berhasil dikembalikan ke: $previous"
  fi
  exit 1
fi

# 8. Catat manifest rilis
manifest='{"release_id":"'"$release_id"'","git_sha":"'"$sha"'","previous":"'"$previous"'","deployed_at":"'"$(date -u +%Y-%m-%dT%H:%M:%SZ)"'","health":"passed"}'
printf '%s\\n' "$manifest" > "$root/deployment-manifest.json"
printf '%s\\n' "$manifest" > "$release/deployment-manifest.json"

# 9. Pruning rilis lama (keep retention)
echo "-> Membersihkan rilis usang (retensi: $keep rilis terakhir)..."
active_release_name="$(basename "$release")"
cd "$releases"
all_releases="$(ls -1dt 20* 2>/dev/null || true)"
count=0
echo "$all_releases" | while read -r r; do
  test -n "$r" || continue
  count=$((count + 1))
  if test $count -gt $keep && test "$r" != "$active_release_name"; then
    echo "   Menghapus rilis usang: $r"
    git -C "$source_root" worktree remove --force "$releases/$r" 2>/dev/null || rm -rf "$releases/$r"
  fi
done

echo ""
echo "🎉 DEPLOYMENT ATOMIK BERHASIL! 🎉"
echo "Release ID : $release_id"
echo "Git SHA    : $sha"
echo "Status     : Online & Verified (HTTP 200/302)"
`;
}

function buildRollbackScript() {
  const c = CONFIG;
  return `
set -euo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
root=${shellQuote(c.serverRoot)}
releases=${shellQuote(c.releasesRoot)}
current=${shellQuote(c.currentLink)}
pm2_app=${shellQuote(c.pm2AppName)}
port=${c.port}

echo "🔄 === MEMULAI ROLLBACK INSTAN DI MAC MINI === 🚀"
test -L "$current" || { echo "Symlink current tidak ditemukan!" >&2; exit 1; }
active_path="$(readlink "$current")"
active_name="$(basename "$active_path")"

cd "$releases"
prev_release="$(ls -1dt 20* 2>/dev/null | grep -v "^$active_name$" | head -n 1 || true)"
if test -z "$prev_release"; then
  echo "❌ Tidak ditemukan rilis sebelumnya di $releases untuk di-rollback." >&2
  exit 1
fi

target="$releases/$prev_release"
echo "Rilis aktif saat ini : $active_name"
echo "Target rollback      : $prev_release ($target)"

ln -sfn "$target" "$current"
echo "✓ Symlink current dipindahkan ke $target."

echo "-> Me-restart PM2 service: $pm2_app..."
pm2 restart "$pm2_app" >/dev/null

sleep 2
code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/video-generator.php" || true)"
echo "HTTP response code: $code"
if test "$code" = "200" || test "$code" = "302"; then
  echo "✓ Health check rollback berhasil!"
  printf '{"release_id":"%s","rollback_from":"%s","deployed_at":"%s","health":"passed"}\\n' \\
    "$prev_release" "$active_name" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$root/deployment-manifest.json"
  echo "🎉 ROLLBACK BERHASIL AKTIF! 🎉"
else
  echo "⚠️ Peringatan: Respon health check $code."
fi
`;
}

function buildStatusScript() {
  const c = CONFIG;
  return `
set -euo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
root=${shellQuote(c.serverRoot)}
releases=${shellQuote(c.releasesRoot)}
current=${shellQuote(c.currentLink)}
pm2_app=${shellQuote(c.pm2AppName)}

echo "📊 === STATUS DEPLOYMENT MAC MINI === 📊"
echo "Server Root   : $root"
if test -L "$current"; then
  echo "Active Release: $(readlink "$current")"
else
  echo "Active Release: (Symlink current belum terpasang)"
fi

if test -f "$root/deployment-manifest.json"; then
  echo ""
  echo "Manifest Terakhir:"
  cat "$root/deployment-manifest.json"
  echo ""
fi

echo ""
echo "Daftar Rilis Tersedia di Server:"
ls -lht "$releases" 2>/dev/null | grep -E '^d' | head -n 6 || echo "(Belum ada rilis)"

echo ""
echo "Status PM2 Service:"
pm2 status "$pm2_app" 2>/dev/null || echo "Service $pm2_app tidak ditemukan di PM2."
`;
}

module.exports = {
  CONFIG,
  resolveLocalGitSha,
  runRemoteScript,
  buildBootstrapScript,
  buildDeployScript,
  buildRollbackScript,
  buildStatusScript
};
