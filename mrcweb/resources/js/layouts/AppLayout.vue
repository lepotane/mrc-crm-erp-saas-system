<template>
    <div class="min-h-screen bg-[#191E2C] text-white flex transition-colors duration-300">
        <!-- Slim Icon Dock (Desktop Left Rail + Mobile Bottom Bar) -->
        <AppDock />

        <!-- Sağ Üst Masaüstü Bildirim Popup Yığını (Otomatik Kaybolan 3D Bildirim Kartları) -->
        <div class="fixed top-20 right-4 sm:right-6 z-50 flex flex-col gap-3 w-80 sm:w-96 pointer-events-none">
            <transition-group name="notif-popup">
                <div
                    v-for="popup in notificationStore.popups"
                    :key="popup.popupId"
                    class="pointer-events-auto relative overflow-hidden rounded-2xl bg-[#212738]/95 backdrop-blur-xl border border-white/15 shadow-[0_20px_50px_rgba(0,0,0,0.65)] p-4 cursor-pointer group transition-transform hover:-translate-y-0.5"
                    @click="openPopupNotification(popup)"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
                            :style="{ backgroundColor: (popup.color || '#B9FF66') + '22', color: popup.color || '#B9FF66' }"
                        >
                            <BellRing class="w-4 h-4" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[#B9FF66]/15 text-[#B9FF66]">
                                    {{ $t('Yeni Bildirim') }}
                                </span>
                                <button
                                    type="button"
                                    @click.stop="notificationStore.dismissPopup(popup.popupId)"
                                    class="text-[#94A3B8] hover:text-white p-0.5 rounded-lg transition-colors"
                                >
                                    <X class="w-3.5 h-3.5" />
                                </button>
                            </div>
                            <p class="text-xs font-bold text-white mt-1.5 leading-snug">{{ popup.title }}</p>
                            <p v-if="popup.body" class="text-[11px] text-[#94A3B8] mt-0.5 line-clamp-2">{{ popup.body }}</p>
                        </div>
                    </div>
                    <!-- Otomatik kapanma ilerleme çubuğu -->
                    <div
                        class="absolute bottom-0 left-0 right-0 h-1 animate-notif-bar"
                        :style="{ backgroundColor: popup.color || '#B9FF66' }"
                    />
                </div>
            </transition-group>
        </div>

        <!-- Main Workspace -->
        <div class="flex-1 flex flex-col min-w-0 lg:pl-16 pb-16 lg:pb-0">
            <!-- Topbar / Pill Navigation -->
            <PillNav />

            <!-- Bekleyen davetler & Firma Durum Uyarıları -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8 perspective-stage overflow-x-hidden">
                <!-- Firma Durum / Lisans Süresi Uyarı Bandı -->
                <div
                    v-if="companyAlert"
                    class="mb-6 p-4 rounded-2xl flex items-center justify-between gap-4 border shadow-lg backdrop-blur-md transition-all"
                    :class="companyAlert.isSuperAdminPreview
                        ? 'bg-[#DC6F31]/15 border-[#DC6F31]/40 text-[#FDBA74]'
                        : 'bg-[#C55050]/20 border-[#C55050]/50 text-[#FF9E9E]'"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                            :class="companyAlert.isSuperAdminPreview ? 'bg-[#DC6F31]/20 text-[#DC6F31]' : 'bg-[#C55050]/30 text-[#FF9E9E]'"
                        >
                            <AlertTriangle class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-xs sm:text-sm font-black">{{ companyAlert.title }}</p>
                            <p class="text-[11px] sm:text-xs opacity-90">{{ companyAlert.message }}</p>
                        </div>
                    </div>
                    <router-link
                        v-if="authStore.user?.is_superadmin"
                        to="/yonetim-ussu"
                        class="px-3.5 py-1.5 rounded-xl bg-[#B9FF66] text-[#131722] text-xs font-black shrink-0 hover:bg-[#c8ff85] keep-white shadow-xs"
                    >
                        SüperAdmin Paneli →
                    </router-link>
                </div>

                <VerifyEmailBanner />
                <PendingInvitations />
                <router-view v-slot="{ Component, route }">
                    <transition name="page-3d" mode="out-in">
                        <div :key="route.path" class="w-full">
                            <component :is="Component" />
                        </div>
                    </transition>
                </router-view>
            </main>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { BellRing, X, AlertTriangle } from 'lucide-vue-next';
import { useAuthStore } from '../stores/auth';
import { useNotificationStore } from '../stores/notifications';
import { useTheme } from '../composables/useTheme';
import AppDock from '../components/AppDock.vue';
import PillNav from '../components/PillNav.vue';
import PendingInvitations from '../components/account/PendingInvitations.vue';
import VerifyEmailBanner from '../components/account/VerifyEmailBanner.vue';

const router = useRouter();
const authStore = useAuthStore();
const notificationStore = useNotificationStore();
useTheme();

const companyAlert = computed(() => {
    const comp = authStore.company;
    if (!comp) return null;
    const isSuper = Boolean(authStore.user?.is_superadmin);

    if (comp.approval_status === 'pending') {
        return {
            isSuperAdminPreview: isSuper,
            title: isSuper ? 'SüperAdmin Uyarısı: Firma Onay Bekliyor' : 'Firma Onay Bekliyor',
            message: isSuper
                ? 'Bu firma hesabı henüz yönetici onayından geçmemiştir. Normal personeller erişemez.'
                : 'Firma hesabınız sistem yöneticisi onayı beklemektedir. Onaylanana kadar işlem yapılamaz.',
        };
    }

    if (comp.approval_status === 'suspended' || comp.approval_status === 'rejected') {
        return {
            isSuperAdminPreview: isSuper,
            title: isSuper ? 'SüperAdmin Uyarısı: Firma Askıya Alındı / Kısıtlandı' : 'Firma Hesabı Askıya Alındı',
            message: isSuper
                ? 'Bu firmanın hesap durumu askıya alınmıştır. Normal personeller erişemez, siz SüperAdmin olarak önizliyorsunuz.'
                : 'Firma hesabınızın erişimi sınırlandırılmıştır. Lütfen yöneticiniz veya sistem yetkilisi ile iletişime geçin.',
        };
    }

    if (comp.is_expired) {
        return {
            isSuperAdminPreview: isSuper,
            title: isSuper ? 'SüperAdmin Uyarısı: Lisans Süresi Dolmuş' : 'Lisans Süreniz Doldu',
            message: isSuper
                ? 'Bu firmanın lisans/kullanım süresi dolmuştur. Normal personeller işlem yapamaz, siz SüperAdmin olarak önizliyorsunuz.'
                : 'Firmanızın kullanım süresi dolmuştur. Süre uzatımı için lütfen sistem yöneticisi ile iletişime geçin.',
        };
    }

    return null;
});

function openPopupNotification(popup) {
    notificationStore.markRead(popup.id);
    notificationStore.dismissPopup(popup.popupId);
    if (popup.action_url) {
        router.push(popup.action_url);
    }
}

onMounted(async () => {
    if (authStore.token && !authStore.user) {
        await authStore.fetchMe();
    }
    if (authStore.token && !authStore.invitationsLoaded) {
        await authStore.fetchMyInvitations();
    }
    if (authStore.token) {
        notificationStore.startPolling();
    }
});

onUnmounted(() => {
    notificationStore.stopPolling();
});
</script>
