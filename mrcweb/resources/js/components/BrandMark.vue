<template>
    <div class="inline-flex items-center gap-2.5 select-none font-sans group">
        <div class="relative flex items-center justify-center" :class="sizeClasses.wrapper">
            <!-- Arka Katman (Çift Katmanlı 3D Yaprak/Squircle Gölgesi) -->
            <div
                :class="[
                    'absolute -left-1 top-1 bg-[#4E956A]/65 -rotate-12 transition-transform duration-300 group-hover:-rotate-18',
                    sizeClasses.box,
                ]"
            />
            <!-- Ön Parlak Neon Lime Katman -->
            <div
                :class="[
                    'relative flex items-center justify-center font-extrabold bg-[#B9FF66] text-[#131722] rotate-6 shadow-[0_0_20px_rgba(185,255,102,0.35)] transition-transform duration-300 group-hover:rotate-0 group-hover:scale-105',
                    sizeClasses.box,
                ]"
            >
                <span :class="sizeClasses.letter" class="-rotate-6 group-hover:rotate-0 transition-transform">M</span>
            </div>
        </div>
        <div v-if="!collapsed" class="flex flex-col leading-none">
            <span :class="['font-extrabold tracking-tight text-white', sizeClasses.wordmark]">MRC<span class="text-[#B9FF66]">.</span></span>
            <span v-if="showTag" class="text-[10px] font-semibold text-[#94A3B8] tracking-widest uppercase">CRM & ERP</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    size: {
        type: String,
        default: 'md', // sm, md, lg
    },
    collapsed: {
        type: Boolean,
        default: false,
    },
    showTag: {
        type: Boolean,
        default: false,
    },
});

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return {
                wrapper: 'w-9 h-9',
                box: 'w-8 h-8 rounded-[11px]',
                letter: 'text-sm font-black',
                wordmark: 'text-base font-bold',
            };
        case 'lg':
            return {
                wrapper: 'w-13 h-13',
                box: 'w-12 h-12 rounded-[16px]',
                letter: 'text-2xl font-black',
                wordmark: 'text-2xl font-black',
            };
        case 'md':
        default:
            return {
                wrapper: 'w-11 h-11',
                box: 'w-10 h-10 rounded-[14px]',
                letter: 'text-lg font-black',
                wordmark: 'text-xl font-bold',
            };
    }
});
</script>
