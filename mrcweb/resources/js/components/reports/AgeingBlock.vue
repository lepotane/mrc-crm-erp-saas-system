<!-- Yaşlandırma bloğu: receivables + payables kovaları yan yana.
     reports/receivables-aging + reports/payables-aging (?as_of paralel) -->
<template>
    <div class="space-y-4">
        <div v-if="loading" class="grid gap-4 md:grid-cols-2">
            <div v-for="i in 2" :key="i" class="h-64 bg-[#131722]/60 rounded-2xl border border-white/5 animate-pulse"></div>
        </div>
        <div v-else class="grid gap-4 md:grid-cols-2">
            <Card
                v-for="panel in panels"
                :key="panel.side"
                rounded="2xl"
                padding="md"
            >
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h4 class="text-sm font-bold text-white">{{ $t(panel.titleKey) }}</h4>
                    <span class="text-[11px] text-[#64748B]">
                        {{ $t('Açık belge') }}: {{ formatNumber(panel.docCount) }} · {{ $t('Toplam') }}: {{ formatMoney(panel.total) }}
                    </span>
                </div>
                <div class="space-y-1.5">
                    <div
                        v-for="b in panel.buckets"
                        :key="b.key"
                        class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs"
                        :class="b.value > 0 ? 'bg-[#212738] border border-white/10' : 'bg-white/[0.02] border border-white/5'"
                    >
                        <span class="font-semibold" :class="b.value > 0 ? 'text-[#FFAE7C]' : 'text-[#94A3B8]'">{{ $t(b.labelKey) }}</span>
                        <span class="font-bold whitespace-nowrap" :class="b.value > 0 ? 'text-white' : 'text-[#64748B]'">{{ formatMoney(b.value) }}</span>
                    </div>
                </div>
                <div v-if="panel.topParties.length" class="mt-3 pt-3 border-t border-white/[0.08]">
                    <h5 class="text-[11px] font-bold uppercase tracking-wider text-[#64748B] mb-2">{{ $t('En açık cariler') }}</h5>
                    <div class="space-y-1.5">
                        <div
                            v-for="p in panel.topParties"
                            :key="p.party_id"
                            class="flex items-center justify-between gap-2 text-xs px-3 py-2 bg-[#131722] rounded-xl border border-white/5"
                        >
                            <span class="text-white font-semibold truncate">{{ p.name }}</span>
                            <span class="text-right shrink-0">
                                <span class="block font-bold text-[#CBD5E1]">{{ formatMoney(p.open_total) }}</span>
                                <span class="block text-[10px] text-[#64748B]">{{ formatDate(p.oldest_due_date) }}</span>
                            </span>
                        </div>
                    </div>
                </div>
                <p v-if="panel.note" class="mt-3 text-[11px] text-[#64748B]">{{ panel.note }}</p>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import Card from '../ui/Card.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber, formatDate } from '../../utils/format';
import { AGING_BUCKETS } from '../finance/financeUtils';

const props = defineProps({
    // { asOf }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rec = ref(null);
const pay = ref(null);
const loading = ref(true);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

function panelFor(data, side, titleKey) {
    const buckets = (data?.buckets || {});
    return {
        side,
        titleKey,
        buckets: AGING_BUCKETS.map((b) => ({ ...b, value: Number(buckets[b.key] ?? 0) })),
        total: data?.total_open ?? 0,
        docCount: data?.document_count ?? 0,
        topParties: (data?.top_parties || []).slice(0, 5),
        note: data?.meta?.currency_note || '',
    };
}

const panels = computed(() => [
    panelFor(rec.value, 'rec', 'Alacak Yaşlandırma'),
    panelFor(pay.value, 'pay', 'Borç Yaşlandırma'),
]);

async function fetchAging() {
    const id = ++reqId;
    loading.value = true;
    try {
        const params = props.applied.asOf ? { as_of: props.applied.asOf } : {};
        const [recRes, payRes] = await Promise.all([
            client.get('/reports/receivables-aging', { params }),
            client.get('/reports/payables-aging', { params }),
        ]);
        if (!alive || id !== reqId) return;
        rec.value = recRes.data;
        pay.value = payRes.data;
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Yaşlandırma yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchAging, { immediate: true, deep: true });
</script>
