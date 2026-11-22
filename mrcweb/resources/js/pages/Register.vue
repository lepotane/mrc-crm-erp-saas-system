<template>
    <div
        class="min-h-screen flex items-center justify-center p-4 select-none py-12 transition-colors duration-300"
        :class="isLight ? 'bg-[#EEF2F6] text-[#0F172A]' : 'bg-[#191E2C] text-white'"
    >
        <div
            class="w-full max-w-md rounded-3xl p-6 sm:p-8 border transition-colors"
            :class="isLight
                ? 'bg-white border-slate-200 shadow-[0_20px_50px_rgba(15,23,42,0.08)]'
                : 'bg-[#212738] border-white/[0.08] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)]'"
        >
            <!-- Header Brand -->
            <div class="flex flex-col items-center text-center mb-6">
                <BrandMark size="lg" :showTag="true" class="mb-3" />
                <h1 class="text-2xl font-black tracking-tight mt-2" :class="isLight ? 'text-slate-900' : 'text-white'">
                    {{ $t('Firma Kaydı Oluşturun') }}
                </h1>
                <p class="text-sm text-[#94A3B8] mt-1">
                    {{ $t('Firma kurulum talebiniz yönetici onayından sonra aktifleşir.') }}
                </p>
            </div>

            <!-- Başarılı Talep Bildirimi -->
            <div
                v-if="pendingApprovalMessage"
                class="mb-6 p-5 rounded-2xl bg-[#4E956A]/15 border border-[#B9FF66]/40 text-center space-y-3"
            >
                <div class="w-12 h-12 rounded-2xl bg-[#B9FF66]/20 border border-[#B9FF66]/40 flex items-center justify-center mx-auto text-[#B9FF66] font-black text-xl">
                    ✓
                </div>
                <p class="text-sm font-black" :class="isLight ? 'text-emerald-800' : 'text-[#B9FF66]'">
                    {{ $t('Firma Kurulum Talebiniz Alındı') }}
                </p>
                <p class="text-xs leading-relaxed" :class="isLight ? 'text-slate-600' : 'text-[#94A3B8]'">
                    {{ pendingApprovalMessage }}
                </p>
                <router-link
                    to="/login"
                    class="inline-flex items-center justify-center px-5 py-2.5 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-extrabold hover:brightness-105 transition-all"
                >
                    {{ $t('Giriş Ekranına Dön') }}
                </router-link>
            </div>

            <!-- Error Banner -->
            <div
                v-if="errorMessage"
                class="mb-6 p-4 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs leading-relaxed"
            >
                <p class="font-bold mb-1">{{ errorMessage }}</p>
                <ul v-if="validationErrors.length" class="list-disc list-inside space-y-0.5 mt-1 text-[#FFA0A0]/90">
                    <li v-for="(err, idx) in validationErrors" :key="idx">{{ err }}</li>
                </ul>
            </div>

            <!-- Form -->
            <form v-if="!pendingApprovalMessage" @submit.prevent="handleRegister" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('Adınız & Soyadınız') }}
                    </label>
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        autocomplete="name"
                        :placeholder="$t('Örn: Ahmet Yılmaz')"
                        class="w-full px-4 py-2.5 border rounded-xl text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        :class="isLight ? 'bg-slate-50 border-slate-300 text-slate-900' : 'bg-[#131722] border-white/10 text-white'"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('E-posta Adresi') }}
                    </label>
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        :placeholder="$t('ahmet@sirketiniz.com')"
                        class="w-full px-4 py-2.5 border rounded-xl text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        :class="isLight ? 'bg-slate-50 border-slate-300 text-slate-900' : 'bg-[#131722] border-white/10 text-white'"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('Firma Adı') }}
                    </label>
                    <input
                        v-model="form.company_name"
                        type="text"
                        required
                        :placeholder="$t('Örn: Örnek Şirket Ltd.')"
                        class="w-full px-4 py-2.5 border rounded-xl text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        :class="isLight ? 'bg-slate-50 border-slate-300 text-slate-900' : 'bg-[#131722] border-white/10 text-white'"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('Şifre') }}
                    </label>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="$t('Minimum 8 karakter')"
                        class="w-full px-4 py-2.5 border rounded-xl text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        :class="isLight ? 'bg-slate-50 border-slate-300 text-slate-900' : 'bg-[#131722] border-white/10 text-white'"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#94A3B8] uppercase tracking-wider mb-1.5">
                        {{ $t('Şifre Tekrarı') }}
                    </label>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="$t('Şifrenizi tekrar girin')"
                        class="w-full px-4 py-2.5 border rounded-xl text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                        :class="isLight ? 'bg-slate-50 border-slate-300 text-slate-900' : 'bg-[#131722] border-white/10 text-white'"
                    />
                </div>

                <div class="pt-2">
                    <PillButton
                        type="submit"
                        variant="primary"
                        size="lg"
                        :loading="loading"
                        class="w-full"
                    >
                        {{ $t('Firmamı oluştur') }}
                    </PillButton>
                </div>
            </form>

            <!-- Footer Switch -->
            <div class="mt-6 text-center text-xs text-[#94A3B8] pt-4 border-t" :class="isLight ? 'border-slate-200' : 'border-white/[0.08]'">
                {{ $t('Zaten hesabınız var mı?') }}
                <router-link
                    to="/login"
                    class="font-semibold text-[#B9FF66] hover:underline ml-1 inline-flex items-center gap-0.5"
                >
                    {{ $t('Giriş yapın') }} &rarr;
                </router-link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useTheme } from '../composables/useTheme';
import client from '../api/client';
import { t } from '../i18n';
import BrandMark from '../components/BrandMark.vue';
import PillButton from '../components/ui/PillButton.vue';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const { isLight } = useTheme();

function redirectTarget() {
    const raw = route.query.redirect;
    const path = Array.isArray(raw) ? raw[0] : raw;
    if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) return '/';
    return path;
}

const form = reactive({
    name: '',
    email: '',
    company_name: '',
    password: '',
    password_confirmation: '',
});

const loading = ref(false);
const errorMessage = ref('');
const errorBag = ref({});
const pendingApprovalMessage = ref('');

const validationErrors = computed(() => {
    if (!errorBag.value) return [];
    return Object.values(errorBag.value).flat();
});

async function handleRegister() {
    if (form.password !== form.password_confirmation) {
        errorMessage.value = t('Şifre ve şifre tekrarı uyuşmuyor.');
        return;
    }

    loading.value = true;
    errorMessage.value = '';
    errorBag.value = {};
    pendingApprovalMessage.value = '';

    try {
        const target = redirectTarget();
        const isInviteRedirect = target.includes('invite=');
        const res = await client.post('/auth/register', {
            name: form.name,
            email: form.email,
            company_name: form.company_name,
            password: form.password,
            require_approval: !isInviteRedirect,
        });

        if (res.data?.pending_approval) {
            pendingApprovalMessage.value = res.data.message || t('Firma kurulum talebiniz alındı. Yönetici onayından sonra hesabınız aktifleşecektir.');
            return;
        }

        if (res.data?.token) {
            authStore.token = res.data.token;
            authStore.user = res.data.user;
            authStore.company = res.data.company;
            localStorage.setItem('mrc_token', res.data.token);
            if (res.data.company?.id) {
                authStore.setCompanyId(res.data.company.id);
            }
            router.push(target);
        }
    } catch (err) {
        if (err.response?.status === 422) {
            errorMessage.value = err.response.data.message || t('Lütfen form hatalarını düzeltin.');
            errorBag.value = err.response.data.errors || {};
        } else {
            errorMessage.value = err.response?.data?.message || t('Kayıt işlemi sırasında bir hata oluştu.');
        }
    } finally {
        loading.value = false;
    }
}
</script>
