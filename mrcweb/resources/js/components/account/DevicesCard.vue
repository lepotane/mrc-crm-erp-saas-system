<!--
    Yazıcı köprüsü : firma cihazları + kuyruk.
    Cihaz ekleme YALNIZ Admin (eşleşme kodu TEK gösterilir); liste herkese açık
    (POS'tan fiş göndermek için cihaz/yazıcı seçimi gerekir).
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <Printer class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Yazıcı Köprüsü') }}</h2>
                    <p class="text-xs text-[#94A3B8]">{{ $t('PC köprüleri ve yazdırma kuyruğu') }}</p>
                </div>
            </div>
            <PillButton size="sm" variant="ghost" :loading="loading" @click="fetchAll">{{ $t('Yenile') }}</PillButton>
        </div>

        <div v-if="loading" class="space-y-2 py-3">
            <div v-for="i in 3" :key="i" class="h-12 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>

        <template v-else>
            <p v-if="error" class="mb-3 text-xs font-semibold text-[#FFA0A0]">{{ error }}</p>
            <p v-if="notice" class="mb-3 text-xs font-semibold text-[#B9FF66]">{{ notice }}</p>

            <div class="space-y-2">
                <div
                    v-for="d in devices"
                    :key="d.id"
                    class="flex flex-wrap items-center gap-3 rounded-xl bg-[#131722]/70 border border-white/10 p-3.5"
                >
                    <div class="min-w-[160px] grow">
                        <p class="text-sm font-bold text-white flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" :class="d.online ? 'bg-[#B9FF66] shadow-[0_0_8px_#B9FF66]' : 'bg-[#64748B]'"></span>
                            {{ d.name }}
                            <span class="text-[11px] font-normal text-[#64748B]">{{ d.pending_jobs }} {{ $t('bekleyen') }}</span>
                        </p>
                        <p class="text-[11px] text-[#64748B] mt-1">{{ (d.printers || []).join(', ') || $t('Yazıcı yok') }}</p>
                    </div>
                    <StatusPill :color="d.online ? 'lime' : 'neutral'" :dot="false">
                        {{ d.online ? $t('Çevrimiçi') : $t('Çevrimdışı') }}
                    </StatusPill>
                    <PillButton size="sm" variant="ghost" @click="askRemove(d)">{{ $t('Kaldır') }}</PillButton>
                </div>
                <p v-if="!devices.length" class="text-xs text-[#64748B] py-2">{{ $t('Henüz cihaz yok. Aşağıdan ekleyin.') }}</p>
            </div>

            <div class="mt-4 rounded-xl bg-[#131722]/70 border border-white/10 p-3.5">
                <p class="text-sm font-bold text-white mb-1">{{ $t('Yeni cihaz eşleştir') }}</p>
                <p class="text-[11px] text-[#64748B] mb-2">{{ $t('Köprü uygulamasında bu kodu girin (15 dakika geçerli, tek kullanımlık).') }}</p>
                <div class="flex flex-wrap gap-2">
                    <input
                        v-model="deviceName"
                        type="text"
                        :placeholder="$t('Cihaz adı (örn. Kasa PC)')"
                        class="grow min-w-[160px] px-4 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                    />
                    <PillButton size="sm" variant="primary" :loading="saving" :disabled="!deviceName.trim()" @click="create">{{ $t('Kod üret') }}</PillButton>
                </div>
                <div v-if="pairing" class="mt-2 p-3 rounded-xl bg-[#B9FF66]/10 border border-[#B9FF66]/30 text-center">
                    <p class="text-2xl font-black tracking-[0.3em] text-[#B9FF66] font-mono">{{ pairing.code }}</p>
                    <p class="text-[11px] text-[#94A3B8] mt-1">{{ pairing.name }} · {{ $t('geçerlilik') }}: {{ pairing.expires }}</p>
                </div>
            </div>

            <div v-if="removing" class="mt-3 flex items-center justify-between gap-3 rounded-xl bg-[#C55050]/10 border border-[#C55050]/30 p-3">
                <p class="text-xs text-[#FFA0A0]">{{ $t('Cihaz ve kuyruğundaki işler silinsin mi?') }} <b class="text-white">{{ removing.name }}</b></p>
                <div class="flex gap-2">
                    <PillButton size="sm" variant="ghost" @click="removing = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton size="sm" variant="danger" :loading="saving" @click="remove">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>

            <div class="mt-4">
                <p class="text-sm font-bold text-white mb-2">{{ $t('Kuyruk') }}</p>
                <div v-if="!jobs.length" class="text-xs text-[#64748B]">{{ $t('Kuyruk boş.') }}</div>
                <div v-else class="space-y-1.5">
                    <div v-for="j in jobs" :key="j.id" class="flex items-center justify-between gap-2 text-xs p-2 rounded-xl bg-[#131722]/60 border border-white/5">
                        <span class="text-white truncate">#{{ j.id }} {{ j.document_serial || '' }} · {{ j.device_name }} → {{ j.printer_name }}</span>
                        <StatusPill :color="j.status === 'failed' ? 'coral' : 'neutral'" :dot="false">{{ jobStatusLabel(j.status) }}</StatusPill>
                    </div>
                </div>
            </div>

            <p class="text-[11px] text-[#64748B] pt-3 mt-3 border-t border-white/[0.08]">
                {{ $t('Cihaz ekleme/silme yalnız yönetici yapar; kuyruk izleme herkese açıktır.') }}
            </p>
        </template>
    </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Printer } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';

const devices = ref([]);
const jobs = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const deviceName = ref('');
const pairing = ref(null);
const removing = ref(null);

function jobStatusLabel(s) {
    if (s === 'queued') return t('Kuyrukta');
    if (s === 'claimed') return t('Yazdırılıyor');
    return t('Başarısız');
}

async function fetchAll() {
    loading.value = true;
    error.value = '';
    try {
        const [d, j] = await Promise.all([
            client.get('/devices'),
            client.get('/print-jobs', { params: { per_page: 20 } }),
        ]);
        devices.value = d.data?.data || [];
        jobs.value = j.data?.data || [];
    } catch (err) {
        error.value = err.response?.data?.message || t('Yazıcı listesi yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function create() {
    saving.value = true;
    error.value = '';
    notice.value = '';
    pairing.value = null;
    try {
        const res = await client.post('/devices', { name: deviceName.value.trim() });
        pairing.value = {
            code: res.data?.pairing_code || '',
            name: res.data?.device?.name || '',
            expires: res.data?.expires_at || '',
        };
        deviceName.value = '';
        notice.value = t('Eşleşme kodu üretildi.');
        await fetchAll();
    } catch (err) {
        error.value = err.response?.status === 403
            ? t('Bu işlem için yönetici yetkisi gereklidir.')
            : (err.response?.data?.message || t('Cihaz eklenemedi.'));
    } finally {
        saving.value = false;
    }
}

function askRemove(d) {
    removing.value = d;
}

async function remove() {
    if (!removing.value) return;
    saving.value = true;
    error.value = '';
    try {
        await client.delete(`/devices/${removing.value.id}`);
        notice.value = t('Cihaz silindi.');
        removing.value = null;
        await fetchAll();
    } catch (err) {
        error.value = err.response?.status === 403
            ? t('Bu işlem için yönetici yetkisi gereklidir.')
            : (err.response?.data?.message || t('Cihaz silinemedi.'));
    } finally {
        saving.value = false;
    }
}

onMounted(fetchAll);

defineExpose({ fetchAll });
</script>
