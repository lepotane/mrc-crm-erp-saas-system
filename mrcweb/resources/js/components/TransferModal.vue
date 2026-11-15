<!-- Depolar arası transfer : tek işlemde out+in, toplam korunur. -->
<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[90] flex items-center justify-center p-4 bg-black/70" @click.self="close">
            <Card rounded="2xl" padding="lg" class="w-full max-w-md">
                <h3 class="text-lg font-bold text-white mb-1">{{ $t('Depolar Arası Transfer') }}</h3>
                <p class="text-xs text-[#94A3B8] mb-4">{{ $t('Kaynakta yeterli stok olmalı; toplam değişmez.') }}</p>

                <p v-if="error" class="mb-3 text-xs font-semibold text-[#FFA0A0]">{{ error }}</p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Ürün') }}</label>
                        <select v-model="form.product_id" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option :value="null">{{ $t('Seçiniz') }}</option>
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Kaynak Depo') }}</label>
                            <select v-model="form.from_warehouse_id" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option :value="null">{{ $t('Seçiniz') }}</option>
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Hedef Depo') }}</label>
                            <select v-model="form.to_warehouse_id" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option :value="null">{{ $t('Seçiniz') }}</option>
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Miktar') }}</label>
                        <input v-model.number="form.quantity" type="number" min="0.5" step="0.5" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm text-right focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1.5">{{ $t('Not') }}</label>
                        <input v-model="form.note" type="text" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-5 pt-4 border-t border-white/[0.08]">
                    <PillButton type="button" variant="ghost" size="md" @click="close">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="primary" size="md" :loading="saving" @click="submit">{{ $t('Transferi Bitir') }}</PillButton>
                </div>
            </Card>
        </div>
    </Teleport>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import Card from './ui/Card.vue';
import PillButton from './ui/PillButton.vue';
import client from '../api/client';
import { t } from '../i18n';

const props = defineProps({
    open: { type: Boolean, default: false },
    warehouses: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'done']);

const form = reactive({ product_id: null, from_warehouse_id: null, to_warehouse_id: null, quantity: '', note: '' });
const error = ref('');
const saving = ref(false);

watch(() => props.open, (v) => {
    if (v) {
        Object.assign(form, { product_id: null, from_warehouse_id: null, to_warehouse_id: null, quantity: '', note: '' });
        error.value = '';
    }
});

function close() {
    emit('close');
}

async function submit() {
    saving.value = true;
    error.value = '';
    try {
        await client.post('/stock-transfers', {
            product_id: form.product_id,
            from_warehouse_id: form.from_warehouse_id,
            to_warehouse_id: form.to_warehouse_id,
            quantity: form.quantity,
            note: form.note || undefined,
        });
        emit('done');
        close();
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        error.value = Object.values(bag).flat()[0]
            || err.response?.data?.message
            || t('Transfer yapılamadı.');
    } finally {
        saving.value = false;
    }
}
</script>
