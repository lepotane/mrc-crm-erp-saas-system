<!-- Tedarik önerisi sekmesi: reports/stock-replenishment
     Minimumun altındaki ürünler + önerilen miktar (max yoksa min*2 hedefi). -->
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
                    {{ $t('Tedarik gereken ürün yok.') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[720px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Ürün') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('SKU') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Stok') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Min. Stok') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Maks. Stok') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Önerilen') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in rows" :key="r.product_id" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white">{{ r.name }}</td>
                                <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ r.sku || '-' }}</td>
                                <td class="py-3 pr-3 text-right font-bold text-[#FFA0A0] whitespace-nowrap">{{ formatNumber(r.stock) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.min_stock) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ r.max_stock === null ? '—' : formatNumber(r.max_stock) }}</td>
                                <td class="py-3 pr-3 text-right font-bold text-white whitespace-nowrap">+{{ formatNumber(r.suggested_qty) }}</td>
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
import SkeletonPill from '../ui/SkeletonPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';

defineProps({
    applied: { type: Object, required: false, default: () => ({}) },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const currencyNote = ref('');
const loading = ref(true);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const summaryPills = computed(() => [
    { key: 'Tedarik Gereken', value: formatNumber(rows.value.length) },
    {
        key: 'Toplam Öneri',
        value: formatNumber(rows.value.reduce((s, r) => s + (Number(r.suggested_qty) || 0), 0)),
    },
]);

async function fetchReport() {
    const id = ++reqId;
    loading.value = true;
    try {
        const res = await client.get('/reports/stock-replenishment');
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        currencyNote.value = res.data?.meta?.currency_note || '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Tedarik raporu yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => true, fetchReport, { immediate: true });

const printRef = ref(null);

function exportCsv() {
    try {
        downloadCsv(`tedarik-${Date.now()}.csv`, [
            [t('Ürün'), t('SKU'), t('Stok'), t('Min. Stok'), t('Maks. Stok'), t('Önerilen')],
            ...rows.value.map((r) => [r.name, r.sku || '', r.stock, r.min_stock, r.max_stock ?? '', r.suggested_qty]),
        ]);
    } catch {
        emit('error', t('CSV üretilemedi.'));
    }
}

function printReport() {
    window.print();
}
</script>
