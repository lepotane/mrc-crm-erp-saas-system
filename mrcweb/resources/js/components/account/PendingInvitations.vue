<template>
    <!--
        Bekleyen davetler şeridi : e-posta teslimi bu fazda olmadığı için
        davetlinin kabul bağlantısına (token) ulaşacağı yer burasıdır. Şerit bilerek
        ayrı bir sayfa değil — davet hangi ekranda olursan ol görünmeli.

        Derin bağlantı: /?invite=<token>
          - eşleşen davet bulunursa satır öne çıkarılır (halka),
          - bulunmazsa neden olduğu söylenir,
          - token adres çubuğunda dolaşmasın diye işlendikten sonra param düşürülür.
    -->
    <div
        v-if="showBanner"
        class="mb-5 rounded-2xl border border-[#B9FF66]/30 bg-[#B9FF66]/10 p-4 sm:p-5 shadow-[0_10px_30px_-12px_rgba(0,0,0,0.6)]"
    >
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-[#B9FF66]/15 border border-[#B9FF66]/30 flex items-center justify-center shrink-0">
                    <Mail class="w-4 h-4 text-[#B9FF66]" />
                </div>
                <div>
                    <h3 class="text-sm font-bold text-white">
                        {{ acceptedMessage ? acceptedMessage : $t('Seni bekleyen davetler var') }}
                    </h3>
                    <p v-if="!acceptedMessage" class="text-xs text-[#94A3B8] mt-0.5">
                        {{ $t('Bir firma seni çalışmaya davet etti. Kabul edince o firmanın ekibine katılırsın.') }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                @click="dismiss"
                class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer"
                :title="$t('Kapat')"
            >
                <X class="w-4 h-4" />
            </button>
        </div>

        <div v-if="visibleInvitations.length" class="space-y-2.5">
            <div
                v-for="inv in visibleInvitations"
                :key="inv.id"
                class="flex flex-wrap items-center gap-3 rounded-xl bg-[#131722]/70 border p-3.5 sm:p-4"
                :class="inv.token === highlightedToken
                    ? 'border-[#B9FF66]/60 ring-2 ring-[#B9FF66]/25'
                    : 'border-white/10'"
            >
                <div class="min-w-[200px] grow">
                    <p class="text-sm font-bold text-white">
                        {{ $t(':company firması seni :role rolüyle bekliyor.', { company: inv.company_name || '—', role: inv.role }) }}
                    </p>
                    <p class="text-[11px] text-[#94A3B8] mt-1">
                        {{ $t('Son katılım tarihi: :date', { date: formatDate(inv.expires_at) }) }}
                        <span class="text-[#64748B]">· {{ $t('Davet e-postası: :email', { email: inv.email }) }}</span>
                    </p>
                    <p v-if="inv.company_id === currentCompanyId" class="text-[11px] text-[#B9FF66] mt-1">
                        {{ $t('Şu anda zaten bu firmadasın.') }}
                    </p>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <PillButton
                        variant="primary"
                        size="sm"
                        :loading="busy === inv.token"
                        :disabled="!!busy"
                        @click="accept(inv)"
                    >
                        <Check class="w-3.5 h-3.5" />
                        {{ $t('Kabul et') }}
                    </PillButton>
                </div>
            </div>
        </div>

        <!-- kabul sonrası: sunucu bağlamı değiştirmediyse (kullanıcının başka firması
             varsa) geçiş kısayolu sunulur -->
        <div v-if="justAccepted" class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-[#131722]/70 border border-[#B9FF66]/40 p-3.5">
            <p class="text-xs text-[#CBD5E1] grow">
                {{ $t('Artık :company firmasının da üyesin. O firmada çalışmak için bağlamı değiştirebilirsin.', { company: justAccepted.company_name }) }}
            </p>
            <PillButton variant="secondary" size="sm" :loading="switching" @click="switchCompany">
                <Building2 class="w-3.5 h-3.5" />
                {{ $t('Bu firmaya geç') }}
            </PillButton>
        </div>

        <p v-if="errorText" class="mt-3 text-xs font-semibold text-[#FFA0A0]">{{ errorText }}</p>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Mail, X, Check, Building2 } from 'lucide-vue-next';
import PillButton from '../ui/PillButton.vue';
import { useAuthStore } from '../../stores/auth';
import { t } from '../../i18n';
import { formatDate } from '../../utils/format';

const authStore = useAuthStore();
const route = useRoute();
const router = useRouter();

const busy = ref('');
const switching = ref(false);
const errorText = ref('');
const justAccepted = ref(null);
const acceptedMessage = ref('');
const highlightedToken = ref('');

const currentCompanyId = computed(() => authStore.company?.id ?? null);
const visibleInvitations = computed(() => authStore.invitations);

// Şerit iki durumda durur: bekleyen davet varsa VEYA kabul yeni yapıldıysa
// (kabul sonrası liste boşalır ama onay metni ve „bu firmaya geç” kısayolu kalmalı).
const showBanner = computed(() => {
    if (authStore.invitationsDismissed) return false;
    return visibleInvitations.value.length > 0 || !!justAccepted.value || !!acceptedMessage.value;
});

function dismiss() {
    authStore.invitationsDismissed = true;
    errorText.value = '';
}

function setHighlighted(token) {
    highlightedToken.value = token || '';
}

async function accept(inv) {
    busy.value = inv.token;
    errorText.value = '';
    justAccepted.value = null;
    acceptedMessage.value = '';
    try {
        const data = await authStore.acceptInvitation(inv.token);
        const company = data?.company || {};
        // sunucu mesajı aktif dile göre çevrilmiş gelir → olduğu gibi gösterilir
        acceptedMessage.value = data?.message || t('Davet kabul edildi.');
        // Sunucu bağlamı kurduysa (kullanıcının başka firması yoksa) üst menü zaten
        // o firmayı gösterir; bağlam değişmediyse geçiş kısayolu sunuyoruz.
        justAccepted.value = company.current
            ? null
            : { company_id: company.id, company_name: company.name || inv.company_name };
        return data;
    } catch (err) {
        errorText.value = err.response?.data?.message || t('Davet kabul edilemedi.');
    } finally {
        busy.value = '';
    }
}

async function switchCompany() {
    if (!justAccepted.value?.company_id) return;
    switching.value = true;
    errorText.value = '';
    try {
        await authStore.selectCompany(justAccepted.value.company_id);
        // firma bağlamı değişti: tüm ekranların verisi yenilenmeli
        window.location.assign('/');
    } catch (err) {
        errorText.value = err.response?.data?.message || t('Firmaya geçilemedi.');
    } finally {
        switching.value = false;
    }
}

// --- Derin bağlantı: /?invite=<token> -------------------------------------
// Misafir ise router guard girişe yönlendirir ve ?redirect= içinde bu param korunur
// (Login/Register sayfaları redirect hedefine gider).
function inviteTokenFromRoute() {
    const raw = route.query.invite;
    return Array.isArray(raw) ? raw[0] : raw;
}

function applyInviteParam() {
    const token = inviteTokenFromRoute();
    if (!token) return;
    if (!authStore.invitationsLoaded) return; // liste gelmeden hüküm verme

    const match = authStore.invitations.find((i) => i.token === token);
    if (match) {
        authStore.invitationsDismissed = false;
        setHighlighted(token);
    } else {
        errorText.value = t('Bu davet senin e-postana gönderilmemiş, süresi geçmiş ya da zaten kabul edilmiş görünüyor.');
    }

    // token adres çubuğunda dolaşmasın: param işlendikten sonra düşürülür
    const query = { ...route.query };
    delete query.invite;
    router.replace({ path: route.path, query });
}

watch(() => authStore.invitationsLoaded, applyInviteParam);
watch(() => route.query.invite, applyInviteParam);
onMounted(applyInviteParam);

defineExpose({ setHighlighted });
</script>
