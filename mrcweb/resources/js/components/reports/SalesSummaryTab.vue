<!-- Satış Özeti sekmesi: reports/sales-summary?from&to&group_by&type
     Dışa aktarma düğmeleri her sekmededir (ExportButtons). Kâr = net satış − birim maliyet×miktar. -->
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
            <template v-else>
                <StatPill v-for="s in summaryPills" :key="s.key" :label-key="s.key" :value="s.value" :cls="s.cls" />
            </template>
            <ExportButtons :disabled="loading || rows.length === 0" @csv="exportCsv" @print="printReport" />
        </div>

        <div
            v-if="!loading && cogsMissing > 0"
            class="flex items-center gap-3 p-3.5 rounded-2xl bg-[#DC6F31]/15 border border-[#DC6F31]/40 text-[#FFAE7C]"
        >
            <AlertTriangle class="w-5 h-5 shrink-0" />
            <span class="text-xs font-semibold">{{ $t('Maliyeti tanımlı olmayan :n satır sonuçlara yansıtılmadı.', { n: formatNumber(cogsMissing) }) }}</span>
        </div>

        <div ref="printRef" class="rpt-print-area space-y-4">
            <Card v-if="showChart" rounded="2xl" padding="md">
                <h4 class="text-[11px] font-bold uppercase tracking-wider text-[#64748B] mb-3">
                    {{ $t('Dağılım grafiği') }} · {{ $t('Brüt Toplam') }}
                </h4>
                <div class="flex items-stretch gap-1.5 h-52 overflow-x-auto pb-1">
                    <div
                        v-for="r in rows"
                        :key="r.key"
                        class="flex-1 min-w-[30px] flex flex-col items-center justify-end gap-1.5 h-full"
                        :title="chartTip(r)"
                    >
                        <span class="text-[10px] font-bold text-[#CBD5E1] whitespace-nowrap">{{ shortMoney(r.gross_total) }}</span>
                        <div
                            class="w-full max-w-10 rounded-t-lg bg-[#B9FF66]/75 hover:bg-[#B9FF66] transition-colors"
                            :style="{ height: barPct(r) + '%' }"
                        ></div>
                        <span class="text-[10px] leading-tight text-[#64748B] text-center break-words w-full">{{ chartLabel(r) }}</span>
                    </div>
                </div>
            </Card>

            <Card rounded="2xl" padding="md">
                <div v-if="loading" class="space-y-2 py-4">
                    <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="rows.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Kayıt yok') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[880px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t(groupLabelKey) }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Brüt Toplam') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Net Toplam') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('İskonto Toplamı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('KDV Toplamı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Maliyet Toplamı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Kâr') }}</th>
                                <th class="py-3 font-semibold text-right">{{ $t('Belge Sayısı') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in rows" :key="r.key" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white whitespace-nowrap">{{ chartLabel(r) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.gross_total) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.net_total) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.discount_total) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.tax_total) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.cogs_total) }}</td>
                                <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="Number(r.profit) >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">
                                    {{ formatMoney(r.profit) }}
                                </td>
                                <td class="py-3 text-right text-[#CBD5E1]">{{ formatNumber(r.document_count) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/[0.12] font-bold">
                                <td class="py-3 pr-3 text-white">{{ $t('Toplam') }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(totals.gross_total) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(totals.net_total) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(totals.discount_total) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(totals.tax_total) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(totals.cogs_total) }}</td>
                                <td class="py-3 pr-3 text-right whitespace-nowrap" :class="Number(totals.profit) >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">
                                    {{ formatMoney(totals.profit) }}
                                </td>
                                <td class="py-3 text-right text-white">{{ formatNumber(totals.document_count) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <ByCurrency
                    :map="byCurrency"
                    :fields="[
                        { key: 'gross_total', labelKey: 'Brüt Toplam' },
                        { key: 'net_total', labelKey: 'Net Toplam' },
                        { key: 'profit', labelKey: 'Kâr' },
                    ]"
                />
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
import ByCurrency from './ByCurrency.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';
import { AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
    // { from, to, groupBy, docType }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const totals = ref({});
const byCurrency = ref({});
const currencyNote = ref('');
const loading = ref(true);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const cogsMissing = computed(() => Number(totals.value?.cogs_missing_rows ?? 0));

const groupLabelKey = computed(() => (props.applied?.groupBy === 'month' ? 'Ay' : 'Grup'));

// Zaman kırılımlarında (gün/ay) dikey çubuk grafik; diğer kırılımlarda tablo yeterlidir.
const showChart = computed(() => {
    const g = props.applied?.groupBy;
    return !loading.value && rows.value.length > 0 && (g === 'day' || g === 'month');
});

const maxGross = computed(() => rows.value.reduce((m, r) => Math.max(m, Number(r.gross_total) || 0), 0));

const summaryPills = computed(() => [
    { key: 'Brüt Toplam', value: formatMoney(totals.value?.gross_total) },
    { key: 'Net Toplam', value: formatMoney(totals.value?.net_total) },
    {
        key: 'Kâr',
        value: formatMoney(totals.value?.profit),
        cls: Number(totals.value?.profit ?? 0) >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]',
    },
    { key: 'Belge Sayısı', value: formatNumber(totals.value?.document_count) },
]);

function chartLabel(r) {
    return r.month_name || r.label || r.key;
}

function barPct(r) {
    if (maxGross.value <= 0) return 2;
    return Math.max(2, Math.round(((Number(r.gross_total) || 0) / maxGross.value) * 88));
}

function shortMoney(v) {
    const n = Number(v) || 0;
    if (Math.abs(n) >= 1000000) return `${(n / 1000000).toFixed(1)}M`;
    if (Math.abs(n) >= 1000) return `${(n / 1000).toFixed(1)}B`;
    return String(Math.round(n));
}

function chartTip(r) {
    return `${chartLabel(r)} — ${t('Brüt Toplam')}: ${formatMoney(r.gross_total)} · ${t('Net Toplam')}: ${formatMoney(r.net_total)} · ${t('Kâr')}: ${formatMoney(r.profit)}`;
}

async function fetchSummary() {
    const id = ++reqId;
    loading.value = true;
    try {
        const params = {
            group_by: props.applied.groupBy || 'month',
            type: props.applied.docType || 'invoice',
        };
        if (props.applied.from) params.from = props.applied.from;
        if (props.applied.to) params.to = props.applied.to;
        const res = await client.get('/reports/sales-summary', { params });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        totals.value = res.data?.totals || {};
        byCurrency.value = res.data?.by_currency || {};
        currencyNote.value = res.data?.meta?.currency_note || '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Satış özeti yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchSummary, { immediate: true, deep: true });

const printRef = ref(null);

function exportCsv() {
    try {
        const headers = [
            t(groupLabelKey.value),
            t('Brüt Toplam'),
            t('Net Toplam'),
            t('İskonto Toplamı'),
            t('KDV Toplamı'),
            t('Maliyet Toplamı'),
            t('Kâr'),
            t('Belge Sayısı'),
        ];
        const body = rows.value.map((r) => [
            chartLabel(r),
            r.gross_total,
            r.net_total,
            r.discount_total,
            r.tax_total,
            r.cogs_total,
            r.profit,
            r.document_count,
        ]);
        const f = props.applied?.from || '';
        const tt = props.applied?.to || '';
        downloadCsv(`satis-ozeti-${f}-${tt}.csv`, headers, body);
    } catch {
        emit('error', t('CSV oluşturulamadı.'));
    }
}

function printReport() {
    window.print();
}
</script>
