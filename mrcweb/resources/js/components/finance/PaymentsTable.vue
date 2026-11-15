<template>
    <div>
        <div v-if="loading" class="space-y-2 py-4">
            <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>

        <div v-else-if="payments.length === 0" class="py-12 text-center flex flex-col items-center justify-center bg-[#131722]/40 rounded-xl border border-white/5 mt-4">
            <div class="w-14 h-14 rounded-2xl bg-[#212738] border border-white/10 flex items-center justify-center text-[#B9FF66] mb-3">
                <ArrowRightLeft class="w-7 h-7 stroke-[1.5]" />
            </div>
            <p class="text-sm font-bold text-white">{{ $t('Kayıtlı ödeme bulunamadı') }}</p>
            <p class="text-xs text-[#94A3B8] mt-1 mb-4">{{ $t('Filtrelere uygun ödeme yok. Yeni tahsilat veya ödeme kaydederek başlayın.') }}</p>
            <PillButton variant="primary" size="md" @click="$emit('create')">
                <Plus class="w-4 h-4 stroke-[3]" />
                <span>{{ $t('Yeni Ödeme Kaydet') }}</span>
            </PillButton>
        </div>

        <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
            <table class="w-full text-sm min-w-[980px]">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                        <th class="py-3 pr-3 font-semibold">{{ $t('Tarih') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Yön') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Cari') }}</th>
                        <th class="py-3 pr-3 font-semibold text-right">{{ $t('Tutar') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Yöntem') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Kasa / Banka') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Bağlı belge') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Dekont no') }}</th>
                        <th class="py-3 font-semibold text-right">{{ $t('İşlemler') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.06]">
                    <tr v-for="p in payments" :key="p.id" class="hover:bg-white/[0.03] transition-colors">
                        <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ formatDate(p.paid_at) }}</td>
                        <td class="py-3 pr-3">
                            <StatusPill :color="directionColor(p.direction)" :dot="false">{{ $t(directionLabelKey(p.direction)) }}</StatusPill>
                        </td>
                        <td class="py-3 pr-3">
                            <div class="font-semibold text-white">{{ p.party_name || '-' }}</div>
                            <div v-if="p.party_code" class="text-[11px] font-mono text-[#64748B]">{{ p.party_code }}</div>
                        </td>
                        <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="p.direction === 'in' ? 'text-[#B9FF66]' : 'text-[#FFAE7C]'">
                            {{ formatMoney(p.amount, p.currency_code) }}
                        </td>
                        <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ p.method ? $t(methodLabelKey(p.method)) : '-' }}</td>
                        <td class="py-3 pr-3 text-[#CBD5E1]">{{ p.bank_account_name || '-' }}</td>
                        <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ p.document_serial || '-' }}</td>
                        <td class="py-3 pr-3 text-[#CBD5E1]">{{ p.reference || '-' }}</td>
                        <td class="py-3 text-right" @click.stop>
                            <div class="inline-flex items-center gap-1.5">
                                <button
                                    v-if="p.note"
                                    type="button"
                                    :title="p.note"
                                    class="p-1.5 rounded-lg text-[#8CD5F4] hover:bg-[#459BBE]/20 border border-transparent hover:border-[#459BBE]/40 transition-colors cursor-pointer"
                                >
                                    <StickyNote class="w-4 h-4" />
                                </button>
                                <button
                                    type="button"
                                    @click="$emit('delete', p)"
                                    :title="$t('Sil')"
                                    class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between pt-4 mt-2 border-t border-white/[0.08]">
            <span class="text-xs text-[#94A3B8]">
                {{ $t('Toplam :total kayıt · Sayfa :cur / :last', { total: formatNumber(meta.total), cur: meta.current_page, last: meta.last_page }) }}
            </span>
            <div class="flex items-center gap-2">
                <PillButton variant="ghost" size="sm" :disabled="meta.current_page <= 1" @click="$emit('page', meta.current_page - 1)">{{ $t('Önceki') }}</PillButton>
                <PillButton variant="ghost" size="sm" :disabled="meta.current_page >= meta.last_page" @click="$emit('page', meta.current_page + 1)">{{ $t('Sonraki') }}</PillButton>
            </div>
        </div>
    </div>
</template>

<script setup>
import PillButton from '../ui/PillButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import { formatMoney, formatDate, formatNumber } from '../../utils/format';
import { directionLabelKey, directionColor, methodLabelKey } from './financeUtils';
import { ArrowRightLeft, Plus, Trash2, StickyNote } from 'lucide-vue-next';

defineProps({
    payments: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    meta: { type: Object, default: null },
});

defineEmits(['page', 'delete', 'create']);
</script>
