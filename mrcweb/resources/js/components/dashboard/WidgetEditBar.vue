<template>
    <!--
        Canlı düzenleme üst kontrol barı (yalnız düzenleme modunda görünür).

        Dashboard.vue'da 13 widget'ın hepsinde aynı blok elle kopyalanmıştı;
        genişlik butonları (1/3 · 1/2 · 2/3 · Tam), sürükleme tutamağı ve
        "gizle" düğmesi. Bir değişiklik 13 yerde yapılmak zorundaydı.
        Artık tek yer.
    -->
    <div
        class="flex flex-wrap items-center justify-between gap-2 mb-4 px-3 py-2 rounded-2xl bg-[#131722] border border-[#B9FF66]/40 text-xs cursor-grab active:cursor-grabbing"
    >
        <div class="flex items-center gap-2 text-[#B9FF66] font-extrabold">
            <GripVertical class="w-4 h-4" />
            <span>{{ $t(titleKey) }}</span>
        </div>
        <div class="flex items-center gap-1">
            <button
                v-for="sp in SPANS"
                :key="sp"
                type="button"
                @click="$emit('set-span', sp)"
                class="px-2 py-0.5 rounded-lg text-[11px] font-black cursor-pointer transition-colors"
                :class="span === sp ? 'bg-[#B9FF66] text-[#131722]' : 'bg-white/10 text-[#94A3B8] hover:text-white'"
            >
                {{ spanLabel(sp) }}
            </button>
            <button
                type="button"
                @click="$emit('toggle')"
                class="ml-1 px-2 py-0.5 rounded-lg bg-[#C55050]/20 text-[#FFA0A0] hover:bg-[#C55050]/35 font-bold cursor-pointer"
            >
                ✕
            </button>
        </div>
    </div>
</template>

<script setup>
import { GripVertical } from 'lucide-vue-next';
import { t } from '../../i18n';

const props = defineProps({
    /** Widget id'si (kaydetme/olay için). */
    widgetId: { type: String, required: true },
    /** Başlık çeviri anahtarı (ör. "Sürükle: Takvim"). */
    titleKey: { type: String, required: true },
    /** Seçili sütun genişliği (4 / 6 / 8 / 12). */
    span: { type: Number, default: 4 },
});

defineEmits(['set-span', 'toggle']);

// 12 sütunlu bento ızgarası: 4 = 1/3, 6 = 1/2, 8 = 2/3, 12 = tam.
const SPANS = [4, 6, 8, 12];

function spanLabel(span) {
    if (span === 4) return '1/3';
    if (span === 6) return '1/2';
    if (span === 8) return '2/3';
    return t('Tam');
}
</script>
