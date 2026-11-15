<!--
    Hızlı satış — POS . Mobil öncelikli: ürün ara/barkodla ekle, sepet,
    indirim, ödeme yöntemi, para üstü. Bitir → fatura + stok + tahsilat tek çağrıda.
    Fiş: termal (80/58 mm) veya A5 PDF indirilir; mobilde paylaşım sayfası açılır.
-->
<template>
    <div class="space-y-4 select-none max-w-3xl mx-auto pb-12">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Hızlı Satış') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">{{ $t('Hızlı Satış') }}</h1>
            </div>
            <StatusPill color="lime" :dot="true">POS</StatusPill>
        </div>

        <div v-if="error" class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0]">
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span class="text-sm font-semibold">{{ error }}</span>
        </div>

        <!-- Müşteri + ürün arama -->
        <Card rounded="2xl" padding="md">
            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Müşteri') }}</label>
            <select
                v-model="partyId"
                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all mb-3"
            >
                <option value="">{{ $t('Müşteri seçin') }}</option>
                <option v-for="c in parties" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Ürün ara / barkod okut') }}</label>
            <div class="flex gap-2">
                <input
                    v-model="query"
                    type="text"
                    :placeholder="$t('Ad, kod veya barkod yazın')"
                    @input="searchProducts"
                    @keyup.enter="addFirst"
                    class="grow px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                />
                <button
                    type="button"
                    @click="scanOpen = true"
                    class="shrink-0 w-[46px] rounded-xl bg-[#B9FF66] text-[#131722] flex items-center justify-center shadow-[0_0_16px_rgba(185,255,102,0.35)]"
                    :title="$t('Kamerayla tara')"
                >
                    <ScanBarcode class="w-5 h-5" />
                </button>
            </div>
            <div v-if="results.length" class="mt-2 space-y-1.5 max-h-56 overflow-y-auto">
                <button
                    v-for="p in results"
                    :key="p.id"
                    type="button"
                    @click="addLine(p)"
                    class="w-full flex items-center justify-between gap-2 p-2.5 rounded-xl bg-[#131722]/70 border border-white/10 hover:bg-white/[0.04] transition-colors text-left"
                >
                    <span class="min-w-0"><b class="text-white text-sm truncate block">{{ p.name }}</b>
                    <span class="text-[11px] text-[#64748B]">{{ p.barcode || p.sku || '' }}</span></span>
                    <span class="shrink-0 text-sm font-bold text-[#B9FF66]">{{ formatMoney(posFiyat(p), posCcy) }}</span>
                </button>
            </div>
        </Card>

        <!-- Sepet -->
        <Card v-if="lines.length" rounded="2xl" padding="md">
            <div class="space-y-2">
                <div v-for="(l, i) in lines" :key="i" class="p-3 rounded-xl bg-[#131722]/70 border border-white/10">
                    <div class="flex items-center justify-between gap-2">
                        <b class="text-white text-sm truncate">{{ l.name }}</b>
                        <button type="button" @click="lines.splice(i, 1)" class="text-[#64748B] hover:text-white text-xs px-2 py-1">{{ $t('Sil') }}</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        <div class="flex items-center gap-1">
                            <button type="button" @click="l.quantity = Math.max(0.5, round2(l.quantity - 1))" class="w-8 h-8 rounded-lg bg-white/5 text-white font-bold">−</button>
                            <input v-model.number="l.quantity" type="number" min="0.5" step="0.5" class="w-16 px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-sm text-center" />
                            <button type="button" @click="l.quantity = round2(l.quantity + 1)" class="w-8 h-8 rounded-lg bg-white/5 text-white font-bold">+</button>
                        </div>
                        <input v-model.number="l.unit_price" type="number" min="0" step="0.01" :title="$t('Birim fiyat')" class="w-24 px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-sm text-right" />
                        <input v-model.number="l.discount_rate" type="number" min="0" max="100" step="1" title="%" placeholder="%" class="w-16 px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-sm text-right" />
                        <span class="ml-auto text-sm font-bold text-white">{{ formatMoney(lineTotal(l), posCcy) }}</span>
                    </div>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-white/[0.08] space-y-1 text-sm">
                <div class="flex justify-between text-[#94A3B8]"><span>{{ $t('Ara toplam') }}</span><span>{{ formatMoney(totals.subtotal, posCcy) }}</span></div>
                <div v-if="totals.discount > 0" class="flex justify-between text-[#94A3B8]"><span>{{ $t('İskonto') }}</span><span>−{{ formatMoney(totals.discount, posCcy) }}</span></div>
                <div class="flex justify-between text-[#94A3B8]"><span>{{ $t('KDV') }}</span><span>{{ formatMoney(totals.tax, posCcy) }}</span></div>
                <div class="flex justify-between text-lg font-black text-white"><span>{{ $t('TOPLAM') }}</span><span>{{ formatMoney(totals.grand, posCcy) }}</span></div>
            </div>
        </Card>

        <!-- Ödeme -->
        <Card v-if="lines.length" rounded="2xl" padding="md">
            <div class="flex flex-wrap gap-1.5 mb-3">
                <button
                    v-for="m in methods"
                    :key="m.value"
                    type="button"
                    @click="method = m.value"
                    class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                    :class="method === m.value ? 'bg-[#B9FF66] text-[#131722]' : 'text-[#94A3B8] hover:text-white bg-[#131722] border border-white/10'"
                >
                    {{ $t(m.label) }}
                </button>
            </div>
            <div v-if="method !== 'cash'" class="mb-3">
                <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Tahsilat hesabı') }}</label>
                <select
                    v-model="bankAccountId"
                    class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                >
                    <option value="">{{ $t('Hesapsız kaydet') }}</option>
                    <option v-for="h in accounts" :key="h.id" :value="h.id">{{ h.name }}</option>
                </select>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow min-w-[140px]">
                    <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Alınan') }}</label>
                    <input v-model.number="tendered" type="number" min="0" step="0.01" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm text-right focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all" />
                </div>
                <div class="text-right">
                    <p class="text-[11px] text-[#64748B]">{{ $t('Para üstü') }}</p>
                    <p class="text-xl font-black text-[#B9FF66]">{{ formatMoney(change, posCcy) }}</p>
                </div>
            </div>
            <PillButton class="mt-4 w-full" variant="primary" size="lg" :loading="saving" :disabled="!partyId || !lines.length" @click="submit">
                {{ $t('Satışı Bitir') }} · {{ formatMoney(totals.grand, posCcy) }}
            </PillButton>
            <p v-if="!partyId" class="text-[11px] text-[#64748B] mt-2">{{ $t('Önce müşteri seçin.') }}</p>

        <BarcodeScanner :open="scanOpen" :auto-add="true" @resolve="addLine" @close="scanOpen = false" />
        </Card>

        <!-- Sonuç + fiş -->
        <Card v-if="sale" rounded="2xl" padding="md">
            <div class="text-center py-2">
                <p class="text-sm font-bold text-[#B9FF66]">{{ $t('Satış tamamlandı.') }}</p>
                <p class="text-xs text-[#94A3B8] mt-1">{{ sale.document.serial }} · {{ formatMoney(sale.document.grand_total, sale.document.currency_code) }}</p>
                <p v-if="saleChange > 0" class="text-2xl font-black text-white mt-1">{{ $t('Para üstü') }}: {{ formatMoney(saleChange, sale.document.currency_code) }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2 mt-3">
                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Çıktı dili') }}</label>
                    <select v-model="receiptLang" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm cursor-pointer">
                        <option value="tr">TR</option>
                        <option value="en">EN</option>
                    </select>
                </div>
                <PillButton size="sm" variant="primary" :loading="printing === '80'" @click="printReceipt('80')">{{ $t('Fiş yazdır (80 mm)') }}</PillButton>
                <PillButton size="sm" variant="ghost" :loading="printing === '58'" @click="printReceipt('58')">{{ $t('58 mm') }}</PillButton>
                <PillButton size="sm" variant="ghost" :loading="printing === 'a5'" @click="printReceipt('a5')">A5 PDF</PillButton>
                <button type="button" @click="shareReceipt" class="text-xs text-[#94A3B8] hover:text-white px-3 py-2">{{ $t('Paylaş') }}</button>
                <button type="button" @click="reset" class="text-xs text-[#94A3B8] hover:text-white px-3 py-2">{{ $t('Yeni satış') }}</button>
            </div>
            <div v-if="bridges.length" class="flex flex-wrap items-end gap-2 mt-3 pt-3 border-t border-white/[0.08]">
                <div class="grow min-w-[140px]">
                    <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Köprü yazıcısı') }}</label>
                    <select v-model="bridgeKey" class="w-full px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm cursor-pointer">
                        <option value="">{{ $t('Seçiniz') }}</option>
                        <option v-for="b in bridges" :key="b.device + b.printer" :value="b.device + '|' + b.printer">{{ b.device }} → {{ b.printer }}</option>
                    </select>
                </div>
                <PillButton size="sm" variant="ghost" :loading="printing === 'bridge'" :disabled="!bridgeKey" @click="printBridge">{{ $t('Yazıcıya gönder') }}</PillButton>
            </div>
        </Card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BarcodeScanner from '../components/BarcodeScanner.vue';
import client from '../api/client';
import { t } from '../i18n';
import { formatMoney } from '../utils/format';
import { companySettings, loadCompanySettings } from '../api/settings';
import { AlertCircle, ScanBarcode } from 'lucide-vue-next';

const scanOpen = ref(false);

const parties = ref([]);
const accounts = ref([]);
const results = ref([]);
const query = ref('');
const partyId = ref('');
const lines = ref([]);
const method = ref('cash');
const bankAccountId = ref('');
const tendered = ref(null);
const error = ref('');
const saving = ref(false);
const printing = ref('');
const sale = ref(null);
const saleChange = ref(0);
const bridges = ref([]);
const bridgeKey = ref('');
const receiptLang = ref('tr');

async function fetchBridges() {
    try {
        const res = await client.get('/devices');
        const list = [];
        for (const d of res.data?.data || []) {
            for (const p of d.printers || []) {
                list.push({ device: d.name, deviceId: d.id, printer: p });
            }
        }
        bridges.value = list;
    } catch {
        bridges.value = [];
    }
}

async function printBridge() {
    if (!bridgeKey.value || !sale.value?.document?.id) return;
    printing.value = 'bridge';
    error.value = '';
    try {
        const [deviceName, printer] = bridgeKey.value.split('|');
        const dev = bridges.value.find((b) => b.device === deviceName && b.printer === printer);
        await client.post('/print-jobs', {
            device_id: dev.deviceId,
            printer_name: printer,
            kind: 'escpos',
            lang: receiptLang.value,
            document_id: sale.value.document.id,
        });
        error.value = '';
        alert(t('Yazdırma kuyruğa alındı.'));
    } catch (err) {
        error.value = err.response?.data?.errors?.printer_name?.[0]
            || err.response?.data?.message
            || t('Yazdırma kuyruğa alınamadı.');
    } finally {
        printing.value = '';
    }
}

const methods = [
    { value: 'cash', label: 'Nakit' },
    { value: 'card', label: 'Kart' },
    { value: 'bank', label: 'Banka' },
    { value: 'transfer', label: 'Havale / EFT' },
];

const round2 = (v) => Math.round(Number(v) * 100) / 100;

// POS'un para birimi = şirket para birimi (Ayarlar → Genel). Fiş/fatura da
// sunucuda bu para biriminde kesilir (QuickSaleController::companyCurrency).
const posCcy = computed(() => companySettings.value.currency_code || 'TRY');

/** Ürünün POS para birimindeki satış fiyatı: fiyat sınıfı varsa o, yoksa ana fiyat. */
function posFiyat(p) {
    if (!p) return 0;
    const hedef = posCcy.value;
    const liste = Array.isArray(p.prices) ? p.prices : [];
    const satir = liste.find((r) => r.currency_code === hedef);
    if (satir) {
        const v = Number(satir.sale_price);
        if (Number.isFinite(v)) return v;
    }
    return Number(p.sale_price) || 0;
}

function lineTotal(l) {
    const sub = l.quantity * l.unit_price;
    const disc = sub * (Number(l.discount_rate) || 0) / 100;
    return round2(sub - disc);
}

const totals = computed(() => {
    let sub = 0, disc = 0, tax = 0;
    for (const l of lines.value) {
        const s = l.quantity * l.unit_price;
        const d = s * (Number(l.discount_rate) || 0) / 100;
        // KDV istemcide %20 varsayılır; sunucu ürün/ayar KDV'sini uygular (nihai toplam sunucudadır).
        const rate = l.tax_rate ?? 20;
        sub += s; disc += d; tax += (s - d) * rate / 100;
    }
    sub = round2(sub); disc = round2(disc); tax = round2(tax);
    return { subtotal: sub, discount: disc, tax, grand: round2(sub - disc + tax) };
});

const change = computed(() => {
    if (tendered.value === null || tendered.value === '') return 0;
    return Math.max(0, round2(Number(tendered.value) - totals.value.grand));
});

let searchTimer = null;

async function searchProducts() {
    clearTimeout(searchTimer);
    const q = query.value.trim();
    if (q.length < 2) { results.value = []; return; }
    searchTimer = setTimeout(async () => {
        try {
            const res = await client.get('/products', { params: { search: q, per_page: 10 } });
            results.value = res.data?.data || [];
        } catch {
            results.value = [];
        }
    }, 250);
}

function addLine(p) {
    const found = lines.value.find((l) => l.product_id === p.id);
    if (found) {
        found.quantity = round2(found.quantity + 1);
    } else {
        lines.value.push({
            product_id: p.id,
            name: p.name,
            quantity: 1,
            unit_price: posFiyat(p),
            price_currency_code: posCcy.value,
            discount_rate: 0,
            tax_rate: p.tax_rate ?? 20,
        });
    }
    query.value = '';
    results.value = [];
}

function addFirst() {
    if (results.value.length) addLine(results.value[0]);
}

async function submit() {
    saving.value = true;
    error.value = '';
    try {
        const payload = {
            party_id: Number(partyId.value),
            items: lines.value.map((l) => ({
                product_id: l.product_id,
                quantity: l.quantity,
                unit_price: l.unit_price,
                price_currency_code: l.price_currency_code || posCcy.value,
                discount_rate: l.discount_rate || 0,
            })),
            payment_method: method.value,
        };
        if (bankAccountId.value) payload.bank_account_id = Number(bankAccountId.value);
        if (tendered.value !== null && tendered.value !== '') payload.amount_tendered = Number(tendered.value);
        const res = await client.post('/quick-sales', payload);
        sale.value = res.data?.sale || null;
        saleChange.value = Number(res.data?.change || 0);
        sale.value.receipt_pdf_url = res.data?.receipt_pdf_url;
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        error.value = Object.values(bag).flat()[0]
            || err.response?.data?.message
            || t('Satış kaydedilemedi.');
    } finally {
        saving.value = false;
    }
}

async function fetchReceiptPdf(size, inline) {
    const res = await client.get(sale.value.receipt_pdf_url, {
        params: { size, inline: inline ? 1 : 0, lang: receiptLang.value },
        responseType: 'blob',
    });
    return res.data instanceof Blob ? res.data : new Blob([res.data], { type: 'application/pdf' });
}

async function printReceipt(size) {
    printing.value = size;
    error.value = '';
    try {
        const blob = await fetchReceiptPdf(size, true);
        const url = URL.createObjectURL(blob);
        const w = window.open(url, '_blank');
        if (!w) {
            const a = document.createElement('a');
            a.href = url;
            a.download = `fis-${sale.value.document.serial}-${size}.pdf`;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }
        setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch {
        error.value = t('Fiş alınamadı.');
    } finally {
        printing.value = '';
    }
}

async function shareReceipt() {
    error.value = '';
    try {
        const blob = await fetchReceiptPdf('80', true);
        const file = new File([blob], `fis-${sale.value.document.serial}.pdf`, { type: 'application/pdf' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title: t('Satış fişi') });
        } else {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `fis-${sale.value.document.serial}.pdf`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 5000);
        }
    } catch {
        if (!error.value) error.value = t('Fiş alınamadı.');
    }
}

function reset() {
    lines.value = [];
    sale.value = null;
    tendered.value = null;
    bankAccountId.value = '';
}

onMounted(async () => {
    loadCompanySettings();
    try {
        const [c, h] = await Promise.all([
            client.get('/parties', { params: { type: 'customer', per_page: 100 } }),
            client.get('/bank-accounts'),
        ]);
        parties.value = c.data?.data || [];
        accounts.value = h.data?.bank_accounts || [];
    } catch {
        error.value = t('Liste yüklenemedi.');
    }
    await fetchBridges();
});
</script>
