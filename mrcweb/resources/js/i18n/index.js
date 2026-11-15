import { ref } from 'vue';
import en from './locales/en.json';

const SUPPORTED = ['tr', 'en'];
const STORAGE_KEY = 'mrc_locale';

function initialLocale() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (SUPPORTED.includes(stored)) return stored;
    } catch {
        // localStorage erişilemezse Türkçe varsayılan
    }
    return 'tr';
}

// Reaktif dil durumu - modül düzeyinde tek kaynak
export const locale = ref(initialLocale());

// CSS `text-transform: uppercase` harf çevirisini belgenin diline göre yapar:
// <html lang="tr"> iken İngilizce etiketler bozulur (“Line count” → “LİNE COUNT”,
// “Receivable” → “RECEİVABLE”). Bu yüzden arayüz dili değiştiğinde lang özniteliği
// de güncellenir (Türkçe ana dil olduğu için Blade tarafındaki varsayılan tr kalır).
function applyDocumentLang(l) {
    if (typeof document !== 'undefined') {
        document.documentElement.lang = l === 'en' ? 'en' : 'tr';
    }
}

applyDocumentLang(locale.value);

let formats = { tr: 'tr-TR', en: 'en-US' };

const dictionaries = { en };

export function setLocale(l) {
    if (!SUPPORTED.includes(l)) return;
    locale.value = l;
    applyDocumentLang(l);
    try {
        localStorage.setItem(STORAGE_KEY, l);
    } catch {
        // yazılamazsa sessiz geç
    }
}

// Sunucudaki kullanıcı tercihiyle eşitler
export function syncLocaleFromUser(userLocale) {
    if (SUPPORTED.includes(userLocale)) {
        setLocale(userLocale);
    }
}

// /api/v1/me cevabındaki locale_formats ile ezilebilir
export function setLocaleFormats(obj) {
    if (obj && typeof obj === 'object') {
        for (const k of SUPPORTED) {
            if (typeof obj[k] === 'string' && obj[k]) formats[k] = obj[k];
        }
    }
}

// tr: eksik anahtar = anahtarın kendisi (Türkçe metin döner)
//
// EN tekil/çoğul: sözlük değeri "tekil|çoğul" biçiminde yazılabilir. Biçim,
// sayı parametresinden seçilir (:n → :count → :total sırasıyla bakılır; 1 ise
// tekil). Türkçede sayıdan sonra tekil kullanılır, o yüzden TR metinlerde "|"
// olmaz ve bu dallanma İngilizce için çalışır.
function pickPluralForm(text, params) {
    if (locale.value !== 'en' || !text.includes('|')) return text;
    const forms = text.split('|');
    if (forms.length < 2) return text;
    const raw = params.n ?? params.count ?? params.total;
    const n = Number(raw);
    if (!Number.isFinite(n)) return forms[forms.length - 1];
    return n === 1 ? forms[0] : forms[1];
}

export function t(key, params = {}) {
    let out = key;
    if (locale.value !== 'tr') {
        const dict = dictionaries[locale.value] || {};
        if (Object.prototype.hasOwnProperty.call(dict, key)) out = dict[key];
    }
    out = pickPluralForm(out, params || {});
    if (params && typeof params === 'object') {
        for (const [k, v] of Object.entries(params)) {
            out = out.split(':' + k).join(String(v));
        }
    }
    return out;
}

export function localeTag() {
    return formats[locale.value] || (locale.value === 'en' ? 'en-US' : 'tr-TR');
}

// Belirli dilde çeviri : belge ÇIKTI dilindeki önizleme etiketleri
// için kullanılır; uygulamanın genel dilini DEĞİŞTİRMEZ. EN sözlüğünde yoksa
// Türkçe anahtar döner (sessiz düşüş — boş etiket çıkmaz).
export function tIn(lang, key, params = {}) {
    let out = key;
    if (lang === 'en') {
        const dict = dictionaries.en || {};
        if (Object.prototype.hasOwnProperty.call(dict, key)) out = dict[key];
        if (out.includes('|')) {
            const forms = out.split('|');
            if (forms.length >= 2) {
                const raw = (params || {}).n ?? (params || {}).count ?? (params || {}).total;
                const n = Number(raw);
                out = Number.isFinite(n) ? (n === 1 ? forms[0] : forms[1]) : forms[forms.length - 1];
            }
        }
    }
    if (params && typeof params === 'object') {
        for (const [k, v] of Object.entries(params)) {
            out = out.split(':' + k).join(String(v));
        }
    }
    return out;
}

export function useI18n() {
    return { t, locale, setLocale, syncLocaleFromUser, setLocaleFormats, localeTag };
}

export default { t, tIn, locale, setLocale, syncLocaleFromUser, setLocaleFormats, localeTag, useI18n };
