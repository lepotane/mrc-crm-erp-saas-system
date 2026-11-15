<template>
    <div class="space-y-6 select-none max-w-7xl mx-auto pb-12">
        <!-- Başlık + Aksiyon -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Ürünler & Hizmetler') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                        {{ $t('Ürünler & Hizmetler') }}
                    </h1>
                    <span v-if="loadingProducts" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                    <StatusPill v-else color="violet" :dot="true">{{ formatNumber(productMeta?.total ?? products.length) }} {{ $t('Ürün') }}</StatusPill>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <PillButton v-if="activeTab === 'urunler'" variant="primary" size="md" @click="openCreate">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Yeni Ürün') }}</span>
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

        <!-- Sekmeler -->
        <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#212738] border border-white/10 w-fit">
            <button
                type="button"
                @click="activeTab = 'urunler'"
                class="px-5 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                :class="activeTab === 'urunler' ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]' : 'text-[#94A3B8] hover:text-white'"
            >
                {{ $t('Ürünler') }}
            </button>
            <button
                type="button"
                @click="activeTab = 'tanimlar'"
                class="px-5 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                :class="activeTab === 'tanimlar' ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]' : 'text-[#94A3B8] hover:text-white'"
            >
                {{ $t('Tanımlar') }}
            </button>
        </div>

        <!-- ÜRÜNLER SEKMESİ -->
        <Card v-if="activeTab === 'urunler'" rounded="2xl" padding="md">
            <div class="flex flex-col md:flex-row md:items-center gap-3 pb-4 border-b border-white/[0.08] mb-2">
                <select
                    v-model="categoryFilter"
                    @change="productPage = 1; fetchProducts()"
                    class="px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer md:w-56"
                >
                    <option value="">{{ $t('Tüm Kategoriler') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div class="relative md:ml-auto md:w-72">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[#64748B]" />
                    <input
                        v-model="productSearch"
                        type="text"
                        :placeholder="$t('Ad, SKU veya barkod ile ara...')"
                        class="w-full pl-10 pr-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    />
                </div>
            </div>

            <div v-if="loadingProducts" class="space-y-2 py-4">
                <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
            </div>

            <div v-else-if="products.length === 0" class="py-12 text-center flex flex-col items-center justify-center bg-[#131722]/40 rounded-xl border border-white/5 mt-4">
                <div class="w-14 h-14 rounded-2xl bg-[#212738] border border-white/10 flex items-center justify-center text-[#E6A6F3] mb-3">
                    <Package class="w-7 h-7 stroke-[1.5]" />
                </div>
                <p class="text-sm font-bold text-white">{{ $t('Kayıtlı ürün bulunamadı') }}</p>
                <p class="text-xs text-[#94A3B8] mt-1 mb-4">{{ $t('İlk ürün kartınızı oluşturarak başlayın.') }}</p>
                <PillButton variant="primary" size="md" @click="openCreate">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Yeni Ürün Ekle') }}</span>
                </PillButton>
            </div>

            <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                <table class="w-full text-sm min-w-[900px]">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                            <th class="py-3 pr-3 font-semibold">{{ $t('Ad') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Kategori') }}</th>
                            <th class="py-3 pr-3 font-semibold">SKU</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Barkod') }}</th>
                            <th class="py-3 pr-3 font-semibold text-right">{{ $t('Alış') }}</th>
                            <th class="py-3 pr-3 font-semibold text-right">{{ $t('Satış') }}</th>
                            <th class="py-3 pr-3 font-semibold text-center">{{ $t('KDV') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Birim') }}</th>
                            <th class="py-3 pr-3 font-semibold text-right">{{ $t('Stok') }}</th>
                            <th class="py-3 font-semibold text-right">{{ $t('İşlemler') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.06]">
                        <tr v-for="p in products" :key="p.id" class="hover:bg-white/[0.03] transition-colors">
                            <td class="py-3 pr-3 font-semibold text-white">{{ p.name }}</td>
                            <td class="py-3 pr-3">
                                <span v-if="p.category" class="inline-flex items-center gap-1.5 text-xs text-[#CBD5E1]">
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: p.category.color || '#64748B' }"></span>
                                    {{ p.category.name }}
                                </span>
                                <span v-else class="text-xs text-[#64748B]">-</span>
                            </td>
                            <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8]">{{ p.sku || '-' }}</td>
                            <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8]">{{ p.barcode || '-' }}</td>
                            <td class="py-3 pr-3 text-right text-[#CBD5E1] whitespace-nowrap">{{ formatMoney(p.cost_price, p.currency_code) }}</td>
                            <td class="py-3 pr-3 text-right text-white font-semibold whitespace-nowrap">{{ formatMoney(p.sale_price, p.currency_code) }}</td>
                            <td class="py-3 pr-3 text-center text-[#CBD5E1]">%{{ p.tax_rate ?? '-' }}</td>
                            <td class="py-3 pr-3 text-[#CBD5E1]">{{ p.unit?.code || p.unit?.name || '-' }}</td>
                            <td class="py-3 pr-3 text-right">
                                <span
                                    v-if="p.stock_quantity === null || p.stock_quantity === undefined"
                                    class="text-[#64748B]"
                                >-</span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold border"
                                    :class="isCritical(p)
                                        ? 'bg-[#C55050]/20 text-[#FF9E9E] border-[#C55050]/50'
                                        : 'bg-white/5 text-white border-white/10'"
                                >
                                    <AlertTriangle v-if="isCritical(p)" class="w-3.5 h-3.5" />
                                    {{ formatNumber(p.stock_quantity) }}
                                </span>
                            </td>
                            <td class="py-3 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button type="button" @click="openEdit(p)" :title="$t('Düzenle')" class="p-1.5 rounded-lg text-[#94A3B8] hover:bg-white/10 border border-transparent hover:border-white/20 transition-colors cursor-pointer">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button type="button" @click="askDelete(p)" :title="$t('Sil')" class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer">
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="productMeta && productMeta.last_page > 1" class="flex items-center justify-between pt-4 mt-2 border-t border-white/[0.08]">
                <span class="text-xs text-[#94A3B8]">
                    {{ $t('Toplam :total kayıt · Sayfa :cur / :last', { total: formatNumber(productMeta.total), cur: productMeta.current_page, last: productMeta.last_page }) }}
                </span>
                <div class="flex items-center gap-2">
                    <PillButton variant="ghost" size="sm" :disabled="productMeta.current_page <= 1" @click="productPage--; fetchProducts()">{{ $t('Önceki') }}</PillButton>
                    <PillButton variant="ghost" size="sm" :disabled="productMeta.current_page >= productMeta.last_page" @click="productPage++; fetchProducts()">{{ $t('Sonraki') }}</PillButton>
                </div>
            </div>
        </Card>

        <!-- TANIMLAR SEKMESİ -->
        <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Kategoriler -->
            <Card rounded="2xl" padding="md">
                <div class="flex items-center gap-3 pb-4 border-b border-white/[0.08] mb-4">
                    <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#E6A6F3]">
                        <Tags class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white tracking-tight">{{ $t('Kategoriler') }}</h2>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Ürün grupları ve renk etiketleri') }}</p>
                    </div>
                </div>
                <div v-if="loadingCategories" class="space-y-2">
                    <div v-for="i in 3" :key="i" class="h-12 bg-[#131722]/60 rounded-xl animate-pulse"></div>
                </div>
                <div v-else-if="categories.length === 0" class="text-center py-6 text-xs text-[#94A3B8]">
                    {{ $t('Henüz kategori tanımlanmamış.') }}
                </div>
                <div v-else class="space-y-2 mb-4">
                    <div v-for="c in categories" :key="c.id" class="flex items-center justify-between gap-2 p-3 bg-[#131722] rounded-xl border border-white/10">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="w-3.5 h-3.5 rounded-full shrink-0" :style="{ backgroundColor: c.color || '#64748B' }"></span>
                            <span class="text-sm font-semibold text-white truncate">{{ c.name }}</span>
                            <span class="text-[11px] text-[#94A3B8]">{{ formatNumber(c.products_count ?? 0) }} {{ $t('ürün') }}</span>
                        </div>
                        <button type="button" @click="deletingCategory = c" :title="$t('Kategoriyi sil')" class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer shrink-0">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
                <form @submit.prevent="submitCategory" class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
                    <p class="text-xs font-semibold text-white">{{ $t('Yeni Kategori') }}</p>
                    <div v-if="categoryGeneralError" class="p-2.5 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ categoryGeneralError }}</div>
                    <input v-model="categoryForm.name" type="text" :placeholder="$t('Kategori adı *')" class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-[#94A3B8]">{{ $t('Renk:') }}</span>
                        <button
                            v-for="color in palette"
                            :key="color"
                            type="button"
                            @click="categoryForm.color = color"
                            class="w-7 h-7 rounded-full border-2 transition-all cursor-pointer"
                            :style="{ backgroundColor: color }"
                            :class="categoryForm.color === color ? 'border-white scale-110' : 'border-transparent hover:scale-105'"
                            :title="color"
                        />
                    </div>
                    <div class="flex justify-end">
                        <PillButton type="submit" variant="secondary" size="sm" :loading="categorySaving">
                            <Plus class="w-3.5 h-3.5" /> {{ $t('Ekle') }}
                        </PillButton>
                    </div>
                </form>
            </Card>

            <!-- Birimler -->
            <Card rounded="2xl" padding="md">
                <div class="flex items-center gap-3 pb-4 border-b border-white/[0.08] mb-4">
                    <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#8CD5F4]">
                        <Ruler class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white tracking-tight">{{ $t('Birimler') }}</h2>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Adet, kg, litre gibi ölçü birimleri') }}</p>
                    </div>
                </div>
                <div v-if="loadingUnits" class="space-y-2">
                    <div v-for="i in 3" :key="i" class="h-12 bg-[#131722]/60 rounded-xl animate-pulse"></div>
                </div>
                <div v-else-if="units.length === 0" class="text-center py-6 text-xs text-[#94A3B8]">
                    {{ $t('Henüz birim tanımlanmamış.') }}
                </div>
                <div v-else class="space-y-2 mb-4">
                    <div v-for="u in units" :key="u.id" class="flex items-center justify-between gap-2 p-3 bg-[#131722] rounded-xl border border-white/10">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-sm font-semibold text-white truncate">{{ u.name }}</span>
                            <span class="text-[11px] font-mono text-[#94A3B8]">({{ u.code }})</span>
                            <StatusPill v-if="u.is_default" color="lime" :dot="false">{{ $t('Varsayılan') }}</StatusPill>
                        </div>
                        <button type="button" @click="deletingUnit = u" :title="$t('Birimi sil')" class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer shrink-0">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
                <form @submit.prevent="submitUnit" class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
                    <p class="text-xs font-semibold text-white">{{ $t('Yeni Birim') }}</p>
                    <div v-if="unitGeneralError" class="p-2.5 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ unitGeneralError }}</div>
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="unitForm.name" type="text" :placeholder="$t('Birim adı * (Adet)')" class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                        <input v-model="unitForm.code" type="text" :placeholder="$t('Kod * (adet)')" class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    </div>
                    <div class="flex justify-end">
                        <PillButton type="submit" variant="secondary" size="sm" :loading="unitSaving">
                            <Plus class="w-3.5 h-3.5" /> {{ $t('Ekle') }}
                        </PillButton>
                    </div>
                </form>
            </Card>
        </div>

        <!-- ÜRÜN MODAL (Yeni + Düzenle) -->
        <div v-if="showProductModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs" @click.self="closeProductModal">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">{{ editingProduct ? $t('Ürünü Düzenle') : $t('Yeni Ürün') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ editingProduct ? $t('Ürün kartı bilgilerini güncelleyin') : $t('Satışa konu ürün veya hizmet kartı oluşturun') }}</p>
                    </div>
                    <button type="button" @click="closeProductModal" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div v-if="productGeneralError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">
                    {{ productGeneralError }}
                </div>
                <form @submit.prevent="submitProduct" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Ürün Adı') }} <span class="text-[#B9FF66]">*</span></label>
                        <input v-model="productForm.name" type="text" :placeholder="$t('Örn: Motor Yağı 4L')" :class="inputClass(productErrors.name)" />
                        <p v-if="productErrors.name" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.name) }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Ürün Adı (İngilizce)') }}</label>
                        <input v-model="productForm.name_en" type="text" :placeholder="$t('Örn: Engine Oil 4L')" :class="inputClass(productErrors.name_en)" />
                        <p class="text-[11px] text-[#64748B] mt-1">{{ $t('Boş bırakılırsa çıktılarda Türkçe ad kullanılır.') }}</p>
                        <p v-if="productErrors.name_en" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.name_en) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Kategori') }}</label>
                        <select v-model="productForm.category_id" :class="selectClass()">
                            <option :value="null">{{ $t('Seçiniz') }}</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="productErrors.category_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.category_id) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Birim') }}</label>
                        <select v-model="productForm.unit_id" :class="selectClass()">
                            <option :value="null">{{ $t('Seçiniz') }}</option>
                            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.code }})</option>
                        </select>
                        <p v-if="productErrors.unit_id" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.unit_id) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">SKU</label>
                        <input v-model="productForm.sku" type="text" :placeholder="$t('Stok kodu')" :class="inputClass(productErrors.sku)" />
                        <p v-if="productErrors.sku" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.sku) }}</p>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide">{{ $t('Barkod / QR Kodu') }}</label>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] text-[#94A3B8]">{{ $t('Başlangıç:') }}</span>
                                <input
                                    v-model="barcodePrefix"
                                    type="text"
                                    maxlength="6"
                                    placeholder="869"
                                    :title="$t('Barkod Başlangıç Sayıları (Önek, örn: 869 veya 24)')"
                                    class="w-14 px-2 py-0.5 bg-[#131722] border border-white/15 rounded-lg text-[#B9FF66] text-[11px] font-mono font-bold text-center focus:outline-none focus:ring-1 focus:ring-[#B9FF66]"
                                />
                                <button
                                    v-if="!editingProduct"
                                    type="button"
                                    @click="generateClientPrefixedBarcode"
                                    class="text-[11px] font-bold text-[#B9FF66] hover:underline cursor-pointer"
                                >
                                    {{ $t('Üret') }}
                                </button>
                            </div>
                        </div>
                        <input v-model="productForm.barcode" type="text" :placeholder="$t('Barkod veya QR içeriği')" :class="inputClass(productErrors.barcode)" />
                        <p v-if="productErrors.barcode" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.barcode) }}</p>
                        <!-- Barkod + QR üretimi + etiket : kayıtlı üründe önizleme/ata/indir/yazdır -->
                        <div v-if="editingProduct" class="mt-2 rounded-xl bg-black/30 border border-white/10 p-2.5 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <select v-model="barcodeType" class="px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-xs">
                                    <option value="ean13">EAN-13 (Barkod)</option>
                                    <option value="code128">CODE128 (Barkod)</option>
                                    <option value="qr">QR Kod (2D Etiket)</option>
                                </select>
                                <PillButton type="button" size="sm" variant="ghost" :loading="barcodeLoading" @click="loadBarcodePreview">{{ $t('Önizle') }}</PillButton>
                                <button
                                    type="button"
                                    @click="assignBarcode(Boolean(productForm.barcode))"
                                    :disabled="barcodeLoading"
                                    class="text-xs font-bold text-[#B9FF66] hover:text-white px-2 py-1.5 disabled:opacity-50 cursor-pointer"
                                >
                                    {{ productForm.barcode ? $t('Önekle Yenile') : $t('Otomatik ata') }}
                                </button>
                            </div>
                            <img v-if="barcodePreviewUrl" :src="barcodePreviewUrl" alt="barkod" class="max-h-20 bg-white rounded px-2 py-1" />
                            <p v-if="barcodeMsg" class="text-[11px]" :class="barcodeOk ? 'text-[#B9FF66]' : 'text-[#FFA0A0]'">{{ barcodeMsg }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <PillButton type="button" size="sm" variant="ghost" :loading="barcodeLoading" :disabled="!productForm.barcode" @click="downloadBarcode('png')">PNG</PillButton>
                                <PillButton type="button" size="sm" variant="ghost" :loading="barcodeLoading" :disabled="!productForm.barcode" @click="downloadBarcode('svg')">SVG</PillButton>
                                <select v-model="labelSize" :title="$t('Etiket ölçüsü')" class="px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-xs">
                                    <option value="38x25">38×25</option>
                                    <option value="40x30">40×30</option>
                                    <option value="50x30">50×30</option>
                                </select>
                                <input v-model.number="labelQty" type="number" min="1" max="200" title="adet" class="w-16 px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-xs text-center" />
                                <label class="flex items-center gap-1.5 text-xs text-[#94A3B8] cursor-pointer">
                                    <input v-model="labelShowPrice" type="checkbox" class="accent-[#B9FF66] w-3.5 h-3.5" /> {{ $t('Fiyatlı') }}
                                </label>
                                <PillButton type="button" size="sm" variant="primary" :loading="labelLoading" :disabled="!productForm.barcode" @click="printLabels">
                                    {{ barcodeType === 'qr' ? $t('QR Etiket Yazdır') : $t('Etiket yazdır') }}
                                </PillButton>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Alış Fiyatı') }}</label>
                        <input v-model.number="productForm.cost_price" type="number" step="0.01" min="0" placeholder="0" :class="inputClass(productErrors.cost_price)" />
                        <p v-if="productErrors.cost_price" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.cost_price) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Satış Fiyatı') }}</label>
                        <input v-model.number="productForm.sale_price" type="number" step="0.01" min="0" placeholder="0" :class="inputClass(productErrors.sale_price)" />
                        <p v-if="productErrors.sale_price" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.sale_price) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Para Birimi') }}</label>
                        <select v-model="productForm.currency_code" :class="selectClass()">
                            <option value="TRY">₺ TRY</option>
                            <option value="USD">$ USD</option>
                            <option value="EUR">€ EUR</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('KDV Oranı (%)') }}</label>
                        <input v-model.number="productForm.tax_rate" type="number" min="0" max="100" placeholder="20" :class="inputClass(productErrors.tax_rate)" />
                        <p v-if="productErrors.tax_rate" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.tax_rate) }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Min. Stok') }}</label>
                        <input v-model.number="productForm.min_stock" type="number" min="0" placeholder="0" :class="inputClass(productErrors.min_stock)" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Maks. Stok') }}</label>
                        <input v-model.number="productForm.max_stock" type="number" min="0" :placeholder="$t('Boşsa min. x2')" :class="inputClass(productErrors.max_stock)" />
                        <p v-if="productErrors.max_stock" class="text-xs text-[#FFA0A0] mt-1.5">{{ firstMsg(productErrors.max_stock) }}</p>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2.5 text-sm text-[#CBD5E1] cursor-pointer">
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="productForm.track_inventory"
                                @click="productForm.track_inventory = !productForm.track_inventory"
                                class="relative w-11 h-6 rounded-full transition-colors cursor-pointer"
                                :class="productForm.track_inventory ? 'bg-[#B9FF66]' : 'bg-white/10'"
                            >
                                <span
                                    class="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                                    :class="productForm.track_inventory ? 'left-[22px]' : 'left-0.5'"
                                ></span>
                            </button>
                            {{ $t('Stok takibi açık') }}
                        </label>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Açıklama') }}</label>
                        <textarea v-model="productForm.description" rows="2" :placeholder="$t('Ürün açıklaması...')" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                    </div>

                    <!-- FİYAT LİSTELERİ (para birimi bazlı çoklu fiyat) -->
                    <div class="sm:col-span-2 rounded-2xl border border-white/[0.08] bg-[#131722]/50 p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h4 class="text-sm font-extrabold text-white">{{ $t('Fiyat Listeleri') }}</h4>
                                <p class="text-[11px] text-[#94A3B8] mt-0.5">
                                    {{ $t('Her para birimi için ayrı fiyat girebilirsin. Fiyatlar birbirine eşit olmak zorunda değildir; istersen kurla eşitleyebilirsin.') }}
                                </p>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#B9FF66]/15 text-[#B9FF66]">
                                {{ $t('Fiyat Sınıfı') }}
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-[#64748B] text-[10px] uppercase tracking-wider">
                                        <th class="text-left font-bold py-1.5 pr-2">{{ $t('Para Birimi') }}</th>
                                        <th class="text-left font-bold py-1.5 pr-2">{{ $t('Satış Fiyatı') }}</th>
                                        <th class="text-left font-bold py-1.5 pr-2">{{ $t('Alış Fiyatı') }}</th>
                                        <th class="text-right font-bold py-1.5">{{ $t('Kurla Eşitle') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="satir in priceRows"
                                        :key="satir.currency_code"
                                        class="border-t border-white/[0.06]"
                                        :class="satir.currency_code === productForm.currency_code ? 'bg-[#B9FF66]/5' : ''"
                                    >
                                        <td class="py-2 pr-2 font-bold text-white whitespace-nowrap">
                                            {{ paraBirimiEtiketi(satir.currency_code) }}
                                            <span v-if="satir.currency_code === productForm.currency_code" class="ml-1 text-[9px] font-black text-[#B9FF66]">{{ $t('ANA') }}</span>
                                        </td>
                                        <td class="py-2 pr-2">
                                            <input
                                                v-model.number="satir.sale_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="0"
                                                class="w-full px-2.5 py-1.5 bg-[#0F1420] border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-1 focus:ring-[#B9FF66]"
                                            />
                                        </td>
                                        <td class="py-2 pr-2">
                                            <input
                                                v-model.number="satir.cost_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="0"
                                                class="w-full px-2.5 py-1.5 bg-[#0F1420] border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-1 focus:ring-[#B9FF66]"
                                            />
                                        </td>
                                        <td class="py-2 text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                @click="syncPriceRow(satir)"
                                                :disabled="!editingProduct || priceSyncing"
                                                class="px-2 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-[10px] font-bold text-[#94A3B8] hover:text-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                                :title="$t('Ana fiyatı kura göre bu para birimine çevirip yazar.')"
                                            >
                                                {{ $t('Kurla Eşitle') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p v-if="!editingProduct" class="text-[10px] text-[#64748B]">
                            {{ $t('Fiyat listeleri ürün kaydedildikten sonra güncellenebilir.') }}
                        </p>
                    </div>

                    <div class="sm:col-span-2 flex items-center justify-end gap-3 pt-2 border-t border-white/[0.08]">
                        <PillButton type="button" variant="ghost" size="md" @click="closeProductModal">{{ $t('Vazgeç') }}</PillButton>
                        <PillButton type="submit" variant="primary" size="md" :loading="productSaving">{{ $t('Kaydet') }}</PillButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- SİLME ONAY MODALI -->
        <div v-if="deletingProduct" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Ürün Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ deletingProduct.name }}</strong> {{ $t('kalıcı olarak silinecektir.') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingProduct = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="confirmDelete">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>

        <!-- KATEGORİ SİLME ONAY MODALI -->
        <div v-if="deletingCategory" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Kategori Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ deletingCategory.name }}</strong> {{ $t('kalıcı olarak silinecektir.') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingCategory = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="deleteCategory(deletingCategory)">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>

        <!-- BİRİM SİLME ONAY MODALI -->
        <div v-if="deletingUnit" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Birim Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ deletingUnit.name }} ({{ deletingUnit.code }})</strong> {{ $t('kalıcı olarak silinecektir.') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingUnit = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="deleteUnit(deletingUnit)">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import client from '../api/client';
import { t } from '../i18n';
import { formatNumber, formatMoney } from '../utils/format';
import { companySettings, loadCompanySettings } from '../api/settings';
import {
    Package, Tags, Ruler, Plus, Search, Pencil, Trash2,
    Check, AlertCircle, AlertTriangle, X,
} from 'lucide-vue-next';

const palette = ['#BC5FCF', '#459BBE', '#DC6F31', '#C55050', '#3D55B6', '#4E956A'];

const route = useRoute();
const activeTab = ref('urunler');
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
function inputClass(err) {
    return 'w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all'
        + (err ? ' border-[#C55050] focus:ring-[#C55050]' : '');
}
function selectClass() {
    return 'w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all cursor-pointer';
}
function isCritical(p) {
    if (!p.track_inventory) return false;
    if (p.stock_quantity === null || p.stock_quantity === undefined) return false;
    return Number(p.stock_quantity) < Number(p.min_stock || 0);
}

// --- Ürün listesi ---
const products = ref([]);
const productMeta = ref(null);
const loadingProducts = ref(true);
const productSearch = ref('');
const categoryFilter = ref('');
const productPage = ref(1);
let productTimer = null;
let productsReq = 0;
onUnmounted(() => clearTimeout(productTimer));

async function fetchProducts() {
    const reqId = ++productsReq;
    loadingProducts.value = true;
    try {
        const params = { page: productPage.value };
        if (productSearch.value.trim()) params.search = productSearch.value.trim();
        if (categoryFilter.value) params.category_id = categoryFilter.value;
        const res = await client.get('/products', { params });
        if (reqId !== productsReq) return; // eski yanıt: hızlı filtre değişiminde üzerine yazma
        products.value = res.data?.data || [];
        productMeta.value = res.data?.meta || null;
    } catch (err) {
        if (reqId !== productsReq) return;
        triggerError(err.response?.data?.message || t('Ürün listesi yüklenemedi.'));
    } finally {
        if (reqId === productsReq) loadingProducts.value = false;
    }
}
watch(productSearch, () => {
    clearTimeout(productTimer);
    productTimer = setTimeout(() => {
        productPage.value = 1;
        fetchProducts();
    }, 300);
});

// --- Kategoriler / Birimler ---
const categories = ref([]);
const units = ref([]);
const loadingCategories = ref(true);
const loadingUnits = ref(true);

async function fetchCategories() {
    loadingCategories.value = true;
    try {
        const res = await client.get('/categories');
        categories.value = res.data?.data || res.data?.categories || [];
    } catch (err) {
        triggerError(err.response?.data?.message || t('Kategoriler yüklenemedi.'));
    } finally {
        loadingCategories.value = false;
    }
}
async function fetchUnits() {
    loadingUnits.value = true;
    try {
        const res = await client.get('/units');
        units.value = res.data?.data || res.data?.units || [];
    } catch (err) {
        triggerError(err.response?.data?.message || t('Birimler yüklenemedi.'));
    } finally {
        loadingUnits.value = false;
    }
}

const categoryForm = reactive({ name: '', color: palette[0] });
const categorySaving = ref(false);
const categoryGeneralError = ref('');
async function submitCategory() {
    if (!categoryForm.name.trim()) {
        categoryGeneralError.value = t('Kategori adı zorunludur.');
        return;
    }
    categorySaving.value = true;
    categoryGeneralError.value = '';
    try {
        await client.post('/categories', { name: categoryForm.name.trim(), color: categoryForm.color });
        categoryForm.name = '';
        triggerSuccess(t('Kategori eklendi.'));
        await fetchCategories();
    } catch (err) {
        if (err.response?.status === 422) {
            const errs = err.response.data?.errors || {};
            categoryGeneralError.value = firstMsg(errs.name) || err.response.data?.message || t('Kategori eklenemedi.');
        } else {
            categoryGeneralError.value = err.response?.data?.message || t('Kategori eklenemedi.');
        }
    } finally {
        categorySaving.value = false;
    }
}
async function deleteCategory(c) {
    deletingCategory.value = null;
    try {
        await client.delete(`/categories/${c.id}`);
        triggerSuccess(t('Kategori silindi.'));
        await fetchCategories();
        fetchProducts();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Kategori silinirken bir hata oluştu.'));
    }
}

const unitForm = reactive({ name: '', code: '' });
const unitSaving = ref(false);
const unitGeneralError = ref('');
async function submitUnit() {
    if (!unitForm.name.trim() || !unitForm.code.trim()) {
        unitGeneralError.value = t('Birim adı ve kodu zorunludur.');
        return;
    }
    unitSaving.value = true;
    unitGeneralError.value = '';
    try {
        await client.post('/units', { name: unitForm.name.trim(), code: unitForm.code.trim() });
        unitForm.name = '';
        unitForm.code = '';
        triggerSuccess(t('Birim eklendi.'));
        await fetchUnits();
    } catch (err) {
        if (err.response?.status === 422) {
            const errs = err.response.data?.errors || {};
            unitGeneralError.value = firstMsg(errs.name) || firstMsg(errs.code) || err.response.data?.message || t('Birim eklenemedi.');
        } else {
            unitGeneralError.value = err.response?.data?.message || t('Birim eklenemedi.');
        }
    } finally {
        unitSaving.value = false;
    }
}
async function deleteUnit(u) {
    deletingUnit.value = null;
    try {
        await client.delete(`/units/${u.id}`);
        triggerSuccess(t('Birim silindi.'));
        await fetchUnits();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Birim silinirken bir hata oluştu.'));
    }
}

// --- Ürün modal (yeni + düzenle) ---
const showProductModal = ref(false);
const editingProduct = ref(null);
const productSaving = ref(false);
const productErrors = ref({});
const productGeneralError = ref('');
const productForm = reactive({
    name: '', name_en: '', category_id: null, unit_id: null, sku: '', barcode: '',
    cost_price: '', sale_price: '', currency_code: companySettings.value.currency_code, tax_rate: companySettings.value.default_tax_rate,
    min_stock: '', max_stock: '', track_inventory: true, description: '',
});
function resetProductForm() {
    Object.assign(productForm, {
        name: '', name_en: '', category_id: null, unit_id: null, sku: '', barcode: '',
        cost_price: '', sale_price: '', currency_code: companySettings.value.currency_code, tax_rate: companySettings.value.default_tax_rate,
        min_stock: '', max_stock: '', track_inventory: true, description: '',
    });
    priceRows.value = fiyatSatirlariUret(productForm.currency_code);
}

// --- Fiyat sınıfları (para birimi bazlı çoklu fiyat) ---
const PARA_BIRIMLERI = ['TRY', 'USD', 'EUR', 'GBP'];
const priceRows = ref([]);
const priceSyncing = ref('');

function paraBirimiEtiketi(kod) {
    return { TRY: '₺ TRY', USD: '$ USD', EUR: '€ EUR', GBP: '£ GBP' }[kod] || kod;
}

function fiyatSatirlariUret(anaCcy) {
    return PARA_BIRIMLERI.map((ccy) => ({
        currency_code: ccy,
        sale_price: '',
        cost_price: '',
    }));
}

/** Ürünün fiyat sınıflarını forma taşır (ana para birimi hariç tutulmaz —
 *  ana fiyat alanlarıyla aynı satır, kaydetmede birlikte gönderilir). */
function fiyatSatirlariniYukle(urun) {
    const liste = Array.isArray(urun?.prices) ? urun.prices : [];
    const anaCcy = urun?.currency_code || 'TRY';

    priceRows.value = PARA_BIRIMLERI.map((ccy) => {
        const bulunan = liste.find((p) => p.currency_code === ccy);
        return {
            currency_code: ccy,
            sale_price: bulunan ? Number(bulunan.sale_price) : (ccy === anaCcy ? Number(urun?.sale_price || 0) : ''),
            cost_price: bulunan && bulunan.cost_price !== null ? Number(bulunan.cost_price) : (ccy === anaCcy ? Number(urun?.cost_price || 0) : ''),
        };
    });
}

/** Fiyat listelerini kaydet (ana fiyat dahil). */
async function fiyatListesiniKaydet(urunId) {
    const prices = priceRows.value
        .filter((s) => s.sale_price !== '' && s.sale_price !== null)
        .map((s) => ({
            currency_code: s.currency_code,
            sale_price: Number(s.sale_price),
            cost_price: s.cost_price === '' || s.cost_price === null ? null : Number(s.cost_price),
        }));

    if (prices.length === 0) return;

    await client.put(`/products/${urunId}/prices`, { prices });
}

/** Kur bazlı eşitleme: ana fiyat → hedef para birimi. */
async function syncPriceRow(satir) {
    if (!editingProduct.value) return;

    priceSyncing.value = satir.currency_code;
    try {
        const res = await client.post(`/products/${editingProduct.value.id}/prices/sync`, {
            from_currency: productForm.currency_code,
            to_currency: satir.currency_code,
        });

        const guncel = (res.data?.prices || []).find((p) => p.currency_code === satir.currency_code);
        if (guncel) {
            satir.sale_price = Number(guncel.sale_price);
            satir.cost_price = guncel.cost_price === null ? '' : Number(guncel.cost_price);
        }

        triggerSuccess(t('Fiyat sınıfı kur bazlı eşitlendi.'));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Fiyat eşitlenemedi.'));
    } finally {
        priceSyncing.value = '';
    }
}
function openCreate() {
    editingProduct.value = null;
    resetProductForm();
    resetBarcodePanel();
    productErrors.value = {};
    productGeneralError.value = '';
    showProductModal.value = true;
}
function openEdit(p) {
    editingProduct.value = p;
    Object.assign(productForm, {
        name: p.name || '', name_en: p.name_en || '', category_id: p.category_id ?? null, unit_id: p.unit_id ?? null,
        sku: p.sku || '', barcode: p.barcode || '',
        cost_price: p.cost_price ?? '', sale_price: p.sale_price ?? '',
        currency_code: p.currency_code || 'TRY', tax_rate: p.tax_rate ?? 20,
        min_stock: p.min_stock ?? '', max_stock: p.max_stock ?? '', track_inventory: p.track_inventory !== false,
        description: p.description || '',
    });
    fiyatSatirlariniYukle(p);
    productErrors.value = {};
    productGeneralError.value = '';
    resetBarcodePanel();
    showProductModal.value = true;
}
function closeProductModal() {
    showProductModal.value = false;
    editingProduct.value = null;
    revokeBarcodePreview();
}

// --- Barkod üretimi + QR + etiket  ---
const barcodeType = ref('ean13');
const barcodePrefix = ref(localStorage.getItem('mrc_barcode_prefix') || '869');
const barcodeLoading = ref(false);
const barcodePreviewUrl = ref('');
const barcodeMsg = ref('');
const barcodeOk = ref(true);
const labelQty = ref(1);
const labelSize = ref('38x25');
const labelShowPrice = ref(true);
const labelLoading = ref(false);

function generateClientPrefixedBarcode() {
    const clean = (barcodePrefix.value || '869').replace(/\D/g, '').slice(0, 6) || '869';
    barcodePrefix.value = clean;
    localStorage.setItem('mrc_barcode_prefix', clean);
    const padLen = 12 - clean.length;
    const randDigits = Array.from({ length: padLen }, () => Math.floor(Math.random() * 10)).join('');
    const twelve = clean + randDigits;
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        const d = Number(twelve[i]);
        sum += i % 2 === 0 ? d : d * 3;
    }
    const check = (10 - (sum % 10)) % 10;
    productForm.barcode = `${twelve}${check}`;
}
function revokeBarcodePreview() {
    if (barcodePreviewUrl.value) URL.revokeObjectURL(barcodePreviewUrl.value);
    barcodePreviewUrl.value = '';
}
function resetBarcodePanel() {
    revokeBarcodePreview();
    barcodeMsg.value = '';
    barcodeOk.value = true;
    labelQty.value = 1;
    labelSize.value = '38x25';
    labelShowPrice.value = true;
}
async function fetchBarcodeBlob(format) {
    const res = await client.get(`/products/${editingProduct.value.id}/barcode`, {
        params: { type: barcodeType.value, format },
        responseType: 'blob',
    });
    return res.data instanceof Blob ? res.data : new Blob([res.data]);
}
async function loadBarcodePreview() {
    if (!editingProduct.value) return;
    barcodeLoading.value = true;
    barcodeMsg.value = '';
    try {
        revokeBarcodePreview();
        const blob = await fetchBarcodeBlob('png');
        barcodePreviewUrl.value = URL.createObjectURL(blob);
        barcodeOk.value = true;
        barcodeMsg.value = '';
    } catch (err) {
        barcodeOk.value = false;
        barcodeMsg.value = err.response?.data?.errors?.barcode?.[0] || err.response?.data?.message || t('Barkod üretilemedi.');
    } finally {
        barcodeLoading.value = false;
    }
}
async function assignBarcode(force = false) {
    barcodeLoading.value = true;
    barcodeMsg.value = '';
    const cleanPrefix = (barcodePrefix.value || '24').replace(/\D/g, '').slice(0, 6) || '24';
    localStorage.setItem('mrc_barcode_prefix', cleanPrefix);
    try {
        const res = await client.post(`/products/${editingProduct.value.id}/barcode/assign`, {
            prefix: cleanPrefix,
            force,
        });
        productForm.barcode = res.data?.product?.barcode || '';
        barcodeOk.value = true;
        barcodeMsg.value = t('Barkod atandı.');
        revokeBarcodePreview();
    } catch (err) {
        barcodeOk.value = false;
        barcodeMsg.value = err.response?.data?.errors?.barcode?.[0] || err.response?.data?.message || t('Barkod üretilemedi.');
    } finally {
        barcodeLoading.value = false;
    }
}
async function downloadBarcode(format) {
    barcodeLoading.value = true;
    barcodeMsg.value = '';
    try {
        const blob = await fetchBarcodeBlob(format);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `barkod-${editingProduct.value.id}.${format}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (err) {
        barcodeOk.value = false;
        barcodeMsg.value = err.response?.data?.errors?.barcode?.[0] || err.response?.data?.message || t('Barkod üretilemedi.');
    } finally {
        barcodeLoading.value = false;
    }
}
async function printLabels() {
    labelLoading.value = true;
    barcodeMsg.value = '';
    try {
        const res = await client.post('/products/labels', {
            items: [{ product_id: editingProduct.value.id, qty: labelQty.value || 1 }],
            show_price: labelShowPrice.value,
            size: labelSize.value,
            barcode_type: barcodeType.value,
        }, { responseType: 'blob' });
        const blob = res.data instanceof Blob ? res.data : new Blob([res.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const w = window.open(url, '_blank');
        if (!w) {
            const a = document.createElement('a');
            a.href = url;
            a.download = 'etiketler.pdf';
            document.body.appendChild(a);
            a.click();
            a.remove();
        }
        setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch {
        barcodeOk.value = false;
        barcodeMsg.value = t('Etiket üretilemedi.');
    } finally {
        labelLoading.value = false;
    }
}
function buildProductPayload() {
    const payload = { name: productForm.name, track_inventory: productForm.track_inventory };
    if (productForm.name_en) payload.name_en = productForm.name_en;
    if (productForm.category_id) payload.category_id = productForm.category_id;
    if (productForm.unit_id) payload.unit_id = productForm.unit_id;
    if (productForm.sku) payload.sku = productForm.sku;
    if (productForm.barcode) payload.barcode = productForm.barcode;
    if (productForm.cost_price !== '' && productForm.cost_price !== null) payload.cost_price = productForm.cost_price;
    if (productForm.sale_price !== '' && productForm.sale_price !== null) payload.sale_price = productForm.sale_price;
    if (productForm.currency_code) payload.currency_code = productForm.currency_code;
    if (productForm.tax_rate !== '' && productForm.tax_rate !== null) payload.tax_rate = productForm.tax_rate;
    if (productForm.min_stock !== '' && productForm.min_stock !== null) payload.min_stock = productForm.min_stock;
    if (productForm.max_stock !== '' && productForm.max_stock !== null) payload.max_stock = productForm.max_stock;
    if (productForm.description) payload.description = productForm.description;
    return payload;
}
async function submitProduct() {
    productSaving.value = true;
    productErrors.value = {};
    productGeneralError.value = '';
    try {
        if (editingProduct.value) {
            await client.patch(`/products/${editingProduct.value.id}`, buildProductPayload());
            await fiyatListesiniKaydet(editingProduct.value.id);
            triggerSuccess(t('Ürün güncellendi.'));
        } else {
            const yeni = await client.post('/products', buildProductPayload());
            const yeniId = yeni.data?.product?.id || yeni.data?.id;
            if (yeniId) await fiyatListesiniKaydet(yeniId);
            triggerSuccess(t('Ürün başarıyla oluşturuldu.'));
        }
        closeProductModal();
        fetchProducts();
    } catch (err) {
        if (err.response?.status === 422) {
            productErrors.value = err.response.data?.errors || {};
            productGeneralError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            productGeneralError.value = err.response?.data?.message || t('Ürün kaydedilemedi.');
        }
    } finally {
        productSaving.value = false;
    }
}

// --- Ürün silme ---
const deletingProduct = ref(null);
const deletingCategory = ref(null);
const deletingUnit = ref(null);
const deleteSaving = ref(false);
function askDelete(p) {
    deletingProduct.value = p;
}
async function confirmDelete() {
    if (!deletingProduct.value) return;
    deleteSaving.value = true;
    try {
        await client.delete(`/products/${deletingProduct.value.id}`);
        triggerSuccess(t('Ürün silindi.'));
        deletingProduct.value = null;
        fetchProducts();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Ürün silinirken bir hata oluştu.'));
    } finally {
        deleteSaving.value = false;
    }
}

onMounted(async () => {
    loadCompanySettings(); // urun formunun para birimi ve KDV'si icin

    // Hızlı aramadan derin bağlantı: ?search=kalem → liste filtreli açılır.
    const q = typeof route.query.search === 'string' ? route.query.search.trim() : '';
    if (q) productSearch.value = q;

    await Promise.allSettled([fetchProducts(), fetchCategories(), fetchUnits()]);
});
</script>
