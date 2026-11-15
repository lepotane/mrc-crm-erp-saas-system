<!--
    API erişim anahtarları . Masaüstü istemci ve entegrasyonlar bu anahtarlarla
    girer; anahtar burada ÜRETİLİR ve bir daha gösterilmez → kaybolursa yenisi açılır.
    Yetenekler: tam yetki (*), salt okuma (read → yalnız GET), yazma (write).
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <Key class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('API Erişim Anahtarları') }}</h2>
                    <p class="text-xs text-[#94A3B8]">
                        {{ $t('Masaüstü uygulaması ve entegrasyonlar bu anahtarlarla bağlanır. Anahtar yalnız üretildiği an gösterilir.') }}
                    </p>
                </div>
            </div>
            <div class="hidden sm:flex items-center gap-2 text-xs text-[#94A3B8]">
                <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                <span v-else>{{ $t(':count anahtar', { count: tokens.length }) }}</span>
            </div>
        </div>

        <!-- yeni anahtar üretildi: bir kez gösterilen düz metin -->
        <div
            v-if="createdPlain"
            class="mb-5 p-4 rounded-2xl bg-[#B9FF66]/10 border border-[#B9FF66]/40"
        >
            <p class="text-xs font-bold text-[#B9FF66] mb-2">
                {{ $t('Anahtar hazır — bir daha gösterilmeyecek. Şimdi kopyalayıp masaüstü uygulamasına yapıştırın.') }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code class="px-3 py-2 rounded-xl bg-[#131722] border border-white/10 text-xs text-white break-all grow min-w-[240px]">{{ createdPlain }}</code>
                <PillButton variant="secondary" size="sm" @click="copyPlain">
                    <Copy class="w-3.5 h-3.5" />
                    {{ copied ? $t('Kopyalandı') : $t('Kopyala') }}
                </PillButton>
                <PillButton variant="ghost" size="sm" @click="createdPlain = ''">{{ $t('Kapat') }}</PillButton>
            </div>
        </div>

        <!-- üretme formu -->
        <div class="flex flex-wrap items-end gap-3 mb-5">
            <div class="flex flex-col gap-1 grow min-w-[220px]">
                <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Anahtar adı') }}</label>
                <input
                    v-model="form.name"
                    type="text"
                    :placeholder="$t('örn. Masaüstü kasa, Rapor robotu')"
                    class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-[#94A3B8] font-semibold">{{ $t('Yetki') }}</label>
                <select
                    v-model="form.ability"
                    class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer"
                >
                    <option v-for="a in abilityOptions" :key="a.value" :value="a.value">{{ $t(a.key) }}</option>
                </select>
            </div>
            <PillButton variant="primary" size="sm" :loading="creating" :disabled="!form.name.trim()" @click="createToken">
                <Plus class="w-3.5 h-3.5" />
                {{ $t('Anahtar üret') }}
            </PillButton>
        </div>
        <p v-if="formError" class="mb-4 text-xs font-semibold text-[#FFA0A0]">{{ formError }}</p>
        <p v-if="notice" class="mb-4 text-xs font-semibold text-[#B9FF66]">{{ notice }}</p>

        <!-- : diğer oturumları kapat (şu anki korunur) -->
        <div v-if="!loading && tokens.length > 1" class="flex flex-wrap items-center gap-2 mb-5">
            <template v-if="confirmOthers">
                <PillButton size="sm" variant="primary" :loading="revokingOthers" @click="revokeOthers">
                    {{ $t('Evet, diğerlerini kapat') }}
                </PillButton>
                <PillButton size="sm" variant="ghost" @click="confirmOthers = false">{{ $t('Vazgeç') }}</PillButton>
            </template>
            <PillButton v-else size="sm" variant="ghost" @click="revokeOthers">
                <LogOut class="w-3.5 h-3.5" />
                {{ $t('Diğer oturumları kapat') }}
            </PillButton>
        </div>

        <!-- liste -->
        <div v-if="loading" class="space-y-2 py-3">
            <div v-for="i in 3" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>
        <div v-else-if="tokens.length === 0" class="py-8 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
            {{ $t('Henüz erişim anahtarı yok.') }}
        </div>
        <div v-else class="space-y-2.5">
            <div
                v-for="tk in tokens"
                :key="tk.id"
                class="flex flex-wrap items-center gap-3 rounded-xl bg-[#131722]/70 border border-white/10 p-3.5"
            >
                <div class="min-w-[220px] grow">
                    <p class="text-sm font-bold text-white">
                        {{ tk.kind === 'session' ? $t('Giriş oturumu') : (tk.name || '—') }}
                    </p>
                    <p class="text-[11px] text-[#94A3B8] mt-1">
                        {{ $t('Oluşturulma: :date', { date: formatDate(tk.created_at) }) }}
                        <span v-if="tk.last_used_at">· {{ $t('Son kullanım: :date', { date: formatDate(tk.last_used_at) }) }}</span>
                        <span v-else>· {{ $t('Hiç kullanılmadı') }}</span>
                        <span v-if="tk.expires_at && isExpired(tk.expires_at)" class="text-[#FFA0A0]">· {{ $t('Süresi doldu') }}</span>
                        <span v-else-if="tk.expires_at">· {{ $t('Geçerlilik: :date', { date: formatDate(tk.expires_at) }) }}</span>
                    </p>
                    <!-- : hangi cihazdan girildiği (IP + tarayıcı etiketi) -->
                    <p class="text-[11px] text-[#64748B] mt-0.5">
                        {{ tk.device || $t('Bilinmeyen cihaz') }}<span v-if="tk.ip_address"> · {{ tk.ip_address }}</span>
                    </p>
                </div>

                <template v-if="tk.kind === 'session'">
                    <StatusPill color="neutral" :dot="false">{{ $t('Tarayıcı oturumu') }}</StatusPill>
                </template>
                <StatusPill v-else :color="abilityColor(tk.abilities)" :dot="false">{{ abilityLabel(tk.abilities) }}</StatusPill>

                <div class="flex items-center gap-2 shrink-0">
                    <!-- şu an kullanılan anahtar iptal edilemez (kullanıcı kendi oturumunu kapatır) -->
                    <StatusPill v-if="tk.current" color="lime" :dot="false">{{ $t('Şu anki oturum') }}</StatusPill>
                    <template v-else-if="tk.kind !== 'session'">
                        <template v-if="confirmId === tk.id">
                            <PillButton variant="primary" size="sm" :loading="revoking === tk.id" @click="revoke(tk)">
                                {{ $t('Evet, iptal et') }}
                            </PillButton>
                            <PillButton variant="ghost" size="sm" @click="confirmId = null">{{ $t('Vazgeç') }}</PillButton>
                        </template>
                        <PillButton v-else variant="ghost" size="sm" @click="confirmId = tk.id">
                            <Trash2 class="w-3.5 h-3.5" />
                            {{ $t('İptal et') }}
                        </PillButton>
                    </template>
                </div>
            </div>
        </div>

        <p class="mt-4 text-[11px] text-[#64748B]">
            {{ $t('Salt okuma anahtarı yalnız görüntüleme yapabilir; kayıt yazmaya çalışırsa reddedilir. Anahtarını kaybettiğin kişi için yeni anahtar üret, eskisini iptal et.') }}
        </p>
    </Card>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Key, Plus, Copy, Trash2, LogOut } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import SkeletonPill from '../ui/SkeletonPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';
import { formatDate } from '../../utils/format';

const tokens = ref([]);
const loading = ref(true);
const creating = ref(false);
const revoking = ref('');
const revokingOthers = ref(false);
const confirmId = ref(null);
const confirmOthers = ref(false);
const formError = ref('');
const notice = ref('');
const createdPlain = ref('');
const copied = ref(false);

const form = reactive({ name: '', ability: '*' });

const abilityOptions = [
    { value: '*', key: 'Tam yetki' },
    { value: 'read', key: 'Salt okuma' },
    { value: 'write', key: 'Yazma yetkisi' },
];

function abilityLabel(abilities) {
    const a = abilities?.[0] ?? '*';
    if (a === '*') return t('Tam yetki');
    if (a === 'read') return t('Salt okuma');
    return t('Yazma yetkisi');
}

function abilityColor(abilities) {
    const a = abilities?.[0] ?? '*';
    return a === 'read' ? 'cyan' : a === 'write' ? 'forest' : 'coral';
}

function isExpired(iso) {
    return !!iso && new Date(iso).getTime() < Date.now();
}

async function fetchTokens() {
    loading.value = true;
    try {
        const res = await client.get('/me/tokens');
        tokens.value = res.data?.tokens || [];
    } catch (err) {
        formError.value = err.response?.data?.message || t('Erişim anahtarları yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function createToken() {
    if (!form.name.trim() || creating.value) return;
    creating.value = true;
    formError.value = '';
    createdPlain.value = '';
    copied.value = false;
    try {
        const res = await client.post('/me/tokens', {
            name: form.name.trim(),
            abilities: [form.ability],
        });
        createdPlain.value = res.data?.plain_text_token || '';
        form.name = '';
        await fetchTokens();
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        const first = Object.values(bag).flat()[0];
        formError.value = first || err.response?.data?.message || t('Anahtar üretilemedi.');
    } finally {
        creating.value = false;
    }
}

async function revoke(tk) {
    revoking.value = tk.id;
    formError.value = '';
    try {
        await client.delete(`/me/tokens/${tk.id}`);
        confirmId.value = null;
        await fetchTokens();
    } catch (err) {
        formError.value = err.response?.data?.message || t('Anahtar iptal edilemedi.');
    } finally {
        revoking.value = '';
    }
}

/** Şu anki oturum dışındakileri kapat . */
async function revokeOthers() {
    if (!confirmOthers.value) {
        confirmOthers.value = true;
        return;
    }
    revokingOthers.value = true;
    formError.value = '';
    notice.value = '';
    try {
        const res = await client.delete('/me/tokens/others');
        confirmOthers.value = false;
        notice.value = res.data?.message || '';
        await fetchTokens();
    } catch (err) {
        formError.value = err.response?.data?.message || t('Oturumlar kapatılamadı.');
    } finally {
        revokingOthers.value = false;
    }
}

async function copyPlain() {
    try {
        await navigator.clipboard.writeText(createdPlain.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        formError.value = t('Panoya kopyalanamadı; anahtarı elle seçip kopyalayın.');
    }
}

onMounted(fetchTokens);
</script>
