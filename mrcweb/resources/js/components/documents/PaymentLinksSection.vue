<!--
    Ödeme bağlantıları : belgenin çekirdeğindeki „tahsilat" halkası.
    Satıcı tutarı yazar, sağlayıcıyı seçer, bağlantıyı kopyalar; müşteri kartıyla öder,
    tahsilat bildirime dayanarak deftere kendiliğinden işler (bu ekranda „ödendi"
    işaretleme YOKTUR — para gerçekten gelmeden kayıt doğmaz).
-->
<template>
    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">
                {{ $t('Ödeme Bağlantıları') }}
            </h4>
            <button
                v-if="!formOpen"
                type="button"
                class="text-[11px] font-bold text-[#B9FF66] hover:underline cursor-pointer"
                @click="openForm"
            >
                + {{ $t('Yeni Bağlantı') }}
            </button>
        </div>

        <div v-if="loading" class="space-y-2">
            <div v-for="i in 2" :key="i" class="h-12 bg-[#212738]/60 rounded-xl animate-pulse"></div>
        </div>

        <div v-else-if="links.length === 0 && !formOpen" class="text-xs text-[#94A3B8]">
            {{ $t('Bu belge için henüz ödeme bağlantısı yok.') }}
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="l in links"
                :key="l.id"
                class="p-3 bg-[#212738] rounded-xl border border-white/10 space-y-1.5"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-bold text-white">
                        {{ formatMoney(l.amount, l.currency_code) }}
                    </span>
                    <StatusPill :color="statusColor(l.status)" :dot="false">{{ $t(l.status_label) }}</StatusPill>
                </div>
                <p class="text-[11px] text-[#94A3B8]">
                    {{ l.provider_label }}
                    <span v-if="l.is_expired"> · {{ $t('süresi doldu') }}</span>
                    <span v-if="l.failure_reason"> · {{ l.failure_reason }}</span>
                </p>
                <div v-if="l.status === 'pending' && l.pay_url" class="flex flex-wrap items-center gap-2">
                    <code class="text-[11px] text-[#8DB8FE] break-all grow font-mono">{{ l.pay_url }}</code>
                    <PillButton size="sm" variant="ghost" @click="copy(l)">
                        <Copy class="w-3.5 h-3.5" /> {{ copiedId === l.id ? $t('Kopyalandı') : $t('Kopyala') }}
                    </PillButton>
                    <PillButton size="sm" variant="ghost" @click="cancel(l)">
                        {{ $t('Süresini doldur') }}
                    </PillButton>
                </div>
            </div>
        </div>

        <form v-if="formOpen" class="space-y-2.5 pt-1" @submit.prevent="create">
            <div class="grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="text-[11px] font-bold text-[#94A3B8]">{{ $t('Sağlayıcı') }}</span>
                    <select
                        v-model="form.provider"
                        class="mt-1 w-full px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer"
                    >
                        <option value="paytr">PayTR</option>
                        <option value="iyzico">iyzico</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-[11px] font-bold text-[#94A3B8]">{{ $t('Tutar') }} ({{ doc.currency_code }})</span>
                    <input
                        v-model="form.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        class="mt-1 w-full px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                    />
                </label>
            </div>
            <label class="block">
                <span class="text-[11px] font-bold text-[#94A3B8]">{{ $t('Geçerlilik (gün)') }}</span>
                <input
                    v-model.number="form.valid_days"
                    type="number"
                    min="1"
                    max="90"
                    class="mt-1 w-full px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                />
            </label>
            <p v-if="formError" class="text-xs font-semibold text-[#FFA0A0]">{{ formError }}</p>
            <div class="flex gap-2">
                <PillButton size="sm" :loading="creating" @click="create" type="button">
                    {{ $t('Bağlantı Üret') }}
                </PillButton>
                <PillButton size="sm" variant="ghost" type="button" @click="formOpen = false">
                    {{ $t('Vazgeç') }}
                </PillButton>
            </div>
        </form>
    </section>
</template>

<script setup>
import { ref, reactive, watch } from 'vue';
import { Copy } from 'lucide-vue-next';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatMoney } from '../../utils/format';

const props = defineProps({
    doc: { type: Object, required: true },
});

const links = ref([]);
const loading = ref(false);
const formOpen = ref(false);
const creating = ref(false);
const formError = ref('');
const copiedId = ref(null);
const form = reactive({ provider: 'paytr', amount: '', valid_days: 7 });

function statusColor(s) {
    return s === 'paid' ? 'lime' : s === 'failed' || s === 'cancelled' ? 'coral' : 'cobalt';
}

async function fetch() {
    if (!props.doc?.id) return;
    loading.value = true;
    try {
        const res = await client.get('/payment-links', { params: { document_id: props.doc.id, per_page: 20 } });
        links.value = res.data?.data || [];
    } catch {
        links.value = [];
    } finally {
        loading.value = false;
    }
}

function openForm() {
    formError.value = '';
    const grand = Number(props.doc?.grand_total);
    form.amount = Number.isFinite(grand) ? grand.toFixed(2) : '';
    form.valid_days = 7;
    formOpen.value = true;
}

async function create() {
    if (creating.value) return;
    creating.value = true;
    formError.value = '';
    try {
        const res = await client.post('/payment-links', {
            provider: form.provider,
            document_id: props.doc.id,
            amount: Number(form.amount),
            valid_days: Number(form.valid_days) || 7,
        });
        formOpen.value = false;
        await fetch();
        const fresh = res.data?.payment_link;
        if (fresh?.pay_url) {
            try { await navigator.clipboard.writeText(fresh.pay_url); } catch { /* pano yoksa kullanıcı kopyalar */ }
        }
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        formError.value = Object.values(bag).flat()[0]
            || err.response?.data?.message
            || t('Bağlantı üretilemedi.');
    } finally {
        creating.value = false;
    }
}

async function copy(l) {
    try {
        await navigator.clipboard.writeText(l.pay_url);
        copiedId.value = l.id;
        setTimeout(() => { copiedId.value = null; }, 2000);
    } catch { /* pano yoksa kullanıcı elle kopyalar */ }
}

async function cancel(l) {
    try {
        await client.post(`/payment-links/${l.id}/cancel`);
        await fetch();
    } catch (err) {
        formError.value = err.response?.data?.message || t('Bağlantı kapatılamadı.');
        formOpen.value = true;
    }
}

watch(() => props.doc?.id, fetch, { immediate: true });
</script>
