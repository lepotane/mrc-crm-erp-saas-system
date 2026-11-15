<!--
    Tam ekran kamera overlay — barkod tarayıcı .
    Koyu arayüz kimliği: karartılmış zemin, lime tarama çerçevesi, hap butonlar.
    Son okunan ürün kartı + manuel SKU/barkod girişi (kamerasız fallback).
    V2-3 sayım ekranı da bu bileşeni kullanır (onResolve prop'u sayım akışına bağlanır).
-->
<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[100] bg-black/95 flex flex-col select-none">
            <div class="flex items-center justify-between px-4 pt-4 pb-2">
                <button type="button" @click="close" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white" :title="$t('Kapat')">
                    <X class="w-5 h-5" />
                </button>
                <span class="text-xs font-bold text-white tracking-widest">{{ $t('Barkod tara') }}</span>
                <button
                    v-if="scanner.canTorch.value"
                    type="button"
                    @click="scanner.toggleTorch()"
                    class="w-10 h-10 rounded-full flex items-center justify-center transition-colors"
                    :class="scanner.torchOn.value ? 'bg-[#B9FF66] text-[#131722]' : 'bg-white/10 text-white'"
                    :title="$t('Fener')"
                >
                    <Flashlight class="w-5 h-5" />
                </button>
                <span v-else class="w-10"></span>
            </div>

            <div class="relative grow flex items-center justify-center overflow-hidden">
                <video ref="videoEl" class="absolute inset-0 w-full h-full object-cover" playsinline muted></video>
                <div class="relative w-64 h-40 rounded-2xl border-2 border-[#B9FF66] shadow-[0_0_30px_rgba(185,255,102,0.35)]">
                    <span class="absolute -top-1 -left-1 w-6 h-6 border-t-4 border-l-4 border-[#B9FF66] rounded-tl-xl"></span>
                    <span class="absolute -top-1 -right-1 w-6 h-6 border-t-4 border-r-4 border-[#B9FF66] rounded-tr-xl"></span>
                    <span class="absolute -bottom-1 -left-1 w-6 h-6 border-b-4 border-l-4 border-[#B9FF66] rounded-bl-xl"></span>
                    <span class="absolute -bottom-1 -right-1 w-6 h-6 border-b-4 border-r-4 border-[#B9FF66] rounded-br-xl"></span>
                </div>
                <p v-if="scanner.usingFallback.value" class="absolute bottom-2 text-[11px] text-[#94A3B8]">{{ $t('Uyumluluk modu') }}</p>
            </div>

            <div class="px-4 pb-5 pt-2 space-y-2 bg-[#131722] rounded-t-3xl border-t border-white/10">
                <p v-if="notice" class="text-xs font-semibold text-center" :class="noticeOk ? 'text-[#B9FF66]' : 'text-[#FFA0A0]'">{{ notice }}</p>
                <div v-if="found" class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-white/[0.04] border border-white/10">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-white truncate">{{ found.name }}</p>
                        <p class="text-[11px] text-[#64748B] font-mono">{{ found.barcode || found.sku || '' }} · {{ formatMoney(found.sale_price || 0, 'TRY') }}</p>
                    </div>
                    <PillButton size="sm" variant="primary" @click="confirm(found)">{{ $t('Ekle') }}</PillButton>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="manual"
                        type="text"
                        inputmode="text"
                        :placeholder="$t('Elle barkod / kod yazın')"
                        @keyup.enter="lookupManual"
                        class="grow px-4 py-2.5 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                    />
                    <PillButton size="sm" variant="ghost" :loading="looking" @click="lookupManual">{{ $t('Bul') }}</PillButton>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { X, Flashlight } from 'lucide-vue-next';
import PillButton from './ui/PillButton.vue';
import { useBarcodeScanner, resolveBarcode, isScannerSupported } from '../composables/useBarcodeScanner';
import { formatMoney } from '../utils/format';
import { t } from '../i18n';

const props = defineProps({
    open: { type: Boolean, default: false },
    autoAdd: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'resolve']);

const scanner = useBarcodeScanner();
const videoEl = ref(null);
const manual = ref('');
const found = ref(null);
const notice = ref('');
const noticeOk = ref(true);
const looking = ref(false);

async function onDetect(code) {
    await lookup(code, true);
}

async function lookup(code, fromCamera = false) {
    looking.value = true;
    notice.value = '';
    try {
        const product = await resolveBarcode(code);
        if (!product) {
            noticeOk.value = false;
            notice.value = fromCamera
                ? t('Ürün bulunamadı.')
                : t('Bu kodda ürün yok.');
            if (!fromCamera) found.value = null;
            return;
        }
        found.value = product;
        noticeOk.value = true;
        notice.value = product.name;
        if (props.autoAdd) {
            emit('resolve', product);
            found.value = null;
            notice.value = '';
        }
    } catch {
        noticeOk.value = false;
        notice.value = t('Liste yüklenemedi.');
    } finally {
        looking.value = false;
    }
}

async function lookupManual() {
    const code = manual.value.trim();
    if (!code) return;
    await lookup(code, false);
    manual.value = '';
}

function confirm(product) {
    emit('resolve', product);
    found.value = null;
    notice.value = '';
}

async function close() {
    scanner.stop();
    emit('close');
}

watch(() => props.open, async (v) => {
    if (!v) {
        scanner.stop();
        return;
    }
    found.value = null;
    notice.value = '';
    manual.value = '';
    if (!isScannerSupported()) {
        noticeOk.value = false;
        notice.value = t('Kamera bu cihazda açılamıyor; kodu elle yazın.');
        return;
    }
    await scanner.start(videoEl.value, onDetect);
    if (scanner.error.value === 'camera-denied') {
        noticeOk.value = false;
        notice.value = t('Kamera izni verilmedi.');
    } else if (scanner.error.value === 'camera-insecure') {
        noticeOk.value = false;
        notice.value = t('Kamera yalnız güvenli bağlantıda (HTTPS) çalışır.');
    }
});

onBeforeUnmount(() => scanner.stop());

defineExpose({ lookup });
</script>
