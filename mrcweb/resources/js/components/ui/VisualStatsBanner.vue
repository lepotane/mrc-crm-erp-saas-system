<template>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 select-none">
        <!-- 1. RENKLİ SÜTUN GRAFİĞİ KARTI (SÜTUNLAR) -->
        <div
            class="lg:col-span-5 rounded-[26px] p-5 border border-white/[0.08] bg-[#212738] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.45)] flex flex-col justify-between card-3d-interactive relative overflow-hidden"
        >
            <div
                class="absolute -right-10 -top-10 w-36 h-36 rounded-full opacity-20 pointer-events-none"
                :style="{ background: `radial-gradient(circle, ${accentColor} 0%, transparent 70%)` }"
            ></div>

            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider mb-1.5"
                        :style="{ backgroundColor: accentColor + '25', color: accentColor }"
                    >
                        <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: accentColor }"></span>
                        {{ badgeText }}
                    </span>
                    <h3 class="text-base sm:text-lg font-black text-white tracking-tight">{{ title }}</h3>
                    <p class="text-xs text-[#94A3B8]">{{ subtitle }}</p>
                </div>
                <div class="text-right shrink-0">
                    <div class="text-xl sm:text-2xl font-black text-white tracking-tight">{{ primaryValue }}</div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-[#4E956A]/25 text-[#B9FF66] border border-[#B9FF66]/30">
                        {{ trendText }}
                    </span>
                </div>
            </div>

            <!-- Sütun (Pill Column) Grafiği -->
            <div class="pt-2">
                <div class="grid grid-cols-6 gap-2 items-end h-24 px-1">
                    <div
                        v-for="(col, idx) in normalizedBars"
                        :key="idx"
                        class="flex flex-col items-center justify-end h-full group cursor-pointer"
                    >
                        <span class="text-[9px] font-extrabold text-[#94A3B8] group-hover:text-white mb-1 transition-colors">
                            {{ col.val }}
                        </span>
                        <div class="w-full h-16 rounded-xl bg-[#191E2C] p-0.5 flex items-end overflow-hidden border border-white/[0.05]">
                            <div
                                class="w-full rounded-lg transition-all duration-500"
                                :style="{
                                    height: Math.max(18, col.pct) + '%',
                                    background: idx === normalizedBars.length - 1
                                        ? 'linear-gradient(180deg, #B9FF66 0%, #4E956A 100%)'
                                        : `linear-gradient(180deg, ${col.color} 0%, ${col.color}88 100%)`,
                                }"
                            ></div>
                        </div>
                        <span class="text-[10px] font-bold text-[#64748B] mt-1 truncate max-w-full">{{ col.label }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. PASTA / DONUT DAĞILIM GRAFİĞİ KARTI (PASTA) -->
        <div
            class="lg:col-span-3 rounded-[26px] p-5 border border-white/[0.08] bg-[#212738] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.45)] flex flex-col justify-between card-3d-interactive"
        >
            <div class="flex items-center justify-between mb-2">
                <div>
                    <h4 class="text-sm font-black text-white tracking-tight">{{ pieTitle }}</h4>
                    <p class="text-[11px] text-[#94A3B8]">{{ pieSubtitle }}</p>
                </div>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-white/5 text-[#B9FF66] border border-white/10">
                    Pasta
                </span>
            </div>

            <div class="flex items-center gap-3.5 my-auto py-1">
                <div class="relative w-24 h-24 shrink-0 flex items-center justify-center">
                    <svg viewBox="0 0 42 42" class="w-full h-full -rotate-90">
                        <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#191E2C" stroke-width="6.2" />
                        <circle
                            v-for="(seg, sIdx) in computedSegments"
                            :key="sIdx"
                            cx="21"
                            cy="21"
                            r="15.915"
                            fill="transparent"
                            :stroke="seg.color"
                            stroke-width="6.2"
                            :stroke-dasharray="`${seg.pct} ${100 - seg.pct}`"
                            :stroke-dashoffset="-seg.offset"
                        />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                        <span class="text-xs font-black text-white">{{ pieCenterValue }}</span>
                        <span class="text-[8px] font-bold text-[#94A3B8] uppercase">{{ pieCenterLabel }}</span>
                    </div>
                </div>

                <div class="space-y-1.5 flex-1 min-w-0">
                    <div
                        v-for="(seg, sIdx) in computedSegments"
                        :key="sIdx"
                        class="flex items-center justify-between text-[11px] gap-1"
                    >
                        <span class="inline-flex items-center gap-1.5 text-white font-semibold truncate">
                            <span class="w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: seg.color }"></span>
                            <span class="truncate">{{ seg.label }}</span>
                        </span>
                        <span class="font-black shrink-0" :style="{ color: seg.color }">{{ seg.pct }}%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. DALGA TREND & AKIM KARTI (DALGALAR) -->
        <div
            class="lg:col-span-4 rounded-[26px] p-5 border border-white/[0.08] bg-[#212738] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.45)] flex flex-col justify-between card-3d-interactive"
        >
            <div class="flex items-center justify-between mb-2">
                <div>
                    <h4 class="text-sm font-black text-white tracking-tight">{{ waveTitle }}</h4>
                    <p class="text-[11px] text-[#94A3B8]">{{ waveSubtitle }}</p>
                </div>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-[#B9FF66]/15 text-[#B9FF66] border border-[#B9FF66]/30">
                    {{ waveBadge }}
                </span>
            </div>

            <div class="relative h-24 w-full bg-[#191E2C]/90 rounded-2xl border border-white/[0.05] px-3 pt-2 pb-1 flex flex-col justify-between overflow-hidden">
                <svg viewBox="0 0 400 90" class="w-full h-16 overflow-visible">
                    <defs>
                        <linearGradient :id="gradId1" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" :stop-color="accentColor" stop-opacity="0.45" />
                            <stop offset="100%" :stop-color="accentColor" stop-opacity="0.0" />
                        </linearGradient>
                        <linearGradient :id="gradId2" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#B9FF66" stop-opacity="0.35" />
                            <stop offset="100%" stop-color="#B9FF66" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path
                        d="M0,65 C45,25 90,72 135,35 C180,10 225,58 270,24 C315,8 360,42 400,16 L400,90 L0,90 Z"
                        :fill="`url(#${gradId1})`"
                    />
                    <path
                        d="M0,65 C45,25 90,72 135,35 C180,10 225,58 270,24 C315,8 360,42 400,16"
                        fill="none"
                        :stroke="accentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                    />
                    <path
                        d="M0,78 C55,55 110,82 165,50 C220,32 275,68 330,40 C365,28 385,35 400,28 L400,90 L0,90 Z"
                        :fill="`url(#${gradId2})`"
                    />
                    <path
                        d="M0,78 C55,55 110,82 165,50 C220,32 275,68 330,40 C365,28 385,35 400,28"
                        fill="none"
                        stroke="#B9FF66"
                        stroke-width="2.2"
                        stroke-dasharray="4 3"
                        stroke-linecap="round"
                    />
                    <circle cx="135" cy="35" r="3.5" fill="#131722" :stroke="accentColor" stroke-width="2.5" />
                    <circle cx="270" cy="24" r="3.5" fill="#131722" :stroke="accentColor" stroke-width="2.5" />
                    <circle cx="400" cy="16" r="4.5" fill="#B9FF66" />
                </svg>

                <div class="flex items-center justify-between text-[10px] font-bold text-[#64748B]">
                    <span v-for="(m, mIdx) in waveLabels" :key="mIdx">{{ m }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    badgeText: { type: String, default: 'Canlı Analitik' },
    title: { type: String, default: 'Operasyon & Hacim Özeti' },
    subtitle: { type: String, default: 'Dönemsel sütun karşılaştırması' },
    primaryValue: { type: String, default: '100%' },
    trendText: { type: String, default: '+18.4% dönem' },
    accentColor: { type: String, default: '#3D55B6' },
    bars: {
        type: Array,
        default: () => [
            { label: 'Haz', pct: 48, val: '48', color: '#3D55B6' },
            { label: 'Tem', pct: 64, val: '64', color: '#459BBE' },
            { label: 'Ağu', pct: 55, val: '55', color: '#BC5FCF' },
            { label: 'Eyl', pct: 78, val: '78', color: '#DC6F31' },
            { label: 'Eki', pct: 84, val: '84', color: '#4E956A' },
            { label: 'Kas', pct: 95, val: '95', color: '#B9FF66' },
        ],
    },
    pieTitle: { type: String, default: 'Kategori Dağılımı' },
    pieSubtitle: { type: String, default: 'Oransal kırılım' },
    pieCenterValue: { type: String, default: '100%' },
    pieCenterLabel: { type: String, default: 'Toplam' },
    pieSegments: {
        type: Array,
        default: () => [
            { label: 'Aktif İşlem', pct: 45, color: '#B9FF66' },
            { label: 'Tamamlanan', pct: 30, color: '#3D55B6' },
            { label: 'Bekleyen', pct: 15, color: '#DC6F31' },
            { label: 'Diğer', pct: 10, color: '#BC5FCF' },
        ],
    },
    waveTitle: { type: String, default: 'Dalga Akım Grafiği' },
    waveSubtitle: { type: String, default: 'Haftalık işlem ivmesi' },
    waveBadge: { type: String, default: 'Canlı Akış' },
    waveLabels: {
        type: Array,
        default: () => ['H1', 'H2', 'H3', 'H4', 'H5', 'Bugün'],
    },
});

const uid = Math.random().toString(36).slice(2, 8);
const gradId1 = `vsbGrad1_${uid}`;
const gradId2 = `vsbGrad2_${uid}`;

const normalizedBars = computed(() => {
    const palette = ['#3D55B6', '#459BBE', '#BC5FCF', '#DC6F31', '#4E956A', '#B9FF66'];
    return (props.bars || []).slice(0, 6).map((b, i) => ({
        label: b.label || `D${i + 1}`,
        pct: Number(b.pct) || 40,
        val: b.val ?? `${b.pct}%`,
        color: b.color || palette[i % palette.length],
    }));
});

const computedSegments = computed(() => {
    let offset = 0;
    return (props.pieSegments || []).map((s) => {
        const pct = Math.max(2, Number(s.pct) || 0);
        const item = {
            label: s.label,
            pct,
            color: s.color || '#B9FF66',
            offset,
        };
        offset += pct;
        return item;
    });
});
</script>
