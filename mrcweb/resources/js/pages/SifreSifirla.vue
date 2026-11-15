<!--
    Yeni şifre : postadaki bağlantı buraya düşer (?token=&email=).
    Bağlantı 60 dakika geçerli, tek kullanımlık; başarıda tüm oturumlar düşer.
-->
<template>
    <div class="min-h-screen bg-[#191E2C] flex items-center justify-center p-4 select-none">
        <div class="w-full max-w-md bg-[#212738] border border-white/[0.08] rounded-3xl p-6 sm:p-8 shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)]">
            <div class="flex flex-col items-center text-center mb-8">
                <BrandMark size="lg" :showTag="true" class="mb-4" />
                <h1 class="text-2xl font-black text-white tracking-tight mt-2">{{ $t('Yeni şifre belirle') }}</h1>
                <p class="text-sm text-[#94A3B8] mt-1">{{ form.email }}</p>
            </div>

            <div
                v-if="done"
                class="mb-6 p-4 rounded-xl bg-[#B9FF66]/10 border border-[#B9FF66]/25 text-[#B9FF66] text-xs leading-relaxed text-center"
            >
                <p class="font-bold mb-2">{{ $t('Şifren yenilendi.') }}</p>
                <router-link to="/login" class="underline font-semibold">{{ $t('Giriş yap') }}</router-link>
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
                        {{ $t('Yeni şifre (en az 8 karakter)') }}
                    </label>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="••••••••"
                        class="w-full px-4 py-3 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('Yeni şifre (tekrar)') }}
                    </label>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="••••••••"
                        class="w-full px-4 py-3 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    />
                </div>
                <div class="pt-2">
                    <PillButton type="submit" variant="primary" size="lg" :loading="loading" class="w-full">
                        {{ $t('Şifreyi yenile') }}
                    </PillButton>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import client from '../api/client';
import { t } from '../i18n';
import BrandMark from '../components/BrandMark.vue';
import PillButton from '../components/ui/PillButton.vue';

const route = useRoute();
const form = reactive({
    token: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const loading = ref(false);
const done = ref(false);
const errorMessage = ref('');

onMounted(() => {
    form.token = typeof route.query.token === 'string' ? route.query.token : '';
    form.email = typeof route.query.email === 'string' ? route.query.email : '';
    if (!form.token || !form.email) {
        errorMessage.value = t('Bağlantı eksik görünüyor. Postadaki düğmeyle yeniden gel.');
    }
});

async function submit() {
    loading.value = true;
    errorMessage.value = '';
    try {
        await client.post('/auth/reset-password', { ...form });
        done.value = true;
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        errorMessage.value = Object.values(bag).flat()[0]
            || err.response?.data?.message
            || t('Şifre yenilenemedi.');
    } finally {
        loading.value = false;
    }
}
</script>
