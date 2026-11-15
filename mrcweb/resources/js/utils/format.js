import { localeTag } from '../i18n';

// Aktif dile bağlı biçimlendirme yardımcıları (tr'de tr-TR davranışı korunur)
export function formatNumber(v) {
    return new Intl.NumberFormat(localeTag()).format(Number(v) || 0);
}

export function formatMoney(v, currency) {
    if (v === null || v === undefined || v === '') return '-';
    const symbols = { TRY: '₺', USD: '$', EUR: '€' };
    const prefix = symbols[currency] || '';
    // Para her zaman 2 basamak: varsayılan Intl ayarı 1951.5 için „1.951,5" üretiyordu.
    const nf = new Intl.NumberFormat(localeTag(), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return `${prefix} ${nf.format(Number(v) || 0)}`.trim();
}

export function formatDate(iso, opts) {
    if (!iso) return '-';
    try {
        const d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return new Intl.DateTimeFormat(
            localeTag(),
            opts || { day: 'numeric', month: 'short', year: 'numeric' }
        ).format(d);
    } catch {
        return iso;
    }
}
