// lang/tr.json dosyasını üretir: anahtar (Türkçe kaynak metin) → aynı metin.
//
// Neden gerekiyor: kaynak metinler Türkçe yazılmış. Laravel JSON çevirisi
// önce aktif locale bakar, bulamazsa fallback_locale ('en') dosyasına düşer.
// lang/tr.json olmayınca "Bu uç nokta mevcut değil." gibi mesajlar İngilizce
// en.json'a eklendiği AN İngilizce dönüyordu (Türkçe kullanıcıya yanlış dil).
// Kimlik haritasıyla: tr → Türkçe metin, en → İngilizce karşılığı.
//
// Kullanım: node scripts/i18n-make-tr.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');

const enPath = path.join(root, 'lang/en.json');
const trPath = path.join(root, 'lang/tr.json');

const en = JSON.parse(fs.readFileSync(enPath, 'utf8'));

const tr = {};
for (const key of Object.keys(en)) {
    tr[key] = key;
}

fs.writeFileSync(trPath, JSON.stringify(tr, null, 2) + '\n', 'utf8');
console.log(`lang/tr.json yazıldı: ${Object.keys(tr).length} anahtar`);
