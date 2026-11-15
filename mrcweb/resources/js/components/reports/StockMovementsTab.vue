<!-- Stok Hareketleri sekmesi: reports/stock-movements-summary
     ?from&to&group_by&warehouse_id&product_id
     out_value çıkış MALİYETİDİR (satış fiyatı değil) — etiketler buna göredir.
     „Giriş Miktarı / Çıkış Miktarı” hapları tıklanabilir süzgeçtir: yalnız o yönde
     hareket görmüş satırlar kalır (alt toplam satırı görünen satırlardan hesaplanır). -->
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
                <span>{{ chipHint }}</span>
                <button type="button" @click="toggleChip('')" class="text-[#B9FF66] font-semibold hover:underline cursor-pointer">
                    {{ $t('Süzgeci kaldır') }}
                </button>
            </p>

            <Card rounded="2xl" padding="md">
                <p class="text-[11px] text-[#64748B] mb-3">{{ $t('Çıkış tutarı maliyet üzerinden hesaplanır, satış fiyatı değildir.') }}</p>
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
                                <th class="py-3 pr-3 font-semibold">{{ $t('Grup') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Giriş Miktarı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Çıkış Miktarı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Düzeltme Miktarı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Net Miktar') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Hareket Sayısı') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Giriş Değeri') }}</th>
                                <th class="py-3 font-semibold text-right">{{ $t('Çıkış Maliyeti') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="r in shown" :key="r.key" class="hover:bg-white/[0.03] transition-colors">
                                <td class="py-3 pr-3 font-semibold text-white whitespace-nowrap">{{ r.label || r.key }}</td>
                                <td class="py-3 pr-3 text-right text-[#8FE1AF] whitespace-nowrap">+{{ formatNumber(r.in_qty) }}</td>
                                <td class="py-3 pr-3 text-right text-[#FFAE7C] whitespace-nowrap">-{{ formatNumber(r.out_qty) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatNumber(r.adjust_qty) }}</td>
                                <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="Number(r.net_qty) >= 0 ? 'text-white' : 'text-[#FFAE7C]'">
                                    {{ formatNumber(r.net_qty) }}
                                </td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1]">{{ formatNumber(r.movement_count) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.in_value) }}</td>
                                <td class="py-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(r.out_value) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/[0.12] font-bold">
                                <td class="py-3 pr-3 text-white">
                                    {{ $t('Toplam') }}
                                    <span v-if="chip" class="text-[11px] font-medium text-[#64748B]">({{ $t('görünen satırlar') }})</span>
                                </td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatNumber(sumOf('in_qty')) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatNumber(sumOf('out_qty')) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatNumber(sumOf('adjust_qty')) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatNumber(sumOf('net_qty')) }}</td>
                                <td class="py-3 pr-3 text-right text-white">{{ formatNumber(sumOf('movement_count')) }}</td>
                                <td class="py-3 pr-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('in_value')) }}</td>
                                <td class="py-3 text-right text-white whitespace-nowrap">{{ formatMoney(sumOf('out_value')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <ByCurrency
                    :map="byCurrency"
                    :fields="[
                        { key: 'in_value', labelKey: 'Giriş Değeri' },
                        { key: 'out_value', labelKey: 'Çıkış Maliyeti' },
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
import SkeletonPill from '../ui/SkeletonPill.vue';
import ByCurrency from './ByCurrency.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber } from '../../utils/format';
import { downloadCsv } from './reportUtils';

const props = defineProps({
    // { from, to, groupBy, warehouseId, productId }
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);

const rows = ref([]);
const totals = ref({});
const byCurrency = ref({});
const currencyNote = ref('');
const loading = ref(true);
// '' | 'in' | 'out' — istemci tarafı satır süzgeci
const chip = ref('');
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

const shown = computed(() => {
    if (chip.value === 'in') return rows.value.filter((r) => Number(r.in_qty) > 0);
    if (chip.value === 'out') return rows.value.filter((r) => Number(r.out_qty) > 0);
    return rows.value;
});

function sumOf(key) {
    return shown.value.reduce((s, r) => s + (Number(r[key]) || 0), 0);
}

const chipHint = computed(() => {
    if (chip.value === 'in') {
        return t('Süzgeç: yalnız girişi olan satırlar (:n / :total satır)', {
            n: formatNumber(shown.value.length),
            total: formatNumber(rows.value.length),
        });
    }
    if (chip.value === 'out') {
        return t('Süzgeç: yalnız çıkışı olan satırlar (:n / :total satır)', {
            n: formatNumber(shown.value.length),
            total: formatNumber(rows.value.length),
        });
    }
    return '';
});

const summaryPills = computed(() => [
    {
        key: 'Giriş Miktarı',
        value: formatNumber(totals.value?.in_qty),
        cls: 'text-[#8FE1AF]',
        clickable: Number(totals.value?.in_qty || 0) > 0,
        chip: 'in',
        hintKey: 'Yalnız girişi olan satırları görmek için bu hapa tıkla.',
    },
    {
        key: 'Çıkış Miktarı',
        value: formatNumber(totals.value?.out_qty),
        cls: 'text-[#FFAE7C]',
        clickable: Number(totals.value?.out_qty || 0) > 0,
        chip: 'out',
        hintKey: 'Yalnız çıkışı olan satırları görmek için bu hapa tıkla.',
    },
    { key: 'Net Miktar', value: formatNumber(totals.value?.net_qty) },
    { key: 'Hareket Sayısı', value: formatNumber(totals.value?.movement_count) },
]);

function toggleChip(next) {
    chip.value = chip.value === next ? '' : next;
}

async function fetchSummary() {
    const id = ++reqId;
    loading.value = true;
    try {
        const params = { group_by: props.applied.groupBy || 'product' };
        if (props.applied.from) params.from = props.applied.from;
        if (props.applied.to) params.to = props.applied.to;
        if (props.applied.warehouseId) params.warehouse_id = props.applied.warehouseId;
        if (props.applied.productId) params.product_id = props.applied.productId;
        const res = await client.get('/reports/stock-movements-summary', { params });
        if (!alive || id !== reqId) return;
        rows.value = res.data?.data || [];
        totals.value = res.data?.totals || {};
        byCurrency.value = res.data?.by_currency || {};
        currencyNote.value = res.data?.meta?.currency_note || '';
        // süzgeç artık işe yaramıyorsa düşür (filtre değişince veri değişir)
        if (chip.value === 'in' && !rows.value.some((r) => Number(r.in_qty) > 0)) chip.value = '';
        if (chip.value === 'out' && !rows.value.some((r) => Number(r.out_qty) > 0)) chip.value = '';
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Stok hareketleri yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

watch(() => props.applied, fetchSummary, { immediate: true, deep: true });

const printRef = ref(null);

function exportCsv() {
    try {
        const headers = [
            t('Grup'),
            t('Giriş Miktarı'),
            t('Çıkış Miktarı'),
            t('Düzeltme Miktarı'),
            t('Net Miktar'),
            t('Hareket Sayısı'),
            t('Giriş Değeri'),
            t('Çıkış Maliyeti'),
        ];
        const body = shown.value.map((r) => [
            r.label || r.key,
            r.in_qty,
            r.out_qty,
            r.adjust_qty,
            r.net_qty,
            r.movement_count,
            r.in_value,
            r.out_value,
        ]);
        body.push([
            t('Toplam'),
            sumOf('in_qty'),
            sumOf('out_qty'),
            sumOf('adjust_qty'),
            sumOf('net_qty'),
            sumOf('movement_count'),
            sumOf('in_value'),
            sumOf('out_value'),
        ]);
        const g = props.applied?.groupBy || 'product';
        downloadCsv(`stok-hareketleri-${g}.csv`, headers, body);
    } catch {
        emit('error', t('CSV oluşturulamadı.'));
    }
}

function printReport() {
    window.print();
}
</script>
