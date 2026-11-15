<template>
    <div class="space-y-6 select-none max-w-5xl mx-auto pb-12">
        <!-- Top Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Kullanıcı Profili') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                    {{ $t('Profil & Hesap Ayarları') }}
                </h1>
            </div>

            <div class="flex items-center gap-2.5">
                <button
                    type="button"
                    @click="toggleTheme"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-full bg-[#212738] border border-white/10 text-xs font-bold text-white hover:border-[#B9FF66]/40 transition-all cursor-pointer"
                >
                    <Sun v-if="!isLight" class="w-4 h-4 text-[#B9FF66]" />
                    <Moon v-else class="w-4 h-4 text-[#131722]" />
                    <span>{{ isLight ? $t('Açık Tema (Gündüz)') : $t('Koyu Tema (Gece)') }}</span>
                </button>
            </div>
        </div>

        <!-- Feedback Banners -->
        <div
            v-if="successMsg"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-[#B9FF66] text-sm font-bold"
        >
            <Check class="w-5 h-5 shrink-0" />
            <span>{{ successMsg }}</span>
        </div>

        <div
            v-if="errorMsg"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0] text-sm font-bold"
        >
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span>{{ errorMsg }}</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Left 4 Cols: 3D Identity Card -->
            <div class="lg:col-span-4 space-y-6">
                <div class="rounded-[28px] bg-[#212738] border border-white/[0.08] p-6 text-center space-y-4 card-3d-interactive">
                    <div
                        class="w-24 h-24 rounded-full mx-auto flex items-center justify-center text-2xl font-black text-[#131722] shadow-[0_12px_30px_rgba(185,255,102,0.28)] border-4 border-white/15"
                        :style="{ backgroundColor: form.avatar_color || '#B9FF66' }"
                    >
                        {{ initials }}
                    </div>

                    <div>
                        <h2 class="text-xl font-black text-white">{{ form.name || authStore.user?.name }}</h2>
                        <p class="text-xs text-[#94A3B8] mt-0.5">{{ form.email }}</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                        <span class="px-3 py-1 rounded-full bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-[#B9FF66] text-xs font-extrabold">
                            {{ form.user_class || 'Personel' }}
                        </span>
                        <span
                            v-for="r in authStore.roles"
                            :key="r"
                            class="px-3 py-1 rounded-full bg-[#131722] border border-white/10 text-white text-xs font-bold"
                        >
                            {{ r }}
                        </span>
                    </div>

                    <!-- Avatar Color Picker -->
                    <div class="pt-4 border-t border-white/[0.08]">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-[#94A3B8] mb-2.5">
                            {{ $t('Avatar Vurgu Rengi') }}
                        </p>
                        <div class="flex items-center justify-center gap-2.5">
                            <button
                                v-for="clr in avatarColors"
                                :key="clr"
                                type="button"
                                @click="form.avatar_color = clr"
                                class="w-7 h-7 rounded-full border-2 transition-transform cursor-pointer"
                                :class="form.avatar_color === clr ? 'scale-125 border-white' : 'border-transparent opacity-80 hover:opacity-100'"
                                :style="{ backgroundColor: clr }"
                            ></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right 8 Cols: Edit Form -->
            <div class="lg:col-span-8">
                <form @submit.prevent="saveProfile" class="rounded-[28px] bg-[#212738] border border-white/[0.08] p-6 sm:p-8 space-y-5 card-3d-interactive">
                    <h3 class="text-lg font-extrabold text-white border-b border-white/[0.08] pb-3">
                        {{ $t('Kişisel Bilgiler & Kullanıcı Sınıfı') }}
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Ad Soyad') }}</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('E-posta Adresi') }}</label>
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Telefon Numarası') }}</label>
                            <input
                                v-model="form.phone"
                                type="tel"
                                placeholder="05XX XXX XX XX"
                                class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Unvan / Görev Tanımı') }}</label>
                            <input
                                v-model="form.job_title"
                                type="text"
                                :placeholder="$t('Örn: Finans Direktörü')"
                                class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Kullanıcı Sınıfı') }}</label>
                            <select
                                v-model="form.user_class"
                                class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            >
                                <option value="Yönetici">{{ $t('Yönetici / Admin') }}</option>
                                <option value="Kıdemli Muhasebe Uzmanı">{{ $t('Kıdemli Muhasebe Uzmanı') }}</option>
                                <option value="Satış Temsilcisi">{{ $t('Satış & CRM Temsilcisi') }}</option>
                                <option value="Depo Sorumlusu">{{ $t('Depo & Stok Sorumlusu') }}</option>
                                <option value="Personel">{{ $t('Standart Personel') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Adres') }}</label>
                            <input
                                v-model="form.address"
                                type="text"
                                :placeholder="$t('Şehir / İlçe / Açık Adres')"
                                class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            />
                        </div>
                    </div>

                    <div class="pt-4 border-t border-white/[0.08] space-y-4">
                        <h4 class="text-sm font-extrabold text-white">
                            {{ $t('Şifre Değiştir (İsteğe Bağlı)') }}
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Mevcut Şifre') }}</label>
                                <input
                                    v-model="form.current_password"
                                    type="password"
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                    class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-[#94A3B8] mb-1.5">{{ $t('Yeni Şifre') }}</label>
                                <input
                                    v-model="form.new_password"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="••••••••"
                                    class="w-full px-4 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3">
                        <PillButton type="submit" variant="primary" size="lg" :loading="saving">
                            {{ $t('Profili Güncelle') }}
                        </PillButton>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useTheme } from '../composables/useTheme';
import client from '../api/client';
import { t } from '../i18n';
import PillButton from '../components/ui/PillButton.vue';
import { Sun, Moon, Check, AlertCircle } from 'lucide-vue-next';

const authStore = useAuthStore();
const { isLight, toggleTheme } = useTheme();

const avatarColors = ['#B9FF66', '#4F46E5', '#E06C28', '#338F65', '#A855F7', '#2B92A4', '#C55050'];

const form = reactive({
    name: '',
    email: '',
    phone: '',
    address: '',
    job_title: '',
    user_class: 'Personel',
    avatar_color: '#B9FF66',
    current_password: '',
    new_password: '',
});

const saving = ref(false);
const successMsg = ref('');
const errorMsg = ref('');

const initials = computed(() => {
    const n = (form.name || authStore.user?.name || 'MR').trim();
    const parts = n.split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

function syncFromUser(u) {
    if (!u) return;
    form.name = u.name || '';
    form.email = u.email || '';
    form.phone = u.phone || '';
    form.address = u.address || '';
    form.job_title = u.job_title || '';
    form.user_class = u.user_class || 'Personel';
    form.avatar_color = u.avatar_color || '#B9FF66';
}

onMounted(async () => {
    if (!authStore.user) {
        await authStore.fetchMe();
    }
    syncFromUser(authStore.user);
});

async function saveProfile() {
    saving.value = true;
    successMsg.value = '';
    errorMsg.value = '';

    try {
        const payload = {
            name: form.name,
            email: form.email,
            phone: form.phone,
            address: form.address,
            job_title: form.job_title,
            user_class: form.user_class,
            avatar_color: form.avatar_color,
        };
        if (form.new_password) {
            payload.current_password = form.current_password;
            payload.new_password = form.new_password;
        }

        const { data } = await client.patch('/me/profile', payload);
        if (data?.user) {
            authStore.user = { ...authStore.user, ...data.user };
            syncFromUser(authStore.user);
        }
        form.current_password = '';
        form.new_password = '';
        successMsg.value = t('Profil bilgileriniz başarıyla güncellendi.');
    } catch (err) {
        errorMsg.value = err.response?.data?.message || t('Profil güncellenirken bir hata oluştu.');
    } finally {
        saving.value = false;
    }
}
</script>
