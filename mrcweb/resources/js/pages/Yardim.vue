<!--
    MRC Yardım & Kullanım Kılavuzu Merkezi
    İçerik sistem dokümantasyonundan derlenir.
    (`node scripts/help-uret.mjs` → resources/js/help/articles.js).
-->
<template>
    <div
        class="min-h-screen flex flex-col transition-colors duration-300 select-none"
        :class="isLight ? 'bg-[#EEF2F6] text-[#0F172A]' : 'bg-[#191E2C] text-white'"
    >
        <!-- Üst Navigasyon Barı -->
        <header
            class="sticky top-0 z-30 border-b backdrop-blur-md px-4 sm:px-8 py-3.5 flex flex-wrap items-center justify-between gap-3"
            :class="isLight ? 'bg-white/90 border-slate-200' : 'bg-[#131722]/90 border-white/[0.08]'"
        >
            <div class="flex items-center gap-3">
                <router-link
                    to="/"
                    class="w-10 h-10 rounded-full bg-[#B9FF66] text-[#131722] flex items-center justify-center font-black text-base shadow-[0_0_20px_rgba(185,255,102,0.4)] hover:scale-105 transition-transform"
                    :title="$t('Dashboard\'a Git')"
                >
                    M
                </router-link>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base sm:text-lg font-black tracking-tight" :class="isLight ? 'text-slate-900' : 'text-white'">
                            {{ $t('MRC Yardım & Kullanım Kılavuzu') }}
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-[#B9FF66] text-[#131722] uppercase">
                            22 Rehber · 16 Ekran
                        </span>
                    </div>
                    <p class="text-[11px] text-[#94A3B8]">
                        {{ $t('Yeni nesil arayüz, form kurucu, barkod/QR ve tüm modüller için adım adım görsel rehber') }}
                    </p>
                </div>
            </div>

            <!-- Hızlı Bölüm Hapları & Tema / Panele Dön -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="sec in HELP_SECTIONS"
                    :key="sec.id"
                    type="button"
                    @click="ac(ilkBolumMakale(sec.id))"
                    class="px-3.5 py-1.5 rounded-full text-xs font-extrabold transition-all cursor-pointer border"
                    :class="makale?.bolum === sec.id
                        ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_14px_rgba(185,255,102,0.35)]'
                        : isLight
                            ? 'bg-slate-100 text-slate-700 border-slate-200 hover:bg-slate-200'
                            : 'bg-[#212738] text-[#94A3B8] border-white/10 hover:text-white'"
                >
                    {{ bolumAdi(sec) }} ({{ bolumMakaleleri(sec.id).length }})
                </button>

                <button
                    type="button"
                    @click="toggleTheme()"
                    class="w-9 h-9 rounded-full border flex items-center justify-center transition-colors cursor-pointer"
                    :class="isLight ? 'bg-slate-100 border-slate-300 text-amber-600' : 'bg-[#212738] border-white/10 text-[#B9FF66]'"
                    :title="isLight ? $t('Koyu Temaya Geç') : $t('Açık Temaya Geç')"
                >
                    <Sun v-if="!isLight" class="w-4 h-4" />
                    <Moon v-else class="w-4 h-4" />
                </button>

                <router-link
                    to="/"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-extrabold hover:brightness-105 transition-all"
                >
                    <ArrowLeft class="w-3.5 h-3.5" />
                    <span>{{ $t('Panele Dön') }}</span>
                </router-link>
            </div>
        </header>

        <div class="flex-1 flex flex-col lg:flex-row max-w-[110rem] w-full mx-auto">
            <!-- Sol Kenar Çubuğu (Kategoriler & Anlık Arama) -->
            <aside
                class="lg:w-84 shrink-0 border-b lg:border-b-0 lg:border-r p-4 sm:p-5 lg:h-[calc(100vh-4.25rem)] lg:sticky lg:top-[4.25rem] lg:overflow-y-auto space-y-4"
                :class="isLight ? 'bg-white border-slate-200' : 'bg-[#131722]/75 border-white/[0.08]'"
            >
                <div class="relative">
                    <Search class="w-4 h-4 text-[#64748B] absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                    <input
                        v-model="arama"
                        type="text"
                        :placeholder="$t('Kılavuzda ara: barkod, fatura, vade, form kurucu…')"
                        class="w-full pl-10 pr-4 py-2.5 border rounded-2xl text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        :class="isLight ? 'bg-slate-50 border-slate-300 text-slate-900' : 'bg-[#212738] border-white/10 text-white'"
                    />
                </div>

                <!-- Arama Sonuçları -->
                <div v-if="aramaSonuclari.length" class="space-y-1.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-[#94A3B8] px-1">
                        {{ aramaSonuclari.length }} {{ $t('sonuç bulundu') }}
                    </p>
                    <button
                        v-for="a in aramaSonuclari"
                        :key="a.slug"
                        type="button"
                        @click="ac(a.slug)"
                        class="w-full text-left px-3.5 py-2.5 rounded-2xl text-xs transition-all cursor-pointer border"
                        :class="aktif === a.slug
                            ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] font-extrabold'
                            : isLight
                                ? 'bg-slate-50 border-slate-200 text-slate-800 hover:bg-slate-100'
                                : 'bg-[#212738]/70 border-white/[0.06] text-[#CBD5E1] hover:bg-[#212738]'"
                    >
                        <span class="block font-bold">{{ a.baslik }}</span>
                        <span class="block text-[11px] opacity-75 truncate mt-0.5">{{ ozet(a) }}</span>
                    </button>
                </div>

                <!-- Bölüm Ağacı -->
                <div v-else class="space-y-2.5">
                    <div
                        v-for="b in HELP_SECTIONS"
                        :key="b.id"
                        class="rounded-2xl border overflow-hidden transition-colors"
                        :class="isLight ? 'bg-slate-50/70 border-slate-200' : 'bg-[#212738]/55 border-white/[0.07]'"
                    >
                        <button
                            type="button"
                            @click="acikBolum(b.id)"
                            class="w-full flex items-center gap-2.5 px-3.5 py-3 text-left transition-colors cursor-pointer"
                            :class="isLight ? 'hover:bg-slate-100' : 'hover:bg-white/5'"
                        >
                            <ChevronDown
                                :class="['w-4 h-4 text-[#B9FF66] transition-transform shrink-0', acikBolumler.has(b.id) ? '' : '-rotate-90']"
                            />
                            <span class="flex-1 min-w-0">
                                <span class="block text-xs font-extrabold uppercase tracking-wider" :class="isLight ? 'text-slate-900' : 'text-white'">
                                    {{ bolumAdi(b) }}
                                </span>
                                <span class="block text-[11px] text-[#94A3B8] truncate">{{ bolumAciklama(b.id) }}</span>
                            </span>
                            <span class="text-[10px] font-black px-2 py-0.5 rounded-full bg-[#B9FF66]/20 text-[#B9FF66]">
                                {{ bolumMakaleleri(b.id).length }}
                            </span>
                        </button>

                        <div v-show="acikBolumler.has(b.id)" class="px-2 pb-2 space-y-1">
                            <button
                                v-for="a in bolumMakaleleri(b.id)"
                                :key="a.slug"
                                type="button"
                                @click="ac(a.slug)"
                                class="w-full text-left px-3 py-2 text-xs rounded-xl transition-all cursor-pointer flex items-center justify-between gap-2"
                                :class="aktif === a.slug
                                    ? 'bg-[#B9FF66] text-[#131722] font-extrabold shadow-sm'
                                    : isLight
                                        ? 'text-slate-700 hover:bg-slate-200/70'
                                        : 'text-[#CBD5E1] hover:bg-white/[0.06]'"
                            >
                                <span class="truncate">{{ kisaBaslik(a.baslik) }}</span>
                                <span v-if="aktif === a.slug" class="text-[10px] font-black">●</span>
                            </button>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Ana Makale & Görsel Rehber Alanı -->
            <main class="flex-1 min-w-0 p-4 sm:p-8 lg:p-10">
                <article v-if="makale" :key="makale.slug" class="max-w-4xl mx-auto space-y-6">
                    <!-- Üst Bento Hızlı Başlangıç Kartları (Index sayfasında veya her zaman kolay geçiş için) -->
                    <div v-if="makale.slug === 'index'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
                        <button
                            v-for="card in vitrinKartlari"
                            :key="card.slug"
                            type="button"
                            @click="ac(card.slug)"
                            class="text-left p-4 rounded-3xl text-white transition-all cursor-pointer card-3d-interactive shadow-lg relative overflow-hidden group"
                            :style="{ backgroundColor: card.bg }"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-black/25 text-white">
                                    {{ card.tag }}
                                </span>
                                <span class="w-7 h-7 rounded-full bg-white/20 flex items-center justify-center text-xs font-black group-hover:translate-x-0.5 transition-transform">
                                    ↗
                                </span>
                            </div>
                            <h3 class="text-base font-black tracking-tight">{{ card.title }}</h3>
                            <p class="text-xs text-white/85 mt-1 leading-relaxed">{{ card.desc }}</p>
                        </button>
                    </div>

                    <!-- Önerilen Okuma Sırası -->
                    <div
                        class="p-4 sm:p-5 rounded-3xl border"
                        :class="isLight ? 'bg-white border-slate-200 shadow-sm' : 'bg-[#212738] border-white/[0.08]'"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <nav class="flex items-center gap-1.5 text-xs text-[#94A3B8]">
                                <button type="button" @click="ac('index')" class="hover:text-[#B9FF66] font-bold transition-colors cursor-pointer">
                                    {{ $t('Yardım Merkezi') }}
                                </button>
                                <span>/</span>
                                <button type="button" @click="ac(ilkBolumMakale(makale.bolum))" class="hover:text-[#B9FF66] font-bold transition-colors cursor-pointer">
                                    {{ bolumAdi({ id: makale.bolum }) }}
                                </button>
                                <span>/</span>
                                <span class="font-bold text-[#B9FF66] truncate">{{ kisaBaslik(makale.baslik) }}</span>
                            </nav>

                            <span class="text-[11px] font-bold text-[#94A3B8]">
                                {{ siraNo + 1 }} / {{ ARTICLES.length }}
                            </span>
                        </div>

                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight mb-3" :class="isLight ? 'text-slate-900' : 'text-white'">
                            {{ makale.baslik }}
                        </h1>

                        <div class="flex flex-wrap gap-1.5 pt-2 border-t" :class="isLight ? 'border-slate-200' : 'border-white/[0.07]'">
                            <span class="text-[11px] font-bold text-[#94A3B8] mr-1 self-center">{{ $t('Hızlı Geçiş:') }}</span>
                            <button
                                v-for="(s, i) in baslangicYolu"
                                :key="s"
                                type="button"
                                @click="ac(s)"
                                class="text-xs px-3 py-1 rounded-full border transition-all cursor-pointer font-semibold"
                                :class="aktif === s
                                    ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] font-extrabold'
                                    : isLight
                                        ? 'bg-slate-100 border-slate-200 text-slate-700 hover:border-slate-400'
                                        : 'bg-[#131722] border-white/10 text-[#CBD5E1] hover:border-[#B9FF66]/40'"
                            >
                                <span class="font-black mr-1">{{ i + 1 }}.</span>{{ makaleBasligi(s) }}
                            </button>
                        </div>
                    </div>

                    <!-- Makale İçeriği -->
                    <div
                        class="p-5 sm:p-8 rounded-3xl border y-govde"
                        :class="[
                            isLight ? 'bg-white border-slate-200 text-slate-700 y-govde-light' : 'bg-[#212738] border-white/[0.08] text-[#CBD5E1]',
                        ]"
                        v-html="makale.html"
                        @click="govdeTikla"
                    ></div>

                    <!-- Alt İleri / Geri Navigasyonu -->
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 p-4 rounded-3xl border"
                        :class="isLight ? 'bg-white border-slate-200' : 'bg-[#212738] border-white/[0.08]'"
                    >
                        <PillButton v-if="onceki" size="sm" variant="ghost" @click="ac(onceki.slug)">
                            ← {{ kisaBaslik(onceki.baslik) }}
                        </PillButton>
                        <span v-else></span>

                        <button type="button" @click="basaDon" class="text-xs font-bold text-[#94A3B8] hover:text-[#B9FF66] transition-colors cursor-pointer">
                            ↑ {{ $t('Sayfa Başına Dön') }}
                        </button>

                        <PillButton v-if="sonraki" size="sm" variant="primary" @click="ac(sonraki.slug)">
                            {{ kisaBaslik(sonraki.baslik) }} →
                        </PillButton>
                    </div>
                </article>
            </main>
        </div>

        <!-- Görsel Büyütme (Lightbox) Modalı -->
        <div
            v-if="lightboxSrc"
            class="fixed inset-0 z-50 bg-black/85 backdrop-blur-md flex items-center justify-center p-4"
            @click="lightboxSrc = ''"
        >
            <div class="max-w-6xl w-full space-y-3" @click.stop>
                <div class="flex items-center justify-between text-white px-2">
                    <span class="text-sm font-bold">{{ lightboxAlt }}</span>
                    <button
                        type="button"
                        @click="lightboxSrc = ''"
                        class="px-3 py-1 rounded-full bg-white/10 hover:bg-[#B9FF66] hover:text-[#131722] text-xs font-extrabold cursor-pointer"
                    >
                        ✕ {{ $t('Kapat') }}
                    </button>
                </div>
                <img :src="lightboxSrc" :alt="lightboxAlt" class="w-full rounded-2xl border border-white/20 shadow-2xl" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronDown, Search, Sun, Moon, ArrowLeft } from 'lucide-vue-next';
import { ARTICLES, HELP_SECTIONS } from '../help/articles';
import { useTheme } from '../composables/useTheme';
import { t } from '../i18n';
import PillButton from '../components/ui/PillButton.vue';

const route = useRoute();
const router = useRouter();
const { isLight, toggleTheme } = useTheme();

const aktif = ref(route.query.s || 'index');
const arama = ref('');
const lightboxSrc = ref('');
const lightboxAlt = ref('');

const acikBolumler = ref(new Set(['modul']));
const baslangicYolu = ['HIZLI-BASLANGIC', 'Panel', 'Satislar', 'Urunler', 'Sablonlar', 'Kullanicilar-Roller'];

const vitrinKartlari = [
    {
        slug: 'HIZLI-BASLANGIC',
        tag: '#Başlangıç',
        title: '15 Dakikada Hızlı Kurulum',
        desc: 'Yeni firma ayarları, ilk cari, barkodlu ürün ve ilk fatura kesimi.',
        bg: '#3D55B6',
    },
    {
        slug: 'Panel',
        tag: '#Dashboard',
        title: 'Canlı Dashboard & Widget Kurucu',
        desc: 'Kartları fareyle sürükleyin, boyutlandırın, döviz ve dalga grafiklerini yönetin.',
        bg: '#DC6F31',
    },
    {
        slug: 'Satislar',
        tag: '#KameraBarkod',
        title: 'Satış, Teklif & Barkod Okuyucu',
        desc: 'Telefon veya bilgisayar kamerasıyla barkod/QR okutarak saniyeler içinde fatura kesin.',
        bg: '#4E956A',
    },
    {
        slug: 'Sablonlar',
        tag: '#Tasarım',
        title: 'Fatura & QR Etiket Tasarımcısı',
        desc: 'Fatura, irsaliye ve QR ürün etiketlerini görsel olarak düzenleyin, Orijinale Dön ile sıfırlayın.',
        bg: '#BC5FCF',
    },
    {
        slug: 'Kullanicilar-Roller',
        tag: '#İK & CV',
        title: 'Ekip, Roller & CV Başvuru Formu',
        desc: 'Özel kullanıcı sınıfları oluşturun ve firmanızın iş/katılım başvuru formunu tasarlayın.',
        bg: '#459BBE',
    },
    {
        slug: 'Borc-Alacak',
        tag: '#Finans',
        title: 'Borç/Alacak & Kasa-Banka',
        desc: 'Vade yaşlandırma sütunları, tahsilat dalgası ve çoklu döviz IBAN takibi.',
        bg: '#C55050',
    },
];

function acikBolum(id) {
    const next = new Set(acikBolumler.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    acikBolumler.value = next;
}

function bolumAciklama(id) {
    return {
        baslangic: t('Kurulum, kavramlar ve sık sorulanlar'),
        surec: t('Tekliften tahsilata uçtan uca iş akışları'),
        modul: t('Ekran ekran görsel modül kılavuzları'),
    }[id] || '';
}

function kisaBaslik(b) {
    const i = (b || '').indexOf('—');
    const k = i > 0 ? b.slice(0, i).trim() : (b || '');
    return k.length > 34 ? k.slice(0, 33) + '…' : k;
}

function makaleBasligi(slug) {
    return kisaBaslik(ARTICLES.find((a) => a.slug === slug)?.baslik || slug);
}

function ilkBolumMakale(bolum) {
    return (ARTICLES.find((a) => a.bolum === bolum) || {}).slug || 'index';
}

function basaDon() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const sira = computed(() => ARTICLES.map((a) => a.slug));
const makale = computed(() => ARTICLES.find((a) => a.slug === aktif.value) || ARTICLES[0]);
const siraNo = computed(() => Math.max(0, sira.value.indexOf(aktif.value)));
const onceki = computed(() => ARTICLES[siraNo.value - 1] || null);
const sonraki = computed(() => ARTICLES[siraNo.value + 1] || null);

function bolumAdi(b) {
    const bulunan = HELP_SECTIONS.find((s) => s.id === (b?.id || b));
    if (bulunan) return t(bulunan.ad);
    return { baslangic: t('Başlangıç'), surec: t('Süreçler'), modul: t('Modüller') }[b?.id || b] || '';
}

function bolumMakaleleri(id) {
    return ARTICLES.filter((a) => a.bolum === id);
}

function metinden(html) {
    const d = document.createElement('div');
    d.innerHTML = html;
    return (d.textContent || '').slice(0, 220);
}

function ozet(a) {
    return metinden(a.html);
}

const aramaSonuclari = computed(() => {
    const q = arama.value.trim().toLocaleLowerCase('tr');
    if (q.length < 2) return [];
    return ARTICLES.filter((a) => (a.baslik + ' ' + metinden(a.html)).toLocaleLowerCase('tr').includes(q)).slice(0, 12);
});

function ac(slug) {
    aktif.value = slug;
    arama.value = '';
    const m = ARTICLES.find((a) => a.slug === slug);
    if (m) {
        const next = new Set(acikBolumler.value);
        next.add(m.bolum);
        acikBolumler.value = next;
    }
    router.replace({ query: { ...route.query, s: slug } });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function govdeTikla(e) {
    const img = e.target.closest?.('img');
    if (img) {
        lightboxSrc.value = img.getAttribute('src') || '';
        lightboxAlt.value = img.getAttribute('alt') || '';
        return;
    }
    const a = e.target.closest?.('a[data-sayfa]');
    if (!a) return;
    e.preventDefault();
    const slug = a.getAttribute('data-sayfa');
    if (ARTICLES.some((x) => x.slug === slug)) ac(slug);
}

onMounted(() => {
    if (route.query.s && ARTICLES.some((a) => a.slug === route.query.s)) {
        aktif.value = route.query.s;
    }
    nextTick(() => window.scrollTo({ top: 0 }));
});
</script>

<style>
.y-govde { font-size: 14.5px; line-height: 1.8; }
.y-govde h2 { font-size: 21px; font-weight: 900; color: #fff; margin: 28px 0 12px; letter-spacing: -0.01em; }
.y-govde h3 { font-size: 16.5px; font-weight: 800; color: #B9FF66; margin: 22px 0 8px; }
.y-govde h4 { font-size: 14.5px; font-weight: 800; color: #7DD3FC; margin: 18px 0 6px; }
.y-govde p { margin: 10px 0; }
.y-govde ul, .y-govde ol { margin: 10px 0; padding-left: 22px; }
.y-govde li { margin: 6px 0; }
.y-govde li::marker { color: #B9FF66; font-weight: 800; }
.y-govde a { color: #B9FF66; font-weight: 700; text-decoration: underline; text-underline-offset: 3px; }
.y-govde a:hover { color: #fff; }
.y-govde code { font-family: ui-monospace, monospace; font-size: 12.5px; background: #131722; border: 1px solid rgba(255,255,255,.12); border-radius: 8px; padding: 2px 7px; color: #B9FF66; }
.y-govde pre { background: #131722; border: 1px solid rgba(255,255,255,.1); border-radius: 16px; padding: 14px 16px; overflow-x: auto; margin: 14px 0; }
.y-govde pre code { background: none; border: none; padding: 0; }
.y-govde blockquote { border-left: 4px solid #B9FF66; background: rgba(185,255,102,.08); border-radius: 0 16px 16px 0; padding: 12px 16px; margin: 14px 0; font-size: 13.5px; }
.y-govde hr { border: none; border-top: 1px solid rgba(255,255,255,.08); margin: 24px 0; }
.y-govde figure.y-fig { margin: 18px 0; background: #131722; border: 1px solid rgba(185,255,102,.25); border-radius: 20px; padding: 10px; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,.35); }
.y-govde figure.y-fig img, .y-govde img { max-width: 100%; border-radius: 14px; display: block; margin: 0 auto; cursor: zoom-in; transition: transform 0.2s ease; }
.y-govde figure.y-fig img:hover { transform: scale(1.01); }
.y-govde figure.y-fig figcaption { font-size: 12px; font-weight: 700; color: #94A3B8; text-align: center; padding-top: 8px; }
.y-govde .y-tabwrap { overflow-x: auto; margin: 14px 0; border: 1px solid rgba(255,255,255,.1); border-radius: 16px; }
.y-govde table.y-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.y-govde .y-table th { background: #131722; color: #B9FF66; text-align: left; padding: 10px 12px; font-size: 12px; font-weight: 800; white-space: nowrap; }
.y-govde .y-table td { padding: 10px 12px; border-top: 1px solid rgba(255,255,255,.07); vertical-align: top; }

/* Açık Tema Uyumu */
.y-govde-light h2 { color: #0F172A; }
.y-govde-light h3 { color: #15803D; }
.y-govde-light h4 { color: #0369A1; }
.y-govde-light a { color: #15803D; }
.y-govde-light code { background: #F1F5F9; color: #0F172A; border-color: #CBD5E1; }
.y-govde-light .y-table th { background: #F1F5F9; color: #0F172A; }
.y-govde-light .y-table td { border-top-color: #E2E8F0; color: #334155; }
</style>
