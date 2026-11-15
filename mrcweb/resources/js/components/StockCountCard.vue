<!-- Stok sayım oturumları : taslak aç, satırları say, onayla.
     Satır eklemede barkod tarayıcı (BarcodeScanner) + manuel arama birlikte.
     Onay YALNIZ Admin (sunucu 403); Personel kartı görür, onay düğmesi reddedilir. -->
<template>
    <Card rounded="2xl" padding="md">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <ClipboardList class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-base font-bold text-white tracking-tight">{{ $t('Stok Sayımları') }}</h2>
                    <p class="text-xs text-[#94A3B8]">{{ $t('Taslak → sayım → onay; farklar düzeltme olur') }}</p>
                </div>
            </div>
            <PillButton variant="ghost" size="sm" @click="showForm = !showForm">{{ $t('Yeni Sayım') }}</PillButton>
        </div>

        <p v-if="error" class="mb-3 text-xs font-semibold text-[#FFA0A0]">{{ error }}</p>
        <p v-if="notice" class="mb-3 text-xs font-semibold text-[#B9FF66]">{{ notice }}</p>

        <!-- Yeni sayım formu -->
        <div v-if="showForm" class="mb-4 rounded-xl bg-black/30 border border-white/10 p-3 space-y-2">
            <select v-model="warehouseId" class="w-full px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm cursor-pointer">
                <option :value="null">{{ $t('Depo seçin') }}</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
            <div class="flex gap-2">
                <input
                    v-model="search"
                    type="text"
                    :placeholder="$t('Ürün ara / barkod')"
                    @input="searchProducts"
                    @keyup.enter="addFirst"
                    class="grow px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                />
                <button
                    type="button"
                    @click="scanOpen = true"
                    class="shrink-0 w-[42px] rounded-xl bg-[#B9FF66] text-[#131722] flex items-center justify-center"
                    :title="$t('Kamerayla tara')"
                >
                    <ScanBarcode class="w-5 h-5" />
                </button>
            </div>
            <div v-if="results.length" class="space-y-1 max-h-40 overflow-y-auto">
                <button
                    v-for="p in results"
                    :key="p.id"
                    type="button"
                    @click="addLine(p)"
                    class="w-full flex items-center justify-between gap-2 p-2 rounded-xl bg-[#131722]/70 border border-white/10 hover:bg-white/[0.04] text-left"
                >
                    <span class="text-sm text-white truncate">{{ p.name }}</span>
                    <span class="text-xs text-[#64748B]">{{ $t('Ekle') }}</span>
                </button>
            </div>
            <div v-if="lines.length" class="space-y-1.5">
                <div v-for="(l, i) in lines" :key="l.product_id" class="flex items-center gap-2 p-2 rounded-xl bg-[#131722]/70 border border-white/10">
                    <span class="grow text-sm text-white truncate">{{ l.name }}</span>
                    <input v-model.number="l.counted_qty" type="number" min="0" step="0.5" class="w-20 px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-sm text-right" />
                    <button type="button" @click="lines.splice(i, 1)" class="text-xs text-[#64748B] hover:text-white px-2">{{ $t('Sil') }}</button>
                </div>
            </div>
            <PillButton size="sm" variant="primary" :loading="saving" :disabled="!warehouseId || !lines.length" @click="createCount">
                {{ $t('Sayımı Başlat') }}
            </PillButton>
        </div>

        <!-- Liste -->
        <div v-if="loading" class="space-y-2">
            <div v-for="i in 3" :key="i" class="h-12 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>
        <div v-else-if="!counts.length" class="py-6 text-center text-sm text-[#64748B]">{{ $t('Henüz sayım yok.') }}</div>
        <div v-else class="space-y-2">
            <div v-for="c in counts" :key="c.id" class="p-3 rounded-xl bg-[#131722]/70 border border-white/10">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-white truncate">#{{ c.id }} · {{ c.warehouse_name }} <span class="text-[11px] font-normal text-[#64748B]">{{ c.lines_count }} {{ $t('kalem') }}</span></p>
                        <p class="text-[11px] text-[#64748B]">{{ c.created_at?.slice(0, 10) }}</p>
                    </div>
                    <StatusPill :color="c.status === 'approved' ? 'lime' : c.status === 'cancelled' ? 'neutral' : 'orange'" :dot="false">
                        {{ c.status === 'approved' ? $t('Onaylandı') : c.status === 'cancelled' ? $t('İptal') : $t('Taslak') }}
                    </StatusPill>
                </div>
                <div class="flex flex-wrap gap-2 mt-2">
                    <button type="button" @click="toggleDetail(c)" class="text-xs text-[#94A3B8] hover:text-white">{{ openId === c.id ? $t('Gizle') : $t('Detay') }}</button>
                    <template v-if="c.status === 'draft'">
                        <button type="button" @click="approve(c)" :disabled="acting" class="text-xs text-[#B9FF66] hover:text-white disabled:opacity-50">{{ $t('Onayla') }}</button>
                        <button type="button" @click="cancel(c)" :disabled="acting" class="text-xs text-[#FFA0A0] hover:text-white disabled:opacity-50">{{ $t('İptal et') }}</button>
                    </template>
                </div>
                <div v-if="openId === c.id && details[c.id]" class="mt-2 space-y-1">
                    <div v-for="l in details[c.id]" :key="l.product_id" class="flex items-center justify-between gap-2 text-xs py-1.5 border-t border-white/[0.06]">
                        <span class="text-white truncate">{{ l.product_name }}</span>
                        <span class="shrink-0 font-mono" :class="l.diff === 0 ? 'text-[#64748B]' : 'text-[#FFA0A0]'">
                            {{ l.expected_qty }} → {{ l.counted_qty }} ({{ l.diff > 0 ? '+' : '' }}{{ l.diff }})
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <BarcodeScanner :open="scanOpen" :auto-add="true" @resolve="onScanned" @close="scanOpen = false" />
    </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ClipboardList, ScanBarcode } from 'lucide-vue-next';
import Card from './ui/Card.vue';
import PillButton from './ui/PillButton.vue';
import StatusPill from './ui/StatusPill.vue';
import BarcodeScanner from './BarcodeScanner.vue';
import client from '../api/client';
import { t } from '../i18n';

const props = defineProps({
    warehouses: { type: Array, default: () => [] },
});

const counts = ref([]);
const details = ref({});
const loading = ref(true);
const error = ref('');
const notice = ref('');
const showForm = ref(false);
const warehouseId = ref(null);
const search = ref('');
const results = ref([]);
const lines = ref([]);
const saving = ref(false);
const acting = ref(false);
const openId = ref(null);
const scanOpen = ref(false);

let searchTimer = null;

async function fetchCounts() {
    loading.value = true;
    try {
        const res = await client.get('/stock-counts', { params: { per_page: 20 } });
        counts.value = res.data?.data || [];
    } catch (err) {
        error.value = err.response?.data?.message || t('Sayımlar yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function searchProducts() {
    clearTimeout(searchTimer);
    const q = search.value.trim();
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
    if (!lines.value.some((l) => l.product_id === p.id)) {
        lines.value.push({ product_id: p.id, name: p.name, counted_qty: 0 });
    }
    search.value = '';
    results.value = [];
}

function addFirst() {
    if (results.value.length) addLine(results.value[0]);
}

function onScanned(p) {
    addLine(p);
}

async function createCount() {
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        await client.post('/stock-counts', {
            warehouse_id: warehouseId.value,
            lines: lines.value.map((l) => ({ product_id: l.product_id, counted_qty: Number(l.counted_qty) || 0 })),
        });
        lines.value = [];
        showForm.value = false;
        notice.value = t('Sayım taslağı oluşturuldu.');
        await fetchCounts();
    } catch (err) {
        error.value = err.response?.data?.message || t('Sayım oluşturulamadı.');
    } finally {
        saving.value = false;
    }
}

async function toggleDetail(c) {
    if (openId.value === c.id) { openId.value = null; return; }
    openId.value = c.id;
    if (details.value[c.id]) return;
    try {
        const res = await client.get(`/stock-counts/${c.id}`);
        details.value[c.id] = res.data?.lines || [];
    } catch (err) {
        error.value = err.response?.data?.message || t('Sayım detayı alınamadı.');
    }
}

async function approve(c) {
    acting.value = true;
    error.value = '';
    notice.value = '';
    try {
        const res = await client.post(`/stock-counts/${c.id}/approve`);
        notice.value = t('Sayım onaylandı.') + ` (${res.data?.adjustments ?? 0})`;
        delete details.value[c.id];
        if (openId.value === c.id) openId.value = null;
        await fetchCounts();
    } catch (err) {
        error.value = err.response?.status === 403
            ? t('Bu işlem için yönetici yetkisi gereklidir.')
            : (err.response?.data?.message || t('Onay yapılamadı.'));
    } finally {
        acting.value = false;
    }
}

async function cancel(c) {
    acting.value = true;
    error.value = '';
    try {
        await client.post(`/stock-counts/${c.id}/cancel`);
        notice.value = t('Sayım iptal edildi.');
        await fetchCounts();
    } catch (err) {
        error.value = err.response?.data?.message || t('İptal yapılamadı.');
    } finally {
        acting.value = false;
    }
}

onMounted(fetchCounts);

defineExpose({ fetchCounts });
</script>
