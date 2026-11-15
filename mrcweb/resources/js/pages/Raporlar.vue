<template>
    <div class="space-y-6 select-none max-w-7xl mx-auto pb-12">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Raporlar & Analiz') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                    {{ $t('Raporlar & Analiz') }}
                </h1>
                <p class="text-xs text-[#94A3B8] mt-2">
                    {{ $t('Ciro, KDV, cari bakiye, stok değeri ve stok hareketleri burada raporlanır.') }}
                    <span class="text-[#64748B]">· {{ $t('Taslak ve iptal edilmiş belgeler hiçbir raporda sayılmaz.') }}</span>
                </p>
            </div>
        </div>

        <!-- GÖRSEL ANALİTİK ŞÖLENİ (Sadece Raporlar Sayfasına Özel: Sütun + Pasta + Dalga Grafikleri) -->
        <VisualStatsBanner
            badge-text="Raporlar & Görsel Analitik"
            :title="$t('Dönemsel Ciro & Kârlılık Sütunları')"
            :subtitle="$t('Aylık satış, tahsilat ve stok devir sütun karşılaştırması')"
            primary-value="%28,4 Kâr"
            trend-text="+19.4% büyüme"
            accent-color="#3D55B6"
            :pie-title="$t('Rapor & Gelir Pasta Dağılımı')"
            :pie-subtitle="$t('Satış / Kâr / KDV / Stok')"
            pie-center-value="100%"
            :pie-center-label="$t('Analiz')"
            :pie-segments="[
                { label: $t('Net Satış'), pct: 52, color: '#B9FF66' },
                { label: $t('Net Kâr'), pct: 28, color: '#3D55B6' },
                { label: $t('Stok Değeri'), pct: 12, color: '#DC6F31' },
                { label: $t('KDV Dengesi'), pct: 8, color: '#BC5FCF' },
            ]"
            :wave-title="$t('Büyüme & Nakit Akışı Dalgası')"
            :wave-subtitle="$t('6 aylık finansal ve operasyonel ivme dalgası')"
            wave-badge="Canlı Rapor"
        />

        <div
            v-if="globalError"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0]"
        >
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span class="text-sm font-semibold">{{ globalError }}</span>
        </div>

        <Card rounded="2xl" padding="md">
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#131722] border border-white/10 w-fit flex-wrap">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        @click="activeTab = tab.value"
                        class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                        :class="activeTab === tab.value
                            ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                            : 'text-[#94A3B8] hover:text-white'"
                    >
                        {{ $t(tab.key) }}
                    </button>
                </div>
                <PillButton class="sm:ml-auto" variant="ghost" size="sm" @click="resetFilters">
                    <RotateCcw class="w-3.5 h-3.5" />
                    {{ $t('Sıfırla') }}
                </PillButton>
            </div>

            <!-- filtre şeridi: sekmenin ihtiyacına göre (denetim kendi filtresini taşır) -->
            <div v-if="activeTab !== 'denetim'" class="flex flex-wrap items-end gap-3 mt-4 pt-4 border-t border-white/[0.08]">
                <div v-if="needsDateRange" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Başlangıç') }}</label>
                    <input v-model="f.from" type="date" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                </div>
                <div v-if="needsDateRange" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Bitiş') }}</label>
                    <input v-model="f.to" type="date" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                </div>
                <div v-if="activeTab === 'satis'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Kırılım') }}</label>
                    <select v-model="f.groupBy" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option v-for="g in salesGroupBys" :key="g.value" :value="g.value">{{ $t(g.key) }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'satis'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Belge Tipi') }}</label>
                    <select v-model="f.docType" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option v-for="d in docTypeOptions" :key="d.value" :value="d.value">{{ $t(d.key) }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'stokhareket'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Kırılım') }}</label>
                    <select v-model="f.movementGroupBy" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option v-for="g in movementGroupBys" :key="g.value" :value="g.value">{{ $t(g.key) }}</option>
                    </select>
                </div>
                <div v-if="needsWarehouse" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Depo') }}</label>
                    <select v-model="f.warehouseId" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option :value="''">{{ $t('Tüm depolar') }}</option>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}{{ w.is_default ? ' ★' : '' }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'stokdegeri'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Kategori') }}</label>
                    <select v-model="f.categoryId" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option :value="''">{{ $t('Tüm kategoriler') }}</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'stokhareket'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Ürün') }}</label>
                    <select v-model="f.productId" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option :value="''">{{ $t('Tüm ürünler') }}</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'kar'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Kırılım') }}</label>
                    <select v-model="f.profitGroupBy" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="day">{{ $t('Günlük') }}</option>
                        <option value="product">{{ $t('Ürün') }}</option>
                        <option value="category">{{ $t('Kategori') }}</option>
                    </select>
                </div>
                <div v-if="needsDays" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Dönem') }}</label>
                    <select v-model.number="f.days" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option :value="30">{{ $t('Son 30 gün') }}</option>
                        <option :value="90">{{ $t('Son 90 gün') }}</option>
                        <option :value="180">{{ $t('Son 180 gün') }}</option>
                        <option :value="365">{{ $t('Son 1 yıl') }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'cari'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Cari Tipi') }}</label>
                    <select v-model="f.partyType" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="all">{{ $t('Tümü') }}</option>
                        <option value="customer">{{ $t('Müşteri') }}</option>
                        <option value="supplier">{{ $t('Tedarikçi') }}</option>
                        <option value="both">{{ $t('Her İkisi') }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'cari'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Sıralama') }}</label>
                    <select v-model="f.sort" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="balance_desc">{{ $t('Bakiye (önce büyük)') }}</option>
                        <option value="balance_asc">{{ $t('Bakiye (önce küçük)') }}</option>
                        <option value="name">{{ $t('Ada göre') }}</option>
                        <option value="oldest_due">{{ $t('En eski vadeye göre') }}</option>
                    </select>
                </div>
                <div v-if="activeTab === 'cari'" class="flex flex-col gap-1">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Tarih') }}</label>
                    <input v-model="f.asOf" type="date" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                </div>
                <div v-if="activeTab === 'cari'" class="flex flex-col gap-1 grow min-w-[180px]">
                    <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Ara') }}</label>
                    <input
                        v-model="f.search"
                        type="text"
                        :placeholder="$t('Cari kod veya adı ile ara...')"
                        class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                    />
                </div>
            </div>
        </Card>

        <SalesSummaryTab v-if="activeTab === 'satis'" :applied="appliedSales" @error="onTabError" />
        <ProfitTab v-else-if="activeTab === 'kar'" :applied="appliedProfit" @error="onTabError" />
        <TaxSummaryTab v-else-if="activeTab === 'kdv'" :applied="appliedTax" @error="onTabError" />
        <PartyBalancesTab v-else-if="activeTab === 'cari'" :applied="appliedParties" @error="onTabError" />
        <StockValuationTab v-else-if="activeTab === 'stokdegeri'" :applied="appliedStockValue" @error="onTabError" />
        <StockMovementsTab v-else-if="activeTab === 'stokhareket'" :applied="appliedMovements" @error="onTabError" />
        <StockReplenishmentTab v-else-if="activeTab === 'tedarik'" :applied="appliedReplenishment" @error="onTabError" />
        <StockAbcTab v-else-if="activeTab === 'abc'" :applied="appliedAbc" @error="onTabError" />
        <StockTurnoverTab v-else-if="activeTab === 'devir'" :applied="appliedTurnover" @error="onTabError" />
        <AuditTab v-else-if="activeTab === 'denetim'" @error="onTabError" />
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { AlertCircle, RotateCcw } from 'lucide-vue-next';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import VisualStatsBanner from '../components/ui/VisualStatsBanner.vue';
import client from '../api/client';
import { t } from '../i18n';
import {
    SALES_GROUP_BYS, MOVEMENT_GROUP_BYS, DOC_TYPE_OPTIONS, yearStartISO, todayISO,
} from '../components/reports/reportUtils';
import SalesSummaryTab from '../components/reports/SalesSummaryTab.vue';
import TaxSummaryTab from '../components/reports/TaxSummaryTab.vue';
import PartyBalancesTab from '../components/reports/PartyBalancesTab.vue';
import StockValuationTab from '../components/reports/StockValuationTab.vue';
import StockMovementsTab from '../components/reports/StockMovementsTab.vue';
import StockReplenishmentTab from '../components/reports/StockReplenishmentTab.vue';
import StockAbcTab from '../components/reports/StockAbcTab.vue';
import StockTurnoverTab from '../components/reports/StockTurnoverTab.vue';
import ProfitTab from '../components/reports/ProfitTab.vue';
import AuditTab from '../components/reports/AuditTab.vue';

const tabs = [
    { value: 'satis', key: 'Satış Özeti' },
    { value: 'kar', key: 'Kâr Raporu' },
    { value: 'kdv', key: 'KDV Özeti' },
    { value: 'cari', key: 'Cari Bakiye' },
    { value: 'stokdegeri', key: 'Stok Değeri' },
    { value: 'stokhareket', key: 'Stok Hareketleri' },
    { value: 'tedarik', key: 'Tedarik Önerisi' },
    { value: 'abc', key: 'ABC Analizi' },
    { value: 'devir', key: 'Devir Hızı' },
    { value: 'denetim', key: 'Denetim' },
];

const activeTab = ref('satis');
const salesGroupBys = SALES_GROUP_BYS;
const movementGroupBys = MOVEMENT_GROUP_BYS;
const docTypeOptions = DOC_TYPE_OPTIONS;

const f = reactive({
    from: yearStartISO(),
    to: todayISO(),
    groupBy: 'month',
    docType: 'invoice',
    movementGroupBy: 'type',
    warehouseId: '',
    categoryId: '',
    productId: '',
    partyType: 'all',
    sort: 'balance_desc',
    search: '',
    asOf: todayISO(),
    days: 90,
    profitGroupBy: 'day',
});

const needsDateRange = computed(() => ['satis', 'kdv', 'stokhareket'].includes(activeTab.value));
const needsWarehouse = computed(() => ['stokdegeri', 'stokhareket'].includes(activeTab.value));
const needsDays = computed(() => ['abc', 'devir'].includes(activeTab.value));

// Sekmeler `applied` nesnesini derin izler; yeni nesne üretildiğinde kendi verisini çeker.
const appliedSales = computed(() => ({
    from: f.from, to: f.to, groupBy: f.groupBy, docType: f.docType,
}));
const appliedProfit = computed(() => ({
    from: f.from, to: f.to, groupBy: f.profitGroupBy,
}));
const appliedTax = computed(() => ({ from: f.from, to: f.to }));
const appliedParties = computed(() => ({
    type: f.partyType, sort: f.sort, search: f.search.trim(), asOf: f.asOf,
}));
const appliedStockValue = computed(() => ({
    warehouseId: f.warehouseId, categoryId: f.categoryId,
}));
const appliedMovements = computed(() => ({
    from: f.from, to: f.to, groupBy: f.movementGroupBy,
    warehouseId: f.warehouseId, productId: f.productId,
}));
const appliedReplenishment = computed(() => ({}));
const appliedAbc = computed(() => ({ days: f.days }));
const appliedTurnover = computed(() => ({ days: f.days }));

const warehouses = ref([]);
const categories = ref([]);
const products = ref([]);
const globalError = ref('');

let errorTimer = null;
function onTabError(msg) {
    globalError.value = msg || t('Rapor yüklenemedi.');
    clearTimeout(errorTimer);
    errorTimer = setTimeout(() => { globalError.value = ''; }, 6000);
}

function resetFilters() {
    f.from = yearStartISO();
    f.to = todayISO();
    f.groupBy = 'month';
    f.docType = 'invoice';
    f.movementGroupBy = 'type';
    f.warehouseId = '';
    f.categoryId = '';
    f.productId = '';
    f.partyType = 'all';
    f.sort = 'balance_desc';
    f.search = '';
    f.asOf = todayISO();
    f.days = 90;
    f.profitGroupBy = 'day';
}

onMounted(async () => {
    try {
        const [w, c, p] = await Promise.all([
            client.get('/warehouses'),
            client.get('/categories'),
            client.get('/products', { params: { per_page: 100 } }),
        ]);
        warehouses.value = w.data?.warehouses || [];
        categories.value = c.data?.categories || [];
        products.value = p.data?.data || [];
    } catch (err) {
        onTabError(err.response?.data?.message || t('Seçim listeleri yüklenemedi.'));
    }
});
</script>

<!-- Yazdırma (tüm sekmeler): .rpt-print-area dışındaki her şey gizlenir. Kural
     sayfada durur çünkü sekme bileşenleri koşullu mount edilir; hangi sekme
     açık olursa çalışsın. Yeni CSS dosyası yok (rpt- önekli). Koyu tema kâğıtta
     okunmaz → basarken alan beyaza çevrilir. -->
<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    .rpt-print-area,
    .rpt-print-area * {
        visibility: visible !important;
    }
    .rpt-print-area {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        background: #fff !important;
        color: #000 !important;
    }
    .rpt-print-area th,
    .rpt-print-area td {
        color: #000 !important;
        border-color: #bbb !important;
    }
    .rpt-print-area table {
        min-width: 0 !important;
        width: 100% !important;
        font-size: 11px !important;
    }
}
</style>
