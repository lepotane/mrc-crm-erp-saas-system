<!--
    E-posta doğrulandı : imzalı bağlantı API'de işlenir, buraya 302 atılır.
    Bağlantısız gelen (elle yazan) bilgilendirilir, işlem yapılmaz.
-->
<template>
    <div class="min-h-screen bg-[#191E2C] flex items-center justify-center p-4 select-none">
        <div class="w-full max-w-md bg-[#212738] border border-white/[0.08] rounded-3xl p-6 sm:p-8 text-center shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)]">
            <BrandMark size="lg" :showTag="true" class="mb-4 mx-auto" />
            <h1 class="text-2xl font-black text-white tracking-tight mt-2">{{ $t('E-postan doğrulandı') }}</h1>
            <p class="text-sm text-[#94A3B8] mt-2">
                {{ email ? $t('{email} artık doğrulanmış durumda.', { email }) : $t('Hesabın artık doğrulanmış durumda.') }}
            </p>
            <div class="pt-6">
                <router-link to="/login">
                    <PillButton variant="primary" size="lg" class="w-full">{{ $t('Giriş yap') }}</PillButton>
                </router-link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import BrandMark from '../components/BrandMark.vue';
import PillButton from '../components/ui/PillButton.vue';

const route = useRoute();
const email = ref('');

onMounted(() => {
    email.value = typeof route.query.email === 'string' ? route.query.email : '';
});
</script>
