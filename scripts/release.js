const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const readline = require('readline');

const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout
});

const question = (query) => new Promise((resolve) => rl.question(query, resolve));

const args = process.argv.slice(2);
const isNonInteractive = args.includes('--non-interactive') || args.includes('-y');

function getArg(flag) {
  const index = args.indexOf(flag);
  if (index !== -1 && index + 1 < args.length) {
    return args[index + 1];
  }
  return null;
}

async function main() {
  console.log('🚀 === ASISTEN RILIS OTOMATIS AIRFRY STUDIO V2 === 🚀\n');

  try {
    // 1. Read package.json
    const packagePath = path.join(__dirname, '../package.json');
    const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
    const currentVersion = packageJson.version || '2.0.0';
    console.log(`Versi saat ini: v${currentVersion}`);

    let newVersion = '';
    let releaseTitle = '';
    let bulletPoints = [];

    if (isNonInteractive) {
      console.log('Mode: Non-Interaktif');
      
      const type = getArg('--type') || 'patch';
      const customVer = getArg('--version');
      if (customVer) {
        newVersion = customVer.replace(/^v/, '');
      } else {
        newVersion = bumpVersion(currentVersion, type);
      }
      
      releaseTitle = getArg('--title') || 'Pembaruan Otomatis';
      
      const pointsArg = getArg('--points');
      if (pointsArg) {
        bulletPoints = pointsArg.split('|').map(p => p.trim()).filter(Boolean);
      } else {
        bulletPoints = ['Pembaruan internal dan optimasi sistem.'];
      }
    } else {
      // 2. Select New Version
      console.log('\nPilih tipe rilis:');
      console.log('1. Patch (Bugfix/Kecil)  -> v' + bumpVersion(currentVersion, 'patch'));
      console.log('2. Minor (Fitur Baru)    -> v' + bumpVersion(currentVersion, 'minor'));
      console.log('3. Major (Perubahan Besar)-> v' + bumpVersion(currentVersion, 'major'));
      console.log('4. Kustom (Masukkan versi sendiri)');
      
      const choice = await question('Pilihan (1-4, default: 1): ');
      
      if (choice === '2') {
        newVersion = bumpVersion(currentVersion, 'minor');
      } else if (choice === '3') {
        newVersion = bumpVersion(currentVersion, 'major');
      } else if (choice === '4') {
        newVersion = await question('Masukkan versi kustom (misal: 2.1.0): ');
        newVersion = newVersion.trim().replace(/^v/, '');
      } else {
        newVersion = bumpVersion(currentVersion, 'patch');
      }

      console.log(`\nVersi Baru yang akan dirilis: v${newVersion}`);

      // 3. Input Release Summary
      releaseTitle = await question('\nJudul Rilis (contoh: Peningkatan Motion Graphics & Auto Sanitizer): ');
      
      console.log('\nMasukkan poin-poin perubahan (ketik poin demi poin. Kosongkan & tekan Enter jika sudah selesai):');
      while (true) {
        const point = await question(`- `);
        if (!point.trim()) break;
        bulletPoints.push(point.trim());
      }
    }

    if (bulletPoints.length === 0) {
      console.log('❌ Rilis dibatalkan: Poin perubahan tidak boleh kosong.');
      rl.close();
      return;
    }

    // 4. Final Confirmation
    console.log('\n--- RINGKASAN RILIS ---');
    console.log(`Versi: v${newVersion}`);
    console.log(`Judul: ${releaseTitle}`);
    bulletPoints.forEach(p => console.log(`- ${p}`));
    console.log('----------------------');
    
    if (!isNonInteractive) {
      const confirm = await question('\nApakah Anda ingin merilis versi ini sekarang? (y/n, default: y): ');
      if (confirm.toLowerCase() === 'n') {
        console.log('❌ Rilis dibatalkan.');
        rl.close();
        return;
      }
    }

    // 5. Update package.json
    packageJson.version = newVersion;
    fs.writeFileSync(packagePath, JSON.stringify(packageJson, null, 2) + '\n');
    console.log('✓ package.json diperbarui.');

    // 6. Update sot/global/changelog.md
    const changelogDir = path.join(__dirname, '../sot/global');
    if (!fs.existsSync(changelogDir)) {
      fs.mkdirSync(changelogDir, { recursive: true });
    }
    const changelogPath = path.join(changelogDir, 'changelog.md');
    let changelogContent = '';
    if (fs.existsSync(changelogPath)) {
      changelogContent = fs.readFileSync(changelogPath, 'utf8');
    }

    // Create new markdown release block
    const today = new Date().toLocaleDateString('id-ID', { year: 'numeric', month: '2-digit', day: '2-digit' });
    let newReleaseBlock = `## V${newVersion} — ${releaseTitle || 'Pembaruan'} (${today})\n`;
    bulletPoints.forEach(p => {
      newReleaseBlock += `- ${p}\n`;
    });
    newReleaseBlock += '\n';

    // Insert after the main title "# Changelog"
    let updatedChangelog = '';
    if (changelogContent.startsWith('# Changelog')) {
      updatedChangelog = changelogContent.replace('# Changelog\n\n', `# Changelog\n\n${newReleaseBlock}`);
      if (updatedChangelog === changelogContent) {
        // Fallback for different spacing
        updatedChangelog = changelogContent.replace('# Changelog\n', `# Changelog\n\n${newReleaseBlock}`);
      }
    } else {
      updatedChangelog = `# Changelog\n\n${newReleaseBlock}${changelogContent}`;
    }

    fs.writeFileSync(changelogPath, updatedChangelog);
    console.log('✓ sot/global/changelog.md diperbarui.');

    // 7. Git Add, Commit, Push
    console.log('\nStaging file ke Git...');
    execSync('git add -A');
    
    const commitMsg = `release: v${newVersion} — ${releaseTitle || 'Pembaruan'}`;
    console.log(`Melakukan Git Commit: "${commitMsg}"...`);
    execSync(`git commit -m "${commitMsg}"`);
    
    console.log('Melakukan Git Push ke remote server...');
    execSync('git push origin HEAD');
    
    console.log(`Membuat dan mengunggah Git Tag v${newVersion}...`);
    try {
      execSync(`git tag -a v${newVersion} -m "${commitMsg}"`);
      execSync('git push origin --tags');
      console.log(`✓ Git Tag v${newVersion} terunggah.`);
    } catch (tagErr) {
      console.warn('⚠️ Catatan tag (mungkin tag sudah ada):', tagErr.message);
    }
    
    console.log('\n🎉 RILIS BERHASIL! Versi baru Anda v' + newVersion + ' sekarang sudah aktif dan terunggah ke GitHub! 🎉');
  } catch (err) {
    console.error('\n❌ Terjadi kesalahan saat rilis:', err.message);
  } finally {
    rl.close();
  }
}

function bumpVersion(version, type) {
  const parts = version.split('.').map(Number);
  if (parts.length !== 3 || parts.some(isNaN)) {
    return '2.0.0';
  }
  let [major, minor, patch] = parts;
  if (type === 'major') {
    major++;
    minor = 0;
    patch = 0;
  } else if (type === 'minor') {
    minor++;
    patch = 0;
  } else {
    patch++;
  }
  return `${major}.${minor}.${patch}`;
}

main();
