<template>
    <div class="space-y-6 select-none max-w-5xl mx-auto">
        <!-- Top Breadcrumb & Heading -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t(resolvedTitle) }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                    {{ $t(resolvedTitle) }}
                </h1>
            </div>

            <div class="flex items-center gap-3">
                <StatusPill color="lime" :dot="true">
                    {{ $t('Hazırlanıyor') }}
                </StatusPill>
                <router-link to="/">
                    <PillButton variant="ghost" size="sm">
                        &larr; {{ $t("Dashboard'a Dön") }}
                    </PillButton>
                </router-link>
            </div>
        </div>

        <!-- Main Card -->
        <div class="bg-[#212738] border border-white/[0.08] rounded-3xl p-8 sm:p-12 shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] text-center">
            <div class="max-w-md mx-auto space-y-4">
                <!-- Icon Squircle -->
                <div class="w-16 h-16 rounded-[22px] bg-[#131722] border border-white/10 flex items-center justify-center mx-auto text-[#B9FF66] shadow-glow">
                    <Sparkles class="w-8 h-8" />
                </div>

                <h2 class="text-xl font-bold text-white">
                    {{ $t(resolvedSubtitle) }}
                </h2>

                <p class="text-sm text-[#94A3B8] leading-relaxed">
                    {{ $t('Bu modül planlanan faz takvimine uygun olarak geliştirilmektedir. Arka plan veri modelleri ve API servisleri backend tarafından tamamlandığında otomatik olarak aktif hale gelecektir.') }}
                </p>

                <!-- Status details pill -->
                <div class="pt-4 flex flex-wrap items-center justify-center gap-2">
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-[#131722] border border-white/10 text-white">
                        MRC Arayüzü
                    </span>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-[#131722] border border-white/10 text-[#94A3B8]">
                        Vue 3 + Tailwind v4
                    </span>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-[#B9FF66]/10 border border-[#B9FF66]/30 text-[#B9FF66]">
                        {{ $t('SaaS Mimarisi') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { t } from '../i18n';
import { Sparkles } from 'lucide-vue-next';
import StatusPill from '../components/ui/StatusPill.vue';
import PillButton from '../components/ui/PillButton.vue';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});

const route = useRoute();

const resolvedTitle = computed(() => {
    return props.title || route.meta?.title || t('Modül');
});

const resolvedSubtitle = computed(() => {
    return props.subtitle || route.meta?.subtitle || t('Yakında kullanıma açılacaktır.');
});
</script>
