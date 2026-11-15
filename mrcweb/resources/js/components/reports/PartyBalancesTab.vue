<!-- Cari Bakiye sekmesi: reports/party-balances?type&sort&search&page
     + üstte yaşlandırma bloğu (as_of). Satır tıklaması PartyBalanceDrawer açar
     (finance bileşeni yeniden kullanılır, dosyası değiştirilmez).
     Bakiye işareti: pozitif = bizim alacağımız, negatif = borcumuz.
     CSV listeyi sayfa sayfa çekip TÜMÜNÜ indirir (ekranda 15 satır görünmesi
     CSV'nin 15 satır olacağı anlamına gelmez). -->
<template>
    <div class="space-y-4">
        <div ref="printRef" class="rpt-print-area space-y-4">
            <AgeingBlock :applied="{ asOf: props.applied.asOf }" @error="forwardError" />

            <div class="flex flex-wrap items-center gap-2">
                <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                <template v-else>
                    <StatPill label-key="Toplam Alacak" :value="formatMoney(balanceTotals.receivable_sum)" />
                    <StatPill label-key="Toplam Borç" :value="formatMoney(balanceTotals.payable_sum)" />
                    <StatPill
                        label-key="Net Bakiye"
                        :value="formatMoney(balanceTotals.net_balance)"
                        :cls="Number(balanceTotals.net_balance) >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'"
                    />
                </template>
                <ExportButtons
                    :disabled="loading || balances.length === 0"
                    :loading="exporting"
                    @csv="exportCsv"
                    @print="printReport"
                />
            </div>

            <Card rounded="2xl" padding="md">
                <div v-if="loading" class="space-y-2 py-4">
                    <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="balances.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                    {{ $t('Kayıtlı cari bakiye bulunamadı') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[920px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Kod') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Cari') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Tip') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Para Birimi') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Bakiye') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Alacak') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Borç') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Açık Belge') }}</th>
                                <th class="py-3 font-semibold">{{ $t('En Eski Vade') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="row in balances" :key="row.id" @click="openDrawer(row.id)" class="hover:bg-white/[0.03] transition-colors cursor-pointer">
                                <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ row.code || '-' }}</td>
                                <td class="py-3 pr-3 font-semibold text-white">{{ row.name }}</td>
                                <td class="py-3 pr-3">
                                    <StatusPill :color="partyTypeColor(row.type)" :dot="false">{{ $t(partyTypeLabelKey(row.type)) }}</StatusPill>
                                </td>
                                <td class="py-3 pr-3 font-bold text-white">{{ row.currency_code || '-' }}</td>
                                <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="Number(row.balance) >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">
                                    {{ formatMoney(row.balance, row.currency_code) }}
                                </td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(row.receivable, row.currency_code) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(row.payable, row.currency_code) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1]">{{ formatNumber(row.open_document_count) }}</td>
                                <td class="py-3 text-[#CBD5E1] whitespace-nowrap">{{ formatDate(row.oldest_due_date) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-[11px] text-[#64748B]">{{ $t('Pozitif bakiye alacağımızı, negatif bakiye borcumuzu gösterir.') }}<span v-if="currencyNote"> · {{ currencyNote }}</span></p>

                <div v-if="balanceMeta && balanceMeta.last_page > 1" class="flex items-center justify-between pt-4 mt-2 border-t border-white/[0.08]">
                    <span class="text-xs text-[#94A3B8]">
                        {{ $t('Toplam :total kayıt · Sayfa :cur / :last', { total: formatNumber(balanceMeta.total), cur: balanceMeta.current_page, last: balanceMeta.last_page }) }}
                    </span>
                    <div class="flex items-center gap-2">
                        <PillButton variant="ghost" size="sm" :disabled="balanceMeta.current_page <= 1" @click="goPage(balanceMeta.current_page - 1)">{{ $t('Önceki') }}</PillButton>
                        <PillButton variant="ghost" size="sm" :disabled="balanceMeta.current_page >= balanceMeta.last_page" @click="goPage(balanceMeta.current_page + 1)">{{ $t('Sonraki') }}</PillButton>
                    </div>
                </div>
            </Card>
        </div>

        <PartyBalanceDrawer
            :open="drawerOpen"
            :party-id="drawerPartyId"
            @close="closeDrawer"
            @receipt="goPayment($event, 'in')"
            @pay="goPayment($event, 'out')"
        />
    </div>
</template>

<script setup>
import { ref, watch, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatPill from './StatPill.vue';
import ExportButtons from './ExportButtons.vue';
import StatusPill from '../ui/StatusPill.vue';
import SkeletonPill from '../ui/SkeletonPill.vue';
import PartyBalanceDrawer from '../finance/PartyBalanceDrawer.vue';
import AgeingBlock from './AgeingBlock.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney, formatNumber, formatDate } from '../../utils/format';
import { partyTypeLabelKey, partyTypeColor } from '../finance/financeUtils';
import { downloadCsv, fetchAllPages } from './reportUtils';

const props = defineProps({
    // { type, sort, search, asOf } — sayfa bu bileşene aittir
    applied: { type: Object, required: true },
});

const emit = defineEmits(['error']);
const router = useRouter();
function forwardError(msg) {
    emit('error', msg);
}

const balances = ref([]);
const balanceMeta = ref(null);
const balanceTotals = ref({ receivable_sum: 0, payable_sum: 0, net_balance: 0 });
const currencyNote = ref('');
const loading = ref(true);
const page = ref(1);
let reqId = 0;
let alive = true;
onUnmounted(() => { alive = false; });

function queryParams() {
    const params = {
        type: props.applied.type || 'all',
        sort: props.applied.sort || 'balance_desc',
    };
    if (props.applied.search && String(props.applied.search).trim()) params.search = String(props.applied.search).trim();
    return params;
}

async function fetchBalances() {
    const id = ++reqId;
    loading.value = true;
    try {
        const res = await client.get('/reports/party-balances', {
            params: { ...queryParams(), page: page.value, per_page: 15 },
        });
        if (!alive || id !== reqId) return;
        balances.value = res.data?.data || [];
        balanceMeta.value = res.data?.meta || null;
        if (res.data?.totals) balanceTotals.value = res.data.totals;
        if (res.data?.currency_note) currencyNote.value = res.data.currency_note;
    } catch (err) {
        if (!alive || id !== reqId) return;
        emit('error', err.response?.data?.message || t('Cari bakiyeler yüklenemedi.'));
    } finally {
        if (alive && id === reqId) loading.value = false;
    }
}

function goPage(p) {
    page.value = p;
    fetchBalances();
}

// Filtre değişince ilk sayfaya dön (sayfa, applied anahtarına dahil değildir).
// immediate: sekme ilk açıldığında da yüklensin (aksi hâlde iskelette takılı kalır).
watch(() => props.applied, () => {
    page.value = 1;
    fetchBalances();
}, { deep: true, immediate: true });

const drawerOpen = ref(false);
const drawerPartyId = ref(null);

function openDrawer(id) {
    drawerPartyId.value = id;
    drawerOpen.value = true;
}
function closeDrawer() {
    drawerOpen.value = false;
    drawerPartyId.value = null;
}

// Rapor yalnız okunur; tahsilat/ödeme işlemi Borç-Alacak ekranında yapılır.
// Derin bağlantı: /borc-alacak?party=<id>&dir=in|out → orada form cari ön-dolu açılır.
function goPayment(partyId, direction) {
    const id = partyId ?? drawerPartyId.value;
    closeDrawer();
    if (!id) return;
    router.push({ path: '/borc-alacak', query: { party: id, dir: direction } });
}

const printRef = ref(null);
const exporting = ref(false);

async function exportCsv() {
    if (exporting.value) return;
    exporting.value = true;
    try {
        const all = await fetchAllPages('/reports/party-balances', queryParams());
        const headers = [
            t('Kod'),
            t('Cari'),
            t('Tip'),
            t('Para Birimi'),
            t('Bakiye'),
            t('Alacak'),
            t('Borç'),
            t('Açık Belge'),
            t('En Eski Vade'),
        ];
        const body = all.map((row) => [
            row.code || '',
            row.name,
            t(partyTypeLabelKey(row.type)),
            row.currency_code || '',
            row.balance,
            row.receivable,
            row.payable,
            row.open_document_count,
            row.oldest_due_date || '',
        ]);
        downloadCsv(`cari-bakiye-${queryParams().type}-${props.applied.asOf || ''}.csv`, headers, body);
    } catch (err) {
        emit('error', err.response?.data?.message || t('CSV oluşturulamadı.'));
    } finally {
        exporting.value = false;
    }
}

function printReport() {
    window.print();
}
</script>
