<template>
    <div class="space-y-6 select-none max-w-7xl mx-auto pb-12">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Satış & Siparişler') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                        {{ $t('Satış & Siparişler') }}
                    </h1>
                    <span v-if="loadingKpi" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                    <template v-else>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#B9FF66]">
                                <TrendingUp class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Toplam satış') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatMoney(kpi.gross_total) }}</span>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#8FE1AF]">
                                <PiggyBank class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Kâr') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatMoney(kpi.profit) }}</span>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#8CD5F4]">
                                <Percent class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('KDV') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatMoney(kpi.tax_total) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
                <p class="text-xs text-[#94A3B8] mt-2">{{ $t('Satış teklifleri, siparişler ve faturalar burada yönetilir.') }}</p>
            </div>
            <div class="flex items-center gap-3">
                <PillButton variant="primary" size="md" @click="openCreate">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Yeni Belge') }}</span>
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
            <div class="flex flex-col xl:flex-row xl:items-center gap-3 pb-4 border-b border-white/[0.08] mb-2">
                <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#131722] border border-white/10 w-fit">
                    <button
                        v-for="tab in typeTabs"
                        :key="tab.value"
                        type="button"
                        @click="setType(tab.value)"
                        class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                        :class="docType === tab.value
                            ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                            : 'text-[#94A3B8] hover:text-white'"
                    >
                        {{ $t(tab.key) }}
                    </button>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 xl:ml-auto">
                    <select v-model="statusFilter" @change="resetPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                        <option value="">{{ $t('Tümü') }}</option>
                        <option value="draft">{{ $t('Taslak') }}</option>
                        <!-- Karar 21/B: teklif/sipariş ara durumları artık kullanıcıya
                             açık; filtre olmasaydı "Gönderildi"deki teklifler yalnız
                             Tümü'nde görünürdü. -->
                        <option value="sent">{{ $t('Gönderildi') }}</option>
                        <option value="accepted">{{ $t('Kabul edildi') }}</option>
                        <option value="rejected">{{ $t('Reddedildi') }}</option>
                        <option value="confirmed">{{ $t('Onaylandı') }}</option>
                        <option value="issued">{{ $t('İhraç') }}</option>
                        <option value="cancelled">{{ $t('İptal') }}</option>
                    </select>
                    <input v-model="fromDate" @change="resetPageAndFetch" type="date" :title="$t('Başlangıç')" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                    <input v-model="toDate" @change="resetPageAndFetch" type="date" :title="$t('Bitiş')" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                    <div class="relative sm:w-64">
                        <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[#64748B]" />
                        <input
                            v-model="search"
                            type="text"
                            :placeholder="$t('Belge no, cari veya ürün ile ara...')"
                            class="w-full pl-10 pr-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        />
                    </div>
                </div>
            </div>

            <DocumentTable
                :documents="docs"
                :loading="loading"
                :meta="meta"
                @view="openDetail"
                @edit="openEditFromRow"
                @delete="askDelete"
                @convert="submitConvert"
                @page="goPage"
                @create="openCreate"
                @pdf="downloadPdf"
                @deliveryNote="downloadDelivery"
            />
        </Card>

        <DocumentForm
            :open="showForm"
            :mode="formMode"
            :allow-types="['quote', 'sales_order', 'invoice', 'return']"
            :parties="parties"
            :products="products"
            :warehouses="warehouses"
            :saving="formSaving"
            :errors="formErrors"
            :general-error="formGeneralError"
            :initial="editingDoc"
            party-label-key="Müşteri"
            :show-warehouse="true"
            @close="closeForm"
            @submit="submitForm"
        />

        <DocumentDrawer
            :open="drawerOpen"
            :doc="detail"
            :loading="detailLoading"
            :saving="actionSaving"
            @close="closeDetail"
            @statusAction="changeStatus"
            @refresh="refreshDetail"
            @cancelDoc="changeStatus('cancelled')"
            @convert="submitConvert"
            @duplicate="submitDuplicate"
            @returnDoc="submitReturnDoc"
            @edit="openEditFromDrawer"
            @deleteRequest="askDelete"
            @pdf="downloadPdf"
            @deliveryNote="downloadDelivery"
        />

        <div v-if="deletingDoc" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Belge Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ serialLabel(deletingDoc) }}</strong> {{ $t('kalıcı olarak silinecektir. Kesilmişse stoğu aynaya düşer; bağlı ödeme/iade birlikte silinir (denetim kaydına işlenir).') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingDoc = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="confirmDelete">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import DocumentTable from '../components/documents/DocumentTable.vue';
import DocumentForm from '../components/documents/DocumentForm.vue';
import DocumentDrawer from '../components/documents/DocumentDrawer.vue';
import { convertTargetFor, downloadDocumentPdf, downloadDeliveryNote, calcTotals, documentSerial, documentWithItems, statusSuccessMessage, submitReturn } from '../components/documents/documentUtils';
import client from '../api/client';
import { t } from '../i18n';
import { formatMoney } from '../utils/format';
import {
    Plus, Search, Check, AlertCircle, AlertTriangle,
    TrendingUp, PiggyBank, Percent,
} from 'lucide-vue-next';

const typeTabs = computed(() => [
    { value: 'quote', key: 'Teklif' },
    { value: 'sales_order', key: 'Sipariş' },
    { value: 'invoice', key: 'Fatura' },
    { value: 'return', key: 'İade' },
]);

const docs = ref([]);
const meta = ref(null);
const loading = ref(true);
const page = ref(1);
const docType = ref('quote');
const statusFilter = ref('');
const search = ref('');
const fromDate = ref('');
const toDate = ref('');
let searchTimer = null;
let docsReq = 0;
onUnmounted(() => clearTimeout(searchTimer));

const parties = ref([]);
const products = ref([]);
const warehouses = ref([]);

const kpi = reactive({ gross_total: 0, profit: 0, tax_total: 0 });
const loadingKpi = ref(true);

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
function serialLabel(d) {
    return documentSerial(d);
}

// Canlı önizleme ↔ sunucu totals karşılaştırması. Eşik üstü farkta sunucu
// değerleri esas alınır (çağıran belgeyi sunucu verisiyle açar/tazeler);
// yalnızca tanılama amaçlı boolean döner.
function assertTotalsMatch(items, serverTotals) {
    try {
        const srv = serverTotals?.grand_total ?? serverTotals?.total ?? serverTotals?.grand;
        if (srv === undefined || srv === null) return true;
        const clientGrand = calcTotals(
            (items || []).map((it) => ({
                quantity: it.quantity,
                unit_price: it.unit_price,
                discount_rate: it.discount_rate ?? 0,
                tax_rate: it.tax_rate ?? 0,
            }))
        ).grand;
        return Math.abs(Number(srv) - clientGrand) <= 0.005;
    } catch {
        return true;
    }
}

// PDF indir (blob). Uç JSON değil; hata gövdesi ayrıştırılamazsa genel mesaj.
// Çekmece {doc, lang} gönderir, liste ham belge gönderir — ikisi de kabul edilir.
async function downloadPdf(payload) {
    const doc = payload?.doc ?? payload;
    const lang = payload?.lang ?? 'tr';
    try {
        await downloadDocumentPdf(doc, lang);
    } catch {
        triggerError(t('PDF indirilemedi.'));
    }
}

async function downloadDelivery(payload) {
    const doc = payload?.doc ?? payload;
    const lang = payload?.lang ?? 'tr';
    try {
        await downloadDeliveryNote(doc, lang);
    } catch {
        triggerError(t('İrsaliye indirilemedi.'));
    }
}

async function fetchDocs() {
    const reqId = ++docsReq;
    loading.value = true;
    try {
        const params = { type: docType.value, page: page.value, per_page: 15 };
        if (statusFilter.value) params.status = statusFilter.value;
        if (search.value.trim()) params.search = search.value.trim();
        if (fromDate.value) params.from = fromDate.value;
        if (toDate.value) params.to = toDate.value;
        const res = await client.get('/documents', { params });
        if (reqId !== docsReq) return;
        docs.value = res.data?.data || [];
        meta.value = res.data?.meta || null;
    } catch (err) {
        if (reqId !== docsReq) return;
        triggerError(err.response?.data?.message || t('Belge listesi yüklenemedi.'));
    } finally {
        if (reqId === docsReq) loading.value = false;
    }
}
function resetPageAndFetch() {
    page.value = 1;
    fetchDocs();
}
function setType(v) {
    docType.value = v;
    page.value = 1;
    fetchDocs();
}
function goPage(p) {
    page.value = p;
    fetchDocs();
}
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        fetchDocs();
    }, 300);
});

async function fetchKpi() {
    loadingKpi.value = true;
    try {
        const year = new Date().getFullYear();
        const res = await client.get('/reports/sales-summary', {
            params: { from: `${year}-01-01`, to: `${year}-12-31`, group_by: 'month' },
        });
        const totals = res.data?.totals || {};
        kpi.gross_total = totals.gross_total ?? 0;
        kpi.profit = totals.profit ?? 0;
        kpi.tax_total = totals.tax_total ?? 0;
    } catch {
        // KPI kritik değil; sessiz geç
    } finally {
        loadingKpi.value = false;
    }
}

async function fetchLookups() {
    try {
        const [partyRes, prodRes, whRes] = await Promise.allSettled([
            client.get('/parties', { params: { type: 'customer', per_page: 100 } }),
            client.get('/products', { params: { per_page: 100 } }),
            client.get('/warehouses'),
        ]);
        if (partyRes.status === 'fulfilled') parties.value = partyRes.value.data?.data || [];
        if (prodRes.status === 'fulfilled') products.value = prodRes.value.data?.data || [];
        if (whRes.status === 'fulfilled') whRes.value && (warehouses.value = whRes.value.data?.data || whRes.value.data?.warehouses || []);
    } catch { /* liste yine de çalışır */ }
}

// --- Yeni / düzenle ---
const showForm = ref(false);
const formMode = ref('create');
const editingDoc = ref(null);
const formSaving = ref(false);
const formErrors = ref({});
const formGeneralError = ref('');

function openCreate() {
    formMode.value = 'create';
    editingDoc.value = null;
    formErrors.value = {};
    formGeneralError.value = '';
    showForm.value = true;
}
function openEditFromRow(row) {
    openDetail(row.id, true);
}
function openEditFromDrawer(doc) {
    formMode.value = 'edit';
    editingDoc.value = doc;
    formErrors.value = {};
    formGeneralError.value = '';
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
    editingDoc.value = null;
}
async function submitForm({ payload, clientError }) {
    if (clientError) {
        formGeneralError.value = clientError;
        return;
    }
    formSaving.value = true;
    formErrors.value = {};
    formGeneralError.value = '';
    try {
        let savedId = null;
        let serverTotals = null;
        if (formMode.value === 'edit' && editingDoc.value) {
            const res = await client.patch(`/documents/${editingDoc.value.id}`, payload);
            savedId = res.data?.document?.id ?? res.data?.data?.id ?? editingDoc.value.id;
            serverTotals = res.data?.totals || res.data?.document?.totals || null;
            triggerSuccess(t('Belge güncellendi.'));
        } else {
            const res = await client.post('/documents', payload);
            const created = res.data?.document || res.data?.data;
            savedId = created?.id ?? null;
            serverTotals = res.data?.totals || created?.totals || null;
            triggerSuccess(t('Belge başarıyla oluşturuldu.'));
        }
        // Canlı önizleme ↔ sunucu totals karşılaştırması (sunucu formülüyle birebir);
        // fark ne olursa olsun sunucu değerleri esas alınır: belge çekmecede sunucu
        // totals ile açılır/tazelenir.
        assertTotalsMatch(payload.items, serverTotals);
        const keepDrawer = formMode.value === 'edit' && drawerOpen.value;
        closeForm();
        if (formMode.value === 'create') {
            closeDetail();
            fetchDocs();
            fetchKpi();
            if (savedId) openDetail(savedId);
        } else {
            if (keepDrawer) refreshDetail();
            fetchDocs();
            fetchKpi();
        }
    } catch (err) {
        if (err.response?.status === 422) {
            formErrors.value = err.response.data?.errors || {};
            formGeneralError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            formGeneralError.value = err.response?.data?.message || t('Belge oluşturulamadı.');
        }
    } finally {
        formSaving.value = false;
    }
}

// --- Detay ---
const drawerOpen = ref(false);
const detailLoading = ref(false);
const detail = ref(null);
const actionSaving = ref(false);

async function openDetail(id, startEditing = false) {
    drawerOpen.value = true;
    detailLoading.value = true;
    detail.value = null;
    try {
        const res = await client.get(`/documents/${id}`);
        detail.value = documentWithItems(res.data);
        if (startEditing) openEditFromDrawer(detail.value);
    } catch (err) {
        triggerError(err.response?.data?.message || t('Belge detayı yüklenemedi.'));
        drawerOpen.value = false;
    } finally {
        detailLoading.value = false;
    }
}
function closeDetail() {
    drawerOpen.value = false;
    detail.value = null;
}
async function refreshDetail() {
    if (!detail.value) return;
    try {
        const res = await client.get(`/documents/${detail.value.id}`);
        detail.value = documentWithItems(res.data);
    } catch { /* liste taze zaten */ }
}

// --- Durum geçişleri (kapalı makine; hatalı geçişte errors.status sunucu mesajı) ---
async function changeStatus(status) {
    if (!detail.value) return;
    actionSaving.value = true;
    try {
        await client.post(`/documents/${detail.value.id}/status`, { status });
        triggerSuccess(t(statusSuccessMessage(status)));
        await Promise.allSettled([refreshDetail(), fetchDocs(), fetchKpi()]);
    } catch (err) {
        const errs = err.response?.data?.errors || {};
        const statusMsg = Array.isArray(errs.status) ? errs.status[0] : errs.status;
        triggerError(statusMsg || err.response?.data?.message || t('Durum güncellenemedi.'));
    } finally {
        actionSaving.value = false;
    }
}

// --- Dönüştürme ---
async function submitDuplicate(payload) {
    const doc = payload?.doc ?? payload;
    if (!doc?.id) return;
    actionSaving.value = true;
    try {
        const res = await client.post(`/documents/${doc.id}/duplicate`);
        triggerSuccess(res.data?.message || t('Belge kopyalandı.'));
        const created = res.data?.document || res.data?.data;
        await fetchDocs();
        fetchKpi();
        if (created?.id) openDetail(created.id);
        else refreshDetail();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Belge kopyalanamadı.'));
    } finally {
        actionSaving.value = false;
    }
}

async function submitConvert(doc) {
    const to = convertTargetFor(doc);
    if (!to) return;
    actionSaving.value = true;
    try {
        const res = await client.post(`/documents/${doc.id}/convert`, { to });
        triggerSuccess(t('Belge dönüştürüldü.'));
        const created = res.data?.document || res.data?.data;
        await fetchDocs();
        fetchKpi();
        if (created?.id) openDetail(created.id);
        else refreshDetail();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Belge dönüştürülemedi.'));
    } finally {
        actionSaving.value = false;
    }
}

// --- İade : faturadan iade belgesi aç ---
async function submitReturnDoc(doc) {
    actionSaving.value = true;
    try {
        const res = await submitReturn(client, doc);
        triggerSuccess(t('İade belgesi oluşturuldu.'));
        const created = res?.document || res?.data;
        await fetchDocs();
        fetchKpi();
        if (created?.id) openDetail(created.id);
        else refreshDetail();
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        triggerError(Object.values(bag).flat()[0] || err.response?.data?.message || t('İade oluşturulamadı.'));
    } finally {
        actionSaving.value = false;
    }
}

// --- Silme (yalnız taslak; sunucu kuralı) ---
const deletingDoc = ref(null);
const deleteSaving = ref(false);
function askDelete(doc) {
    deletingDoc.value = doc;
}
async function confirmDelete() {
    if (!deletingDoc.value) return;
    deleteSaving.value = true;
    try {
        await client.delete(`/documents/${deletingDoc.value.id}`);
        triggerSuccess(t('Belge silindi.'));
        deletingDoc.value = null;
        closeDetail();
        fetchDocs();
        fetchKpi();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Belge silinirken bir hata oluştu.'));
    } finally {
        deleteSaving.value = false;
    }
}

onMounted(() => {
    fetchDocs();
    fetchKpi();
    fetchLookups();

    // Hızlı aramadan derin bağlantı: ?serial=FT-26-00001 → belge çekmecede açılır.
    const seri = typeof route.query.serial === 'string' ? route.query.serial.trim() : '';
    if (seri) {
        search.value = seri;
        fetchDocs().then(() => {
            const first = docs.value?.[0];
            if (first) openDetail(first.id);
        });
    }
});
</script>
