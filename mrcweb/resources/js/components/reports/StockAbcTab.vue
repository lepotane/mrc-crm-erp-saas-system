<!-- ABC analizi sekmesi: reports/stock-abc?days=
     Ciroya göre sınıflar: ilk %80 A (cobalt), %95'e kadar B (orange), kalan C. -->
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
            <template v-else>
                <StatPill v-for="s in summaryPills" :key="s.key" :label-key="s.key" :value="s.value" :cls="s.cls" />
            </template>
            <ExportButtons :disabled="loading || rows.length === 0" @csv="exportCsv" @print="printReport" />
        </div>

        <div ref="printRef" class="rpt-print-area space-y-4">
            <Card rounded="2xl" padding="md">
                <div v-if="loading" class="space-y-2 py-4">
                    <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="rows.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Bu dönemde kesilmiş fatura yok.') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[640px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Ürün') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Ciro') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Pay') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Kümülatif') }}</th>
                                <th class="py-3 font-semibold">{{ $t('Sınıf') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in rows" :key="r.product_id" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white">{{ r.name }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.revenue) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">%{{ (r.share * 100).toFixed(1) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">%{{ (r.cumulative * 100).toFixed(1) }}</td>
                                <td class="py-3">
                                    <StatusPill :color="r.class === 'A' ? 'cobalt' : r.class === 'B' ? 'orange' : 'neutral'" :dot="false">{{ r.class }}</StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!loading && currencyNote" class="mt-3 text-[11px] text-[#64748B]">{{ currencyNote }}</p>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import Card from '../ui/Card.vue';
import StatPill from './StatPill.vue';
import ExportButtons from './ExportButtons.vue';
import StatusPill from '../ui/StatusPill.vue';
import SkeletonPill from '../ui/SkeletonPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';

const props = defineProps({
    // { days }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const serverMeta = ref(null);
const currencyNote = ref('');
const loading = ref(true);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const summaryPills = computed(() => {
    const a = rows.value.filter((r) => r.class === 'A').length;
    const b = rows.value.filter((r) => r.class === 'B').length;
    return [
        { key: 'Toplam Ciro', value: formatMoney(serverMeta.value?.total_revenue ?? 0) },
        { key: 'A Sınıfı', value: formatNumber(a), cls: 'text-white' },
        { key: 'B Sınıfı', value: formatNumber(b), cls: 'text-white' },
        { key: 'C Sınıfı', value: formatNumber(rows.value.length - a - b), cls: 'text-white' },
    ];
});

async function fetchReport() {
    const id = ++reqId;
    loading.value = true;
    try {
        const res = await client.get('/reports/stock-abc', { params: { days: props.applied.days || 90 } });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        serverMeta.value = res.data?.meta || null;
        currencyNote.value = res.data?.meta?.currency_note || '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('ABC raporu yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchReport, { immediate: true, deep: true });

const printRef = ref(null);

function exportCsv() {
    try {
        downloadCsv(`abc-${Date.now()}.csv`, [
            [t('Ürün'), t('Ciro'), t('Pay'), t('Kümülatif'), t('Sınıf')],
            ...rows.value.map((r) => [r.name, r.revenue, r.share, r.cumulative, r.class]),
        ]);
    } catch {
        emit('error', t('CSV üretilemedi.'));
    }
}

function printReport() {
    window.print();
}
</script>
