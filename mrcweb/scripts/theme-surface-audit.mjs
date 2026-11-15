// Gündüz modu (theme-light) yüzey denetimi.
//
// İki çıktı üretir:
//  1) Kodda kullanılan `bg-[#HEX]` sınıflarından açık-mod override listesinde
//     karşılanmayanlar (bilgi).
//  2) Aynı class dizisinde `text-white` ile birlikte kullanılan ve KOYU olan
//     yüzeyler — bunlar gündüzde koyu kaldığı için üstlerindeki beyaz yazı
//     beyaz kalmalı (aksi halde koyu üstüne koyu yazı = okunmaz).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');

const css = fs.readFileSync(path.join(root, 'resources/css/app.css'), 'utf8').replace(/\r\n/g, '\n');

const files = [];
(function walk(dir) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (/\.(vue|js)$/.test(e.name)) files.push(p);
    }
})(path.join(root, 'resources/js'));

// class="..." içindeki her bg sınıfı + text-white birlikte mi?
const darkWithWhite = new Map();
const allSurfaces = new Map();

for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    const re = /class="([^"]*)"/g;
    let m;
    while ((m = re.exec(src))) {
        const cls = m[1];
        const bgs = cls.match(/bg-\[#[0-9A-Fa-f]{6}\](\/\d+)?/g) || [];
        for (const b of bgs) {
            const hex = b.match(/#[0-9A-Fa-f]{6}/)[0];
            const key = b;
            if (!allSurfaces.has(key)) allSurfaces.set(key, new Set());
            allSurfaces.get(key).add(path.relative(root, f));

            if (cls.includes('text-white') && isDark(hex)) {
                if (!darkWithWhite.has(key)) darkWithWhite.set(key, new Set());
                darkWithWhite.get(key).add(path.relative(root, f));
            }
        }
    }
}

function isDark(hex) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 < 0.45;
}

const covered = new Set();
const reCss = /bg-\\\[\\#([0-9A-Fa-f]{6})\\\](\\\/\\d+)?/g;
let c;
while ((c = reCss.exec(css))) {
    covered.add('bg-[#' + c[1].toUpperCase() + ']' + (c[2] ? '/' + c[2].replace(/\\\//, '') : ''));
}

const gaps = [];
for (const [cls, fs_] of allSurfaces) {
    const base = cls.replace(/\/\d+$/, '');
    if (!covered.has(cls) && !covered.has(base)) {
        gaps.push({ cls, files: [...fs_] });
    }
}

console.log('kullanılan yüzey sınıfı: ' + allSurfaces.size);
console.log('açık-mod listesinde karşılanmayan: ' + gaps.length + '\n');

console.log('--- KOYU yüzey + text-white birlikte (gündüzde beyaz yazı kalmalı) ---');
const list = [];
for (const [cls, fs_] of darkWithWhite) {
    const base = cls.replace(/\/\d+$/, '');
    if (covered.has(cls) || covered.has(base)) continue; // zaten açığa çevriliyor
    list.push(cls);
}
console.log(list.sort().join('\n'));
console.log('\n--- karşılanmayan yüzeyler (bilgi) ---');
for (const g of gaps) console.log('  ' + g.cls + '  <- ' + g.files.slice(0, 2).join(', '));
