<!--
    Widget kartı  — Dashboard dili (TASARIM-SISTEMI.md §6):
    - Başlıkta {adet} rozeti + sağda ↗ daire buton (referans: „Projects {88}").
    - KPI/borç-alacak: TAM RENKLİ kategori kartları (beyaz metin, hashtag hapı,
      ↗ dairesi, büyük tutar) — referanstaki proje kartı mimarisi.
    - Grafik: kalın kapsül çubuklar + üstte ay hapları; en yüksek ay lime + parlama.
    - Veri biçimleri API-SOZLESME §2 „Dashboard widget ızgarası" tablosundadır.
-->
<template>
    <Card rounded="2xl" padding="md" :class="widget.size === 'wide' ? 'md:col-span-2 xl:col-span-2' : ''">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                {{ $t(widget.title_key) }}
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-black leading-none"
                    :style="{ backgroundColor: countBg, color: countFg }">{{ headCount }}</span>
            </h3>
            <router-link
                v-if="target"
                :to="target"
                class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white/90 hover:bg-white hover:text-[#191E2C] transition-colors"
                :title="$t('Detay')"
            >
                <ArrowUpRight class="w-4 h-4 transition-transform hover:translate-x-0.5 hover:-translate-y-0.5" />
            </router-link>
        </div>

        <!-- KPI: renkli kategori kartları -->
        <div v-if="widget.key === 'kpi'" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div
                v-for="kart in kpiCards"
                :key="kart.key"
                class="relative overflow-hidden rounded-2xl p-4 flex flex-col justify-between min-h-[150px] transition-transform hover:-translate-y-0.5"
                :style="{ backgroundColor: kart.bg }"
            >
                <div class="flex items-center justify-between">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold text-white border border-white/20 bg-white/[0.12] backdrop-blur-xs">
                        #{{ $t(kart.tag) }}
                    </span>
                    <span class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-white/90">
                        <ArrowUpRight class="w-4 h-4" />
                    </span>
                </div>
                <div>
                    <p class="text-xs font-semibold text-white/80 uppercase tracking-wider">{{ $t(kart.labelKey) }} ({{ kart.currency }})</p>
                    <p class="text-2xl font-black text-white tracking-tight">{{ kart.value }}</p>
                    <p class="text-[11px] text-white/70 font-medium">
                        <template v-if="kart.subCount !== undefined">{{ kart.subCount }} {{ $t('belge') }}</template>
                        <template v-else>{{ $t(kart.subKey) }}</template>
                    </p>
                </div>
            </div>
            <div v-if="!kpiCards.length" class="text-xs text-[#64748B] sm:col-span-3">{{ $t('Bu ay kesilmiş fatura yok.') }}</div>
        </div>

        <!-- Ciro trendi: ay hapları + kalın kapsül çubuklar -->
        <div v-else-if="widget.key === 'revenue_trend'">
            <div v-if="!trendRows.length" class="py-8 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                {{ $t('Son 6 ayda kesilmiş fatura yok.') }}
            </div>
            <template v-else>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    <span
                        v-for="m in trendMonths"
                        :key="m"
                        class="px-3 py-1 rounded-full text-[11px] font-semibold"
                        :class="m === trendActiveMonth ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]' : 'bg-white/[0.06] text-[#94A3B8]'"
                    >
                        {{ m }}
                    </span>
                </div>
                <div class="flex items-stretch gap-1.5 h-44 pt-1 pb-1 overflow-x-auto">
                    <div
                        v-for="r in trendRows"
                        :key="r.month + r.currency"
                        class="flex-1 min-w-[44px] flex flex-col items-center justify-end gap-1.5 h-full"
                        :title="trendTip(r)"
                    >
                        <span class="text-[10px] font-bold text-[#CBD5E1] whitespace-nowrap">{{ shortMoney(r.total) }}</span>
                        <div
                            class="w-full max-w-10 rounded-t-lg transition-colors"
                            :class="r.total >= trendMax ? 'bg-[#B9FF66]/75 hover:bg-[#B9FF66] shadow-[0_0_16px_rgba(185,255,102,0.35)]' : 'bg-white/15 hover:bg-white/25'"
                            :style="{ height: barPct(r.total) + '%' }"
                        ></div>
                        <span class="text-[10px] leading-tight text-[#64748B] text-center break-words w-full">{{ r.month.slice(5) }}</span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Vade takvimi: ay ızgarası + yaklaşanlar -->
        <div v-else-if="widget.key === 'due_calendar'" class="space-y-2 text-xs">
            <div class="flex items-center justify-between">
                <span class="font-black text-white text-sm">{{ takvimAyAdi }}</span>
                <span class="text-[11px] text-[#64748B]">{{ $t('Pzt') }}–{{ $t('Paz') }}</span>
            </div>
            <div class="grid grid-cols-7 gap-1 max-w-[400px]">
                <div
                    v-for="(g, i) in takvimGunleri"
                    :key="i"
                    class="aspect-square rounded-lg border flex flex-col items-center justify-center text-[11px] font-bold"
                    :class="[
                        !g ? 'border-transparent' : '',
                        g && takvimBugunMu(g.iso) ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66]' : 'text-[#94A3B8]',
                        g && !takvimBugunMu(g.iso) && (vadeGunHaritasi[g.iso] || []).length ? 'bg-white/[0.04] ' + takvimHalkasi(g.iso) : 'border-white/[0.06]',
                    ]"
                    :title="g && (vadeGunHaritasi[g.iso] || []).length ? vadeGunHaritasi[g.iso].length + ' vade' : ''"
                >
                    <template v-if="g">{{ g.gun }}</template>
                    <span v-if="g && (vadeGunHaritasi[g.iso] || []).length" class="text-[9px] font-black leading-none"
                        :class="takvimBugunMu(g.iso) ? 'text-[#131722]' : 'text-[#B9FF66]'">{{ vadeGunHaritasi[g.iso].length }}</span>
                </div>
            </div>
            <div v-if="!(widget.data.items || []).length" class="text-[#64748B]">{{ $t('Önümüzdeki 30 günde vade yok.') }}</div>
            <div
                v-for="(d, i) in (widget.data.items || []).slice(0, 4)"
                :key="d.document_id"
                class="flex items-center gap-2.5 p-2.5 rounded-xl bg-[#131722]/70 border border-white/10 hover:bg-white/[0.04] transition-colors"
            >
                <span
                    class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center text-[11px] font-black text-white"
                    :style="{ backgroundColor: avatarColor(i) }"
                >{{ avatarLetter(d.party_name) }}</span>
                <span class="min-w-0 grow"><b class="text-white font-mono">{{ d.serial }}</b> <span class="text-[#94A3B8] truncate">· {{ d.party_name || '—' }}</span></span>
                <span class="shrink-0 text-right">
                    <b class="text-white">{{ formatMoney(d.open_amount, d.currency) }}</b>
                    <StatusPill :color="isOverdue(d.due_date) ? 'coral' : 'neutral'" :dot="false">{{ d.due_date }}</StatusPill>
                </span>
            </div>
        </div>

        <!-- Kur göstergesi -->
        <div v-else-if="widget.key === 'fx_rates'" class="space-y-1.5 text-xs">
            <div v-if="!(widget.data.items || []).length" class="text-[#64748B]">{{ $t('Kur bilgisi yok.') }}</div>
            <div v-for="f in widget.data.items || []" :key="f.currency" class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-[#131722]/70 border border-white/10">
                <span class="font-bold text-white">{{ f.symbol }} {{ f.currency }}</span>
                <span class="text-[#94A3B8]">{{ f.rate === null ? '—' : ('1 ' + f.currency + ' = ' + formatRate(f.rate) + ' TRY') }}</span>
            </div>
        </div>

        <!-- Kritik stok -->
        <div v-else-if="widget.key === 'low_stock'" class="space-y-1.5 text-xs">
            <div v-if="!(widget.data.items || []).length" class="text-[#64748B]">{{ $t('Kritik stokta ürün yok.') }}</div>
            <div v-for="p in widget.data.items || []" :key="p.product_id" class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-[#C55050]/10 border border-[#C55050]/25">
                <span class="font-semibold text-white truncate">{{ p.name }}</span>
                <span class="shrink-0 text-[#FFA0A0] font-mono">{{ formatNumber(p.stock) }} / {{ formatNumber(p.minimum) }}</span>
            </div>
        </div>

        <!-- Son hareketler (yalnız Admin havuzunda) -->
        <div v-else-if="widget.key === 'recent_activity'" class="space-y-1.5 text-xs">
            <div v-if="!(widget.data.items || []).length" class="text-[#64748B]">{{ $t('Henüz denetim kaydı yok.') }}</div>
            <div v-for="(a, i) in widget.data.items || []" :key="i" class="flex items-center justify-between gap-2 py-1.5 border-b border-white/[0.06] last:border-0">
                <StatusPill color="violet" :dot="false">{{ a.action_label }}</StatusPill>
                <span class="text-[#64748B] truncate">{{ a.user_name || '—' }}</span>
                <span class="shrink-0 font-mono text-[#64748B]">{{ a.created_at }}</span>
            </div>
        </div>

        <!-- Borç/alacak özeti: renkli kartlar -->
        <div v-else-if="widget.key === 'debt_summary'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div
                v-for="kart in debtCards"
                :key="kart.key"
                class="relative overflow-hidden rounded-2xl p-4 flex flex-col justify-between min-h-[130px]"
                :style="{ backgroundColor: kart.bg }"
            >
                <div class="flex items-center justify-between">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold text-white border border-white/20 bg-white/[0.12] backdrop-blur-xs">
                        {{ $t(kart.tag) }}
                    </span>
                </div>
                <div>
                    <p class="text-xs font-semibold text-white/80 uppercase tracking-wider">{{ $t(kart.labelKey) }} ({{ kart.currency }})</p>
                    <p class="text-2xl font-black text-white tracking-tight">{{ kart.value }}</p>
                    <p class="text-[11px] text-white/70 font-medium">{{ $t(kart.subKey) }}</p>
                </div>
            </div>
            <div v-if="!debtCards.length" class="text-xs text-[#64748B] sm:col-span-2">{{ $t('Açık borç/alacak yok.') }}</div>
        </div>

        <!-- Eklenti widget'ı : modül config metni -->
        <div v-else class="text-sm text-white/90 whitespace-pre-wrap">{{ widget.data.text || $t('Henüz not yok.') }}</div>
    </Card>
</template>

<script setup>
import { computed } from 'vue';
import Card from '../ui/Card.vue';
import StatusPill from '../ui/StatusPill.vue';
import { formatMoney, formatNumber } from '../../utils/format';
import { ArrowUpRight } from 'lucide-vue-next';

const props = defineProps({
    widget: { type: Object, required: true },
});

/** Kartın ↗ gittiği yer (eski pano dilindeki „Detay →" oku). */
const target = computed(() => ({
    kpi: '/raporlar',
    revenue_trend: '/raporlar',
    due_calendar: '/satislar',
    fx_rates: '/ayarlar',
    low_stock: '/stok',
    recent_activity: null,
    debt_summary: '/borc-alacak',
}[props.widget.key] || null));

/** Başlıktaki {adet}: kartın satır sayısı (referans: „Projects {88}"). */
const headCount = computed(() => {
    const d = props.widget.data || {};
    const counts = {
        kpi: (d.revenue_month || []).length + (d.receivable || []).length + (d.payable || []).length,
        revenue_trend: new Set((d.points || []).map((r) => r.month)).size,
        due_calendar: (d.items || []).length,
        fx_rates: (d.items || []).length,
        low_stock: (d.items || []).length,
        recent_activity: (d.items || []).length,
        debt_summary: (d.receivable || []).length + (d.payable || []).length,
    };
    return counts[props.widget.key] ?? 0;
});

// Kategori renk paleti (TASARIM-SISTEMI.md §2).
const PALETTE = {
    cobalt: '#3D55B6',
    violet: '#BC5FCF',
    forest: '#4E956A',
    orange: '#DC6F31',
    coral: '#C55050',
    cyan: '#459BBE',
};

/** Başlıktaki adet rozeti: her widget kendi renginde (referanstaki {88} yerine). */
const countBg = computed(() => ({
    kpi: PALETTE.cobalt,
    revenue_trend: '#B9FF66',
    due_calendar: PALETTE.orange,
    fx_rates: PALETTE.cyan,
    low_stock: PALETTE.coral,
    recent_activity: PALETTE.violet,
    debt_summary: PALETTE.forest,
}[props.widget.key] || '#B9FF66'));
const countFg = computed(() => (props.widget.key === 'revenue_trend' ? '#131722' : '#ffffff'));

const kpiCards = computed(() => {
    const d = props.widget.data || {};
    const out = [];
    (d.revenue_month || []).forEach((r) => {
        out.push({
            key: 'c' + r.currency, bg: PALETTE.cobalt, tag: 'Ciro',
            labelKey: 'Bu ay ciro', currency: r.currency,
            value: formatMoney(r.total, r.currency),
            subKey: 'belge', subCount: r.documents,
        });
    });
    (d.receivable || []).forEach((r) => {
        out.push({
            key: 'a' + r.currency, bg: PALETTE.forest, tag: 'Alacak',
            labelKey: 'Açık alacak', currency: r.currency,
            value: formatMoney(r.total, r.currency),
            subKey: 'Tahsil bekliyor',
        });
    });
    (d.payable || []).forEach((r) => {
        out.push({
            key: 'b' + r.currency, bg: PALETTE.coral, tag: 'Borç',
            labelKey: 'Açık borç', currency: r.currency,
            value: formatMoney(r.total, r.currency),
            subKey: 'Ödeme bekliyor',
        });
    });
    return out.slice(0, 6);
});

const debtCards = computed(() => {
    const d = props.widget.data || {};
    const out = [];
    (d.receivable || []).forEach((r) => {
        out.push({
            key: 'a' + r.currency, bg: PALETTE.forest, tag: 'Alacak',
            labelKey: 'Alacak', currency: r.currency,
            value: formatMoney(r.total, r.currency),
            subKey: 'Müşteriden beklenen',
        });
    });
    (d.payable || []).forEach((r) => {
        out.push({
            key: 'b' + r.currency, bg: PALETTE.orange, tag: 'Borç',
            labelKey: 'Borç', currency: r.currency,
            value: formatMoney(r.total, r.currency),
            subKey: 'Tedarikçiye ödenecek',
        });
    });
    return out.slice(0, 4);
});

const trendRows = computed(() => {
    const rows = [...(props.widget.data?.points || [])];
    rows.sort((a, b) => (a.month < b.month ? -1 : a.month > b.month ? 1 : a.currency < b.currency ? -1 : 1));
    return rows.slice(-12);
});

const trendMonths = computed(() => [...new Set(trendRows.value.map((r) => (r.month || '').slice(5)))]);
const trendActiveMonth = computed(() => {
    if (!trendRows.value.length) return '';
    let best = trendRows.value[0];
    for (const r of trendRows.value) if (Number(r.total) > Number(best.total)) best = r;
    return (best.month || '').slice(5);
});

const trendMax = computed(() => Math.max(1, ...trendRows.value.map((r) => Number(r.total) || 0)));

function barPct(v) {
    return Math.max(4, Math.round((Number(v) / trendMax.value) * 100));
}

function trendTip(r) {
    return `${r.month} · ${r.currency} ${formatMoney(r.total, r.currency)}`;
}

function formatRate(v) {
    return Number(v).toLocaleString('tr-TR', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}

function avatarColor(i) {
    return [PALETTE.cobalt, PALETTE.violet, PALETTE.forest, PALETTE.orange, PALETTE.cyan, PALETTE.coral][i % 6];
}

function avatarLetter(name) {
    const t = (name || '—').trim();
    return t ? t[0].toLocaleUpperCase('tr') : '—';
}

function isOverdue(ymd) {
    if (!ymd) return false;
    return ymd < new Date().toISOString().slice(0, 10);
}

function shortMoney(v) {
    const n = Number(v) || 0;
    if (Math.abs(n) >= 1000) return (n / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + 'K';
    return String(Math.round(n));
}

/** Vade takvimi ay ızgarası (referans: renkli gün hücreli Calendar). */
const takvimAyi = computed(() => {
    const simdi = new Date();
    return { yil: simdi.getFullYear(), ay: simdi.getMonth() };
});
const takvimGunleri = computed(() => {
    const { yil, ay } = takvimAyi.value;
    const ilk = (new Date(yil, ay, 1).getDay() + 6) % 7; // Pazartesi başlar
    const sayi = new Date(yil, ay + 1, 0).getDate();
    const gunler = [];
    for (let i = 0; i < ilk; i++) gunler.push(null);
    for (let g = 1; g <= sayi; g++) {
        const iso = `${yil}-${String(ay + 1).padStart(2, '0')}-${String(g).padStart(2, '0')}`;
        gunler.push({ gun: g, iso });
    }
    return gunler;
});
const vadeGunHaritasi = computed(() => {
    const harita = {};
    for (const d of props.widget.data?.items || []) {
        if (!d.due_date) continue;
        (harita[d.due_date] = harita[d.due_date] || []).push(d);
    }
    return harita;
});
function takvimBugunMu(iso) {
    return iso === new Date().toISOString().slice(0, 10);
}
function takvimHalkasi(iso) {
    const kalemler = vadeGunHaritasi.value[iso] || [];
    if (!kalemler.length) return '';
    return kalemler.some((d) => isOverdue(d.due_date))
        ? 'border-[#C55050] shadow-[0_0_10px_rgba(197,80,80,0.35)]'
        : 'border-[#DC6F31] shadow-[0_0_10px_rgba(220,111,49,0.3)]';
}
const takvimAyAdi = computed(() => {
    const adlar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    return `${adlar[takvimAyi.value.ay]} ${takvimAyi.value.yil}`;
});
</script>
