<template>
    <div v-if="open" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('close')"></div>
        <aside class="absolute inset-y-0 right-0 w-full max-w-lg bg-[#212738] border-l border-white/15 shadow-2xl flex flex-col">
            <div class="flex items-center justify-between p-5 border-b border-white/[0.08]">
                <div>
                    <h3 class="text-base font-bold text-white">{{ $t('Cari Bakiye Detayı') }}</h3>
                    <p v-if="party" class="text-xs text-[#94A3B8] font-mono">{{ party.code }} · {{ $t(partyTypeLabelKey(party.type)) }}</p>
                </div>
                <button type="button" @click="$emit('close')" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                    <X class="w-5 h-5" />
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-4">
                <div v-if="loading" class="space-y-2">
                    <div v-for="i in 5" :key="i" class="h-12 bg-[#131722]/60 rounded-xl animate-pulse"></div>
                </div>
                <div v-else-if="loadError" class="p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0] text-xs font-semibold">
                    {{ loadError }}
                </div>
                <template v-else-if="data">
                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-lg font-bold text-white truncate">{{ party.name }}</p>
                            <StatusPill :color="partyTypeColor(party.type)" :dot="false">{{ $t(partyTypeLabelKey(party.type)) }}</StatusPill>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="text-[#94A3B8]">{{ $t('Para Birimi') }}: <span class="text-white">{{ party.currency_code || '-' }}</span></div>
                            <div class="text-[#94A3B8]">{{ $t('Açılış Bakiyesi') }}: <span class="text-white">{{ formatMoney(party.opening_balance, party.currency_code) }}</span></div>
                            <div class="text-[#94A3B8]">{{ $t('Kredi Limiti') }}: <span class="text-white">{{ party.credit_limit != null ? formatMoney(party.credit_limit, party.currency_code) : '-' }}</span></div>
                            <div class="text-[#94A3B8]">{{ $t('Net Bakiye') }}: <span class="font-bold" :class="totals.balance >= 0 ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">{{ formatMoney(totals.balance, party.currency_code) }}</span></div>
                        </div>
                        <p class="text-[11px] text-[#64748B]">{{ $t('Pozitif bakiye alacağımızı, negatif bakiye borcumuzu gösterir.') }}</p>
                    </section>

                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Özet') }}</h4>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="flex items-center justify-between bg-[#212738] rounded-xl px-3 py-2">
                                <span class="text-[#94A3B8]">{{ $t('Satış Toplamı') }}</span>
                                <span class="text-white font-semibold">{{ formatMoney(totals.sales_total, party.currency_code) }}</span>
                            </div>
                            <div class="flex items-center justify-between bg-[#212738] rounded-xl px-3 py-2">
                                <span class="text-[#94A3B8]">{{ $t('Alım Toplamı') }}</span>
                                <span class="text-white font-semibold">{{ formatMoney(totals.purchases_total, party.currency_code) }}</span>
                            </div>
                            <div class="flex items-center justify-between bg-[#212738] rounded-xl px-3 py-2">
                                <span class="text-[#94A3B8]">{{ $t('Tahsilatlar') }}</span>
                                <span class="text-white font-semibold">{{ formatMoney(totals.receipts_total, party.currency_code) }}</span>
                            </div>
                            <div class="flex items-center justify-between bg-[#212738] rounded-xl px-3 py-2">
                                <span class="text-[#94A3B8]">{{ $t('Ödemeler') }}</span>
                                <span class="text-white font-semibold">{{ formatMoney(totals.payments_total, party.currency_code) }}</span>
                            </div>
                        </div>
                        <div v-if="byCurrencyRows.length" class="pt-1">
                            <h5 class="text-[11px] font-bold text-[#64748B] mb-1.5">{{ $t('Para Birimine Göre') }}</h5>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs min-w-[420px]">
                                    <thead>
                                        <tr class="text-left text-[10px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                                            <th class="py-1.5 pr-2 font-semibold">{{ $t('Para Birimi') }}</th>
                                            <th class="py-1.5 pr-2 font-semibold text-right">{{ $t('Satış') }}</th>
                                            <th class="py-1.5 pr-2 font-semibold text-right">{{ $t('Alım') }}</th>
                                            <th class="py-1.5 pr-2 font-semibold text-right">{{ $t('Tahsilat') }}</th>
                                            <th class="py-1.5 pr-2 font-semibold text-right">{{ $t('Ödeme') }}</th>
                                            <th class="py-1.5 font-semibold text-right">{{ $t('Bakiye') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/[0.06]">
                                        <tr v-for="row in byCurrencyRows" :key="row.ccy">
                                            <td class="py-1.5 pr-2 font-bold text-white">{{ row.ccy }}</td>
                                            <td class="py-1.5 pr-2 text-right text-[#CBD5E1]">{{ formatMoney(row.sales, row.ccy) }}</td>
                                            <td class="py-1.5 pr-2 text-right text-[#CBD5E1]">{{ formatMoney(row.purchases, row.ccy) }}</td>
                                            <td class="py-1.5 pr-2 text-right text-[#CBD5E1]">{{ formatMoney(row.receipts, row.ccy) }}</td>
                                            <td class="py-1.5 pr-2 text-right text-[#CBD5E1]">{{ formatMoney(row.payments, row.ccy) }}</td>
                                            <td class="py-1.5 text-right font-bold text-white">{{ formatMoney(row.balance, row.ccy) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Yaşlandırma') }}</h4>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="b in agingChips"
                                :key="b.key"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border"
                                :class="b.value > 0 ? 'bg-[#DC6F31]/15 text-[#FFAE7C] border-[#DC6F31]/30' : 'bg-white/5 text-[#94A3B8] border-white/10'"
                            >
                                {{ $t(b.labelKey) }}: {{ formatMoney(b.value, party.currency_code) }}
                            </span>
                        </div>
                    </section>

                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Açık Belgeler') }} ({{ openDocs.length }})</h4>
                        <div v-if="openDocs.length === 0" class="text-center py-3 text-xs text-[#94A3B8]">{{ $t('Kayıt yok') }}</div>
                        <div v-else class="space-y-2">
                            <div v-for="d in openDocs" :key="d.id" class="p-3 bg-[#212738] rounded-xl border border-white/10 text-xs">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[#94A3B8]">{{ d.serial }}</span>
                                    <span class="font-bold text-white">{{ formatMoney(d.open_amount, party.currency_code) }}</span>
                                </div>
                                <div class="text-[11px] text-[#94A3B8] mt-1">
                                    {{ $t('Kesim') }}: {{ formatDate(d.issue_date) }} · {{ $t('Vade') }}: {{ formatDate(d.due_date) }}
                                    · {{ $t('Genel toplam') }}: {{ formatMoney(d.grand_total, party.currency_code) }}
                                    <span v-if="Number(d.days_overdue) > 0" class="text-[#FFAE7C] font-semibold"> · {{ d.days_overdue }} {{ $t('gün gecikmiş') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Son Ödemeler') }} ({{ recentPayments.length }})</h4>
                        <div v-if="recentPayments.length === 0" class="text-center py-3 text-xs text-[#94A3B8]">{{ $t('Kayıt yok') }}</div>
                        <div v-else class="space-y-2">
                            <div v-for="p in recentPayments" :key="p.id" class="flex items-center justify-between gap-2 p-2.5 bg-[#212738] rounded-xl border border-white/10 text-xs">
                                <div class="flex items-center gap-2 min-w-0">
                                    <StatusPill :color="directionColor(p.direction)" :dot="false">{{ $t(directionLabelKey(p.direction)) }}</StatusPill>
                                    <span class="text-[#94A3B8] whitespace-nowrap">{{ formatDate(p.paid_at) }}</span>
                                    <span v-if="p.document_serial" class="font-mono text-[#64748B] truncate">{{ p.document_serial }}</span>
                                </div>
                                <span class="font-bold whitespace-nowrap" :class="p.direction === 'in' ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">{{ formatMoney(p.amount, p.currency_code) }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="flex gap-2 pb-2">
                        <PillButton variant="primary" size="sm" @click="$emit('receipt', party.id)">
                            <Plus class="w-3.5 h-3.5" /> {{ $t('Tahsilat Al') }}
                        </PillButton>
                        <PillButton variant="secondary" size="sm" @click="$emit('pay', party.id)">
                            <Minus class="w-3.5 h-3.5" /> {{ $t('Ödeme Yap') }}
                        </PillButton>
                    </section>
                </template>
            </div>
        </aside>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import { formatMoney, formatDate } from '../../utils/format';
import { t } from '../../i18n';
import { directionLabelKey, directionColor, partyTypeLabelKey, partyTypeColor, AGING_BUCKETS } from './financeUtils';
import client from '../../api/client';
import { X, Plus, Minus } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    partyId: { type: Number, default: null },
});

defineEmits(['close', 'receipt', 'pay']);

const data = ref(null);
const loading = ref(false);
const loadError = ref('');
let balanceReq = 0;

const party = computed(() => data.value?.party || null);
const totals = computed(() => data.value?.totals || { sales_total: 0, purchases_total: 0, receipts_total: 0, payments_total: 0, balance: 0 });

const byCurrencyRows = computed(() => {
    const bc = data.value?.by_currency || {};
    return Object.entries(bc).map(([ccy, v]) => ({
        ccy,
        sales: v?.sales ?? 0,
        purchases: v?.purchases ?? 0,
        receipts: v?.receipts ?? 0,
        payments: v?.payments ?? 0,
        balance: v?.balance ?? 0,
    }));
});

const agingChips = computed(() => {
    const aging = data.value?.aging || {};
    return AGING_BUCKETS.map((b) => ({ ...b, value: Number(aging[b.key] ?? 0) }));
});

const openDocs = computed(() => data.value?.open_documents || []);
const recentPayments = computed(() => data.value?.payments || []);

async function fetchBalance() {
    if (!props.partyId) return;
    const reqId = ++balanceReq;
    loading.value = true;
    loadError.value = '';
    try {
        const res = await client.get(`/parties/${props.partyId}/balance`);
        if (reqId !== balanceReq) return;
        data.value = res.data;
    } catch (err) {
        if (reqId !== balanceReq) return;
        loadError.value = err.response?.data?.message || t('Cari detayı yüklenemedi.');
        data.value = null;
    } finally {
        if (reqId === balanceReq) loading.value = false;
    }
}

watch(
    () => [props.open, props.partyId],
    ([isOpen, id]) => {
        if (isOpen && id) {
            data.value = null;
            fetchBalance();
        }
    },
    { immediate: true }
);

function refresh() {
    fetchBalance();
}

defineExpose({ refresh });
</script>
