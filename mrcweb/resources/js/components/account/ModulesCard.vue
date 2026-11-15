<!--
    Modüller ve eklentiler .
    Liste herkese açık (menü/ızgara bayrakları); açma/kapama/kurulum YALNIZ Admin.
    Personel yazmaya kalkarsa sunucu 403 döner ve kart bunu söyler (CurrencyCard deseni).
    Kapalı modül menüden ve pano ızgarasından kaybolur; verisi durur.
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <Blocks class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Modüller ve Eklentiler') }}</h2>
                    <p class="text-xs text-[#94A3B8]">
                        {{ $t('Kapalı modül menüden ve panodan kaybolur; verisi korunur.') }}
                    </p>
                </div>
            </div>
            <PillButton size="sm" variant="ghost" :loading="discovering" @click="discover">
                {{ $t('Eklentileri Tara') }}
            </PillButton>
        </div>

        <div v-if="loading" class="space-y-2 py-3">
            <div v-for="i in 5" :key="i" class="h-12 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>

        <template v-else>
            <p v-if="error" class="mb-3 text-xs font-semibold text-[#FFA0A0]">{{ error }}</p>
            <p v-if="notice" class="mb-3 text-xs font-semibold text-[#B9FF66]">{{ notice }}</p>

            <div class="space-y-2">
                <div
                    v-for="m in rows"
                    :key="m.key"
                    class="flex flex-wrap items-center gap-3 rounded-xl bg-[#131722]/70 border border-white/10 p-3.5"
                >
                    <div class="min-w-[160px] grow">
                        <p class="text-sm font-bold text-white">
                            {{ locale === 'en' ? (m.name_en || m.name_tr) : m.name_tr }}
                            <span class="text-[11px] font-normal text-[#64748B]">v{{ m.version }}</span>
                        </p>
                        <p class="text-[11px] text-[#64748B] mt-1">
                            <span v-if="m.source === 'local'">{{ $t('Eklenti') }}</span>
                            <span v-else>{{ $t('Çekirdek') }}</span>
                            <span v-if="m.source === 'local' && !m.installed"> · {{ $t('Kurulu değil') }}</span>
                            <span v-if="(m.widgets || []).length"> · {{ (m.widgets || []).length }} {{ $t('araç') }}</span>
                        </p>
                    </div>
                    <StatusPill :color="m.enabled ? 'lime' : 'neutral'" :dot="false">
                        {{ m.enabled ? $t('Etkin') : (m.installed ? $t('Pasif') : $t('Kurulu değil')) }}
                    </StatusPill>
                    <PillButton
                        v-if="m.source === 'local' && !m.installed"
                        size="sm"
                        variant="primary"
                        :loading="saving === 'kur:' + m.key"
                        @click="install(m)"
                    >
                        {{ $t('Eklentiyi Kur') }}
                    </PillButton>
                    <PillButton
                        v-else
                        size="sm"
                        variant="ghost"
                        :loading="saving === m.key"
                        @click="toggle(m)"
                    >
                        {{ m.enabled ? $t('Kapat') : $t('Aç') }}
                    </PillButton>
                </div>
            </div>

            <div v-if="configModule" class="mt-4 rounded-xl bg-[#131722]/70 border border-white/10 p-3.5">
                <p class="text-sm font-bold text-white mb-1">{{ configModuleName }}</p>
                <div v-for="f in (configModule.config_schema || [])" :key="f.key" class="mt-2">
                    <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">
                        {{ locale === 'en' ? (f.label_en || f.label_tr) : f.label_tr }}
                    </label>
                    <textarea
                        v-if="f.type === 'textarea'"
                        v-model="configDraft[f.key]"
                        rows="2"
                        class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    ></textarea>
                    <input
                        v-else
                        v-model="configDraft[f.key]"
                        type="text"
                        class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    />
                </div>
                <div class="flex items-center gap-2 mt-3">
                    <PillButton size="sm" variant="primary" :loading="saving === 'config:' + configModule.key" @click="saveConfig">
                        {{ $t('Kaydet') }}
                    </PillButton>
                    <button type="button" class="text-xs text-[#94A3B8] hover:text-white px-3 py-2" @click="configModule = null">
                        {{ $t('Vazgeç') }}
                    </button>
                </div>
            </div>
            <p v-else-if="schemaModule" class="mt-3">
                <button type="button" class="text-xs text-[#94A3B8] hover:text-white underline underline-offset-2" @click="openConfig(schemaModule)">
                    {{ $t('Eklenti ayarını düzenle') }}: {{ schemaModule.key }}
                </button>
            </p>

            <p class="text-[11px] text-[#64748B] pt-3 mt-3 border-t border-white/[0.08]">
                {{ $t('Panel modülü kapatılamaz; kapatılan modülün kayıtları silinmez.') }}
            </p>
        </template>
    </Card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Blocks } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t, locale } from '../../i18n';

const rows = ref([]);
const loading = ref(true);
const discovering = ref(false);
const saving = ref('');
const error = ref('');
const notice = ref('');
const configModule = ref(null);
const configDraft = ref({});

const configModuleName = computed(() => {
    const m = configModule.value;
    if (!m) return '';
    return locale.value === 'en' ? (m.name_en || m.name_tr) : m.name_tr;
});

const schemaModule = computed(() => rows.value.find((m) => m.installed && (m.config_schema || []).length) || null);

function openConfig(m) {
    configModule.value = m;
    const draft = {};
    for (const f of m.config_schema || []) {
        draft[f.key] = (m.config || {})[f.key] || '';
    }
    configDraft.value = draft;
}

async function fetchAll() {
    loading.value = true;
    error.value = '';
    try {
        const res = await client.get('/modules');
        rows.value = res.data?.data || [];
        if (configModule.value) {
            const fresh = rows.value.find((m) => m.key === configModule.value.key);
            if (fresh) openConfig(fresh);
        }
    } catch (err) {
        error.value = err.response?.data?.message || t('Modüller yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function toggle(m) {
    saving.value = m.key;
    error.value = '';
    notice.value = '';
    try {
        await client.patch(`/modules/${m.key}`, { enabled: !m.enabled });
        await fetchAll();
        notice.value = t('Modül güncellendi.');
    } catch (err) {
        if (err.response?.status === 403) {
            error.value = t('Bu işlem için yönetici yetkisi gereklidir.');
        } else {
            const bag = err.response?.data?.errors || {};
            error.value = Object.values(bag).flat()[0]
                || err.response?.data?.message
                || t('Modül güncellenemedi.');
        }
    } finally {
        saving.value = '';
    }
}

async function discover() {
    discovering.value = true;
    error.value = '';
    notice.value = '';
    try {
        const res = await client.post('/plugins/discover');
        await fetchAll();
        const n = (res.data?.data || []).length;
        notice.value = n ? t('Eklenti taraması tamamlandı.') : t('Kurulacak eklenti bulunamadı.');
    } catch (err) {
        error.value = err.response?.status === 403
            ? t('Bu işlem için yönetici yetkisi gereklidir.')
            : (err.response?.data?.message || t('Tarama yapılamadı.'));
    } finally {
        discovering.value = false;
    }
}

async function install(m) {
    saving.value = 'kur:' + m.key;
    error.value = '';
    notice.value = '';
    try {
        await client.post('/plugins/install', { key: m.key });
        await fetchAll();
        notice.value = t('Eklenti kuruldu.');
    } catch (err) {
        error.value = err.response?.status === 403
            ? t('Bu işlem için yönetici yetkisi gereklidir.')
            : (err.response?.data?.message || t('Eklenti kurulamadı.'));
    } finally {
        saving.value = '';
    }
}

async function saveConfig() {
    const m = configModule.value;
    if (!m) return;
    saving.value = 'config:' + m.key;
    error.value = '';
    notice.value = '';
    try {
        await client.patch(`/modules/${m.key}`, { config: configDraft.value });
        await fetchAll();
        notice.value = t('Modül güncellendi.');
    } catch (err) {
        error.value = err.response?.status === 403
            ? t('Bu işlem için yönetici yetkisi gereklidir.')
            : (err.response?.data?.message || t('Modül güncellenemedi.'));
    } finally {
        saving.value = '';
    }
}

onMounted(fetchAll);

defineExpose({ fetchAll });
</script>
