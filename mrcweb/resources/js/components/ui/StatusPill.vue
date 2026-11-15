<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border select-none',
            colorClasses,
        ]"
    >
        <span v-if="dot" :class="['w-1.5 h-1.5 rounded-full animate-pulse', dotClass]" />
        <slot />
    </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: {
        type: String,
        default: 'lime', // lime, cobalt, coral, orange, violet, cyan, forest, neutral
    },
    dot: {
        type: Boolean,
        default: true,
    },
});

const colorClasses = computed(() => {
    switch (props.color) {
        case 'cobalt':
            return 'bg-[#3D55B6]/15 text-[#889BEE] border-[#3D55B6]/30';
        case 'coral':
            return 'bg-[#C55050]/15 text-[#FFA0A0] border-[#C55050]/30';
        case 'orange':
            return 'bg-[#DC6F31]/15 text-[#FFAE7C] border-[#DC6F31]/30';
        case 'violet':
            return 'bg-[#BC5FCF]/15 text-[#E6A6F3] border-[#BC5FCF]/30';
        case 'cyan':
            return 'bg-[#459BBE]/15 text-[#8CD5F4] border-[#459BBE]/30';
        case 'forest':
            return 'bg-[#4E956A]/15 text-[#8FE1AF] border-[#4E956A]/30';
        case 'neutral':
            return 'bg-white/5 text-[#94A3B8] border-white/10';
        case 'lime':
        default:
            return 'bg-[#B9FF66]/15 text-[#B9FF66] border-[#B9FF66]/30';
    }
});

const dotClass = computed(() => {
    switch (props.color) {
        case 'cobalt':
            return 'bg-[#3D55B6]';
        case 'coral':
            return 'bg-[#C55050]';
        case 'orange':
            return 'bg-[#DC6F31]';
        case 'violet':
            return 'bg-[#BC5FCF]';
        case 'cyan':
            return 'bg-[#459BBE]';
        case 'forest':
            return 'bg-[#4E956A]';
        case 'neutral':
            return 'bg-[#94A3B8]';
        case 'lime':
        default:
            return 'bg-[#B9FF66]';
    }
});
</script>
