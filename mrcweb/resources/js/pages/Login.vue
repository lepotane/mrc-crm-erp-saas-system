<template>
    <div
        class="min-h-screen flex items-center justify-center p-3 sm:p-6 lg:p-10 select-none overflow-x-hidden transition-colors duration-300"
        :class="isLight ? 'bg-[#E2E8F0] text-[#0F172A]' : 'bg-[#0E121B] text-white'"
    >
        <!-- Ana Split-Screen Kart ("Anywhere app." Referans Mimarisinde, Açık/Koyu Tema Tam Uyumlu) -->
        <div
            class="relative w-full max-w-[1260px] min-h-[700px] rounded-[36px] border overflow-hidden flex flex-col lg:flex-row perspective-stage transition-colors duration-300"
            :class="isLight
                ? 'bg-[#FFFFFF] border-slate-200 shadow-[0_28px_80px_rgba(15,23,42,0.12)]'
                : 'bg-[#171C29] border-white/[0.08] shadow-[0_32px_90px_rgba(0,0,0,0.75)]'"
        >
            <!-- SOL BÖLÜM: Üst Menü + Form Alanı -->
            <div class="relative z-20 w-full lg:w-[57%] p-6 sm:p-10 lg:p-12 flex flex-col justify-between">
                <!-- Üst Navigasyon Barı: Logo + Tek Dilli Sekmeler + Bayraklı Dil Seçici + Tema -->
                <header class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3 cursor-pointer" @click="switchMode('login')">
                        <BrandMark size="md" :collapsed="true" />
                        <span
                            class="text-base sm:text-lg font-extrabold tracking-tight"
                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                        >
                            MRC SaaS<span class="text-[#16A34A] dark:text-[#B9FF66]">.</span>
                        </span>
                    </div>

                    <nav class="flex items-center gap-1 sm:gap-2">
                        <button
                            type="button"
                            @click="switchMode('login')"
                            class="text-xs sm:text-sm font-bold px-3 py-1.5 rounded-full transition-all cursor-pointer"
                            :class="activeMode === 'login'
                                ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.4)] keep-white'
                                : (isLight ? 'text-[#475569] hover:text-[#0F172A] hover:bg-slate-100' : 'text-[#94A3B8] hover:text-white hover:bg-white/5')"
                        >
                            {{ L('Giriş Yap', 'Log In') }}
                        </button>
                        <button
                            type="button"
                            @click="switchMode('join')"
                            class="text-xs sm:text-sm font-bold px-3 py-1.5 rounded-full transition-all cursor-pointer"
                            :class="activeMode === 'join'
                                ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.4)] keep-white'
                                : (isLight ? 'text-[#475569] hover:text-[#0F172A] hover:bg-slate-100' : 'text-[#94A3B8] hover:text-white hover:bg-white/5')"
                        >
                            {{ L('Katıl', 'Join') }}
                        </button>
                        <button
                            type="button"
                            @click="switchMode('create_company')"
                            class="text-xs sm:text-sm font-bold px-3 py-1.5 rounded-full transition-all cursor-pointer"
                            :class="activeMode === 'create_company'
                                ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.4)] keep-white'
                                : (isLight ? 'text-[#475569] hover:text-[#0F172A] hover:bg-slate-100' : 'text-[#94A3B8] hover:text-white hover:bg-white/5')"
                        >
                            {{ L('Firma Kur', 'Create Company') }}
                        </button>
                    </nav>

                    <!-- Sağ Üst: Bayraklı Dil Seçimi (TR / EN) + Güneş / Ay Tema Değiştirici -->
                    <div class="flex items-center gap-2">
                        <div
                            class="inline-flex items-center p-1 rounded-full border"
                            :class="isLight ? 'bg-slate-100 border-slate-200' : 'bg-[#212738] border-white/10'"
                        >
                            <button
                                type="button"
                                @click="changeLang('tr')"
                                class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold transition-all cursor-pointer"
                                :class="currentLocale === 'tr'
                                    ? 'bg-[#B9FF66] text-[#131722] shadow-xs keep-white'
                                    : (isLight ? 'text-[#475569] hover:text-[#0F172A]' : 'text-[#94A3B8] hover:text-white')"
                                title="Türkçe"
                            >
                                <!-- Türk Bayrağı SVG -->
                                <svg class="w-4 h-3 rounded-[2px] shrink-0" viewBox="0 0 30 20">
                                    <rect width="30" height="20" fill="#E30A17" />
                                    <circle cx="11" cy="10" r="5" fill="#FFFFFF" />
                                    <circle cx="12.3" cy="10" r="4" fill="#E30A17" />
                                    <polygon points="16,10 19.5,11.2 17.3,8.2 17.3,11.8 19.5,8.8" fill="#FFFFFF" />
                                </svg>
                                <span>TR</span>
                            </button>
                            <button
                                type="button"
                                @click="changeLang('en')"
                                class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold transition-all cursor-pointer"
                                :class="currentLocale === 'en'
                                    ? 'bg-[#B9FF66] text-[#131722] shadow-xs keep-white'
                                    : (isLight ? 'text-[#475569] hover:text-[#0F172A]' : 'text-[#94A3B8] hover:text-white')"
                                title="English"
                            >
                                <!-- İngiliz Bayrağı SVG -->
                                <svg class="w-4 h-3 rounded-[2px] shrink-0" viewBox="0 0 30 20">
                                    <rect width="30" height="20" fill="#012169" />
                                    <path d="M0,0 L30,20 M30,0 L0,20" stroke="#FFFFFF" stroke-width="3.5" />
                                    <path d="M0,0 L30,20 M30,0 L0,20" stroke="#C8102E" stroke-width="2" />
                                    <path d="M15,0 V20 M0,10 H30" stroke="#FFFFFF" stroke-width="5.5" />
                                    <path d="M15,0 V20 M0,10 H30" stroke="#C8102E" stroke-width="3.2" />
                                </svg>
                                <span>EN</span>
                            </button>
                        </div>

                        <button
                            type="button"
                            @click="toggleTheme"
                            class="w-9 h-9 rounded-full border flex items-center justify-center transition-all cursor-pointer"
                            :class="isLight
                                ? 'bg-slate-100 border-slate-200 text-[#3D55B6] hover:bg-slate-200'
                                : 'bg-[#212738] border-white/10 text-[#FBBF24] hover:border-[#B9FF66]/40'"
                            :title="isLight ? L('Koyu Temaya Geç', 'Switch to Dark Theme') : L('Açık Temaya Geç', 'Switch to Light Theme')"
                        >
                            <Sun v-if="!isLight" class="w-4 h-4" />
                            <Moon v-else class="w-4 h-4" />
                        </button>
                    </div>
                </header>

                <!-- ORTA İÇERİK: 3D Geçişli Giriş / Katıl (Firma Seç + Başvuru Formu) / Firma Kur -->
                <div class="my-auto py-6 max-w-[520px]">
                    <transition name="page-3d" mode="out-in">
                        <!-- ==========================================
                             1. GİRİŞ YAP EKRANI (LOG IN)
                             ========================================== -->
                        <div v-if="activeMode === 'login'" key="login-view">
                            <p
                                class="text-[11px] font-extrabold uppercase tracking-[0.2em] mb-2"
                                :class="isLight ? 'text-[#475569]' : 'text-[#94A3B8]'"
                            >
                                {{ L('KURUMSAL BULUT PLATFORMU', 'ENTERPRISE CLOUD PLATFORM') }}
                            </p>
                            <h1
                                class="text-3xl sm:text-4xl font-extrabold tracking-tight leading-tight"
                                :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                            >
                                {{ L('Hesabınıza giriş yapın', 'Log in to your account') }}<span class="text-[#16A34A] dark:text-[#B9FF66]">.</span>
                            </h1>
                            <p class="mt-2.5 text-sm" :class="isLight ? 'text-[#475569]' : 'text-[#94A3B8]'">
                                {{ L('Bir firmaya iş veya personel başvurusu mu yapacaksınız?', 'Want to apply to join a registered company?') }}
                                <button
                                    type="button"
                                    @click="switchMode('join')"
                                    class="font-bold ml-1 hover:underline cursor-pointer"
                                    :class="isLight ? 'text-[#15803D]' : 'text-[#B9FF66]'"
                                >
                                    {{ L('Katıl', 'Join') }}
                                </button>
                            </p>

                            <!-- 2FA Doğrulama Adımı -->
                            <form v-if="authStore.challengeToken" @submit.prevent="handleTwoFactor" class="mt-7 space-y-4">
                                <div :class="inputBoxClass(true)">
                                    <label class="block text-[10px] font-bold uppercase tracking-wider text-[#16A34A] dark:text-[#B9FF66]">
                                        {{ useRecovery ? L('Yedek Kurtarma Kodu', 'Backup Recovery Code') : L('6 Haneli Doğrulama Kodu', '6-Digit Verification Code') }}
                                    </label>
                                    <input
                                        v-model="twoFactorInput"
                                        type="text"
                                        required
                                        autofocus
                                        class="w-full bg-transparent text-base font-bold tracking-widest focus:outline-none mt-1"
                                        :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                        :placeholder="useRecovery ? 'XXXX-XXXX' : '000000'"
                                    />
                                </div>
                                <p v-if="authStore.error" class="text-xs font-semibold text-[#DC2626] dark:text-[#FF9E9E]">
                                    {{ authStore.error }}
                                </p>
                                <div class="flex items-center gap-3 pt-2">
                                    <button
                                        type="button"
                                        @click="cancelTwoFactor"
                                        class="flex-1 py-3.5 px-5 rounded-full text-xs font-bold transition-colors cursor-pointer"
                                        :class="isLight ? 'bg-slate-200 text-[#0F172A] hover:bg-slate-300' : 'bg-[#2B3245] text-white hover:bg-[#353D54]'"
                                    >
                                        {{ L('Geri Dön', 'Go Back') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="authStore.loading"
                                        class="flex-1 py-3.5 px-6 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_8px_24px_rgba(185,255,102,0.3)] transition-all cursor-pointer keep-white"
                                    >
                                        {{ authStore.loading ? L('Doğrulanıyor...', 'Verifying...') : L('Doğrula ve Giriş Yap', 'Verify & Log In') }}
                                    </button>
                                </div>
                            </form>

                            <!-- Standart Giriş Formu -->
                            <form v-else @submit.prevent="handleLogin" class="mt-7 space-y-4">
                                <div :class="inputBoxClass(focusedField === 'login_email')">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0">
                                            <label class="block text-[11px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                                {{ L('E-posta Adresi', 'Email Address') }}
                                            </label>
                                            <input
                                                v-model="loginForm.email"
                                                type="email"
                                                required
                                                autocomplete="email"
                                                @focus="focusedField = 'login_email'"
                                                @blur="focusedField = ''"
                                                class="w-full bg-transparent text-sm font-bold focus:outline-none mt-0.5"
                                                :class="isLight ? 'text-[#0F172A] placeholder-slate-400' : 'text-white placeholder-[#64748B]'"
                                                :placeholder="L('ornek@firma.com', 'name@company.com')"
                                            />
                                        </div>
                                        <Mail class="w-4 h-4 shrink-0 ml-3" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'" />
                                    </div>
                                </div>

                                <div :class="inputBoxClass(focusedField === 'login_password')">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0">
                                            <label class="block text-[11px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                                {{ L('Şifre', 'Password') }}
                                            </label>
                                            <input
                                                v-model="loginForm.password"
                                                :type="showPassword ? 'text' : 'password'"
                                                required
                                                autocomplete="current-password"
                                                @focus="focusedField = 'login_password'"
                                                @blur="focusedField = ''"
                                                class="w-full bg-transparent text-sm font-bold focus:outline-none mt-0.5"
                                                :class="isLight ? 'text-[#0F172A] placeholder-slate-400' : 'text-white placeholder-[#64748B]'"
                                                placeholder="••••••••"
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            @click="showPassword = !showPassword"
                                            class="ml-3 cursor-pointer"
                                            :class="isLight ? 'text-[#64748B] hover:text-[#0F172A]' : 'text-[#94A3B8] hover:text-white'"
                                        >
                                            <EyeOff v-if="showPassword" class="w-4 h-4" />
                                            <Eye v-else class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between text-xs px-1">
                                    <router-link
                                        to="/sifremi-unuttum"
                                        class="transition-colors font-medium"
                                        :class="isLight ? 'text-[#475569] hover:text-[#0F172A]' : 'text-[#94A3B8] hover:text-white'"
                                    >
                                        {{ L('Şifremi unuttum', 'Forgot password?') }}
                                    </router-link>
                                    <button
                                        type="button"
                                        @click="switchMode('create_company')"
                                        class="transition-colors font-semibold cursor-pointer"
                                        :class="isLight ? 'text-[#15803D] hover:underline' : 'text-[#B9FF66] hover:underline'"
                                    >
                                        {{ L('Yeni firma başvurusu oluştur →', 'Apply to create a new company →') }}
                                    </button>
                                </div>

                                <div
                                    v-if="authStore.error"
                                    class="p-3.5 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-xs font-semibold"
                                    :class="isLight ? 'text-[#B91C1C]' : 'text-[#FF9E9E]'"
                                >
                                    {{ authStore.error }}
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button
                                        type="button"
                                        @click="switchMode('join')"
                                        class="flex-1 py-3.5 px-5 rounded-full text-xs font-bold transition-all cursor-pointer"
                                        :class="isLight
                                            ? 'bg-slate-200/80 hover:bg-slate-300/80 text-[#0F172A]'
                                            : 'bg-[#2C3347] hover:bg-[#363E57] text-white'"
                                    >
                                        {{ L('Firmaya Katıl', 'Join a Company') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="authStore.loading"
                                        class="flex-1 py-3.5 px-6 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_10px_28px_rgba(185,255,102,0.35)] transition-all cursor-pointer keep-white"
                                    >
                                        {{ authStore.loading ? L('Giriş Yapılıyor...', 'Logging in...') : L('Giriş Yap', 'Log In') }}
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- ==========================================
                             2. KATIL: ADIM 1 (FİRMA SEÇİMİ) & ADIM 2 (FİRMA ÖZEL BAŞVURU FORMU + CV YÜKLEME)
                             ========================================== -->
                        <div v-else-if="activeMode === 'join'" key="join-view">
                            <p
                                class="text-[11px] font-extrabold uppercase tracking-[0.2em] mb-1.5"
                                :class="isLight ? 'text-[#15803D]' : 'text-[#B9FF66]'"
                            >
                                {{ selectedCompany
                                    ? L('ADIM 2 / 2 · FİRMA ÖZEL BAŞVURU FORMU', 'STEP 2 / 2 · COMPANY APPLICATION FORM')
                                    : L('ADIM 1 / 2 · BAŞVURU YAPILACAK FİRMAYI SEÇİN', 'STEP 1 / 2 · SELECT COMPANY TO APPLY') }}
                            </p>
                            <h1
                                class="text-2xl sm:text-3xl font-extrabold tracking-tight leading-tight"
                                :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                            >
                                {{ selectedCompany ? selectedCompany.name : L('Kayıtlı Firmaya Başvur', 'Apply to a Company') }}<span class="text-[#16A34A] dark:text-[#B9FF66]">.</span>
                            </h1>
                            <p class="mt-1.5 text-xs sm:text-sm" :class="isLight ? 'text-[#475569]' : 'text-[#94A3B8]'">
                                {{ L('Zaten üye misiniz?', 'Already a member?') }}
                                <button
                                    type="button"
                                    @click="switchMode('login')"
                                    class="font-bold ml-1 hover:underline cursor-pointer"
                                    :class="isLight ? 'text-[#15803D]' : 'text-[#B9FF66]'"
                                >
                                    {{ L('Giriş Yap', 'Log In') }}
                                </button>
                            </p>

                            <!-- Başvuru Tamamlandı Onay Kartı -->
                            <div
                                v-if="joinSuccessMessage"
                                class="mt-6 p-6 rounded-3xl bg-[#3E9B6F]/15 border border-[#B9FF66]/40 space-y-3"
                            >
                                <div class="w-11 h-11 rounded-2xl bg-[#B9FF66] text-[#131722] flex items-center justify-center font-black keep-white">
                                    ✓
                                </div>
                                <h3 class="text-base font-extrabold" :class="isLight ? 'text-[#0F172A]' : 'text-white'">
                                    {{ L('Başvurunuz Firma Yönetimine İletildi!', 'Your Application Has Been Sent!') }}
                                </h3>
                                <p class="text-xs leading-relaxed" :class="isLight ? 'text-[#334155]' : 'text-[#CBD5E1]'">
                                    {{ joinSuccessMessage }}
                                </p>
                                <button
                                    type="button"
                                    @click="switchMode('login')"
                                    class="mt-2 py-2.5 px-5 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-extrabold cursor-pointer keep-white"
                                >
                                    {{ L('Giriş Ekranına Dön', 'Back to Log In') }}
                                </button>
                            </div>

                            <!-- ADIM 1: Başvuru Kabul Eden Firmaları AJAX ile Ara ve Seç -->
                            <div v-else-if="!selectedCompany" class="mt-5 space-y-3">
                                <div :class="inputBoxClass(focusedField === 'company_search')">
                                    <div class="flex items-center justify-between gap-2">
                                        <Search class="w-4 h-4 shrink-0" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'" />
                                        <input
                                            v-model="companySearchQuery"
                                            type="text"
                                            @focus="focusedField = 'company_search'"
                                            @blur="focusedField = ''"
                                            @input="onSearchCompaniesInput"
                                            class="w-full bg-transparent text-xs sm:text-sm font-bold focus:outline-none"
                                            :class="isLight ? 'text-[#0F172A] placeholder-slate-400' : 'text-white placeholder-[#64748B]'"
                                            :placeholder="L('Firma adı veya şehir ara (örn: Ortaklar)...', 'Search company name or city...')"
                                        />
                                        <span v-if="companiesLoading" class="text-[11px] font-semibold text-[#16A34A] dark:text-[#B9FF66] shrink-0">
                                            {{ L('Aranıyor...', 'Searching...') }}
                                        </span>
                                    </div>
                                </div>

                                <div class="max-h-[300px] overflow-y-auto space-y-2 pr-1">
                                    <div
                                        v-for="comp in availableCompanies"
                                        :key="comp.id"
                                        @click="chooseCompanyForJoin(comp)"
                                        class="p-3.5 rounded-2xl border transition-all cursor-pointer flex items-center justify-between gap-3 card-3d-interactive"
                                        :class="isLight
                                            ? 'bg-slate-50 hover:bg-white border-slate-200 hover:border-[#16A34A]'
                                            : 'bg-[#212738]/90 hover:bg-[#283046] border-white/[0.08] hover:border-[#B9FF66]/50'"
                                    >
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="w-10 h-10 rounded-xl bg-[#B9FF66]/15 border border-[#B9FF66]/30 flex items-center justify-center shrink-0">
                                                <Building2 class="w-5 h-5 text-[#16A34A] dark:text-[#B9FF66]" />
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-extrabold truncate" :class="isLight ? 'text-[#0F172A]' : 'text-white'">
                                                    {{ comp.name }}
                                                </p>
                                                <p class="text-[11px]" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                                    {{ comp.city || L('Türkiye', 'Turkey') }} · {{ (comp.form_schema || []).filter((f) => f.enabled !== false).length }} {{ L('Başvuru Alanı', 'Form Fields') }}
                                                </p>
                                            </div>
                                        </div>
                                        <span class="px-3 py-1.5 rounded-full bg-[#B9FF66] text-[#131722] text-[11px] font-extrabold shrink-0 keep-white">
                                            {{ L('Seç & Başvur →', 'Select & Apply →') }}
                                        </span>
                                    </div>

                                    <div
                                        v-if="!companiesLoading && availableCompanies.length === 0"
                                        class="p-6 rounded-2xl border text-center text-xs"
                                        :class="isLight ? 'bg-slate-50 border-slate-200 text-[#64748B]' : 'bg-[#212738]/50 border-white/[0.06] text-[#94A3B8]'"
                                    >
                                        {{ L('Aramanızla eşleşen ve başvuru kabul eden firma bulunamadı.', 'No companies accepting applications matched your search.') }}
                                    </div>
                                </div>
                            </div>

                            <!-- ADIM 2: Seçilen Firmanın Özel Başvuru Formu & CV Yükleme -->
                            <form v-else @submit.prevent="handleJoinSubmit" class="mt-4 space-y-3">
                                <!-- Seçili Firma Üst Şeridi -->
                                <div
                                    class="p-3 rounded-2xl border flex items-center justify-between gap-2"
                                    :class="isLight ? 'bg-emerald-50 border-emerald-200' : 'bg-[#B9FF66]/10 border-[#B9FF66]/30'"
                                >
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <Building2 class="w-4 h-4 text-[#16A34A] dark:text-[#B9FF66] shrink-0" />
                                        <div class="min-w-0">
                                            <p class="text-xs font-extrabold truncate" :class="isLight ? 'text-[#0F172A]' : 'text-white'">
                                                {{ selectedCompany.name }}
                                            </p>
                                            <p class="text-[10px]" :class="isLight ? 'text-[#15803D]' : 'text-[#B9FF66]'">
                                                {{ L('Firmanın özel başvuru formu yüklendi', 'Company custom application form loaded') }}
                                            </p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="selectedCompany = null"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-bold cursor-pointer"
                                        :class="isLight ? 'bg-white text-[#0F172A] border border-slate-200' : 'bg-[#212738] text-white border border-white/10'"
                                    >
                                        {{ L('Firmayı Değiştir', 'Change Company') }}
                                    </button>
                                </div>

                                <!-- Firmanın Yapılandırdığı Dinamik Başvuru Alanları -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 max-h-[320px] overflow-y-auto pr-1">
                                    <div
                                        v-for="field in activeCompanyFields"
                                        :key="field.id"
                                        :class="[
                                            field.width === 'full' ? 'sm:col-span-2' : 'sm:col-span-1',
                                            inputBoxClass(focusedField === field.id)
                                        ]"
                                    >
                                        <label class="block text-[10px] font-bold" :class="isLight ? 'text-[#475569]' : 'text-[#94A3B8]'">
                                            {{ fieldLabel(field) }}
                                            <span v-if="field.required" class="text-[#EF4444]">*</span>
                                        </label>

                                        <!-- Select / Çoktan Seçmeli -->
                                        <select
                                            v-if="field.type === 'select'"
                                            v-model="dynamicValues[field.id]"
                                            :required="field.required"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-1"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                        >
                                            <option
                                                v-for="opt in fieldOptions(field)"
                                                :key="opt"
                                                :value="opt"
                                                :class="isLight ? 'bg-white text-[#0F172A]' : 'bg-[#171C29] text-white'"
                                            >
                                                {{ opt }}
                                            </option>
                                        </select>

                                        <!-- Textarea / Uzun Metin -->
                                        <textarea
                                            v-else-if="field.type === 'textarea'"
                                            v-model="dynamicValues[field.id]"
                                            :required="field.required"
                                            rows="2"
                                            @focus="focusedField = field.id"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-semibold focus:outline-none mt-1 resize-none"
                                            :class="isLight ? 'text-[#0F172A] placeholder-slate-400' : 'text-white placeholder-[#64748B]'"
                                            :placeholder="fieldPlaceholder(field)"
                                        />

                                        <!-- CV / Dosya Yükleme Alanı -->
                                        <div v-else-if="field.type === 'file'" class="mt-1.5">
                                            <label
                                                class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl border border-dashed cursor-pointer transition-colors"
                                                :class="isLight
                                                    ? 'bg-white border-slate-300 hover:border-[#16A34A] text-[#0F172A]'
                                                    : 'bg-[#191E2C]/70 border-white/20 hover:border-[#B9FF66] text-white'"
                                            >
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <Upload class="w-4 h-4 text-[#16A34A] dark:text-[#B9FF66] shrink-0" />
                                                    <span class="text-xs font-bold truncate">
                                                        {{ cvFile ? cvFile.name : L('CV / Özgeçmiş Dosyası Seç (PDF, DOCX, Görsel)', 'Choose CV / Resume File (PDF, DOCX, Image)') }}
                                                    </span>
                                                </div>
                                                <span class="px-2 py-0.5 rounded-md bg-[#B9FF66]/20 text-[#15803D] dark:text-[#B9FF66] text-[10px] font-extrabold shrink-0">
                                                    {{ cvFile ? L('Değiştir', 'Change') : L('Yükle', 'Upload') }}
                                                </span>
                                                <input
                                                    type="file"
                                                    accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"
                                                    class="hidden"
                                                    @change="onCvFileChange"
                                                />
                                            </label>
                                        </div>

                                        <!-- Standart Metin / E-posta / Telefon / Tarih -->
                                        <input
                                            v-else
                                            v-model="dynamicValues[field.id]"
                                            :type="field.type || 'text'"
                                            :required="field.required"
                                            @focus="focusedField = field.id"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A] placeholder-slate-400' : 'text-white placeholder-[#64748B]'"
                                            :placeholder="fieldPlaceholder(field)"
                                        />
                                    </div>

                                    <!-- Başvuru Onaylandığında Giriş İçin Belirlenecek Şifre -->
                                    <div class="sm:col-span-2" :class="inputBoxClass(focusedField === 'join_password')">
                                        <div class="flex items-center justify-between">
                                            <div class="flex-1 min-w-0">
                                                <label class="block text-[10px] font-bold" :class="isLight ? 'text-[#475569]' : 'text-[#94A3B8]'">
                                                    {{ L('Hesap Şifreniz (Onaylandığında giriş için)', 'Your Account Password (for login once approved)') }} *
                                                </label>
                                                <input
                                                    v-model="joinPassword"
                                                    :type="showPassword ? 'text' : 'password'"
                                                    required
                                                    minlength="8"
                                                    @focus="focusedField = 'join_password'"
                                                    @blur="focusedField = ''"
                                                    class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                                    :class="isLight ? 'text-[#0F172A] placeholder-slate-400' : 'text-white placeholder-[#64748B]'"
                                                    :placeholder="L('En az 8 karakterli şifre', 'Minimum 8 characters')"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                @click="showPassword = !showPassword"
                                                class="ml-2 cursor-pointer"
                                                :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'"
                                            >
                                                <EyeOff v-if="showPassword" class="w-4 h-4" />
                                                <Eye v-else class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="joinError"
                                    class="p-3 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-xs font-semibold"
                                    :class="isLight ? 'text-[#B91C1C]' : 'text-[#FF9E9E]'"
                                >
                                    {{ joinError }}
                                </div>

                                <div class="flex items-center gap-3 pt-1">
                                    <button
                                        type="button"
                                        @click="selectedCompany = null"
                                        class="flex-1 py-3 px-4 rounded-full text-xs font-bold transition-all cursor-pointer"
                                        :class="isLight ? 'bg-slate-200 text-[#0F172A] hover:bg-slate-300' : 'bg-[#2C3347] text-white hover:bg-[#363E57]'"
                                    >
                                        {{ L('← Firma Seçimine Dön', '← Back to Companies') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="joinSubmitting"
                                        class="flex-1 py-3 px-6 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_10px_28px_rgba(185,255,102,0.35)] transition-all cursor-pointer keep-white"
                                    >
                                        {{ joinSubmitting ? L('Gönderiliyor...', 'Submitting...') : L('Başvuruyu Gönder', 'Submit Application') }}
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- ==========================================
                             3. FİRMA KUR (YÖNETİCİ ONAYLI YENİ FİRMA KURULUMU)
                             ========================================== -->
                        <div v-else key="create-company-view">
                            <p
                                class="text-[11px] font-extrabold uppercase tracking-[0.2em] mb-1.5"
                                :class="isLight ? 'text-[#15803D]' : 'text-[#B9FF66]'"
                            >
                                {{ L('YÖNETİCİ ONAYLI KURUMSAL KAYIT', 'ADMIN-APPROVED CORPORATE REGISTRATION') }}
                            </p>
                            <h1
                                class="text-2xl sm:text-3xl font-extrabold tracking-tight leading-tight"
                                :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                            >
                                {{ L('Yeni Firma Kurulum Başvurusu', 'New Company Registration') }}<span class="text-[#16A34A] dark:text-[#B9FF66]">.</span>
                            </h1>
                            <p class="mt-1.5 text-xs sm:text-sm" :class="isLight ? 'text-[#475569]' : 'text-[#94A3B8]'">
                                {{ L('Başvurunuz sistem yöneticisi onayından sonra aktifleşir.', 'Your company will be activated after system administrator approval.') }}
                            </p>

                            <div
                                v-if="createCompanySuccess"
                                class="mt-6 p-6 rounded-3xl bg-[#3E9B6F]/15 border border-[#B9FF66]/40 space-y-3"
                            >
                                <div class="w-11 h-11 rounded-2xl bg-[#B9FF66] text-[#131722] flex items-center justify-center font-black keep-white">
                                    ✓
                                </div>
                                <h3 class="text-base font-extrabold" :class="isLight ? 'text-[#0F172A]' : 'text-white'">
                                    {{ L('Firma Kurulum Başvurunuz Alındı!', 'Company Registration Received!') }}
                                </h3>
                                <p class="text-xs leading-relaxed" :class="isLight ? 'text-[#334155]' : 'text-[#CBD5E1]'">
                                    {{ createCompanySuccess }}
                                </p>
                                <button
                                    type="button"
                                    @click="switchMode('login')"
                                    class="mt-2 py-2.5 px-5 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-extrabold cursor-pointer keep-white"
                                >
                                    {{ L('Giriş Ekranına Dön', 'Back to Log In') }}
                                </button>
                            </div>

                            <form v-else @submit.prevent="handleCreateCompany" class="mt-5 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div :class="inputBoxClass(focusedField === 'cc_name')">
                                        <label class="block text-[10px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                            {{ L('Yetkili Ad Soyad *', 'Full Name *') }}
                                        </label>
                                        <input
                                            v-model="companyForm.name"
                                            type="text"
                                            required
                                            @focus="focusedField = 'cc_name'"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                            :placeholder="L('Adınız Soyadınız', 'Your Full Name')"
                                        />
                                    </div>
                                    <div :class="inputBoxClass(focusedField === 'cc_company')">
                                        <label class="block text-[10px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                            {{ L('Ticari Firma Unvanı *', 'Company Legal Name *') }}
                                        </label>
                                        <input
                                            v-model="companyForm.company_name"
                                            type="text"
                                            required
                                            @focus="focusedField = 'cc_company'"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                            :placeholder="L('Örn: Örnek A.Ş.', 'e.g. Acme Corp.')"
                                        />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div :class="inputBoxClass(focusedField === 'cc_email')">
                                        <label class="block text-[10px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                            {{ L('Kurumsal E-posta *', 'Corporate Email *') }}
                                        </label>
                                        <input
                                            v-model="companyForm.email"
                                            type="email"
                                            required
                                            @focus="focusedField = 'cc_email'"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                            placeholder="yonetim@firma.com"
                                        />
                                    </div>
                                    <div :class="inputBoxClass(focusedField === 'cc_phone')">
                                        <label class="block text-[10px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                            {{ L('Telefon', 'Phone Number') }}
                                        </label>
                                        <input
                                            v-model="companyForm.phone"
                                            type="text"
                                            @focus="focusedField = 'cc_phone'"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                            placeholder="0555 000 00 00"
                                        />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div :class="inputBoxClass(focusedField === 'cc_city')">
                                        <label class="block text-[10px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                            {{ L('Şehir', 'City') }}
                                        </label>
                                        <input
                                            v-model="companyForm.city"
                                            type="text"
                                            @focus="focusedField = 'cc_city'"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                            :placeholder="L('İstanbul', 'Istanbul')"
                                        />
                                    </div>
                                    <div :class="inputBoxClass(focusedField === 'cc_pass')">
                                        <label class="block text-[10px] font-semibold" :class="isLight ? 'text-[#64748B]' : 'text-[#94A3B8]'">
                                            {{ L('Yönetici Şifresi *', 'Admin Password *') }}
                                        </label>
                                        <input
                                            v-model="companyForm.password"
                                            type="password"
                                            required
                                            minlength="8"
                                            @focus="focusedField = 'cc_pass'"
                                            @blur="focusedField = ''"
                                            class="w-full bg-transparent text-xs font-bold focus:outline-none mt-0.5"
                                            :class="isLight ? 'text-[#0F172A]' : 'text-white'"
                                            :placeholder="L('En az 8 karakter', 'Min 8 characters')"
                                        />
                                    </div>
                                </div>

                                <div
                                    v-if="createCompanyError"
                                    class="p-3 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-xs font-semibold"
                                    :class="isLight ? 'text-[#B91C1C]' : 'text-[#FF9E9E]'"
                                >
                                    {{ createCompanyError }}
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button
                                        type="button"
                                        @click="switchMode('login')"
                                        class="flex-1 py-3 px-4 rounded-full text-xs font-bold cursor-pointer"
                                        :class="isLight ? 'bg-slate-200 text-[#0F172A]' : 'bg-[#2C3347] text-white'"
                                    >
                                        {{ L('Giriş Ekranı', 'Back to Log In') }}
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="createCompanySubmitting"
                                        class="flex-1 py-3 px-6 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_10px_28px_rgba(185,255,102,0.35)] transition-all cursor-pointer keep-white"
                                    >
                                        {{ createCompanySubmitting ? L('Gönderiliyor...', 'Submitting...') : L('Firma Kurulum Başvurusu Yap', 'Submit Company Registration') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </transition>
                </div>

                <!-- Alt Bilgi -->
                <footer
                    class="flex items-center justify-between text-[11px] pt-2 border-t"
                    :class="isLight ? 'text-[#64748B] border-slate-200' : 'text-[#64748B] border-white/[0.05]'"
                >
                    <span>© {{ new Date().getFullYear() }} MRC CRM · ERP · SaaS</span>
                    <span class="flex items-center gap-1.5">
                        <ShieldCheck class="w-3.5 h-3.5 text-[#16A34A] dark:text-[#B9FF66]" />
                        {{ L('Uçtan Uca İzole Kurumsal Güvenlik', 'End-to-End Isolated Enterprise Security') }}
                    </span>
                </footer>
            </div>

            <!-- SAĞ BÖLÜM: Kıvrımlı Kesikli Çizgi + Atmosferik Görsel Sahne + .MRC Filigranı -->
            <div class="relative hidden lg:flex lg:w-[43%] overflow-hidden items-end justify-end p-10">
                <div
                    class="absolute inset-0"
                    :class="isLight
                        ? 'bg-gradient-to-br from-[#0F172A] via-[#1E293B] to-[#0F172A]'
                        : 'bg-gradient-to-br from-[#1C2438] via-[#141A29] to-[#0D111A]'"
                />
                <!-- Atmosferik Dağ / Ufuk Silüeti -->
                <svg class="absolute inset-0 w-full h-full object-cover opacity-45" viewBox="0 0 800 900" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="mrcGlow" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#B9FF66" stop-opacity="0.28" />
                            <stop offset="50%" stop-color="#3D55B6" stop-opacity="0.24" />
                            <stop offset="100%" stop-color="#0E121B" stop-opacity="0.95" />
                        </linearGradient>
                    </defs>
                    <rect width="800" height="900" fill="url(#mrcGlow)" />
                    <path d="M0,520 L180,360 L340,460 L540,270 L720,410 L800,340 L800,900 L0,900 Z" fill="#131826" opacity="0.75" />
                    <path d="M0,640 L230,490 L430,590 L640,430 L800,530 L800,900 L0,900 Z" fill="#0E121B" opacity="0.92" />
                </svg>

                <!-- Sol Kenar Kıvrımlı Kesikli Ayırıcı Çizgi -->
                <svg
                    class="absolute inset-y-0 -left-12 h-full w-32 z-10 pointer-events-none"
                    viewBox="0 0 120 900"
                    preserveAspectRatio="none"
                >
                    <path
                        d="M0,0 L70,0 C110,180 20,340 75,520 C115,660 30,780 65,900 L0,900 Z"
                        :fill="isLight ? '#FFFFFF' : '#171C29'"
                    />
                    <path
                        d="M72,0 C112,180 22,340 77,520 C117,660 32,780 67,900"
                        fill="none"
                        :stroke="isLight ? '#16A34A' : '#B9FF66'"
                        stroke-opacity="0.45"
                        stroke-width="1.8"
                        stroke-dasharray="7 9"
                    />
                </svg>

                <!-- Yüzen 3D Önizleme Kartı -->
                <div class="relative z-20 w-full max-w-[330px] mb-auto mt-14 ml-auto space-y-4 animate-float-3d keep-white">
                    <div class="p-5 rounded-3xl bg-[#212738]/85 backdrop-blur-xl border border-white/10 shadow-2xl">
                        <div class="flex items-center justify-between mb-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-[#B9FF66] text-[#131722] keep-white">
                                #MRC ERP
                            </span>
                            <span class="text-[11px] font-bold text-[#B9FF66]">+28.4%</span>
                        </div>
                        <p class="text-sm font-bold text-white keep-white">
                            {{ L('Canlı Finans, Barkod & İK Ekosistemi', 'Live Finance, Barcode & HR Ecosystem') }}
                        </p>
                        <p class="text-[11px] text-[#94A3B8] mt-1">
                            {{ L('Modüler eklentiler, özel İK başvuru formları ve kamera barkod okuyucu.', 'Modular plugins, custom HR forms, and camera barcode scanner.') }}
                        </p>
                    </div>
                </div>

                <!-- Sağ Alt Köşe ".MRC" Markası -->
                <div class="relative z-20 flex items-baseline gap-1 select-none keep-white">
                    <span class="w-3 h-3 rounded-full bg-[#B9FF66] inline-block" />
                    <span class="text-4xl font-black tracking-tighter text-white keep-white">MRC</span>
                    <span class="w-2.5 h-2.5 rounded-full bg-white inline-block mb-4" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useTheme } from '../composables/useTheme';
import { locale, setLocale } from '../i18n';
import client from '../api/client';
import BrandMark from '../components/BrandMark.vue';
import {
    Mail,
    Eye,
    EyeOff,
    Building2,
    ShieldCheck,
    Sun,
    Moon,
    Search,
    Upload,
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const { isLight, toggleTheme } = useTheme();

const currentLocale = computed(() => locale.value);

// Tek dil kuralı: Türkçe seçiliyse %100 Türkçe, İngilizce seçiliyse %100 İngilizce
function L(trText, enText) {
    return locale.value === 'en' ? enText : trText;
}

function changeLang(lang) {
    setLocale(lang);
}

const activeMode = ref(route.query.mode === 'join' ? 'join' : (route.query.mode === 'create_company' ? 'create_company' : 'login'));
const focusedField = ref('');
const showPassword = ref(false);

// 2FA state
const twoFactorInput = ref('');
const useRecovery = ref(false);

const loginForm = reactive({
    email: '',
    password: '',
});

// Katıl (Firma Seçimi + Özel Başvuru Formu)
const companySearchQuery = ref('');
const availableCompanies = ref([]);
const companiesLoading = ref(false);
const selectedCompany = ref(null);
const dynamicValues = reactive({});
const cvFile = ref(null);
const joinPassword = ref('');
const joinSubmitting = ref(false);
const joinError = ref('');
const joinSuccessMessage = ref('');

// Firma Kur (Yönetici Onaylı)
const companyForm = reactive({
    name: '',
    company_name: '',
    email: '',
    phone: '',
    city: '',
    password: '',
});
const createCompanySubmitting = ref(false);
const createCompanyError = ref('');
const createCompanySuccess = ref('');

let searchDebounce = null;

function inputBoxClass(isFocused) {
    if (isLight.value) {
        return [
            'px-4 py-2.5 rounded-2xl border transition-all',
            isFocused
                ? 'bg-white border-[#16A34A] ring-2 ring-[#16A34A]/20'
                : 'bg-[#F8FAFC] border-slate-200 hover:border-slate-300',
        ];
    }
    return [
        'px-4 py-2.5 rounded-2xl border transition-all',
        isFocused
            ? 'bg-[#242B3D] border-[#B9FF66] shadow-[0_0_0_3px_rgba(185,255,102,0.12)]'
            : 'bg-[#212738]/90 border-white/[0.08] hover:border-white/15',
    ];
}

function switchMode(mode) {
    activeMode.value = mode;
    authStore.error = null;
    joinError.value = '';
    joinSuccessMessage.value = '';
    createCompanyError.value = '';
    createCompanySuccess.value = '';
    if (mode === 'join' && availableCompanies.value.length === 0) {
        fetchPublicCompanies();
    }
}

async function fetchPublicCompanies() {
    companiesLoading.value = true;
    try {
        const { data } = await client.get('/public/companies', {
            params: { q: companySearchQuery.value.trim() },
        });
        availableCompanies.value = Array.isArray(data?.companies) ? data.companies : [];
    } catch {
        availableCompanies.value = [];
    } finally {
        companiesLoading.value = false;
    }
}

function onSearchCompaniesInput() {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        fetchPublicCompanies();
    }, 260);
}

const activeCompanyFields = computed(() => {
    const schema = selectedCompany.value?.form_schema || [];
    return schema.filter((f) => f.enabled !== false);
});

function fieldLabel(field) {
    return locale.value === 'en' ? (field.label_en || field.label_tr) : (field.label_tr || field.label_en);
}

function fieldPlaceholder(field) {
    return locale.value === 'en'
        ? (field.placeholder_en || field.placeholder_tr || '')
        : (field.placeholder_tr || field.placeholder_en || '');
}

function fieldOptions(field) {
    const list = locale.value === 'en'
        ? (field.options_en || field.options_tr || [])
        : (field.options_tr || field.options_en || []);
    return Array.isArray(list) ? list : [];
}

function chooseCompanyForJoin(comp) {
    selectedCompany.value = comp;
    joinError.value = '';
    cvFile.value = null;
    Object.keys(dynamicValues).forEach((k) => delete dynamicValues[k]);

    const schema = comp.form_schema || [];
    schema.forEach((f) => {
        if (f.type === 'select') {
            const opts = fieldOptions(f);
            dynamicValues[f.id] = opts[0] || '';
        } else {
            dynamicValues[f.id] = '';
        }
    });
}

function onCvFileChange(e) {
    const file = e.target.files?.[0];
    if (file) {
        cvFile.value = file;
    }
}

async function handleLogin() {
    try {
        const res = await authStore.login(loginForm);
        if (res?.requires_two_factor) return;
        if (res?.user?.is_superadmin) {
            router.push('/yonetim-ussu');
            return;
        }
        const redirectPath = route.query.redirect || '/';
        router.push(redirectPath);
    } catch {
        // Hata authStore.error içinde gösterilir
    }
}

async function handleTwoFactor() {
    try {
        await authStore.completeTwoFactor(
            useRecovery.value
                ? { recovery_code: twoFactorInput.value.trim() }
                : { code: twoFactorInput.value.trim() }
        );
        router.push(route.query.redirect || '/');
    } catch {
        // Hata authStore.error içinde
    }
}

function cancelTwoFactor() {
    authStore.cancelTwoFactor();
    twoFactorInput.value = '';
    useRecovery.value = false;
}

async function handleJoinSubmit() {
    joinError.value = '';
    if (!selectedCompany.value?.id) {
        joinError.value = L('Lütfen başvuracağınız firmayı seçin.', 'Please select a company to apply.');
        return;
    }

    joinSubmitting.value = true;
    try {
        const formData = new FormData();
        formData.append('company_id', String(selectedCompany.value.id));
        formData.append('first_name', String(dynamicValues.first_name || '').trim());
        formData.append('last_name', String(dynamicValues.last_name || '').trim());
        formData.append('email', String(dynamicValues.email || '').trim());
        formData.append('password', joinPassword.value);
        formData.append('phone', String(dynamicValues.phone || '').trim());
        formData.append('address', String(dynamicValues.address || '').trim());
        formData.append('requested_class', String(dynamicValues.requested_class || 'Personel').trim());
        formData.append('message', String(dynamicValues.message || '').trim());

        // Özel form yanıtlarını JSON olarak ekle
        const customAnswers = {};
        activeCompanyFields.value.forEach((f) => {
            if (!['first_name', 'last_name', 'email', 'phone', 'address', 'requested_class', 'message', 'cv_file'].includes(f.id)) {
                customAnswers[fieldLabel(f)] = dynamicValues[f.id] || '';
            }
        });
        formData.append('custom_answers', JSON.stringify(customAnswers));

        if (cvFile.value) {
            formData.append('cv_file', cvFile.value);
        }

        const { data } = await client.post('/public/join-requests', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        joinSuccessMessage.value = data.message;
    } catch (err) {
        const errors = err.response?.data?.errors;
        if (errors) {
            const firstErr = Object.values(errors)[0];
            joinError.value = Array.isArray(firstErr) ? firstErr[0] : String(firstErr);
        } else {
            joinError.value = err.response?.data?.message || L('Başvuru gönderilemedi.', 'Application could not be submitted.');
        }
    } finally {
        joinSubmitting.value = false;
    }
}

async function handleCreateCompany() {
    createCompanyError.value = '';
    createCompanySubmitting.value = true;
    try {
        const { data } = await client.post('/auth/register', {
            ...companyForm,
            require_approval: true,
        });
        createCompanySuccess.value = data.message || L(
            'Firma kurulum başvurunuz alındı. Sistem yöneticisi onayladıktan sonra giriş yapabilirsiniz.',
            'Your company registration request has been received. You can log in once approved by the system administrator.'
        );
    } catch (err) {
        const errors = err.response?.data?.errors;
        if (errors) {
            const firstErr = Object.values(errors)[0];
            createCompanyError.value = Array.isArray(firstErr) ? firstErr[0] : String(firstErr);
        } else {
            createCompanyError.value = err.response?.data?.message || L('Kayıt oluşturulamadı.', 'Registration failed.');
        }
    } finally {
        createCompanySubmitting.value = false;
    }
}

onMounted(() => {
    if (activeMode.value === 'join') {
        fetchPublicCompanies();
    }
});
</script>
