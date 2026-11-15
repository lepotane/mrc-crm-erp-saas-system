<!--
    Plan ve limitler . LIMIT NEREDEYİZ görmek için: kullanıcı
    „neden yeni ürün ekleyemiyorum" sorusunu burada cevaplamalı, hata mesajı
    tek başına yetmiyor (ayı ne zaman yeniler, kaç hakkı kaldı).
    Sayılar sunucudan gelir (GET /company/plan) — SPA kendi sayısını saymaz;
    config/plans.php TEK doğruluk kaynağıdır.
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <Gauge class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Plan ve Limitler') }}</h2>
                    <p class="text-xs text-[#94A3B8]">
                        {{ $t('Abonelik planının izin verdiği kullanıcı, ürün, aylık belge ve ödeme linki sayıları.') }}
                    </p>
                </div>
            </div>
            <div class="hidden sm:flex items-center gap-2 text-xs text-[#94A3B8]">
                <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                <template v-else-if="planInfo">
                    <span
                        v-if="planInfo.has_custom_limits"
                        class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-[#BC5FCF]/20 text-[#E9D5FF] border border-[#BC5FCF]/40"
                    >
                        {{ $t('Özel Limitler Aktif') }}
                    </span>
                    <StatusPill :color="planInfo.plan === 'ucretsiz' ? 'orange' : 'lime'">
                        {{ planInfo.label }}
                    </StatusPill>
                </template>
            </div>
        </div>

        <p v-if="loadError" class="mb-4 text-xs font-semibold text-[#FFA0A0]">{{ loadError }}</p>

        <div v-else-if="planInfo" class="space-y-4">
            <div v-for="satir in planInfo.limits" :key="satir.resource" class="flex flex-col gap-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="text-[#94A3B8] font-semibold">{{ $t(satir.label) }}</span>
                    <span class="text-white font-semibold">
                        {{ formatNumber(satir.used) }}<span class="text-[#64748B]"> / {{ satir.limit === null ? '—' : formatNumber(satir.limit) }}</span>
                    </span>
                </div>
                <div class="h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="dolumSinifi(satir)"
                        :style="{ width: dolumYuzde(satir) + '%' }"
                    ></div>
                </div>
                <p class="text-[11px]" :class="satir.limit !== null && satir.remaining === 0 ? 'text-[#FFAE7C]' : 'text-[#64748B]'">
                    <template v-if="satir.limit === null">{{ $t('Bu kalem planında sınırsız.') }}</template>
                    <template v-else-if="satir.remaining === 0">{{ $t('Limit dolu — bu ay yeni kayıt alınamaz.') }}</template>
                    <template v-else>{{ $t(':count hak kaldı', { count: satir.remaining }) }}</template>
                </p>
            </div>

            <p class="text-[11px] text-[#64748B] pt-3 border-t border-white/[0.08]">
                {{ $t('Belge ve ödeme linki limitleri her ayın başında yenilenir. Plan değişikliği hesabın sahibi üzerinden yapılır; bu ekrandan değiştirilmez.') }}
            </p>
        </div>
    </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Card from '../ui/Card.vue';
import StatusPill from '../ui/StatusPill.vue';
import SkeletonPill from '../ui/SkeletonPill.vue';
import { Gauge } from 'lucide-vue-next';
import client from '../../api/client';
import { formatNumber } from '../../utils/format';
import { t } from '../../i18n';

const planInfo = ref(null);
const loading = ref(true);
const loadError = ref('');

function dolumYuzde(satir) {
    if (satir.limit === null || satir.limit === 0) return 100;
    return Math.min(100, Math.round((satir.used / satir.limit) * 100));
}

function dolumSinifi(satir) {
    const yuzde = dolumYuzde(satir);
    if (satir.limit !== null && satir.remaining === 0) return 'bg-[#C55050]';
    if (yuzde >= 80) return 'bg-[#DC6F31]';
    return 'bg-[#B9FF66]';
}

async function fetchPlan() {
    loading.value = true;
    loadError.value = '';
    try {
        const res = await client.get('/company/plan');
        planInfo.value = res.data;
    } catch (err) {
        loadError.value = err.response?.data?.message || t('Plan bilgisi yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

onMounted(fetchPlan);

defineExpose({ fetchPlan });
</script>
