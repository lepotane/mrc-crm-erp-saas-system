<template>
    <div v-if="open" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('close')"></div>
        <aside class="absolute inset-y-0 right-0 w-full max-w-md bg-[#212738] border-l border-white/15 shadow-2xl flex flex-col">
            <div class="flex items-center justify-between p-5 border-b border-white/[0.08]">
                <div>
                    <h3 class="text-base font-bold text-white">{{ $t('Belge Detayı') }}</h3>
                    <p v-if="doc" class="text-xs text-[#94A3B8] font-mono">{{ documentSerial(doc) }}
                        <span class="ml-1 font-sans font-bold px-1.5 py-0.5 rounded-md border align-middle"
                            :class="pdfLang === 'en' ? 'bg-[#3D55B6]/20 border-[#3D55B6]/50 text-[#B6EAFF]' : 'bg-white/5 border-white/10 text-[#94A3B8]'"
                            :title="$t('Çıktı dili')">{{ pdfLang === 'en' ? 'EN' : 'TR' }}</span>
                    </p>
                </div>
                <button type="button" @click="$emit('close')" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                    <X class="w-5 h-5" />
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-4">
                <div v-if="loading" class="space-y-2">
                    <div v-for="i in 4" :key="i" class="h-12 bg-[#131722]/60 rounded-xl animate-pulse"></div>
                </div>
                <template v-else-if="doc">
                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <StatusPill :color="documentTypeColor(doc.type)" :dot="false">{{ docT(documentTypeLabel(doc.type)) }}</StatusPill>
                            <StatusPill :color="documentStatusColor(doc.status)" :dot="false">{{ docT(documentStatusLabel(doc.status)) }}</StatusPill>
                        </div>
                        <div>
                            <p class="text-lg font-bold text-white">{{ partyOf(doc) }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                <div class="text-[#94A3B8]">{{ docT('Kesim') }}: <span class="text-white">{{ formatDate(doc.issue_date) }}</span></div>
                                <div class="text-[#94A3B8]">{{ docT('Vade') }}: <span class="text-white">{{ formatDate(doc.due_date) }}</span></div>
                                <div class="text-[#94A3B8]">{{ docT('Para Birimi') }}: <span class="text-white">{{ doc.currency_code || '-' }}</span></div>
                                <div v-if="doc.warehouse?.name || doc.warehouse_name" class="text-[#94A3B8]">{{ docT('Depo') }}: <span class="text-white">{{ doc.warehouse?.name ?? doc.warehouse_name }}</span></div>
                            </div>
                            <div v-if="doc.notes" class="mt-2 text-xs text-[#94A3B8]">{{ docT('Notlar') }}: <span class="text-white">{{ doc.notes }}</span></div>
                        </div>
                    </section>

                    <!-- Dış kanal (Tsoft) metadata'sı: yalnızca teknik/opak alanlar, kişisel veri yok. -->
                    <section v-if="channelMeta" class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Kanal') }}</h4>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div v-if="doc.external_no" class="text-[#94A3B8]">{{ $t('Sipariş No') }}: <span class="text-white font-mono">{{ doc.external_no }}</span></div>
                            <div v-if="channelMeta.payment_method" class="text-[#94A3B8]">{{ $t('Ödeme yöntemi') }}: <span class="text-white">{{ channelMeta.payment_method }}</span></div>
                            <div v-if="channelMeta.payment_bank || channelMeta.payment_vendor || channelMeta.payment_card_brand" class="text-[#94A3B8]">{{ $t('Banka / Kart') }}: <span class="text-white">{{ [channelMeta.payment_bank, channelMeta.payment_vendor, channelMeta.payment_card_brand].filter(Boolean).join(' · ') }}</span></div>
                            <div v-if="channelMeta.payment_transaction_id" class="text-[#94A3B8]">{{ $t('İşlem No') }}: <span class="text-white font-mono">{{ channelMeta.payment_transaction_id }}</span></div>
                            <div v-if="channelMeta.cargo_company" class="text-[#94A3B8]">{{ $t('Kargo firması') }}: <span class="text-white">{{ channelMeta.cargo_company }}</span></div>
                            <div v-if="channelMeta.tracking_number" class="text-[#94A3B8]">{{ $t('Takip No') }}: <span class="text-white font-mono">{{ channelMeta.tracking_number }}</span></div>
                            <div v-if="channelMeta.invoice_no" class="text-[#94A3B8]">{{ $t('Fatura No') }}: <span class="text-white font-mono">{{ channelMeta.invoice_no }}</span></div>
                        </div>
                    </section>

                    <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2.5">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ docT('Satırlar') }} ({{ lines.length }})</h4>

                        <!-- Fiyat sınıfı: toplu değiştirme (yalnız taslak/iptal) -->
                        <div
                            v-if="canChangePriceClass"
                            class="flex flex-wrap items-center gap-2 p-2.5 rounded-xl bg-[#B9FF66]/[0.07] border border-[#B9FF66]/25"
                        >
                            <span class="text-[10px] font-black text-[#B9FF66] uppercase tracking-wider">
                                {{ $t('Fiyat Sınıfı') }}
                            </span>
                            <select
                                v-model="priceClassTarget"
                                class="px-2 py-1 bg-[#131722] border border-white/10 rounded-lg text-white text-[11px] font-bold focus:outline-none focus:ring-1 focus:ring-[#B9FF66] cursor-pointer"
                            >
                                <option v-for="ccy in PARA_BIRIMLERI" :key="ccy" :value="ccy">{{ paraBirimiEtiketi(ccy) }}</option>
                            </select>
                            <button
                                type="button"
                                @click="applyPriceClass(null)"
                                :disabled="priceClassSaving"
                                class="px-2.5 py-1 rounded-lg bg-[#B9FF66] text-[#131722] text-[10px] font-black hover:brightness-105 disabled:opacity-50 transition-all cursor-pointer"
                            >
                                {{ $t('Tümünü Uygula') }}
                            </button>
                            <span class="text-[10px] text-[#94A3B8]">{{ $t('Çıktı alırken fiyat sınıfını değiştirebilirsin.') }}</span>
                        </div>

                        <div v-if="lines.length === 0" class="text-center py-4 text-xs text-[#94A3B8]">{{ docT('Kayıt yok') }}</div>
                        <div v-else class="space-y-2">
                            <div v-for="(l, i) in lines" :key="i" class="p-3 bg-[#212738] rounded-xl border border-white/10">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-white truncate">{{ lineName(l) }}</span>
                                    <span class="text-xs font-bold text-[#B9FF66] whitespace-nowrap">{{ formatMoney(lineTotal(l), doc.currency_code) }}</span>
                                </div>
                                <div class="text-[11px] text-[#94A3B8] mt-1">
                                    {{ formatNumber(l.quantity) }} {{ lineUnit(l) }} × {{ formatMoney(l.unit_price, doc.currency_code) }}
                                    <span v-if="Number(l.discount_rate)"> · −%{{ formatNumber(Number(l.discount_rate)) }}</span>
                                    <span> · %{{ formatNumber(Number(l.tax_rate ?? 0)) }} {{ docT('KDV') }}</span>
                                </div>
                                <div v-if="canChangePriceClass" class="flex items-center gap-2 mt-2">
                                    <span class="text-[10px] font-semibold text-[#94A3B8]">{{ $t('Fiyat Sınıfı') }}:</span>
                                    <select
                                        :value="l.price_currency_code || doc.currency_code"
                                        @change="applyPriceClass(l.id, $event.target.value)"
                                        :disabled="priceClassSaving"
                                        class="px-2 py-0.5 bg-[#131722] border border-white/10 rounded-lg text-white text-[10px] font-bold focus:outline-none focus:ring-1 focus:ring-[#B9FF66] cursor-pointer disabled:opacity-50"
                                    >
                                        <option v-for="ccy in PARA_BIRIMLERI" :key="ccy" :value="ccy">{{ paraBirimiEtiketi(ccy) }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="pt-2 border-t border-white/[0.08] space-y-1.5 text-sm">
                            <div class="flex items-center justify-between text-[#94A3B8] text-xs">
                                <span>{{ docT('Ara toplam') }}</span>
                                <span class="text-white font-semibold">{{ formatMoney(viewTotals.subtotal, doc.currency_code) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-[#94A3B8] text-xs">
                                <span>{{ docT('İskonto') }}</span>
                                <span class="text-white font-semibold">−{{ formatMoney(viewTotals.discount, doc.currency_code) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-[#94A3B8] text-xs">
                                <span>{{ docT('KDV') }}</span>
                                <span class="text-white font-semibold">{{ formatMoney(viewTotals.tax, doc.currency_code) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white text-sm">{{ docT('Genel toplam') }}</span>
                                <span class="font-black text-[#B9FF66]">{{ formatMoney(viewTotals.grand, doc.currency_code) }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="flex flex-wrap gap-2">
                        <!-- Karar 21/B: fiiller belge türü + durumuna göre önerilir
                             (kapalı durum makinesinin aynası). Eskiden "İhraç et"
                             her taslak belgede görünür, teklif/siparişte basınca
                             sunucu 422 ile reddederdi. -->
                        <PillButton
                            v-for="action in statusActionsFor(doc)"
                            :key="action.status"
                            :variant="action.variant"
                            size="sm"
                            :loading="saving"
                            @click="$emit('statusAction', action.status)"
                        >
                            <Check class="w-3.5 h-3.5" /> {{ $t(action.label) }}
                        </PillButton>
                        <PillButton v-if="canCancel(doc)" variant="ghost" size="sm" :loading="saving" @click="$emit('cancelDoc')">
                            <Ban class="w-3.5 h-3.5" /> {{ $t('İptal et') }}
                        </PillButton>
                        <PillButton v-if="convertTargetFor(doc)" variant="secondary" size="sm" :loading="saving" @click="$emit('convert', doc)">
                            <ArrowRightLeft class="w-3.5 h-3.5" /> {{ $t(convertLabelFor(doc)) }}
                        </PillButton>
                        <PillButton v-if="canReturn(doc)" variant="ghost" size="sm" :loading="saving" @click="$emit('returnDoc', doc)">
                            <Undo2 class="w-3.5 h-3.5" /> {{ $t('İade al') }}
                        </PillButton>
                        <PillButton variant="ghost" size="sm" @click="$emit('pdf', { doc, lang: pdfLang })">
                            <FileDown class="w-3.5 h-3.5" /> {{ $t('PDF indir') }}
                        </PillButton>
                        <PillButton v-if="(doc.type === 'invoice' || doc.type === 'purchase') && !['draft', 'cancelled'].includes(doc.status)" variant="ghost" size="sm" @click="$emit('deliveryNote', { doc, lang: pdfLang })">
                            <FileDown class="w-3.5 h-3.5" /> {{ $t('İrsaliye') }}
                        </PillButton>
                        <select v-model="pdfLang" :title="$t('Çıktı dili')" class="px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-xs">
                            <option value="tr">TR</option>
                            <option value="en">EN</option>
                        </select>
                    </section>

                    <!-- Karar 44: kesilmiş belge iptal edilip silinebilir (stok aynaya
                         düşer); iptal edilmiş kayıt koşullu silinir. Eskiden ihraç
                         sonrası iptal kapalıydı. -->

                    <!-- Ödemesi olan belge önce ödemesiz kalmalı: iptal/silme
                         sunucuda 422 ile yönlendirir. -->

                    <!-- Ödeme bağlantıları : yalnız ihraç edilmiş satış
                         faturasında. Link üretmek tahsilat değildir; tahsilat sağlayıcı
                         bildirimiyle kendiliğinden deftere işler. -->
                    <PaymentLinksSection v-if="doc && doc.type === 'invoice' && ['issued', 'partially_paid'].includes(doc.status)" :doc="doc" />

                    <!-- Silme : HER durumda silinir. Kesilmişse stok aynaya
                         düşer; bağlı ödeme/iade birlikte silinir (denetim izli);
                         dönüştürme bağları kopar. -->
                    <!-- Silme  + düzenleme (taslak ve
                         iptal: ikisi de stok-nötr) + kopyalama (her durumdan
                         yeni taslak açar — hatalı belgenin doğrusu). -->
                    <section class="flex flex-wrap gap-2">
                        <PillButton v-if="['draft', 'cancelled'].includes(doc.status)" variant="secondary" size="sm" @click="$emit('edit', doc)">
                            <Pencil class="w-3.5 h-3.5" /> {{ $t('Düzenle') }}
                        </PillButton>
                        <PillButton variant="danger" size="sm" @click="$emit('deleteRequest', doc)">
                            <Trash2 class="w-3.5 h-3.5" /> {{ $t('Sil') }}
                        </PillButton>
                        <PillButton variant="ghost" size="sm" @click="$emit('duplicate', doc)">
                            <Copy class="w-3.5 h-3.5" /> {{ $t('Kopyala') }}
                        </PillButton>
                    </section>
                </template>
            </div>
        </aside>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import PaymentLinksSection from './PaymentLinksSection.vue';
import client from '../../api/client';
import { formatMoney, formatDate, formatNumber } from '../../utils/format';
import { tIn } from '../../i18n';
import {
    documentTypeLabel, documentTypeColor, documentStatusLabel, documentStatusColor,
    convertTargetFor, convertLabelFor, canReturn, calcLine, calcTotals, documentSerial, canCancel, statusActionsFor,
} from './documentUtils';
import { X, Check, Ban, Pencil, Trash2, FileDown, ArrowRightLeft, Undo2, Copy } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    doc: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
});

defineEmits(['close', 'issue', 'cancelDoc', 'statusAction', 'convert', 'returnDoc', 'edit', 'deleteRequest', 'pdf', 'deliveryNote', 'duplicate', 'refresh']);

// Çıktı dili : PDF + irsaliye bu dille üretilir.
const pdfLang = ref('tr');

// Belge içeriği etiketleri ÇIKTI dilini izler : EN seçiliyse önizleme
// de İngilizce görünür, düğmeler ve başlıklar arayüz dilinde kalır.
const docT = (key, params) => tIn(pdfLang.value, key, params || {});

const lines = computed(() => props.doc?.lines || props.doc?.items || []);

// Kanal metadata'sı backend'den dizi ya da JSON string gelebilir; ikisini de
// kabul eder, bozuk/null değerde bölümü gizler.
const channelMeta = computed(() => {
    const raw = props.doc?.channel_meta;
    if (!raw) return null;
    let parsed = raw;
    if (typeof raw === 'string') {
        try { parsed = JSON.parse(raw); } catch { return null; }
    }
    if (!parsed || typeof parsed !== 'object') return null;
    return Object.keys(parsed).length ? parsed : null;
});

/* --- Fiyat sınıfı (para birimi bazlı çoklu fiyat) --- */
const PARA_BIRIMLERI = ['TRY', 'USD', 'EUR', 'GBP'];
const priceClassTarget = ref('TRY');
const priceClassSaving = ref(false);

// Fiyat sınıfı yalnız taslak/iptal belgelerde değişebilir (kesilmiş belgenin
// sözleşmesi bozulmaz — sunucu da aynı kapıyı uygular).
const canChangePriceClass = computed(() =>
    props.doc && ['draft', 'cancelled'].includes(props.doc.status)
);

function paraBirimiEtiketi(kod) {
    return { TRY: '₺ TRY', USD: '$ USD', EUR: '€ EUR', GBP: '£ GBP' }[kod] || kod;
}

/**
 * Fiyat sınıfını değiştirir: itemId verilirse o kalem, verilmezse tüm kalemler.
 * Dönüşüm kurla yapılır (sunucu yetkili) → belgenin para birimi değişmez.
 */
async function applyPriceClass(itemId, currency) {
    const ccy = currency || priceClassTarget.value;
    if (!props.doc) return;

    priceClassSaving.value = true;
    try {
        await client.post(`/documents/${props.doc.id}/price-class`, {
            currency_code: ccy,
            ...(itemId ? { item_ids: [itemId] } : {}),
        });

        $emit('refresh');
    } catch (err) {
        // Hata mesajı üst katmanda gösterilir.
        console.error(err);
    } finally {
        priceClassSaving.value = false;
    }
}

const viewTotals = computed(() => {
    const d = props.doc || {};

    // GET /documents/{id} toplamları üst seviye kolonlarda verir (sunucuda dondurulmuş
    // değerler) → bunlar esas alınır; satırdan yeniden hesap yalnız son çare.
    if (d.grand_total !== undefined && d.grand_total !== null) {
        return {
            subtotal: d.subtotal ?? 0,
            discount: d.discount_total ?? 0,
            tax: d.tax_total ?? 0,
            grand: d.grand_total ?? 0,
        };
    }

    const t = d.totals;
    if (t && (t.grand_total !== undefined || t.total !== undefined || t.grand !== undefined)) {
        return {
            subtotal: t.subtotal ?? t.gross_total ?? 0,
            discount: t.discount_total ?? t.discount ?? 0,
            tax: t.tax_total ?? t.tax ?? 0,
            grand: t.grand_total ?? t.total ?? t.grand ?? 0,
        };
    }

    return calcTotals(lines.value);
});

function partyOf(d) {
    return d.party?.name ?? d.party_name ?? '-';
}
function lineTotal(l) {
    if (l.total !== undefined && l.total !== null) return l.total;
    if (l.line_total !== undefined && l.line_total !== null) return l.line_total;
    return calcLine(l).total;
}
function lineUnit(l) {
    const u = l.product?.unit;
    if (pdfLang.value === 'en' && u?.name_en) return u.name_en;
    return u?.code || u?.name || l.unit_display || l.unit || '';
}
function lineName(l) {
    if (pdfLang.value === 'en' && l.product?.name_en) return l.product.name_en;
    return l.name || '-';
}
</script>
