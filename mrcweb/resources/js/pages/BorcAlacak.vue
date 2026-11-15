<template>
    <div class="space-y-6 select-none max-w-7xl mx-auto pb-12">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Borç Alacak') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                        {{ $t('Borç Alacak') }}
                    </h1>
                    <span v-if="loadingSummary" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                    <template v-else>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#B9FF66]">
                                <TrendingUp class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Toplam Alacak') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatMoney(summaryReceivable) }}</span>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#FFAE7C]">
                                <TrendingDown class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Toplam Borç') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatMoney(summaryPayable) }}</span>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#8CD5F4]">
                                <Scale class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Net Bakiye') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatMoney(summaryNet) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
                <p class="text-xs text-[#94A3B8] mt-2">
                    {{ $t('Cari bakiyeler, yaşlandırma, tahsilat ve ödemeler burada yönetilir.') }}
                    <span v-if="currencyNote" class="text-[#64748B]">· {{ currencyNote }}</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <PillButton variant="primary" size="md" @click="openPaymentForm('in')" class="whitespace-nowrap">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Yeni Ödeme Kaydet') }}</span>
                </PillButton>
            </div>
        </div>

        <div
            v-if="globalSuccess"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-[#B9FF66] shadow-[0_0_20px_rgba(185,255,102,0.15)]"
        >
            <Check class="w-5 h-5 shrink-0 stroke-[2.5]" />
            <span class="text-sm font-semibold">{{ globalSuccess }}</span>
        </div>
        <div
            v-if="globalError"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0]"
        >
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span class="text-sm font-semibold">{{ globalError }}</span>
        </div>

        <Card rounded="2xl" padding="md">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 pb-4 border-b border-white/[0.08] mb-4">
                <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#131722] border border-white/10 w-fit">
                    <button
                        v-for="tab in ageTabs"
                        :key="tab.value"
                        type="button"
                        @click="ageTab = tab.value"
                        class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                        :class="ageTab === tab.value
                            ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                            : 'text-[#94A3B8] hover:text-white'"
                    >
                        {{ $t(tab.key) }}
                    </button>
                </div>
                <div class="flex items-center gap-2 sm:ml-auto">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Tarih') }}</label>
                    <input v-model="asOf" @change="fetchAging" type="date" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                </div>
            </div>
            <div v-if="agingLoading" class="flex gap-2 flex-wrap">
                <div v-for="i in 5" :key="i" class="h-8 w-36 bg-[#131722]/60 rounded-full border border-white/5 animate-pulse"></div>
            </div>
            <div v-else class="flex flex-wrap gap-1.5">
                <span
                    v-for="b in activeAgingChips"
                    :key="b.key"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border"
                    :class="b.value > 0 ? 'bg-[#DC6F31]/15 text-[#FFAE7C] border-[#DC6F31]/30' : 'bg-white/5 text-[#94A3B8] border-white/10'"
                >
                    {{ $t(b.labelKey) }}: {{ formatMoney(b.value) }}
                </span>
                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs text-[#64748B]">
                    {{ $t('Açık belge') }}: {{ formatNumber(activeAging?.document_count ?? 0) }} · {{ $t('Toplam') }}: {{ formatMoney(activeAging?.total_open ?? 0) }}
                </span>
            </div>
        </Card>

        <Card rounded="2xl" padding="md">
            <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#131722] border border-white/10 w-fit mb-4">
                <button
                    v-for="tab in mainTabs"
                    :key="tab.value"
                    type="button"
                    @click="mainTab = tab.value"
                    class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                    :class="mainTab === tab.value
                        ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                        : 'text-[#94A3B8] hover:text-white'"
                >
                    {{ $t(tab.key) }}
                </button>
            </div>

            <div v-if="mainTab === 'bakiye'">
                <div class="flex flex-col xl:flex-row xl:items-center gap-3 pb-4 border-b border-white/[0.08] mb-2">
                    <select v-model="balanceType" @change="resetBalancePageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="all">{{ $t('Tümü') }}</option>
                        <option value="customer">{{ $t('Müşteri') }}</option>
                        <option value="supplier">{{ $t('Tedarikçi') }}</option>
                        <option value="both">{{ $t('Her İkisi') }}</option>
                    </select>
                    <select v-model="balanceSort" @change="resetBalancePageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ $t(o.key) }}</option>
                    </select>
                    <div class="relative sm:w-64 xl:ml-auto">
                        <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[#64748B]" />
                        <input
                            v-model="balanceSearch"
                            type="text"
                            :placeholder="$t('Cari kod veya adı ile ara...')"
                            class="w-full pl-10 pr-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        />
                    </div>
                </div>

                <div v-if="balancesLoading" class="space-y-2 py-4">
                    <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
                </div>
                <div v-else-if="balances.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5 mt-4">
                    {{ $t('Kayıtlı cari bakiye bulunamadı') }}
                </div>
                <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                    <table class="w-full text-sm min-w-[1020px]">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                <th class="py-3 pr-3 font-semibold">{{ $t('Kod') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Cari') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('Tip') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Bakiye') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Alacak') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Borç') }}</th>
                                <th class="py-3 pr-3 font-semibold text-right">{{ $t('Açık Belge') }}</th>
                                <th class="py-3 pr-3 font-semibold">{{ $t('En Eski Vade') }}</th>
                                <th class="py-3 font-semibold">{{ $t('Yaşlandırma') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <tr v-for="row in balances" :key="row.id" @click="openDrawer(row.id)" class="hover:bg-white/[0.03] transition-colors cursor-pointer">
                                <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ row.code || '-' }}</td>
                                <td class="py-3 pr-3 font-semibold text-white">{{ row.name }}</td>
                                <td class="py-3 pr-3">
                                    <StatusPill :color="partyTypeColor(row.type)" :dot="false">{{ $t(partyTypeLabelKey(row.type)) }}</StatusPill>
                                </td>
                                <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="row.balance >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">
                                    {{ formatMoney(row.balance, row.currency_code) }}
                                </td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(row.receivable, row.currency_code) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(row.payable, row.currency_code) }}</td>
                                <td class="py-3 pr-3 text-right text-[#CBD5E1]">{{ formatNumber(row.open_document_count) }}</td>
                                <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ formatDate(row.oldest_due_date) }}</td>
                                <td class="py-3">
                                    <div class="flex h-2 w-28 overflow-hidden rounded-full bg-white/5" :title="agingTitle(row)">
                                        <span v-for="b in agingBar(row)" :key="b.key" :style="{ width: b.pct + '%' }" :class="b.cls"></span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="balanceMeta && balanceMeta.last_page > 1" class="flex items-center justify-between pt-4 mt-2 border-t border-white/[0.08]">
                    <span class="text-xs text-[#94A3B8]">
                        {{ $t('Toplam :total kayıt · Sayfa :cur / :last', { total: formatNumber(balanceMeta.total), cur: balanceMeta.current_page, last: balanceMeta.last_page }) }}
                    </span>
                    <div class="flex items-center gap-2">
                        <PillButton variant="ghost" size="sm" :disabled="balanceMeta.current_page <= 1" @click="goBalancePage(balanceMeta.current_page - 1)">{{ $t('Önceki') }}</PillButton>
                        <PillButton variant="ghost" size="sm" :disabled="balanceMeta.current_page >= balanceMeta.last_page" @click="goBalancePage(balanceMeta.current_page + 1)">{{ $t('Sonraki') }}</PillButton>
                    </div>
                </div>
            </div>

            <div v-else-if="mainTab === 'hareket'">
                <div class="flex flex-col xl:flex-row xl:items-center gap-3 pb-4 border-b border-white/[0.08] mb-2">
                    <select v-model="payDirection" @change="resetPayPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="">{{ $t('Tümü') }}</option>
                        <option value="in">{{ $t('Tahsilat') }}</option>
                        <option value="out">{{ $t('Ödeme') }}</option>
                    </select>
                    <select v-model="payMethod" @change="resetPayPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="">{{ $t('Tüm Yöntemler') }}</option>
                        <option v-for="m in methodOptions" :key="m.value" :value="m.value">{{ $t(m.key) }}</option>
                    </select>
                    <select v-model="payParty" @change="resetPayPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer xl:max-w-56">
                        <option value="">{{ $t('Tüm Cariler') }}</option>
                        <option v-for="p in parties" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <select v-model="payAccount" @change="resetPayPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer xl:max-w-56">
                        <option value="">{{ $t('Tüm Hesaplar') }}</option>
                        <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                    <div class="flex flex-col sm:flex-row gap-3 xl:ml-auto">
                        <input v-model="payFrom" @change="resetPayPageAndFetch" type="date" :title="$t('Başlangıç')" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                        <input v-model="payTo" @change="resetPayPageAndFetch" type="date" :title="$t('Bitiş')" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                        <div class="relative sm:w-56">
                            <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[#64748B]" />
                            <input
                                v-model="paySearch"
                                type="text"
                                :placeholder="$t('Cari veya dekont no ile ara...')"
                                class="w-full pl-10 pr-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                            />
                        </div>
                    </div>
                </div>

                <PaymentsTable
                    :payments="payments"
                    :loading="paymentsLoading"
                    :meta="payMeta"
                    @page="goPayPage"
                    @delete="askDeletePayment"
                    @create="openPaymentForm('in')"
                />
            </div>

            <div v-else>
                <BankAccountsCard :accounts="bankAccounts" :loading="accountsLoading" @changed="onAccountsChanged" />
            </div>
        </Card>

        <PartyBalanceDrawer
            ref="drawerRef"
            :open="drawerOpen"
            :party-id="drawerPartyId"
            @close="closeDrawer"
            @receipt="openPaymentForm('in', $event)"
            @pay="openPaymentForm('out', $event)"
        />

        <PaymentForm
            :open="paymentFormOpen"
            :parties="parties"
            :bank-accounts="bankAccounts"
            :saving="paymentSaving"
            :errors="paymentErrors"
            :general-error="paymentGeneralError"
            :initial-direction="paymentDirection"
            :initial-party-id="paymentPartyId"
            @close="closePaymentForm"
            @submit="submitPayment"
        />

        <div v-if="deletingPayment" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Ödeme Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ formatMoney(deletingPayment.amount, deletingPayment.currency_code) }}</strong>
                    · {{ deletingPayment.party_name || '-' }}
                    {{ $t('kaydı kalıcı olarak silinecektir.') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingPayment = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="confirmDeletePayment">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import PaymentsTable from '../components/finance/PaymentsTable.vue';
import PaymentForm from '../components/finance/PaymentForm.vue';
import PartyBalanceDrawer from '../components/finance/PartyBalanceDrawer.vue';
import BankAccountsCard from '../components/finance/BankAccountsCard.vue';
import client from '../api/client';
import { t } from '../i18n';
import { formatMoney, formatDate, formatNumber } from '../utils/format';
import { partyTypeLabelKey, partyTypeColor, AGING_BUCKETS, todayISO } from '../components/finance/financeUtils';
import {
    Plus, Check, AlertCircle, AlertTriangle, Search,
    TrendingUp, TrendingDown, Scale,
} from 'lucide-vue-next';

const route = useRoute();

const mainTabs = computed(() => [
    { value: 'bakiye', key: 'Cari Bakiyeler' },
    { value: 'hareket', key: 'Ödeme Hareketleri' },
    { value: 'kasa', key: 'Kasa / Banka' },
]);

const ageTabs = computed(() => [
    { value: 'rec', key: 'Alacak Yaşlandırma' },
    { value: 'pay', key: 'Borç Yaşlandırma' },
]);

const sortOptions = computed(() => [
    { value: 'balance_desc', key: 'Bakiye (önce büyük)' },
    { value: 'balance_asc', key: 'Bakiye (önce küçük)' },
    { value: 'name', key: 'Ada göre' },
    { value: 'oldest_due', key: 'En eski vadeye göre' },
]);

const methodOptions = computed(() => [
    { value: 'cash', key: 'Nakit' },
    { value: 'bank', key: 'Banka' },
    { value: 'card', key: 'Kart' },
    { value: 'transfer', key: 'Havale / EFT' },
]);

const mainTab = ref('bakiye');
const ageTab = ref('rec');
const asOf = ref(todayISO());

const globalSuccess = ref('');
const globalError = ref('');

function triggerSuccess(msg) {
    globalSuccess.value = msg;
    globalError.value = '';
    setTimeout(() => { if (globalSuccess.value === msg) globalSuccess.value = ''; }, 4500);
}
function triggerError(msg) {
    globalError.value = msg;
    setTimeout(() => { if (globalError.value === msg) globalError.value = ''; }, 5000);
}

// --- Özet + yaşlandırma ---
const recAging = ref(null);
const payAging = ref(null);
const agingLoading = ref(true);
const balanceTotals = ref({ receivable_sum: 0, payable_sum: 0, net_balance: 0 });
const currencyNote = ref('');
let ageReq = 0;

// Özet şerit bakiye tabanlı olmalı: satırlardaki Alacak/Borç/Net ile aynı kaynaktan
// (party-balances.totals) okunur. Yaşlandırma bölümü „açık belge tutarı”nı ayrıca
// gösterir; ikisi karışırsa kullanıcı „216 alacak var, satırda 0 görüyorum” der.
const summaryReceivable = computed(() => balanceTotals.value.receivable_sum ?? 0);
const summaryPayable = computed(() => balanceTotals.value.payable_sum ?? 0);
const summaryNet = computed(() => balanceTotals.value.net_balance ?? 0);
const loadingSummary = computed(() => agingLoading.value && !recAging.value && !balanceTotals.value.receivable_sum);

const activeAging = computed(() => (ageTab.value === 'rec' ? recAging.value : payAging.value));
const activeAgingChips = computed(() => {
    const buckets = activeAging.value?.buckets || {};
    return AGING_BUCKETS.map((b) => ({ ...b, value: Number(buckets[b.key] ?? 0) }));
});

async function fetchAging() {
    const reqId = ++ageReq;
    agingLoading.value = true;
    try {
        const params = asOf.value ? { as_of: asOf.value } : {};
        const [rec, pay] = await Promise.all([
            client.get('/reports/receivables-aging', { params }),
            client.get('/reports/payables-aging', { params }),
        ]);
        if (reqId !== ageReq) return;
        recAging.value = rec.data;
        payAging.value = pay.data;
        currencyNote.value = rec.data?.meta?.currency_note || '';
    } catch (err) {
        if (reqId !== ageReq) return;
        triggerError(err.response?.data?.message || t('Yaşlandırma yüklenemedi.'));
    } finally {
        if (reqId === ageReq) agingLoading.value = false;
    }
}

// --- Cari bakiyeler ---
const balances = ref([]);
const balanceMeta = ref(null);
const balancesLoading = ref(true);
const balancePage = ref(1);
const balanceType = ref('all');
const balanceSort = ref('balance_desc');
const balanceSearch = ref('');
let balanceTimer = null;
let balReq = 0;
onUnmounted(() => clearTimeout(balanceTimer));

async function fetchBalances() {
    const reqId = ++balReq;
    balancesLoading.value = true;
    try {
        const params = { type: balanceType.value, sort: balanceSort.value, page: balancePage.value, per_page: 15 };
        if (balanceSearch.value.trim()) params.search = balanceSearch.value.trim();
        const res = await client.get('/reports/party-balances', { params });
        if (reqId !== balReq) return;
        balances.value = res.data?.data || [];
        balanceMeta.value = res.data?.meta || null;
        if (res.data?.totals) balanceTotals.value = res.data.totals;
        if (res.data?.currency_note && !currencyNote.value) currencyNote.value = res.data.currency_note;
    } catch (err) {
        if (reqId !== balReq) return;
        triggerError(err.response?.data?.message || t('Cari bakiyeler yüklenemedi.'));
    } finally {
        if (reqId === balReq) balancesLoading.value = false;
    }
}
function resetBalancePageAndFetch() {
    balancePage.value = 1;
    fetchBalances();
}
function goBalancePage(p) {
    balancePage.value = p;
    fetchBalances();
}
watch(balanceSearch, () => {
    clearTimeout(balanceTimer);
    balanceTimer = setTimeout(() => {
        balancePage.value = 1;
        fetchBalances();
    }, 300);
});

function agingBar(row) {
    const ag = row.aging || {};
    const vals = AGING_BUCKETS.map((b) => ({ key: b.key, value: Number(ag[b.key] ?? 0) }));
    const total = vals.reduce((s, v) => s + Math.max(v.value, 0), 0);
    const clss = {
        not_due: 'bg-[#4E956A]',
        days_1_30: 'bg-[#B9FF66]',
        days_31_60: 'bg-[#DCB031]',
        days_61_90: 'bg-[#DC6F31]',
        days_over_90: 'bg-[#C55050]',
    };
    if (total <= 0) return [];
    return vals.map((v) => ({ key: v.key, pct: (Math.max(v.value, 0) / total) * 100, cls: clss[v.key] }));
}
function agingTitle(row) {
    const ag = row.aging || {};
    return AGING_BUCKETS.map((b) => `${t(b.labelKey)}: ${formatMoney(Number(ag[b.key] ?? 0), row.currency_code)}`).join(' · ');
}

// --- Çekmece ---
const drawerOpen = ref(false);
const drawerPartyId = ref(null);
const drawerRef = ref(null);

function openDrawer(id) {
    drawerPartyId.value = id;
    drawerOpen.value = true;
}
function closeDrawer() {
    drawerOpen.value = false;
    drawerPartyId.value = null;
}

// --- Ödemeler ---
const payments = ref([]);
const payMeta = ref(null);
const paymentsLoading = ref(true);
const payPage = ref(1);
const payDirection = ref('');
const payMethod = ref('');
const payParty = ref('');
const payAccount = ref('');
const payFrom = ref('');
const payTo = ref('');
const paySearch = ref('');
let payTimer = null;
let payReq = 0;
onUnmounted(() => clearTimeout(payTimer));

async function fetchPayments() {
    const reqId = ++payReq;
    paymentsLoading.value = true;
    try {
        const params = { page: payPage.value, per_page: 15 };
        if (payDirection.value) params.direction = payDirection.value;
        if (payMethod.value) params.method = payMethod.value;
        if (payParty.value) params.party_id = payParty.value;
        if (payAccount.value) params.bank_account_id = payAccount.value;
        if (payFrom.value) params.from = payFrom.value;
        if (payTo.value) params.to = payTo.value;
        if (paySearch.value.trim()) params.search = paySearch.value.trim();
        const res = await client.get('/payments', { params });
        if (reqId !== payReq) return;
        payments.value = res.data?.data || [];
        payMeta.value = res.data?.meta || null;
    } catch (err) {
        if (reqId !== payReq) return;
        triggerError(err.response?.data?.message || t('Ödemeler yüklenemedi.'));
    } finally {
        if (reqId === payReq) paymentsLoading.value = false;
    }
}
function resetPayPageAndFetch() {
    payPage.value = 1;
    fetchPayments();
}
function goPayPage(p) {
    payPage.value = p;
    fetchPayments();
}
watch(paySearch, () => {
    clearTimeout(payTimer);
    payTimer = setTimeout(() => {
        payPage.value = 1;
        fetchPayments();
    }, 300);
});

// --- Ödeme formu ---
const paymentFormOpen = ref(false);
const paymentDirection = ref('in');
const paymentPartyId = ref(null);
const paymentSaving = ref(false);
const paymentErrors = ref({});
const paymentGeneralError = ref('');

function openPaymentForm(direction = 'in', partyId = null) {
    paymentDirection.value = direction;
    paymentPartyId.value = partyId;
    paymentErrors.value = {};
    paymentGeneralError.value = '';
    paymentFormOpen.value = true;
}
function closePaymentForm() {
    paymentFormOpen.value = false;
    paymentPartyId.value = null;
}
async function submitPayment({ payload, clientError }) {
    if (clientError) {
        paymentGeneralError.value = clientError;
        return;
    }
    paymentSaving.value = true;
    paymentErrors.value = {};
    paymentGeneralError.value = '';
    try {
        const res = await client.post('/payments', payload);
        const serverBalance = res.data?.party_balance;
        const ccy = payload.currency_code || 'TRY';
        triggerSuccess(
            serverBalance !== undefined && serverBalance !== null
                ? t('Ödeme kaydedildi. Güncel bakiye: :b', { b: formatMoney(serverBalance, ccy) })
                : t('Ödeme kaydedildi.')
        );
        closePaymentForm();
        fetchPayments();
        fetchBalances();
        fetchAging();
        if (drawerOpen.value) drawerRef.value?.refresh?.();
    } catch (err) {
        if (err.response?.status === 422) {
            paymentErrors.value = err.response.data?.errors || {};
            paymentGeneralError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            paymentGeneralError.value = err.response?.data?.message || t('Ödeme kaydedilemedi.');
        }
    } finally {
        paymentSaving.value = false;
    }
}

// --- Ödeme silme ---
const deletingPayment = ref(null);
const deleteSaving = ref(false);
function askDeletePayment(p) {
    deletingPayment.value = p;
}
async function confirmDeletePayment() {
    if (!deletingPayment.value) return;
    deleteSaving.value = true;
    try {
        await client.delete(`/payments/${deletingPayment.value.id}`);
        triggerSuccess(t('Ödeme silindi.'));
        deletingPayment.value = null;
        fetchPayments();
        fetchBalances();
        fetchAging();
        if (drawerOpen.value) drawerRef.value?.refresh?.();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Ödeme silinirken bir hata oluştu.'));
    } finally {
        deleteSaving.value = false;
    }
}

// --- Ortak listeler ---
const parties = ref([]);
const bankAccounts = ref([]);
const accountsLoading = ref(true);

async function fetchParties() {
    try {
        const res = await client.get('/parties', { params: { per_page: 100 } });
        const rows = res.data?.data || [];
        parties.value = rows;
        // Tip filtresi yoksa tek sayfa yetmeyebilir; müşteri+tedarikçi havuzunu birleştir.
        if (rows.length === 0) {
            const [c, s] = await Promise.allSettled([
                client.get('/parties', { params: { type: 'customer', per_page: 100 } }),
                client.get('/parties', { params: { type: 'supplier', per_page: 100 } }),
            ]);
            const merged = new Map();
            for (const r of [c, s]) {
                if (r.status === 'fulfilled') {
                    for (const p of r.value.data?.data || []) merged.set(p.id, p);
                }
            }
            parties.value = [...merged.values()];
        }
    } catch { /* liste yine de çalışır */ }
}

async function fetchBankAccounts() {
    accountsLoading.value = true;
    try {
        const res = await client.get('/bank-accounts');
        bankAccounts.value = res.data?.bank_accounts || [];
    } catch {
        bankAccounts.value = [];
    } finally {
        accountsLoading.value = false;
    }
}
function onAccountsChanged() {
    fetchBankAccounts();
}

onMounted(async () => {
    fetchBalances();
    fetchAging();
    fetchPayments();
    fetchBankAccounts();
    await fetchParties();
    applyPaymentDeepLink();
});

// Diğer ekranlar (ör. Raporlar → „Tahsilat Al / Ödeme Yap”) /borc-alacak?party=<id>&dir=in|out
// ile gelir. Cari listesi yüklendikten sonra form açılır; böylece cari adı ön-dolu görünür.
function applyPaymentDeepLink() {
    const raw = route.query.party;
    const partyId = Number(Array.isArray(raw) ? raw[0] : raw);
    if (!Number.isFinite(partyId) || partyId <= 0) return;

    const rawDir = route.query.dir;
    const dir = Array.isArray(rawDir) ? rawDir[0] : rawDir;
    openPaymentForm(dir === 'out' ? 'out' : 'in', partyId);
}
</script>
