<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs" @click.self="$emit('close')">
        <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-white">{{ $t('Yeni Ödeme Kaydet') }}</h3>
                    <p class="text-xs text-[#94A3B8]">{{ $t('Tahsilat veya ödeme kaydedin; bakiye sunucudan güncellenir.') }}</p>
                </div>
                <button type="button" @click="$emit('close')" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <div v-if="generalError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">
                {{ generalError }}
            </div>

            <form @submit.prevent="onSubmit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Yön') }} <span class="text-[#B9FF66]">*</span></label>
                    <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#131722] border border-white/10 w-fit">
                        <button
                            v-for="d in directionTabs"
                            :key="d.value"
                            type="button"
                            @click="setDirection(d.value)"
                            class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                            :class="form.direction === d.value
                                ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                                : 'text-[#94A3B8] hover:text-white'"
                        >
                            {{ $t(d.key) }}
                        </button>
                    </div>
                    <p v-if="errors.direction" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.direction) }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="relative">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Cari') }} <span class="text-[#B9FF66]">*</span></label>
                        <input
                            v-model="partyQuery"
                            type="text"
                            :placeholder="$t('Cari arayın...')"
                            @focus="partyDropdownOpen = true"
                            @click="partyDropdownOpen = true"
                            @input="partyDropdownOpen = true"
                            class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                        />
                        <div v-if="partyDropdownOpen" class="absolute z-10 mt-1 w-full max-h-48 overflow-y-auto bg-[#131722] border border-white/10 rounded-xl shadow-xl">
                            <button
                                v-for="p in filteredParties"
                                :key="p.id"
                                type="button"
                                @click="selectParty(p)"
                                class="w-full text-left px-4 py-2 text-sm text-white hover:bg-white/5 transition-colors cursor-pointer"
                            >
                                {{ p.name }}
                            </button>
                            <div v-if="filteredParties.length === 0" class="px-4 py-2 text-xs text-[#94A3B8]">{{ $t('Kayıt yok') }}</div>
                        </div>
                        <p v-if="errors.party_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.party_id) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Tutar') }} <span class="text-[#B9FF66]">*</span></label>
                        <input
                            v-model.number="form.amount"
                            type="number"
                            step="0.01"
                            min="0"
                            :placeholder="$t('0,00')"
                            class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                        />
                        <p v-if="errors.amount" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.amount) }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Para Birimi') }}</label>
                        <select
                            v-model="form.currency_code"
                            :disabled="currencyLocked"
                            :title="currencyLocked ? $t('Belge para birimine kilitlendi.') : ''"
                            class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer disabled:opacity-60"
                        >
                            <option value="TRY">₺ TRY</option>
                            <option value="USD">$ USD</option>
                            <option value="EUR">€ EUR</option>
                        </select>
                        <p v-if="errors.currency_code" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.currency_code) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Tarih') }}</label>
                        <input v-model="form.paid_at" type="date" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                        <p v-if="errors.paid_at" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.paid_at) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Yöntem') }}</label>
                        <select v-model="form.method" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option value="">{{ $t('Seçiniz') }}</option>
                            <option v-for="m in methodOptions" :key="m.value" :value="m.value">{{ $t(m.key) }}</option>
                        </select>
                        <p v-if="errors.method" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.method) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Kasa / Banka') }}</label>
                        <select v-model="form.bank_account_id" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option :value="null">{{ $t('Seçiniz') }}</option>
                            <option v-for="a in activeAccounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency_code }})</option>
                        </select>
                        <p v-if="errors.bank_account_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.bank_account_id) }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Bağlı belge') }}</label>
                    <select
                        v-model="form.document_id"
                        :disabled="!form.party_id || docsLoading"
                        class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer disabled:opacity-60"
                    >
                        <option :value="null">{{ docsLoading ? $t('Yükleniyor...') : $t('Belge seçin (isteğe bağlı)') }}</option>
                        <option v-for="d in eligibleDocs" :key="d.id" :value="d.id">
                            {{ d.serial }} · {{ formatMoney(d.grand_total, d.currency_code) }} · {{ formatDate(d.due_date) }}
                        </option>
                    </select>
                    <p class="text-[11px] text-[#64748B] mt-1">{{ $t('Yalnızca ihraç edilmiş ve kısmen ödenmiş belgeler listelenir.') }}</p>
                    <p v-if="errors.document_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.document_id) }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Dekont no') }}</label>
                        <input v-model="form.reference" type="text" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        <p v-if="errors.reference" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.reference) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Notlar') }}</label>
                        <input v-model="form.note" type="text" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        <p v-if="errors.note" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(errors.note) }}</p>
                    </div>
                </div>

                <div v-if="selectedDoc" class="p-3 rounded-xl bg-[#459BBE]/10 border border-[#459BBE]/30 text-xs text-[#8CD5F4] leading-relaxed">
                    {{ $t('Belge kalanı') }}: <strong class="text-white">{{ formatMoney(docOpenAmount, selectedDoc.currency_code) }}</strong>
                </div>

                <!-- Kur seçimi : dövizli belgede tahsilat hangi kurla işlensin?
                     Belge kuru = kesim gününün fotoğrafı; güncel kur = bugünün kuru. -->
                <div v-if="showRateChoice" class="p-3 rounded-xl bg-[#131722] border border-white/10 space-y-2">
                    <p class="text-xs font-bold text-[#94A3B8]">{{ $t('Hangi kurla işlensin?') }}</p>
                    <label class="flex items-center gap-2 text-xs text-white cursor-pointer">
                        <input v-model="form.rate_source" type="radio" value="document" class="w-4 h-4 accent-[#B9FF66]" />
                        <span>{{ $t('Belge kuru') }}: 1 {{ docCurrency }} = {{ formatRate(docRate) }} TRY
                            <span v-if="docRateDate" class="text-[#94A3B8]">({{ docRateDate }})</span>
                        </span>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-white cursor-pointer">
                        <input v-model="form.rate_source" type="radio" value="current" class="w-4 h-4 accent-[#B9FF66]" />
                        <span>{{ $t('Güncel kur') }}: 1 {{ docCurrency }} = {{ formatRate(currentRate) }} TRY
                            <span class="text-[#94A3B8]">({{ $t('bugün') }})</span>
                        </span>
                    </label>
                    <p v-if="errors.rate_source" class="text-xs text-[#FFA0A0]">{{ firstMessage(errors.rate_source) }}</p>
                    <p class="text-[11px] text-[#64748B]">
                        {{ $t('Tutarın TL karşılığı') }}: <strong class="text-white">{{ formatMoney(tryEquivalent, 'TRY') }}</strong>
                    </p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-white/[0.08]">
                    <PillButton type="button" variant="ghost" size="md" @click="$emit('close')">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="submit" variant="primary" size="md" :loading="saving">{{ $t('Kaydet') }}</PillButton>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, computed, watch, onUnmounted } from 'vue';
import PillButton from '../ui/PillButton.vue';
import { formatMoney, formatDate } from '../../utils/format';
import { t } from '../../i18n';
import { firstMessage, methodLabelKey, todayISO } from './financeUtils';
import client from '../../api/client';
import { X } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    parties: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    generalError: { type: String, default: '' },
    initialDirection: { type: String, default: 'in' },
    initialPartyId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'submit']);

const directionTabs = computed(() => [
    { value: 'in', key: 'Tahsilat' },
    { value: 'out', key: 'Ödeme' },
]);

const methodOptions = computed(() => [
    { value: 'cash', key: 'Nakit' },
    { value: 'bank', key: 'Banka' },
    { value: 'card', key: 'Kart' },
    { value: 'transfer', key: 'Havale / EFT' },
]);

const form = reactive({
    direction: 'in',
    party_id: null,
    amount: null,
    currency_code: 'TRY',
    paid_at: todayISO(),
    method: '',
    bank_account_id: null,
    document_id: null,
    reference: '',
    note: '',
    rate_source: 'document',
});

// Kur seçimi : yalnız dövizli BELGEYE bağlı tahsilatta sorulur.
const docCurrency = computed(() => selectedDoc.value?.currency_code || 'TRY');
const showRateChoice = computed(() => !!selectedDoc.value && docCurrency.value !== 'TRY');
const docRate = ref(null);
const docRateDate = ref('');
const currentRate = ref(null);

function formatRate(v) {
    if (v === null || v === undefined) return '—';
    return Number(v).toLocaleString('tr-TR', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}

const effectiveRate = computed(() => (form.rate_source === 'current' ? currentRate.value : docRate.value) ?? 1);
const tryEquivalent = computed(() => (Number(form.amount) > 0 ? Number(form.amount) * effectiveRate.value : 0));

async function fetchRateInfo(docId) {
    docRate.value = null;
    docRateDate.value = '';
    currentRate.value = null;
    if (!docId) return;
    try {
        const [detay, guncel] = await Promise.all([
            client.get(`/documents/${docId}`),
            client.get('/exchange-rates', { params: { currency: docCurrency.value } }),
        ]);
        const doc = detay.data?.document;
        if (doc && doc.currency_code === docCurrency.value) {
            docRate.value = doc.exchange_rate !== undefined && doc.exchange_rate !== null ? Number(doc.exchange_rate) : null;
            docRateDate.value = doc.rate_date || '';
        }
        const satir = (guncel.data?.data || [])[0];
        if (satir) currentRate.value = Number(satir.rate);
    } catch {
        // Kur bilgisi yüklenemezse seçim gizlenmez; sunucu varsayılanı (belge kuru) çalışır.
    }
}

const partyQuery = ref('');
const partyDropdownOpen = ref(false);

// Yön↔cari tipi uyumu (sunucu kuralı): in→customer|both, out→supplier|both.
const allowedPartyTypes = computed(() => (form.direction === 'in' ? ['customer', 'both'] : ['supplier', 'both']));

const directionParties = computed(() => props.parties.filter((p) => allowedPartyTypes.value.includes(p.type)));

const filteredParties = computed(() => {
    const q = partyQuery.value.trim().toLocaleLowerCase('tr');
    if (!q) return directionParties.value.slice(0, 50);
    return directionParties.value.filter((p) => (p.name || '').toLocaleLowerCase('tr').includes(q)).slice(0, 50);
});

const activeAccounts = computed(() => (props.bankAccounts || []).filter((a) => a.is_active));

// Bağlı belge adayları: cari + yöne göre belge tipi, durumu issued|partially_paid.
const eligibleDocs = ref([]);
const docsLoading = ref(false);
let docsReq = 0;
let docsTimer = null;
onUnmounted(() => clearTimeout(docsTimer));

const docType = computed(() => (form.direction === 'in' ? 'invoice' : 'purchase'));

async function fetchEligibleDocs() {
    clearTimeout(docsTimer);
    if (!form.party_id) {
        eligibleDocs.value = [];
        return;
    }
    const reqId = ++docsReq;
    docsLoading.value = true;
    try {
        const res = await client.get('/documents', {
            params: { party_id: form.party_id, type: docType.value, per_page: 100 },
        });
        if (reqId !== docsReq) return;
        const rows = res.data?.data || [];
        eligibleDocs.value = rows.filter((d) => ['issued', 'partially_paid'].includes(d.status));
    } catch {
        if (reqId !== docsReq) return;
        eligibleDocs.value = [];
    } finally {
        if (reqId === docsReq) docsLoading.value = false;
    }
}

function scheduleDocsFetch() {
    clearTimeout(docsTimer);
    docsTimer = setTimeout(fetchEligibleDocs, 250);
}

const selectedDoc = computed(() => eligibleDocs.value.find((d) => d.id === form.document_id) || null);
const currencyLocked = computed(() => !!selectedDoc);
const docOpenAmount = computed(() => {
    if (!selectedDoc.value) return 0;
    const paid = Number(selectedDoc.value.paid_total ?? 0);
    return Number(selectedDoc.value.grand_total ?? 0) - paid;
});

watch(
    () => selectedDoc.value,
    (d) => {
        if (d && d.currency_code) form.currency_code = d.currency_code;
        form.rate_source = 'document';
        fetchRateInfo(d ? d.id : null);
    }
);

function selectParty(p) {
    form.party_id = p.id;
    partyQuery.value = p.name || '';
    partyDropdownOpen.value = false;
    form.document_id = null;
    if (p.currency_code) form.currency_code = p.currency_code;
    fetchEligibleDocs();
}

function setDirection(v) {
    if (form.direction === v) return;
    form.direction = v;
    // Yön değişince belge seçimi geçersiz kalır; uymayan cari seçimini temizle.
    form.document_id = null;
    const cur = props.parties.find((p) => p.id === form.party_id);
    if (cur && !allowedPartyTypes.value.includes(cur.type)) {
        form.party_id = null;
        partyQuery.value = '';
    }
    fetchEligibleDocs();
}

function resetForm() {
    form.direction = props.initialDirection === 'out' ? 'out' : 'in';
    form.party_id = props.initialPartyId ?? null;
    const preset = props.initialPartyId != null ? props.parties.find((p) => p.id === props.initialPartyId) : null;
    partyQuery.value = preset ? preset.name || '' : '';
    form.amount = null;
    form.currency_code = preset?.currency_code || 'TRY';
    form.paid_at = todayISO();
    form.method = '';
    form.bank_account_id = null;
    form.document_id = null;
    form.reference = '';
    form.note = '';
    form.rate_source = 'document';
    docRate.value = null;
    docRateDate.value = '';
    currentRate.value = null;
    partyDropdownOpen.value = false;
    eligibleDocs.value = [];
    if (form.party_id) fetchEligibleDocs();
}

watch(
    () => props.open,
    (v) => {
        if (v) resetForm();
    },
    { immediate: true }
);

watch(
    () => [props.initialPartyId, props.initialDirection],
    () => {
        if (props.open) resetForm();
    }
);

function onSubmit() {
    if (!form.party_id) {
        emit('submit', { clientError: t('Cari seçimi zorunludur.') });
        return;
    }
    if (!(Number(form.amount) > 0)) {
        emit('submit', { clientError: t('Tutar sıfırdan büyük olmalıdır.') });
        return;
    }
    const payload = {
        party_id: form.party_id,
        direction: form.direction,
        amount: Number(form.amount),
        currency_code: form.currency_code,
    };
    if (form.paid_at) payload.paid_at = form.paid_at;
    if (form.method) payload.method = form.method;
    if (form.bank_account_id) payload.bank_account_id = form.bank_account_id;
    if (form.document_id) payload.document_id = form.document_id;
    if (form.document_id && (form.currency_code || 'TRY') !== 'TRY') payload.rate_source = form.rate_source || 'document';
    if ((form.reference || '').trim() !== '') payload.reference = form.reference.trim();
    if ((form.note || '').trim() !== '') payload.note = form.note.trim();
    emit('submit', { payload });
}
</script>
