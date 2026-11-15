<!--
    E-posta doğrulama şeridi : doğrulanmamış hesap her ekranda görür.
    Kapatılamaz; doğrulayınca kendiliğinden kaybolur.
-->
<template>
    <div
        v-if="visible"
        class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[#DC6F31]/10 border border-[#DC6F31]/30 px-4 py-3"
    >
        <p class="text-xs text-white">
            <b>{{ $t('E-postan doğrulanmadı.') }}</b>
            {{ $t('Bazı bildirimler sana ulaşamayabilir.') }}
        </p>
        <PillButton size="sm" variant="ghost" :loading="sending" @click="resend">
            {{ sent ? $t('Gönderildi, kutunu kontrol et') : $t('Doğrulama postasını gönder') }}
        </PillButton>
        <p v-if="error" class="w-full text-[11px] text-[#FFA0A0]">{{ error }}</p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { t } from '../../i18n';
import client from '../../api/client';
import PillButton from '../ui/PillButton.vue';

const authStore = useAuthStore();
const sending = ref(false);
const sent = ref(false);
const error = ref('');

const visible = computed(() => authStore.token && authStore.user && authStore.user.email_verified === false);

async function resend() {
    sending.value = true;
    error.value = '';
    try {
        await client.post('/auth/email/verification-notification');
        sent.value = true;
    } catch (err) {
        error.value = err.response?.data?.message || t('Gönderilemedi, sonra tekrar dene.');
    } finally {
        sending.value = false;
    }
}
</script>
