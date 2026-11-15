<!--
    Ödeme Ayarları : PayTR + iyzico hesap kartları.
    YALNIZ Admin görür; Personel'e 403 düşer ve kart kendini „yöneticiye özel"
    notuna indirger (Ayarlar'daki isSettingsForbidden deseniyle aynı).
    Sırlar (anahtar/salt) sunucuda şifreli durur; arayüz maskeli değeri gösterir,
    boş bırakılan alan MEVCUT değeri korur — kullanıcı maskeyi silmek zorunda kalmaz.
-->
<template>
    <Card rounded="2xl" padding="lg">
        <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <Wallet class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Ödeme Ayarları') }}</h2>
                    <p class="text-xs text-[#94A3B8]">
                        {{ $t('PayTR ve iyzico ile kartla tahsilat. Müşteriye ödeme bağlantısı gönderilir, tahsilat kendiliğinden deftere işler.') }}
                    </p>
                </div>
            </div>
        </div>

        <div v-if="loading" class="space-y-2 py-3">
            <div v-for="i in 2" :key="i" class="h-24 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>

        <div v-else-if="forbidden" class="py-6 text-center text-sm text-[#94A3B8]">
            <Lock class="w-6 h-6 mx-auto mb-2 text-[#64748B]" />
            {{ $t('Bu bölüm yalnız yöneticilere açıktır. Ödeme hesabı tanımlamak için firma yöneticisine başvurun.') }}
        </div>

        <div v-else class="space-y-4">
            <p v-if="loadError" class="text-xs font-semibold text-[#FFA0A0]">{{ loadError }}</p>

            <section
                v-for="row in rows"
                :key="row.provider"
                class="rounded-2xl bg-[#131722]/70 border border-white/10 p-4 space-y-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-black text-white">{{ row.label }}</span>
                        <StatusPill :color="row.is_active ? 'lime' : 'neutral'" :dot="false">
                            {{ row.is_active ? $t('Etkin') : $t('Kapalı') }}
                        </StatusPill>
                        <StatusPill v-if="row.is_sandbox" color="orange" :dot="false">{{ $t('Deneme modu') }}</StatusPill>
                    </div>
                    <span class="text-[11px] text-[#64748B] font-mono break-all">{{ row.callback_url }}</span>
                </div>

                <p v-if="row.missing_fields?.length" class="text-[11px] text-[#FFAE7C]">
                    {{ $t('Eksik alanlar: :fields', { fields: row.missing_fields.map((f) => t(fieldLabel(f))).join(', ') }) }}
                </p>

                <div class="grid sm:grid-cols-2 gap-3">
                    <label v-for="f in textFields(row.provider)" :key="f" class="block">
                        <span class="text-[11px] font-bold text-[#94A3B8]">{{ $t(fieldLabel(f)) }}</span>
                        <input
                            v-model="forms[row.provider][f]"
                            :type="isSecret(f) ? 'password' : 'text'"
                            :placeholder="row.fields?.[f] || $t('Girilmedi')"
                            autocomplete="off"
                            class="mt-1 w-full px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white placeholder:text-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] font-mono"
                        />
                    </label>
                </div>

                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block">
                        <span class="text-[11px] font-bold text-[#94A3B8]">{{ $t('Tahsilat hesabı') }}</span>
                        <select
                            v-model="forms[row.provider].bank_account_id"
                            class="mt-1 w-full px-3 py-2 bg-[#0F1420] border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer"
                        >
                            <option :value="null">{{ $t('Hesaba bağlama') }}</option>
                            <option v-for="h in bankAccounts" :key="h.id" :value="h.id">
                                {{ h.name }} ({{ h.currency_code }})
                            </option>
                        </select>
                    </label>
                    <label class="flex items-end gap-2 pb-2 text-xs text-white cursor-pointer">
                        <input v-model="forms[row.provider].is_sandbox" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                        {{ $t('Deneme modu (gerçek para alınmaz)') }}
                    </label>
                    <label class="flex items-end gap-2 pb-2 text-xs text-white cursor-pointer">
                        <input v-model="forms[row.provider].is_active" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                        {{ $t('Hesap etkin') }}
                    </label>
                </div>

                <p v-if="errors[row.provider]" class="text-xs font-semibold text-[#FFA0A0]">{{ errors[row.provider] }}</p>
                <p v-if="savedAt[row.provider]" class="text-xs font-semibold text-[#B9FF66]">{{ $t('Kaydedildi.') }}</p>

                <div class="flex flex-wrap items-center gap-2">
                    <PillButton size="sm" :loading="saving === row.provider" @click="save(row)">
                        {{ $t('Kaydet') }}
                    </PillButton>
                    <PillButton v-if="row.configured" size="sm" variant="ghost" @click="copyUrl(row)">
                        <Copy class="w-3.5 h-3.5" /> {{ copiedUrl === row.provider ? $t('Kopyalandı') : $t('Bildirim adresini kopyala') }}
                    </PillButton>
                    <template v-if="row.configured">
                        <template v-if="confirmRemove === row.provider">
                            <PillButton size="sm" variant="primary" :loading="removing === row.provider" @click="remove(row)">
                                {{ $t('Evet, kaldır') }}
                            </PillButton>
                            <PillButton size="sm" variant="ghost" @click="confirmRemove = null">{{ $t('Vazgeç') }}</PillButton>
                        </template>
                        <PillButton v-else size="sm" variant="ghost" @click="confirmRemove = row.provider">
                            <Trash2 class="w-3.5 h-3.5" /> {{ $t('Hesabı kaldır') }}
                        </PillButton>
                    </template>
                </div>
            </section>

            <p class="text-[11px] text-[#64748B] pt-3 border-t border-white/[0.08]">
                {{ $t('Bildirim adresi ödeme kuruluşunun paneline aynen yazılır (PayTR: Bildirim URL). Anahtarlar bir daha düz metin olarak gösterilmez; değiştirmek için üzerine yazın, korumak için boş bırakın.') }}
            </p>
        </div>
    </Card>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Wallet, Lock, Copy, Trash2 } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import client from '../../api/client';
import { t } from '../../i18n';

const rows = ref([]);
const bankAccounts = ref([]);
const loading = ref(true);
const forbidden = ref(false);
const loadError = ref('');
const saving = ref('');
const removing = ref('');
const confirmRemove = ref(null);
const copiedUrl = ref('');
const errors = reactive({});
const savedAt = reactive({});
const forms = reactive({
    paytr: { merchant_id: '', merchant_key: '', merchant_salt: '', bank_account_id: null, is_sandbox: true, is_active: false },
    iyzico: { api_key: '', secret_key: '', bank_account_id: null, is_sandbox: true, is_active: false },
});

const SECRET_FIELDS = ['merchant_key', 'merchant_salt', 'api_key', 'secret_key'];
const FIELD_LABELS = {
    merchant_id: 'Mağaza numarası',
    merchant_key: 'Mağaza anahtarı',
    merchant_salt: 'Güvenlik parolası (salt)',
    api_key: 'API anahtarı',
    secret_key: 'Gizli anahtar',
};

function fieldLabel(f) {
    return FIELD_LABELS[f] || f;
}

function isSecret(f) {
    return SECRET_FIELDS.includes(f);
}

function textFields(provider) {
    return provider === 'paytr'
        ? ['merchant_id', 'merchant_key', 'merchant_salt']
        : ['api_key', 'secret_key'];
}

function syncForm(row) {
    const f = forms[row.provider];
    f.bank_account_id = row.bank_account_id ?? null;
    f.is_sandbox = !!row.is_sandbox;
    f.is_active = !!row.is_active;
}

async function fetchAll() {
    loading.value = true;
    loadError.value = '';
    try {
        const [res, hesaplar] = await Promise.all([
            client.get('/payment-settings'),
            client.get('/bank-accounts').catch(() => ({ data: { bank_accounts: [] } })),
        ]);
        rows.value = res.data?.data || [];
        rows.value.forEach(syncForm);
        bankAccounts.value = hesaplar.data?.bank_accounts || [];
    } catch (err) {
        if (err.response?.status === 403) {
            forbidden.value = true;
        } else {
            loadError.value = err.response?.data?.message || t('Ödeme ayarları yüklenemedi.');
        }
    } finally {
        loading.value = false;
    }
}

async function save(row) {
    saving.value = row.provider;
    errors[row.provider] = '';
    savedAt[row.provider] = '';
    try {
        const f = forms[row.provider];
        const credentials = {};
        for (const key of textFields(row.provider)) {
            const v = (f[key] || '').trim();
            if (v !== '') credentials[key] = v; // boş = mevcut değeri koru
        }
        const res = await client.put(`/payment-settings/${row.provider}`, {
            credentials,
            bank_account_id: f.bank_account_id,
            is_sandbox: !!f.is_sandbox,
            is_active: !!f.is_active,
        });
        const i = rows.value.findIndex((r) => r.provider === row.provider);
        if (i > -1) {
            rows.value[i] = res.data?.data;
            syncForm(rows.value[i]);
            for (const key of textFields(row.provider)) f[key] = '';
        }
        savedAt[row.provider] = '1';
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        errors[row.provider] = Object.values(bag).flat()[0]
            || err.response?.data?.message
            || t('Ödeme ayarları kaydedilemedi.');
    } finally {
        saving.value = '';
    }
}

async function remove(row) {
    removing.value = row.provider;
    try {
        await client.delete(`/payment-settings/${row.provider}`);
        confirmRemove.value = null;
        await fetchAll();
    } catch (err) {
        errors[row.provider] = err.response?.data?.message || t('Ödeme hesabı kaldırılamadı.');
    } finally {
        removing.value = '';
    }
}

async function copyUrl(row) {
    try {
        await navigator.clipboard.writeText(row.callback_url);
        copiedUrl.value = row.provider;
        setTimeout(() => { copiedUrl.value = ''; }, 2000);
    } catch {
        errors[row.provider] = t('Kopyalama desteklenmiyor; adresi elle seçin.');
    }
}

onMounted(fetchAll);

defineExpose({ fetchAll });
</script>
