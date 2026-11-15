<!--
    Şifremi unuttum : e-posta yazılır, kayıtlıysa sıfırlama postası gider.
    Bilinmeyen e-postada da aynı mesaj görünür (kullanıcı sayımı yapılamaz).
-->
<template>
    <div class="min-h-screen bg-[#191E2C] flex items-center justify-center p-4 select-none">
        <div class="w-full max-w-md bg-[#212738] border border-white/[0.08] rounded-3xl p-6 sm:p-8 shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)]">
            <div class="flex flex-col items-center text-center mb-8">
                <BrandMark size="lg" :showTag="true" class="mb-4" />
                <h1 class="text-2xl font-black text-white tracking-tight mt-2">{{ $t('Şifreni sıfırla') }}</h1>
                <p class="text-sm text-[#94A3B8] mt-1">{{ $t('Kayıtlı e-postanı yaz, sıfırlama bağlantısı gönderelim.') }}</p>
            </div>

            <div
                v-if="done"
                class="mb-6 p-4 rounded-xl bg-[#B9FF66]/10 border border-[#B9FF66]/25 text-[#B9FF66] text-xs leading-relaxed"
            >
                {{ $t('E-posta kayıtlıysa sıfırlama bağlantısı gönderildi. Gelen kutunu (ve spam klasörünü) kontrol et.') }}
            </div>

            <div
                v-else-if="errorMessage"
                class="mb-6 p-4 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs leading-relaxed"
            >
                <p class="font-bold">{{ errorMessage }}</p>
            </div>

            <form v-if="!done" @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('E-posta Adresi') }}
                    </label>
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        :placeholder="$t('ad.soyad@firma.com')"
                        class="w-full px-4 py-3 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    />
                </div>
                <div class="pt-2">
                    <PillButton type="submit" variant="primary" size="lg" :loading="loading" class="w-full">
                        {{ $t('Bağlantı gönder') }}
                    </PillButton>
                </div>
            </form>

            <div class="mt-8 text-center text-xs text-[#94A3B8] pt-6 border-t border-white/[0.08]">
                <router-link to="/login" class="font-semibold text-[#B9FF66] hover:underline">
                    &larr; {{ $t('Girişe dön') }}
                </router-link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import client from '../api/client';
import { t } from '../i18n';
import BrandMark from '../components/BrandMark.vue';
import PillButton from '../components/ui/PillButton.vue';

const form = reactive({ email: '' });
const loading = ref(false);
const done = ref(false);
const errorMessage = ref('');

async function submit() {
    loading.value = true;
    errorMessage.value = '';
    try {
        await client.post('/auth/forgot-password', { email: form.email.trim() });
        done.value = true;
    } catch (err) {
        errorMessage.value = err.response?.data?.message || t('Bağlantı gönderilemedi, sonra tekrar dene.');
    } finally {
        loading.value = false;
    }
}
</script>
