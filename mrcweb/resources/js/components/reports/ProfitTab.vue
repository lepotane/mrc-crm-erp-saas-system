<!-- Kâr raporu sekmesi: reports/profit?from&to&group_by=day|product|category
     İadeler netlenmiştir (işaretli toplama): ciro ve COGS iade kadar düşer.
     Satır marjı = kâr / net. -->
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
                    <table class="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Kırılım') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Belge') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Ciro') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Net') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Maliyet') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Kâr') }}</th>
                                <th class="py-3 font-semibold text-right">{{ $t('Marj') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in rows" :key="r.currency_code + String(r.key)" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white">{{ r.label }} <span class="text-[11px] font-normal text-[#64748B]">{{ r.currency_code }}</span></td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.document_count) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.gross_total) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.net_total) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.cogs_total) }}</td>
                                <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="r.profit < 0 ? 'text-[#FFA0A0]' : 'text-white'">{{ formatMoney(r.profit) }}</td>
                                <td class="py-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ r.margin === null ? '—' : '%' + (r.margin * 100).toFixed(1) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/[0.12] font-bold">
                                <td class="py-3 pr-3 text-white">{{ $t('Toplam') }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatNumber(serverTotals?.document_count ?? 0) }}</td>
                                <td class="py-3 pr-3"></td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(serverTotals?.net_total ?? 0) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(serverTotals?.cogs_total ?? 0) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(serverTotals?.profit ?? 0) }}</td>
                                <td class="py-3 text-right text-white whitespace-nowrap">{{ serverTotals?.margin === null || serverTotals?.margin === undefined ? '—' : '%' + (serverTotals.margin * 100).toFixed(1) }}</td>
                            </tr>
                        </tfoot>
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
import SkeletonPill from '../ui/SkeletonPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';

const props = defineProps({
    // { from, to, groupBy: day|product|category }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const serverTotals = ref(null);
const currencyNote = ref('');
const loading = ref(true);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const summaryPills = computed(() => [
    { key: 'Ciro', value: formatMoney(rows.value.reduce((s, r) => s + (Number(r.gross_total) || 0), 0)) },
    { key: 'Kâr', value: formatMoney(serverTotals.value?.profit ?? 0) },
    {
        key: 'Marj',
        value: serverTotals.value?.margin === null || serverTotals.value?.margin === undefined
            ? '—' : '%' + (serverTotals.value.margin * 100).toFixed(1),
    },
]);

async function fetchReport() {
    const id = ++reqId;
    loading.value = true;
    try {
        const res = await client.get('/reports/profit', {
            params: { from: props.applied.from, to: props.applied.to, group_by: props.applied.groupBy || 'day' },
        });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        serverTotals.value = res.data?.totals || null;
        currencyNote.value = res.data?.meta?.currency_note || '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Kâr raporu yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchReport, { immediate: true, deep: true });

const printRef = ref(null);

function exportCsv() {
    try {
        downloadCsv(`kar-${Date.now()}.csv`, [
            [t('Kırılım'), t('Belge'), t('Ciro'), t('Net'), t('Maliyet'), t('Kâr'), t('Marj')],
            ...rows.value.map((r) => [r.label, r.document_count, r.gross_total, r.net_total, r.cogs_total, r.profit, r.margin ?? '']),
        ]);
    } catch {
        emit('error', t('CSV üretilemedi.'));
    }
}

function printReport() {
    window.print();
}
</script>
