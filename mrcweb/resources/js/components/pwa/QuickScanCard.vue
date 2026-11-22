<!-- Mevcut BarcodeScanner'ı saran küçük hızlı işlem kartı.
     Yeni bağımlılık yok: kamera/okuma composable'ı ve scanner bileşeni zaten var.
     Okunan ürün/QR değeri mevcut 'urunler' / 'stok' rotalarına search sorgusuyla taşınır. -->
<template>
    <Card rounded="2xl" padding="sm" class="w-56 max-w-full">
        <button
            type="button"
            class="w-full flex items-center gap-3 p-1.5 text-left rounded-xl hover:bg-white/5 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#B9FF66]"
            :title="$t('Barkod tara')"
            @click="open = true"
        >
            <span class="w-9 h-9 rounded-xl bg-[#B9FF66] text-[#131722] flex items-center justify-center shrink-0">
                <ScanBarcode class="w-5 h-5" />
            </span>
            <span class="min-w-0">
                <span class="block text-xs font-black text-white">{{ $t('Hızlı Tara') }}</span>
                <span class="block text-[10px] text-[#94A3B8] truncate">{{ $t('Barkod tara') }}</span>
            </span>
        </button>

        <template v-if="lastCode">
            <p class="mt-2 px-1 text-[10px] text-[#64748B] truncate">
                {{ $t('Son tarama: :code', { code: lastCode }) }}
            </p>
            <div class="flex gap-1.5 mt-2">
                <button
                    type="button"
                    class="flex-1 px-2 py-1.5 rounded-lg bg-white/5 text-[10px] font-bold text-[#B9FF66] hover:bg-white/10"
                    @click="go('urunler')"
                >
                    {{ $t('Ürünü aç') }}
                </button>
                <button
                    type="button"
                    class="flex-1 px-2 py-1.5 rounded-lg bg-white/5 text-[10px] font-bold text-[#94A3B8] hover:text-white hover:bg-white/10"
                    @click="go('stok')"
                >
                    {{ $t('Stokta gör') }}
                </button>
            </div>
        </template>

        <BarcodeScanner :open="open" @close="open = false" @resolve="onResolve" />
    </Card>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ScanBarcode } from 'lucide-vue-next';
import Card from '../ui/Card.vue';
import BarcodeScanner from '../BarcodeScanner.vue';

const router = useRouter();
const open = ref(false);
const lastCode = ref('');

function onResolve(product) {
    open.value = false;
    const code = product?.barcode || product?.sku || product?.id || '';
    lastCode.value = String(code || '');
    router.push({ name: 'urunler', query: code ? { search: String(code) } : {} });
}

function go(name) {
    router.push({ name, query: lastCode.value ? { search: lastCode.value } : {} });
}
</script>
