// Borç-Alacak  paylaşılan yardımcılar.
//
// Etiket fonksiyonları çeviri ANAHTARI döndürür; $t() şablonda uygulanır
// (dil değişimi reaktif kalır). Tutarlılık: Cari→Account, Tahsilat→Receipt,
// Ödeme→Payment, Alacak→Receivable, Borç→Payable, Kasa→Cash drawer,
// Banka hesabı→Bank account, Vade→Due date, Yaşlandırma→Ageing.

export function firstMessage(err) {
    if (!err) return '';
    return Array.isArray(err) ? err[0] : err;
}

// Ödeme yönü: 'in' (tahsilat) / 'out' (ödeme)
export function directionLabelKey(direction) {
    if (direction === 'in') return 'Tahsilat';
    if (direction === 'out') return 'Ödeme';
    return direction || '-';
}

export function directionColor(direction) {
    if (direction === 'in') return 'forest';
    if (direction === 'out') return 'orange';
    return 'neutral';
}

// Cari tipi: 'customer' / 'supplier' / 'both'
export function partyTypeLabelKey(type) {
    if (type === 'customer') return 'Müşteri';
    if (type === 'supplier') return 'Tedarikçi';
    if (type === 'both') return 'Her İkisi';
    return type || '-';
}

export function partyTypeColor(type) {
    if (type === 'customer') return 'lime';
    if (type === 'supplier') return 'orange';
    if (type === 'both') return 'violet';
    return 'neutral';
}

// Ödeme yöntemi: cash / bank / card / transfer
export function methodLabelKey(method) {
    if (method === 'cash') return 'Nakit';
    if (method === 'bank') return 'Banka';
    if (method === 'card') return 'Kart';
    if (method === 'transfer') return 'Havale / EFT';
    return method || '-';
}

// Kasa/banka hesap tipi: cash / bank / card
export function accountTypeLabelKey(type) {
    if (type === 'cash') return 'Kasa';
    if (type === 'bank') return 'Banka hesabı';
    if (type === 'card') return 'Kart hesabı';
    return type || '-';
}

export function accountTypeColor(type) {
    if (type === 'cash') return 'forest';
    if (type === 'bank') return 'cobalt';
    if (type === 'card') return 'violet';
    return 'neutral';
}

// Yaşlandırma kovaları (sabit sıra).
export const AGING_BUCKETS = [
    { key: 'not_due', labelKey: 'Vadesi Gelmemiş' },
    { key: 'days_1_30', labelKey: '1-30 gün gecikmiş' },
    { key: 'days_31_60', labelKey: '31-60 gün gecikmiş' },
    { key: 'days_61_90', labelKey: '61-90 gün gecikmiş' },
    { key: 'days_over_90', labelKey: '90+ gün gecikmiş' },
];

export function todayISO() {
    return new Date().toISOString().slice(0, 10);
}
