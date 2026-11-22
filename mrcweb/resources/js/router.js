import { createRouter, createWebHistory } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';
const Login = () => import('./pages/Login.vue');
const Register = () => import('./pages/Register.vue');
const Dashboard = () => import('./pages/Dashboard.vue');
const Musteriler = () => import('./pages/Musteriler.vue');
const Urunler = () => import('./pages/Urunler.vue');
const Stok = () => import('./pages/Stok.vue');
const Satislar = () => import('./pages/Satislar.vue');
const Alimlar = () => import('./pages/Alimlar.vue');
const BorcAlacak = () => import('./pages/BorcAlacak.vue');
const HizliSatis = () => import('./pages/HizliSatis.vue');
const Raporlar = () => import('./pages/Raporlar.vue');
const Yardim = () => import('./pages/Yardim.vue');
const Entegrasyon = () => import('./pages/Entegrasyon.vue');
const Sablonlar = () => import('./pages/Sablonlar.vue');
const Ayarlar = () => import('./pages/Ayarlar.vue');
const Kullanicilar = () => import('./pages/Kullanicilar.vue');
const Profil = () => import('./pages/Profil.vue');
const SuperAdmin = () => import('./pages/SuperAdmin.vue');
const SifremiUnuttum = () => import('./pages/SifremiUnuttum.vue');
const SifreSifirla = () => import('./pages/SifreSifirla.vue');
const Dogrulandi = () => import('./pages/Dogrulandi.vue');

const routes = [
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: { guestOnly: true },
    },
    {
        path: '/sifremi-unuttum',
        name: 'sifremi-unuttum',
        component: SifremiUnuttum,
        meta: { guestOnly: true },
    },
    {
        path: '/sifre-sifirla',
        name: 'sifre-sifirla',
        component: SifreSifirla,
        meta: { guestOnly: true },
    },
    {
        path: '/dogrulandi',
        name: 'dogrulandi',
        component: Dogrulandi,
    },
    {
        path: '/register',
        name: 'register',
        component: Register,
        meta: { guestOnly: true },
    },
    {
        path: '/',
        component: AppLayout,
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'dashboard',
                component: Dashboard,
                meta: { title: 'Dashboard' },
            },
            {
                path: 'musteriler',
                name: 'musteriler',
                component: Musteriler,
                meta: { title: 'Müşteriler & Cariler' },
            },
            {
                path: 'urunler',
                name: 'urunler',
                component: Urunler,
                meta: { title: 'Ürünler & Hizmetler' },
            },
            {
                path: 'stok',
                name: 'stok',
                component: Stok,
                meta: { title: 'Stok Yönetimi' },
            },
            {
                path: 'satislar',
                name: 'satislar',
                component: Satislar,
                meta: { title: 'Satış & Siparişler' },
            },
            {
                path: 'alimlar',
                name: 'alimlar',
                component: Alimlar,
                meta: { title: 'Satınalma & Giderler' },
            },
            {
                path: 'borc-alacak',
                name: 'borc-alacak',
                component: BorcAlacak,
                meta: { title: 'Borç Alacak' },
            },
            {
                path: 'hizli-satis',
                name: 'hizli-satis',
                component: HizliSatis,
                meta: { title: 'Hızlı Satış' },
            },
            {
                path: 'raporlar',
                name: 'raporlar',
                component: Raporlar,
                meta: { title: 'Raporlar & Analiz' },
            },
            {
                path: 'yardim',
                name: 'yardim',
                component: Yardim,
                meta: { title: 'Yardım Merkezi' },
            },
            {
                path: 'sablonlar',
                name: 'sablonlar',
                component: Sablonlar,
                meta: { title: 'Yazdırma Şablonları' },
            },
            {
                path: 'entegrasyon',
                name: 'entegrasyon',
                component: Entegrasyon,
                meta: { title: 'Entegrasyon Merkezi' },
            },
            {
                path: 'ayarlar',
                name: 'ayarlar',
                component: Ayarlar,
                meta: { title: 'Sistem Ayarları' },
            },
            {
                path: 'kullanicilar',
                name: 'kullanicilar',
                component: Kullanicilar,
                meta: { title: 'Kullanıcılar & Roller' },
            },
            {
                path: 'profil',
                name: 'profil',
                component: Profil,
                meta: { title: 'Kullanıcı Profili' },
            },
            {
                path: 'yonetim-ussu',
                name: 'yonetim-ussu',
                component: SuperAdmin,
                meta: { title: 'Yönetim Üssü', requiresSuperAdmin: true },
            },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior() {
        return { top: 0 };
    },
});

const ROUTE_MODULE_MAP = {
    musteriler: 'cariler',
    urunler: 'urunler',
    stok: 'stok',
    satislar: 'belgeler',
    alimlar: 'belgeler',
    'borc-alacak': 'borc-alacak',
    'hizli-satis': 'pos',
    raporlar: 'raporlar',
    // Şablon Builder sol menüde yok ama rotası durur; çıktı altyapısı
    // `belgeler` modülüne bağlıdır (print-templates ucu da öyle).
    sablonlar: 'belgeler',
    kullanicilar: 'ekip',
};

router.beforeEach(async (to, from, next) => {
    const token = localStorage.getItem('mrc_token');

    if (to.matched.some((record) => record.meta.requiresAuth)) {
        if (!token) {
            return next({ path: '/login', query: { redirect: to.fullPath } });
        }
    }

    if (to.matched.some((record) => record.meta.guestOnly)) {
        if (token) {
            return next({ path: '/' });
        }
    }

    // Firma Modül Kısıtlama Güvenlik Duvarı
    const modKey = ROUTE_MODULE_MAP[to.name];
    if (modKey) {
        try {
            const { useAuthStore } = await import('./stores/auth');
            const authStore = useAuthStore();
            const allowed = authStore.company?.allowed_modules;
            if (Array.isArray(allowed) && !allowed.includes(modKey)) {
                return next({ path: '/' });
            }
        } catch {
            // ignore
        }
    }

    // SüperAdmin Gizli Sayfa Koruması
    if (to.matched.some((record) => record.meta.requiresSuperAdmin)) {
        try {
            const { useAuthStore } = await import('./stores/auth');
            const authStore = useAuthStore();
            if (!authStore.user?.is_superadmin) {
                return next({ path: '/' });
            }
        } catch {
            return next({ path: '/' });
        }
    }

    next();
});

export default router;
