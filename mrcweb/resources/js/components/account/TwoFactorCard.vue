<!--
    İki faktörlü giriş : kullanıcı kendi hesabını korur.
    Akış: Kur → telefona ekle → kodla onayla → yedek kodları sakla.
    Sır onaylanmadan 2FA ETKİN DEĞİLDİR (kilitlenme tuzağı yok).
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <ShieldCheck class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('İki Faktörlü Giriş') }}</h2>
                    <p class="text-xs text-[#94A3B8]">
                        {{ $t('Şifrenin üstüne telefon kodu: hesabın çalınan şifreyle açılamaz.') }}
                    </p>
                </div>
            </div>
            <StatusPill v-if="!loading" :color="enabled ? 'lime' : 'neutral'" :dot="false">
                {{ enabled ? $t('Etkin') : $t('Kapalı') }}
            </StatusPill>
        </div>

        <div v-if="loading" class="h-16 bg-[#131722]/60 rounded-xl animate-pulse"></div>

        <template v-else>
            <p v-if="error" class="mb-3 text-xs font-semibold text-[#FFA0A0]">{{ error }}</p>
            <p v-if="notice" class="mb-3 text-xs font-semibold text-[#B9FF66]">{{ notice }}</p>

            <!-- ETKİN: kapatma + yedek kod yenileme -->
            <template v-if="enabled && !settingUp">
                <p class="text-xs text-[#94A3B8] leading-relaxed">
                    {{ $t('Girişte şifrenden sonra telefonundaki 6 haneli kod istenir. Telefonun yanınde değilse yedek kodlarından birini kullanabilirsin (her biri tek kullanımlık).') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <PillButton size="sm" variant="secondary" @click="regenerating = true; passwordForm.password = ''">
                        {{ $t('Yedek kodları yenile') }}
                    </PillButton>
                    <PillButton size="sm" variant="ghost" @click="confirming_disable = true; passwordForm.password = ''">
                        {{ $t('İki faktörü kapat') }}
                    </PillButton>
                </div>
            </template>

            <!-- KURULUM: sır + onay -->
            <template v-else-if="settingUp">
                <ol class="text-xs text-[#94A3B8] space-y-2 list-decimal list-inside leading-relaxed">
                    <li>{{ $t('Telefonundaki doğrulama uygulamasında (Google/Microsoft Authenticator vb.) „+ Ekle" → „Kurulum anahtarını gir" de.') }}</li>
                    <li>
                        {{ $t('Alttaki gizli anahtarı aynen yaz:') }}
                        <span class="flex items-center gap-2 mt-1">
                            <code class="font-mono text-sm text-white bg-[#0F1420] border border-white/10 rounded-lg px-2 py-1 break-all">{{ secret }}</code>
                            <PillButton size="sm" variant="ghost" @click="copySecret">
                                <Copy class="w-3.5 h-3.5" /> {{ copied ? $t('Kopyalandı') : $t('Kopyala') }}
                            </PillButton>
                        </span>
                    </li>
                    <li>{{ $t('Uygulamada beliren 6 haneli güncel kodu altta onayla.') }}</li>
                </ol>
                <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="confirm">
                    <label class="block">
                        <span class="text-[11px] font-bold text-[#94A3B8]">{{ $t('Doğrulama kodu') }}</span>
                        <input
                            v-model="confirmCode"
                            type="text"
                            inputmode="numeric"
                            maxlength="16"
                            placeholder="123 456"
                            class="mt-1 w-44 px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white text-center tracking-[0.3em] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                        />
                    </label>
                    <PillButton size="sm" :loading="confirming" @click="confirm" type="button">
                        {{ $t('Onayla ve etkinleştir') }}
                    </PillButton>
                    <PillButton size="sm" variant="ghost" type="button" @click="settingUp = false">
                        {{ $t('Vazgeç') }}
                    </PillButton>
                </form>
            </template>

            <!-- KAPALI: kurulumu başlat -->
            <template v-else>
                <p v-if="pending" class="mb-2 text-[11px] text-[#FFAE7C]">
                    {{ $t('Yarım kalmış bir kurulum var (sayfa yenilenmiş olabilir). Yeni kodla devam etmek için Kuruluma başla — eski kod geçersizdir.') }}
                </p>
                <p class="text-xs text-[#94A3B8] leading-relaxed">
                    {{ $t('Etkinleştirince girişte şifrenden sonra telefonundaki kod da istenir. Kurulum 1 dakika sürer.') }}
                </p>
                <div class="mt-3">
                    <PillButton size="sm" :loading="starting" @click="start">
                        {{ $t('Kuruluma başla') }}
                    </PillButton>
                </div>
            </template>

            <!-- YEDEK KODLAR (bir kez gösterilir) -->
            <div v-if="recoveryCodes.length" class="mt-4 p-3 rounded-2xl bg-[#B9FF66]/10 border border-[#B9FF66]/25">
                <p class="text-xs font-bold text-[#B9FF66] mb-2">
                    {{ $t('Yedek kodların (bir kez gösterilir — güvenli bir yere yaz):') }}
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                    <code v-for="c in recoveryCodes" :key="c" class="font-mono text-xs text-white bg-[#0F1420] border border-white/10 rounded-lg px-2 py-1 text-center">{{ c }}</code>
                </div>
                <div class="mt-2">
                    <PillButton size="sm" variant="ghost" @click="copyCodes">
                        <Copy class="w-3.5 h-3.5" /> {{ copied ? $t('Kopyalandı') : $t('Tümünü kopyala') }}
                    </PillButton>
                </div>
            </div>

            <!-- ŞİFRE ONAYI (kapatma / yenileme) -->
            <form v-if="confirming_disable || regenerating" class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="confirmPassword">
                <label class="block">
                    <span class="text-[11px] font-bold text-[#94A3B8]">
                        {{ confirming_disable ? $t('Kapatmayı onayla — şifren:') : $t('Yenilemeyi onayla — şifren:') }}
                    </span>
                    <input
                        v-model="passwordForm.password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="••••••••"
                        class="mt-1 w-56 px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                    />
                </label>
                <PillButton size="sm" :loading="passwordBusy" @click="confirmPassword" type="button">
                    {{ $t('Onayla') }}
                </PillButton>
                <PillButton size="sm" variant="ghost" type="button" @click="confirming_disable = false; regenerating = false">
                    {{ $t('Vazgeç') }}
                </PillButton>
            </form>
        </template>
    </Card>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ShieldCheck, Copy } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();

const loading = ref(true);
const enabled = ref(false);
const pending = ref(false);
const settingUp = ref(false);
const secret = ref('');
const confirmCode = ref('');
const starting = ref(false);
const confirming = ref(false);
const recoveryCodes = ref([]);
const copied = ref(false);
const error = ref('');
const notice = ref('');
const confirming_disable = ref(false);
const regenerating = ref(false);
const passwordBusy = ref(false);
const passwordForm = reactive({ password: '' });

function fail(err, fallback) {
    const bag = err.response?.data?.errors || {};
    error.value = Object.values(bag).flat()[0]
        || err.response?.data?.message
        || fallback;
    notice.value = '';
}

async function refresh() {
    loading.value = true;
    error.value = '';
    try {
        const res = await client.get('/me');
        enabled.value = !!res.data?.user?.two_factor_enabled;
        pending.value = !!res.data?.user?.two_factor_pending;
        if (authStore.user) authStore.user.two_factor_enabled = enabled.value;
    } catch (err) {
        error.value = err.response?.data?.message || t('İki faktör durumu yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function start() {
    starting.value = true;
    error.value = '';
    notice.value = '';
    recoveryCodes.value = [];
    try {
        const res = await client.post('/me/2fa/setup');
        secret.value = res.data?.secret || '';
        settingUp.value = true;
        pending.value = false;
        confirmCode.value = '';
    } catch (err) {
        fail(err, t('Kurulum başlatılamadı.'));
    } finally {
        starting.value = false;
    }
}

async function confirm() {
    if (!confirmCode.value.trim() || confirming.value) return;
    confirming.value = true;
    error.value = '';
    try {
        const res = await client.post('/me/2fa/confirm', { code: confirmCode.value.trim() });
        recoveryCodes.value = res.data?.recovery_codes || [];
        enabled.value = true;
        settingUp.value = false;
        if (authStore.user) authStore.user.two_factor_enabled = true;
        notice.value = res.data?.message || '';
    } catch (err) {
        fail(err, t('Kod onaylanamadı.'));
    } finally {
        confirming.value = false;
    }
}

async function confirmPassword() {
    if (!passwordForm.password || passwordBusy.value) return;
    passwordBusy.value = true;
    error.value = '';
    try {
        if (confirming_disable.value) {
            await client.post('/me/2fa/disable', { password: passwordForm.password });
            enabled.value = false;
            if (authStore.user) authStore.user.two_factor_enabled = false;
            notice.value = t('İki faktörlü giriş kapatıldı.');
        } else {
            const res = await client.post('/me/2fa/recovery-codes', { password: passwordForm.password });
            recoveryCodes.value = res.data?.recovery_codes || [];
            notice.value = res.data?.message || '';
        }
        confirming_disable.value = false;
        regenerating.value = false;
        passwordForm.password = '';
    } catch (err) {
        fail(err, t('Onaylanamadı.'));
    } finally {
        passwordBusy.value = false;
    }
}

async function copySecret() {
    try {
        await navigator.clipboard.writeText(secret.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch { /* pano yoksa kullanıcı elle kopyalar */ }
}

async function copyCodes() {
    try {
        await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch { /* pano yoksa kullanıcı elle kopyalar */ }
}

onMounted(refresh);

defineExpose({ refresh });
</script>
