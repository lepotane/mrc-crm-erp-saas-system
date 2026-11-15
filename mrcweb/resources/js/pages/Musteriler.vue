<template>
    <div class="space-y-6 select-none max-w-7xl mx-auto pb-12">
        <!-- Başlık + KPI Hapları + Aksiyon -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Müşteriler & Cariler') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                        {{ $t('Müşteriler & Cariler') }}
                    </h1>
                    <span v-if="loading" class="inline-flex"><SkeletonPill width="w-20" height="h-5" /></span>
                    <template v-else>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#B9FF66]">
                                <Wallet class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Toplam Cari:') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatNumber(totalCount) }}</span>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-[#212738] border border-white/10 shadow-xs">
                            <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-[#8CD5F4]">
                                <Users class="w-3.5 h-3.5" />
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[#94A3B8] font-medium hidden sm:inline">{{ $t('Müşteri Sayısı:') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-white">{{ formatNumber(customerCount) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <PillButton variant="primary" size="md" @click="openCreate">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Yeni Cari') }}</span>
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

        <!-- Filtre + Liste Kartı -->
        <Card rounded="2xl" padding="md">
            <!-- Tip sekmeleri + arama -->
            <div class="flex flex-col md:flex-row md:items-center gap-3 pb-4 border-b border-white/[0.08] mb-2">
                <div class="inline-flex items-center gap-1 p-1 rounded-full bg-[#131722] border border-white/10 w-fit">
                    <button
                        v-for="tab in typeTabs"
                        :key="tab.value"
                        type="button"
                        @click="setType(tab.value)"
                        class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                        :class="typeFilter === tab.value
                            ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                            : 'text-[#94A3B8] hover:text-white'"
                    >
                        {{ $t(tab.label) }}
                    </button>
                </div>
                <div class="relative md:ml-auto md:w-72">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-[#64748B]" />
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="$t('Ad, kod veya telefon ile ara...')"
                        class="w-full pl-10 pr-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                    />
                </div>
            </div>

            <!-- Yükleniyor -->
            <div v-if="loading" class="space-y-2 py-4">
                <div v-for="i in 5" :key="i" class="h-14 bg-[#131722]/60 rounded-xl border border-white/5 animate-pulse"></div>
            </div>

            <!-- Boş durum -->
            <div v-else-if="parties.length === 0" class="py-12 text-center flex flex-col items-center justify-center bg-[#131722]/40 rounded-xl border border-white/5 mt-4">
                <div class="w-14 h-14 rounded-2xl bg-[#212738] border border-white/10 flex items-center justify-center text-[#B9FF66] mb-3">
                    <Users class="w-7 h-7 stroke-[1.5]" />
                </div>
                <p class="text-sm font-bold text-white">{{ $t('Kayıtlı cari bulunamadı') }}</p>
                <p class="text-xs text-[#94A3B8] mt-1 mb-4">{{ $t('İlk cari hesabınızı oluşturarak başlayın.') }}</p>
                <PillButton variant="primary" size="md" @click="openCreate">
                    <Plus class="w-4 h-4 stroke-[3]" />
                    <span>{{ $t('Yeni Cari Ekle') }}</span>
                </PillButton>
            </div>

            <!-- Tablo -->
            <div v-else class="overflow-x-auto -mx-4 sm:-mx-6 px-4 sm:px-6">
                <table class="w-full text-sm min-w-[760px]">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-[#64748B] border-b border-white/[0.08]">
                            <th class="py-3 pr-3 font-semibold">{{ $t('Kod') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Ad') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Tip') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Telefon') }}</th>
                            <th class="py-3 pr-3 font-semibold">{{ $t('Şehir') }}</th>
                            <th class="py-3 pr-3 font-semibold text-center">{{ $t('Kişi Sayısı') }}</th>
                            <th class="py-3 font-semibold text-right">{{ $t('İşlemler') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.06]">
                        <tr
                            v-for="party in parties"
                            :key="party.id"
                            @click="openDetail(party.id)"
                            class="hover:bg-white/[0.03] transition-colors cursor-pointer"
                        >
                            <td class="py-3 pr-3 font-mono text-xs text-[#94A3B8]">{{ party.code || '-' }}</td>
                            <td class="py-3 pr-3 font-semibold text-white">{{ party.name }}</td>
                            <td class="py-3 pr-3">
                                <Badge :color="typeBadgeColor(party.type)">{{ $t(typeLabel(party.type)) }}</Badge>
                            </td>
                            <td class="py-3 pr-3 text-[#CBD5E1]">{{ party.phone || '-' }}</td>
                            <td class="py-3 pr-3 text-[#CBD5E1]">{{ party.city || '-' }}</td>
                            <td class="py-3 pr-3 text-center text-[#CBD5E1]">{{ party.contacts_count ?? '-' }}</td>
                            <td class="py-3 text-right" @click.stop>
                                <div class="inline-flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="openDetail(party.id)"
                                        :title="$t('Detay')"
                                        class="p-1.5 rounded-lg text-[#8CD5F4] hover:bg-[#459BBE]/20 border border-transparent hover:border-[#459BBE]/40 transition-colors cursor-pointer"
                                    >
                                        <Eye class="w-4 h-4" />
                                    </button>
                                    <button
                                        type="button"
                                        @click="openDetail(party.id, true)"
                                        :title="$t('Düzenle')"
                                        class="p-1.5 rounded-lg text-[#94A3B8] hover:bg-white/10 border border-transparent hover:border-white/20 transition-colors cursor-pointer"
                                    >
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button
                                        type="button"
                                        @click="askDelete(party)"
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

            <!-- Sayfalama -->
            <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between pt-4 mt-2 border-t border-white/[0.08]">
                <span class="text-xs text-[#94A3B8]">
                    {{ $t('Toplam :total kayıt · Sayfa :cur / :last', { total: formatNumber(meta.total), cur: meta.current_page, last: meta.last_page }) }}
                </span>
                <div class="flex items-center gap-2">
                    <PillButton variant="ghost" size="sm" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">
                        {{ $t('Önceki') }}
                    </PillButton>
                    <PillButton variant="ghost" size="sm" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">
                        {{ $t('Sonraki') }}
                    </PillButton>
                </div>
            </div>
        </Card>

        <!-- YENİ CARİ MODAL -->
        <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs" @click.self="closeCreate">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">{{ $t('Yeni Cari Hesap') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Müşteri veya tedarikçi cari kartı oluşturun') }}</p>
                    </div>
                    <button type="button" @click="closeCreate" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div v-if="createGeneralError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">
                    {{ createGeneralError }}
                </div>
                <form @submit.prevent="submitCreate" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Cari Adı') }} <span class="text-[#B9FF66]">*</span></label>
                        <input v-model="createForm.name" type="text" :placeholder="$t('Örn: ABC Ticaret Ltd. Şti.')" :class="inputClass(createErrors.name)" />
                        <FieldError :error="createErrors.name" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Tip') }}</label>
                        <select v-model="createForm.type" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option value="customer">{{ $t('Müşteri') }}</option>
                            <option value="supplier">{{ $t('Tedarikçi') }}</option>
                            <option value="both">{{ $t('Her İkisi') }}</option>
                        </select>
                        <FieldError :error="createErrors.type" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Vergi / T.C. No') }}</label>
                        <input v-model="createForm.tax_number" type="text" placeholder="1234567890" :class="inputClass(createErrors.tax_number)" />
                        <FieldError :error="createErrors.tax_number" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Telefon') }}</label>
                        <input v-model="createForm.phone" type="tel" placeholder="+90 555 000 00 00" :class="inputClass(createErrors.phone)" />
                        <FieldError :error="createErrors.phone" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('E-posta') }}</label>
                        <input v-model="createForm.email" type="email" :placeholder="$t('info@firma.com')" :class="inputClass(createErrors.email)" />
                        <FieldError :error="createErrors.email" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Şehir') }}</label>
                        <input v-model="createForm.city" type="text" :placeholder="$t('İstanbul')" :class="inputClass(createErrors.city)" />
                        <FieldError :error="createErrors.city" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Para Birimi') }}</label>
                        <select v-model="createForm.currency_code" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                            <option value="TRY">₺ TRY</option>
                            <option value="USD">$ USD</option>
                            <option value="EUR">€ EUR</option>
                        </select>
                        <FieldError :error="createErrors.currency_code" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Açılış Bakiyesi') }}</label>
                        <input v-model.number="createForm.opening_balance" type="number" step="0.01" placeholder="0" :class="inputClass(createErrors.opening_balance)" />
                        <FieldError :error="createErrors.opening_balance" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Kredi Limiti') }}</label>
                        <input v-model.number="createForm.credit_limit" type="number" step="0.01" placeholder="0" :class="inputClass(createErrors.credit_limit)" />
                        <FieldError :error="createErrors.credit_limit" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Adres') }}</label>
                        <textarea v-model="createForm.address" rows="2" :placeholder="$t('Mahalle, sokak, no...')" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">{{ $t('Notlar') }}</label>
                        <textarea v-model="createForm.notes" rows="2" :placeholder="$t('Cari ile ilgili notlar...')" class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                    </div>
                    <div class="sm:col-span-2 flex items-center justify-end gap-3 pt-2 border-t border-white/[0.08]">
                        <PillButton type="button" variant="ghost" size="md" @click="closeCreate">{{ $t('Vazgeç') }}</PillButton>
                        <PillButton type="submit" variant="primary" size="md" :loading="createSaving">{{ $t('Kaydet') }}</PillButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- DETAY DRAWER -->
        <div v-if="detailOpen" class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="closeDetail"></div>
            <aside class="absolute inset-y-0 right-0 w-full max-w-md bg-[#212738] border-l border-white/15 shadow-2xl flex flex-col">
                <div class="flex items-center justify-between p-5 border-b border-white/[0.08]">
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Cari Detayı') }}</h3>
                        <p v-if="detail" class="text-xs text-[#94A3B8] font-mono">{{ detail.code }}</p>
                    </div>
                    <button type="button" @click="closeDetail" class="p-2 rounded-full text-[#94A3B8] hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-5 space-y-6">
                    <div v-if="detailLoading" class="space-y-2">
                        <div v-for="i in 4" :key="i" class="h-12 bg-[#131722]/60 rounded-xl animate-pulse"></div>
                    </div>
                    <template v-else-if="detail">
                        <!-- Genel bilgiler -->
                        <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Genel Bilgiler') }}</h4>
                                <Badge :color="typeBadgeColor(detail.type)">{{ $t(typeLabel(detail.type)) }}</Badge>
                            </div>
                            <div v-if="!editing">
                                <p class="text-lg font-bold text-white">{{ detail.name }}</p>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                    <div class="text-[#94A3B8]">{{ $t('Telefon') }}: <span class="text-white">{{ detail.phone || '-' }}</span></div>
                                    <div class="text-[#94A3B8]">{{ $t('E-posta') }}: <span class="text-white break-all">{{ detail.email || '-' }}</span></div>
                                    <div class="text-[#94A3B8]">{{ $t('Vergi No') }}: <span class="text-white">{{ detail.tax_number || '-' }}</span></div>
                                    <div class="text-[#94A3B8]">{{ $t('Şehir') }}: <span class="text-white">{{ detail.city || '-' }}</span></div>
                                    <div class="text-[#94A3B8]">{{ $t('Para Birimi') }}: <span class="text-white">{{ detail.currency_code || '-' }}</span></div>
                                    <div class="text-[#94A3B8]">{{ $t('Açılış Bakiyesi') }}: <span class="text-white">{{ formatMoney(detail.opening_balance, detail.currency_code) }}</span></div>
                                    <div class="text-[#94A3B8]">{{ $t('Kredi Limiti') }}: <span class="text-white">{{ formatMoney(detail.credit_limit, detail.currency_code) }}</span></div>
                                    <div class="text-[#94A3B8] col-span-2">{{ $t('Adres') }}: <span class="text-white">{{ detail.address || '-' }}</span></div>
                                    <div v-if="detail.notes" class="text-[#94A3B8] col-span-2">{{ $t('Notlar') }}: <span class="text-white">{{ detail.notes }}</span></div>
                                </div>
                                <div class="flex items-center gap-2 pt-3">
                                    <PillButton variant="secondary" size="sm" @click="startEdit">
                                        <Pencil class="w-3.5 h-3.5" /> {{ $t('Düzenle') }}
                                    </PillButton>
                                    <PillButton variant="danger" size="sm" @click="askDelete(detail)">
                                        <Trash2 class="w-3.5 h-3.5" /> {{ $t('Sil') }}
                                    </PillButton>
                                </div>
                            </div>
                            <form v-else @submit.prevent="submitEdit" class="space-y-3">
                                <div v-if="editGeneralError" class="p-3 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ editGeneralError }}</div>
                                <div>
                                    <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Cari Adı') }} *</label>
                                    <input v-model="editForm.name" type="text" :class="inputClass(editErrors.name)" />
                                    <FieldError :error="editErrors.name" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Tip') }}</label>
                                        <select v-model="editForm.type" class="w-full px-3 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                            <option value="customer">{{ $t('Müşteri') }}</option>
                                            <option value="supplier">{{ $t('Tedarikçi') }}</option>
                                            <option value="both">{{ $t('Her İkisi') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Şehir') }}</label>
                                        <input v-model="editForm.city" type="text" :class="inputClass(editErrors.city)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Telefon') }}</label>
                                        <input v-model="editForm.phone" type="tel" :class="inputClass(editErrors.phone)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('E-posta') }}</label>
                                        <input v-model="editForm.email" type="email" :class="inputClass(editErrors.email)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Vergi No') }}</label>
                                        <input v-model="editForm.tax_number" type="text" :class="inputClass(editErrors.tax_number)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Para Birimi') }}</label>
                                        <select v-model="editForm.currency_code" class="w-full px-3 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] cursor-pointer">
                                            <option value="TRY">₺ TRY</option>
                                            <option value="USD">$ USD</option>
                                            <option value="EUR">€ EUR</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Açılış Bakiyesi') }}</label>
                                        <input v-model.number="editForm.opening_balance" type="number" step="0.01" :class="inputClass(editErrors.opening_balance)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Kredi Limiti') }}</label>
                                        <input v-model.number="editForm.credit_limit" type="number" step="0.01" :class="inputClass(editErrors.credit_limit)" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Adres') }}</label>
                                    <textarea v-model="editForm.address" rows="2" class="w-full px-3 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-[#94A3B8] mb-1">{{ $t('Notlar') }}</label>
                                    <textarea v-model="editForm.notes" rows="2" class="w-full px-3 py-2 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] resize-y"></textarea>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <PillButton type="button" variant="ghost" size="sm" @click="editing = false">{{ $t('Vazgeç') }}</PillButton>
                                    <PillButton type="submit" variant="primary" size="sm" :loading="editSaving">{{ $t('Kaydet') }}</PillButton>
                                </div>
                            </form>
                        </section>

                        <!-- İletişim kişileri -->
                        <section class="p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-[#94A3B8]">
                                    {{ $t('İletişim Kişileri') }} ({{ contacts.length }})
                                </h4>
                            </div>
                            <div v-if="contactsLoading" class="space-y-2">
                                <div v-for="i in 2" :key="i" class="h-12 bg-[#212738]/60 rounded-xl animate-pulse"></div>
                            </div>
                            <div v-else-if="contacts.length === 0" class="text-center py-4 text-xs text-[#94A3B8]">
                                {{ $t('Henüz kişi eklenmemiş.') }}
                            </div>
                            <div v-else class="space-y-2">
                                <div v-for="c in contacts" :key="c.id" class="flex items-center justify-between gap-2 p-3 bg-[#212738] rounded-xl border border-white/10">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-white truncate">{{ c.name }}</span>
                                            <StatusPill v-if="c.is_primary" color="lime" :dot="false">{{ $t('Birincil') }}</StatusPill>
                                        </div>
                                        <div class="text-[11px] text-[#94A3B8] truncate">{{ c.title || '' }}{{ c.title && c.phone ? ' · ' : '' }}{{ c.phone || '' }}{{ (c.title || c.phone) && c.email ? ' · ' : '' }}{{ c.email || '' }}</div>
                                    </div>
                                    <button type="button" @click="deleteContact(c)" :title="$t('Kişiyi sil')" class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer shrink-0">
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <!-- Kişi ekleme formu -->
                            <form @submit.prevent="submitContact" class="pt-3 border-t border-white/[0.08] space-y-2.5">
                                <p class="text-xs font-semibold text-white">{{ $t('Yeni Kişi Ekle') }}</p>
                                <div v-if="contactGeneralError" class="p-2.5 rounded-xl bg-[#C55050]/15 border border-[#C55050]/30 text-[#FFA0A0] text-xs font-semibold">{{ contactGeneralError }}</div>
                                <input v-model="contactForm.name" type="text" :placeholder="$t('Ad Soyad *')" :class="inputClass(contactErrors.name)" />
                                <FieldError :error="contactErrors.name" />
                                <div class="grid grid-cols-2 gap-2">
                                    <input v-model="contactForm.title" type="text" :placeholder="$t('Ünvan')" :class="inputClass(contactErrors.title)" />
                                    <input v-model="contactForm.phone" type="tel" :placeholder="$t('Telefon')" :class="inputClass(contactErrors.phone)" />
                                </div>
                                <input v-model="contactForm.email" type="email" :placeholder="$t('E-posta')" :class="inputClass(contactErrors.email)" />
                                <label class="flex items-center gap-2 text-xs text-[#CBD5E1] cursor-pointer">
                                    <input v-model="contactForm.is_primary" type="checkbox" class="w-4 h-4 accent-[#B9FF66] cursor-pointer" />
                                    {{ $t('Birincil kişi olarak işaretle') }}
                                </label>
                                <div class="flex justify-end">
                                    <PillButton type="submit" variant="secondary" size="sm" :loading="contactSaving">
                                        <Plus class="w-3.5 h-3.5" /> {{ $t('Kişi Ekle') }}
                                    </PillButton>
                                </div>
                            </form>
                        </section>
                    </template>
                </div>
            </aside>
        </div>

        <!-- SİLME ONAY MODALI -->
        <div v-if="deletingParty" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs">
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Cari Silinsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>
                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ deletingParty.name }}</strong> {{ $t('kalıcı olarak silinecektir.') }}
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton type="button" variant="ghost" size="md" :disabled="deleteSaving" @click="deletingParty = null">{{ $t('Vazgeç') }}</PillButton>
                    <PillButton type="button" variant="danger" size="md" :loading="deleteSaving" @click="confirmDelete">{{ $t('Evet, Sil') }}</PillButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch, h, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import Badge from '../components/ui/Badge.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import client from '../api/client';
import { t } from '../i18n';
import { formatNumber, formatMoney } from '../utils/format';
import { companySettings, loadCompanySettings } from '../api/settings';
import {
    Users, Wallet, Plus, Search, Eye, Pencil, Trash2,
    Check, AlertCircle, AlertTriangle, X,
} from 'lucide-vue-next';

// --- Alan hatası satırı (Login desenine uygun, runtime-derlemesiz) ---
function firstError(err) {
    if (!err) return '';
    return Array.isArray(err) ? err[0] : err;
}
const FieldError = (props) => {
    const msg = firstError(props.error);
    if (!msg) return null;
    return h('p', { class: 'text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1' }, msg);
};
FieldError.props = { error: { default: null } };

// --- Sabitler (dile göre reaktif) ---
const typeTabs = computed(() => [
    { value: '', label: t('Tümü') },
    { value: 'customer', label: t('Müşteri') },
    { value: 'supplier', label: t('Tedarikçi') },
]);

// --- Liste state ---
const route = useRoute();
const parties = ref([]);
const meta = ref(null);
const loading = ref(true);
const search = ref('');
const typeFilter = ref('');
const page = ref(1);
const totalCount = ref(0);
const customerCount = ref(0);
let searchTimer = null;
let partiesReq = 0;
onUnmounted(() => clearTimeout(searchTimer));

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

function typeLabel(tip) {
    if (tip === 'customer') return 'Müşteri';
    if (tip === 'supplier') return 'Tedarikçi';
    if (tip === 'both') return 'Her İkisi';
    return tip || '-';
}
function typeBadgeColor(t) {
    if (t === 'customer') return 'cyan';
    if (t === 'supplier') return 'orange';
    if (t === 'both') return 'violet';
    return 'neutral';
}
function inputClass(err) {
    return 'w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all'
        + (err ? ' border-[#C55050] focus:ring-[#C55050]' : '');
}

async function fetchParties() {
    const reqId = ++partiesReq;
    loading.value = true;
    try {
        const params = { page: page.value };
        if (search.value.trim()) params.search = search.value.trim();
        if (typeFilter.value) params.type = typeFilter.value;
        const res = await client.get('/parties', { params });
        if (reqId !== partiesReq) return; // eski yanıt: hızlı filtre değişiminde üzerine yazma
        parties.value = res.data?.data || [];
        meta.value = res.data?.meta || null;
    } catch (err) {
        if (reqId !== partiesReq) return;
        triggerError(err.response?.data?.message || t('Cari listesi yüklenemedi.'));
    } finally {
        if (reqId === partiesReq) loading.value = false;
    }
}

async function fetchCounts() {
    try {
        const [allRes, custRes] = await Promise.allSettled([
            client.get('/parties', { params: { per_page: 1 } }),
            client.get('/parties', { params: { per_page: 1, type: 'customer' } }),
        ]);
        if (allRes.status === 'fulfilled') totalCount.value = allRes.value.data?.meta?.total ?? 0;
        if (custRes.status === 'fulfilled') customerCount.value = custRes.value.data?.meta?.total ?? 0;
    } catch { /* sayaçlar kritik değil */ }
}

function refreshAll() {
    fetchParties();
    fetchCounts();
}

function setType(v) {
    typeFilter.value = v;
    page.value = 1;
    fetchParties();
}
function goPage(p) {
    page.value = p;
    fetchParties();
}
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        fetchParties();
    }, 300);
});

// --- Yeni cari ---
const showCreate = ref(false);
const createSaving = ref(false);
const createErrors = ref({});
const createGeneralError = ref('');
const createForm = reactive({
    name: '', type: 'customer', tax_number: '', phone: '', email: '',
    address: '', city: '', currency_code: companySettings.value.currency_code, opening_balance: '', credit_limit: '', notes: '',
});
function openCreate() {
    createErrors.value = {};
    createGeneralError.value = '';
    showCreate.value = true;
}
function closeCreate() {
    showCreate.value = false;
}
function buildPayload(form) {
    const payload = { name: form.name, type: form.type };
    if (form.tax_number !== '' && form.tax_number !== null) payload.tax_number = form.tax_number;
    if (form.phone) payload.phone = form.phone;
    if (form.email) payload.email = form.email;
    if (form.address) payload.address = form.address;
    if (form.city) payload.city = form.city;
    if (form.currency_code) payload.currency_code = form.currency_code;
    if (form.opening_balance !== '' && form.opening_balance !== null) payload.opening_balance = form.opening_balance;
    if (form.credit_limit !== '' && form.credit_limit !== null) payload.credit_limit = form.credit_limit;
    if (form.notes) payload.notes = form.notes;
    return payload;
}
async function submitCreate() {
    createSaving.value = true;
    createErrors.value = {};
    createGeneralError.value = '';
    try {
        await client.post('/parties', buildPayload(createForm));
        closeCreate();
        Object.assign(createForm, {
            name: '', type: 'customer', tax_number: '', phone: '', email: '',
            address: '', city: '', currency_code: companySettings.value.currency_code, opening_balance: '', credit_limit: '', notes: '',
        });
        triggerSuccess(t('Cari hesap başarıyla oluşturuldu.'));
        refreshAll();
    } catch (err) {
        if (err.response?.status === 422) {
            createErrors.value = err.response.data?.errors || {};
            createGeneralError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            createGeneralError.value = err.response?.data?.message || t('Cari oluşturulamadı.');
        }
    } finally {
        createSaving.value = false;
    }
}

// --- Detay drawer ---
const detailOpen = ref(false);
const detailLoading = ref(false);
const detail = ref(null);
const editing = ref(false);
const editSaving = ref(false);
const editErrors = ref({});
const editGeneralError = ref('');
const editForm = reactive({
    name: '', type: 'customer', tax_number: '', phone: '', email: '',
    address: '', city: '', currency_code: companySettings.value.currency_code, opening_balance: '', credit_limit: '', notes: '',
});

async function openDetail(id, startEditing = false) {
    detailOpen.value = true;
    detailLoading.value = true;
    editing.value = false;
    detail.value = null;
    try {
        const res = await client.get(`/parties/${id}`);
        detail.value = res.data?.data || res.data?.party || res.data;
        if (startEditing) startEdit();
        fetchContacts(id);
    } catch (err) {
        triggerError(err.response?.data?.message || t('Cari detayı yüklenemedi.'));
        detailOpen.value = false;
    } finally {
        detailLoading.value = false;
    }
}
function closeDetail() {
    detailOpen.value = false;
    detail.value = null;
    editing.value = false;
}
function startEdit() {
    if (!detail.value) return;
    const d = detail.value;
    Object.assign(editForm, {
        name: d.name || '', type: d.type || 'customer', tax_number: d.tax_number || '',
        phone: d.phone || '', email: d.email || '', address: d.address || '',
        city: d.city || '', currency_code: d.currency_code || 'TRY',
        opening_balance: d.opening_balance ?? '', credit_limit: d.credit_limit ?? '', notes: d.notes || '',
    });
    editErrors.value = {};
    editGeneralError.value = '';
    editing.value = true;
}
async function submitEdit() {
    if (!detail.value) return;
    editSaving.value = true;
    editErrors.value = {};
    editGeneralError.value = '';
    try {
        const res = await client.patch(`/parties/${detail.value.id}`, buildPayload(editForm));
        detail.value = res.data?.data || res.data?.party || res.data;
        editing.value = false;
        triggerSuccess(t('Cari bilgileri güncellendi.'));
        fetchParties();
        fetchCounts();
    } catch (err) {
        if (err.response?.status === 422) {
            editErrors.value = err.response.data?.errors || {};
            editGeneralError.value = err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.');
        } else {
            editGeneralError.value = err.response?.data?.message || t('Güncelleme yapılamadı.');
        }
    } finally {
        editSaving.value = false;
    }
}

// --- Silme ---
const deletingParty = ref(null);
const deleteSaving = ref(false);
function askDelete(party) {
    deletingParty.value = party;
}
async function confirmDelete() {
    if (!deletingParty.value) return;
    deleteSaving.value = true;
    try {
        await client.delete(`/parties/${deletingParty.value.id}`);
        triggerSuccess(t('Cari hesap silindi.'));
        deletingParty.value = null;
        closeDetail();
        refreshAll();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Cari silinirken bir hata oluştu.'));
    } finally {
        deleteSaving.value = false;
    }
}

// --- İletişim kişileri ---
const contacts = ref([]);
const contactsLoading = ref(false);
const contactSaving = ref(false);
const contactErrors = ref({});
const contactGeneralError = ref('');
const contactForm = reactive({ name: '', title: '', phone: '', email: '', is_primary: false });

async function fetchContacts(partyId) {
    contactsLoading.value = true;
    try {
        const res = await client.get(`/parties/${partyId}/contacts`);
        contacts.value = res.data?.data || res.data?.contacts || [];
        if (detail.value) detail.value.contacts = contacts.value;
    } catch {
        contacts.value = detail.value?.contacts || [];
    } finally {
        contactsLoading.value = false;
    }
}
async function submitContact() {
    if (!detail.value) return;
    contactSaving.value = true;
    contactErrors.value = {};
    contactGeneralError.value = '';
    try {
        const payload = { name: contactForm.name };
        if (contactForm.title) payload.title = contactForm.title;
        if (contactForm.phone) payload.phone = contactForm.phone;
        if (contactForm.email) payload.email = contactForm.email;
        if (contactForm.is_primary) payload.is_primary = true;
        const res = await client.post(`/parties/${detail.value.id}/contacts`, payload);
        const created = res.data?.data || res.data?.contact;
        if (created) contacts.value.push(created);
        else await fetchContacts(detail.value.id);
        Object.assign(contactForm, { name: '', title: '', phone: '', email: '', is_primary: false });
        triggerSuccess(t('İletişim kişisi eklendi.'));
        fetchParties();
    } catch (err) {
        if (err.response?.status === 422) {
            contactErrors.value = err.response.data?.errors || {};
            contactGeneralError.value = err.response.data?.message || t('Lütfen kişi bilgilerini kontrol edin.');
        } else {
            contactGeneralError.value = err.response?.data?.message || t('Kişi eklenemedi.');
        }
    } finally {
        contactSaving.value = false;
    }
}
async function deleteContact(c) {
    if (!detail.value) return;
    try {
        await client.delete(`/parties/${detail.value.id}/contacts/${c.id}`);
        contacts.value = contacts.value.filter((x) => x.id !== c.id);
        triggerSuccess(t('İletişim kişisi silindi.'));
        fetchParties();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Kişi silinirken bir hata oluştu.'));
    }
}

onMounted(() => {
    loadCompanySettings(); // yeni cari formunun para birimi icin

    // Hızlı aramadan derin bağlantı: ?search=ahmet → liste filtreli açılır.
    const q = typeof route.query.search === 'string' ? route.query.search.trim() : '';
    if (q) search.value = q;

    refreshAll();
});
</script>
