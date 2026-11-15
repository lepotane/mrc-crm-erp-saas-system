<!-- Devir hızı sekmesi: reports/stock-turnover?days=
     turnover = dönem çıkışı / ortalama stok; bayat = son çıkış stale_days günü aşmış. -->
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
            <template v-else>
                <StatPill v-for="s in summaryPills" :key="s.key" :label-key="s.key" :value="s.value" :cls="s.cls" :clickable="s.clickable" :active="s.clickable && chip === s.chip" :hint-key="s.hintKey" @click="s.clickable && toggleChip(s.chip)" />
            </template>
            <ExportButtons :disabled="loading || rows.length === 0" @csv="exportCsv" @print="printReport" />
        </div>

        <div ref="printRef" class="rpt-print-area space-y-4">
            <Card rounded="2xl" padding="md">
                <div v-if="loading" class="space-y-2 py-4">
                    <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="shown.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Kayıt yok') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Ürün') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Dönem Çıkışı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Ort. Stok') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Devir') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Gün Stoğu') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Son Çıkış') }}</th>
                                <th class="py-3 font-semibold">{{ $t('Durum') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in shown" :key="r.product_id" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white">{{ r.name }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.out_qty) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.avg_stock) }}</td>
                                <td class="py-3 pr-3 text-right text-white font-bold whitespace-nowrap">{{ r.turnover === null ? '—' : r.turnover }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ r.days_of_stock === null ? '—' : r.days_of_stock }}</td>
                                <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ r.last_out_at || '—' }}</td>
                                <td class="py-3">
                                    <StatusPill v-if="r.stale" color="coral" :dot="false">{{ $t('Bayat') }}</StatusPill>
                                    <StatusPill v-else color="forest" :dot="false">{{ $t('Canlı') }}</StatusPill>
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
import { formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';

const props = defineProps({
    // { days }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const currencyNote = ref('');
const loading = ref(true);
const chip = ref('');
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const staleCount = computed(() => rows.value.filter((r) => r.stale).length);
const shown = computed(() => (chip.value === 'stale' ? rows.value.filter((r) => r.stale) : rows.value));

const summaryPills = computed(() => [
    { key: 'İzlenen Ürün', value: formatNumber(rows.value.length) },
    {
        key: 'Bayat Stok',
        value: formatNumber(staleCount.value),
        cls: staleCount.value > 0 ? 'text-[#FFAE7C]' : 'text-white',
        clickable: staleCount.value > 0,
        chip: 'stale',
        hintKey: 'Son çıkışı üzerinden uzun süre geçmiş ürünleri listelemek için bu hapa tıkla.',
    },
]);

function toggleChip(next) {
    chip.value = chip.value === next ? '' : next;
}

async function fetchReport() {
    const id = ++reqId;
    loading.value = true;
    try {
        const res = await client.get('/reports/stock-turnover', { params: { days: props.applied.days || 90 } });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        currencyNote.value = res.data?.meta?.currency_note || '';
        if (!rows.value.some((r) => r.stale)) chip.value = '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Devir raporu yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchReport, { immediate: true, deep: true });

const printRef = ref(null);

function exportCsv() {
    try {
        downloadCsv(`devir-${Date.now()}.csv`, [
            [t('Ürün'), t('Dönem Çıkışı'), t('Ort. Stok'), t('Devir'), t('Gün Stoğu'), t('Son Çıkış'), t('Durum')],
            ...shown.value.map((r) => [r.name, r.out_qty, r.avg_stock, r.turnover ?? '', r.days_of_stock ?? '', r.last_out_at || '', r.stale ? t('Bayat') : t('Canlı')]),
        ]);
    } catch {
        emit('error', t('CSV üretilemedi.'));
    }
}

function printReport() {
    window.print();
}
</script>
