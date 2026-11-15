<!-- Para birimi kırılımı: by_currency haritası varsa para birimi bazında ayrı
     satırlar. Tek sayıda toplama YAPILMAZ — her para birimi kendi satırında. -->
<template>
    <div v-if="entries.length" class="mt-4 pt-4 border-t border-white/[0.08]">
        <h4 class="text-[11px] font-bold uppercase tracking-wider text-[#64748B] mb-2">
            {{ $t('Para Birimine Göre') }}
        </h4>
        <div class="overflow-x-auto">
            <table class="w-full text-xs min-w-[480px]">
                <thead>
                    <tr class="text-left text-[10px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                        <th class="py-1.5 pr-2 font-semibold">{{ $t('Para Birimi') }}</th>
                        <th
                            v-for="f in fields"
                            :key="f.key"
                            class="py-1.5 pr-2 font-semibold text-right"
                        >
                            {{ $t(f.labelKey) }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.06]">
                    <tr v-for="[ccy, vals] in entries" :key="ccy">
                        <td class="py-1.5 pr-2 font-bold text-white">{{ ccy }}</td>
                        <td
                            v-for="f in fields"
                            :key="f.key"
                            class="py-1.5 pr-2 text-right text-[#CBD5E1] whitespace-nowrap"
                        >
                            {{ formatMoney(vals?.[f.key], ccy) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatMoney } from '../../utils/format';
import { byCurrencyEntries } from './reportUtils';

const props = defineProps({
    // Sunucu by_currency haritası: { TRY: {...}, USD: {...} } (boşken {})
    map: { type: Object, default: () => ({}) },
    // [{ key: 'gross_total', labelKey: 'Brüt Toplam' }]
    fields: { type: Array, default: () => [] },
});

const entries = computed(() => byCurrencyEntries(props.map));
</script>
