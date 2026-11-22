<template>
    <RouterView />

    <!-- Bağlantı durumu: çevrimdışıyken uyarı, geri gelince bekleyen taslaklar -->
    <div
        v-if="!isOnline"
        class="fixed inset-x-0 top-0 z-[70] bg-[#C55050]/95 backdrop-blur-md text-white px-4 py-2.5 flex items-center justify-center gap-3 shadow-lg"
    >
        <WifiOff class="w-4 h-4 shrink-0" />
        <span class="text-xs sm:text-sm font-bold text-center">
            {{ $t('Çevrimdışısınız. Değişiklikler taslak olarak kaydedilir.') }}
        </span>
        <button
            type="button"
            class="shrink-0 px-3 py-1 rounded-full bg-white/15 hover:bg-white/25 text-[11px] font-black"
            @click="draftsOpen = true"
        >
            {{ $t('Taslaklar') }}
        </button>
    </div>
    <div
        v-else-if="drafts.length && !onlineBannerDismissed"
        class="fixed inset-x-0 top-0 z-[70] bg-[#B9FF66]/95 text-[#131722] px-4 py-2.5 flex items-center justify-center gap-3 shadow-lg"
    >
        <Wifi class="w-4 h-4 shrink-0" />
        <span class="text-xs sm:text-sm font-bold text-center">
            {{ $t('Bağlantı geri geldi. :count taslak bekliyor.', { count: drafts.length }) }}
        </span>
        <button
            type="button"
            class="shrink-0 px-3 py-1 rounded-full bg-[#131722] text-[#B9FF66] text-[11px] font-black"
            @click="draftsOpen = true"
        >
            {{ $t('Yeniden dene') }}
        </button>
        <button
            type="button"
            class="shrink-0 p-1 rounded-full hover:bg-black/10"
            :title="$t('Kapat')"
            @click="onlineBannerDismissed = true"
        >
            <X class="w-3.5 h-3.5" />
        </button>
    </div>

    <!-- İlk girişte bildirim izni: merkezi modal, her şeyin üstünde -->
    <div
        v-if="showNotifyPrompt"
        class="fixed inset-0 z-[95] flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        @click.self="skipNotifications"
    >
        <div class="w-full max-w-sm rounded-2xl bg-[#212738] border border-white/15 shadow-[0_20px_50px_rgba(0,0,0,0.6)] p-5">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#B9FF66]/15 text-[#B9FF66] flex items-center justify-center shrink-0">
                    <BellRing class="w-5 h-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-black text-white leading-snug">
                        {{ $t('Bildirimleri açmak ister misiniz?') }}
                    </p>
                    <p class="text-[11px] text-[#94A3B8] mt-1">
                        {{ $t('Hata ve kritik stok uyarılarını telefonunda gör.') }}
                    </p>
                </div>
                <button type="button" class="p-1 text-[#64748B] hover:text-white" :title="$t('Kapat')" @click="skipNotifications">
                    <X class="w-4 h-4" />
                </button>
            </div>
            <div class="flex gap-2 mt-5">
                <button
                    type="button"
                    class="flex-1 px-4 py-2.5 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-black hover:bg-[#a6f050] cursor-pointer"
                    @click="allowNotifications"
                >
                    {{ $t('İzin ver') }}
                </button>
                <button
                    type="button"
                    class="flex-1 px-4 py-2.5 rounded-full bg-white/5 text-[#94A3B8] text-xs font-bold hover:text-white hover:bg-white/10 cursor-pointer"
                    @click="skipNotifications"
                >
                    {{ $t('Şimdi değil') }}
                </button>
            </div>
        </div>
    </div>

    <!-- PWA hızlı işlem yığını (yalnızca giriş yapmışken) -->
    <div
        v-if="showPwaUi"
        class="fixed bottom-20 right-4 lg:bottom-6 lg:right-6 z-[50] flex flex-col items-end gap-2 pointer-events-none"
    >
        <div v-if="quickScanEnabled" class="pointer-events-auto">
            <QuickScanCard @disable="setQuickScanEnabled(false)" />
        </div>

        <button
            v-else
            type="button"
            class="pointer-events-auto flex items-center gap-2 px-3 py-2 rounded-full bg-[#212738]/95 backdrop-blur-md border border-white/10 text-[#94A3B8] text-[11px] font-bold shadow-lg hover:text-white"
            :title="$t('Hızlı tara kartını aç')"
            @click="setQuickScanEnabled(true)"
        >
            <ScanBarcode class="w-4 h-4" />
            {{ $t('Hızlı Tara') }}
        </button>

        <button
            v-if="notifyPermission !== 'granted' && notifyPermission !== 'unsupported'"
            type="button"
            class="pointer-events-auto flex items-center gap-2 px-3 py-2 rounded-full bg-[#212738]/95 backdrop-blur-md border border-[#B9FF66]/30 text-[#B9FF66] text-[11px] font-bold shadow-lg"
            :title="$t('Bildirimleri aç')"
            @click="openNotifyPrompt"
        >
            <BellRing class="w-4 h-4" />
            {{ $t('Bildirimler') }}
        </button>

        <button
            v-if="drafts.length"
            type="button"
            class="pointer-events-auto flex items-center gap-2 px-3 py-2 rounded-full bg-[#212738]/95 backdrop-blur-md border border-[#DC6F31]/40 text-[#FDBA74] text-xs font-bold shadow-lg hover:bg-[#282F44]"
            @click="draftsOpen = true"
        >
            <FileClock class="w-4 h-4" />
            <span>{{ $t('Taslaklar') }}</span>
            <span class="px-1.5 rounded-full bg-[#DC6F31]/25 text-[10px]">{{ drafts.length }}</span>
        </button>

        <div
            v-if="isInstallable && !installDismissed"
            class="pointer-events-auto w-56 max-w-full rounded-2xl bg-[#212738]/95 backdrop-blur-md border border-white/15 shadow-lg p-3"
        >
            <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-bold text-white leading-snug">{{ $t('Uygulama olarak kur') }}</p>
                <button
                    type="button"
                    class="shrink-0 text-[#64748B] hover:text-white"
                    :title="$t('Kapat')"
                    @click="installDismissed = true"
                >
                    <X class="w-3.5 h-3.5" />
                </button>
            </div>
            <button
                type="button"
                class="mt-2.5 w-full flex items-center justify-center gap-2 px-3 py-2 rounded-full bg-[#B9FF66] text-[#131722] text-[11px] font-black hover:bg-[#a6f050]"
                @click="installApp"
            >
                <Download class="w-3.5 h-3.5" />
                {{ $t('Uygulama olarak kur') }}
            </button>
        </div>
    </div>

    <OfflineDraftsModal
        :open="draftsOpen"
        :drafts="drafts"
        @close="draftsOpen = false"
        @remove="deleteDraft"
    />
</template>

<script setup>
import { ref, computed, provide, watch, onMounted, onUnmounted } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import { WifiOff, Wifi, FileClock, X, BellRing, Download, ScanBarcode } from 'lucide-vue-next';
import client from './api/client';
import router from './router';
import { useAuthStore } from './stores/auth';
import { t } from './i18n';
import OfflineDraftsModal from './components/pwa/OfflineDraftsModal.vue';
import QuickScanCard from './components/pwa/QuickScanCard.vue';
import {
    usePwa,
    isOnline,
    isInstallable,
    quickScanEnabled,
    setQuickScanEnabled,
    promptInstall,
    requestNotifyPermission,
    getNotifyPermission,
    notify,
    hasSeenNotifyPrompt,
    markNotifyPromptSeen,
    loadDrafts,
    removeDraft,
    setPwaScope,
    clearPwaScope,
} from './composables/usePwa';

// online/offline + beforeinstallprompt dinleyicilerini bir kez kurar.
usePwa();

const route = useRoute();
const authStore = useAuthStore();

const drafts = ref([]);
const draftsOpen = ref(false);
const showNotifyPrompt = ref(false);
// Reaktif izin durumu: izin verildikten sonra sağ alttaki chip anında kaybolur.
const notifyPermission = ref(getNotifyPermission());
const installDismissed = ref(false);
const onlineBannerDismissed = ref(false);

const GUEST_ROUTES = ['/login', '/register', '/sifremi-unuttum', '/sifre-sifirla', '/dogrulandi'];
const showPwaUi = computed(() => {
    if (GUEST_ROUTES.includes(route.path)) return false;
    try {
        return !!localStorage.getItem('mrc_token');
    } catch {
        return false;
    }
});

function refreshDrafts() {
    drafts.value = loadDrafts();
}

function deleteDraft(draft) {
    removeDraft(draft?.id);
    refreshDrafts();
}

/**
 * Modalın inject ettiği yeniden deneme geri çağrısı.
 * Taslak, üreten sayfa tarafından { endpoint, method, payload, route } bilgisiyle
 * kaydedilmişse axios istemcisiyle tekrar gönderilir; aksi halde kullanıcı
 * mevcut ilgili rotaya yönlendirilir ve taslak elle tamamlanır.
 */
async function retryDraft(draft) {
    if (!isOnline.value) {
        return { ok: false, message: t('Bağlantı geri gelene kadar bekleyin.') };
    }

    const meta = draft?.data || {};
    const endpoint = meta.endpoint || meta.url;
    if (!endpoint) {
        const target = typeof meta.route === 'string' && meta.route.startsWith('/') ? meta.route : '/urunler';
        await router.push(target);
        return {
            ok: true,
            message: t('Taslak için kayıtlı gönderim adresi yok; ilgili sayfaya yönlendirildiniz.'),
        };
    }

    try {
        const body = Object.prototype.hasOwnProperty.call(meta, 'payload') ? meta.payload : meta;
        await client.request({
            method: meta.method || 'post',
            url: endpoint,
            data: body,
        });
        removeDraft(draft.id);
        refreshDrafts();
        return { ok: true, message: t('Taslak gönderildi.') };
    } catch {
        return { ok: false, message: t('Taslak gönderilemedi.') };
    }
}

provide('pwaRetryDraft', retryDraft);

function syncScope() {
    if (authStore.token && (authStore.company || authStore.user)) {
        setPwaScope({
            companyId: authStore.company?.id,
            userId: authStore.user?.id,
        });
    } else if (!authStore.token) {
        clearPwaScope();
    }
    refreshDrafts();
}

async function allowNotifications() {
    // Önce kapat: izin isteği bazı tarayıcılarda (PWA standalone) yavaş veya
    // sessiz yanıt verir; buton donmuş görünmesin.
    showNotifyPrompt.value = false;
    markNotifyPromptSeen();
    try {
        await requestNotifyPermission();
        notifyPermission.value = getNotifyPermission();
    } catch { /* sessiz */ }
}

function skipNotifications() {
    markNotifyPromptSeen();
    showNotifyPrompt.value = false;
}

function openNotifyPrompt() {
    showNotifyPrompt.value = true;
}

async function installApp() {
    await promptInstall();
}

// GERÇEK WEB PUSH DEĞİL: yalnızca yerel uyarı. Sunucudan push almak için
// VAPID anahtarları + PushManager.subscribe() + abonelik endpoint'i gerekir.
const HEALTH_POLL_MS = 60_000;
let healthTimer = 0;
let lastHealthFingerprint = '';

async function pollIntegrationHealth() {
    if (!isOnline.value || !authStore.token) return;
    try {
        const { data } = await client.get('/integrations/health');
        const channels = Object.values(data?.data || {});
        const failing = channels.filter((item) => item && (Number(item.failed_count) > 0 || item.last_error));
        const fingerprint = failing
            .map((item) => `${item.channel || '?'}|${Number(item.failed_count) || 0}|${item.last_error || ''}`)
            .join('||');

        if (failing.length && fingerprint !== lastHealthFingerprint) {
            const first = failing[0];
            notify(
                t('Entegrasyon hatası'),
                t(':channel entegrasyonunda senkronizasyon hatası.', {
                    channel: first.channel || t('Bilinmeyen kanal'),
                }),
                { tag: 'mrc-integration-health' },
            );
        }
        lastHealthFingerprint = fingerprint;
    } catch {
        // Endpoint yalnızca Admin kullanıcılara açık; diğerlerinde 403 gelir.
        // Ağ hatası/oturum kapalı olması da sessizce yoksayılır.
    }
}

watch(
    () => [authStore.token, authStore.company?.id, authStore.user?.id],
    syncScope,
);

watch(isOnline, (online) => {
    if (!online) onlineBannerDismissed.value = false;
});

onMounted(() => {
    syncScope();
    notifyPermission.value = getNotifyPermission();
    if (authStore.token && !hasSeenNotifyPrompt() && notifyPermission.value === 'default') {
        showNotifyPrompt.value = true;
    } else if (notifyPermission.value === 'granted' || notifyPermission.value === 'denied') {
        // Kullanıcı tarayıcıdan izin vermiş/reddetmişse bir daha sorma.
        markNotifyPromptSeen();
    }
    healthTimer = window.setInterval(pollIntegrationHealth, HEALTH_POLL_MS);
});

onUnmounted(() => {
    if (healthTimer) window.clearInterval(healthTimer);
});
</script>
