/*
 * MENÜ TEK KAYNAĞI
 *
 * Neden: Aynı ekran (ör. /kullanicilar) üç farklı yerde üç farklı isimle
 * görünüyordu — dock'ta "Kullanıcılar & Roller", avatar menüsünde "Kullanıcı
 * Sınıfları & Ekip", hızlı işlem menüsünde "Personel & Başvuru Yönetimi".
 * Kullanıcı "menüler dağınık" diyordu; sebep buydu.
 *
 * Burada her rota için TEK isim + ikon tanımlanır; AppDock (sol ray + mobil
 * çekmece), PillNav (üst haps + avatar menüsü) ve hızlı işlem menüsü hepsi
 * buradan beslenir. İsim değişecekse tek yer değişir.
 *
 * Etiketler $t() ile çevrilir → kaynak metin Türkçe, EN karşılığı
 * resources/js/i18n/locales/en.json içinde.
 */
import {
    LayoutDashboard,
    UserRound,
    Package,
    Boxes,
    ShoppingCart,
    ShoppingBag,
    ArrowRightLeft,
    Zap,
    BarChart3,
    CircleUserRound,
    FileText,
    Settings,
    CircleHelp,
    UserCircle,
} from 'lucide-vue-next';

/** Rota → tek isim + ikon. */
export const MENU = {
    panel: { to: '/', title: 'Panel', icon: LayoutDashboard },
    musteriler: { to: '/musteriler', title: 'Müşteriler & Cariler', icon: UserRound },
    urunler: { to: '/urunler', title: 'Ürünler & Hizmetler', icon: Package },
    stok: { to: '/stok', title: 'Stok Yönetimi', icon: Boxes },
    satislar: { to: '/satislar', title: 'Satış & Siparişler', icon: ShoppingCart },
    alimlar: { to: '/alimlar', title: 'Alımlar & Masraflar', icon: ShoppingBag },
    borcAlacak: { to: '/borc-alacak', title: 'Borç & Alacak', icon: ArrowRightLeft },
    hizliSatis: { to: '/hizli-satis', title: 'Hızlı Satış (POS)', icon: Zap },
    raporlar: { to: '/raporlar', title: 'Raporlar & Analiz', icon: BarChart3 },
    kullanicilar: { to: '/kullanicilar', title: 'Kullanıcı Sınıfları & Ekip', icon: CircleUserRound },
    // Şablon & Etiket Builder BİLEREK sol menüde yok: Ayarlar → Yazıcılar
    // sekmesindeki "Tasarımcı" kartından açılıyor (tek giriş noktası).
    sablonlar: { to: '/sablonlar', title: 'Şablon & Etiket Builder', icon: FileText },
    ayarlar: { to: '/ayarlar', title: 'Sistem & Firma Ayarları', icon: Settings },
    yardim: { to: '/yardim', title: 'Yardım Merkezi', icon: CircleHelp },
    profil: { to: '/profil', title: 'Profilim & Hesap Düzenle', icon: UserCircle },
};

/** Sol ray (masaüstü) — ana modüller. */
export const PRIMARY_MENU = [
    MENU.panel,
    MENU.musteriler,
    MENU.urunler,
    MENU.stok,
    MENU.satislar,
    MENU.alimlar,
    MENU.borcAlacak,
    MENU.hizliSatis,
    MENU.raporlar,
];

/**
 * Sol rayın altı (masaüstü) + avatar menüsünün ortak kısmı.
 * "Kullanıcı Sınıfları & Ekip" burada — avatar menüsündeki linkle AYNI isim
 * ve AYNI rota (/kullanicilar). Şablon Builder burada YOK (Ayarlar → Yazıcılar).
 */
export const SECONDARY_MENU = [
    MENU.kullanicilar,
    MENU.ayarlar,
];

/** Avatar (sağ üst) menüsü — profil + ikincil menüler. */
export const USER_MENU = [
    MENU.profil,
    MENU.kullanicilar,
    MENU.sablonlar,
    MENU.ayarlar,
];

/** Mobil çekmece — tüm modüller (açıklama + renk ile). */
export const DRAWER_MENU = [
    { ...MENU.musteriler, desc: 'Cari hesaplar ve bakiye', bg: '#3D55B6' },
    { ...MENU.urunler, desc: 'Katalog ve fiyat listeleri', bg: '#459BBE' },
    { ...MENU.stok, desc: 'Depolar ve stok hareketleri', bg: '#4E956A' },
    { ...MENU.satislar, desc: 'Faturalar, sipariş takibi', bg: '#DC6F31' },
    { ...MENU.alimlar, desc: 'Giderler ve tedarikçi faturaları', bg: '#C55050' },
    { ...MENU.borcAlacak, desc: 'Açık hesaplar ve tahsilat', bg: '#BC5FCF' },
    { ...MENU.hizliSatis, desc: 'Barkodlu hızlı satış', bg: '#DC6F31' },
    { ...MENU.raporlar, desc: 'Kâr-zarar, ciro grafikler', bg: '#3D55B6' },
    { ...MENU.kullanicilar, desc: 'Sınıflar, ekip ve başvurular', bg: '#334155' },
    { ...MENU.ayarlar, desc: 'Firma, döviz ve e-dönüşüm', bg: '#475569' },
];

/** Üst hap nav — sık kullanılan 5 kısayol. */
export const PILL_MENU = [
    MENU.panel,
    MENU.satislar,
    MENU.urunler,
    MENU.musteriler,
    MENU.raporlar,
];
