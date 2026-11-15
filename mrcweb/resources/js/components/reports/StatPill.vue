<!-- Rapor özet hapı. Tıklanabilir olduğunda SATIR SÜZGECİDİR:
     aynı hap hem sayıyı gösterir hem de tabloyu ona göre süzer (rozet süzgeci).
     Tıklanabilir hapların yanında süzgecin ne yaptığı yazar (başlık/`hintKey`). -->
<template>
    <component
        :is="clickable ? 'button' : 'div'"
        :type="clickable ? 'button' : undefined"
        @click="clickable && $emit('click')"
        class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border shadow-xs transition-all"
        :class="[
            active
                ? 'border-[#B9FF66]/60 ring-2 ring-[#B9FF66]/25'
                : 'border-white/10',
            clickable ? 'cursor-pointer hover:border-white/25' : '',
        ]"
        :title="clickable && hintKey ? $t(hintKey) : undefined"
        :aria-pressed="clickable ? String(active) : undefined"
    >
        <Filter v-if="clickable" class="w-3 h-3 shrink-0" :class="active ? 'text-[#B9FF66]' : 'text-[#64748B]'" />
        <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t(labelKey) }}</span>
        <span class="text-xs sm:text-sm font-bold" :class="cls || 'text-white'">{{ value }}</span>
    </component>
</template>

<script setup>
import { Filter } from 'lucide-vue-next';

defineProps({
    labelKey: { type: String, required: true },
    value: { type: [String, Number], default: '' },
    cls: { type: String, default: '' },
    clickable: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
    // tıklanabilir hapın ne süzdüğünü anlatan çeviri anahtarı
    hintKey: { type: String, default: '' },
});

defineEmits(['click']);
</script>
