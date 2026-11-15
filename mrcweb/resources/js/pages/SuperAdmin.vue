<template>
    <div v-if="authStore.user?.is_superadmin" class="space-y-6 select-none max-w-[1380px] mx-auto pb-16">
        <!-- Üst Başlık & Gizli Güvenlik Rozeti -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#B9FF66] font-extrabold uppercase tracking-widest mb-1">
                    <ShieldCheck class="w-4 h-4" />
                    <span>GİZLİ SÜPERADMİN YÖNETİM ÜSSÜ · ZIRHLI ERİŞİM KATMANI</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                    Sistem, Firma Lisansları, Modül Kısıtlama & Plugin Merkezi
                </h1>
            </div>

            <!-- Sekme Seçici -->
            <div class="flex flex-wrap items-center gap-2 bg-[#212738] p-1.5 rounded-full border border-white/10">
                <button
                    type="button"
                    @click="activeTab = 'companies'"
                    class="px-4 py-2 rounded-full text-xs font-extrabold transition-all cursor-pointer flex items-center gap-2"
                    :class="activeTab === 'companies'
                        ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)] keep-white'
                        : 'text-[#94A3B8] hover:text-white'"
                >
                    <Building2 class="w-3.5 h-3.5" />
                    <span>Firmalar & Süre/Modül Yönetimi ({{ companies.length }})</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'plugins'"
                    class="px-4 py-2 rounded-full text-xs font-extrabold transition-all cursor-pointer flex items-center gap-2"
                    :class="activeTab === 'plugins'
                        ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)] keep-white'
                        : 'text-[#94A3B8] hover:text-white'"
                >
                    <Blocks class="w-3.5 h-3.5" />
                    <span>Modüler Plugin Fabrikası ({{ plugins.length }})</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'security'"
                    class="px-4 py-2 rounded-full text-xs font-extrabold transition-all cursor-pointer flex items-center gap-2"
                    :class="activeTab === 'security'
                        ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_16px_rgba(185,255,102,0.35)] keep-white'
                        : 'text-[#94A3B8] hover:text-white'"
                >
                    <KeyRound class="w-3.5 h-3.5" />
                    <span>SüperAdmin Hesap & Yetkililer ({{ superadmins.length }})</span>
                </button>
            </div>
        </div>

        <!-- Bildirim Bantları -->
        <div
            v-if="feedbackSuccess"
            class="p-4 rounded-2xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 text-xs font-extrabold text-[#B9FF66] flex items-center justify-between"
        >
            <span>✓ {{ feedbackSuccess }}</span>
            <button type="button" @click="feedbackSuccess = ''" class="text-white/70 hover:text-white cursor-pointer">✕</button>
        </div>
        <div
            v-if="feedbackError"
            class="p-4 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/50 text-xs font-extrabold text-[#FF9E9E] flex items-center justify-between"
        >
            <span>! {{ feedbackError }}</span>
            <button type="button" @click="feedbackError = ''" class="text-white/70 hover:text-white cursor-pointer">✕</button>
        </div>

        <!-- Özet KPI Kartları -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5">
            <div class="p-4 rounded-2xl bg-[#212738] border border-white/10 card-3d-interactive">
                <p class="text-[11px] font-bold text-[#94A3B8]">Toplam Firma</p>
                <p class="text-2xl font-black text-white mt-1">{{ stats.total_companies || 0 }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-[#212738] border border-[#DC6F31]/40 card-3d-interactive">
                <p class="text-[11px] font-bold text-[#DC6F31]">Onay Bekleyen</p>
                <p class="text-2xl font-black text-white mt-1">{{ stats.pending_companies || 0 }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-[#212738] border border-[#B9FF66]/40 card-3d-interactive">
                <p class="text-[11px] font-bold text-[#B9FF66]">Aktif Lisanslı</p>
                <p class="text-2xl font-black text-white mt-1">{{ stats.active_companies || 0 }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-[#212738] border border-[#D45759]/40 card-3d-interactive">
                <p class="text-[11px] font-bold text-[#FF9E9E]">Süresi Dolan</p>
                <p class="text-2xl font-black text-white mt-1">{{ stats.expired_companies || 0 }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-[#212738] border border-[#459BBE]/40 card-3d-interactive">
                <p class="text-[11px] font-bold text-[#459BBE]">Toplam Kullanıcı</p>
                <p class="text-2xl font-black text-white mt-1">{{ stats.total_users || 0 }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-[#212738] border border-[#BC5FCF]/40 card-3d-interactive">
                <p class="text-[11px] font-bold text-[#BC5FCF]">Kurulu Plugin</p>
                <p class="text-2xl font-black text-white mt-1">{{ stats.plugins_count || 0 }}</p>
            </div>
        </div>

        <!-- =========================================================================
             SEKME 1: FİRMA ONAY, KULLANIM SÜRESİ & MODÜL KISITLAMA MERKEZİ
             ========================================================================= -->
        <div v-if="activeTab === 'companies'" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-[#212738] p-4 rounded-2xl border border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="f in statusFilters"
                        :key="f.id"
                        type="button"
                        @click="selectedStatusFilter = f.id"
                        class="px-3.5 py-1.5 rounded-full text-xs font-bold transition-all cursor-pointer"
                        :class="selectedStatusFilter === f.id
                            ? 'bg-[#B9FF66] text-[#131722] keep-white'
                            : 'bg-[#131722] text-[#94A3B8] hover:text-white border border-white/[0.06]'"
                    >
                        {{ f.label }}
                    </button>
                </div>

                <div class="relative w-full sm:w-72">
                    <Search class="w-4 h-4 text-[#94A3B8] absolute left-3.5 top-1/2 -translate-y-1/2" />
                    <input
                        v-model="companySearch"
                        type="text"
                        placeholder="Firma adı, yetkili veya e-posta ara..."
                        class="w-full pl-9 pr-4 py-2 rounded-full bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                    />
                </div>
            </div>

            <!-- Firma Kartları -->
            <div class="space-y-4">
                <div
                    v-for="comp in filteredCompanies"
                    :key="comp.id"
                    class="p-5 rounded-3xl bg-[#212738] border transition-all space-y-4"
                    :class="comp.approval_status === 'pending'
                        ? 'border-[#DC6F31]/60 shadow-[0_0_25px_rgba(220,111,49,0.15)]'
                        : 'border-white/10'"
                >
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="text-base font-extrabold text-white">{{ comp.name }}</span>
                                <span
                                    class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase"
                                    :class="statusBadgeClass(comp)"
                                >
                                    {{ statusBadgeLabel(comp) }}
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-[#3D55B6]/25 text-[#93C5FD] border border-[#3D55B6]/40 uppercase">
                                    Plan: {{ comp.plan || 'standart' }}
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#131722] text-[#94A3B8] border border-white/10">
                                    {{ comp.users_count }} / {{ comp.max_users || '∞' }} Kullanıcı
                                </span>
                            </div>
                            <p class="text-xs text-[#94A3B8]">
                                Kurucu: <strong class="text-white">{{ comp.owner?.name || '—' }}</strong> ({{ comp.owner?.email || '—' }})
                                <span v-if="comp.city"> · Şehir: {{ comp.city }}</span>
                                <span v-if="comp.tax_number"> · VKN: {{ comp.tax_number }}</span>
                            </p>
                            <p class="text-[11px]" :class="comp.is_expired ? 'text-[#FF9E9E] font-bold' : 'text-[#B9FF66]'">
                                <template v-if="!comp.subscription_ends_at">
                                    Lisans Süresi: SÜRESİZ (Limitsiz Erişim)
                                </template>
                                <template v-else-if="comp.is_expired">
                                    Lisans Süresi DOLDU ({{ formatDate(comp.subscription_ends_at) }})
                                </template>
                                <template v-else>
                                    Kalan Kullanım Süresi: {{ comp.days_remaining }} Gün (Bitiş: {{ formatDate(comp.subscription_ends_at) }})
                                </template>
                            </p>
                        </div>

                        <!-- Hızlı Onay / Askıya Al / Modül Kısıtla Butonları -->
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-if="comp.approval_status !== 'active'"
                                type="button"
                                @click="quickUpdateCompany(comp, { approval_status: 'active' })"
                                class="px-4 py-2 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold cursor-pointer keep-white"
                            >
                                ✓ Firmayı Onayla & Aktif Et
                            </button>

                            <button
                                v-if="comp.approval_status === 'active'"
                                type="button"
                                @click="quickUpdateCompany(comp, { approval_status: 'suspended' })"
                                class="px-3.5 py-2 rounded-full bg-[#DC6F31]/20 border border-[#DC6F31]/50 text-[#FDBA74] text-xs font-bold hover:bg-[#DC6F31]/30 cursor-pointer"
                            >
                                Askıya Al
                            </button>

                            <button
                                type="button"
                                @click="toggleManageDrawer(comp)"
                                class="px-4 py-2 rounded-full bg-[#3D55B6] hover:bg-[#4B66D1] text-white text-xs font-extrabold cursor-pointer keep-white"
                            >
                                {{ editingCompanyId === comp.id ? 'Ayarları Kapat' : '⚙️ Plan, Süre & Tüm Limitler' }}
                            </button>

                            <button
                                type="button"
                                @click="deleteCompany(comp)"
                                class="p-2 rounded-full bg-[#C55050]/15 border border-[#C55050]/35 text-[#FF9E9E] hover:bg-[#C55050]/30 cursor-pointer"
                                title="Firmayı Sil"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <!-- Açılır Detaylı Kullanım Süresi, Modül & Limit Paneli -->
                    <div
                        v-if="editingCompanyId === comp.id"
                        class="pt-5 border-t border-white/10 space-y-6 bg-[#131722]/85 p-5 rounded-2xl border border-white/10"
                    >
                        <!-- 1. BÖLÜM: PLAN SEVİYESİ & HESAP DURUMU (AKTİF/PASİF) -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pb-4 border-b border-white/[0.08]">
                            <!-- Plan Seçici -->
                            <div>
                                <label class="block text-xs font-black text-white mb-2 flex items-center justify-between">
                                    <span>1. Abonelik Planı Seviyesi:</span>
                                    <span class="text-[11px] text-[#B9FF66] font-mono">Seçili: {{ editState.plan.toUpperCase() }}</span>
                                </label>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <button
                                        v-for="p in availablePlans"
                                        :key="p.key"
                                        type="button"
                                        @click="setCompanyPlan(p.key)"
                                        class="px-3 py-2 rounded-xl text-xs font-bold border text-center transition-all cursor-pointer"
                                        :class="editState.plan === p.key
                                            ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_12px_rgba(185,255,102,0.35)]'
                                            : 'bg-[#212738] text-white border-white/10 hover:border-white/20'"
                                    >
                                        {{ p.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- Hesap Durumu (Aktif/Pasif/Onay) -->
                            <div>
                                <label class="block text-xs font-black text-white mb-2 flex items-center justify-between">
                                    <span>2. Firma Hesap Durumu (Aktif / Pasif / Askı):</span>
                                    <span class="text-[11px] font-bold uppercase" :class="statusBadgeClass({ approval_status: editState.approval_status, is_expired: false })">
                                        {{ editState.approval_status }}
                                    </span>
                                </label>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <button
                                        type="button"
                                        @click="editState.approval_status = 'active'"
                                        class="px-2.5 py-2 rounded-xl text-xs font-bold border text-center transition-all cursor-pointer"
                                        :class="editState.approval_status === 'active'
                                            ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_12px_rgba(185,255,102,0.35)]'
                                            : 'bg-[#212738] text-[#94A3B8] border-white/10 hover:border-white/20'"
                                    >
                                        ✓ Aktif & Onaylı
                                    </button>
                                    <button
                                        type="button"
                                        @click="editState.approval_status = 'suspended'"
                                        class="px-2.5 py-2 rounded-xl text-xs font-bold border text-center transition-all cursor-pointer"
                                        :class="editState.approval_status === 'suspended'
                                            ? 'bg-[#DC6F31] text-white border-[#DC6F31] shadow-[0_0_12px_rgba(220,111,49,0.35)]'
                                            : 'bg-[#212738] text-[#94A3B8] border-white/10 hover:border-white/20'"
                                    >
                                        ⏸ Askıya Al (Pasif)
                                    </button>
                                    <button
                                        type="button"
                                        @click="editState.approval_status = 'pending'"
                                        class="px-2.5 py-2 rounded-xl text-xs font-bold border text-center transition-all cursor-pointer"
                                        :class="editState.approval_status === 'pending'
                                            ? 'bg-[#FDBA74] text-[#131722] border-[#FDBA74]'
                                            : 'bg-[#212738] text-[#94A3B8] border-white/10 hover:border-white/20'"
                                    >
                                        ⏳ Onay Bekliyor
                                    </button>
                                    <button
                                        type="button"
                                        @click="editState.approval_status = 'rejected'"
                                        class="px-2.5 py-2 rounded-xl text-xs font-bold border text-center transition-all cursor-pointer"
                                        :class="editState.approval_status === 'rejected'
                                            ? 'bg-[#C55050] text-white border-[#C55050]'
                                            : 'bg-[#212738] text-[#94A3B8] border-white/10 hover:border-white/20'"
                                    >
                                        ✕ Reddedildi
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 2. BÖLÜM: KULLANIM SÜRESİ & LİSANS BİTİŞ TARİHİ -->
                        <div class="pb-4 border-b border-white/[0.08]">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-xs font-black text-white">
                                    3. Firma Lisans & Kullanım Süresi (Bitiş Tarihi):
                                </label>
                                <span class="text-xs font-bold" :class="editState.custom_date ? 'text-[#B9FF66]' : 'text-[#60A5FA]'">
                                    {{ editState.custom_date ? `Bitiş: ${editState.custom_date} (${calcRemainingDays(editState.custom_date)})` : 'SÜRESİZ LİSANS (Tarih Sınırı Yok)' }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-for="preset in durationPresets"
                                    :key="preset.key"
                                    type="button"
                                    @click="applyDurationPreset(preset.key)"
                                    class="px-3 py-1.5 rounded-xl border text-xs font-bold transition-all cursor-pointer"
                                    :class="editState.duration_preset === preset.key
                                        ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66]'
                                        : 'bg-[#212738] text-white border-white/10 hover:border-[#B9FF66]/50'"
                                >
                                    {{ preset.label }}
                                </button>

                                <div class="flex items-center gap-2 ml-auto">
                                    <span class="text-xs text-[#94A3B8] font-bold">Özel Tarih:</span>
                                    <input
                                        v-model="editState.custom_date"
                                        type="date"
                                        class="px-3 py-1.5 rounded-xl bg-[#212738] border border-white/15 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                                    />
                                    <button
                                        v-if="editState.custom_date"
                                        type="button"
                                        @click="editState.custom_date = ''; editState.duration_preset = 'unlimited'"
                                        class="px-2.5 py-1.5 rounded-xl bg-white/10 text-[#94A3B8] hover:text-white text-xs font-bold cursor-pointer"
                                    >
                                        Süresiz Yap
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 3. BÖLÜM: YETKİLİ MODÜLLER (MODÜL KISITLAMA) -->
                        <div class="pb-4 border-b border-white/[0.08]">
                            <div class="flex items-center justify-between mb-2.5">
                                <div>
                                    <label class="text-xs font-black text-white">
                                        4. Firma Yetkili Modülleri (Kapatılan modüller sol menü, üst bar ve API seviyesinde engellenir):
                                    </label>
                                    <p class="text-[11px] text-[#94A3B8]">
                                        İşareti kaldırılan modül firmanın tüm kullanıcılarına derhal kapanır.
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="selectAllModulesForEdit"
                                        class="text-[11px] font-bold text-[#B9FF66] hover:underline cursor-pointer"
                                    >
                                        Tümünü Aç
                                    </button>
                                    <span class="text-white/20">|</span>
                                    <button
                                        type="button"
                                        @click="deselectAllModulesForEdit"
                                        class="text-[11px] font-bold text-[#F87171] hover:underline cursor-pointer"
                                    >
                                        Tümünü Kapat
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2.5">
                                <label
                                    v-for="mod in coreModules"
                                    :key="mod.key"
                                    class="flex items-center gap-2.5 p-3 rounded-xl border text-xs font-bold cursor-pointer transition-all select-none"
                                    :class="editState.allowed_modules.includes(mod.key)
                                        ? 'bg-[#B9FF66]/10 border-[#B9FF66]/40 text-white shadow-xs'
                                        : 'bg-[#212738]/50 border-white/5 text-[#64748B] opacity-75'"
                                >
                                    <input
                                        type="checkbox"
                                        :value="mod.key"
                                        :disabled="mod.pinned"
                                        v-model="editState.allowed_modules"
                                        class="w-4 h-4 accent-[#B9FF66] rounded cursor-pointer"
                                    />
                                    <div class="flex flex-col">
                                        <span :class="editState.allowed_modules.includes(mod.key) ? 'text-white font-extrabold' : 'text-[#64748B] font-medium'">{{ mod.name_tr }}</span>
                                        <span v-if="mod.pinned" class="text-[9px] text-[#B9FF66] font-mono">Zorunlu</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- 4. BÖLÜM: ÖZEL KAYNAK, STOK & BELGE LİMİTLERİ -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <label class="text-xs font-black text-white">
                                        5. Özel Firma Limitleri (Kullanıcı, Ürün/Stok, Aylık Belge ve Tahsilat Sınırları):
                                    </label>
                                    <p class="text-[11px] text-[#94A3B8]">
                                        Burada belirlenen limitler firma ayarlarındaki Plan sekmesinde canlı görünür ve aşılınca sistem yeni kaydı durdurur.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="loadPlanDefaultLimits"
                                    class="px-3 py-1 rounded-xl bg-white/5 hover:bg-white/10 text-white text-[11px] font-bold border border-white/10 cursor-pointer"
                                >
                                    ↺ Seçili Plana Göre Varsayılan Limitleri Yükle
                                </button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                <!-- Limit 1: Kullanıcı -->
                                <div class="p-3.5 rounded-2xl bg-[#212738] border border-white/10 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-white">Kullanıcı Limiti:</span>
                                        <label class="flex items-center gap-1.5 text-[11px] text-[#94A3B8] cursor-pointer">
                                            <input
                                                type="checkbox"
                                                v-model="editState.unlimited_flags.users"
                                                class="w-3.5 h-3.5 accent-[#B9FF66]"
                                            />
                                            <span>Sınırsız</span>
                                        </label>
                                    </div>
                                    <input
                                        v-if="!editState.unlimited_flags.users"
                                        v-model.number="editState.custom_limits.users"
                                        type="number"
                                        min="1"
                                        placeholder="Örn: 10"
                                        class="w-full px-3 py-1.5 rounded-xl bg-[#131722] border border-white/15 text-sm font-black text-white focus:outline-none focus:border-[#B9FF66]"
                                    />
                                    <div v-else class="py-1.5 px-3 rounded-xl bg-[#131722]/50 text-xs font-black text-[#B9FF66]">
                                        ∞ Sınırsız Kullanıcı
                                    </div>
                                </div>

                                <!-- Limit 2: Ürün / Stok -->
                                <div class="p-3.5 rounded-2xl bg-[#212738] border border-white/10 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-white">Ürün / Stok Limiti:</span>
                                        <label class="flex items-center gap-1.5 text-[11px] text-[#94A3B8] cursor-pointer">
                                            <input
                                                type="checkbox"
                                                v-model="editState.unlimited_flags.products"
                                                class="w-3.5 h-3.5 accent-[#B9FF66]"
                                            />
                                            <span>Sınırsız</span>
                                        </label>
                                    </div>
                                    <input
                                        v-if="!editState.unlimited_flags.products"
                                        v-model.number="editState.custom_limits.products"
                                        type="number"
                                        min="1"
                                        placeholder="Örn: 2000"
                                        class="w-full px-3 py-1.5 rounded-xl bg-[#131722] border border-white/15 text-sm font-black text-white focus:outline-none focus:border-[#B9FF66]"
                                    />
                                    <div v-else class="py-1.5 px-3 rounded-xl bg-[#131722]/50 text-xs font-black text-[#B9FF66]">
                                        ∞ Sınırsız Ürün & Stok
                                    </div>
                                </div>

                                <!-- Limit 3: Aylık Belge -->
                                <div class="p-3.5 rounded-2xl bg-[#212738] border border-white/10 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-white">Aylık Belge Limiti:</span>
                                        <label class="flex items-center gap-1.5 text-[11px] text-[#94A3B8] cursor-pointer">
                                            <input
                                                type="checkbox"
                                                v-model="editState.unlimited_flags.documents_per_month"
                                                class="w-3.5 h-3.5 accent-[#B9FF66]"
                                            />
                                            <span>Sınırsız</span>
                                        </label>
                                    </div>
                                    <input
                                        v-if="!editState.unlimited_flags.documents_per_month"
                                        v-model.number="editState.custom_limits.documents_per_month"
                                        type="number"
                                        min="1"
                                        placeholder="Örn: 2000"
                                        class="w-full px-3 py-1.5 rounded-xl bg-[#131722] border border-white/15 text-sm font-black text-white focus:outline-none focus:border-[#B9FF66]"
                                    />
                                    <div v-else class="py-1.5 px-3 rounded-xl bg-[#131722]/50 text-xs font-black text-[#B9FF66]">
                                        ∞ Sınırsız Belge / Ay
                                    </div>
                                </div>

                                <!-- Limit 4: Aylık Ödeme Linki -->
                                <div class="p-3.5 rounded-2xl bg-[#212738] border border-white/10 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-white">Aylık Ödeme Linki:</span>
                                        <label class="flex items-center gap-1.5 text-[11px] text-[#94A3B8] cursor-pointer">
                                            <input
                                                type="checkbox"
                                                v-model="editState.unlimited_flags.payment_links_per_month"
                                                class="w-3.5 h-3.5 accent-[#B9FF66]"
                                            />
                                            <span>Sınırsız</span>
                                        </label>
                                    </div>
                                    <input
                                        v-if="!editState.unlimited_flags.payment_links_per_month"
                                        v-model.number="editState.custom_limits.payment_links_per_month"
                                        type="number"
                                        min="1"
                                        placeholder="Örn: 300"
                                        class="w-full px-3 py-1.5 rounded-xl bg-[#131722] border border-white/15 text-sm font-black text-white focus:outline-none focus:border-[#B9FF66]"
                                    />
                                    <div v-else class="py-1.5 px-3 rounded-xl bg-[#131722]/50 text-xs font-black text-[#B9FF66]">
                                        ∞ Sınırsız Tahsilat Linki
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 5. BÖLÜM: TÜMÜNÜ BİRDEN KAYDET BUTONU -->
                        <div class="pt-3 flex flex-wrap items-center justify-between gap-4">
                            <p class="text-xs text-[#94A3B8]">
                                Yapılan süre, plan, durum, yetki ve limit değişiklikleri veritabanına işlenir ve bu firmanın tüm oturumlarına derhal yansır.
                            </p>
                            <button
                                type="button"
                                @click="saveAllCompanySettings(comp)"
                                class="py-3 px-8 rounded-2xl bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-sm font-black shadow-[0_8px_25px_rgba(185,255,102,0.4)] transition-all cursor-pointer keep-white shrink-0 active:scale-95"
                            >
                                💾 Tüm Ayarları ve Limitleri Kaydet
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             SEKME 2: MODÜLER PLUGIN / EKLENTİ FABRİKASI
             ========================================================================= -->
        <div v-else-if="activeTab === 'plugins'" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Sol: Yeni Modüler Plugin Oluşturucu -->
            <div class="lg:col-span-5 p-6 rounded-3xl bg-[#212738] border border-white/10 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 flex items-center justify-center text-[#B9FF66]">
                        <Blocks class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-white">Yeni Modüler Plugin Paketi Üret</h2>
                        <p class="text-xs text-[#94A3B8]">Ana site kodunu değiştirmeden yeni eklenti ve widget modülü ekleyin</p>
                    </div>
                </div>

                <form @submit.prevent="handleCreatePlugin" class="space-y-3 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-[#94A3B8] mb-1">Plugin Anahtarı (küçük harf & tire) *</label>
                        <input
                            v-model="pluginForm.key"
                            type="text"
                            required
                            placeholder="orn: kargo-entegrasyon"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1">Plugin Adı (TR) *</label>
                            <input
                                v-model="pluginForm.name_tr"
                                type="text"
                                required
                                placeholder="Kargo Takip Modülü"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1">Plugin Adı (EN)</label>
                            <input
                                v-model="pluginForm.name_en"
                                type="text"
                                placeholder="Cargo Tracking Plugin"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1">Dashboard Widget Başlığı *</label>
                            <input
                                v-model="pluginForm.widget_title_tr"
                                type="text"
                                required
                                placeholder="Kargo Gönderi Özeti"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#94A3B8] mb-1">Widget Genişliği</label>
                            <select
                                v-model="pluginForm.widget_size"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none"
                            >
                                <option value="half">Yarım Genişlik (1/2)</option>
                                <option value="full">Tam Genişlik (Tam)</option>
                            </select>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-xs font-bold text-[#B9FF66] cursor-pointer pt-1">
                        <input v-model="pluginForm.install_all" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                        <span>Oluşturulduğunda tüm kayıtlı firmalara otomatik kur ve etkinleştir</span>
                    </label>

                    <button
                        type="submit"
                        class="w-full py-3 px-5 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_8px_24px_rgba(185,255,102,0.3)] cursor-pointer keep-white"
                    >
                        + Plugin Paketini Oluştur ve Sisteme Ekle
                    </button>
                </form>
            </div>

            <!-- Sağ: Sistemdeki Mevcut Plugin Paketleri -->
            <div class="lg:col-span-7 p-6 rounded-3xl bg-[#212738] border border-white/10 space-y-4">
                <h2 class="text-base font-extrabold text-white">Sistemde Kayıtlı Modüler Plugin Paketleri ({{ plugins.length }})</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div
                        v-for="p in plugins"
                        :key="p.key"
                        class="p-4 rounded-2xl bg-[#131722] border border-white/10 space-y-2 card-3d-interactive"
                    >
                        <div class="flex items-center justify-between">
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-[#B9FF66]/15 text-[#B9FF66]">
                                v{{ p.version }}
                            </span>
                            <span class="text-[11px] font-mono text-[#94A3B8]">plugins/{{ p.key }}</span>
                        </div>
                        <p class="text-sm font-extrabold text-white">{{ p.name_tr }}</p>
                        <p class="text-xs text-[#94A3B8]">{{ p.name_en }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             SEKME 3: SÜPERADMİN HESAP BİLGİLERİ & YETKİLİ YÖNETİMİ
             ========================================================================= -->
        <div v-else class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Sol: Kendi SüperAdmin Bilgilerimi Güncelle -->
            <div class="lg:col-span-5 p-6 rounded-3xl bg-[#212738] border border-white/10 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 flex items-center justify-center text-[#B9FF66]">
                        <KeyRound class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-white">SüperAdmin Giriş Bilgilerimi Değiştir</h2>
                        <p class="text-xs text-[#94A3B8]">Giriş e-posta adresinizi ve şifrenizi istediğiniz zaman güncelleyin</p>
                    </div>
                </div>

                <form @submit.prevent="handleUpdateMyCredentials" class="space-y-3 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-[#94A3B8] mb-1">Ad Soyad / Başlık *</label>
                        <input
                            v-model="myCreds.name"
                            type="text"
                            required
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-[#94A3B8] mb-1">SüperAdmin E-posta Adresi *</label>
                        <input
                            v-model="myCreds.email"
                            type="email"
                            required
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-[#94A3B8] mb-1">Yeni Şifre (Değiştirmek istemiyorsanız boş bırakın)</label>
                        <input
                            v-model="myCreds.new_password"
                            type="password"
                            minlength="8"
                            placeholder="••••••••"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                        />
                    </div>
                    <button
                        type="submit"
                        class="w-full py-3 px-5 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_8px_24px_rgba(185,255,102,0.3)] cursor-pointer keep-white"
                    >
                        Giriş Bilgilerimi Güncelle
                    </button>
                </form>
            </div>

            <!-- Sağ: Yeni SüperAdmin Ekle & Mevcut SüperAdminler -->
            <div class="lg:col-span-7 p-6 rounded-3xl bg-[#212738] border border-white/10 space-y-5">
                <div>
                    <h2 class="text-base font-extrabold text-white">Yeni SüperAdmin Yetkilisi Ekle</h2>
                    <p class="text-xs text-[#94A3B8] mt-0.5">Bu panele tam yetkiyle erişebilecek başka bir SüperAdmin hesabı tanımlayın</p>
                </div>

                <form @submit.prevent="handleAddSuperAdmin" class="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-[#131722] p-4 rounded-2xl border border-white/10">
                    <input
                        v-model="newSuperAdmin.name"
                        type="text"
                        required
                        placeholder="Ad Soyad"
                        class="px-3.5 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none"
                    />
                    <input
                        v-model="newSuperAdmin.email"
                        type="email"
                        required
                        placeholder="E-posta Adresi"
                        class="px-3.5 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none"
                    />
                    <div class="flex items-center gap-2">
                        <input
                            v-model="newSuperAdmin.password"
                            type="password"
                            required
                            minlength="8"
                            placeholder="Şifre"
                            class="w-full px-3.5 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none"
                        />
                        <button
                            type="submit"
                            class="px-4 py-2 rounded-xl bg-[#B9FF66] text-[#131722] text-xs font-extrabold shrink-0 cursor-pointer keep-white"
                        >
                            + Ekle
                        </button>
                    </div>
                </form>

                <div class="space-y-2.5">
                    <h3 class="text-xs font-extrabold uppercase tracking-wider text-[#94A3B8]">
                        Yetkili SüperAdmin Hesapları ({{ superadmins.length }})
                    </h3>
                    <div
                        v-for="sa in superadmins"
                        :key="sa.id"
                        class="p-3.5 rounded-2xl bg-[#131722] border border-white/10 flex items-center justify-between gap-3"
                    >
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-[#B9FF66] text-[#131722] font-black text-xs flex items-center justify-center keep-white">
                                SA
                            </div>
                            <div>
                                <p class="text-sm font-extrabold text-white">{{ sa.name }}</p>
                                <p class="text-xs text-[#94A3B8]">{{ sa.email }}</p>
                            </div>
                        </div>

                        <button
                            v-if="superadmins.length > 1"
                            type="button"
                            @click="handleRevokeSuperAdmin(sa)"
                            class="px-3 py-1.5 rounded-xl bg-[#C55050]/20 border border-[#C55050]/40 text-[#FF9E9E] text-xs font-bold hover:bg-[#C55050]/35 cursor-pointer"
                        >
                            Yetkiyi Kaldır
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import client from '../api/client';
import { formatDate } from '../utils/format';
import {
    ShieldCheck,
    Building2,
    Blocks,
    KeyRound,
    Search,
    Trash2,
} from 'lucide-vue-next';

const router = useRouter();
const authStore = useAuthStore();

const activeTab = ref('companies');
const stats = ref({});
const companies = ref([]);
const superadmins = ref([]);
const coreModules = ref([]);
const plugins = ref([]);
const availablePlans = ref([
    { key: 'ucretsiz', label: 'Ücretsiz', limits: { users: 2, products: 100, documents_per_month: 50, payment_links_per_month: 10 } },
    { key: 'standart', label: 'Standart', limits: { users: 10, products: 2000, documents_per_month: 2000, payment_links_per_month: 300 } },
    { key: 'pro', label: 'Pro', limits: { users: 50, products: 20000, documents_per_month: 20000, payment_links_per_month: 3000 } },
    { key: 'sinirsiz', label: 'Sınırsız', limits: { users: null, products: null, documents_per_month: null, payment_links_per_month: null } },
]);

const selectedStatusFilter = ref('all');
const companySearch = ref('');
const editingCompanyId = ref(null);

const editState = reactive({
    approval_status: 'active',
    plan: 'standart',
    custom_date: '',
    duration_preset: null,
    allowed_modules: [],
    custom_limits: {
        users: 10,
        products: 2000,
        documents_per_month: 2000,
        payment_links_per_month: 300,
    },
    unlimited_flags: {
        users: false,
        products: false,
        documents_per_month: false,
        payment_links_per_month: false,
    },
});

const feedbackSuccess = ref('');
const feedbackError = ref('');

const statusFilters = [
    { id: 'all', label: 'Tümü' },
    { id: 'pending', label: 'Onay Bekleyenler' },
    { id: 'active', label: 'Aktif Firmalar' },
    { id: 'expired', label: 'Süresi Dolanlar' },
    { id: 'suspended', label: 'Askıya Alınanlar' },
];

const durationPresets = [
    { key: '7d', label: '+7 Gün' },
    { key: '30d', label: '+1 Ay (30 Gün)' },
    { key: '90d', label: '+3 Ay (90 Gün)' },
    { key: '180d', label: '+6 Ay' },
    { key: '365d', label: '+1 Yıl (365 Gün)' },
    { key: 'unlimited', label: 'Süresiz Lisans' },
    { key: 'expired', label: 'Süreyi Bitir (Kapat)' },
];

const pluginForm = reactive({
    key: '',
    name_tr: '',
    name_en: '',
    widget_title_tr: '',
    widget_size: 'half',
    install_all: true,
});

const myCreds = reactive({
    name: authStore.user?.name || 'Super Admin',
    email: authStore.user?.email || 'admin@example.com',
    new_password: '',
});

const newSuperAdmin = reactive({
    name: '',
    email: '',
    password: '',
});

const filteredCompanies = computed(() => {
    const q = companySearch.value.trim().toLowerCase();
    return companies.value.filter((c) => {
        if (selectedStatusFilter.value === 'pending' && c.approval_status !== 'pending') return false;
        if (selectedStatusFilter.value === 'active' && (c.approval_status !== 'active' || c.is_expired)) return false;
        if (selectedStatusFilter.value === 'expired' && !c.is_expired) return false;
        if (selectedStatusFilter.value === 'suspended' && c.approval_status !== 'suspended') return false;

        if (!q) return true;
        return (
            (c.name || '').toLowerCase().includes(q) ||
            (c.owner?.email || '').toLowerCase().includes(q) ||
            (c.owner?.name || '').toLowerCase().includes(q) ||
            (c.city || '').toLowerCase().includes(q)
        );
    });
});

function statusBadgeClass(comp) {
    if (comp.approval_status === 'pending') return 'bg-[#DC6F31]/25 text-[#FDBA74] border border-[#DC6F31]/50';
    if (comp.approval_status === 'suspended' || comp.approval_status === 'rejected') return 'bg-[#C55050]/25 text-[#FF9E9E] border border-[#C55050]/50';
    if (comp.is_expired) return 'bg-[#C55050]/25 text-[#FF9E9E] border border-[#C55050]/50';
    return 'bg-[#B9FF66]/20 text-[#B9FF66] border border-[#B9FF66]/40';
}

function statusBadgeLabel(comp) {
    if (comp.approval_status === 'pending') return 'Yönetici Onayı Bekliyor';
    if (comp.approval_status === 'suspended') return 'Askıya Alındı';
    if (comp.approval_status === 'rejected') return 'Reddedildi';
    if (comp.is_expired) return 'Süresi Doldu';
    return 'Aktif & Onaylı';
}

function showSuccess(msg) {
    feedbackSuccess.value = msg;
    feedbackError.value = '';
}

function showError(msg) {
    feedbackError.value = msg;
    feedbackSuccess.value = '';
}

function calcRemainingDays(dateStr) {
    if (!dateStr) return 'Süresiz';
    const target = new Date(dateStr + 'T23:59:59');
    const now = new Date();
    const diff = Math.ceil((target - now) / (1000 * 60 * 60 * 24));
    if (diff < 0) return 'Süresi Doldu';
    if (diff === 0) return 'Bugün Bitiyor';
    return `${diff} Gün Kaldı`;
}

function setCompanyPlan(planKey) {
    editState.plan = planKey;
    loadPlanDefaultLimits();
}

function loadPlanDefaultLimits() {
    const p = availablePlans.value.find((x) => x.key === editState.plan);
    if (!p || !p.limits) return;

    for (const res of ['users', 'products', 'documents_per_month', 'payment_links_per_month']) {
        const val = p.limits[res];
        editState.unlimited_flags[res] = (val === null);
        editState.custom_limits[res] = val === null ? null : Number(val);
    }
}

function applyDurationPreset(presetKey) {
    editState.duration_preset = presetKey;
    const d = new Date();
    if (presetKey === '7d') {
        d.setDate(d.getDate() + 7);
        editState.custom_date = d.toISOString().slice(0, 10);
    } else if (presetKey === '30d') {
        d.setDate(d.getDate() + 30);
        editState.custom_date = d.toISOString().slice(0, 10);
    } else if (presetKey === '90d') {
        d.setDate(d.getDate() + 90);
        editState.custom_date = d.toISOString().slice(0, 10);
    } else if (presetKey === '180d') {
        d.setDate(d.getDate() + 180);
        editState.custom_date = d.toISOString().slice(0, 10);
    } else if (presetKey === '365d') {
        d.setDate(d.getDate() + 365);
        editState.custom_date = d.toISOString().slice(0, 10);
    } else if (presetKey === 'unlimited') {
        editState.custom_date = '';
    } else if (presetKey === 'expired') {
        d.setDate(d.getDate() - 1);
        editState.custom_date = d.toISOString().slice(0, 10);
    }
}

async function fetchOverview() {
    try {
        const { data } = await client.get('/sa-core/overview');
        stats.value = data?.stats || {};
        companies.value = data?.companies || [];
        superadmins.value = data?.superadmins || [];
        coreModules.value = data?.core_modules || [];
        plugins.value = data?.plugins || [];
        if (Array.isArray(data?.available_plans) && data.available_plans.length > 0) {
            availablePlans.value = data.available_plans;
        }
    } catch {
        router.replace('/');
    }
}

function toggleManageDrawer(comp) {
    if (editingCompanyId.value === comp.id) {
        editingCompanyId.value = null;
        return;
    }
    editingCompanyId.value = comp.id;
    editState.approval_status = comp.approval_status || 'active';
    editState.plan = comp.plan || 'standart';
    editState.custom_date = comp.subscription_ends_at ? comp.subscription_ends_at.slice(0, 10) : '';
    editState.duration_preset = null;

    if (Array.isArray(comp.allowed_modules) && comp.allowed_modules.length > 0) {
        editState.allowed_modules = [...comp.allowed_modules];
    } else {
        editState.allowed_modules = coreModules.value.map((m) => m.key);
    }

    const cLimits = comp.custom_limits || {};
    const eff = {};
    (comp.effective_limits || []).forEach((item) => {
        eff[item.resource] = item.limit;
    });

    const resources = ['users', 'products', 'documents_per_month', 'payment_links_per_month'];
    resources.forEach((r) => {
        let val;
        if (cLimits[r] !== undefined) {
            val = cLimits[r];
        } else if (r === 'users' && comp.max_users) {
            val = comp.max_users;
        } else if (eff[r] !== undefined) {
            val = eff[r];
        } else {
            const p = availablePlans.value.find((x) => x.key === editState.plan);
            val = p?.limits?.[r] ?? null;
        }

        editState.unlimited_flags[r] = (val === null);
        editState.custom_limits[r] = val === null ? null : Number(val);
    });
}

function selectAllModulesForEdit() {
    editState.allowed_modules = coreModules.value.map((m) => m.key);
}

function deselectAllModulesForEdit() {
    editState.allowed_modules = ['pano'];
}

async function quickUpdateCompany(comp, payload) {
    try {
        const { data } = await client.patch(`/sa-core/companies/${comp.id}`, payload);
        showSuccess(data?.message || 'Firma güncellendi.');
        await fetchOverview();
    } catch (err) {
        showError(err.response?.data?.message || 'İşlem başarısız oldu.');
    }
}

async function saveAllCompanySettings(comp) {
    const limitsPayload = {};
    for (const [key, val] of Object.entries(editState.custom_limits)) {
        limitsPayload[key] = editState.unlimited_flags[key] ? null : (val !== null && val !== '' ? Number(val) : null);
    }

    const payload = {
        approval_status: editState.approval_status,
        plan: editState.plan,
        subscription_ends_at: editState.custom_date ? editState.custom_date : null,
        allowed_modules: editState.allowed_modules,
        max_users: editState.unlimited_flags.users ? null : (editState.custom_limits.users || 25),
        custom_limits: limitsPayload,
    };

    try {
        const { data } = await client.patch(`/sa-core/companies/${comp.id}`, payload);
        showSuccess(data?.message || 'Firma tüm ayarları ve limitleri başarıyla kaydedildi.');
        await fetchOverview();
        const updated = companies.value.find((c) => c.id === comp.id);
        if (updated) {
            editState.approval_status = updated.approval_status;
            editState.plan = updated.plan;
            editState.custom_date = updated.subscription_ends_at ? updated.subscription_ends_at.slice(0, 10) : '';
        }
    } catch (err) {
        showError(err.response?.data?.message || 'Güncelleme başarısız oldu.');
    }
}

async function deleteCompany(comp) {
    if (!window.confirm(`${comp.name} firmasını kalıcı olarak silmek istediğinize emin misiniz?`)) return;
    try {
        const { data } = await client.delete(`/sa-core/companies/${comp.id}`);
        showSuccess(data?.message || 'Firma silindi.');
        await fetchOverview();
    } catch (err) {
        showError(err.response?.data?.message || 'Firma silinemedi.');
    }
}

async function handleCreatePlugin() {
    try {
        const { data } = await client.post('/sa-core/plugins', pluginForm);
        showSuccess(data?.message || 'Plugin oluşturuldu.');
        pluginForm.key = '';
        pluginForm.name_tr = '';
        pluginForm.name_en = '';
        pluginForm.widget_title_tr = '';
        await fetchOverview();
    } catch (err) {
        showError(err.response?.data?.message || 'Plugin oluşturulamadı.');
    }
}

async function handleUpdateMyCredentials() {
    try {
        const { data } = await client.patch('/sa-core/credentials', myCreds);
        showSuccess(data?.message || 'Bilgileriniz güncellendi.');
        myCreds.new_password = '';
        await authStore.fetchMe();
        await fetchOverview();
    } catch (err) {
        showError(err.response?.data?.message || 'Bilgiler güncellenemedi.');
    }
}

async function handleAddSuperAdmin() {
    try {
        const { data } = await client.post('/sa-core/superadmins', newSuperAdmin);
        showSuccess(data?.message || 'Yeni SüperAdmin eklendi.');
        newSuperAdmin.name = '';
        newSuperAdmin.email = '';
        newSuperAdmin.password = '';
        await fetchOverview();
    } catch (err) {
        showError(err.response?.data?.message || 'SüperAdmin eklenemedi.');
    }
}

async function handleRevokeSuperAdmin(sa) {
    if (!window.confirm(`${sa.email} kullanıcısının SüperAdmin yetkisini kaldırmak istiyor musunuz?`)) return;
    try {
        const { data } = await client.delete(`/sa-core/superadmins/${sa.id}`);
        showSuccess(data?.message || 'Yetki kaldırıldı.');
        await fetchOverview();
    } catch (err) {
        showError(err.response?.data?.message || 'Yetki kaldırılamadı.');
    }
}

onMounted(async () => {
    if (!authStore.user) {
        await authStore.fetchMe();
    }
    if (!authStore.user?.is_superadmin) {
        router.replace('/');
        return;
    }
    myCreds.name = authStore.user.name;
    myCreds.email = authStore.user.email;
    await fetchOverview();
});
</script>
