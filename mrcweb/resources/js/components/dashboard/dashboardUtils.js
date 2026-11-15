// Panel  paylaşılan yardımcılar: tarih aralığı hesapları + para birimi
// kırılımı satırları. Grafikler saf CSS/SVG ile çizilir, yeni npm paketi yok.

export function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

export function monthStartISO() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

// n ay önceki ayın ilk günü (örn. 5 → içinde bulunulan ayla birlikte 6 takvim ayı).
export function monthsAgoStartISO(n) {
    const d = new Date();
    const first = new Date(d.getFullYear(), d.getMonth() - n, 1);
    return `${first.getFullYear()}-${String(first.getMonth() + 1).padStart(2, '0')}-01`;
}

export function daysAgoISO(n) {
    const t = new Date();
    t.setDate(t.getDate() - n);
    return t.toISOString().slice(0, 10);
}

// Grafik çubuğu üstü kısa tutar: 1.2K / 3.4M biçimi.
export function shortMoney(v) {
    const n = Number(v) || 0;
    const abs = Math.abs(n);
    if (abs >= 1000000) return `${(n / 1000000).toFixed(1)}M`;
    if (abs >= 1000) return `${(n / 1000).toFixed(1)}K`;
    return String(Math.round(n));
}

// by_currency haritasını { ccy, amount } satırlarına çevirir.
// Alacak yaşlandırmada değer SKALER tutardır ({"TRY":216}); diğer uçlarda
// nesnedir ({"TRY":{net_total,...}}) — iki biçimi de karşılar.
export function currencyLines(map, preferredKeys) {
    if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
    const out = [];
    for (const [ccy, v] of Object.entries(map)) {
        if (v !== null && typeof v === 'object') {
            let picked = null;
            for (const k of preferredKeys || []) {
                if (typeof v[k] === 'number') { picked = v[k]; break; }
            }
            if (picked === null) {
                const firstNum = Object.values(v).find((x) => typeof x === 'number');
                picked = firstNum ?? 0;
            }
            out.push({ ccy, amount: picked });
        } else if (typeof v === 'number') {
            out.push({ ccy, amount: v });
        }
    }
    return out;
}
