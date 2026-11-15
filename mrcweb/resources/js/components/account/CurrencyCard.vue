<!--
    Para birimleri ve güncel kurlar .
    Liste herkese açık (Personel de „dolar kaç" görebilmeli); etkin/pasif
    anahtarı YALNIZ Admin'e açık — Personel kapatmaya çalışırsa sunucu 403 döner
    ve kart bunu söyler. Kur fotoğrafı belgede donar ; bu kart yalnız GÜNCEL
    tabloyu gösterir, geçmişi değiştirmez.
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <Banknote class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Para Birimleri ve Kurlar') }}</h2>
                    <p class="text-xs text-[#94A3B8]">
                        {{ $t('Günlük kurlar (TCMB öncelikli). Dövizli belgeler kesim gününün kuruyla fotoğraflanır.') }}
                    </p>
                </div>
                <PillButton size="sm" variant="secondary" :loading="cekiliyor" @click="kurlariCek">
                    <RefreshCw class="w-3.5 h-3.5" /> {{ $t('Kurları şimdi çek') }}
                </PillButton>
            </div>
        </div>

        <div v-if="loading" class="space-y-2 py-3">
            <div v-for="i in 4" :key="i" class="h-12 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>

        <template v-else>
            <p v-if="error" class="mb-3 text-xs font-semibold text-[#FFA0A0]">{{ error }}</p>
            <p v-if="notice" class="mb-3 text-xs font-semibold text-[#B9FF66]">{{ notice }}</p>

            <div class="space-y-2">
                <div
                    v-for="c in rows"
                    :key="c.code"
                    class="flex flex-wrap items-center gap-3 rounded-xl bg-[#131722]/70 border border-white/10 p-3.5"
                >
                    <div class="min-w-[140px] grow">
                        <p class="text-sm font-bold text-white">
                            {{ c.symbol }} {{ c.code }}
                            <span class="text-[11px] font-normal text-[#94A3B8]">{{ c.name }}</span>
                        </p>
                        <p class="text-[11px] text-[#64748B] mt-1">
                            <template v-if="c.rate_today !== null && c.rate_today !== undefined">
                                1 {{ c.code }} = {{ formatRate(c.rate_today) }} TRY
                                <span v-if="c.rate_date">· {{ c.rate_date }}{{ c.rate_source ? ' · ' + c.rate_source : '' }}</span>
                            </template>
                            <template v-else>{{ $t('Henüz kur çekilmedi.') }}</template>
                        </p>
                    </div>
                    <StatusPill :color="c.is_active ? 'lime' : 'neutral'" :dot="false">
                        {{ c.is_active ? $t('Etkin') : $t('Pasif') }}
                    </StatusPill>
                    <PillButton
                        v-if="c.code !== 'TRY'"
                        size="sm"
                        variant="ghost"
                        :loading="saving === c.code"
                        @click="toggle(c)"
                    >
                        {{ c.is_active ? $t('Kapat') : $t('Aç') }}
                    </PillButton>
                </div>
            </div>

            <p class="text-[11px] text-[#64748B] pt-3 mt-3 border-t border-white/[0.08]">
                {{ $t('Pasif para birimiyle yeni belge kesilemez. Ana para birimi (TRY) kapatılamaz. Kurlar her sabah otomatik çekilir; cron kapalıysa düğmeyle alın.') }}
            </p>
        </template>
    </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Banknote, RefreshCw } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';

const rows = ref([]);
const loading = ref(true);
const saving = ref('');
const cekiliyor = ref(false);
const error = ref('');
const notice = ref('');

function formatRate(v) {
    return Number(v).toLocaleString('tr-TR', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}

async function fetchAll() {
    loading.value = true;
    error.value = '';
    try {
        const res = await client.get('/currencies');
        rows.value = res.data?.data || [];
    } catch (err) {
        error.value = err.response?.data?.message || t('Kurlar yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function kurlariCek() {
    cekiliyor.value = true;
    error.value = '';
    notice.value = '';
    try {
        const res = await client.post('/exchange-rates/fetch');
        await fetchAll();
        notice.value = res.data?.message || t('Kurlar güncellendi.');
    } catch (err) {
        if (err.response?.status === 403) {
            error.value = t('Bu işlem için yönetici yetkisi gereklidir.');
        } else if (err.response?.status === 422) {
            error.value = err.response?.data?.message || t('Kur çekilemedi.');
        } else {
            error.value = err.response?.data?.message || t('Kur çekilemedi.');
        }
    } finally {
        cekiliyor.value = false;
    }
}

async function toggle(c) {
    saving.value = c.code;
    error.value = '';
    notice.value = '';
    try {
        await client.patch(`/currencies/${c.code}`, { is_active: !c.is_active });
        await fetchAll();
        notice.value = t('Para birimi güncellendi.');
    } catch (err) {
        if (err.response?.status === 403) {
            error.value = t('Bu işlem için yönetici yetkisi gereklidir.');
        } else {
            const bag = err.response?.data?.errors || {};
            error.value = Object.values(bag).flat()[0]
                || err.response?.data?.message
                || t('Para birimi güncellenemedi.');
        }
    } finally {
        saving.value = '';
    }
}

onMounted(fetchAll);

defineExpose({ fetchAll });
</script>
