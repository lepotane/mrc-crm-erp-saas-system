#!/usr/bin/env node
/*
|--------------------------------------------------------------------------
| API dokümanı derlemesi (Faz 5) — `npm run openapi`
|--------------------------------------------------------------------------
| Sunucuda Scramble YOKTUR (yayin paketi `composer install --no-dev` ile alinir),
| bu yuzden OpenAPI belgesi burada, local'da uretilir ve STATIK artifact olarak
| sunucuya tasinir:
|
|   1) php artisan scramble:export  -> docs/openapi/api.json   (derleme araci)
|   2) redocly lint                 -> semayi dogrular (hata varsa build durur)
|   3) Postman koleksiyonu (v2.1)   -> public/docs/postman_collection.json
|   4) redocly build-docs           -> public/docs/index.html  (okunabilir sayfa)
|   5) api.json kopyasi             -> public/docs/api.json
|
| /api-docs, /api-docs.json ve /api-docs/postman uclari public/docs/ altindaki
| bu dosyalari config('api.docs') kapisindan sunar.
*/
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SPEC = path.join(ROOT, 'docs', 'openapi', 'api.json');
const OUT = path.join(ROOT, 'public', 'docs');

const run = (cmd, args, label, env) => {
  process.stdout.write(`\n== ${label}\n`);
  execFileSync(cmd, args, { cwd: ROOT, stdio: 'inherit', env: env ? { ...process.env, ...env } : process.env });
};

/*
 * Belgedeki `servers` (Redoc'un "Sunucu" alani + Postman {{baseUrl}}) APP_URL'den
 * uretilir. Belge local'da uretilip sunucuya tasindigi icin, yayin paketinde
 * paketin dogru adresi gosterilmesi adina adres verilebilir:
 *
 *   npm run openapi -- --server=https://crm.ornek.site
 *
 * (App URL'si /api ile bitmemeli; /api onekini betik kendisi ekler.)
 */
const sunucuArg = process.argv.find((a) => a.startsWith('--server='));
const sunucu = sunucuArg
  ? sunucuArg.slice('--server='.length).replace(/\/+$/, '')
  : (process.env.MRC_API_SERVER_URL || '').replace(/\/+$/, '');

if (sunucuArg && !sunucu) {
  console.error("HATA: --server= degeri bos. Ornek: npm run openapi -- --server=https://crm.ornek.site");
  process.exit(1);
}

// redocly'yi .cmd kabuguyla degil dogrudan JS giris noktasiyla calistiriyoruz:
// Windows'ta Node, shell kullanmadan .cmd calistirmayi reddediyor (EINVAL).
const redocly = (args, label) => run(process.execPath, [path.join(ROOT, 'node_modules/@redocly/cli/bin/cli.js'), ...args], label);

fs.mkdirSync(path.dirname(SPEC), { recursive: true });
fs.mkdirSync(OUT, { recursive: true });

// 1) OpenAPI semasini uret (config/scramble.php -> export_path)
run('php', ['artisan', 'scramble:clear'], 'Scramble onbegi temizleniyor');
run('php', ['artisan', 'scramble:export'], sunucu ? `OpenAPI uretiliyor (sunucu: ${sunucu})` : 'OpenAPI uretiliyor', sunucu ? { APP_URL: sunucu } : undefined);

if (!fs.existsSync(SPEC)) {
  console.error(`\nHATA: ${SPEC} bulunamadi (config/scramble.php export_path kontrol edin).`);
  process.exit(1);
}

// 2) dogrulama: sozlesme hatasiyla yayinlanmasin
try {
  redocly(['lint', path.relative(ROOT, SPEC)], 'redocly lint');
} catch {
  console.error('\nHATA: OpenAPI semasi redocly lint karsisinda hata veriyor; build durduruldu.');
  process.exit(1);
}

const spec = JSON.parse(fs.readFileSync(SPEC, 'utf8'));

// --- Postman koleksiyonu --------------------------------------------------------
const ENUM_ORNEK = { TRY: 'TRY', tr: 'tr', customer: 'customer', invoice: 'invoice', cash: 'cash', in: 'in' };

function ornekDeger(schema, key = '') {
  if (!schema || typeof schema !== 'object') return null;

  if (schema.example !== undefined) return schema.example;
  if (Array.isArray(schema.enum)) return ENUM_ORNEK[schema.enum[0]] ?? schema.enum[0];

  const tur = Array.isArray(schema.type) ? schema.type.find((t) => t !== 'null') : schema.type;

  if (tur === 'object' || schema.properties) {
    const out = {};
    for (const [k, s] of Object.entries(schema.properties || {})) out[k] = ornekDeger(s, k);
    return out;
  }

  if (tur === 'array') {
    const i = ornekDeger(schema.items, key);
    return i === null ? [] : [i];
  }

  if (tur === 'integer' || tur === 'number') {
    if (schema.default !== undefined) return schema.default;
    if (/(rate|tax)/.test(key)) return 20;
    if (/(price|cost|amount|total|value|balance)/.test(key)) return 100;
    if (/(quantity|qty|count|days|id)/.test(key)) return 1;
    return 1;
  }

  if (tur === 'boolean') return schema.default ?? true;

  if (schema.format === 'date') return '2026-01-31';
  if (schema.format === 'date-time') return '2026-01-31T12:00:00Z';
  if (/(_at|_date)$/.test(key)) return '2026-01-31';
  if (/currency/.test(key)) return 'TRY';
  if (/(name|title)/.test(key)) return 'Örnek';
  if (/code$/.test(key)) return '00001';
  if (/sku$/.test(key)) return 'ORNEK-001';
  if (/email/.test(key)) return 'ornek@firma.test';
  if (/iban/.test(key)) return 'TR0000000000000000000000';
  if (/phone/.test(key)) return '+90 555 000 00 00';
  if (/note|notes|description/.test(key)) return 'Açıklama';
  if (/prefix|serial|reference/.test(key)) return 'SF-26-00001';

  return 'örnek';
}

const serverUrl = (spec.servers && spec.servers[0] && spec.servers[0].url) || 'http://127.0.0.1:8000/api';

const folderler = new Map();

for (const [yol, metodlar] of Object.entries(spec.paths || {})) {
  for (const [metod, op] of Object.entries(metodlar)) {
    if (!['get', 'post', 'put', 'patch', 'delete'].includes(metod)) continue;

    const etiket = (op.tags && op.tags[0]) || 'Diğer';
    if (!folderler.has(etiket)) folderler.set(etiket, []);

    const sorgu = [];
    for (const p of op.parameters || []) {
      const go = {
        key: p.name,
        value: p.schema ? String(ornekDeger(p.schema, p.name) ?? '') : '',
        description: { content: [p.description || '', p.required ? 'zorunlu' : 'opsiyonel'].join(' · ') },
      };
      if (!p.required) go.disabled = true;
      sorgu.push(go);
    }

    const govde = op.requestBody?.content?.['application/json']?.schema;
    const pdf = Object.keys(op.responses || {}).some((k) =>
      Object.keys(op.responses[k].content || {}).some((t) => t !== 'application/json'),
    );

    const item = {
      name: op.summary || `${metod.toUpperCase()} ${yol}`,
      request: {
        method: metod.toUpperCase(),
        header: govde
          ? [{ key: 'Content-Type', value: 'application/json' }]
          : [],
        url: {
          raw: `{{baseUrl}}${yol}${sorgu.filter((s) => !s.disabled).length ? '?' + sorgu.filter((s) => !s.disabled).map((s) => `${s.key}=${s.value}`).join('&') : ''}`,
          path: yol.split('/').filter(Boolean).map((p) => (p.startsWith('{') ? ':' + p.slice(1, -1) : p)),
          ...(sorgu.length ? { query: sorgu } : {}),
          variable: yol.split('/').filter((p) => p.startsWith('{')).map((p) => ({ key: p.slice(1, -1) })),
        },
        ...(govde
          ? {
              body: {
                mode: 'raw',
                options: { raw: { language: 'json' } },
                raw: JSON.stringify(ornekDeger(govde), null, 4),
              },
            }
          : {}),
        description:
          [
            op.description || '',
            pdf ? 'Yanıt JSON değil dosyadır (PDF).' : '',
            op.security && op.security.length === 0 ? 'Bu uç erişim anahtarı istemez.' : '',
          ]
            .filter(Boolean)
            .join('\n\n'),
      },
      // auth takli olmayan uclar (kayit/giris) koleksiyon kimligini kullanmaz
      ...(op.security && op.security.length === 0 ? { auth: { type: 'noauth' } } : {}),
    };

    folderler.get(etiket).push(item);
  }
}

const koleksiyon = {
  info: {
    name: 'MRC CRM · ERP · SaaS — API v1',
    description:
      'Tek kiracili MRC kurulumu. Erişim anahtarı: Ayarlar → API Erişim Anahtarları (düz metin yalnız üretim anında görünür). ' +
      'Taban yol {{baseUrl}} (sonunda /api vardır; uçlar /v1/… ile başlar).\n\n' +
      'Hata gövdesi her zaman {"message": "…"}; alan hatalarında ek olarak {"errors": {"alan": ["…"]}}.',
    _postman_id: 'mrc-api-v1',
    schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
  },
  auth: { type: 'bearer', bearer: [{ key: 'token', value: '{{mrc_token}}', type: 'string' }] },
  variable: [
    { key: 'baseUrl', value: serverUrl, type: 'string' },
    { key: 'mrc_token', value: '', type: 'string', disabled: false },
  ],
  item: [...folderler.entries()].map(([ad, istekler]) => ({ name: ad, item: istekler })),
};

// 3-5) ciktilar
fs.writeFileSync(path.join(OUT, 'postman_collection.json'), JSON.stringify(koleksiyon, null, 2));
fs.copyFileSync(SPEC, path.join(OUT, 'api.json'));

redocly(['build-docs', path.relative(ROOT, SPEC), '-o', path.relative(ROOT, path.join(OUT, 'index.html')), '--title', 'MRC API v1'], 'okunabilir HTML');

const toplam = [...folderler.values()].reduce((a, d) => a + d.length, 0);
process.stdout.write(
  `\nHazır: public/docs/{api.json, postman_collection.json, index.html} · ${toplam} istek, ${folderler.size} klasör\n` +
  `Sunucu adresi: ${serverUrl}\n`,
);
