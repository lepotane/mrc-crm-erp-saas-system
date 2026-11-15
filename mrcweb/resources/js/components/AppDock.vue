<template>
    <!-- Desktop Rail (lg and up) -->
    <aside
        class="hidden lg:flex fixed top-0 bottom-0 left-0 w-16 bg-[#131722] border-r border-white/[0.08] flex-col items-center py-4 z-40 select-none"
    >
        <!-- Top Logo -->
        <router-link
            to="/"
            class="flex items-center justify-center mb-6 group focus:outline-none"
            title="MRC Dashboard"
        >
            <BrandMark size="sm" :collapsed="true" />
        </router-link>

        <!-- Primary Nav Items -->
        <nav class="flex-1 flex flex-col items-center gap-1.5 w-full px-2">
            <router-link
                v-for="item in primaryItems"
                :key="item.to"
                :to="item.to"
                :title="$t(item.title)"
                class="relative flex items-center justify-center w-11 h-11 rounded-xl transition-all duration-150 group focus:outline-none"
                :class="[
                    $route.path === item.to || ($route.path.startsWith(item.to) && item.to !== '/')
                        ? 'text-white bg-white/10'
                        : 'text-[#94A3B8] hover:text-white hover:bg-white/5',
                ]"
            >
                <!-- Active Indicator Bar -->
                <span
                    v-if="$route.path === item.to || ($route.path.startsWith(item.to) && item.to !== '/')"
                    class="absolute -left-2 top-2.5 bottom-2.5 w-1 bg-[#B9FF66] rounded-r shadow-[0_0_10px_rgba(185,255,102,0.8)]"
                />
                <component :is="item.icon" class="w-5 h-5 transition-transform group-hover:scale-110" />
            </router-link>
        </nav>

        <!-- Bottom Secondary Items -->
        <div class="flex flex-col items-center gap-1.5 w-full px-2 pt-3 border-t border-white/[0.08]">
            <router-link
                v-for="item in secondaryItems"
                :key="item.to"
                :to="item.to"
                :title="$t(item.title)"
                class="relative flex items-center justify-center w-11 h-11 rounded-xl transition-all duration-150 group focus:outline-none"
                :class="[
                    $route.path === item.to
                        ? 'text-white bg-white/10'
                        : 'text-[#94A3B8] hover:text-white hover:bg-white/5',
                ]"
            >
                <span
                    v-if="$route.path === item.to"
                    class="absolute -left-2 top-2.5 bottom-2.5 w-1 bg-[#B9FF66] rounded-r shadow-[0_0_10px_rgba(185,255,102,0.8)]"
                />
                <component :is="item.icon" class="w-5 h-5 transition-transform group-hover:scale-110" />
            </router-link>
            <!-- Yardım: kılavuzun uygulama içi aynası, her zaman yeni sekmede -->
            <a
                href="/yardim"
                target="_blank"
                rel="noopener"
                :title="$t('Yardım Merkezi')"
                class="relative flex items-center justify-center w-11 h-11 rounded-xl transition-all duration-150 group focus:outline-none text-[#94A3B8] hover:text-white hover:bg-white/5"
            >
                <CircleHelp class="w-5 h-5 transition-transform group-hover:scale-110" />
            </a>
        </div>
    </aside>

    <!-- Mobile Bottom Navigation (below lg) — Dinamik Yetkili Sekmeler (Panel, Satış, POS, Stok, Menü) -->
    <nav
        class="lg:hidden fixed bottom-0 left-0 right-0 h-16 bg-[#131722]/95 backdrop-blur-md border-t border-white/[0.08] flex items-center justify-around px-2 z-40 select-none"
    >
        <!-- 1. Panel (Dashboard) -->
        <router-link
            to="/"
            class="relative flex flex-col items-center justify-center py-1 px-2 transition-colors focus:outline-none"
            :class="[
                $route.path === '/'
                    ? 'text-[#B9FF66]'
                    : 'text-[#94A3B8] hover:text-white'
            ]"
        >
            <LayoutDashboard class="w-5 h-5" />
            <span class="text-[10px] mt-1 font-semibold truncate">{{ $t('Panel') }}</span>
            <span
                v-if="$route.path === '/'"
                class="absolute -top-1 w-6 h-0.5 bg-[#B9FF66] rounded-full shadow-[0_0_8px_#B9FF66]"
            />
        </router-link>

        <!-- 2. Satış & Siparişler (Yalnız Belgeler Modülü Açıksa) -->
        <router-link
            v-if="isPathAllowed('/satislar')"
            to="/satislar"
            class="relative flex flex-col items-center justify-center py-1 px-2 transition-colors focus:outline-none"
            :class="[
                $route.path.startsWith('/satislar')
                    ? 'text-[#B9FF66]'
                    : 'text-[#94A3B8] hover:text-white'
            ]"
        >
            <ShoppingCart class="w-5 h-5" />
            <span class="text-[10px] mt-1 font-semibold truncate">{{ $t('Satış') }}</span>
            <span
                v-if="$route.path.startsWith('/satislar')"
                class="absolute -top-1 w-6 h-0.5 bg-[#B9FF66] rounded-full shadow-[0_0_8px_#B9FF66]"
            />
        </router-link>

        <!-- 3. Hızlı Satış (Center Raised Neon Button - Yalnız POS Açıksa) -->
        <div v-if="isPathAllowed('/hizli-satis')" class="flex items-center justify-center -mt-5">
            <router-link
                to="/hizli-satis"
                class="w-12 h-12 rounded-full bg-[#B9FF66] text-[#131722] flex items-center justify-center shadow-[0_0_20px_rgba(185,255,102,0.5)] border-4 border-[#131722] transition-transform active:scale-95 focus:outline-none"
                :title="$t('Hızlı Satış (POS)')"
            >
                <Zap class="w-5 h-5 fill-current" />
            </router-link>
        </div>

        <!-- 4. Stok Yönetimi (Yalnız Stok Açıksa) -->
        <router-link
            v-if="isPathAllowed('/stok')"
            to="/stok"
            class="relative flex flex-col items-center justify-center py-1 px-2 transition-colors focus:outline-none"
            :class="[
                $route.path.startsWith('/stok')
                    ? 'text-[#B9FF66]'
                    : 'text-[#94A3B8] hover:text-white'
            ]"
        >
            <Boxes class="w-5 h-5" />
            <span class="text-[10px] mt-1 font-semibold truncate">{{ $t('Stok') }}</span>
            <span
                v-if="$route.path.startsWith('/stok')"
                class="absolute -top-1 w-6 h-0.5 bg-[#B9FF66] rounded-full shadow-[0_0_8px_#B9FF66]"
            />
        </router-link>

        <!-- 5. Daha Fazla (Menü Çekmecesi Açıcı) -->
        <button
            type="button"
            @click="drawerOpen = !drawerOpen"
            class="relative flex flex-col items-center justify-center py-1 px-2 transition-colors focus:outline-none cursor-pointer"
            :class="drawerOpen ? 'text-[#B9FF66]' : 'text-[#94A3B8] hover:text-white'"
        >
            <Menu class="w-5 h-5" />
            <span class="text-[10px] mt-1 font-semibold truncate">{{ $t('Menü') }}</span>
        </button>
    </nav>

    <!-- Mobile Slide-Up Drawer ("Daha Fazla" Menüsü) -->
    <div
        v-if="drawerOpen"
        class="lg:hidden fixed inset-0 z-50 flex flex-col justify-end select-none"
    >
        <!-- Arka Plan Karartma -->
        <div
            @click="drawerOpen = false"
            class="fixed inset-0 bg-black/75 backdrop-blur-xs transition-opacity"
        />

        <!-- Alt Çekmece Paneli -->
        <div
            class="relative w-full bg-[#191E2C] border-t border-white/10 rounded-t-[32px] p-5 pb-8 shadow-[0_-10px_40px_rgba(0,0,0,0.8)] z-10 max-h-[82vh] overflow-y-auto"
        >
            <!-- Tutamaç Çizgisi -->
            <div class="w-12 h-1 bg-white/20 rounded-full mx-auto mb-4 cursor-grab" @click="drawerOpen = false" />

            <div class="flex items-center justify-between mb-5">
                <div>
                    <h3 class="text-base font-black text-white tracking-tight">{{ $t('Tüm Modüller & Menü') }}</h3>
                    <p class="text-xs text-[#94A3B8]">{{ $t('İşlemlerinize hızlıca erişin') }}</p>
                </div>
                <button
                    type="button"
                    @click="drawerOpen = false"
                    class="p-2 rounded-full bg-white/5 text-[#94A3B8] hover:text-white cursor-pointer"
                >
                    <X class="w-4 h-4" />
                </button>
            </div>

            <!-- Modüller Izgarası -->
            <div class="grid grid-cols-2 gap-3 mb-6">
                <router-link
                    v-for="mod in drawerModules"
                    :key="mod.to"
                    :to="mod.to"
                    @click="drawerOpen = false"
                    class="flex items-center gap-3 p-3.5 rounded-2xl bg-[#212738] border border-white/[0.08] hover:border-[#B9FF66]/40 hover:bg-[#282F44] transition-all cursor-pointer group"
                >
                    <div
                        class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                        :style="{ backgroundColor: mod.bg || 'rgba(255,255,255,0.06)' }"
                    >
                        <component :is="mod.icon" class="w-5 h-5 text-white" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs font-bold text-white group-hover:text-[#B9FF66] transition-colors truncate">
                            {{ $t(mod.title) }}
                        </div>
                        <div class="text-[10px] text-[#94A3B8] truncate">{{ $t(mod.desc) }}</div>
                    </div>
                </router-link>
            </div>

            <!-- Alt Yardım & Hızlı İşlem Bağlantıları -->
            <div class="pt-4 border-t border-white/[0.08] flex items-center justify-between">
                <a
                    href="/yardim"
                    target="_blank"
                    rel="noopener"
                    @click="drawerOpen = false"
                    class="flex items-center gap-2 text-xs font-semibold text-[#94A3B8] hover:text-white"
                >
                    <CircleHelp class="w-4 h-4 text-[#B9FF66]" />
                    <span>{{ $t('Yardım Merkezi') }}</span>
                </a>
                <span class="text-[10px] font-mono text-[#64748B]">MRC SaaS v2.9</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { CircleHelp, Menu, X, LayoutDashboard, ShoppingCart, Zap, Boxes } from 'lucide-vue-next';
import BrandMark from './BrandMark.vue';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';
import { PRIMARY_MENU, SECONDARY_MENU, DRAWER_MENU } from '../menu';

const authStore = useAuthStore();
const drawerOpen = ref(false);
const disabledPaths = ref([]);

function isPathAllowed(path) {
    if (disabledPaths.value.includes(path)) return false;
    const allowed = authStore.company?.allowed_modules;
    if (!Array.isArray(allowed)) return true;

    const map = {
        '/': 'pano',
        '/musteriler': 'cariler',
        '/urunler': 'urunler',
        '/stok': 'stok',
        '/satislar': 'belgeler',
        '/alimlar': 'belgeler',
        '/borc-alacak': 'borc-alacak',
        '/hizli-satis': 'pos',
        '/raporlar': 'raporlar',
        '/kullanicilar': 'ekip',
        '/ayarlar': 'ayarlar',
        // Şablon Builder sol menüde yok; yine de belgeler kapalı firmada
        // gizli kalsın diye kapısı burada durur.
        '/sablonlar': 'belgeler',
    };
    const mod = map[path];
    if (!mod || mod === 'pano' || mod === 'ayarlar') return true;
    return allowed.includes(mod);
}

onMounted(async () => {
    try {
        const res = await client.get('/modules');
        const list = res.data?.modules || res.data?.data || [];
        // Yalnızca çekirdek modüller (source !== 'plugin') kapatıldığında menü yolu gizlenir;
        // harici pluginlerin kapalı olması Raporlar veya Kullanıcılar gibi ana menüleri asla gizlemez!
        const kapali = list.filter((m) => !m.enabled && m.source !== 'plugin');
        disabledPaths.value = [...new Set(kapali.flatMap((m) => m.menu || []))];
    } catch {
        disabledPaths.value = [];
    }
});

// Menü tanımları TEK kaynaktan gelir (resources/js/menu.js) — isimler
// dock / çekmece / avatar menüsü arasında dağılmasın.
const ALL_PRIMARY = PRIMARY_MENU;
const ALL_SECONDARY = SECONDARY_MENU;
const ALL_DRAWER = DRAWER_MENU;

const primaryItems = computed(() => ALL_PRIMARY.filter((i) => isPathAllowed(i.to)));
const secondaryItems = computed(() => ALL_SECONDARY.filter((i) => isPathAllowed(i.to)));
const drawerModules = computed(() => ALL_DRAWER.filter((i) => isPathAllowed(i.to)));
</script>
