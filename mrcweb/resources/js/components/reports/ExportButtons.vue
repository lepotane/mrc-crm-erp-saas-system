<!-- Rapor sekmelerinin ortak dışa aktarma düğmeleri: CSV indir + Yazdır.
     Bileşen bilerek paylaşıldı — beş sekmede aynı Sözleşme (aynı yer, aynı söz). -->
<template>
    <div class="flex items-center gap-2 sm:ml-auto">
        <PillButton variant="secondary" size="sm" :disabled="disabled" :loading="loading" @click="$emit('csv')">
            <Download class="w-3.5 h-3.5" />
            <span>{{ $t('CSV indir') }}</span>
        </PillButton>
        <PillButton variant="ghost" size="sm" :disabled="disabled" @click="$emit('print')">
            <Printer class="w-3.5 h-3.5" />
            <span>{{ $t('Yazdır') }}</span>
        </PillButton>
    </div>
</template>

<script setup>
import { Download, Printer } from 'lucide-vue-next';
import PillButton from '../ui/PillButton.vue';

defineProps({
    disabled: { type: Boolean, default: false },
    // CSV çok sayfalı veri çekiyorsa düğme yüklenir
    loading: { type: Boolean, default: false },
});

defineEmits(['csv', 'print']);
</script>
