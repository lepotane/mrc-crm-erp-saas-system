<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center font-semibold rounded-full transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#B9FF66] select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed',
            variantClasses,
            sizeClasses,
        ]"
    >
        <svg
            v-if="loading"
            class="animate-spin -ml-1 mr-2 h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <slot />
    </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    type: {
        type: String,
        default: 'button',
    },
    variant: {
        type: String,
        default: 'primary', // primary, ghost, secondary, danger
    },
    size: {
        type: String,
        default: 'md', // sm, md, lg
    },
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'ghost':
            return 'bg-transparent text-[#FFFFFF] border border-white/10 hover:bg-white/5 hover:border-white/20 active:scale-[0.98]';
        case 'secondary':
            return 'bg-[#212738] text-[#FFFFFF] border border-white/10 hover:bg-[#282F44] hover:border-white/20 active:scale-[0.98]';
        case 'danger':
            return 'bg-[#C55050]/20 text-[#FF9E9E] border border-[#C55050]/40 hover:bg-[#C55050]/30 active:scale-[0.98]';
        case 'primary':
        default:
            return 'bg-[#B9FF66] text-[#131722] hover:bg-[#a6f050] shadow-[0_0_20px_rgba(185,255,102,0.35)] active:scale-[0.98]';
    }
});

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'px-3 py-1.5 text-xs gap-1.5';
        case 'lg':
            return 'px-6 py-3.5 text-base gap-2.5 font-bold';
        case 'md':
        default:
            return 'px-4 py-2 text-sm gap-2';
    }
});
</script>
