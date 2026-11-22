<!--
    Entegrasyon Merkezi — Tsoft farkları, içeri aktarma, fiyat senkronu.
    Tsoft'a yazmaz; yalnızca okur ve MRC'ye aktarır.
-->
<template>
    <div class="space-y-5 select-none max-w-6xl mx-auto pb-12">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ L('Panel', 'Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ L('Entegrasyon Merkezi', 'Integration Center') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                    {{ L('Entegrasyon Merkezi', 'Integration Center') }}
                </h1>
                <p class="text-xs text-[#64748B] mt-1">
                    {{ L('Tsoft değişikliklerini görün, seçilenleri MRC’ye aktarın. Tsoft’a yazılmaz.', 'See Tsoft changes, import selected ones into MRC. Never writes to Tsoft.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <PillButton variant="secondary" size="sm" :loading="scanning" @click="scan">
                    <RefreshCw class="w-3.5 h-3.5" /> {{ L('Tsoft’u Tara', 'Scan Tsoft') }}
                </PillButton>
                <PillButton variant="primary" size="sm" :loading="importing" :disabled="!selected.length" @click="importSelected">
                    <Download class="w-3.5 h-3.5" /> {{ L('Seçilenleri İçe Aktar', 'Import Selected') }} ({{ selected.length }})
                </PillButton>
                <PillButton variant="ghost" size="sm" :loading="ignoring" :disabled="!selected.length" @click="ignoreSelected">
                    {{ L('Yok Say', 'Ignore') }}
                </PillButton>
            </div>
        </div>

        <div v-if="message" class="flex items-center gap-2 p-3 rounded-xl text-sm"
             :class="messageOk ? 'bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-[#B9FF66]' : 'bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0]'">
            <AlertCircle v-if="!messageOk" class="w-4 h-4 shrink-0" />
            <Check v-else class="w-4 h-4 shrink-0" />
            <span>{{ message }}</span>
        </div>

        <!-- Özet kartları -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <Card v-for="card in summaryCards" :key="card.key" rounded="xl" padding="md">
                <p class="text-[11px] text-[#94A3B8]">{{ card.label }}</p>
                <p class="text-2xl font-black text-white mt-1">{{ card.value }}</p>
                <p class="text-[10px] text-[#64748B] mt-1">{{ card.hint }}</p>
            </Card>
        </div>

        <!-- Fiyat senkronu -->
        <Card rounded="2xl" padding="lg">
            <div class="flex items-center gap-3 pb-3 border-b border-white/[0.08] mb-4">
                <div class="w-9 h-9 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <ArrowLeftRight class="w-4 h-4" />
                </div>
                <div>
                    <h2 class="text-sm font-bold text-white">{{ L('Fiyat Senkronizasyonu', 'Price Synchronization') }}</h2>
                    <p class="text-[11px] text-[#94A3B8]">
                        {{ L('Tek ürün, seçili ürünler veya tüm ürünler — iki yönlü.', 'Single product, selected products, or all — bidirectional.') }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-[#94A3B8] mb-1">{{ L('Yön', 'Direction') }}</label>
                    <select v-model="priceForm.direction" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm cursor-pointer">
                        <option value="out">{{ L('MRC → Tsoft (güncelle)', 'MRC → Tsoft (update)') }}</option>
                        <option value="in">{{ L('Tsoft → MRC (senkronize et)', 'Tsoft → MRC (sync)') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-[#94A3B8] mb-1">{{ L('Kapsam', 'Scope') }}</label>
                    <select v-model="priceForm.scope" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm cursor-pointer">
                        <option value="sku">{{ L('Tek ürün', 'Single product') }}</option>
                        <option value="selected">{{ L('Seçili ürünler', 'Selected products') }}</option>
                        <option value="all">{{ L('Tüm ürünler', 'All products') }}</option>
                    </select>
                </div>
                <div v-if="priceForm.scope !== 'all'" class="grow min-w-[220px]">
                    <label class="block text-[11px] font-semibold text-[#94A3B8] mb-1">SKU</label>
                    <input v-model="priceForm.skus" type="text" :placeholder="L('Virgülle ayırın: SKU1, SKU2', 'Comma separated: SKU1, SKU2')"
                           class="w-full px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                </div>
                <label class="flex items-center gap-2 text-[11px] text-[#94A3B8] pb-2 cursor-pointer">
                    <input v-model="priceForm.force" type="checkbox" class="accent-[#B9FF66]" />
                    {{ L('Zorla (MRC fiyatını ez)', 'Force (overwrite MRC price)') }}
                </label>
                <PillButton variant="primary" size="sm" :loading="priceSaving" @click="runPriceSync">
                    {{ L('Çalıştır', 'Run') }}
                </PillButton>
            </div>
            <p v-if="priceResult" class="text-[11px] text-[#94A3B8] mt-3 font-mono">{{ priceResult }}</p>
        </Card>

        <!-- Fark listesi -->
        <Card rounded="2xl" padding="lg">
            <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-white/[0.08] mb-3">
                <h2 class="text-sm font-bold text-white">{{ L('Tsoft Farkları', 'Tsoft Differences') }}</h2>
                <div class="flex gap-1.5">
                    <button v-for="f in filters" :key="f.value" type="button" @click="activeFilter = f.value"
                            class="px-3 py-1 rounded-full text-[11px] font-semibold transition-all cursor-pointer"
                            :class="activeFilter === f.value ? 'bg-[#B9FF66] text-[#131722]' : 'bg-[#131722] text-[#94A3B8] border border-white/10 hover:text-white'">
                        {{ f.label }}
                    </button>
                </div>
            </div>

            <div v-if="!diffs.length" class="text-center py-8 text-sm text-[#94A3B8]">
                {{ L('Fark yok. Tsoft’u tarayarak başlayın.', 'No differences. Start by scanning Tsoft.') }}
            </div>
            <div v-else class="space-y-2">
                <label v-for="d in diffs" :key="d.id"
                       class="flex items-start gap-3 p-3 rounded-xl bg-[#131722]/70 border border-white/10 cursor-pointer hover:border-[#B9FF66]/40 transition-all">
                    <input v-model="selected" type="checkbox" :value="d.id" class="mt-1 accent-[#B9FF66]" />
                    <div class="min-w-0 grow">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                  :class="typeClass(d.entity_type)">{{ d.entity_type }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/5 text-[#94A3B8]">{{ d.diff_type }}</span>
                            <span class="text-xs font-semibold text-white truncate">{{ d.external_key }}</span>
                        </div>
                        <p v-if="d.summary" class="text-[11px] text-[#94A3B8] mt-1">{{ d.summary }}</p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[10px] text-[#64748B]">{{ formatDate(d.detected_at) }}</p>
                        <p class="text-[10px] mt-0.5" :class="d.status === 'pending' ? 'text-[#FBBF24]' : 'text-[#4E956A]'">{{ d.status }}</p>
                    </div>
                </label>
            </div>
        </Card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import client from '../api/client';
import { locale, t } from '../i18n';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import { AlertCircle, Check, RefreshCw, Download, ArrowLeftRight } from 'lucide-vue-next';

const L = (tr, en) => (locale.value === 'en' ? en : tr);

const diffs = ref([]);
const summary = ref({});
const selected = ref([]);
const activeFilter = ref('pending');
const scanning = ref(false);
const importing = ref(false);
const ignoring = ref(false);
const priceSaving = ref(false);
const message = ref('');
const messageOk = ref(true);
const priceResult = ref('');

const priceForm = ref({ direction: 'out', scope: 'sku', skus: '', force: false });

const filters = computed(() => [
    { value: 'pending', label: L('Bekleyen', 'Pending') },
    { value: 'imported', label: L('Aktarılan', 'Imported') },
    { value: 'ignored', label: L('Yok Sayılan', 'Ignored') },
    { value: 'all', label: L('Tümü', 'All') },
]);

const summaryCards = computed(() => [
    { key: 'pending', label: L('Bekleyen fark', 'Pending diffs'), value: summary.value.pending ?? 0, hint: L('işlem bekliyor', 'awaiting action') },
    { key: 'product', label: L('Ürün farkı', 'Product diffs'), value: summary.value.product ?? 0, hint: L('yeni/değişen ürün', 'new/changed product') },
    { key: 'price', label: L('Fiyat farkı', 'Price diffs'), value: summary.value.price ?? 0, hint: L('fiyat değişikliği', 'price changes') },
    { key: 'order', label: L('Yeni sipariş', 'New orders'), value: summary.value.order ?? 0, hint: L('MRC’ye aktarılabilir', 'can be imported') },
]);

function notify(text, ok = true) {
    message.value = text;
    messageOk.value = ok;
}

function typeClass(type) {
    return {
        product: 'bg-[#459BBE]/20 text-[#7FD0F0]',
        price: 'bg-[#B9FF66]/15 text-[#B9FF66]',
        stock: 'bg-[#4E956A]/20 text-[#7FDCA0]',
        order: 'bg-[#DC6F31]/20 text-[#F0A06A]',
    }[type] || 'bg-white/5 text-[#94A3B8]';
}

function formatDate(value) {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString(locale.value === 'en' ? 'en-GB' : 'tr-TR');
}

async function load() {
    try {
        const params = { per_page: 100 };
        if (activeFilter.value !== 'all') params.status = activeFilter.value;
        const [list, sum] = await Promise.all([
            client.get('/integrations/diffs', { params }),
            client.get('/integrations/diffs/summary'),
        ]);
        diffs.value = list.data?.data || [];
        summary.value = sum.data?.data || {};
    } catch (err) {
        notify(err.response?.data?.message || t('Liste yüklenemedi.'), false);
    }
}

async function scan() {
    scanning.value = true;
    try {
        const res = await client.post('/integrations/diffs/scan');
        notify(res.data?.message || L('Tsoft tarandı.', 'Tsoft scanned.'));
        await load();
    } catch (err) {
        notify(err.response?.data?.message || L('Tarama başarısız.', 'Scan failed.'), false);
    } finally {
        scanning.value = false;
    }
}

async function importSelected() {
    importing.value = true;
    try {
        const res = await client.post('/integrations/diffs/import', { ids: selected.value });
        notify(res.data?.message || L('Seçilenler aktarıldı.', 'Selected items imported.'));
        selected.value = [];
        await load();
    } catch (err) {
        notify(err.response?.data?.message || L('Aktarım başarısız.', 'Import failed.'), false);
    } finally {
        importing.value = false;
    }
}

async function ignoreSelected() {
    ignoring.value = true;
    try {
        await client.post('/integrations/diffs/ignore', { ids: selected.value });
        notify(L('Farklar yok sayıldı.', 'Differences ignored.'));
        selected.value = [];
        await load();
    } catch (err) {
        notify(err.response?.data?.message || L('İşlem başarısız.', 'Operation failed.'), false);
    } finally {
        ignoring.value = false;
    }
}

async function runPriceSync() {
    priceSaving.value = true;
    try {
        const skus = priceForm.value.skus.split(',').map((s) => s.trim()).filter(Boolean);
        const res = await client.post('/integrations/prices/sync', {
            direction: priceForm.value.direction,
            all: priceForm.value.scope === 'all',
            selected: priceForm.value.scope === 'selected',
            skus,
            force: priceForm.value.force,
        });
        priceResult.value = JSON.stringify(res.data?.data || res.data);
        notify(L('Fiyat senkronu tamamlandı.', 'Price sync completed.'));
    } catch (err) {
        notify(err.response?.data?.message || L('Senkron başarısız.', 'Sync failed.'), false);
    } finally {
        priceSaving.value = false;
    }
}

onMounted(load);
</script>
