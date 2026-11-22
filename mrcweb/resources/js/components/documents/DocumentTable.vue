<template>
    <div>
        <div v-if="loading" class="space-y-2 py-4">
            <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
        </div>

        <div v-else-if="documents.length === 0" class="py-12 text-center flex flex-col items-center justify-center bg-[#131722]/40 rounded-xl border border-white/5 mt-4">
            <div class="w-14 h-14 rounded-2xl bg-[#212738] border border-white/10 flex items-center justify-center text-[#B9FF66] mb-3">
                <FileText class="w-7 h-7 stroke-[1.5]" />
            </div>
            <p class="text-sm font-bold text-white">{{ $t('Belge bulunamadı') }}</p>
            <p class="text-xs text-[#94A3B8] mt-1 mb-4">{{ $t('Filtrelere uygun belge yok. Yeni belge oluşturarak başlayın.') }}</p>
            <PillButton variant="primary" size="md" @click="$emit('create')">
                <Plus class="w-4 h-4 stroke-[3]" />
                <span>{{ $t('Yeni Belge Ekle') }}</span>
            </PillButton>
        </div>

        <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
            <table class="w-full text-sm min-w-[1100px]">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                        <th class="py-3 pr-3 font-semibold">{{ $t('Seri') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Sipariş No') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Ödeme / Kargo') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Cari') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Kesim') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Vade') }}</th>
                        <th class="py-3 pr-3 font-semibold">{{ $t('Durum') }}</th>
                        <th class="py-3 pr-3 font-semibold text-right">{{ $t('Genel toplam') }}</th>
                        <th class="py-3 font-semibold text-right">{{ $t('İşlemler') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.06]">
                    <tr
                        v-for="d in documents"
                        :key="d.id"
                        @click="$emit('view', d.id)"
                        class="hover:bg-white/[0.03] transition-colors cursor-pointer"
                    >
                        <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8] whitespace-nowrap">{{ documentSerial(d) }}</td>
                        <td class="py-3 pr-3 font-mono text-xs text-[#B6EAFF] whitespace-nowrap">{{ d.external_no || '—' }}</td>
                        <td class="py-3 pr-3 whitespace-nowrap">
                            <span
                                v-if="channelSummary(d)"
                                class="inline-block max-w-[170px] truncate px-2 py-0.5 rounded-md border border-white/10 bg-white/5 text-[11px] text-[#CBD5E1]"
                                :title="channelSummary(d)"
                            >{{ channelSummary(d) }}</span>
                            <span v-else class="text-[#64748B]">—</span>
                        </td>
                        <td class="py-3 pr-3 font-semibold text-white">{{ partyOf(d) }}</td>
                        <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ formatDate(d.issue_date) }}</td>
                        <td class="py-3 pr-3 text-[#CBD5E1] whitespace-nowrap">{{ formatDate(d.due_date) }}</td>
                        <td class="py-3 pr-3">
                            <StatusPill :color="documentStatusColor(d.status)" :dot="false">{{ $t(documentStatusLabel(d.status)) }}</StatusPill>
                        </td>
                        <td class="py-3 pr-3 text-right font-bold text-white whitespace-nowrap">{{ formatMoney(totalOf(d), d.currency_code) }}</td>
                        <td class="py-3 text-right" @click.stop>
                            <div class="inline-flex items-center gap-1.5">
                                <button
                                    type="button"
                                    @click="$emit('view', d.id)"
                                    :title="$t('Görüntüle')"
                                    class="p-1.5 rounded-lg text-[#8CD5F4] hover:bg-[#459BBE]/20 border border-transparent hover:border-[#459BBE]/40 transition-colors cursor-pointer"
                                >
                                    <Eye class="w-4 h-4" />
                                </button>
                                <button
                                    v-if="convertTargetFor(d)"
                                    type="button"
                                    @click="$emit('convert', d)"
                                    :title="$t(convertLabelFor(d))"
                                    class="p-1.5 rounded-lg text-[#E6A6F3] hover:bg-[#BC5FCF]/20 border border-transparent hover:border-[#BC5FCF]/40 transition-colors cursor-pointer"
                                >
                                    <ArrowRightLeft class="w-4 h-4" />
                                </button>
                                <button
                                    v-if="d.status === 'draft'"
                                    type="button"
                                    @click="$emit('edit', d)"
                                    :title="$t('Düzenle')"
                                    class="p-1.5 rounded-lg text-[#94A3B8] hover:bg-white/10 border border-transparent hover:border-white/20 transition-colors cursor-pointer"
                                >
                                    <Pencil class="w-4 h-4" />
                                </button>
                                <button
                                    type="button"
                                    @click="$emit('pdf', d)"
                                    :title="$t('PDF indir')"
                                    class="p-1.5 rounded-lg text-[#94A3B8] hover:bg-white/10 border border-transparent hover:border-white/20 transition-colors cursor-pointer"
                                >
                                    <FileDown class="w-4 h-4" />
                                </button>
                                <button
                                    v-if="d.status === 'draft'"
                                    type="button"
                                    @click="$emit('delete', d)"
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
import { documentStatusLabel, documentStatusColor, convertTargetFor, convertLabelFor, documentSerial } from './documentUtils';
import { Eye, Pencil, Trash2, Plus, FileText, FileDown, ArrowRightLeft } from 'lucide-vue-next';

defineProps({
    documents: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    meta: { type: Object, default: null },
});

defineEmits(['view', 'edit', 'delete', 'convert', 'page', 'create', 'pdf']);

function partyOf(d) {
    return d.party?.name ?? d.party_name ?? '-';
}
function totalOf(d) {
    return d.grand_total ?? d.total ?? d.totals?.grand_total ?? d.totals?.total ?? 0;
}

// channel_meta null/geçersiz/string olabilir — hepsi sessizce yok sayılır.
function channelMetaOf(d) {
    const meta = d?.channel_meta;
    if (!meta) return null;
    if (typeof meta === 'string') {
        try { return JSON.parse(meta) || null; } catch { return null; }
    }
    return typeof meta === 'object' ? meta : null;
}
function channelSummary(d) {
    const meta = channelMetaOf(d);
    if (!meta) return '';
    const parts = [];
    if (meta.payment_method) parts.push(String(meta.payment_method));
    if (meta.cargo_company) parts.push(String(meta.cargo_company));
    if (meta.tracking_number) parts.push(String(meta.tracking_number));
    return parts.join(' · ');
}
</script>
