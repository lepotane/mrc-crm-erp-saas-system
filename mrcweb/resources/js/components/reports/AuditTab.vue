<!--
    Denetim sekmesi : „kim, ne zaman, hangi kayda ne yaptı".
    YALNIZ Admin görür (sunucu 403); Personel bu sekmeyi açarsa „yetkisiz" notu
    düşer. Kayıtlar salt okunur — arayüzde düzenleme/silme YOKTUR (API'de de yok).
-->
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-2">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-[#94A3B8] font-semibold">{{ $t('Eylem') }}</span>
                <select
                    v-model="f.action"
                    @change="resetPageAndFetch"
                    class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer"
                >
                    <option value="">{{ $t('Tümü') }}</option>
                    <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-[#94A3B8] font-semibold">{{ $t('Başlangıç') }}</span>
                <input v-model="f.from" type="date" @change="resetPageAndFetch" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-[#94A3B8] font-semibold">{{ $t('Bitiş') }}</span>
                <input v-model="f.to" type="date" @change="resetPageAndFetch" class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
            </label>
            <div class="flex flex-col gap-1 grow min-w-[180px]">
                <span class="text-xs text-[#94A3B8] font-semibold">{{ $t('Ara (kullanıcı / eylem)') }}</span>
                <input
                    v-model="f.search"
                    type="text"
                    :placeholder="$t('ad, e-posta, eylem...')"
                    @input="onSearchInput"
                    class="px-3 py-2 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                />
            </div>
        </div>

        <Card rounded="2xl" padding="md">
            <div v-if="loading" class="space-y-2 py-4">
                <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
            </div>
            <div v-else-if="forbidden" class="py-12 text-center text-sm text-[#94A3B8]">
                {{ $t('Denetim kaydını yalnız yöneticiler görebilir.') }}
            </div>
            <div v-else-if="rows.length === 0" class="py-12 text-center text-sm font-bold text-white bg-[#131722]/40 rounded-xl border border-white/5">
                {{ $t('Bu filtrede denetim kaydı yok.') }}
            </div>
            <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                <table class="w-full text-sm min-w-[760px]">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                            <th class="py-3 pr-3 font-semibold">{{ $t('Tarih') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Kullanıcı') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Eylem') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Kayıt') }}</th>
                            <th class="py-3 font-semibold">{{ $t('Özet') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.06]">
                        <tr v-for="r in rows" :key="r.id" class="hover:bg-white/[0.03] transition-colors">
                            <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ r.created_at || '-' }}</td>
                            <td class="py-3 pr-3 font-semibold text-white whitespace-nowrap">{{ r.user?.name || '—' }}</td>
                            <td class="py-3 pr-3 whitespace-nowrap">
                                <StatusPill :color="actionColor(r.action)" :dot="false">{{ r.action_label }}</StatusPill>
                            </td>
                            <td class="py-3 pr-3 text-xs text-[#94A3B8] font-mono whitespace-nowrap">{{ r.auditable?.label || '—' }}</td>
                            <td class="py-3 text-xs text-[#94A3B8]">{{ summarize(r) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2">
            <PillButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--; fetch()">
                {{ $t('Önceki') }}
            </PillButton>
            <span class="text-xs text-[#94A3B8]">{{ page }} / {{ meta.last_page }}</span>
            <PillButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="page++; fetch()">
                {{ $t('Sonraki') }}
            </PillButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';

const emit = defineEmits(['error']);

const rows = ref([]);
const loading = ref(true);
const forbidden = ref(false);
const page = ref(1);
const meta = ref({ last_page: 1 });
let searchTimer = null;

const f = reactive({ action: '', from: '', to: '', search: '' });

// Sunucudaki eylem adları (Audit::ACTIONS aynası). Yeni eylem eklenince buraya da satır düşer.
const actions = [
    'auth.login', 'auth.logout', 'auth.2fa.enabled', 'auth.2fa.disabled',
    'document.created', 'document.updated', 'document.status', 'document.converted', 'document.deleted',
    'payment.created', 'payment.deleted',
    'payment_link.created', 'payment_link.cancelled',
    'payment_settings.updated',
    'invitation.created', 'invitation.cancelled', 'invitation.accepted',
    'settings.updated', 'company.updated',
];

const ACTION_LABELS = {
    'auth.login': 'Giriş yaptı', 'auth.logout': 'Çıkış yaptı',
    'auth.2fa.enabled': 'İki faktörü açtı', 'auth.2fa.disabled': 'İki faktörü kapattı',
    'document.created': 'Belge oluşturdu', 'document.updated': 'Belgeyi düzenledi',
    'document.status': 'Belge durumunu değiştirdi', 'document.converted': 'Belgeyi dönüştürdü',
    'document.deleted': 'Belgeyi sildi',
    'payment.created': 'Ödeme kaydetti', 'payment.deleted': 'Ödemeyi sildi',
    'payment_link.created': 'Ödeme bağlantısı üretti', 'payment_link.cancelled': 'Ödeme bağlantısını kapattı',
    'payment_settings.updated': 'Ödeme hesaplarını güncelledi',
    'invitation.created': 'Üye davet etti', 'invitation.cancelled': 'Daveti iptal etti',
    'invitation.accepted': 'Daveti kabul etti',
    'settings.updated': 'Ayarları güncelledi', 'company.updated': 'Firma bilgilerini güncelledi',
};

function actionLabel(a) {
    return t(ACTION_LABELS[a] || a);
}

function actionColor(a) {
    if (a.endsWith('.deleted') || a.endsWith('.cancelled') || a.endsWith('.disabled')) return 'coral';
    if (a.startsWith('auth.')) return 'violet';
    if (a.startsWith('payment')) return 'forest';
    return 'cobalt';
}

/** Özet: meta'nın teknik anahtarları kullanıcı cümlesine indirilir. */
function summarize(r) {
    const m = r.meta || {};
    const parts = [];
    if (m.from && m.to) parts.push(`${m.from} → ${m.to}`);
    if (m.amount !== undefined) parts.push(String(m.amount));
    if (m.method) parts.push(m.method);
    if (m.email) parts.push(m.email);
    if (m.role) parts.push(m.role);
    if (m.changed) parts.push(Array.isArray(m.changed) ? m.changed.join(', ') : String(m.changed));
    if (m.via) parts.push(m.via);
    return parts.join(' · ') || '—';
}

function resetPageAndFetch() {
    page.value = 1;
    fetch();
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(resetPageAndFetch, 400);
}

async function fetch() {
    loading.value = true;
    try {
        const params = { page: page.value, per_page: 20 };
        if (f.action) params.action = f.action;
        if (f.from) params.from = f.from;
        if (f.to) params.to = f.to;
        if (f.search.trim()) params.search = f.search.trim();
        const res = await client.get('/audit', { params });
        rows.value = res.data?.data || [];
        meta.value = res.data?.meta || { last_page: 1 };
        forbidden.value = false;
    } catch (err) {
        if (err.response?.status === 403) {
            forbidden.value = true;
        } else {
            emit('error', err.response?.data?.message || t('Denetim kaydı yüklenemedi.'));
        }
    } finally {
        loading.value = false;
    }
}

onMounted(fetch);
</script>
