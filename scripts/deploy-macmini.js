#!/usr/bin/env node

const {
  CONFIG,
  resolveLocalGitSha,
  runRemoteScript,
  buildBootstrapScript,
  buildDeployScript,
  buildRollbackScript,
  buildStatusScript
} = require('./lib/macmini-deploy');

const args = process.argv.slice(2);

function getArg(flag) {
  const index = args.indexOf(flag);
  if (index !== -1 && index + 1 < args.length) {
    return args[index + 1];
  }
  return null;
}

function printHelp() {
  console.log(`
Penggunaan: node scripts/deploy-macmini.js [options]

Opsi Perintah:
  --apply          Eksekusi perubahan di server (tanpa ini, deploy berjalan sebagai dry-run)
  --bootstrap      Inisialisasi direktori atomik, migrasi data upload, dan setting awal PM2 di Mac Mini
  --rollback       Kembalikan symlink 'current' ke rilis sebelumnya secara instan
  --status         Tampilkan rilis aktif, deployment-manifest.json, dan status PM2 server
  --ref <sha/tag>  Tentukan commit SHA atau tag Git tertentu (default: HEAD lokal)
  --keep <n>       Jumlah rilis lama yang disimpan (retention, default: 5)
  --help, -h       Tampilkan bantuan ini

Contoh:
  npm run deploy:macmini:status
  npm run deploy:macmini:bootstrap
  npm run deploy:macmini
  npm run deploy:macmini:rollback
`);
}

async function main() {
  if (args.includes('--help') || args.includes('-h')) {
    printHelp();
    return;
  }

  // 1. Status Command
  if (args.includes('--status')) {
    const script = buildStatusScript();
    runRemoteScript(script);
    return;
  }

  // 2. Rollback Command
  if (args.includes('--rollback')) {
    if (!args.includes('--apply')) {
      console.log('⚠️ Mode: DRY-RUN rollback. Jalankan dengan flag --apply untuk mengeksekusi di Mac Mini.');
      return;
    }
    const script = buildRollbackScript();
    runRemoteScript(script);
    return;
  }

  // 3. Bootstrap Command
  if (args.includes('--bootstrap')) {
    const isApply = args.includes('--apply');
    if (!isApply) {
      console.log('⚠️ Mode: DRY-RUN bootstrap.');
    }
    const script = buildBootstrapScript({ apply: isApply });
    runRemoteScript(script);
    return;
  }

  // 4. Normal Deploy Command
  const ref = getArg('--ref') || 'HEAD';
  const keep = getArg('--keep') || CONFIG.defaultRetention;
  const sha = resolveLocalGitSha(ref);

  if (!args.includes('--apply')) {
    console.log('=== DRY-RUN: ATOMIC DEPLOYMENT MAC MINI ===');
    console.log(`Target Host : ${CONFIG.host}`);
    console.log(`Git SHA     : ${sha} (ref: ${ref})`);
    console.log(`Retention   : ${keep} rilis`);
    console.log('\nJalankan dengan perintah berikut untuk mengeksekusi deployment nyata:');
    console.log(`npm run deploy:macmini`);
    return;
  }

  const script = buildDeployScript({ sha, keep });
  runRemoteScript(script);
}

main().catch(err => {
  console.error('\n❌ Terjadi kesalahan saat deployment:', err.message);
  process.exit(1);
});
