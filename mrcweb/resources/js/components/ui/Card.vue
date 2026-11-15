<template>
    <div
        :class="[
            'bg-[#212738] border border-white/[0.08] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] transition-all duration-200',
            interactive ? 'hover:bg-[#282F44] hover:border-white/[0.16] hover:-translate-y-0.5 cursor-pointer' : '',
            roundedClass,
            paddingClass,
        ]"
    >
        <slot />
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    interactive: {
        type: Boolean,
        default: false,
    },
    rounded: {
        type: String,
        default: '2xl', // 'xl', '2xl', '3xl'
    },
    padding: {
        type: String,
        default: 'md', // 'none', 'sm', 'md', 'lg'
    },
});

const roundedClass = computed(() => {
    switch (props.rounded) {
        case 'xl':
            return 'rounded-xl';
        case '3xl':
            return 'rounded-3xl';
        case '2xl':
        default:
            return 'rounded-2xl';
    }
});

const paddingClass = computed(() => {
    switch (props.padding) {
        case 'none':
            return 'p-0';
        case 'sm':
            return 'p-3 sm:p-4';
        case 'lg':
            return 'p-6 sm:p-8';
        case 'md':
        default:
            return 'p-4 sm:p-6';
    }
});
</script>
