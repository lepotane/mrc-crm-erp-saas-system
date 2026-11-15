<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <p class="text-xs text-[#94A3B8]">{{ $t('Kasa, banka ve kart hesaplarınızı buradan yönetin.') }}</p>
            <PillButton variant="primary" size="sm" @click="openCreate">
                <Plus class="w-3.5 h-3.5 stroke-[3]" />
                <span>{{ $t('Yeni Hesap') }}</span>
            </PillButton>
        </div>

        <div v-if="formGeneralError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">
            {{ formGeneralError }}
        </div>

        <div v-if="loading" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="i in 4" :key="i" class="h-28 bg-[#131722]/60 rounded-2xl border border-white/5 animate-pulse"></div>
        </div>

        <div v-else-if="accounts.length === 0" class="py-10 text-center bg-[#131722]/40 rounded-2xl border border-white/5">
            <p class="text-sm font-bold text-white">{{ $t('Kayıtlı hesap bulunamadı') }}</p>
            <p class="text-xs text-[#94A3B8] mt-1">{{ $t('İlk kasa veya banka hesabınızı oluşturarak başlayın.') }}</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="a in accounts" :key="a.id" class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-2.5" :class="{ 'opacity-60': !a.is_active }">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <StatusPill :color="accountTypeColor(a.type)" :dot="false">{{ $t(accountTypeLabelKey(a.type)) }}</StatusPill>
                        <span v-if="a.is_default" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border bg-[#B9FF66]/15 text-[#B9FF66] border-[#B9FF66]/30">{{ $t('Varsayılan') }}</span>
                        <StatusPill :color="a.is_active ? 'forest' : 'neutral'" :dot="false">{{ a.is_active ? $t('Aktif') : $t('Pasif') }}</StatusPill>
                    </div>
                    <div class="inline-flex items-center gap-1 shrink-0">
                        <button type="button" @click="openEdit(a)" :title="$t('Düzenle')" class="p-1.5 rounded-lg text-[#94A3B8] hover:bg-white/10 border border-transparent hover:border-white/20 transition-colors cursor-pointer">
                            <Pencil class="w-4 h-4" />
                        </button>
                        <button type="button" @click="askDelete(a)" :title="$t('Sil')" class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-bold text-white truncate">{{ a.name }}</p>
                    <p class="text-sm font-black text-[#B9FF66] whitespace-nowrap">{{ formatMoney(a.balance, a.currency_code) }}</p>
                </div>
                <div class="text-[11px] text-[#94A3B8] space-y-0.5">
                    <p v-if="a.iban">IBAN: <span class="font-mono text-[#CBD5E1]">{{ a.iban }}</span></p>
                    <p v-if="a.account_number">{{ $t('Hesap no') }}: <span class="font-mono text-[#CBD5E1]">{{ a.account_number }}</span></p>
                    <p>{{ $t('Açılış Bakiyesi') }}: {{ formatMoney(a.opening_balance, a.currency_code) }} · {{ a.payments_count }} {{ $t('ödeme') }}</p>
                </div>
            </div>
        </div>

        <p class="text-[11px] text-[#64748B]">{{ $t('Varsayılan olarak işaretlenen hesap, yeni ödemelerde önerilir. İşaret değişince öncekiler otomatik pasife alınır.') }}</p>

        <div v-if="showForm" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs" @click.self="closeForm">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-[0_20px_50px_rgba(0,0,0,8)] space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 class="text-base font-bold text-white">{{ formMode === 'edit' ? $t('Hesabı Düzenle') : $t('Yeni Hesap') }}</h3>
                <div v-if="modalError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ modalError }}</div>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Hesap Adı') }} <span class="text-[#B9FF66]">*</span></label>
                        <input v-model="form.name" type="text" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        <p v-if="formErrors.name" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(formErrors.name) }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Tip') }}</label>
                            <select v-model="form.type" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option v-for="o in accountTypeOptions" :key="o.value" :value="o.value">{{ $t(o.key) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Para Birimi') }}</label>
                            <select v-model="form.currency_code" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option value="TRY">₺ TRY</option>
                                <option value="USD">$ USD</option>
                                <option value="EUR">€ EUR</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">IBAN</label>
                            <input v-model="form.iban" type="text" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Hesap no') }}</label>
                            <input v-model="form.account_number" type="text" class="w-full px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Açılış Bakiyesi') }}</label>
                        <input v-model.number="form.opening_balance" type="number" step="0.01" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        <p v-if="formErrors.opening_balance" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMessage(formErrors.opening_balance) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Notlar') }}</label>
                        <input v-model="form.note" type="text" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    </div>
                    <div class="flex items-center gap-4 text-xs text-white">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input v-model="form.is_default" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                            {{ $t('Varsayılan') }}
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                            {{ $t('Aktif') }}
                        </label>
                    </div>
                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-white/[0.08]">
                        <PillButton type="button" variant="ghost" size="md" @click="closeForm">{{ $t('Vazgeç') }}</PillButton>
                        <PillButton type="submit" variant="primary" size="md" :loading="formSaving">{{ $t('Kaydet') }}</PillButton>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="deletingAccount" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Hesap Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ deletingAccount.name }}</strong> {{ $t('kalıcı olarak silinecektir.') }}
                </p>
                <p v-if="deleteError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ deleteError }}</p>
                <p v-if="deletingAccount.payments_count > 0" class="text-[11px] text-[#FFAE7C]">{{ $t('Bu hesapta :n ödeme hareketi var. Silmek yerine pasife almayı düşünün.', { n: deletingAccount.payments_count }) }}</p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingAccount = null; deleteError = ''">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="confirmDelete">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, computed } from 'vue';
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import { formatMoney } from '../../utils/format';
import { t } from '../../i18n';
import { firstMessage, accountTypeLabelKey, accountTypeColor } from './financeUtils';
import client from '../../api/client';
import { Plus, Pencil, Trash2, AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

const accountTypeOptions = computed(() => [
    { value: 'cash', key: 'Kasa' },
    { value: 'bank', key: 'Banka hesabı' },
    { value: 'card', key: 'Kart hesabı' },
]);

const showForm = ref(false);
const formMode = ref('create');
const editingId = ref(null);
const formSaving = ref(false);
const formErrors = ref({});
const modalError = ref('');
const formGeneralError = ref('');

const form = reactive({
    name: '',
    type: 'cash',
    iban: '',
    account_number: '',
    currency_code: 'TRY',
    opening_balance: 0,
    is_default: false,
    is_active: true,
    note: '',
});

function openCreate() {
    formMode.value = 'create';
    editingId.value = null;
    Object.assign(form, { name: '', type: 'cash', iban: '', account_number: '', currency_code: 'TRY', opening_balance: 0, is_default: false, is_active: true, note: '' });
    formErrors.value = {};
    modalError.value = '';
    showForm.value = true;
}

function openEdit(a) {
    formMode.value = 'edit';
    editingId.value = a.id;
    Object.assign(form, {
        name: a.name || '',
        type: a.type || 'cash',
        iban: a.iban || '',
        account_number: a.account_number || '',
        currency_code: a.currency_code || 'TRY',
        opening_balance: a.opening_balance ?? 0,
        is_default: !!a.is_default,
        is_active: a.is_active !== false,
        note: a.note || '',
    });
    formErrors.value = {};
    modalError.value = '';
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
}

async function submitForm() {
    formSaving.value = true;
    formErrors.value = {};
    modalError.value = '';
    try {
        const payload = {
            name: form.name,
            type: form.type,
            currency_code: form.currency_code,
            opening_balance: Number(form.opening_balance) || 0,
            is_default: !!form.is_default,
            is_active: !!form.is_active,
        };
        if ((form.iban || '').trim() !== '') payload.iban = form.iban.trim();
        if ((form.account_number || '').trim() !== '') payload.account_number = form.account_number.trim();
        if ((form.note || '').trim() !== '') payload.note = form.note.trim();
        if (formMode.value === 'edit' && editingId.value) {
            await client.patch(`/bank-accounts/${editingId.value}`, payload);
        } else {
            await client.post('/bank-accounts', payload);
        }
        formGeneralError.value = '';
        closeForm();
        emit('changed');
    } catch (err) {
        if (err.response?.status === 422) {
            formErrors.value = err.response.data?.errors || {};
            modalError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            modalError.value = err.response?.data?.message || t('Hesap kaydedilemedi.');
        }
    } finally {
        formSaving.value = false;
    }
}

const deletingAccount = ref(null);
const deleteSaving = ref(false);
const deleteError = ref('');

function askDelete(a) {
    deletingAccount.value = a;
    deleteError.value = '';
}

async function confirmDelete() {
    if (!deletingAccount.value) return;
    deleteSaving.value = true;
    deleteError.value = '';
    try {
        await client.delete(`/bank-accounts/${deletingAccount.value.id}`);
        deletingAccount.value = null;
        emit('changed');
    } catch (err) {
        // Hareket gören hesapta sunucu 422 döner; mesajı aynen göster.
        deleteError.value = err.response?.data?.message || t('Hesap silinirken bir hata oluştu.');
    } finally {
        deleteSaving.value = false;
    }
}
</script>
