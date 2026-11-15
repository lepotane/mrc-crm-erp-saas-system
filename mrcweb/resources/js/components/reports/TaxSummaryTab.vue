<!-- KDV Özeti sekmesi: reports/tax-summary?from&to
     Alan adları: base_total / tax_total / gross_total /
     line_count / tax_rate / currency_code. -->
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
                    <div v-for="i in 4" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="rows.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Kayıt yok') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[720px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Vergi Oranı') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Para Birimi') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Matrah (KDV hariç)') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('KDV Toplamı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Brüt Toplam') }}</th>
                                <th class="py-3 font-semibold text-right">{{ $t('Kalem Sayısı') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="(r, i) in rows" :key="`${r.tax_rate}-${r.currency_code}-${i}`" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white whitespace-nowrap">%{{ r.tax_rate }}</td>
                                <td class="py-3 pr-3 font-bold text-white">{{ r.currency_code || '-' }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.base_total, r.currency_code) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.tax_total, r.currency_code) }}</td>
                                <td class="py-3 pr-3 text-right font-bold text-white whitespace-nowrap">{{ formatMoney(r.gross_total, r.currency_code) }}</td>
                                <td class="py-3 text-right text-[#CBD5E1]">{{ formatNumber(r.line_count) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/[0.12] font-bold">
                                <td class="py-3 pr-3 text-white" colspan="2">{{ $t('Toplam') }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('base_total')) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('tax_total')) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('gross_total')) }}</td>
                                <td class="py-3 text-right text-white">{{ formatNumber(sumOf('line_count')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <ByCurrency
                    :map="byCurrency"
                    :fields="[
                        { key: 'base_total', labelKey: 'Matrah (KDV hariç)' },
                        { key: 'tax_total', labelKey: 'KDV Toplamı' },
                        { key: 'gross_total', labelKey: 'Brüt Toplam' },
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

const props = defineProps({
    // { from, to }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const serverTotals = ref(null);
const byCurrency = ref({});
const currencyNote = ref('');
const loading = ref(true);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

function sumOf(key) {
    return rows.value.reduce((s, r) => s + (Number(r[key]) || 0), 0);
}

const summaryPills = computed(() => [
    { key: 'Matrah (KDV hariç)', value: formatMoney(serverTotals.value?.base_total ?? sumOf('base_total')) },
    { key: 'KDV Toplamı', value: formatMoney(serverTotals.value?.tax_total ?? sumOf('tax_total')) },
    { key: 'Brüt Toplam', value: formatMoney(serverTotals.value?.gross_total ?? sumOf('gross_total')) },
    { key: 'Kalem Sayısı', value: formatNumber(sumOf('line_count')) },
]);

async function fetchSummary() {
    const id = ++reqId;
    loading.value = true;
    try {
        const params = {};
        if (props.applied.from) params.from = props.applied.from;
        if (props.applied.to) params.to = props.applied.to;
        const res = await client.get('/reports/tax-summary', { params });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        serverTotals.value = res.data?.totals || null;
        byCurrency.value = res.data?.by_currency || {};
        currencyNote.value = res.data?.meta?.currency_note || '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('KDV özeti yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchSummary, { immediate: true, deep: true });

const printRef = ref(null);

// CSV ham sayılarla indirilir (noktalı); Excel TR için ; ayraç + BOM (reportUtils).
function exportCsv() {
    try {
        const headers = [
            t('Vergi Oranı'),
            t('Para Birimi'),
            t('Matrah (KDV hariç)'),
            t('KDV Toplamı'),
            t('Brüt Toplam'),
            t('Kalem Sayısı'),
        ];
        const body = rows.value.map((r) => [
            r.tax_rate,
            r.currency_code || '',
            r.base_total,
            r.tax_total,
            r.gross_total,
            r.line_count,
        ]);
        body.push([
            t('Toplam'),
            '',
            sumOf('base_total'),
            sumOf('tax_total'),
            sumOf('gross_total'),
            sumOf('line_count'),
        ]);
        const f = props.applied?.from || '';
        const tt = props.applied?.to || '';
        downloadCsv(`kdv-ozeti-${f}-${tt}.csv`, headers, body);
    } catch {
        emit('error', t('CSV oluşturulamadı.'));
    }
}

function printReport() {
    window.print();
}
</script>
