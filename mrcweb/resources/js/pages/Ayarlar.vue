<template>
    <div class="space-y-6 select-none max-w-5xl mx-auto pb-12">
        <!-- Top Breadcrumb & Heading -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Ayarlar') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-3">
                    {{ $t('Ayarlar') }}
                    <span v-if="loadingInitial" class="inline-flex">
                        <SkeletonPill width="w-20" height="h-5" />
                    </span>
                </h1>
            </div>

            <div class="flex items-center gap-3">
                <StatusPill color="lime" :dot="true">
                    {{ $t('Sistem Ayarları') }}
                </StatusPill>
            </div>
        </div>

        <!-- Global Feedback Banners -->
        <div
            v-if="globalSuccess"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-[#B9FF66] shadow-[0_0_20px_rgba(185,255,102,0.15)] transition-all"
        >
            <Check class="w-5 h-5 shrink-0 stroke-[2.5]" />
            <span class="text-sm font-semibold">{{ globalSuccess }}</span>
        </div>

        <div
            v-if="globalError"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0] transition-all"
        >
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span class="text-sm font-semibold">{{ globalError }}</span>
        </div>

        <div class="grid grid-cols-1 gap-6">
            <!-- Sekmeler: kartlar alttaki gruplara ayrılır (tek sayfa, sekmeli görünüm) -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="tab in settingTabs"
                    :key="tab.value"
                    type="button"
                    @click="activeTab = tab.value"
                    class="px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer"
                    :class="activeTab === tab.value
                        ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                        : 'text-[#94A3B8] hover:text-white bg-[#131722] border border-white/10'"
                >
                    {{ $t(tab.key) }}
                </button>
            </div>

            <template v-if="activeTab === 'firma'">
            <!-- 1. KART: FİRMA BİLGİLERİ -->
            <Card rounded="2xl" padding="lg">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                            <Building2 class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Firma Bilgileri') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Resmi fatura ve iletişim kimlik bilgileri') }}</p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="saveCompany" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Firma Adı -->
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Firma Adı') }} <span class="text-[#B9FF66]">*</span>
                            </label>
                            <input
                                v-model="companyForm.name"
                                type="text"
                                required
                                :placeholder="$t('Örn: Ortaklar Motors Ltd. Şti.')"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.name }"
                            />
                            <p v-if="companyErrors.name" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.name) ? companyErrors.name[0] : companyErrors.name }}
                            </p>
                        </div>

                        <!-- Vergi No / TC -->
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Vergi Numarası / T.C.') }}
                            </label>
                            <input
                                v-model="companyForm.tax_number"
                                type="text"
                                :placeholder="$t('Örn: 1234567890')"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.tax_number }"
                            />
                            <p v-if="companyErrors.tax_number" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.tax_number) ? companyErrors.tax_number[0] : companyErrors.tax_number }}
                            </p>
                        </div>

                        <!-- Vergi Dairesi -->
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Vergi Dairesi') }}
                            </label>
                            <input
                                v-model="companyForm.tax_office"
                                type="text"
                                :placeholder="$t('Örn: Malatya Vergi Dairesi')"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.tax_office }"
                            />
                            <p v-if="companyErrors.tax_office" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.tax_office) ? companyErrors.tax_office[0] : companyErrors.tax_office }}
                            </p>
                        </div>

                        <!-- E-posta -->
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('E-posta Adresi') }}
                            </label>
                            <input
                                v-model="companyForm.email"
                                type="email"
                                :placeholder="$t('info@firmaniz.com')"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.email }"
                            />
                            <p v-if="companyErrors.email" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.email) ? companyErrors.email[0] : companyErrors.email }}
                            </p>
                        </div>

                        <!-- Telefon -->
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Telefon Numarası') }}
                            </label>
                            <input
                                v-model="companyForm.phone"
                                type="tel"
                                placeholder="+90 555 000 00 00"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.phone }"
                            />
                            <p v-if="companyErrors.phone" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.phone) ? companyErrors.phone[0] : companyErrors.phone }}
                            </p>
                        </div>

                        <!-- Şehir -->
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Şehir') }}
                            </label>
                            <input
                                v-model="companyForm.city"
                                type="text"
                                :placeholder="$t('Örn: İstanbul')"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.city }"
                            />
                            <p v-if="companyErrors.city" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.city) ? companyErrors.city[0] : companyErrors.city }}
                            </p>
                        </div>

                        <!-- Adres -->
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Açık Adres') }}
                            </label>
                            <textarea
                                v-model="companyForm.address"
                                rows="3"
                                :placeholder="$t('Mahalle, sokak, no, ilçe...')"
                                class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all resize-y"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': companyErrors.address }"
                            ></textarea>
                            <p v-if="companyErrors.address" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ Array.isArray(companyErrors.address) ? companyErrors.address[0] : companyErrors.address }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end pt-4 border-t border-white/[0.08]">
                        <PillButton
                            type="submit"
                            variant="primary"
                            size="md"
                            :loading="companySaving"
                        >
                            {{ $t('Firma Bilgilerini Kaydet') }}
                        </PillButton>
                    </div>
                </form>
            </Card>
            </template>

            <template v-if="activeTab === 'genel'">
            <!-- 2. KART: GENEL AYARLAR -->
            <Card rounded="2xl" padding="lg" class="relative">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#459BBE]">
                            <Sliders class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Genel Ayarlar') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Para birimi, varsayılan KDV oranı ve faturalama parametreleri') }}</p>
                        </div>
                    </div>

                    <div v-if="isSettingsForbidden" class="flex items-center gap-1.5 text-xs text-[#FFAE7C] bg-[#DC6F31]/15 px-3 py-1 rounded-full border border-[#DC6F31]/30">
                        <ShieldAlert class="w-4 h-4" />
                        <span>{{ $t('Yönetici Yetkisi Gerekli') }}</span>
                    </div>
                </div>

                <!-- 403 Forbidden Banner -->
                <div
                    v-if="isSettingsForbidden"
                    class="mb-6 p-4 rounded-xl bg-[#DC6F31]/15 border border-[#DC6F31]/30 text-[#FFAE7C] flex items-center gap-3 text-xs"
                >
                    <ShieldAlert class="w-5 h-5 shrink-0" />
                    <span>{{ $t('Bu alan için yönetici yetkisi gerekli. Değişiklikler yalnızca yönetici rolündeki kullanıcılar tarafından kaydedilebilir.') }}</span>
                </div>

                <form @submit.prevent="saveSettings" class="space-y-4">
                    <fieldset :disabled="isSettingsForbidden" class="space-y-4 disabled:opacity-60">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Para Birimi -->
                            <div>
                                <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                    {{ $t('Para Birimi (currency_code)') }}
                                </label>
                                <select
                                    v-model="settingsForm.currency_code"
                                    class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all cursor-pointer"
                                >
                                    <option value="TRY">{{ $t('₺ Türk Lirası (TRY)') }}</option>
                                    <option value="USD">{{ $t('US Dollar (USD)') }}</option>
                                    <option value="EUR">{{ $t('€ Euro (EUR)') }}</option>
                                </select>
                                <p v-if="settingsErrors.currency_code" class="text-xs text-[#FFA0A0] mt-1.5">
                                    {{ Array.isArray(settingsErrors.currency_code) ? settingsErrors.currency_code[0] : settingsErrors.currency_code }}
                                </p>
                            </div>

                            <!-- Varsayılan KDV Oranı (%) -->
                            <div>
                                <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                    {{ $t('Varsayılan KDV Oranı (%)') }}
                                </label>
                                <input
                                    v-model.number="settingsForm.default_tax_rate"
                                    type="number"
                                    min="0"
                                    max="100"
                                    placeholder="20"
                                    class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                    :class="{ 'border-[#C55050] focus:ring-[#C55050]': settingsErrors.default_tax_rate }"
                                />
                                <p v-if="settingsErrors.default_tax_rate" class="text-xs text-[#FFA0A0] mt-1.5">
                                    {{ Array.isArray(settingsErrors.default_tax_rate) ? settingsErrors.default_tax_rate[0] : settingsErrors.default_tax_rate }}
                                </p>
                            </div>

                            <!-- Fatura Seri Öneki -->
                            <div>
                                <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                    {{ $t('Fatura Seri Öneki') }}
                                </label>
                                <input
                                    v-model="settingsForm.invoice_series_prefix"
                                    type="text"
                                    maxlength="10"
                                    :placeholder="$t('Örn: FT')"
                                    class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all uppercase"
                                    :class="{ 'border-[#C55050] focus:ring-[#C55050]': settingsErrors.invoice_series_prefix }"
                                />
                                <p v-if="settingsErrors.invoice_series_prefix" class="text-xs text-[#FFA0A0] mt-1.5">
                                    {{ Array.isArray(settingsErrors.invoice_series_prefix) ? settingsErrors.invoice_series_prefix[0] : settingsErrors.invoice_series_prefix }}
                                </p>
                            </div>

                            <!-- Varsayılan Vade (Gün) -->
                            <div>
                                <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                    {{ $t('Varsayılan Vade (Gün, 0-365)') }}
                                </label>
                                <input
                                    v-model.number="settingsForm.default_payment_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    placeholder="14"
                                    class="w-full px-4 py-2.5 bg-[#131722] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                    :class="{ 'border-[#C55050] focus:ring-[#C55050]': settingsErrors.default_payment_days }"
                                />
                                <p v-if="settingsErrors.default_payment_days" class="text-xs text-[#FFA0A0] mt-1.5">
                                    {{ Array.isArray(settingsErrors.default_payment_days) ? settingsErrors.default_payment_days[0] : settingsErrors.default_payment_days }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center justify-end pt-4 border-t border-white/[0.08]">
                            <PillButton
                                type="submit"
                                variant="primary"
                                size="md"
                                :disabled="isSettingsForbidden"
                                :loading="settingsSaving"
                            >
                                {{ $t('Genel Ayarları Kaydet') }}
                            </PillButton>
                        </div>
                    </fieldset>
                </form>
            </Card>
            </template>

            <template v-if="activeTab === 'plan'">
            <!-- Plan ve limitler : salt okur — Personel de ayın kaç belgesini
                 kestiğini görebilmeli; bu yüzden yetki kapısına tabi değil. -->
            <PlanLimitsCard />
            </template>

            <template v-if="activeTab === 'odeme'">
            <!-- Ödeme ayarları : yalnız Admin; kart kendini indirger. -->
            <PaymentSettingsCard />
            </template>

            <template v-if="activeTab === 'guvenlik'">
            <!-- İki faktörlü giriş : herkes kendi hesabını korur. -->
            <TwoFactorCard />
            </template>

            <template v-if="activeTab === 'doviz'">
            <!-- Para birimleri ve kurlar : liste herkese açık, anahtar yalnız Admin. -->
            <CurrencyCard />
            </template>

            <template v-if="activeTab === 'modul'">
            <!-- Modüller ve eklentiler : liste herkese açık, yazma yalnız Admin. -->
            <ModulesCard />
            </template>

            <template v-if="activeTab === 'yazici'">
            <!-- Yazıcı köprüsü : cihaz eşleştirme + kuyruk takibi. -->
            <DevicesCard />

            <Card rounded="2xl" padding="lg">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                            <Palette class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Yazdırma Şablonları') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Fatura, fiş, irsaliye ve etiket tasarımları') }}</p>
                        </div>
                    </div>
                    <router-link to="/sablonlar" class="px-5 py-2.5 rounded-full text-sm font-semibold bg-[#B9FF66] text-[#131722] hover:brightness-110 transition-all">
                        {{ $t('Tasarımcı') }}
                    </router-link>
                </div>
                <p class="text-xs text-[#64748B]">{{ $t('Blokları sürükleyerek dizin, renk ve logoyu değiştirin, etiket alanlarını serbestçe yerleştirin.') }}</p>
            </Card>
            </template>

            <template v-if="activeTab === 'firma'">
            <!-- 3. KART: LOGO YÖNETİMİ -->
            <Card rounded="2xl" padding="lg">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#BC5FCF]">
                            <Image class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Firma Logosu') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Fatura, teklif ve sistem arayüzünde görüntülenecek marka amblemi') }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                    <!-- Sol: Önizleme Alanı -->
                    <div class="md:col-span-5 flex flex-col items-center justify-center p-6 bg-[#131722] rounded-2xl border border-white/10 text-center min-h-[180px]">
                        <div v-if="previewUrl || companyLogoUrl" class="relative group">
                            <img
                                :src="previewUrl || companyLogoUrl"
                                :alt="$t('Firma Logosu')"
                                class="max-h-24 max-w-[200px] object-contain rounded-lg border border-white/10 p-2 bg-[#212738]"
                            />
                        </div>
                        <div v-else class="flex flex-col items-center gap-3 text-[#94A3B8]">
                            <div class="w-14 h-14 rounded-2xl bg-[#212738] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                                <UploadCloud class="w-7 h-7 stroke-[1.5]" />
                            </div>
                            <span class="text-xs font-medium">{{ $t('Logo yüklemek için dosya seçin') }}</span>
                        </div>
                    </div>

                    <!-- Sağ: Dosya Seçim & Yükleme Formu -->
                    <div class="md:col-span-7 space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Yeni Logo Seç (PNG, JPG, WebP — Maks 2 MB)') }}
                            </label>
                            <input
                                ref="fileInput"
                                type="file"
                                accept="image/png, image/jpeg, image/webp"
                                @change="onFileSelected"
                                class="w-full text-xs text-[#94A3B8] file:mr-4 file:py-2.5 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-[#282F44] file:text-white hover:file:bg-[#323b55] file:cursor-pointer cursor-pointer bg-[#131722] rounded-xl border border-white/10 p-2"
                            />
                            <p v-if="logoClientError" class="text-xs text-[#FFA0A0] mt-1.5 flex items-center gap-1">
                                <AlertCircle class="w-3.5 h-3.5" />
                                {{ logoClientError }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <PillButton
                                type="button"
                                variant="primary"
                                size="md"
                                :disabled="!selectedFile || !!logoClientError"
                                :loading="logoUploading"
                                @click="uploadLogo"
                            >
                                <UploadCloud class="w-4 h-4 mr-1" />
                                {{ $t('Logoyu Yükle') }}
                            </PillButton>

                            <button
                                v-if="selectedFile"
                                type="button"
                                @click="clearSelectedFile"
                                class="text-xs text-[#94A3B8] hover:text-white px-3 py-2 rounded-full border border-white/10 transition-colors"
                            >
                                {{ $t('Seçimi Temizle') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Card>
            </template>

            <template v-if="activeTab === 'genel'">
            <!-- 4. KART: DİL / LANGUAGE -->
            <Card rounded="2xl" padding="lg">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                            <Languages class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Dil / Language') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Arayüz dilini seçin') }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        v-for="opt in langOptions"
                        :key="opt.value"
                        type="button"
                        @click="changeLocale(opt.value)"
                        :disabled="localeSaving"
                        class="px-5 py-2.5 rounded-full text-sm font-semibold transition-all cursor-pointer border disabled:opacity-50 disabled:cursor-wait inline-flex items-center gap-2"
                        :class="locale === opt.value
                            ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_16px_rgba(185,255,102,0.35)]'
                            : 'text-[#94A3B8] border-white/10 hover:text-white hover:bg-white/5'"
                    >
                        <Check v-if="locale === opt.value" class="w-4 h-4" />
                        {{ opt.native }}
                    </button>
                </div>
            </Card>
            </template>

            <template v-if="activeTab === 'guvenlik'">
            <!-- 5. KART: API ERİŞİM ANAHTARLARI (masaüstü istemci / entegrasyon) -->
            <ApiTokensCard />
            </template>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import ApiTokensCard from '../components/account/ApiTokensCard.vue';
import PlanLimitsCard from '../components/account/PlanLimitsCard.vue';
import PaymentSettingsCard from '../components/account/PaymentSettingsCard.vue';
import TwoFactorCard from '../components/account/TwoFactorCard.vue';
import CurrencyCard from '../components/account/CurrencyCard.vue';
import ModulesCard from '../components/account/ModulesCard.vue';
import DevicesCard from '../components/account/DevicesCard.vue';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';
import { t, locale, setLocale } from '../i18n';
import {
    Building2,
    Sliders,
    Image,
    Check,
    AlertCircle,
    ShieldAlert,
    UploadCloud,
    Languages,
    Palette,
} from 'lucide-vue-next';

const authStore = useAuthStore();

// Loading states
const loadingInitial = ref(true);
// Sekmeler (kartlar gruplara ayrılır; kayıt mantığı kartların içinde aynen durur).
const activeTab = ref('firma');
const settingTabs = [
    { value: 'firma', key: 'Firma' },
    { value: 'genel', key: 'Genel' },
    { value: 'plan', key: 'Plan' },
    { value: 'odeme', key: 'Ödeme' },
    { value: 'doviz', key: 'Döviz' },
    { value: 'modul', key: 'Modüller' },
    { value: 'yazici', key: 'Yazıcılar' },
    { value: 'guvenlik', key: 'Güvenlik' },
];
const companySaving = ref(false);
const settingsSaving = ref(false);
const logoUploading = ref(false);

// Global banners
const globalSuccess = ref('');
const globalError = ref('');

function triggerSuccess(msg) {
    globalSuccess.value = msg;
    globalError.value = '';
    setTimeout(() => {
        if (globalSuccess.value === msg) {
            globalSuccess.value = '';
        }
    }, 4500);
}

function triggerError(msg) {
    globalError.value = msg;
    setTimeout(() => {
        if (globalError.value === msg) {
            globalError.value = '';
        }
    }, 5000);
}

// 1. Firma Form State
const companyForm = reactive({
    name: '',
    tax_number: '',
    tax_office: '',
    email: '',
    phone: '',
    address: '',
    city: '',
});
const companyErrors = ref({});
const companyLogoUrl = ref(null);

// 2. Genel Ayarlar State
const settingsForm = reactive({
    currency_code: 'TRY',
    default_tax_rate: 20,
    invoice_series_prefix: 'FT',
    default_payment_days: 14,
});
const settingsErrors = ref({});
const isSettingsForbidden = ref(false);

// 3. Logo State
const fileInput = ref(null);
const selectedFile = ref(null);
const previewUrl = ref(null);
const logoClientError = ref('');

// 4. Dil State
const localeSaving = ref(false);
const langOptions = [
    { value: 'tr', native: 'Türkçe' },
    { value: 'en', native: 'English' },
];

async function changeLocale(value) {
    if (value === locale.value || localeSaving.value) return;
    localeSaving.value = true;
    try {
        await client.patch('/me/locale', { locale: value });
        setLocale(value);
        triggerSuccess(t('Dil tercihi güncellendi.'));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Dil güncellenemedi.'));
    } finally {
        localeSaving.value = false;
    }
}

// Fetch Initial Data
async function fetchCompany() {
    try {
        const res = await client.get('/company');
        const comp = res.data?.company || res.data;
        if (comp) {
            companyForm.name = comp.name || '';
            companyForm.tax_number = comp.tax_number || '';
            companyForm.tax_office = comp.tax_office || '';
            companyForm.email = comp.email || '';
            companyForm.phone = comp.phone || '';
            companyForm.address = comp.address || '';
            companyForm.city = comp.city || '';
            companyLogoUrl.value = comp.logo_url || null;

            if (authStore.company) {
                authStore.company = { ...authStore.company, ...comp };
            }
        }
    } catch (err) {
        // Hata durumunda loglayabilir veya sessizce geçebiliriz
        console.error('Company fetch error:', err);
    }
}

async function fetchSettings() {
    try {
        const res = await client.get('/settings');
        const st = res.data?.settings || res.data;
        if (st) {
            if (st.currency_code !== undefined) settingsForm.currency_code = st.currency_code;
            if (st.default_tax_rate !== undefined) settingsForm.default_tax_rate = Number(st.default_tax_rate);
            if (st.invoice_series_prefix !== undefined) settingsForm.invoice_series_prefix = st.invoice_series_prefix;
            if (st.default_payment_days !== undefined) settingsForm.default_payment_days = Number(st.default_payment_days);
        }
        isSettingsForbidden.value = false;
    } catch (err) {
        if (err.response?.status === 403) {
            isSettingsForbidden.value = true;
        } else {
            console.error('Settings fetch error:', err);
        }
    }
}

// Save Company
async function saveCompany() {
    companySaving.value = true;
    companyErrors.value = {};
    globalSuccess.value = '';
    globalError.value = '';

    try {
        const payload = {
            name: companyForm.name,
            tax_number: companyForm.tax_number,
            // boşsa null gönderilir: sütun „tanımsız" hâlinde kalsın (PDF satırı düşer)
            tax_office: companyForm.tax_office.trim() ? companyForm.tax_office.trim() : null,
            email: companyForm.email,
            phone: companyForm.phone,
            address: companyForm.address,
            city: companyForm.city,
        };

        const res = await client.patch('/company', payload);
        const updated = res.data?.company || res.data;
        if (updated && authStore.company) {
            authStore.company = { ...authStore.company, ...updated };
        }
        triggerSuccess(t('Firma bilgileri başarıyla kaydedildi.'));
    } catch (err) {
        if (err.response?.status === 422) {
            companyErrors.value = err.response.data?.errors || {};
            triggerError(err.response.data?.message || t('Lütfen formdaki hataları kontrol edin.'));
        } else {
            triggerError(err.response?.data?.message || t('Firma bilgileri kaydedilemedi.'));
        }
    } finally {
        companySaving.value = false;
    }
}

// Save Settings
async function saveSettings() {
    settingsSaving.value = true;
    settingsErrors.value = {};
    globalSuccess.value = '';
    globalError.value = '';

    try {
        const payload = {
            settings: {
                currency_code: settingsForm.currency_code,
                default_tax_rate: settingsForm.default_tax_rate,
                invoice_series_prefix: settingsForm.invoice_series_prefix,
                default_payment_days: settingsForm.default_payment_days,
            },
        };

        await client.patch('/settings', payload);
        triggerSuccess(t('Genel ayarlar başarıyla kaydedildi.'));
    } catch (err) {
        if (err.response?.status === 403) {
            isSettingsForbidden.value = true;
            triggerError(t('Bu alan için yönetici yetkisi gerekli.'));
        } else if (err.response?.status === 422) {
            settingsErrors.value = err.response.data?.errors || {};
            triggerError(err.response.data?.message || t('Lütfen ayar değerlerini kontrol edin.'));
        } else {
            triggerError(err.response?.data?.message || t('Ayarlar kaydedilemedi.'));
        }
    } finally {
        settingsSaving.value = false;
    }
}

// File Selection & Client-Side Validation
function onFileSelected(event) {
    logoClientError.value = '';
    const file = event.target.files?.[0];
    if (!file) {
        clearSelectedFile();
        return;
    }

    // Maks 2 MB (2 * 1024 * 1024)
    if (file.size > 2 * 1024 * 1024) {
        logoClientError.value = t('Dosya boyutu 2 MB üzerinde olamaz.');
        selectedFile.value = null;
        previewUrl.value = null;
        return;
    }

    // image/png, image/jpeg, image/webp
    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        logoClientError.value = t('Yalnızca PNG, JPG veya WebP formatında görsel yükleyebilirsiniz.');
        selectedFile.value = null;
        previewUrl.value = null;
        return;
    }

    selectedFile.value = file;
    previewUrl.value = URL.createObjectURL(file);
}

function clearSelectedFile() {
    selectedFile.value = null;
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
    logoClientError.value = '';
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

// Upload Logo
async function uploadLogo() {
    if (!selectedFile.value || logoClientError.value) return;

    logoUploading.value = true;
    globalSuccess.value = '';
    globalError.value = '';

    try {
        const formData = new FormData();
        formData.append('file', selectedFile.value);

        const res = await client.post('/company/logo', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const comp = res.data?.company || res.data;
        if (comp?.logo_url) {
            companyLogoUrl.value = comp.logo_url;
            if (authStore.company) {
                authStore.company.logo_url = comp.logo_url;
            }
        }
        clearSelectedFile();
        triggerSuccess(t('Firma logosu başarıyla güncellendi.'));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Logo yüklenirken bir hata oluştu.'));
    } finally {
        logoUploading.value = false;
    }
}

onMounted(async () => {
    loadingInitial.value = true;
    await Promise.allSettled([fetchCompany(), fetchSettings()]);
    loadingInitial.value = false;
});
</script>
