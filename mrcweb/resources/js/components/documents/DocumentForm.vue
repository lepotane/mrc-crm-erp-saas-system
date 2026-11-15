<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs" @click.self="$emit('close')">
        <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-3xl w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-white">{{ mode === 'edit' ? $t('Belgeyi Düzenle') : $t('Yeni Belge') }}</h3>
                    <p class="text-xs text-[#94A3B8]">{{ $t(documentTypeLabel(form.type)) }}</p>
                </div>
                <button type="button" @click="$emit('close')" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <div v-if="generalError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">
                {{ generalError }}
            </div>

            <form @submit.prevent="onSubmit" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div v-if="allowTypes.length > 1">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Belge Tipi') }} <span class="text-[#B9FF66]">*</span></label>
                        <select v-model="form.type" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option v-for="tp in allowTypes" :key="tp" :value="tp">{{ $t(documentTypeLabel(tp)) }}</option>
                        </select>
                    </div>
                    <div class="relative" :class="allowTypes.length > 1 ? '' : 'sm:col-span-2'">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t(partyLabelKey) }} <span class="text-[#B9FF66]">*</span></label>
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
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Kesim') }}</label>
                        <input v-model="form.issue_date" type="date" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Vade') }}</label>
                        <input v-model="form.due_date" type="date" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Para Birimi') }}</label>
                        <select v-model="form.currency_code" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option value="TRY">₺ TRY</option>
                            <option value="USD">$ USD</option>
                            <option value="EUR">€ EUR</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                            {{ $t('Fiyat Sınıfı') }}
                        </label>
                        <select
                            v-model="form.price_currency_code"
                            @change="belgeFiyatSinifiDegisti"
                            class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer"
                        >
                            <option v-for="ccy in PARA_BIRIMLERI" :key="ccy" :value="ccy">
                                {{ paraBirimiEtiketi(ccy) }}
                            </option>
                        </select>
                    </div>
                    <div v-if="showWarehouse">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Depo') }}</label>
                        <select v-model="form.warehouse_id" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option :value="null">{{ $t('Seçiniz') }}</option>
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="stockHintKey && hasTrackedProduct" class="p-3 rounded-xl bg-[#459BBE]/10 border border-[#459BBE]/30 text-[#8CD5F4] text-xs leading-relaxed">
                    {{ $t(stockHintKey) }}
                </div>

                <!-- Barkod / QR Kamera & Okuyucu ile Hızlı Ürün Ekleme Barı -->
                <div class="p-3 rounded-2xl bg-[#131722] border border-[#B9FF66]/25 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                    <div class="flex items-center gap-2 flex-1">
                        <QrCode class="w-4 h-4 text-[#B9FF66] shrink-0" />
                        <input
                            v-model="barcodeInput"
                            type="text"
                            @keydown.enter.prevent="addProductByCode(barcodeInput)"
                            :placeholder="$t('Barkod veya QR kod okutun / yazıp Enter\'a basın...')"
                            class="w-full bg-transparent text-xs sm:text-sm text-white placeholder-[#64748B] focus:outline-none"
                        />
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            v-if="barcodeInput.trim()"
                            type="button"
                            @click="addProductByCode(barcodeInput)"
                            class="px-3 py-1.5 rounded-full bg-white/10 hover:bg-white/15 text-white text-xs font-bold cursor-pointer"
                        >
                            {{ $t('Ekle') }}
                        </button>
                        <button
                            type="button"
                            @click="scannerOpen = true"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-black shadow-[0_4px_14px_rgba(185,255,102,0.3)] hover:bg-[#c8ff85] transition-all cursor-pointer"
                        >
                            <Camera class="w-3.5 h-3.5" />
                            <span>{{ $t('Kamera ile Barkod / QR Okut') }}</span>
                        </button>
                    </div>
                </div>
                <p v-if="scanFeedback" class="text-xs font-semibold px-1" :class="scanSuccess ? 'text-[#B9FF66]' : 'text-[#FFA0A0]'">
                    {{ scanFeedback }}
                </p>

                <div class="space-y-3">
                    <div v-for="(line, idx) in form.lines" :key="idx" class="p-3 bg-[#131722] rounded-2xl border border-white/10 space-y-2.5">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold text-[#94A3B8]">#{{ idx + 1 }}</span>
                            <button type="button" @click="removeLine(idx)" :title="$t('Satır sil')" class="p-1 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 transition-colors cursor-pointer">
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <select v-model="line.product_id" @change="onProductChange(line)" class="w-full px-3 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option :value="null">{{ $t('Seçiniz') }}</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                            <input v-model="line.name" type="text" :placeholder="$t('Ürün adı girin...')" class="w-full px-3 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        </div>
                        <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                            <div>
                                <label class="block text-[10px] font-semibold text-[#94A3B8] mb-1">{{ $t('Miktar') }}</label>
                                <input v-model.number="line.quantity" type="number" step="0.01" min="0" class="w-full px-2.5 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-[#94A3B8] mb-1">{{ $t('Birim') }}</label>
                                <input v-model="line.unit" type="text" class="w-full px-2.5 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-[#94A3B8] mb-1">{{ $t('Birim Fiyat') }}</label>
                                <input v-model.number="line.unit_price" type="number" step="0.01" min="0" class="w-full px-2.5 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-[#94A3B8] mb-1">{{ $t('İskonto %') }}</label>
                                <input v-model.number="line.discount_rate" type="number" step="0.01" min="0" max="100" class="w-full px-2.5 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-[#94A3B8] mb-1">{{ $t('KDV %') }}</label>
                                <input v-model.number="line.tax_rate" type="number" step="0.01" min="0" max="100" class="w-full px-2.5 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                            </div>
                            <div class="flex items-end pb-1">
                                <span class="text-xs font-bold text-white whitespace-nowrap">{{ formatMoney(calcLine(line).total, form.currency_code) }}</span>
                            </div>
                        </div>

                        <!-- Kalem bazlı fiyat sınıfı -->
                        <div v-if="urunSiniflari(products.find((x) => x.id === line.product_id)).length > 1" class="flex items-center gap-2">
                            <label class="text-[10px] font-semibold text-[#94A3B8]">{{ $t('Fiyat Sınıfı') }}</label>
                            <select
                                v-model="line.price_currency_code"
                                @change="kalemFiyatSinifiDegisti(line)"
                                class="px-2 py-1 bg-[#212738] border border-white/10 rounded-lg text-white text-[10px] font-bold focus:outline-none focus:ring-1 focus:ring-[#B9FF66] cursor-pointer"
                            >
                                <option v-for="ccy in urunSiniflari(products.find((x) => x.id === line.product_id))" :key="ccy" :value="ccy">
                                    {{ paraBirimiEtiketi(ccy) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <button type="button" @click="addLine" class="w-full py-2.5 rounded-xl border border-dashed border-white/15 text-xs font-semibold text-[#94A3B8] hover:text-white hover:border-[#B9FF66]/50 transition-colors cursor-pointer">
                        + {{ $t('Satır Ekle') }}
                    </button>
                    <p v-if="errors.items" class="text-xs text-[#FFA0A0]">{{ firstMessage(errors.items) }}</p>
                    <p v-else-if="lineError" class="text-xs text-[#FFA0A0]">{{ lineError }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Notlar') }}</label>
                    <textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                </div>

                <div class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-1.5 text-sm">
                    <div class="flex items-center justify-between text-[#94A3B8] text-xs">
                        <span>{{ $t('Ara toplam') }}</span>
                        <span class="text-white font-semibold">{{ formatMoney(totals.subtotal, form.currency_code) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[#94A3B8] text-xs">
                        <span>{{ $t('İskonto') }}</span>
                        <span class="text-white font-semibold">−{{ formatMoney(totals.discount, form.currency_code) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[#94A3B8] text-xs">
                        <span>{{ $t('KDV') }}</span>
                        <span class="text-white font-semibold">{{ formatMoney(totals.tax, form.currency_code) }}</span>
                    </div>
                    <div class="flex items-center justify-between pt-1.5 border-t border-white/[0.08]">
                        <span class="font-bold text-white">{{ $t('Genel toplam') }}</span>
                        <span class="font-black text-[#B9FF66]">{{ formatMoney(totals.grand, form.currency_code) }}</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-white/[0.08]">
                    <PillButton type="button" variant="ghost" size="md" @click="$emit('close')">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="submit" variant="primary" size="md" :loading="saving">{{ $t('Kaydet') }}</PillButton>
                </div>
            </form>
        </div>

        <!-- Kamera ile Barkod / QR Okuma Modalı -->
        <BarcodeScanner
            :open="scannerOpen"
            @close="scannerOpen = false"
            @detected="onCameraBarcodeDetected"
        />
    </div>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue';
import PillButton from '../ui/PillButton.vue';
import BarcodeScanner from '../BarcodeScanner.vue';
import client from '../../api/client';
import { formatMoney } from '../../utils/format';
import { t } from '../../i18n';
import { documentTypeLabel, blankLine, calcLine, calcTotals, firstMessage, firstErrorFor } from './documentUtils';
import { companySettings, loadCompanySettings, dueDateForTerm } from '../../api/settings';
import { X, Trash2, Camera, QrCode } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'create' },
    allowTypes: { type: Array, default: () => ['quote', 'sales_order', 'invoice'] },
    parties: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    generalError: { type: String, default: '' },
    initial: { type: Object, default: null },
    partyLabelKey: { type: String, default: 'Müşteri' },
    showWarehouse: { type: Boolean, default: false },
    stockHintKey: { type: String, default: '' },
});

const emit = defineEmits(['close', 'submit']);

// satır hataları sunucuda "items.0.quantity" gibi düz anahtarlarla gelir
const lineError = computed(() => firstErrorFor(props.errors, 'items'));

const form = reactive({
    type: props.allowTypes[0] || 'quote',
    party_id: null,
    issue_date: '',
    due_date: '',
    currency_code: companySettings.value.currency_code,
    // Fiyat sınıfı: kalem fiyatları hangi para birimi listesinden alınacak.
    price_currency_code: companySettings.value.currency_code,
    warehouse_id: null,
    notes: '',
    lines: [blankLine(companySettings.value.default_tax_rate)],
});

const partyQuery = ref('');
const partyDropdownOpen = ref(false);
const scannerOpen = ref(false);
const barcodeInput = ref('');
const scanFeedback = ref('');
const scanSuccess = ref(true);

async function addProductByCode(rawCode) {
    const code = String(rawCode || '').trim();
    if (!code) return;

    const lower = code.toLocaleLowerCase('tr');
    let match = props.products.find(
        (p) =>
            String(p.barcode || '').toLocaleLowerCase('tr') === lower ||
            String(p.sku || '').toLocaleLowerCase('tr') === lower
    );

    if (!match) {
        try {
            const res = await client.get('/products', { params: { search: code } });
            const list = res.data?.data || [];
            match =
                list.find(
                    (p) =>
                        String(p.barcode || '').toLocaleLowerCase('tr') === lower ||
                        String(p.sku || '').toLocaleLowerCase('tr') === lower
                ) || list[0];
        } catch {
            match = null;
        }
    }

    if (!match) {
        scanSuccess.value = false;
        scanFeedback.value = t('Barkod / QR ile eşleşen ürün bulunamadı: :code', { code });
        return;
    }

    const existingLine = form.lines.find((l) => l.product_id === match.id);
    if (existingLine) {
        existingLine.quantity = (Number(existingLine.quantity) || 0) + 1;
    } else {
        const emptyLine = form.lines.find((l) => !l.product_id && !(l.name || '').trim());
        const target = emptyLine || (() => {
            const nl = blankLine(companySettings.value.default_tax_rate);
            form.lines.push(nl);
            return nl;
        })();
        target.product_id = match.id;
        target.name = match.name || '';
        target.unit = match.unit?.code || match.unit?.name || '';
        target.unit_price = match.sale_price ?? match.cost_price ?? 0;
        // Seçili fiyat sınıfında fiyatı varsa o kullanılır.
        const sinifFiyati = urunSinifFiyati(match, form.price_currency_code);
        if (sinifFiyati !== null) {
            target.unit_price = sinifFiyati;
            target.price_currency_code = form.price_currency_code;
        }
        if (match.tax_rate !== undefined && match.tax_rate !== null) {
            target.tax_rate = match.tax_rate;
        }
    }

    barcodeInput.value = '';
    scanSuccess.value = true;
    scanFeedback.value = t(':name belge satırlarına eklendi.', { name: match.name });
}

function onCameraBarcodeDetected(code) {
    scannerOpen.value = false;
    addProductByCode(code);
}

const filteredParties = computed(() => {
    const q = partyQuery.value.trim().toLocaleLowerCase('tr');
    if (!q) return props.parties.slice(0, 50);
    return props.parties.filter((p) => (p.name || '').toLocaleLowerCase('tr').includes(q)).slice(0, 50);
});

const totals = computed(() => calcTotals(form.lines));

const hasTrackedProduct = computed(() =>
    form.lines.some((l) => {
        if (!l.product_id) return false;
        const p = props.products.find((x) => x.id === l.product_id);
        return p ? p.track_inventory !== false : false;
    })
);

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function selectParty(p) {
    form.party_id = p.id;
    partyQuery.value = p.name || '';
    partyDropdownOpen.value = false;
}

function onProductChange(line) {
    const p = props.products.find((x) => x.id === line.product_id);
    if (!p) return;
    if (!line.name) line.name = p.name || '';
    if (!line.unit) line.unit = p.unit?.code || p.unit?.name || line.unit;
    if (!line.unit_price) line.unit_price = p.sale_price ?? p.cost_price ?? 0;
    if (p.tax_rate !== undefined && p.tax_rate !== null) line.tax_rate = p.tax_rate;
    // Seçili fiyat sınıfının fiyatı varsa o kullanılır.
    const sinifFiyati = urunSinifFiyati(p, form.price_currency_code);
    if (sinifFiyati !== null) {
        line.unit_price = sinifFiyati;
        line.price_currency_code = form.price_currency_code;
    }
}

/* --- Fiyat sınıfları (para birimi bazlı çoklu fiyat) --- */
const PARA_BIRIMLERI = ['TRY', 'USD', 'EUR', 'GBP'];

function paraBirimiEtiketi(kod) {
    return { TRY: '₺ TRY', USD: '$ USD', EUR: '€ EUR', GBP: '£ GBP' }[kod] || kod;
}

/** Ürünün verilen fiyat sınıfındaki satış fiyatı (yoksa null → fiyat değişmez). */
function urunSinifFiyati(urun, ccy) {
    if (!urun || !ccy) return null;
    const fiyatlar = Array.isArray(urun.prices) ? urun.prices : [];
    const bulunan = fiyatlar.find((p) => p.currency_code === ccy);
    if (!bulunan) return null;
    const deger = Number(bulunan.sale_price);
    return Number.isFinite(deger) ? deger : null;
}

/** Ürünün sahip olduğu fiyat sınıfları (ana fiyat dahil). */
function urunSiniflari(urun) {
    if (!urun) return [];
    const fiyatlar = Array.isArray(urun.prices) ? urun.prices : [];
    const kodlar = fiyatlar.map((p) => p.currency_code);
    if (!kodlar.includes(urun.currency_code)) kodlar.push(urun.currency_code);
    return PARA_BIRIMLERI.filter((c) => kodlar.includes(c));
}

/** Belge düzeyinde fiyat sınıfı değişti → tüm satırlar güncellenir. */
function belgeFiyatSinifiDegisti() {
    form.lines.forEach((line) => {
        const p = props.products.find((x) => x.id === line.product_id);
        if (!p) return;
        const fiyat = urunSinifFiyati(p, form.price_currency_code);
        if (fiyat === null) return;
        line.unit_price = fiyat;
        line.price_currency_code = form.price_currency_code;
    });
}

/** Tek kalemin fiyat sınıfı değişti. */
function kalemFiyatSinifiDegisti(line) {
    const p = props.products.find((x) => x.id === line.product_id);
    if (!p) return;
    const fiyat = urunSinifFiyati(p, line.price_currency_code);
    if (fiyat !== null) line.unit_price = fiyat;
}

function addLine() {
    form.lines.push(blankLine(companySettings.value.default_tax_rate));
}

function removeLine(idx) {
    if (form.lines.length <= 1) {
        form.lines[0] = blankLine(companySettings.value.default_tax_rate);
        return;
    }
    form.lines.splice(idx, 1);
}

function resetForm() {
    const src = props.initial;
    if (props.mode === 'edit' && src) {
        form.type = src.type || props.allowTypes[0] || 'quote';
        form.party_id = src.party_id ?? src.party?.id ?? null;
        partyQuery.value = src.party?.name ?? src.party_name ?? '';
        form.issue_date = (src.issue_date || '').slice(0, 10);
        form.due_date = (src.due_date || '').slice(0, 10);
        form.currency_code = src.currency_code || companySettings.value.currency_code;
        form.warehouse_id = src.warehouse_id ?? null;
        form.notes = src.notes || '';
        const srcLines = src.lines || src.items || [];
        form.lines = srcLines.length ? orderLinesForEdit(srcLines) : [blankLine(companySettings.value.default_tax_rate)];
    } else {
        form.type = props.allowTypes[0] || 'quote';
        form.party_id = null;
        partyQuery.value = '';
        form.issue_date = todayISO();
        form.currency_code = companySettings.value.currency_code;
        form.warehouse_id = null;
        form.notes = '';
        form.lines = [blankLine(companySettings.value.default_tax_rate)];
        // Vade: sunucu da ayni oneriyi uygular; burada gorunmesi kullanici icin onemli.
        form.due_date = dueDateForTerm(form.issue_date, form.type, companySettings.value.default_payment_days);
    }
    partyDropdownOpen.value = false;
}

function orderLinesForEdit(srcLines) {
    return srcLines.map((l) => ({
        product_id: l.product_id ?? null,
        name: l.name || '',
        quantity: l.quantity ?? 1,
        // unit sunucuya gönderilmez; yalnızca bağlı üründen gelen görüntü bilgisidir
        unit: l.product?.unit?.code || l.product?.unit?.name || l.unit_display || '',
        unit_price: l.unit_price ?? 0,
        discount_rate: l.discount_rate ?? 0,
        tax_rate: l.tax_rate ?? companySettings.value.default_tax_rate,
    }));
}

watch(
    () => props.open,
    async (v) => {
        if (!v) return;
        await loadCompanySettings(); // form, firma ayarlariyla dogru degerlerle acilsin
        resetForm();
    },
    { immediate: true },
);

// Kullanici Vade'yi elle doldurduysa uzerine yazilmaz; bos birakildiysa firma vadesi onerilir.
watch([() => form.issue_date, () => form.type], () => {
    if (props.mode === 'edit' || form.due_date) return;
    form.due_date = dueDateForTerm(form.issue_date, form.type, companySettings.value.default_payment_days);
});

function onSubmit() {
    if (!form.party_id) {
        emit('submit', { clientError: t('Cari seçimi zorunludur.') });
        return;
    }
    const items = form.lines
        .filter((l) => l.product_id || (l.name || '').trim() !== '')
        .map((l) => {
            const item = {
                quantity: Number(l.quantity) || 0,
                unit_price: Number(l.unit_price) || 0,
            };
            if (l.product_id) item.product_id = l.product_id;
            // Fiyat sınıfı: kalemin fiyatı hangi para birimi listesinden geldi.
            if (l.price_currency_code) item.price_currency_code = l.price_currency_code;
            // name: product_id varsa sunucu ürün adını kullanır (boş bırakılabilir);
            // yoksa ad zorunlu — bu satırlar zaten elendi.
            if ((l.name || '').trim() !== '') item.name = (l.name || '').trim();
            if ((Number(l.discount_rate) || 0) > 0) item.discount_rate = Number(l.discount_rate);
            if (l.tax_rate !== '' && l.tax_rate !== null) item.tax_rate = Number(l.tax_rate);
            return item;
        });
    if (items.length === 0) {
        emit('submit', { clientError: t('En az bir satır ekleyin.') });
        return;
    }
    if (items.some((it) => !(it.quantity > 0))) {
        emit('submit', { clientError: t("Satır miktarı 0'dan büyük olmalıdır.") });
        return;
    }
    const payload = { type: form.type, party_id: form.party_id, items };
    if (form.issue_date) payload.issue_date = form.issue_date;
    if (form.due_date) payload.due_date = form.due_date;
    if (form.currency_code) payload.currency_code = form.currency_code;
    if (form.warehouse_id) payload.warehouse_id = form.warehouse_id;
    if (form.notes) payload.notes = form.notes;
    emit('submit', { payload });
}
</script>
