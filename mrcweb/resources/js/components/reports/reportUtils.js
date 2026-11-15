// Raporlar  paylaşılan yardımcılar.
//
// Etiket seçenekleri çeviri ANAHTARI döndürür; $t() şablonda uygulanır
// (dil değişimi reaktif kalır). Terimler: Cari→Account, Alacak→Receivable,
// Borç→Payable, Yaşlandırma→Ageing, Kâr→Profit, Maliyet→Cost,
// Stok değeri→Inventory value, Giriş/Çıkış→Inbound/Outbound.

import client from '../../api/client';

export const SALES_GROUP_BYS = [
    { value: 'day', key: 'Günlük' },
    { value: 'month', key: 'Aylık' },
    { value: 'category', key: 'Kategoriye göre' },
    { value: 'product', key: 'Ürüne göre' },
    { value: 'party', key: 'Cariye göre' },
];

export const MOVEMENT_GROUP_BYS = [
    { value: 'product', key: 'Ürüne göre' },
    { value: 'warehouse', key: 'Depoya göre' },
    { value: 'type', key: 'Tipe göre' },
    { value: 'day', key: 'Günlük' },
];

export const DOC_TYPE_OPTIONS = [
    { value: 'invoice', key: 'Satış Faturası' },
    { value: 'purchase', key: 'Alım Faturası' },
];

export function yearStartISO() {
    return `${new Date().getFullYear()}-01-01`;
}

export function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

// by_currency boşken {} döner (dizi değil) — girişi dizi çiftlerine çevirir.
export function byCurrencyEntries(map) {
    if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
    return Object.entries(map);
}

// İstemci tarafı süzgeç açıkken alt toplam satırı görünen satırlardan hesaplanır
// (sunucu toplamı süzülmüş görünümü temsil etmez → yanıltıcı olurdu).
export function sumRows(rows, key) {
    return (rows || []).reduce((s, r) => s + (Number(r[key]) || 0), 0);
}

// Sayfalı raporu TÜM sayfaları çekerek döndürür (CSV “bu sayfadaki 15 kayıt”
// tuzağına düşmesin diye). per_page sunucuda 100 ile sınırlı.
export async function fetchAllPages(url, params = {}, { perPage = 100, maxPages = 50 } = {}) {
    const all = [];
    let page = 1;
    while (page <= maxPages) {
        const res = await client.get(url, { params: { ...params, per_page: perPage, page } });
        const rows = res.data?.data || [];
        all.push(...rows);
        const last = Number(res.data?.links?.last ?? res.data?.meta?.last_page ?? 1);
        if (rows.length === 0 || page >= last) break;
        page += 1;
    }
    return all;
}

function csvEscape(v) {
    const s = v === null || v === undefined ? '' : String(v);
    return /[",;\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

// CSV: noktalı sayılar (ham değerler), TR Excel uyumu için ; ayraç + BOM.
export function downloadCsv(filename, headers, rows) {
    const lines = [headers.map(csvEscape).join(';')];
    for (const r of rows || []) lines.push(r.map(csvEscape).join(';'));
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
