import { ref } from 'vue';
import client from './client';

/**
 * Firma ayarları (GET /settings) — yeni cari/ürün/belge formlarının başlangıç
 * değerlerinin TEK kaynağı.
 *
 * Karar 21/A: bu değerler uzun süre yalnız Ayarlar ekranında duruyordu; formlar
 * kendi sabitlerini (%20 KDV, TRY, vadesiz) yazıyordu. İki ayrı "varsayılan"
 * bir süre sonra birbirinden sapar. Sunucu artık ayarı ürette uygular, SPA ise
 * aynı değeri öneri olarak gösterir; kullanıcı alanı elle değiştirirse onun
 * değeri kazanır.
 */
export const DEFAULTS = {
    currency_code: 'TRY',
    default_tax_rate: 20,
    invoice_series_prefix: 'FTR',
    default_payment_days: 7,
};

export const companySettings = ref({ ...DEFAULTS });

let promise = null;

/** Ayarları bir kez çeker; sonraki açılışlar önbellekten okur. Hata sessizdir:
 *  form yine sistem varsayılanlarıyla açılır (engellenmiş akış bırakılmaz). */
export function loadCompanySettings(force = false) {
    if (promise && !force) return promise;

    promise = client
        .get('/settings')
        .then((res) => {
            companySettings.value = { ...DEFAULTS, ...(res.data?.settings || {}) };
            return companySettings.value;
        })
        .catch(() => {
            promise = null; // sonraki açılışta tekrar denensin
            return companySettings.value;
        });

    return promise;
}

/**
 * Vade önerisi: yalnız parayı hareket geçiren belgelerde (fatura / alım faturası)
 * ve vade ayarı 0'dan büyükse. Teklif/sipariş vadesiz doğar — sunucudaki
 * `DocumentController::store` ile birebir aynı kural.
 */
export function dueDateForTerm(issueDate, type, days = companySettings.value.default_payment_days) {
    if (!issueDate || Number(days) <= 0) return '';
    if (type !== 'invoice' && type !== 'purchase') return '';

    const d = new Date(`${String(issueDate).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(d.getTime())) return '';
    d.setDate(d.getDate() + Number(days));

    return d.toISOString().slice(0, 10);
}
