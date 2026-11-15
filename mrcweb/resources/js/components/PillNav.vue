<template>
    <header class="h-18 lg:h-16 px-4 sm:px-6 lg:px-8 border-b border-white/[0.06] bg-[#191E2C]/95 backdrop-blur-md flex items-center justify-between gap-3 sticky top-0 z-30 select-none transition-colors duration-300">
        <!-- SOL: Mobil Logo (Tıklanınca Dashboard'a gider) + Sadece Büyük Ekranda (lg+) Üst Hap Navigasyon -->
        <div class="flex items-center gap-3 min-w-0">
            <!-- Mobil & Küçük Ekran Logo (Paylaşılan Mobil Görseldeki Sol Üst İkon) -->
            <router-link
                to="/"
                class="lg:hidden shrink-0 flex items-center focus:outline-none"
                :title="$t('Dashboard')"
            >
                <BrandMark size="md" :collapsed="true" />
            </router-link>

            <!-- Üst Yatay Hap Menü — YALNIZCA BÜYÜK EKRANDA (lg ve üzeri; küçük ekranda gizlenir) -->
            <nav class="hidden lg:flex items-center gap-2 overflow-x-auto no-scrollbar py-1">
                <router-link
                    v-for="item in navPills"
                    :key="item.to"
                    :to="item.to"
                    class="px-4 py-2 rounded-full text-xs font-bold transition-all flex items-center gap-2 shrink-0 whitespace-nowrap focus:outline-none"
                    :class="[
                        isActiveRoute(item.to)
                            ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_18px_rgba(185,255,102,0.35)] keep-white'
                            : 'bg-[#212738]/90 text-[#94A3B8] hover:text-white hover:bg-[#282F44] border border-white/[0.07]'
                    ]"
                >
                    <component
                        :is="item.icon"
                        class="w-3.5 h-3.5 shrink-0"
                        :class="isActiveRoute(item.to) ? 'text-[#131722]' : 'text-[#94A3B8]'"
                    />
                    <span :class="isActiveRoute(item.to) ? 'text-[#131722]' : ''">{{ $t(item.label) }}</span>
                </router-link>

                <!-- Sadece SüperAdmin Oturumunda Görünen Gizli Yönetim Üssü Hapı -->
                <router-link
                    v-if="authStore.user?.is_superadmin"
                    to="/yonetim-ussu"
                    class="px-4 py-2 rounded-full text-xs font-extrabold transition-all flex items-center gap-2 shrink-0 whitespace-nowrap border"
                    :class="[
                        isActiveRoute('/yonetim-ussu')
                            ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_20px_rgba(185,255,102,0.45)] keep-white'
                            : 'bg-[#BC5FCF]/15 text-[#E9D5FF] border-[#BC5FCF]/40 hover:bg-[#BC5FCF]/25'
                    ]"
                >
                    <ShieldCheck class="w-3.5 h-3.5 shrink-0" />
                    <span>{{ $t('SüperAdmin Üssü') }}</span>
                </router-link>
            </nav>
        </div>

        <!-- SAĞ: Hızlı İşlem (+), Firma Seçici, Arama, Tema, Tam Bildirim Merkezi ve Profil Avatarı -->
        <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">
            <!-- 1. Yeni İşlem Butonu (Anlamlı PlusCircle / Hızlı Operasyon İkonu) -->
            <div class="relative">
                <button
                    type="button"
                    @click="quickMenuOpen = !quickMenuOpen"
                    class="w-11 h-11 lg:w-auto lg:h-10 lg:px-4 rounded-full bg-[#212738] border border-white/[0.08] hover:border-[#B9FF66]/50 text-xs font-bold text-white hover:text-[#B9FF66] flex items-center justify-center gap-2 transition-all cursor-pointer shadow-xs"
                    :title="$t('Yeni İşlem Oluştur')"
                >
                    <PlusCircle class="w-4 h-4 text-[#B9FF66]" />
                    <span class="hidden xl:inline">{{ $t('Yeni İşlem') }}</span>
                </button>

                <div
                    v-if="quickMenuOpen"
                    class="absolute right-0 mt-2 w-60 bg-[#212738] border border-white/10 rounded-2xl shadow-[0_16px_40px_rgba(0,0,0,0.65)] py-2 z-50 text-xs"
                >
                    <div class="px-4 py-1.5 text-[10px] font-bold text-[#94A3B8] uppercase tracking-wider">
                        {{ $t('Hızlı Operasyon & Belge') }}
                    </div>
                    <router-link
                        to="/satislar"
                        @click="quickMenuOpen = false"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-white hover:bg-white/5 transition-colors"
                    >
                        <FileText class="w-4 h-4 text-[#B9FF66]" />
                        <span>{{ $t('Yeni Satış / Teklif / İrsaliye') }}</span>
                    </router-link>
                    <router-link
                        to="/hizli-satis"
                        @click="quickMenuOpen = false"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-white hover:bg-white/5 transition-colors"
                    >
                        <Zap class="w-4 h-4 text-[#DC6F31]" />
                        <span>{{ $t('Hızlı Satış (Barkod POS)') }}</span>
                    </router-link>
                    <router-link
                        to="/alimlar"
                        @click="quickMenuOpen = false"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-white hover:bg-white/5 transition-colors"
                    >
                        <ShoppingBag class="w-4 h-4 text-[#459BBE]" />
                        <span>{{ $t('Yeni Alım Faturası') }}</span>
                    </router-link>
                    <router-link
                        to="/kullanicilar"
                        @click="quickMenuOpen = false"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-white hover:bg-white/5 transition-colors border-t border-white/[0.06] mt-1 pt-2"
                    >
                        <Users class="w-4 h-4 text-[#BC5FCF]" />
                        <span>{{ $t('Kullanıcı Sınıfları & Ekip') }}</span>
                    </router-link>
                </div>
                <div v-if="quickMenuOpen" @click="quickMenuOpen = false" class="fixed inset-0 z-40" />
            </div>

            <!-- 2. Firma Seçici -->
            <div class="hidden md:block relative">
                <button
                    type="button"
                    @click="companyMenuOpen = !companyMenuOpen"
                    class="inline-flex items-center gap-1.5 h-10 px-3.5 rounded-full bg-[#212738] border border-white/[0.08] hover:border-white/20 text-xs font-medium text-white transition-colors cursor-pointer focus:outline-none"
                >
                    <Building2 class="w-3.5 h-3.5 text-[#B9FF66] shrink-0" />
                    <span class="max-w-[115px] truncate font-semibold">
                        {{ authStore.currentCompanyName }}
                    </span>
                    <ChevronDown class="w-3 h-3 text-[#94A3B8] transition-transform shrink-0" :class="{ 'rotate-180': companyMenuOpen }" />
                </button>

                <div
                    v-if="companyMenuOpen"
                    class="absolute right-0 mt-2 w-64 bg-[#212738] border border-white/10 rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.5)] py-2 z-50 text-sm"
                >
                    <div class="px-3.5 py-1.5 text-[11px] font-semibold text-[#94A3B8] uppercase tracking-wider">
                        {{ $t('Kayıtlı Firmalar') }}
                    </div>
                    <div class="max-h-56 overflow-y-auto">
                        <button
                            v-for="comp in authStore.companies"
                            :key="comp.id"
                            type="button"
                            @click="switchCompany(comp.id)"
                            class="w-full flex items-center justify-between px-3.5 py-2 text-left text-white hover:bg-white/5 transition-colors cursor-pointer"
                        >
                            <span class="truncate" :class="{ 'text-[#B9FF66] font-bold': authStore.company?.id === comp.id }">
                                {{ comp.name }}
                            </span>
                            <Check v-if="authStore.company?.id === comp.id" class="w-4 h-4 text-[#B9FF66] shrink-0" />
                        </button>
                        <div v-if="!authStore.companies || authStore.companies.length === 0" class="px-3.5 py-2 text-xs text-[#94A3B8]">
                            {{ authStore.currentCompanyName }}
                        </div>
                    </div>
                </div>
                <div v-if="companyMenuOpen" @click="companyMenuOpen = false" class="fixed inset-0 z-40" />
            </div>

            <!-- 3. Genel Arama -->
            <GlobalSearch />

            <!-- 4. Güneş / Ay (Açık / Koyu Tema Değiştirici) -->
            <button
                type="button"
                @click="toggleTheme"
                class="w-11 h-11 lg:w-10 lg:h-10 rounded-full bg-[#212738] border border-white/[0.08] text-[#94A3B8] hover:text-[#B9FF66] hover:border-[#B9FF66]/40 flex items-center justify-center transition-all cursor-pointer focus:outline-none"
                :title="isLight ? $t('Gece (Koyu) Moduna Geç') : $t('Gündüz (Açık) Moduna Geç')"
            >
                <Sun v-if="!isLight" class="w-4 h-4 text-[#FBBF24] transition-transform duration-300 hover:rotate-45" />
                <Moon v-else class="w-4 h-4 text-[#3D55B6] transition-transform duration-300 hover:-rotate-12" />
            </button>

            <!-- 5. Bildirim Merkezi (Okunmamışsa kırmızı nokta yanar, açıldığında/okunduğunda söner, yeni bildirimde tekrar yanar) -->
            <div class="relative">
                <button
                    type="button"
                    @click="toggleNotifMenu"
                    class="relative w-11 h-11 lg:w-10 lg:h-10 rounded-full bg-[#212738] border border-white/[0.08] text-[#94A3B8] hover:text-white hover:border-white/20 flex items-center justify-center transition-colors cursor-pointer focus:outline-none"
                    :title="$t('Bildirimler')"
                >
                    <Bell class="w-4 h-4" :class="notificationStore.unreadCount > 0 ? 'text-[#B9FF66]' : ''" />
                    <!-- Kırmızı Nokta: Yalnızca okunmamış bildirim varsa görünür -->
                    <span
                        v-if="notificationStore.unreadCount > 0"
                        class="absolute top-2 right-2 min-w-[16px] h-4 px-1 rounded-full bg-[#FF5A5F] text-white text-[9px] font-black flex items-center justify-center shadow-[0_0_10px_#FF5A5F] animate-pulse keep-white"
                    >
                        {{ notificationStore.unreadCount > 9 ? '9+' : notificationStore.unreadCount }}
                    </span>
                </button>

                <div
                    v-if="notifMenuOpen"
                    class="absolute right-0 mt-2 w-80 sm:w-96 bg-[#212738] border border-white/10 rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.75)] py-2.5 z-50 text-xs"
                >
                    <div class="px-4 py-2 border-b border-white/[0.08] flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-extrabold text-white text-sm">{{ $t('Bildirim Merkezi') }}</span>
                            <span
                                v-if="notificationStore.unreadCount > 0"
                                class="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#FF5A5F]/20 text-[#FF5A5F]"
                            >
                                {{ notificationStore.unreadCount }} {{ $t('Yeni') }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="notificationStore.items.length > 0"
                                type="button"
                                @click="notificationStore.markAllRead()"
                                class="text-[11px] font-semibold text-[#B9FF66] hover:underline cursor-pointer"
                            >
                                {{ $t('Tümünü Okundu Yap') }}
                            </button>
                            <button
                                v-if="notificationStore.items.length > 0"
                                type="button"
                                @click="notificationStore.clearAll()"
                                class="text-[11px] text-[#94A3B8] hover:text-[#FF9E9E] cursor-pointer"
                                :title="$t('Tümünü Sil')"
                            >
                                {{ $t('Temizle') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="notificationStore.items.length === 0" class="px-4 py-8 text-center text-[#94A3B8]">
                        <Bell class="w-7 h-7 mx-auto mb-2 opacity-30" />
                        <p class="font-semibold">{{ $t('Hiç bildiriminiz yok') }}</p>
                        <p class="text-[11px] opacity-75 mt-0.5">{{ $t('Yeni başvurular ve uyarılar burada görünür.') }}</p>
                    </div>

                    <div v-else class="divide-y divide-white/[0.06] max-h-80 overflow-y-auto">
                        <div
                            v-for="item in notificationStore.items"
                            :key="item.id"
                            class="flex items-start gap-3 px-4 py-3 hover:bg-white/[0.04] transition-colors group"
                            :class="!item.read ? 'bg-[#B9FF66]/[0.04]' : 'opacity-75'"
                        >
                            <span
                                class="w-2.5 h-2.5 rounded-full mt-1.5 shrink-0"
                                :style="{ backgroundColor: !item.read ? (item.color || '#B9FF66') : '#64748B' }"
                            />
                            <div
                                class="flex-1 min-w-0 cursor-pointer"
                                @click="handleNotificationClick(item)"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-bold text-white truncate">{{ item.title }}</p>
                                    <span v-if="!item.read" class="text-[9px] font-black uppercase text-[#B9FF66] shrink-0">
                                        {{ $t('Yeni') }}
                                    </span>
                                </div>
                                <p v-if="item.body" class="text-[11px] text-[#94A3B8] mt-0.5 leading-relaxed">{{ item.body }}</p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button
                                    v-if="!item.read"
                                    type="button"
                                    @click.stop="notificationStore.markRead(item.id)"
                                    class="p-1.5 rounded-lg text-[#94A3B8] hover:text-[#B9FF66] hover:bg-white/5 transition-colors cursor-pointer"
                                    :title="$t('Okundu İşaretle')"
                                >
                                    <Check class="w-3.5 h-3.5" />
                                </button>
                                <button
                                    type="button"
                                    @click.stop="notificationStore.removeNotification(item.id)"
                                    class="p-1.5 rounded-lg text-[#94A3B8] hover:text-[#FF5A5F] hover:bg-white/5 transition-colors cursor-pointer"
                                    :title="$t('Bildirimi Sil')"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="notifMenuOpen" @click="notifMenuOpen = false" class="fixed inset-0 z-40" />
            </div>

            <!-- 6. Kullanıcı Avatarı & Profil Menüsü -->
            <div class="relative">
                <button
                    type="button"
                    @click="userMenuOpen = !userMenuOpen"
                    class="flex items-center gap-2 p-1 2xl:pr-3 rounded-full bg-[#212738] border border-white/10 hover:border-[#B9FF66]/50 transition-all cursor-pointer focus:outline-none"
                >
                    <div class="relative">
                        <div class="w-9 h-9 lg:w-8 lg:h-8 rounded-full bg-gradient-to-br from-[#B9FF66] to-[#4E956A] text-[#131722] font-extrabold text-xs flex items-center justify-center shadow-xs keep-white">
                            {{ authStore.userInitials }}
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-[#B9FF66] border-2 border-[#212738]" />
                    </div>
                    <span class="hidden 2xl:inline text-xs font-semibold text-white max-w-[100px] truncate">
                        {{ authStore.userName }}
                    </span>
                    <ChevronDown class="hidden 2xl:inline w-3 h-3 text-[#94A3B8]" />
                </button>

                <!-- Kullanıcı Açılır Menüsü -->
                <div
                    v-if="userMenuOpen"
                    class="absolute right-0 mt-2 w-60 bg-[#212738] border border-white/10 rounded-2xl shadow-[0_16px_40px_rgba(0,0,0,0.65)] py-2 z-50 text-sm"
                >
                    <div class="px-4 py-2.5 border-b border-white/[0.08]">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-bold text-white truncate">{{ authStore.userName }}</p>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#B9FF66]/15 text-[#B9FF66]">
                                {{ userClassBadge }}
                            </span>
                        </div>
                        <p class="text-[11px] text-[#94A3B8] truncate mt-0.5">{{ authStore.userEmail }}</p>
                    </div>

                    <router-link
                        v-if="authStore.user?.is_superadmin"
                        to="/yonetim-ussu"
                        @click="userMenuOpen = false"
                        class="flex items-center gap-2.5 px-4 py-2 text-xs font-extrabold text-[#B9FF66] bg-[#B9FF66]/10 hover:bg-[#B9FF66]/20 transition-colors"
                    >
                        <ShieldCheck class="w-4 h-4 text-[#B9FF66]" />
                        <span>{{ $t('SüperAdmin Yönetim Üssü') }}</span>
                    </router-link>

                    <!-- Kullanıcı menüsü: TEK kaynaktan (menu.js) — isimler dock ile aynı. -->
                    <router-link
                        v-for="item in userMenuItems"
                        :key="item.to"
                        :to="item.to"
                        @click="userMenuOpen = false"
                        class="flex items-center gap-2.5 px-4 py-2 text-xs font-semibold text-white hover:bg-white/5 transition-colors"
                    >
                        <component :is="item.icon" class="w-4 h-4 text-[#B9FF66]" />
                        <span>{{ $t(item.title) }}</span>
                    </router-link>

                    <div class="border-t border-white/[0.08] my-1 pt-1">
                        <button
                            type="button"
                            @click="handleLogout"
                            class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-[#FF9E9E] hover:bg-[#C55050]/15 transition-colors text-left cursor-pointer"
                        >
                            <LogOut class="w-3.5 h-3.5" />
                            <span>{{ $t('Güvenli Çıkış') }}</span>
                        </button>
                    </div>
                </div>

                <div
                    v-if="userMenuOpen"
                    @click="userMenuOpen = false"
                    class="fixed inset-0 z-40"
                />
            </div>
        </div>
    </header>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useNotificationStore } from '../stores/notifications';
import { useTheme } from '../composables/useTheme';
import {
    ChevronDown,
    Check,
    Bell,
    LogOut,
    LayoutGrid,
    FileText,
    Package,
    Users,
    BarChart3,
    Zap,
    ShoppingBag,
    Building2,
    PlusCircle,
    Sun,
    Moon,
    Trash2,
    ShieldCheck,
} from 'lucide-vue-next';
import BrandMark from './BrandMark.vue';
import GlobalSearch from './GlobalSearch.vue';
import { USER_MENU } from '../menu';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const notificationStore = useNotificationStore();
const { isLight, toggleTheme } = useTheme();

const companyMenuOpen = ref(false);
const userMenuOpen = ref(false);
const quickMenuOpen = ref(false);
const notifMenuOpen = ref(false);

const userClassBadge = computed(() => {
    const u = authStore.user;
    if (u?.is_superadmin) return 'SüperAdmin';
    if (u?.user_class) return u.user_class;
    if (u?.roles?.includes('Admin')) return 'Yönetici';
    return 'Personel';
});

const ALL_NAV_PILLS = [
    { to: '/', label: 'Dashboard', icon: LayoutGrid, module: 'pano' },
    { to: '/satislar', label: 'Satışlar', icon: FileText, module: 'belgeler' },
    { to: '/urunler', label: 'Ürünler', icon: Package, module: 'urunler' },
    { to: '/musteriler', label: 'Cariler', icon: Users, module: 'cariler' },
    { to: '/raporlar', label: 'Raporlar', icon: BarChart3, module: 'raporlar' },
];

// Avatar menüsü — menu.js'teki USER_MENU ile AYNI isimler (dock ile birebir).
const userMenuItems = USER_MENU;

const navPills = computed(() => {
    const allowed = authStore.company?.allowed_modules;
    if (!Array.isArray(allowed)) return ALL_NAV_PILLS;
    return ALL_NAV_PILLS.filter((p) => p.module === 'pano' || allowed.includes(p.module));
});

function isActiveRoute(to) {
    if (to === '/') return route.path === '/';
    return route.path.startsWith(to);
}

function toggleNotifMenu() {
    notifMenuOpen.value = !notifMenuOpen.value;
    if (notifMenuOpen.value && notificationStore.unreadCount > 0) {
        // Bildirim menüsü açıldığında tüm okunmamışları okundu işaretleyerek kırmızı noktayı söndür
        notificationStore.markAllRead();
    }
}

function handleNotificationClick(item) {
    notificationStore.markRead(item.id);
    notifMenuOpen.value = false;
    if (item.action_url) {
        router.push(item.action_url);
    }
}

async function switchCompany(companyId) {
    companyMenuOpen.value = false;
    try {
        await authStore.selectCompany(companyId);
        await notificationStore.fetchNotifications({ triggerPopups: false });
    } catch {
        // Fallback
    }
}

async function handleLogout() {
    userMenuOpen.value = false;
    notificationStore.stopPolling();
    await authStore.logout();
    router.push('/login');
}
</script>
