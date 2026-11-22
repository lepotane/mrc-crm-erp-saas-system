<!-- Çevrimdışı taslak listesi. Yeniden deneme gerçek işini App.vue
     'pwaRetryDraft' anahtarıyla provide eder; burada yalnızca tetiklenir. -->
<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[110] bg-black/70 backdrop-blur-sm flex items-end sm:items-center justify-center p-4"
            @click.self="$emit('close')"
        >
            <div class="w-full max-w-lg rounded-3xl bg-[#212738] border border-white/10 shadow-[0_24px_70px_rgba(0,0,0,0.6)] overflow-hidden">
                <header class="flex items-center justify-between gap-3 px-5 py-4 border-b border-white/[0.08]">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-xl bg-[#B9FF66]/15 text-[#B9FF66] flex items-center justify-center shrink-0">
                            <FileClock class="w-5 h-5" />
                        </div>
                        <h2 class="text-sm font-black text-white truncate">{{ $t('Çevrimdışı Taslaklar') }}</h2>
                    </div>
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full bg-white/5 text-[#94A3B8] hover:text-white flex items-center justify-center"
                        :title="$t('Kapat')"
                        @click="$emit('close')"
                    >
                        <X class="w-4 h-4" />
                    </button>
                </header>

                <div class="p-4 sm:p-5 max-h-[60vh] overflow-y-auto space-y-2">
                    <p v-if="!drafts.length" class="py-8 text-center text-sm text-[#94A3B8]">
                        {{ $t('Kayıtlı taslak yok.') }}
                    </p>

                    <div
                        v-for="draft in drafts"
                        :key="draft.id"
                        class="p-3 rounded-2xl bg-[#191E2C] border border-white/[0.08]"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-white truncate">{{ draftTitle(draft) }}</p>
                                <p class="text-[11px] text-[#64748B] mt-0.5">{{ formatDate(draft.saved_at) }}</p>
                            </div>
                            <span class="shrink-0 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[#DC6F31]/15 text-[#FDBA74]">
                                {{ $t('Taslak') }}
                            </span>
                        </div>

                        <p v-if="noticeId === draft.id && notice" class="mt-2 text-xs font-semibold text-[#B9FF66]">
                            {{ notice }}
                        </p>

                        <div class="flex gap-2 mt-3">
                            <PillButton
                                size="sm"
                                variant="primary"
                                :loading="busyId === draft.id"
                                @click="retry(draft)"
                            >
                                {{ $t('Yeniden dene') }}
                            </PillButton>
                            <PillButton size="sm" variant="danger" @click="$emit('remove', draft)">
                                {{ $t('Sil') }}
                            </PillButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, inject } from 'vue';
import { X, FileClock } from 'lucide-vue-next';
import PillButton from '../ui/PillButton.vue';
import { t } from '../../i18n';

defineProps({
    open: { type: Boolean, default: false },
    drafts: { type: Array, default: () => [] },
});

defineEmits(['close', 'remove']);

const retryDraft = inject('pwaRetryDraft', null);
const busyId = ref(null);
const noticeId = ref('');
const notice = ref('');

function draftTitle(draft) {
    const data = draft?.data || {};
    return data.title || data.name || draft?.key || t('Taslak');
}

function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
}

async function retry(draft) {
    if (typeof retryDraft !== 'function') return;
    busyId.value = draft.id;
    noticeId.value = draft.id;
    notice.value = '';
    try {
        const result = await retryDraft(draft);
        notice.value = result?.message || '';
    } finally {
        busyId.value = null;
    }
}
</script>
