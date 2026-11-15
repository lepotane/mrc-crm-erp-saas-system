<template>
    <div class="space-y-6 select-none max-w-6xl mx-auto pb-12">
        <!-- Top Breadcrumb & Heading -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                    <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                    <span>/</span>
                    <span class="text-white font-medium">{{ $t('Kullanıcılar, İK & Başvuru Formu') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center gap-3">
                    {{ $t('Ekip, Sınıflar & İK Başvuru Yönetimi') }}
                    <span v-if="loadingMembers || loadingInvitations" class="inline-flex">
                        <SkeletonPill width="w-20" height="h-5" />
                    </span>
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <button
                    type="button"
                    @click="showFormBuilder = !showFormBuilder"
                    class="px-4 py-2 rounded-full text-xs font-extrabold border transition-all flex items-center gap-2 cursor-pointer"
                    :class="showFormBuilder
                        ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_20px_rgba(185,255,102,0.4)] keep-white'
                        : 'bg-[#212738] text-[#B9FF66] border-[#B9FF66]/40 hover:bg-[#B9FF66]/10'"
                >
                    <SlidersHorizontal class="w-3.5 h-3.5" />
                    <span>{{ showFormBuilder ? $t('Form Kurucuyu Kapat') : $t('Başvuru Formu Kurucu') }}</span>
                </button>

                <router-link
                    to="/profil"
                    class="px-3.5 py-2 rounded-full bg-[#212738] border border-white/10 text-xs font-bold text-white hover:border-[#B9FF66]/40 transition-colors"
                >
                    {{ $t('Profilimi Düzenle') }}
                </router-link>

                <StatusPill
                    v-if="pendingJoinRequestsCount > 0"
                    color="lime"
                    :dot="true"
                >
                    {{ pendingJoinRequestsCount }} {{ $t('Yeni Başvuru') }}
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

        <!-- =========================================================================
             CANLI İŞ / KATILIM BAŞVURU FORMU KURUCU (DRAG & DROP)
             ========================================================================= -->
        <Card v-if="showFormBuilder" rounded="2xl" padding="lg" class="border-[#B9FF66]/45">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-5 border-b border-white/[0.08] mb-5">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 flex items-center justify-center text-[#B9FF66]">
                        <SlidersHorizontal class="w-5 h-5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-extrabold text-white tracking-tight">{{ $t('Firma Başvuru & İK Formu Kurucu') }}</h2>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#B9FF66] text-[#131722] keep-white">Canlı Builder</span>
                        </div>
                        <p class="text-xs text-[#94A3B8] mt-0.5">
                            {{ $t('Katıl ekranında firmanızı seçen adayların dolduracağı başvuru alanlarını sürükleyip bırakarak özelleştirin.') }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <!-- Başvuru Kabul Anahtarı -->
                    <label class="flex items-center gap-2 px-3.5 py-2 rounded-full bg-[#131722] border border-white/10 text-xs font-bold cursor-pointer">
                        <input
                            v-model="acceptingApplications"
                            type="checkbox"
                            class="w-4 h-4 accent-[#B9FF66]"
                        />
                        <span :class="acceptingApplications ? 'text-[#B9FF66]' : 'text-[#94A3B8]'">
                            {{ acceptingApplications ? $t('Başvuru Kabulü: AÇIK') : $t('Başvuru Kabulü: KAPALI') }}
                        </span>
                    </label>

                    <!-- Orijinale Dön Butonu -->
                    <button
                        type="button"
                        @click="resetFormSchemaToDefault"
                        :disabled="savingSchema"
                        class="px-3.5 py-2 rounded-full bg-[#212738] border border-white/15 hover:border-[#DC6F31] text-xs font-bold text-[#94A3B8] hover:text-white flex items-center gap-1.5 transition-colors cursor-pointer"
                    >
                        <RotateCcw class="w-3.5 h-3.5 text-[#DC6F31]" />
                        <span>{{ $t('Orijinale Dön') }}</span>
                    </button>

                    <!-- Kaydet Butonu -->
                    <button
                        type="button"
                        @click="saveFormSchema"
                        :disabled="savingSchema"
                        class="px-5 py-2 rounded-full bg-[#B9FF66] hover:bg-[#c8ff85] text-[#131722] text-xs font-extrabold shadow-[0_0_20px_rgba(185,255,102,0.3)] transition-all cursor-pointer keep-white"
                    >
                        {{ savingSchema ? $t('Kaydediliyor...') : $t('Form Tasarımını Kaydet') }}
                    </button>
                </div>
            </div>

            <!-- Yeni Özel Alan Ekleme Barı -->
            <div class="p-4 rounded-2xl bg-[#131722] border border-white/10 mb-5 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-[11px] font-bold text-[#94A3B8] mb-1">{{ $t('Yeni Alan Başlığı (TR)') }}</label>
                    <input
                        v-model="newField.label_tr"
                        type="text"
                        class="w-full px-3 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                        :placeholder="$t('Örn: Ehliyet Sınıfı, Yabancı Dil, Referans...')"
                    />
                </div>
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-[11px] font-bold text-[#94A3B8] mb-1">{{ $t('İngilizce Başlık (EN)') }}</label>
                    <input
                        v-model="newField.label_en"
                        type="text"
                        class="w-full px-3 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none focus:border-[#B9FF66]"
                        :placeholder="$t('e.g. Driving License, Portfolio URL...')"
                    />
                </div>
                <div class="w-40">
                    <label class="block text-[11px] font-bold text-[#94A3B8] mb-1">{{ $t('Alan Tipi') }}</label>
                    <select
                        v-model="newField.type"
                        class="w-full px-3 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none"
                    >
                        <option value="text">{{ $t('Kısa Metin') }}</option>
                        <option value="textarea">{{ $t('Uzun Metin / Açıklama') }}</option>
                        <option value="select">{{ $t('Çoktan Seçmeli') }}</option>
                        <option value="file">{{ $t('CV / Dosya Yükleme') }}</option>
                        <option value="date">{{ $t('Tarih Seçici') }}</option>
                    </select>
                </div>
                <div class="w-32">
                    <label class="block text-[11px] font-bold text-[#94A3B8] mb-1">{{ $t('Genişlik') }}</label>
                    <select
                        v-model="newField.width"
                        class="w-full px-3 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none"
                    >
                        <option value="half">{{ $t('1/2 Yarım') }}</option>
                        <option value="full">{{ $t('Tam Genişlik') }}</option>
                    </select>
                </div>
                <button
                    type="button"
                    @click="addCustomFormField"
                    class="px-4 py-2 rounded-xl bg-[#3D55B6] hover:bg-[#4B66D1] text-white text-xs font-extrabold flex items-center gap-1.5 cursor-pointer keep-white"
                >
                    <Plus class="w-4 h-4" />
                    <span>{{ $t('Forma Ekle') }}</span>
                </button>
            </div>

            <!-- Canlı Sürükle-Bırak (Drag & Drop) Form Blokları Tuvali -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div
                    v-for="(field, idx) in formSchema"
                    :key="field.id"
                    draggable="true"
                    @dragstart="onFieldDragStart(idx)"
                    @dragover.prevent
                    @drop="onFieldDrop(idx)"
                    class="p-4 rounded-2xl border transition-all flex flex-col justify-between gap-3 cursor-grab active:cursor-grabbing"
                    :class="[
                        field.width === 'full' ? 'sm:col-span-2' : 'sm:col-span-1',
                        field.enabled !== false
                            ? 'bg-[#131722]/90 border-white/15 hover:border-[#B9FF66]/60'
                            : 'bg-[#131722]/40 border-white/5 opacity-55'
                    ]"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <GripVertical class="w-4 h-4 text-[#94A3B8] shrink-0" />
                            <div class="min-w-0">
                                <input
                                    v-model="field.label_tr"
                                    type="text"
                                    class="bg-transparent text-xs font-extrabold text-white focus:outline-none border-b border-transparent focus:border-[#B9FF66] w-full"
                                />
                                <span class="text-[10px] text-[#94A3B8] uppercase">
                                    {{ field.type }} · {{ field.id }}
                                </span>
                            </div>
                        </div>

                        <!-- Genişlik (1/2 vs Tam) & Zorunlu & Sil Kontrolleri -->
                        <div class="flex items-center gap-1.5 shrink-0">
                            <button
                                type="button"
                                @click="field.width = field.width === 'full' ? 'half' : 'full'"
                                class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-[#212738] border border-white/10 text-[#94A3B8] hover:text-white cursor-pointer"
                                :title="$t('Genişliği Değiştir')"
                            >
                                {{ field.width === 'full' ? 'Tam (2/2)' : 'Yarım (1/2)' }}
                            </button>

                            <button
                                type="button"
                                :disabled="field.locked"
                                @click="field.required = !field.required"
                                class="px-2 py-0.5 rounded-md text-[10px] font-bold border cursor-pointer"
                                :class="field.required
                                    ? 'bg-[#B9FF66]/15 border-[#B9FF66]/40 text-[#B9FF66]'
                                    : 'bg-[#212738] border-white/10 text-[#94A3B8]'"
                            >
                                {{ field.required ? $t('Zorunlu *') : $t('İsteğe Bağlı') }}
                            </button>

                            <button
                                v-if="!field.locked"
                                type="button"
                                @click="field.enabled = field.enabled === false ? true : false"
                                class="px-2 py-0.5 rounded-md text-[10px] font-bold border cursor-pointer"
                                :class="field.enabled !== false
                                    ? 'bg-[#3E9B6F]/20 border-[#3E9B6F]/40 text-[#B9FF66]'
                                    : 'bg-[#C55050]/20 border-[#C55050]/40 text-[#FFA0A0]'"
                            >
                                {{ field.enabled !== false ? $t('Aktif') : $t('Gizli') }}
                            </button>

                            <button
                                v-if="!field.locked"
                                type="button"
                                @click="removeFormField(idx)"
                                class="p-1 rounded-md text-[#94A3B8] hover:text-[#FF5A5F] cursor-pointer"
                                :title="$t('Alanı Kaldır')"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    <!-- Select ise seçenekleri düzenleme satırı -->
                    <div v-if="field.type === 'select'" class="pt-1 border-t border-white/[0.06]">
                        <label class="block text-[10px] text-[#94A3B8] mb-1">{{ $t('Seçenekler (virgülle ayırın):') }}</label>
                        <input
                            :value="(field.options_tr || []).join(', ')"
                            @change="updateSelectOptions(field, $event.target.value)"
                            type="text"
                            class="w-full px-2.5 py-1.5 rounded-lg bg-[#212738] border border-white/10 text-[11px] text-white focus:outline-none"
                        />
                    </div>
                </div>
            </div>
        </Card>

        <div class="grid grid-cols-1 gap-6">
            <!-- 0. KART: KATIL (İŞ & PERSONEL BAŞVURULARI + CV İNDİRME) -->
            <Card rounded="2xl" padding="lg" class="border-[#B9FF66]/35">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#B9FF66]/15 border border-[#B9FF66]/40 flex items-center justify-center text-[#B9FF66]">
                            <UserCheck class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Gelen İş & Katılım Başvuruları (CV & Form Yanıtları)') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Giriş ekranındaki Katıl bölümünden firmanıza başvuran adaylar ve yükledikleri CV dosyaları') }}</p>
                        </div>
                    </div>

                    <span class="text-xs font-extrabold px-3 py-1 rounded-full bg-[#B9FF66] text-[#131722] keep-white">
                        {{ pendingJoinRequestsCount }} {{ $t('Bekleyen') }}
                    </span>
                </div>

                <div v-if="joinRequests.length === 0" class="py-8 text-center text-xs text-[#94A3B8]">
                    {{ $t('Henüz gelen bir katılım veya iş başvurusu bulunmuyor.') }}
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="req in joinRequests"
                        :key="req.id"
                        class="p-4 rounded-2xl bg-[#131722]/80 border border-white/10 flex flex-col lg:flex-row lg:items-center justify-between gap-4"
                    >
                        <div class="space-y-1.5 flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-black text-white">{{ req.full_name }}</span>
                                <span class="text-xs text-[#94A3B8]">({{ req.email }})</span>
                                <Badge :color="req.status === 'pending' ? 'orange' : req.status === 'approved' ? 'lime' : 'coral'">
                                    {{ req.status === 'pending' ? $t('Onay Bekliyor') : req.status === 'approved' ? $t('Kabul Edildi') : $t('Reddedildi') }}
                                </Badge>
                                <button
                                    v-if="req.has_cv"
                                    type="button"
                                    @click="downloadApplicantCv(req)"
                                    class="px-2.5 py-1 rounded-lg bg-[#3D55B6]/25 border border-[#3D55B6]/50 text-[#93C5FD] hover:bg-[#3D55B6]/40 text-[11px] font-extrabold flex items-center gap-1.5 transition-colors cursor-pointer"
                                >
                                    <FileDown class="w-3.5 h-3.5" />
                                    <span>{{ $t('CV İndir:') }} {{ req.cv_original_name || 'CV.pdf' }}</span>
                                </button>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 text-xs text-[#94A3B8]">
                                <span v-if="req.phone">{{ $t('Tel:') }} {{ req.phone }}</span>
                                <span v-if="req.address">{{ $t('Adres:') }} {{ req.address }}</span>
                                <span>{{ $t('Başvurulan Pozisyon:') }} <strong class="text-[#B9FF66]">{{ req.requested_class || 'Personel' }}</strong></span>
                            </div>

                            <!-- Adayın Doldurduğu Özel Form Soruları -->
                            <div
                                v-if="req.custom_answers && Object.keys(req.custom_answers).length > 0"
                                class="flex flex-wrap gap-2 pt-1"
                            >
                                <span
                                    v-for="(val, label) in req.custom_answers"
                                    :key="label"
                                    class="px-2.5 py-1 rounded-lg bg-[#212738] border border-white/[0.07] text-[11px] text-[#CBD5E1]"
                                >
                                    <strong class="text-[#94A3B8]">{{ label }}:</strong> {{ val || '—' }}
                                </span>
                            </div>

                            <p v-if="req.message" class="text-xs text-[#CBD5E1] bg-[#212738]/60 p-2.5 rounded-xl border border-white/[0.05] mt-1">
                                <strong class="text-[#94A3B8]">{{ $t('Ön Yazı:') }}</strong> {{ req.message }}
                            </p>
                        </div>

                        <div v-if="req.status === 'pending'" class="flex flex-wrap items-center gap-2.5 shrink-0">
                            <select
                                v-model="req.selected_class"
                                class="px-3 py-2 rounded-xl bg-[#212738] border border-white/10 text-xs font-bold text-white focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"
                            >
                                <option value="Personel">{{ $t('Standart Personel') }}</option>
                                <option value="Satış Temsilcisi">{{ $t('Satış Temsilcisi') }}</option>
                                <option value="Kıdemli Muhasebe Uzmanı">{{ $t('Muhasebe Uzmanı') }}</option>
                                <option value="Depo Sorumlusu">{{ $t('Depo & Stok Sorumlusu') }}</option>
                                <option value="Yönetici">{{ $t('Yönetici / Admin') }}</option>
                            </select>

                            <button
                                type="button"
                                @click="approveJoinRequest(req)"
                                :disabled="processingRequestId === req.id"
                                class="px-4 py-2 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-black hover:bg-[#c8ff85] transition-all cursor-pointer keep-white"
                            >
                                {{ $t('Kabul Et & Personel Ekle') }}
                            </button>

                            <button
                                type="button"
                                @click="rejectJoinRequest(req)"
                                :disabled="processingRequestId === req.id"
                                class="px-3.5 py-2 rounded-full bg-[#C55050]/20 border border-[#C55050]/40 text-[#FFA0A0] text-xs font-bold hover:bg-[#C55050]/30 transition-all cursor-pointer"
                            >
                                {{ $t('Reddet') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- 1. KART: MEVCUT EKİP & KULLANICI SINIFLARI -->
            <Card rounded="2xl" padding="lg">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#B9FF66]">
                            <Users class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Mevcut Ekip Üyeleri & Sınıfları') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Şirket hesabına kayıtlı aktif kullanıcılar ve sınıf tanımları') }}</p>
                        </div>
                    </div>

                    <span class="text-xs font-semibold px-3 py-1 rounded-full bg-[#131722] border border-white/10 text-white">
                        {{ members.length }} {{ $t('Üye') }}
                    </span>
                </div>

                <!-- Members List -->
                <div v-if="loadingMembers" class="space-y-3">
                    <div v-for="i in 3" :key="i" class="flex items-center justify-between p-3.5 bg-[#131722]/50 rounded-xl border border-white/5 animate-pulse">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/10"></div>
                            <div class="space-y-1.5">
                                <div class="w-28 h-4 bg-white/10 rounded-full"></div>
                                <div class="w-36 h-3 bg-white/5 rounded-full"></div>
                            </div>
                        </div>
                        <div class="w-16 h-6 bg-white/10 rounded-full"></div>
                    </div>
                </div>

                <div v-else-if="members.length === 0" class="text-center py-8 text-[#94A3B8] text-sm">
                    {{ $t('Henüz kayıtlı ekip üyesi bulunmuyor.') }}
                </div>

                <div v-else class="divide-y divide-white/[0.06]">
                    <div
                        v-for="member in members"
                        :key="member.id"
                        class="py-3.5 first:pt-0 last:pb-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 hover:bg-white/[0.02] px-2 rounded-xl transition-colors"
                    >
                        <div class="flex items-center gap-3.5">
                            <div
                                class="w-10 h-10 rounded-full bg-[#131722] border-2 border-[#B9FF66]/60 text-[#B9FF66] font-bold text-xs flex items-center justify-center shadow-xs shrink-0 select-none"
                            >
                                {{ getInitials(member.name) }}
                            </div>

                            <div>
                                <div class="font-bold text-white text-sm tracking-tight flex items-center gap-2">
                                    {{ member.name }}
                                    <span class="px-2 py-0.5 rounded-full bg-[#B9FF66]/10 border border-[#B9FF66]/30 text-[#B9FF66] text-[10px] font-extrabold">
                                        {{ member.user_class || 'Personel' }}
                                    </span>
                                </div>
                                <div class="text-xs text-[#94A3B8]">
                                    {{ member.email }}
                                    <span v-if="member.phone" class="ml-2">· {{ member.phone }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2.5 pl-13 sm:pl-0">
                            <select
                                :value="member.user_class || 'Personel'"
                                @change="updateMemberClass(member, $event.target.value)"
                                class="px-3 py-1.5 rounded-xl bg-[#131722] border border-white/10 text-xs font-bold text-white focus:outline-none focus:ring-1 focus:ring-[#B9FF66] cursor-pointer"
                                :title="$t('Kullanıcı Sınıfını Değiştir')"
                            >
                                <option value="Yönetici">{{ $t('Sınıf: Yönetici') }}</option>
                                <option value="Kıdemli Muhasebe Uzmanı">{{ $t('Sınıf: Muhasebe Uzmanı') }}</option>
                                <option value="Satış Temsilcisi">{{ $t('Sınıf: Satış Temsilcisi') }}</option>
                                <option value="Depo Sorumlusu">{{ $t('Sınıf: Depo Sorumlusu') }}</option>
                                <option value="Personel">{{ $t('Sınıf: Personel') }}</option>
                            </select>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <template v-if="member.roles && member.roles.length">
                                    <Badge
                                        v-for="role in member.roles"
                                        :key="role"
                                        :color="getRoleBadgeColor(role)"
                                    >
                                        {{ $t(role) }}
                                    </Badge>
                                </template>
                                <Badge v-else color="neutral">
                                    {{ $t('Personel') }}
                                </Badge>
                            </div>

                            <span class="text-xs text-[#94A3B8] whitespace-nowrap hidden md:inline">
                                {{ formatDate(member.joined_at) }}
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- 2. KART: EKİBE DAVET ET & BEKLEYEN DAVETLER -->
            <Card rounded="2xl" padding="lg">
                <div class="flex items-center justify-between pb-4 border-b border-white/[0.08] mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#131722] border border-white/10 flex items-center justify-center text-[#459BBE]">
                            <UserPlus class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white tracking-tight">{{ $t('Yeni Üye Davet Et') }}</h2>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Ekip arkadaşlarınızı e-posta ile doğrudan davet edin') }}</p>
                        </div>
                    </div>

                    <StatusPill
                        v-if="pendingInvitationsCount > 0"
                        color="orange"
                        :dot="true"
                    >
                        {{ pendingInvitationsCount }} {{ $t('Bekliyor') }}
                    </StatusPill>
                </div>

                <!-- Davet Formu -->
                <form @submit.prevent="sendInvitation" class="mb-8 p-4 bg-[#131722] rounded-2xl border border-white/10 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                        <div class="sm:col-span-7">
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('E-posta Adresi') }} <span class="text-[#B9FF66]">*</span>
                            </label>
                            <input
                                v-model="inviteForm.email"
                                type="email"
                                required
                                :placeholder="$t('davet@ornek.com')"
                                class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all"
                                :class="{ 'border-[#C55050] focus:ring-[#C55050]': inviteErrors.email }"
                            />
                        </div>

                        <div class="sm:col-span-3">
                            <label class="block text-xs font-semibold text-[#94A3B8] tracking-wide mb-1.5">
                                {{ $t('Rol') }}
                            </label>
                            <select
                                v-model="inviteForm.role"
                                class="w-full px-4 py-2.5 bg-[#212738] border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66] transition-all cursor-pointer"
                            >
                                <option value="Personel">{{ $t('Personel') }}</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <PillButton
                                type="submit"
                                variant="primary"
                                size="md"
                                :loading="sendingInvite"
                                class="w-full"
                            >
                                {{ $t('Davet Et') }}
                            </PillButton>
                        </div>
                    </div>

                    <p v-if="inviteErrors.email" class="text-xs text-[#FFA0A0] flex items-center gap-1">
                        <AlertCircle class="w-3.5 h-3.5" />
                        {{ Array.isArray(inviteErrors.email) ? inviteErrors.email[0] : inviteErrors.email }}
                    </p>
                </form>

                <!-- Davetler Listesi -->
                <div>
                    <h3 class="text-sm font-bold text-white tracking-wide uppercase mb-3 flex items-center gap-2">
                        {{ $t('Davet Geçmişi') }}
                        <span class="text-xs text-[#94A3B8] font-normal">({{ invitations.length }})</span>
                    </h3>

                    <div v-if="loadingInvitations" class="space-y-2">
                        <div v-for="i in 2" :key="i" class="h-12 bg-[#131722]/50 rounded-xl border border-white/5 animate-pulse"></div>
                    </div>

                    <div v-else-if="invitations.length === 0" class="text-center py-6 text-[#94A3B8] text-xs">
                        {{ $t('Henüz gönderilen bir davet bulunmuyor.') }}
                    </div>

                    <div v-else class="divide-y divide-white/[0.06] border border-white/[0.08] rounded-xl overflow-hidden bg-[#131722]/40">
                        <div
                            v-for="inv in invitations"
                            :key="inv.id"
                            class="p-3.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 hover:bg-white/[0.02] transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-[#212738] border border-white/10 flex items-center justify-center text-[#94A3B8] text-xs shrink-0">
                                    <Mail class="w-4 h-4 text-[#94A3B8]" />
                                </div>

                                <div>
                                    <div class="text-sm font-semibold text-white">
                                        {{ inv.email }}
                                    </div>
                                    <div class="text-[11px] text-[#94A3B8] flex items-center gap-2 mt-0.5">
                                        <span>{{ $t('Gönderildi') }}: {{ formatDate(inv.created_at) }}</span>
                                        <span v-if="inv.expires_at">· {{ $t('Son') }}: {{ formatDate(inv.expires_at) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between sm:justify-end gap-3 pl-11 sm:pl-0">
                                <Badge :color="getRoleBadgeColor(inv.role)">
                                    {{ $t(inv.role) }}
                                </Badge>

                                <StatusPill :color="getStatusColor(inv.status)" :dot="inv.status === 'bekliyor'">
                                    {{ $t(inv.status || 'bekliyor') }}
                                </StatusPill>

                                <button
                                    v-if="inv.status === 'bekliyor' || !inv.status"
                                    type="button"
                                    @click="openCancelModal(inv)"
                                    class="p-1.5 rounded-lg text-[#FFA0A0] hover:bg-[#C55050]/20 border border-transparent hover:border-[#C55050]/40 transition-colors cursor-pointer"
                                    :title="$t('Daveti İptal Et')"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>
        </div>

        <!-- İPTAL ONAY MODALI -->
        <div
            v-if="cancellingInvite"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-xs"
        >
            <div class="bg-[#212738] border border-white/15 rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-[0_20px_50px_rgba(0,0,0,0.8)] space-y-5">
                <div class="flex items-center gap-3 text-[#FFA0A0]">
                    <div class="w-10 h-10 rounded-2xl bg-[#C55050]/20 border border-[#C55050]/30 flex items-center justify-center">
                        <AlertTriangle class="w-5 h-5 text-[#FFA0A0]" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $t('Davet İptal Edilsin mi?') }}</h3>
                        <p class="text-xs text-[#94A3B8]">{{ $t('Bu işlem geri alınamaz.') }}</p>
                    </div>
                </div>

                <p class="text-xs text-[#94A3B8] leading-relaxed bg-[#131722] p-3 rounded-xl border border-white/5">
                    <strong class="text-white">{{ cancellingInvite.email }}</strong> {{ $t('adresine gönderilen davet bağlantısı geçersiz kılınacaktır.') }}
                </p>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <PillButton
                        type="button"
                        variant="ghost"
                        size="md"
                        :disabled="cancellingAction"
                        @click="closeCancelModal"
                    >
                        {{ $t('Vazgeç') }}
                    </PillButton>
                    <PillButton
                        type="button"
                        variant="danger"
                        size="md"
                        :loading="cancellingAction"
                        @click="confirmCancelInvitation"
                    >
                        {{ $t('Evet, İptal Et') }}
                    </PillButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import Badge from '../components/ui/Badge.vue';
import SkeletonPill from '../components/ui/SkeletonPill.vue';
import client from '../api/client';
import { t } from '../i18n';
import { formatDate } from '../utils/format';
import {
    Users,
    UserPlus,
    UserCheck,
    Mail,
    Check,
    AlertCircle,
    AlertTriangle,
    Trash2,
    SlidersHorizontal,
    GripVertical,
    RotateCcw,
    Plus,
    FileDown,
} from 'lucide-vue-next';

const loadingMembers = ref(true);
const loadingInvitations = ref(true);
const sendingInvite = ref(false);
const cancellingAction = ref(false);
const processingRequestId = ref(null);

const members = ref([]);
const invitations = ref([]);
const joinRequests = ref([]);

// Başvuru Formu Kurucu State
const showFormBuilder = ref(false);
const acceptingApplications = ref(true);
const formSchema = ref([]);
const savingSchema = ref(false);
const draggedFieldIndex = ref(null);
const newField = reactive({
    label_tr: '',
    label_en: '',
    type: 'text',
    width: 'half',
});

const inviteForm = reactive({
    email: '',
    role: 'Personel',
});
const inviteErrors = ref({});
const cancellingInvite = ref(null);

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

const pendingInvitationsCount = computed(() => {
    return invitations.value.filter(
        (i) => !i.status || i.status.toLowerCase() === 'bekliyor'
    ).length;
});

const pendingJoinRequestsCount = computed(() => {
    return joinRequests.value.filter((r) => r.status === 'pending').length;
});

function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) {
        return parts[0].substring(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function getRoleBadgeColor(role) {
    if (!role) return 'neutral';
    const r = role.toLowerCase();
    if (r.includes('admin') || r.includes('yönetici')) return 'cobalt';
    if (r.includes('personel') || r.includes('üye') || r.includes('staff')) return 'cyan';
    return 'neutral';
}

function getStatusColor(status) {
    if (!status) return 'orange';
    const s = status.toLowerCase();
    if (s.includes('kabul')) return 'lime';
    if (s.includes('geçti') || s.includes('iptal') || s.includes('red')) return 'coral';
    return 'orange';
}

async function fetchFormSchema() {
    try {
        const { data } = await client.get('/join-requests/form-schema');
        acceptingApplications.value = data?.accepting_applications !== false;
        formSchema.value = Array.isArray(data?.form_schema) ? data.form_schema : [];
    } catch {
        // ignore
    }
}

function onFieldDragStart(index) {
    draggedFieldIndex.value = index;
}

function onFieldDrop(targetIndex) {
    const from = draggedFieldIndex.value;
    draggedFieldIndex.value = null;
    if (from === null || from === targetIndex) return;
    const copy = [...formSchema.value];
    const [moved] = copy.splice(from, 1);
    copy.splice(targetIndex, 0, moved);
    formSchema.value = copy;
}

function addCustomFormField() {
    const tr = newField.label_tr.trim();
    if (!tr) return;
    const en = newField.label_en.trim() || tr;
    const id = 'custom_' + Date.now();
    formSchema.value.push({
        id,
        type: newField.type,
        label_tr: tr,
        label_en: en,
        placeholder_tr: tr,
        placeholder_en: en,
        options_tr: newField.type === 'select' ? ['Seçenek 1', 'Seçenek 2'] : undefined,
        options_en: newField.type === 'select' ? ['Option 1', 'Option 2'] : undefined,
        width: newField.width,
        required: false,
        enabled: true,
        locked: false,
    });
    newField.label_tr = '';
    newField.label_en = '';
}

function removeFormField(index) {
    if (formSchema.value[index]?.locked) return;
    formSchema.value.splice(index, 1);
}

function updateSelectOptions(field, rawValue) {
    const parts = String(rawValue || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    field.options_tr = parts;
    field.options_en = parts;
}

async function saveFormSchema() {
    savingSchema.value = true;
    try {
        const { data } = await client.put('/join-requests/form-schema', {
            accepting_applications: acceptingApplications.value,
            form_schema: formSchema.value,
        });
        formSchema.value = data.form_schema || formSchema.value;
        triggerSuccess(data?.message || t('Başvuru formu şeması kaydedildi.'));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Başvuru formu kaydedilemedi.'));
    } finally {
        savingSchema.value = false;
    }
}

async function resetFormSchemaToDefault() {
    savingSchema.value = true;
    try {
        const { data } = await client.put('/join-requests/form-schema', {
            reset_default: true,
            accepting_applications: true,
        });
        acceptingApplications.value = true;
        formSchema.value = data.form_schema || [];
        triggerSuccess(t('Başvuru formu orijinal fabrika ayarlarına döndürüldü.'));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Sıfırlama başarısız oldu.'));
    } finally {
        savingSchema.value = false;
    }
}

async function downloadApplicantCv(req) {
    try {
        const response = await client.get(`/join-requests/${req.id}/cv`, {
            responseType: 'blob',
        });
        const blobUrl = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = blobUrl;
        a.download = req.cv_original_name || `basvuru-cv-${req.id}.pdf`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);
    } catch {
        triggerError(t('CV dosyası indirilemedi.'));
    }
}

async function fetchMembers() {
    loadingMembers.value = true;
    try {
        const res = await client.get('/company/members');
        members.value = (res.data?.members || []).filter(
            (m) => !m.is_superadmin && !(m.roles || []).includes('SuperAdmin')
        );
    } catch (err) {
        console.error('Fetch members error:', err);
    } finally {
        loadingMembers.value = false;
    }
}

async function fetchJoinRequests() {
    try {
        const res = await client.get('/join-requests');
        joinRequests.value = (res.data?.join_requests || []).map((r) => ({
            ...r,
            selected_class: r.requested_class || 'Personel',
        }));
    } catch {
        joinRequests.value = [];
    }
}

async function approveJoinRequest(req) {
    processingRequestId.value = req.id;
    try {
        const { data } = await client.post(`/join-requests/${req.id}/approve`, {
            user_class: req.selected_class || 'Personel',
            role: req.selected_class === 'Yönetici' ? 'Admin' : 'Personel',
        });
        req.status = 'approved';
        triggerSuccess(data?.message || t('Başvuru onaylandı ve personel eklendi.'));
        await fetchMembers();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Başvuru onaylanamadı.'));
    } finally {
        processingRequestId.value = null;
    }
}

async function rejectJoinRequest(req) {
    processingRequestId.value = req.id;
    try {
        const { data } = await client.post(`/join-requests/${req.id}/reject`);
        req.status = 'rejected';
        triggerSuccess(data?.message || t('Başvuru reddedildi.'));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Başvuru reddedilemedi.'));
    } finally {
        processingRequestId.value = null;
    }
}

async function updateMemberClass(member, newClass) {
    try {
        const { data } = await client.patch(`/company/members/${member.id}`, {
            user_class: newClass,
        });
        member.user_class = data?.member?.user_class || newClass;
        triggerSuccess(t(':name için kullanıcı sınıfı güncellendi.', { name: member.name }));
    } catch (err) {
        triggerError(err.response?.data?.message || t('Kullanıcı sınıfı güncellenemedi.'));
    }
}

async function fetchInvitations() {
    loadingInvitations.value = true;
    try {
        const res = await client.get('/invitations');
        invitations.value = res.data?.invitations || [];
    } catch (err) {
        console.error('Fetch invitations error:', err);
    } finally {
        loadingInvitations.value = false;
    }
}

async function sendInvitation() {
    sendingInvite.value = true;
    inviteErrors.value = {};
    globalSuccess.value = '';
    globalError.value = '';

    try {
        const res = await client.post('/invitations', {
            email: inviteForm.email,
            role: inviteForm.role,
        });

        const newInv = res.data?.invitation;
        if (newInv) {
            invitations.value.unshift(newInv);
        } else {
            await fetchInvitations();
        }

        inviteForm.email = '';
        triggerSuccess(t('Davet başarıyla gönderildi.'));
    } catch (err) {
        if (err.response?.status === 422) {
            inviteErrors.value = err.response.data?.errors || {};
            triggerError(err.response.data?.message || t('Lütfen davet bilgilerini kontrol edin.'));
        } else {
            triggerError(err.response?.data?.message || t('Davet gönderilemedi.'));
        }
    } finally {
        sendingInvite.value = false;
    }
}

function openCancelModal(inv) {
    cancellingInvite.value = inv;
}

function closeCancelModal() {
    cancellingInvite.value = null;
}

async function confirmCancelInvitation() {
    if (!cancellingInvite.value) return;
    const invId = cancellingInvite.value.id;
    cancellingAction.value = true;

    try {
        await client.delete(`/invitations/${invId}`);
        invitations.value = invitations.value.filter((i) => i.id !== invId);
        triggerSuccess(t('Davet başarıyla iptal edildi.'));
        closeCancelModal();
    } catch (err) {
        triggerError(err.response?.data?.message || t('Davet iptal edilirken bir hata oluştu.'));
    } finally {
        cancellingAction.value = false;
    }
}

onMounted(async () => {
    await Promise.allSettled([fetchMembers(), fetchInvitations(), fetchJoinRequests(), fetchFormSchema()]);
});
</script>
