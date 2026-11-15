<template>
    <!-- Masaüstü (Geniş Ekran): satır içi kutu -->
    <div class="hidden 2xl:block relative" ref="rootEl">
        <Search class="w-3.5 h-3.5 text-[#94A3B8] absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
        <input
            v-model="q"
            type="text"
            :placeholder="$t('Hızlı ara...')"
            @input="onInput"
            @keydown.escape="close"
            @keydown.enter.prevent="goFirst"
            @focus="open = q.trim().length >= 2"
            class="w-44 pl-8 pr-3 py-1.5 text-xs bg-[#212738] border border-white/10 rounded-full text-white placeholder-[#64748B] focus:outline-none focus:ring-1 focus:ring-[#B9FF66]"
        />
        <div
            v-if="open && (loading || searched)"
            class="absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto bg-[#212738] border border-white/10 rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.5)] py-2 z-50 text-sm"
        >
            <template v-if="loading">
                <div class="px-4 py-3 text-xs text-[#94A3B8]">{{ $t('Aranıyor...') }}</div>
            </template>
            <template v-else-if="empty">
                <div class="px-4 py-3 text-xs text-[#94A3B8]">{{ $t('Sonuç bulunamadı.') }}</div>
            </template>
            <template v-else v-for="g in grouped" :key="g.key">
                <div class="px-4 pt-2 pb-1 text-[11px] font-bold text-[#64748B] uppercase tracking-wider">{{ g.title }}</div>
                <button
                    v-for="r in g.rows"
                    :key="g.key + r.id"
                    type="button"
                    @click="pick(g.key, r)"
                    class="w-full text-left px-4 py-2 hover:bg-white/5 transition-colors cursor-pointer"
                >
                    <span class="block text-xs font-bold text-white truncate">{{ rowTitle(g.key, r) }}</span>
                    <span class="block text-[11px] text-[#94A3B8] truncate">{{ rowSub(g.key, r) }}</span>
                </button>
            </template>
        </div>
    </div>

    <!-- İkon butonu → açılır arama paneli -->
    <div class="2xl:hidden" ref="rootElMobile">
        <button
            type="button"
            @click="mobileOpen = !mobileOpen; if (mobileOpen) nextTick(() => mobileInput?.focus());"
            class="relative w-11 h-11 lg:w-10 lg:h-10 rounded-full bg-[#212738] border border-white/[0.08] text-[#94A3B8] hover:text-white hover:border-white/20 flex items-center justify-center transition-colors cursor-pointer"
            :title="$t('Hızlı ara (Cari, Fatura, Ürün)...')"
        >
            <Search class="w-4 h-4" />
            <span class="lg:hidden absolute top-2.5 right-2.5 w-2 h-2 rounded-full bg-[#FF5A5F] shadow-[0_0_8px_#FF5A5F]" />
        </button>
    </div>
    <div v-if="mobileOpen" class="2xl:hidden fixed top-16 left-0 right-0 z-40 px-4 max-w-xl ml-auto">
        <div class="rounded-2xl bg-[#212738] border border-white/10 shadow-[0_10px_30px_rgba(0,0,0,0.5)] p-3">
            <input
                ref="mobileInput"
                v-model="q"
                type="text"
                :placeholder="$t('Hızlı ara (Cari, Fatura, Ürün)...')"
                @input="onInput"
                @keydown.escape="mobileOpen = false"
                @keydown.enter.prevent="goFirst"
                class="w-full px-4 py-2.5 text-sm bg-[#131722] border border-white/10 rounded-xl text-white placeholder-[#64748B] focus:outline-none focus:ring-1 focus:ring-[#B9FF66]"
            />
            <div v-if="loading" class="px-1 py-3 text-xs text-[#94A3B8]">{{ $t('Aranıyor...') }}</div>
            <div v-else-if="searched && empty" class="px-1 py-3 text-xs text-[#94A3B8]">{{ $t('Sonuç bulunamadı.') }}</div>
            <template v-else v-for="g in mobileGroups" :key="g.key">
                <div class="px-1 pt-2 pb-1 text-[11px] font-bold text-[#64748B] uppercase tracking-wider">{{ g.title }}</div>
                <button
                    v-for="r in g.rows"
                    :key="g.key + r.id"
                    type="button"
                    @click="pick(g.key, r)"
                    class="w-full text-left px-2 py-2 rounded-xl hover:bg-white/5 transition-colors cursor-pointer"
                >
                    <span class="block text-xs font-bold text-white truncate">{{ rowTitle(g.key, r) }}</span>
                    <span class="block text-[11px] text-[#94A3B8] truncate">{{ rowSub(g.key, r) }}</span>
                </button>
            </template>
        </div>
        <div class="fixed inset-0 -z-10" @click="mobileOpen = false"></div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { Search } from 'lucide-vue-next';
import client from '../api/client';
import { t } from '../i18n';

const router = useRouter();
const q = ref('');
const open = ref(false);
const mobileOpen = ref(false);
const loading = ref(false);
const searched = ref(false);
const mobileInput = ref(null);
const rootEl = ref(null);
const rootElMobile = ref(null);
let reqId = 0;
let timer = null;

const res = ref({ parties: [], documents: [], products: [] });
const empty = computed(() => !res.value.parties.length && !res.value.documents.length && !res.value.products.length);

function groups() {
    return [
        { key: 'parties', title: t('Cariler'), rows: res.value.parties },
        { key: 'documents', title: t('Belgeler'), rows: res.value.documents },
        { key: 'products', title: t('Ürünler'), rows: res.value.products },
    ].filter((g) => g.rows.length);
}
const grouped = computed(() => (open.value ? groups() : []));
const mobileGroups = computed(() => (mobileOpen.value ? groups() : []));

function rowTitle(kind, r) {
    if (kind === 'parties') return r.name;
    if (kind === 'documents') return r.serial;
    return r.name;
}

function rowSub(kind, r) {
    if (kind === 'parties') return [r.code, r.type].filter(Boolean).join(' · ');
    if (kind === 'documents') return [r.party_name, r.status].filter(Boolean).join(' · ');
    return [r.sku, r.sale_price != null ? r.sale_price + ' ' + (r.currency_code || '') : ''].filter(Boolean).join(' · ');
}

function onInput() {
    clearTimeout(timer);
    open.value = true;
    if (q.value.trim().length < 2) {
        res.value = { parties: [], documents: [], products: [] };
        searched.value = false;
        loading.value = false;
        return;
    }
    loading.value = true;
    searched.value = false;
    timer = setTimeout(fetchResults, 300);
}

async function fetchResults() {
    const id = ++reqId;
    try {
        const r = await client.get('/search', { params: { q: q.value.trim() } });
        if (id !== reqId) return;
        res.value = r.data || { parties: [], documents: [], products: [] };
        searched.value = true;
    } catch {
        if (id !== reqId) return;
        res.value = { parties: [], documents: [], products: [] };
        searched.value = true;
    } finally {
        if (id === reqId) loading.value = false;
    }
}

function targetOf(kind, row) {
    if (kind === 'parties') return { path: '/borc-alacak', query: { party: row.id } };
    if (kind === 'documents') {
        return row.type === 'purchase'
            ? { path: '/alimlar', query: { serial: row.serial } }
            : { path: '/satislar', query: { serial: row.serial } };
    }
    return { path: '/urunler', query: { search: row.name } };
}

function pick(kind, row) {
    const to = targetOf(kind, row);
    close();
    mobileOpen.value = false;
    q.value = '';
    router.push(to);
}

function goFirst() {
    const list = open.value ? grouped.value : mobileGroups.value;
    const g = list[0];
    if (g && g.rows[0]) pick(g.key, g.rows[0]);
}

function close() {
    open.value = false;
}

function onDocClick(e) {
    if (rootEl.value && !rootEl.value.contains(e.target)) open.value = false;
}

onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => {
    document.removeEventListener('click', onDocClick);
    clearTimeout(timer);
});
</script>
