<!-- Stok Değeri sekmesi: reports/stock-valuation?warehouse_id&category_id
     Satırda sale_price ve min_stock vardır; kritik satır = quantity <= min_stock.
     „Kritik Stok” hapı tıklanabilir süzgeçtir (rozet süzgeci). -->
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
            <template v-else>
                <StatPill
                    v-for="s in summaryPills"
                    :key="s.key"
                    :label-key="s.key"
                    :value="s.value"
                    :cls="s.cls"
                    :clickable="s.clickable"
                    :active="s.clickable && chip === s.chip"
                    :hint-key="s.hintKey"
                    @click="s.clickable && toggleChip(s.chip)"
                />
            </template>
            <ExportButtons :disabled="loading || rows.length === 0" @csv="exportCsv" @print="printReport" />
        </div>

        <div ref="printRef" class="rpt-print-area space-y-4">
            <p v-if="!loading && chip" class="flex flex-wrap items-center gap-2 text-[11px] text-[#94A3B8]">
                <Filter class="w-3.5 h-3.5 text-[#B9FF66]" />
                <span>{{ $t('Süzgeç: yalnız kritik stokta olan ürünler (:n / :total ürün)', { n: formatNumber(shown.length), total: formatNumber(rows.length) }) }}</span>
                <button type="button" @click="toggleChip('')" class="text-[#B9FF66] font-semibold hover:underline cursor-pointer">
                    {{ $t('Süzgeci kaldır') }}
                </button>
            </p>

            <Card rounded="2xl" padding="md">
                <div v-if="loading" class="space-y-2 py-4">
                    <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="rows.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Kayıt yok') }}
                </div>
                <div v-else-if="shown.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Bu süzgeçle kayıt yok.') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[1080px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Ürün') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('SKU') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Birim') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Miktar') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Birim Maliyet') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Stok Değeri') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Satış Fiyatı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Satış Değeri') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Min. Stok') }}</th>
                                <th class="py-3 font-semibold">{{ $t('Durum') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in shown" :key="r.product_id" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white">{{ r.name }}</td>
                                <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ r.sku || '-' }}</td>
                                <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ r.unit || '-' }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.quantity) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.avg_cost) }}</td>
                                <td class="py-3 pr-3 text-right font-bold text-white whitespace-nowrap">{{ formatMoney(r.stock_value) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.sale_price) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.sale_value) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.min_stock) }}</td>
                                <td class="py-3">
                                    <StatusPill v-if="r.critical" color="coral" :dot="false">{{ $t('Kritik') }}</StatusPill>
                                    <StatusPill v-else color="forest" :dot="false">{{ $t('Normal') }}</StatusPill>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/[0.12] font-bold">
                                <td class="py-3 pr-3 text-white" colspan="3">{{ $t('Toplam') }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatNumber(sumOf('quantity')) }}</td>
                                <td class="py-3 pr-3"></td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('stock_value')) }}</td>
                                <td class="py-3 pr-3"></td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('sale_value')) }}</td>
                                <td class="py-3 pr-3"></td>
                                <td class="py-3 text-[#64748B] text-[11px]">{{ chip ? $t('süzülmüş görünüm') : '' }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <ByCurrency
                    :map="byCurrency"
                    :fields="[
                        { key: 'stock_value', labelKey: 'Stok Değeri' },
                        { key: 'sale_value', labelKey: 'Satış Değeri' },
                    ]"
                />
                <p v-if="chip" class="mt-2 text-[11px] text-[#64748B]">{{ $t('Para birimi kırılımı süzgeçten etkilenmez; tüm raporun kırılımı gösterilir.') }}</p>
                <p v-if="!loading && currencyNote" class="mt-3 text-[11px] text-[#64748B]">{{ currencyNote }}</p>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { Filter } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import StatPill from './StatPill.vue';
import ExportButtons from './ExportButtons.vue';
import StatusPill from '../ui/StatusPill.vue';
import SkeletonPill from '../ui/SkeletonPill.vue';
import ByCurrency from './ByCurrency.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';

const props = defineProps({
    // { warehouseId, categoryId }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const serverTotals = ref(null);
const byCurrency = ref({});
const currencyNote = ref('');
const loading = ref(true);
// '' | 'critical' — istemci tarafı satır süzgeci (sunucuda bu parametre yok)
const chip = ref('');
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const criticalCount = computed(() =>
    Number(serverTotals.value?.critical_count ?? rows.value.filter((r) => r.critical).length),
);

const shown = computed(() => (chip.value === 'critical' ? rows.value.filter((r) => r.critical) : rows.value));

function sumOf(key) {
    return shown.value.reduce((s, r) => s + (Number(r[key]) || 0), 0);
}

const summaryPills = computed(() => [
    { key: 'Ürün Sayısı', value: formatNumber(serverTotals.value?.product_count ?? rows.value.length) },
    { key: 'Stok Değeri', value: formatMoney(serverTotals.value?.stock_value) },
    { key: 'Satış Değeri', value: formatMoney(serverTotals.value?.sale_value) },
    {
        key: 'Kritik Stok',
        value: formatNumber(criticalCount.value),
        cls: criticalCount.value > 0 ? 'text-[#FFAE7C]' : 'text-white',
        clickable: criticalCount.value > 0,
        chip: 'critical',
        hintKey: 'Stokta bulunmayan / kritik seviyede kalan ürünleri listelemek için bu hapa tıkla.',
    },
]);

function toggleChip(next) {
    chip.value = chip.value === next ? '' : next;
}

// Veri yenilendiğinde süzgeç mantığı bozulmasın: kritik kayıt kalmadıysa süzgeç düşer
async function fetchValuation() {
    const id = ++reqId;
    loading.value = true;
    try {
        const params = {};
        if (props.applied.warehouseId) params.warehouse_id = props.applied.warehouseId;
        if (props.applied.categoryId) params.category_id = props.applied.categoryId;
        const res = await client.get('/reports/stock-valuation', { params });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        serverTotals.value = res.data?.totals || null;
        byCurrency.value = res.data?.by_currency || {};
        currencyNote.value = res.data?.meta?.currency_note || '';
        if (!rows.value.some((r) => r.critical)) chip.value = '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Stok değeri yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchValuation, { immediate: true, deep: true });

const printRef = ref(null);

// CSV ekranda GÖRÜNEN satırları indirir (süzgeçliyse süzülmüş hâli).
function exportCsv() {
    try {
        const headers = [
            t('Ürün'),
            t('SKU'),
            t('Birim'),
            t('Miktar'),
            t('Birim Maliyet'),
            t('Stok Değeri'),
            t('Satış Fiyatı'),
            t('Satış Değeri'),
            t('Min. Stok'),
            t('Durum'),
        ];
        const body = shown.value.map((r) => [
            r.name,
            r.sku || '',
            r.unit || '',
            r.quantity,
            r.avg_cost,
            r.stock_value,
            r.sale_price,
            r.sale_value,
            r.min_stock,
            r.critical ? t('Kritik') : t('Normal'),
        ]);
        downloadCsv(`stok-degeri-${props.applied?.warehouseId || 'tumu'}.csv`, headers, body);
    } catch {
        emit('error', t('CSV oluşturulamadı.'));
    }
}

function printReport() {
    window.print();
}
</script>
