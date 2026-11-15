<template>
    <div class="space-y-6 select-none max-w-7xl mx-auto pb-12">
        <!-- Başlık + KPI Hapları + Aksiyon -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Stok Yönetimi') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                        {{ $t('Stok Yönetimi') }}
                    </h1>
                    <span v-if="loadingKpi" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                    <template v-else>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#B9FF66]">
                                <Package class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Toplam Ürün:') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatNumber(totalProducts) }}</span>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#FFA0A0]">
                                <AlertTriangle class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Kritik Stok:') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatNumber(criticalCount) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <PillButton variant="ghost" size="md" @click="showTransferModal = true">
                    <ArrowLeftRight class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Transfer') }}</span>
                </PillButton>
                <PillButton variant="primary" size="md" @click="openMovementModal">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Manuel Hareket') }}</span>
                </PillButton>
            </div>
        </div>

        <!-- Bildirimler -->
        <div
            v-if="globalSuccess"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-[#B9FF66] shadow-[0_0_20px_rgba(185,255,102,0.15)]"
        >
            <Check class="w-5 h-5 shrink-0 stroke-[2.5]" />
            <span class="text-sm font-semibold">{{ globalSuccess }}</span>
        </div>
        <div
            v-if="globalError"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0]"
        >
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span class="text-sm font-semibold">{{ globalError }}</span>
        </div>

        <!-- HAREKETLER KARTI -->
        <Card rounded="2xl" padding="md">
            <div class="flex items-center gap-3 pb-4 border-b border-white/[0.08] mb-4">
                <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                    <ArrowLeftRight class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-base font-bold text-white tracking-tight">{{ $t('Stok Hareketleri') }}</h2>
                    <p class="text-xs text-[#94A3B8]">{{ $t('Giriş, çıkış ve düzeltme kayıtları') }}</p>
                </div>
            </div>

            <!-- Filtre satırı -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-2">
                <select v-model="filters.warehouse_id" @change="resetPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                    <option value="">{{ $t('Tüm Depolar') }}</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
                <select v-model="filters.type" @change="resetPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                    <option value="">{{ $t('Tüm Tipler') }}</option>
                    <option value="in">{{ $t('Giriş') }}</option>
                    <option value="out">{{ $t('Çıkış') }}</option>
                    <option value="adjust">{{ $t('Düzeltme') }}</option>
                </select>
                <select v-model="filters.product_id" @change="resetPageAndFetch" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer col-span-2 md:col-span-1">
                    <option value="">{{ $t('Tüm Ürünler') }}</option>
                    <option v-for="p in productOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.sku ? ` (${p.sku})` : '' }}</option>
                </select>
                <input v-model="filters.from" @change="resetPageAndFetch" type="date" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
                <input v-model="filters.to" @change="resetPageAndFetch" type="date" class="px-3 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] [color-scheme:dark]" />
            </div>

            <div v-if="loadingMovements" class="space-y-2 py-4">
                <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
            </div>

            <div v-else-if="movements.length === 0" class="py-12 text-center flex flex-col items-center justify-center bg-[#131722]/40 rounded-xl border border-white/5 mt-4">
                <div class="w-14 h-14 rounded-2xl bg-[#212738] border border-white/10 flex items-center justify-center text-[#B9FF66] mb-3">
                    <ArrowLeftRight class="w-7 h-7 stroke-[1.5]" />
                </div>
                <p class="text-sm font-bold text-white">{{ $t('Hareket kaydı bulunamadı') }}</p>
                <p class="text-xs text-[#94A3B8] mt-1 mb-4">{{ $t('Filtreleri değiştirin veya ilk manuel hareketi oluşturun.') }}</p>
                <PillButton variant="primary" size="md" @click="openMovementModal">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Manuel Hareket Ekle') }}</span>
                </PillButton>
            </div>

            <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                <table class="w-full text-sm min-w-[820px]">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                            <th class="py-3 pr-3 font-semibold">{{ $t('Tarih') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Ürün') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Depo') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Tip') }}</th>
                            <th class="py-3 pr-3 font-semibold text-right">{{ $t('Miktar') }}</th>
                            <th class="py-3 pr-3 font-semibold text-right">{{ $t('Maliyet') }}</th>
                            <th class="py-3 font-semibold">{{ $t('Not') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.06]">
                        <tr v-for="m in movements" :key="m.id" class="hover:bg-white/[0.03] transition-colors">
                            <td class="py-3 pr-3 text-xs text-[#94A3B8] whitespace-nowrap">{{ formatDateRow(m.created_at) }}</td>
                            <td class="py-3 pr-3 font-semibold text-white">{{ m.product_name || '-' }}</td>
                            <td class="py-3 pr-3 text-[#CBD5E1]">{{ m.warehouse_name || '-' }}</td>
                            <td class="py-3 pr-3">
                                <StatusPill :color="movementColor(m.type)" :dot="false">{{ $t(movementLabel(m.type)) }}</StatusPill>
                            </td>
                            <td class="py-3 pr-3 text-right font-bold whitespace-nowrap" :class="quantityClass(m)">
                                {{ formatQuantity(m) }}
                            </td>
                            <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ m.unit_cost !== null && m.unit_cost !== undefined ? formatNumber(m.unit_cost) : '-' }}</td>
                            <td class="py-3 text-xs text-[#94A3B8] max-w-[220px] truncate" :title="m.note || ''">{{ m.note || '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="movementMeta && movementMeta.last_page > 1" class="flex items-center justify-between pt-4 mt-2 border-t border-white/[0.08]">
                <span class="text-xs text-[#94A3B8]">
                    {{ $t('Toplam :total kayıt · Sayfa :cur / :last', { total: formatNumber(movementMeta.total), cur: movementMeta.current_page, last: movementMeta.last_page }) }}
                </span>
                <div class="flex items-center gap-2">
                    <PillButton variant="ghost" size="sm" :disabled="movementMeta.current_page <= 1" @click="movementPage--; fetchMovements()">{{ $t('Önceki') }}</PillButton>
                    <PillButton variant="ghost" size="sm" :disabled="movementMeta.current_page >= movementMeta.last_page" @click="movementPage++; fetchMovements()">{{ $t('Sonraki') }}</PillButton>
                </div>
            </div>
        </Card>

        <!-- DEPOLAR KARTI -->
        <Card rounded="2xl" padding="md">
            <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#8CD5F4]">
                        <Warehouse class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white tracking-tight">{{ $t('Depolar') }}</h2>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Stokların tutulduğu fiziksel depolar') }}</p>
                    </div>
                </div>
                <span class="text-xs font-semibold px-3 py-1 rounded-full bg-[#131722] border border-white/10 text-white">
                    {{ formatNumber(warehouses.length) }} {{ $t('Depo') }}
                </span>
            </div>

            <div v-if="loadingWarehouses" class="space-y-2">
                <div v-for="i in 2" :key="i" class="h-12 bg-[#131722]/60 rounded-xl animate-pulse"></div>
            </div>
            <div v-else-if="warehouses.length === 0" class="text-center py-6 text-xs text-[#94A3B8]">
                {{ $t('Henüz depo tanımlanmamış. Aşağıdaki formu kullanarak ilk deponuzu ekleyin.') }}
            </div>
            <div v-else class="space-y-2 mb-4">
                <div v-for="w in warehouses" :key="w.id" class="flex items-center justify-between gap-2 p-3 bg-[#131722] rounded-xl border border-white/10">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-sm font-semibold text-white truncate">{{ w.name }}</span>
                        <StatusPill v-if="w.is_default" color="lime" :dot="false">{{ $t('Varsayılan') }}</StatusPill>
                        <span v-if="w.address" class="text-[11px] text-[#94A3B8] truncate hidden sm:inline">· {{ w.address }}</span>
                    </div>
                    <div class="inline-flex items-center gap-1.5 shrink-0">
                        <button type="button" @click="startWarehouseEdit(w)" :title="$t('Düzenle')" class="p-1.5 rounded-lg text-[#94A3B8] hover:bg-white/10 border border-transparent hover:border-white/20 transition-colors cursor-pointer">
                            <Pencil class="w-4 h-4" />
                        </button>
                        <button type="button" @click="deletingWarehouse = w" :title="$t('Sil')" class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submitWarehouse" class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
                <p class="text-xs font-semibold text-white">{{ editingWarehouse ? $t('Depoyu Düzenle') : $t('Yeni Depo') }}</p>
                <div v-if="warehouseGeneralError" class="p-2.5 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ warehouseGeneralError }}</div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input v-model="warehouseForm.name" type="text" :placeholder="$t('Depo adı *')" class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    <input v-model="warehouseForm.address" type="text" :placeholder="$t('Adres')" class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] sm:col-span-1" />
                    <label class="flex items-center gap-2 text-xs text-[#CBD5E1] cursor-pointer px-1">
                        <input v-model="warehouseForm.is_default" type="checkbox" class="w-4 h-4 accent-[#B9FF66] cursor-pointer" />
                        {{ $t('Varsayılan depo') }}
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <PillButton v-if="editingWarehouse" type="button" variant="ghost" size="sm" @click="cancelWarehouseEdit">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="submit" variant="secondary" size="sm" :loading="warehouseSaving">
                        <Plus v-if="!editingWarehouse" class="w-3.5 h-3.5" /> {{ editingWarehouse ? $t('Güncelle') : $t('Ekle') }}
                    </PillButton>
                </div>
            </form>
        </Card>

        <!-- SAYIMLAR KARTI  -->
        <StockCountCard :warehouses="warehouses" />
        <!-- MANUEL HAREKET MODAL -->
        <div v-if="showMovementModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs" @click.self="closeMovementModal">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">{{ $t('Manuel Stok Hareketi') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Depoya giriş, çıkış veya düzeltme kaydedin') }}</p>
                    </div>
                    <button type="button" @click="closeMovementModal" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div v-if="movementGeneralError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">
                    {{ movementGeneralError }}
                </div>
                <form @submit.prevent="submitMovement" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Ürün') }} <span class="text-[#B9FF66]">*</span></label>
                        <select v-model="movementForm.product_id" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option :value="null">{{ $t('Seçiniz') }}</option>
                            <option v-for="p in productOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.sku ? ` (${p.sku})` : '' }}</option>
                        </select>
                        <p v-if="movementErrors.product_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(movementErrors.product_id) }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Depo') }} <span class="text-[#B9FF66]">*</span></label>
                            <select v-model="movementForm.warehouse_id" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option :value="null">{{ $t('Seçiniz') }}</option>
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                            </select>
                            <p v-if="movementErrors.warehouse_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(movementErrors.warehouse_id) }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Hareket Tipi') }} <span class="text-[#B9FF66]">*</span></label>
                            <select v-model="movementForm.type" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                <option value="in">{{ $t('Giriş') }}</option>
                                <option value="out">{{ $t('Çıkış') }}</option>
                                <option value="adjust">{{ $t('Düzeltme') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Miktar') }} <span class="text-[#B9FF66]">*</span></label>
                            <input v-model.number="movementForm.quantity" type="number" step="0.01" placeholder="0" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                            <p v-if="movementErrors.quantity" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(movementErrors.quantity) }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Birim Maliyet') }}</label>
                            <input v-model.number="movementForm.unit_cost" type="number" step="0.01" min="0" placeholder="0" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        </div>
                    </div>
                    <p class="text-[11px] leading-relaxed text-[#94A3B8] bg-[#131722] p-3 rounded-xl border border-white/5">
                        {{ $t("Giriş ve çıkış miktarı 0'dan büyük olmalıdır. Düzeltme sıfırdan farklı olmalıdır (negatif olabilir — örneğin kırık ürün için −1).") }}
                    </p>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Not') }}</label>
                        <textarea v-model="movementForm.note" rows="2" :placeholder="$t('Hareket açıklaması...')" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                    </div>
                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-white/[0.08]">
                        <PillButton type="button" variant="ghost" size="md" @click="closeMovementModal">{{ $t('Vazgeç') }}</PillButton>
                        <PillButton type="submit" variant="primary" size="md" :loading="movementSaving">{{ $t('Kaydet') }}</PillButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- DEPO SİLME ONAY MODALI -->
        <div v-if="deletingWarehouse" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Depo Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ deletingWarehouse.name }}</strong> {{ $t('kalıcı olarak silinecektir.') }}
                    {{ $t('Stok hareketi gören depolar silinemez.') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="warehouseSaving" @click="deletingWarehouse = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="warehouseSaving" @click="deleteWarehouse(deletingWarehouse)">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>

        <TransferModal
            :open="showTransferModal"
            :warehouses="warehouses"
            :products="productOptions"
            @close="showTransferModal = false"
            @done="onTransferDone"
        />
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import TransferModal from '../components/TransferModal.vue';
import StockCountCard from '../components/StockCountCard.vue';
import client from '../api/client';
import { t } from '../i18n';
import { formatNumber, formatDate } from '../utils/format';
import {
    Package, AlertTriangle, ArrowLeftRight, Warehouse,
    Plus, Pencil, Trash2, Check, AlertCircle, X,
} from 'lucide-vue-next';

const globalSuccess = ref('');
const globalError = ref('');

function triggerSuccess(msg) {
    globalSuccess.value = msg;
    globalError.value = '';
    setTimeout(() => { if (globalSuccess.value === msg) globalSuccess.value = ''; }, 4500);
}
function triggerError(msg) {
    globalError.value = msg;
    setTimeout(() => { if (globalError.value === msg) globalError.value = ''; }, 5000);
}
function firstMsg(err) {
    if (!err) return '';
    return Array.isArray(err) ? err[0] : err;
}
function formatDateRow(ds) {
    return formatDate(ds, {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

// --- Tip rozeti (UI işareti TİPTEN türetilir; etiket anahtar olarak döner) ---
function movementLabel(tip) {
    if (tip === 'in') return 'Giriş';
    if (tip === 'out') return 'Çıkış';
    if (tip === 'adjust') return 'Düzeltme';
    return tip || '-';
}
function movementColor(t) {
    if (t === 'in') return 'lime';
    if (t === 'out') return 'coral';
    if (t === 'adjust') return 'orange';
    return 'neutral';
}
function signedQuantity(m) {
    const q = Number(m.quantity) || 0;
    const abs = Math.abs(q);
    if (m.type === 'in') return abs;
    if (m.type === 'out') return -abs;
    return q; // adjust: işaret korunur
}
function formatQuantity(m) {
    const s = signedQuantity(m);
    const prefix = s < 0 ? '−' : '+';
    return `${prefix}${formatNumber(Math.abs(s))}`;
}
function quantityClass(m) {
    const s = signedQuantity(m);
    if (s < 0) return 'text-[#FFA0A0]';
    if (s > 0) return 'text-[#B9FF66]';
    return 'text-[#94A3B8]';
}

// --- Hareket listesi ---
const movements = ref([]);
const movementMeta = ref(null);
const loadingMovements = ref(true);
const movementPage = ref(1);
const filters = reactive({ warehouse_id: '', type: '', product_id: '', from: '', to: '' });
let movementsReq = 0;

async function fetchMovements() {
    const reqId = ++movementsReq;
    loadingMovements.value = true;
    try {
        const params = { page: movementPage.value };
        if (filters.warehouse_id) params.warehouse_id = filters.warehouse_id;
        if (filters.type) params.type = filters.type;
        if (filters.product_id) params.product_id = filters.product_id;
        if (filters.from) params.from = filters.from;
        if (filters.to) params.to = filters.to;
        const res = await client.get('/stock-movements', { params });
        if (reqId !== movementsReq) return; // eski yanıt: hızlı filtre değişiminde üzerine yazma
        movements.value = res.data?.data || [];
        movementMeta.value = res.data?.meta || null;
    } catch (err) {
        if (reqId !== movementsReq) return;
        triggerError(err.response?.data?.message || t('Stok hareketleri yüklenemedi.'));
    } finally {
        if (reqId === movementsReq) loadingMovements.value = false;
    }
}
function resetPageAndFetch() {
    movementPage.value = 1;
    fetchMovements();
}

// --- Depolar ---
const warehouses = ref([]);
const loadingWarehouses = ref(true);
const warehouseSaving = ref(false);
const warehouseGeneralError = ref('');
const editingWarehouse = ref(null);
const deletingWarehouse = ref(null);
const warehouseForm = reactive({ name: '', address: '', is_default: false });

async function fetchWarehouses() {
    loadingWarehouses.value = true;
    try {
        const res = await client.get('/warehouses');
        warehouses.value = res.data?.data || res.data?.warehouses || [];
    } catch (err) {
        triggerError(err.response?.data?.message || t('Depolar yüklenemedi.'));
    } finally {
        loadingWarehouses.value = false;
    }
}
function startWarehouseEdit(w) {
    editingWarehouse.value = w;
    warehouseForm.name = w.name || '';
    warehouseForm.address = w.address || '';
    warehouseForm.is_default = !!w.is_default;
    warehouseGeneralError.value = '';
}
function cancelWarehouseEdit() {
    editingWarehouse.value = null;
    warehouseForm.name = '';
    warehouseForm.address = '';
    warehouseForm.is_default = false;
    warehouseGeneralError.value = '';
}
async function submitWarehouse() {
    if (!warehouseForm.name.trim()) {
        warehouseGeneralError.value = t('Depo adı zorunludur.');
        return;
    }
    warehouseSaving.value = true;
    warehouseGeneralError.value = '';
    try {
        const payload = {
            name: warehouseForm.name.trim(),
            is_default: warehouseForm.is_default,
        };
        if (warehouseForm.address) payload.address = warehouseForm.address;
        if (editingWarehouse.value) {
            await client.patch(`/warehouses/${editingWarehouse.value.id}`, payload);
            triggerSuccess(t('Depo güncellendi.'));
        } else {
            await client.post('/warehouses', payload);
            triggerSuccess(t('Depo eklendi.'));
        }
        cancelWarehouseEdit();
        await fetchWarehouses();
    } catch (err) {
        if (err.response?.status === 422) {
            const errs = err.response.data?.errors || {};
            warehouseGeneralError.value = firstMsg(errs.name) || err.response.data?.message || t('Depo kaydedilemedi.');
        } else {
            warehouseGeneralError.value = err.response?.data?.message || t('Depo kaydedilemedi.');
        }
    } finally {
        warehouseSaving.value = false;
    }
}
async function deleteWarehouse(w) {
    deletingWarehouse.value = null;
    warehouseSaving.value = true;
    try {
        await client.delete(`/warehouses/${w.id}`);
        triggerSuccess(t('Depo silindi.'));
        await fetchWarehouses();
    } catch (err) {
        // 422: hareket gören depo silinemez → sunucu message'ını göster
        triggerError(err.response?.data?.message || t('Depo silinirken bir hata oluştu.'));
    } finally {
        warehouseSaving.value = false;
    }
}

// --- Ürün seçenekleri + KPI ---
const productOptions = ref([]);
const totalProducts = ref(0);
const criticalCount = ref(0);
const loadingKpi = ref(true);

async function fetchProductOptions() {
    try {
        const res = await client.get('/products', { params: { per_page: 100 } });
        productOptions.value = res.data?.data || [];
        totalProducts.value = res.data?.meta?.total ?? productOptions.value.length;
        // Kritik stok sayacı: sunucu tarafı critical filtresi (100+ üründe de doğru)
        try {
            const crit = await client.get('/products', { params: { critical: 1, per_page: 1 } });
            criticalCount.value = crit.data?.meta?.total ?? 0;
        } catch {
            criticalCount.value = productOptions.value.filter((p) => {
                if (!p.track_inventory) return false;
                if (p.stock_quantity === null || p.stock_quantity === undefined) return false;
                return Number(p.stock_quantity) < Number(p.min_stock || 0);
            }).length;
        }
    } catch (err) {
        triggerError(err.response?.data?.message || t('Ürün seçenekleri yüklenemedi.'));
    } finally {
        loadingKpi.value = false;
    }
}

// --- Manuel hareket modal ---
const showMovementModal = ref(false);
const movementSaving = ref(false);
const movementErrors = ref({});
const movementGeneralError = ref('');
const movementForm = reactive({
    product_id: null, warehouse_id: null, type: 'in',
    quantity: '', unit_cost: '', note: '',
});
function openMovementModal() {
    movementErrors.value = {};
    movementGeneralError.value = '';
    showMovementModal.value = true;
}
function closeMovementModal() {
    showMovementModal.value = false;
}
async function submitMovement() {
    movementSaving.value = true;
    movementErrors.value = {};
    movementGeneralError.value = '';
    try {
        const payload = {
            product_id: movementForm.product_id,
            warehouse_id: movementForm.warehouse_id,
            type: movementForm.type,
            quantity: movementForm.quantity,
        };
        if (movementForm.unit_cost !== '' && movementForm.unit_cost !== null) payload.unit_cost = movementForm.unit_cost;
        if (movementForm.note) payload.note = movementForm.note;
        const res = await client.post('/stock-movements', payload);
        const stockQty = res.data?.stock_quantity ?? res.data?.data?.stock_quantity;
        closeMovementModal();
        Object.assign(movementForm, {
            product_id: null, warehouse_id: null, type: 'in',
            quantity: '', unit_cost: '', note: '',
        });
        if (stockQty !== undefined && stockQty !== null) {
            triggerSuccess(t('Hareket kaydedildi. Güncel stok: :qty', { qty: formatNumber(stockQty) }));
        } else {
            triggerSuccess(t('Stok hareketi kaydedildi.'));
        }
        movementPage.value = 1;
        await Promise.allSettled([fetchMovements(), fetchProductOptions()]);
    } catch (err) {
        if (err.response?.status === 422) {
            movementErrors.value = err.response.data?.errors || {};
            movementGeneralError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            movementGeneralError.value = err.response?.data?.message || t('Stok hareketi kaydedilemedi.');
        }
    } finally {
        movementSaving.value = false;
    }
}

// --- Depo transferi  ---
const showTransferModal = ref(false);

function onTransferDone() {
    triggerSuccess(t('Transfer tamamlandı.'));
    movementPage.value = 1;
    Promise.allSettled([fetchMovements(), fetchProductOptions()]);
}

onMounted(async () => {
    await Promise.allSettled([fetchMovements(), fetchWarehouses(), fetchProductOptions()]);
});
</script>
