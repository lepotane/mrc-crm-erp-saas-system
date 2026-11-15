<template>
    <span
        :class="[
            'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold tracking-wide uppercase border backdrop-blur-xs select-none',
            colorClasses,
        ]"
    >
        <slot />
    </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: {
        type: String,
        default: 'neutral', // lime, cobalt, coral, orange, violet, cyan, forest, neutral
    },
});

const colorClasses = computed(() => {
    switch (props.color) {
        case 'lime':
            return 'bg-[#B9FF66]/20 text-[#B9FF66] border-[#B9FF66]/40';
        case 'cobalt':
            return 'bg-[#3D55B6]/25 text-[#BAC7FF] border-[#3D55B6]/50';
        case 'coral':
            return 'bg-[#C55050]/25 text-[#FFBDBD] border-[#C55050]/50';
        case 'orange':
            return 'bg-[#DC6F31]/25 text-[#FFCCA6] border-[#DC6F31]/50';
        case 'violet':
            return 'bg-[#BC5FCF]/25 text-[#F5C7FD] border-[#BC5FCF]/50';
        case 'cyan':
            return 'bg-[#459BBE]/25 text-[#B6EAFF] border-[#459BBE]/50';
        case 'forest':
            return 'bg-[#4E956A]/25 text-[#BCF7D4] border-[#4E956A]/50';
        case 'neutral':
        default:
            return 'bg-white/10 text-white/90 border-white/15';
    }
});
</script>
