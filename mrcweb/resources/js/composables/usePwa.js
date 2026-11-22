/**
 * PWA yardımcıları: çevrimdışı taslaklar, kurulum istemi, yerel bildirimler.
 *
 * ÖNEMLİ (Web Push): Bu dosya YALNIZCA tarayıcının Notification API'sini kullanarak
 * *yerelde üretilen* bildirimler gösterir. Sunucudan gelen gerçek Web Push için
 * VAPID anahtar çifti, PushManager.subscribe() ve aboneliği saklayıp push
 * gönderecek bir sunucu endpoint'i gerekir. Bu altyapı henüz yoktur ve bu
 * değişiklik kapsamında hiçbir backend rotası eklenmemektedir. `notify()` bu
 * yüzden "sunucu bildirimi" değil, istemci tarafı uyarı olarak kalır.
 */

import { ref } from 'vue';

const DRAFTS_KEY = 'mrc_offline_drafts';
const SCOPE_KEY = 'mrc_pwa_draft_scope';
const NOTIFY_PROMPT_KEY = 'mrc_pwa_notify_prompt_seen';

export const isOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false);
export const isInstallable = ref(false);

let deferredPrompt = null;
let listenersReady = false;
let draftScope = readStoredScope();

function readStoredScope() {
    try {
        return localStorage.getItem(SCOPE_KEY) || '';
    } catch {
        return '';
    }
}

function currentScope() {
    return draftScope || 'guest';
}

function ensureListeners() {
    if (listenersReady || typeof window === 'undefined') return;
    listenersReady = true;

    window.addEventListener('online', () => { isOnline.value = true; });
    window.addEventListener('offline', () => { isOnline.value = false; });

    window.addEventListener('beforeinstallprompt', (event) => {
        // Varsayılanı engellemezsek Chrome kendi mini infobar'ını gösterir ve
        // bizim "Uygulama olarak kur" düğmemiz çalışmaz.
        if (typeof event.preventDefault === 'function') event.preventDefault();
        deferredPrompt = event;
        isInstallable.value = true;
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        isInstallable.value = false;
    });
}

/** Firma/kullanıcı bağlamını taslak anahtarlarına bağlar (App.vue çağırır). */
export function setPwaScope(scope = {}) {
    if (!scope || typeof scope !== 'object') return;
    const companyId = scope.companyId ?? scope.company?.id ?? scope.company_id ?? '';
    const userId = scope.userId ?? scope.user?.id ?? scope.user_id ?? '';
    if (!companyId && !userId) return;
    draftScope = `${companyId || 'company'}::${userId || 'user'}`;
    try {
        localStorage.setItem(SCOPE_KEY, draftScope);
    } catch {
        // depolama kapalıysa bellekteki kapsam yeterli
    }
}

export function clearPwaScope() {
    draftScope = '';
    try {
        localStorage.removeItem(SCOPE_KEY);
    } catch {
        // sessiz
    }
}

export async function promptInstall() {
    if (!deferredPrompt || typeof deferredPrompt.prompt !== 'function') return false;
    try {
        deferredPrompt.prompt();
        const choice = await deferredPrompt.userChoice;
        return choice?.outcome === 'accepted';
    } catch {
        return false;
    } finally {
        deferredPrompt = null;
        isInstallable.value = false;
    }
}

function notificationApi() {
    if (typeof window === 'undefined' || !('Notification' in window)) return null;
    return window.Notification;
}

export function getNotifyPermission() {
    return notificationApi()?.permission || 'unsupported';
}

export async function requestNotifyPermission() {
    const N = notificationApi();
    if (!N) return 'unsupported';
    if (N.permission === 'granted' || N.permission === 'denied') return N.permission;
    try {
        return await N.requestPermission();
    } catch {
        return 'denied';
    }
}

/** Yerel bildirim gösterir. İzin verilmemişse hiçbir şey yapmaz. */
export function notify(title, body, options = {}) {
    const N = notificationApi();
    if (!N || N.permission !== 'granted') return false;

    const details = {
        body,
        icon: '/icons/icon-192.png?v=2',
        badge: '/icons/icon-192.png?v=2',
        tag: 'mrc',
        ...options,
    };

    try {
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.ready
                .then((registration) => registration.showNotification(title, details))
                .catch(() => new N(title, details));
        } else {
            new N(title, details);
        }
        return true;
    } catch {
        return false;
    }
}

export function hasSeenNotifyPrompt() {
    try {
        return localStorage.getItem(NOTIFY_PROMPT_KEY) === '1';
    } catch {
        // okunamıyorsa tekrar sormamak daha güvenli
        return true;
    }
}

export function markNotifyPromptSeen() {
    try {
        localStorage.setItem(NOTIFY_PROMPT_KEY, '1');
    } catch {
        // sessiz
    }
}

function readDraftStore() {
    try {
        const raw = localStorage.getItem(DRAFTS_KEY);
        const parsed = raw ? JSON.parse(raw) : {};
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

function writeDraftStore(store) {
    try {
        localStorage.setItem(DRAFTS_KEY, JSON.stringify(store));
        return true;
    } catch {
        // kota dolu / gizli mod: taslak kaydedilemedi
        return false;
    }
}

/** key, firma/kullanıcı kapsamında saklanır; benzersiz tam anahtar döner. */
export function saveDraft(key, data) {
    const scope = currentScope();
    const id = `${scope}::${String(key ?? '')}`;
    const store = readDraftStore();
    store[id] = {
        id,
        key: String(key ?? ''),
        scope,
        data,
        saved_at: new Date().toISOString(),
    };
    return writeDraftStore(store) ? id : null;
}

/** Yalnızca aktif firma/kullanıcı kapsamındaki taslaklar, en yeni ilk. */
export function loadDrafts() {
    const scope = currentScope();
    return Object.values(readDraftStore())
        .filter((draft) => draft && draft.scope === scope)
        .sort((a, b) => String(b.saved_at).localeCompare(String(a.saved_at)));
}

/** Tam id veya kısa key kabul eder. */
export function removeDraft(key) {
    const shortKey = String(key ?? '');
    const store = readDraftStore();
    const target = Object.prototype.hasOwnProperty.call(store, shortKey)
        ? shortKey
        : `${currentScope()}::${shortKey}`;
    if (!Object.prototype.hasOwnProperty.call(store, target)) return false;
    delete store[target];
    return writeDraftStore(store);
}

export function usePwa() {
    ensureListeners();
    return {
        isOnline,
        isInstallable,
        promptInstall,
        requestNotifyPermission,
        getNotifyPermission,
        notify,
        hasSeenNotifyPrompt,
        markNotifyPromptSeen,
        saveDraft,
        loadDrafts,
        removeDraft,
        setPwaScope,
        clearPwaScope,
    };
}

export default usePwa;
