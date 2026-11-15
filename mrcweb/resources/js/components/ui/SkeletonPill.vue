<template>
    <div
        :class="[
            'animate-pulse bg-white/10 rounded-full',
            widthClass,
            heightClass,
        ]"
    />
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    width: {
        type: String,
        default: 'w-24',
    },
    height: {
        type: String,
        default: 'h-6',
    },
});

const widthClass = computed(() => props.width);
const heightClass = computed(() => props.height);
</script>
