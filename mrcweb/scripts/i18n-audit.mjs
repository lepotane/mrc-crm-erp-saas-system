// i18n kapsama denetimi (Faz 5 öncesi kalıcılaştırıldı; geçici tarama betiğinden türedi).
//
// Kullanım (mrcweb kökünden):   node scripts/i18n-audit.mjs
// CI/commit öncesi: eksik anahtar varsa **exit 1** döner.
//
// Taranan kalıplar (SPA, anahtar = Türkçe kaynak metin deseni, PLAN §13):
//   1) t('...') / $t('...') ve t("...") / $t("...")
//   2) etiket haritalarında labelKey/titleKey/hintKey/stockHintKey: '...' (TÜRKÇE görünen)
// Etiket haritaları anahtar döndürüp $t() şablonda uygulandığı için (dil değişimi reaktif
// kalsın diye) bu anahtarlar da EN karşılığı olmadan ekranda çıplak Türkçe kalır.
//
// Backend de taranır: PHP `__()` / `trans()` çağrılarındaki Türkçe anahtarlar
// `lang/en.json` içinde karşılık bulmalı (aksi halde API mesajları İngilizce
// seçildiğinde Türkçe kalır).
//
// Not: `get(` / `emit(` gibi sahte eşleşmeleri elemek için t( önünde sözcük sınırı var;
// key: kalıbı yalnız Türkçe karakter içeren değerleri toplar (API alan adları karışmasın).

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');            // mrcweb/
const jsDir = path.join(root, 'resources/js');
const localeFile = path.join(jsDir, 'i18n/locales/en.json');

const en = JSON.parse(fs.readFileSync(localeFile, 'utf8'));
const enKeys = new Set(Object.keys(en));

const files = [];
(function walk(dir) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (/\.(vue|js)$/.test(e.name)) files.push(p);
    }
})(jsDir);

const patterns = [
    // t('X') / $t('X') ve parametreli hâli t('X', { n: 1 }) — ikisini de yakalar
    /(?:^|[^A-Za-z0-9_$.])\$?t\(\s*'([^']+)'\s*[,)]/g,
    /(?:^|[^A-Za-z0-9_$.])\$?t\(\s*"([^"]+)"\s*[,)]/g,
    /\b(?:labelKey|titleKey|hintKey|stockHintKey)\s*:\s*'([^']+)'/g,
    /\b(?:labelKey|titleKey|hintKey|stockHintKey)\s*:\s*"([^"]+)"/g,
    /\bkey\s*:\s*'([^']*[ÇĞİÖŞÜçğıöşü][^']*)'/g,
    // kart/sekme tanımları title/tag üzerinden kurulur (Panel, Raporlar) → bunlar da
    // şablonda $t(card.title) ile çözüldüğü için EN karşılığı olmadan çıplak Türkçe kalır
    /\b(?:title|tag)\s*:\s*'([^']*[ÇĞİÖŞÜçğıöşü][^']*)'/g,
];

const used = new Map(); // anahtar -> Set(dosya)
for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    for (const re of patterns) {
        re.lastIndex = 0;
        let m;
        while ((m = re.exec(src))) {
            const k = m[1];
            if (!k || k === '...' || k.length < 2) continue;
            if (!used.has(k)) used.set(k, new Set());
            used.get(k).add(path.relative(root, f));
        }
    }
}

const missing = [...used.keys()].filter((k) => !enKeys.has(k));

console.log(`taranan dosya: ${files.length} · kullanılan anahtar: ${used.size} · en.json: ${enKeys.size}`);

if (missing.length) {
    console.log(`\n--- EN karşılığı OLMAYAN (${missing.length}) ---`);
    for (const k of missing) console.log(`  "${k}"  ← ${[...used.get(k)].join(', ')}`);
} else {
    console.log('\nEN karşılığı olmayan anahtar: 0');
}

// en.json'da duran ama kodda bulunmayan anahtarlar: bilgi amaçlı (silme kararı elle verilir;
// sunucudan gelen mesajlar ve gelecekteki ekranlar bu listede bulunabilir).
const unused = [...enKeys].filter((k) => !used.has(k));
console.log(`\n--- en.jsonda durup kodda bulunmayan (${unused.length}) [bilgi amaçlı] ---`);
for (const k of unused) console.log(`  "${k}"`);

// ---------------------------------------------------------------------------
// Backend: PHP __() / trans() çağrılarındaki Türkçe anahtarlar → lang/en.json
// ---------------------------------------------------------------------------
const backendEn = JSON.parse(fs.readFileSync(path.join(root, 'lang/en.json'), 'utf8'));
const backendEnKeys = new Set(Object.keys(backendEn));

const phpFiles = [];
(function walk(dir) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        // bootstrap/ TARANIR: API hata mesajları bootstrap/app.php içinde üretilir.
        if (['vendor', 'node_modules', 'storage', 'public', 'graphify-out'].includes(e.name)) continue;
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (e.name.endsWith('.php')) phpFiles.push(p);
    }
})(root);

const backendUsed = new Map();
for (const f of phpFiles) {
    const src = fs.readFileSync(f, 'utf8');
    const re = /(?:\b__|\btrans|\btrans_choice)\(\s*'((?:[^'\\]|\\.)*)'/g;
    let m;
    while ((m = re.exec(src))) {
        const k = m[1].replace(/\\'/g, "'");
        if (!k || k.length < 3) continue;
        if (!/[ÇĞİÖŞÜçğıöşü]/.test(k)) continue;
        if (!backendUsed.has(k)) backendUsed.set(k, new Set());
        backendUsed.get(k).add(path.relative(root, f));
    }
}

const backendMissing = [...backendUsed.keys()].filter((k) => !backendEnKeys.has(k));

console.log(`\n--- backend taranan dosya: ${phpFiles.length} · EN karşılığı OLMAYAN (${backendMissing.length}) ---`);
for (const k of backendMissing) console.log(`  "${k}"  ← ${[...backendUsed.get(k)].join(', ')}`);

process.exit(missing.length || backendMissing.length ? 1 : 0);
