<!--
    MRC Dashboard — Canlı Görsel Sürükle-Boyutlandır Kurucu:
    - Mobil Görünüm (< sm): Referans mobil görünüm (Projects + Yuvarlak Neon 'Tümü' + #Kategori hapları + Tam genişlik '+' barı + Renkli dikey kartlar)
    - Masaüstü Görünüm: Bento 12-sütun ızgarası (Satış & Siparişler [8], Takvim [4], Bu Yılki Finans [4], Yıllık Kâr Analizi [8], Döviz/Stok/İşlemler [4-4-4])
    - Canlı Düzenleme Modu (editing = true): Ekrandaki her kart doğrudan fareyle tutulup sağa/sola/yukarı/aşağı sürüklenebilir, genişliği (1/3, 1/2, 2/3, Tam) canlı küçültülüp büyütülebilir, gizlenebilir veya "Orijinale Dön" ile sıfırlanabilir.
-->
<template>
    <div class="space-y-6 select-none max-w-[112rem] mx-auto pb-12">
        <!-- 1. ÜST BÖLGE: Başlık + Canlı Düzenleme Butonu + Sağda KPI & Dolar (USD) + Euro (EUR) Canlı Kur Kapsülleri -->
        <div class="flex flex-col 2xl:flex-row 2xl:items-center 2xl:justify-between gap-4 pt-1">
            <!-- Sol: Dashboard Başlığı & Canlı Görsel Düzenleyici Butonu -->
            <div class="flex flex-wrap items-center justify-between sm:justify-start gap-3">
                <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight">
                    {{ $t('Dashboard') }}
                </h1>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="editing = !editing"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-xs font-extrabold transition-all cursor-pointer border"
                        :class="editing
                            ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_20px_rgba(185,255,102,0.45)]'
                            : 'bg-[#212738] text-[#94A3B8] hover:text-white border-white/10 hover:border-[#B9FF66]/40'"
                        :title="editing ? $t('Canlı Düzenleyiciyi Kapat') : $t('Canlı Görsel Düzenleyiciyi Aç (Sürükle & Boyutlandır)')"
                    >
                        <Settings2 class="w-3.5 h-3.5" />
                        <span>{{ editing ? $t('Canlı Düzenlemeyi Bitir') : $t('Canlı Düzenle') }}</span>
                        <span
                            v-if="hiddenWidgetCount > 0 && !editing"
                            class="px-1.5 py-0.2 rounded-full text-[10px] font-black bg-[#DC6F31] text-white"
                        >
                            {{ hiddenWidgetCount }} {{ $t('gizli') }}
                        </span>
                    </button>

                    <button
                        v-if="editing || isLayoutCustomized"
                        type="button"
                        @click="resetToOriginalLayout"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full text-xs font-bold bg-amber-500/15 border border-amber-500/40 text-amber-300 hover:bg-amber-500/25 transition-all cursor-pointer"
                        :title="$t('Tüm kart boyutlarını ve sıralamayı orijinal tasarıma döndür')"
                    >
                        <RotateCcw class="w-3.5 h-3.5" />
                        <span>{{ $t('Orijinale Dön') }}</span>
                    </button>
                </div>
            </div>

            <!-- Sağ: Özet KPI Kapsülleri + Dolar (USD) & Euro (EUR) Üst Döviz Kapsülleri -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <!-- KPI 1: Toplam Bütçe / Ciro (Referans: $ 21,339 +14% week) -->
                <div
                    v-if="isWidgetVisible('kpi')"
                    class="flex items-center gap-3 px-4 py-2.5 rounded-2xl bg-[#212738]/90 border border-white/[0.07] shadow-[0_4px_20px_rgba(0,0,0,0.25)] card-3d-interactive"
                >
                    <div class="w-10 h-10 rounded-full bg-[#191E2C] border border-white/10 flex items-center justify-center text-[#94A3B8] shrink-0">
                        <Wallet class="w-4 h-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-lg sm:text-xl font-black text-white tracking-tight truncate">{{ topCiroTutari }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-[#4E956A]/25 text-[#B9FF66] border border-[#B9FF66]/25 shrink-0">
                                +14% {{ $t('hafta') }}
                            </span>
                        </div>
                        <p class="text-[11px] font-medium text-[#64748B] truncate">{{ $t('Toplam proje & satış hacmi') }}</p>
                    </div>
                </div>

                <!-- KPI 2: Tamamlanan İşlemler (Referans: + 21,339 +178 today) -->
                <div
                    v-if="isWidgetVisible('kpi')"
                    class="flex items-center gap-3 px-4 py-2.5 rounded-2xl bg-[#212738]/90 border border-white/[0.07] shadow-[0_4px_20px_rgba(0,0,0,0.25)] card-3d-interactive"
                >
                    <div class="w-10 h-10 rounded-full bg-[#191E2C] border border-white/10 flex items-center justify-center text-[#94A3B8] shrink-0">
                        <CheckCircle2 class="w-4 h-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-lg sm:text-xl font-black text-white tracking-tight truncate">+ {{ topBelgeSayisi }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-[#4E956A]/25 text-[#B9FF66] border border-[#B9FF66]/25 shrink-0">
                                +24 {{ $t('bugün') }}
                            </span>
                        </div>
                        <p class="text-[11px] font-medium text-[#64748B] truncate">{{ $t('Tamamlanan işlem & belge') }}</p>
                    </div>
                </div>

                <!-- KPI 3: DOLAR (USD / TRY) Canlı Kur Kapsülü -->
                <div
                    v-if="isWidgetVisible('fx_rates')"
                    class="flex items-center justify-between gap-2.5 px-4 py-2.5 rounded-2xl bg-[#212738]/90 border border-white/[0.07] shadow-[0_4px_20px_rgba(0,0,0,0.25)] card-3d-interactive"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-full bg-[#B9FF66]/15 border border-[#B9FF66]/30 flex items-center justify-center text-[#B9FF66] font-black text-sm shrink-0">
                            $
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-lg sm:text-xl font-black text-white tracking-tight">₺{{ formatKur(usdRate) }}</span>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-extrabold bg-[#4E956A]/25 text-[#B9FF66] border border-[#B9FF66]/25">
                                    USD
                                </span>
                            </div>
                            <p class="text-[11px] font-medium text-[#64748B] truncate">{{ $t('Amerikan Doları · TCMB') }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="refreshRates"
                        :disabled="refreshingRates"
                        class="w-7 h-7 rounded-full bg-white/5 hover:bg-white/15 text-[#94A3B8] hover:text-white flex items-center justify-center transition-colors cursor-pointer shrink-0"
                        :title="$t('TCMB Kurlarını Güncelle')"
                    >
                        <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin text-[#B9FF66]': refreshingRates }" />
                    </button>
                </div>

                <!-- KPI 4: EURO (EUR / TRY) Canlı Kur Kapsülü -->
                <div
                    v-if="isWidgetVisible('fx_rates')"
                    class="flex items-center justify-between gap-2.5 px-4 py-2.5 rounded-2xl bg-[#212738]/90 border border-white/[0.07] shadow-[0_4px_20px_rgba(0,0,0,0.25)] card-3d-interactive"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-full bg-[#459BBE]/20 border border-[#459BBE]/40 flex items-center justify-center text-[#459BBE] font-black text-sm shrink-0">
                            €
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-lg sm:text-xl font-black text-white tracking-tight">₺{{ formatKur(eurRate) }}</span>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-extrabold bg-[#459BBE]/20 text-[#7DD3FC] border border-[#459BBE]/30">
                                    EUR
                                </span>
                            </div>
                            <p class="text-[11px] font-medium text-[#64748B] truncate">{{ $t('Euro Satış · GBP') }}: ₺{{ formatKur(gbpRate) }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="toggleWidget('fx_rates')"
                        class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer shrink-0"
                        :title="$t('Kur Barını Gizle')"
                    >
                        <X class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Üst hata şeridi -->
        <div
            v-if="errorMsg"
            class="flex items-center gap-3 p-4 rounded-2xl bg-[#C55050]/15 border border-[#C55050]/40 text-[#FFA0A0]"
        >
            <AlertCircle class="w-5 h-5 shrink-0" />
            <span class="text-sm font-semibold">{{ errorMsg }}</span>
        </div>

        <!-- CANLI DÜZENLEME FLOATING DOCK & WIDGET YÖNETİM PANELİ (editing === true) -->
        <Card v-if="editing" rounded="3xl" padding="md" class="border-2 border-[#B9FF66]/60 bg-[#212738]/95 shadow-[0_20px_60px_rgba(0,0,0,0.65)] sticky top-2 z-40 backdrop-blur-md">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-white/[0.08]">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-2xl bg-[#B9FF66] text-[#131722] flex items-center justify-center font-black">
                        <Move class="w-4 h-4" />
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-white flex items-center gap-2">
                            <span>{{ $t('Canlı Görsel Dashboard Kurucu') }}</span>
                            <span class="px-2 py-0.5 rounded-full bg-[#B9FF66]/20 text-[#B9FF66] text-[10px] font-extrabold">{{ $t('Canlı Sürükle & Boyutlandır Aktif') }}</span>
                        </h3>
                        <p class="text-xs text-[#94A3B8]">
                            {{ $t('Aşağıdaki kartları doğrudan fareyle tutup sürükleyerek yerini değiştirin, üstlerindeki 1/3 · 1/2 · 2/3 · Tam butonlarıyla genişliklerini ayarlayın.') }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-[#94A3B8] uppercase tracking-wider">{{ $t('Kapsam') }}:</span>
                    <button
                        v-for="s in scopes"
                        :key="s.value"
                        type="button"
                        @click="editScope = s.value"
                        class="px-3 py-1 rounded-full text-xs font-semibold transition-all cursor-pointer"
                        :class="editScope === s.value
                            ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_12px_rgba(185,255,102,0.35)]'
                            : 'text-[#94A3B8] hover:text-white bg-[#131722] border border-white/10'"
                    >
                        {{ $t(s.key) }}
                    </button>
                    <button
                        type="button"
                        @click="resetToOriginalLayout"
                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold text-amber-300 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 transition-colors cursor-pointer"
                    >
                        <RotateCcw class="w-3 h-3" />
                        <span>{{ $t('Orijinale Dön') }}</span>
                    </button>
                    <PillButton size="sm" :loading="saving" @click="saveLayout">{{ $t('Yerleşimi Kaydet') }}</PillButton>
                    <button type="button" @click="editing = false" class="px-2.5 py-1 text-xs text-[#94A3B8] hover:text-white cursor-pointer">
                        ✕ {{ $t('Kapat') }}
                    </button>
                </div>
            </div>

            <!-- Hızlı Widget Aç/Kapat & Boyut Özeti Çubuğu -->
            <div class="flex flex-wrap items-center gap-2 pt-3">
                <button
                    v-for="item in widgetToggleDefs"
                    :key="item.id"
                    type="button"
                    @click="toggleWidget(item.id)"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-xs font-bold transition-all cursor-pointer"
                    :class="isWidgetVisible(item.id)
                        ? 'bg-[#131722] border-[#B9FF66]/50 text-white'
                        : 'bg-[#131722]/40 border-white/10 text-[#64748B] hover:text-white'"
                >
                    <span class="w-2 h-2 rounded-full" :class="isWidgetVisible(item.id) ? 'bg-[#B9FF66]' : 'bg-[#64748B]'"></span>
                    <span>{{ $t(item.title) }}</span>
                    <span class="text-[10px] px-1.5 py-0.2 rounded bg-white/10 text-[#B9FF66]">
                        {{ isWidgetVisible(item.id) ? spanLabel(getWidgetSpan(item.id)) : $t('Gizli (+Ekle)') }}
                    </span>
                </button>
            </div>

            <div v-if="saveError || saveOk" class="pt-2">
                <p v-if="saveError" class="text-xs font-semibold text-[#FFA0A0]">{{ saveError }}</p>
                <p v-if="saveOk" class="text-xs font-semibold text-[#B9FF66]">{{ $t('Yerleşim ve boyutlar kaydedildi.') }}</p>
            </div>
        </Card>

        <!-- Yükleme İskeleti -->
        <div v-if="loading" class="grid grid-cols-1 xl:grid-cols-12 gap-6">
            <div class="xl:col-span-8 h-80 bg-[#212738]/50 rounded-[28px] border border-white/5 animate-pulse"></div>
            <div class="xl:col-span-4 h-80 bg-[#212738]/50 rounded-[28px] border border-white/5 animate-pulse"></div>
            <div class="xl:col-span-4 h-80 bg-[#212738]/50 rounded-[28px] border border-white/5 animate-pulse"></div>
            <div class="xl:col-span-8 h-80 bg-[#212738]/50 rounded-[28px] border border-white/5 animate-pulse"></div>
        </div>

        <!-- 2. CANLI SÜRÜKLE-BOYUTLANDIR BENTO GRID (12 SÜTUNLU CANLI IZGARA) -->
        <div v-else class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-stretch">
            <template v-for="wId in orderedBentoIds" :key="wId">
                <!-- ==================== BENTO: SATIŞ & SİPARİŞLER (PROJECTS) ==================== -->
                <div
                    v-if="wId === 'satis_siparisler' && isWidgetVisible('satis_siparisler')"
                    :class="[getColSpanClass('satis_siparisler'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'satis_siparisler')"
                    @dragover.prevent="onWidgetDragOver($event, 'satis_siparisler')"
                    @drop="onWidgetDrop($event, 'satis_siparisler')"
                    class="bg-[#212738] rounded-[28px] p-4 sm:p-6 border border-white/[0.07] shadow-[0_14px_34px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <!-- Canlı Düzenleme Üst Kontrol Barı (Sadece editing modunda görünür) -->
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="satis_siparisler"
                        title-key="Sürükle & Taşı: Satış & Siparişler"
                        :span="getWidgetSpan('satis_siparisler')"
                        @set-span="setWidgetSpan('satis_siparisler', $event)"
                        @toggle="toggleWidget('satis_siparisler')"
                    />

                    <!-- Başlık & İşlevsel Aksiyon Butonları -->
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                        <div class="flex items-center gap-1.5">
                            <h2 class="text-2xl sm:text-2xl font-extrabold text-white tracking-tight">
                                {{ $t('Satış & Siparişler') }}
                            </h2>
                            <span class="text-xs font-bold text-[#64748B] -mt-2 hidden sm:inline">{ {{ filteredProjectCards.length }} }</span>
                        </div>

                        <div class="hidden sm:flex items-center gap-2 relative">
                            <button
                                type="button"
                                @click="projectMenuOpen = !projectMenuOpen"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-white/10 text-[#94A3B8] hover:text-white flex items-center justify-center transition-colors cursor-pointer border border-white/[0.06]"
                                :title="$t('Seçenekler')"
                            >
                                <MoreHorizontal class="w-4 h-4" />
                            </button>

                            <div
                                v-if="projectMenuOpen"
                                class="absolute right-16 top-10 w-48 bg-[#191E2C] border border-white/15 rounded-2xl shadow-2xl py-1.5 z-30 text-xs"
                            >
                                <router-link to="/satislar" class="block px-3.5 py-2 text-white hover:bg-white/5">
                                    {{ $t('Yeni Satış Faturası') }}
                                </router-link>
                                <router-link to="/hizli-satis" class="block px-3.5 py-2 text-white hover:bg-white/5">
                                    {{ $t('Hızlı Satış (POS)') }}
                                </router-link>
                                <button
                                    type="button"
                                    @click="projectFilterOpen = !projectFilterOpen; projectMenuOpen = false"
                                    class="w-full text-left px-3.5 py-2 text-white hover:bg-white/5 cursor-pointer"
                                >
                                    {{ $t('Kategoriye Göre Filtrele') }}
                                </button>
                                <button
                                    type="button"
                                    @click="toggleWidget('satis_siparisler'); projectMenuOpen = false"
                                    class="w-full text-left px-3.5 py-2 text-[#FFA0A0] hover:bg-[#C55050]/15 cursor-pointer"
                                >
                                    {{ $t('Bu Widget\'ı Kapat') }}
                                </button>
                            </div>

                            <button
                                type="button"
                                @click="projectFilterOpen = !projectFilterOpen"
                                class="w-8 h-8 rounded-full flex items-center justify-center transition-colors cursor-pointer border"
                                :class="projectFilterOpen || selectedProjectTag !== 'ALL'
                                    ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66]'
                                    : 'bg-[#191E2C]/70 hover:bg-white/10 text-[#94A3B8] hover:text-white border-white/[0.06]'"
                                :title="$t('Kategori Filtresi')"
                            >
                                <SlidersHorizontal class="w-3.5 h-3.5" />
                            </button>

                            <router-link
                                to="/satislar"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer border border-white/[0.06]"
                                :title="$t('Satışlar Sayfasına Git')"
                            >
                                <ArrowUpRight class="w-4 h-4" />
                            </router-link>
                        </div>
                    </div>

                    <!-- ==================== MOBİL ÖZEL TASARIM (media_1793998659104_5bb8bb3e.jpg BİREBİR) ==================== -->
                    <div class="sm:hidden space-y-3 mb-3">
                        <!-- 1. Yuvarlak Neon 'Tümü' (All) + #Kategori Kapsülleri + Sağda Yuvarlak '+' Butonu -->
                        <div class="flex items-center gap-2.5 overflow-x-auto no-scrollbar pb-1">
                            <button
                                type="button"
                                @click="selectedProjectTag = 'ALL'"
                                class="w-14 h-14 rounded-full font-extrabold text-xs flex items-center justify-center shrink-0 transition-all cursor-pointer"
                                :class="selectedProjectTag === 'ALL'
                                    ? 'bg-[#B9FF66] text-[#131722] shadow-[0_0_20px_rgba(185,255,102,0.45)]'
                                    : 'bg-[#191E2C] text-white border border-white/10'"
                            >
                                {{ $t('Tümü') }}
                            </button>

                            <button
                                v-for="tag in projectTags.filter(t => t !== 'ALL')"
                                :key="tag"
                                type="button"
                                @click="selectedProjectTag = tag"
                                class="h-14 px-5 rounded-full font-bold text-xs flex items-center justify-center shrink-0 transition-all cursor-pointer border"
                                :class="selectedProjectTag === tag
                                    ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66]'
                                    : 'bg-[#191E2C] text-white/90 border-white/[0.07]'"
                            >
                                #{{ tag }}
                            </button>

                            <router-link
                                to="/satislar"
                                class="w-14 h-14 rounded-full bg-[#2A3146] border border-white/15 text-white flex items-center justify-center shrink-0 ml-auto shadow-lg"
                                :title="$t('Yeni Ekle')"
                            >
                                <Plus class="w-6 h-6" />
                            </router-link>
                        </div>

                        <!-- 2. Tam Genişlikli Oval '+' Ekleme Barı (Görseldeki yeşil '+' ikonu ile) -->
                        <router-link
                            to="/satislar"
                            class="w-full h-14 rounded-full bg-[#191E2C]/90 border border-white/[0.08] flex items-center justify-center text-[#B9FF66] hover:bg-[#2A3146] transition-colors shadow-inner"
                        >
                            <Plus class="w-6 h-6 stroke-[2.2]" />
                        </router-link>
                    </div>

                    <!-- Masaüstü Açılır Kategori Filtre Hapları -->
                    <div v-if="projectFilterOpen" class="hidden sm:flex items-center gap-1.5 overflow-x-auto no-scrollbar mb-3 pb-1">
                        <button
                            v-for="tag in projectTags"
                            :key="tag"
                            type="button"
                            @click="selectedProjectTag = tag"
                            class="px-3 py-1 rounded-full text-[11px] font-bold transition-all cursor-pointer shrink-0"
                            :class="selectedProjectTag === tag
                                ? 'bg-[#B9FF66] text-[#131722]'
                                : 'bg-[#191E2C] text-[#94A3B8] hover:text-white border border-white/10'"
                        >
                            {{ tag === 'ALL' ? $t('Tümü') : '#' + tag }}
                        </button>
                    </div>

                    <!-- Gövde: Sol Düşey (+) Kartı (Masaüstü) + Renkli Proje/Satış Kartları -->
                    <div class="flex flex-col sm:flex-row items-stretch gap-3.5 grow">
                        <!-- Sol Düşey (+) Hızlı Ekle Sütunu (Masaüstünde görünür, mobilde üstte tam bar var) -->
                        <router-link
                            to="/satislar"
                            class="hidden sm:flex sm:w-12 shrink-0 rounded-[22px] bg-[#2A3146]/70 hover:bg-[#B9FF66] border border-white/[0.08] hover:border-[#B9FF66] items-center justify-center text-white/70 hover:text-[#131722] transition-all cursor-pointer group shadow-inner"
                            :title="$t('Yeni Satış Faturası Oluştur')"
                        >
                            <Plus class="w-5 h-5 transition-transform group-hover:scale-125" />
                        </router-link>

                        <!-- Renkli Proje/Satış Kartları (Mobilde yan renk şeritleriyle dikey, Masaüstünde 2x3 Grid) -->
                        <div v-if="filteredProjectCards.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-3.5 flex-1 min-w-0">
                            <div
                                v-for="(proj, idx) in filteredProjectCards"
                                :key="idx"
                                @click="router.push(proj.to || '/satislar')"
                                class="relative rounded-[26px] sm:rounded-[22px] p-5 sm:p-4 flex flex-col justify-between text-white transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl cursor-pointer min-h-[158px] sm:min-h-[132px]"
                                :style="{ backgroundColor: proj.bg }"
                            >
                                <!-- Mobil Yan Renk Vurgu Şeritleri (Referans mobil görseldeki sol/sağ kenar renk yansımaları) -->
                                <span
                                    class="sm:hidden pointer-events-none absolute -left-3.5 top-4 bottom-4 w-2 rounded-r-full opacity-90"
                                    :style="{ backgroundColor: idx % 2 === 0 ? '#BC5FCF' : '#B9FF66' }"
                                ></span>
                                <span
                                    class="sm:hidden pointer-events-none absolute -right-3.5 top-4 bottom-4 w-2 rounded-l-full opacity-90"
                                    :style="{ backgroundColor: idx % 2 === 0 ? '#C55050' : '#3D55B6' }"
                                ></span>

                                <!-- Üst: #Kategori Hapı + ↗ Daire İkon -->
                                <div class="flex items-center justify-between mb-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs sm:text-[11px] font-semibold text-white/95 bg-black/15 backdrop-blur-xs">
                                        #{{ proj.tag }}
                                    </span>
                                    <span class="w-9 h-9 sm:w-7 sm:h-7 rounded-full bg-white/15 hover:bg-white/25 flex items-center justify-center text-white transition-colors">
                                        <ArrowUpRight class="w-4 h-4 sm:w-3.5 sm:h-3.5" />
                                    </span>
                                </div>

                                <!-- Orta: Müşteri / Belge Başlığı + Alt Bilgi -->
                                <div class="my-1">
                                    <h3 class="text-xl sm:text-base font-extrabold tracking-tight text-white truncate">
                                        {{ proj.title }}
                                    </h3>
                                    <p class="text-xs sm:text-[11px] text-white/85 font-medium truncate mt-0.5">
                                        {{ proj.subtitle }}
                                    </p>
                                </div>

                                <!-- Alt: Büyük Tutar + Avatar Kümesi -->
                                <div class="flex items-center justify-between pt-3 sm:pt-2 mt-1">
                                    <span class="text-2xl sm:text-xl font-black text-white tracking-tight">
                                        {{ proj.amount }}
                                    </span>
                                    <div class="flex items-center -space-x-2">
                                        <div
                                            v-for="(av, aIdx) in proj.avatars"
                                            :key="aIdx"
                                            class="w-7 h-7 sm:w-6 sm:h-6 rounded-full border-2 border-black/20 text-[10px] sm:text-[9px] font-black text-white flex items-center justify-center shadow-xs"
                                            :style="{ backgroundColor: av.bg }"
                                        >
                                            {{ av.letter }}
                                        </div>
                                        <span
                                            v-if="proj.extraAvatars"
                                            class="w-7 h-7 sm:w-6 sm:h-6 rounded-full border-2 border-black/20 bg-[#131722]/85 text-[10px] sm:text-[9px] font-extrabold text-white flex items-center justify-center"
                                        >
                                            +{{ proj.extraAvatars }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Belge / Sipariş Olmadığında Boş Durum Şık Kartı -->
                        <div
                            v-else
                            class="flex-1 min-w-0 flex flex-col items-center justify-center p-8 rounded-[22px] bg-[#191E2C]/60 border border-white/[0.05] text-center"
                        >
                            <div class="w-12 h-12 rounded-2xl bg-[#B9FF66]/10 text-[#B9FF66] flex items-center justify-center mb-3">
                                <ShoppingCart class="w-6 h-6" />
                            </div>
                            <h4 class="text-sm font-extrabold text-white mb-1">{{ $t('Açık Satış & Sipariş Kaydı Bulunmuyor') }}</h4>
                            <p class="text-xs text-[#94A3B8] max-w-sm mb-4">
                                {{ $t('Yeni bir satış teklifi, sipariş veya fatura oluşturarak buradaki renkli kartlarda hızlı takibini yapabilirsiniz.') }}
                            </p>
                            <router-link
                                to="/satislar"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-black hover:brightness-105 transition-all"
                            >
                                <Plus class="w-4 h-4 stroke-[2.5]" />
                                <span>{{ $t('Yeni Satış / Sipariş Ekle') }}</span>
                            </router-link>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: TAKVİM (CALENDAR) ==================== -->
                <div
                    v-else-if="wId === 'due_calendar' && isWidgetVisible('due_calendar')"
                    :class="[getColSpanClass('due_calendar'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'due_calendar')"
                    @dragover.prevent="onWidgetDragOver($event, 'due_calendar')"
                    @drop="onWidgetDrop($event, 'due_calendar')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_14px_34px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="due_calendar"
                        title-key="Sürükle: Takvim"
                        :span="getWidgetSpan('due_calendar')"
                        @set-span="setWidgetSpan('due_calendar', $event)"
                        @toggle="toggleWidget('due_calendar')"
                    />

                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-1.5">
                            <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">
                                {{ $t('Takvim') }}
                            </h2>
                            <span class="text-xs font-bold text-[#64748B] -mt-2">{ {{ takvimAyAdi }} }</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="changeCalendarMonth(-1)"
                                class="w-7 h-7 rounded-full bg-[#191E2C]/70 hover:bg-white/10 text-[#94A3B8] hover:text-white flex items-center justify-center transition-colors cursor-pointer border border-white/[0.06]"
                                :title="$t('Önceki Ay')"
                            >
                                <ChevronLeft class="w-3.5 h-3.5" />
                            </button>
                            <button
                                type="button"
                                @click="changeCalendarMonth(1)"
                                class="w-7 h-7 rounded-full bg-[#191E2C]/70 hover:bg-white/10 text-[#94A3B8] hover:text-white flex items-center justify-center transition-colors cursor-pointer border border-white/[0.06]"
                                :title="$t('Sonraki Ay')"
                            >
                                <ChevronRight class="w-3.5 h-3.5" />
                            </button>
                            <button
                                type="button"
                                @click="onlyDueHighlight = !onlyDueHighlight"
                                class="w-8 h-8 rounded-full flex items-center justify-center transition-colors cursor-pointer border"
                                :class="onlyDueHighlight
                                    ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66]'
                                    : 'bg-[#191E2C]/70 hover:bg-white/10 text-[#94A3B8] hover:text-white border-white/[0.06]'"
                                :title="$t('Vadeli Günleri Vurgula')"
                            >
                                <SlidersHorizontal class="w-3.5 h-3.5" />
                            </button>
                            <router-link
                                to="/borc-alacak"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer border border-white/[0.06]"
                                :title="$t('Borç & Alacak Vadelerine Git')"
                            >
                                <ArrowUpRight class="w-4 h-4" />
                            </router-link>
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-1.5 grow">
                        <button
                            v-for="(g, i) in takvimGunleri"
                            :key="i"
                            type="button"
                            :disabled="!g"
                            @click="g && selectCalendarDay(g.iso)"
                            class="min-h-[42px] sm:min-h-[46px] rounded-2xl border p-1.5 flex flex-col justify-between text-left transition-all relative cursor-pointer disabled:cursor-default"
                            :class="[
                                !g ? 'bg-[#191E2C]/25 border-transparent opacity-40' : '',
                                g && seciliTakvimGunu === g.iso
                                    ? 'bg-[#B9FF66] text-[#131722] border-[#B9FF66] shadow-[0_0_14px_rgba(185,255,102,0.45)]'
                                    : g && takvimBugunMu(g.iso)
                                        ? 'bg-[#B9FF66]/20 text-[#B9FF66] border-[#B9FF66]'
                                        : g && (vadeGunHaritasi[g.iso] || []).length
                                            ? 'bg-[#2A3146]/70 text-white ' + takvimHalkasi(g.iso)
                                            : 'bg-[#2A3146]/40 text-[#94A3B8] border-white/[0.04] hover:border-white/20',
                            ]"
                        >
                            <template v-if="g">
                                <span class="text-[11px] font-bold leading-none">{{ g.gun }}</span>
                                <div
                                    v-if="(vadeGunHaritasi[g.iso] || []).length"
                                    class="flex items-center justify-end -space-x-1 mt-auto"
                                >
                                    <span class="w-3.5 h-3.5 rounded-full bg-[#3D55B6] border border-[#191E2C] text-[7px] font-black text-white flex items-center justify-center">
                                        {{ ((vadeGunHaritasi[g.iso][0]?.party_name) || 'M')[0] }}
                                    </span>
                                    <span class="w-3.5 h-3.5 rounded-full bg-[#DC6F31] border border-[#191E2C] text-[7px] font-black text-white flex items-center justify-center">
                                        +
                                    </span>
                                </div>
                            </template>
                        </button>
                    </div>

                    <div class="pt-2.5 mt-2.5 border-t border-white/[0.07] flex items-center justify-between text-xs">
                        <template v-if="seciliGunVadeleri.length">
                            <div class="flex items-center gap-2 truncate">
                                <span class="w-2 h-2 rounded-full bg-[#B9FF66]" />
                                <span class="font-bold text-white truncate">{{ seciliGunVadeleri[0].party_name || 'Cari Vade' }}</span>
                                <span class="text-[#64748B] font-mono text-[11px]">{{ seciliGunVadeleri[0].serial }}</span>
                            </div>
                            <span class="font-black text-[#B9FF66] shrink-0">{{ formatMoney(seciliGunVadeleri[0].open_amount || 12500, seciliGunVadeleri[0].currency || 'TRY') }}</span>
                        </template>
                        <template v-else>
                            <span class="text-[11px] text-[#94A3B8]">{{ seciliTakvimGunu || $t('Vade özeti') }}</span>
                            <router-link to="/satislar" class="text-[11px] font-bold text-[#B9FF66] hover:underline">
                                {{ $t('+ Vade Ekle') }}
                            </router-link>
                        </template>
                    </div>
                </div>

                <!-- ==================== BENTO: BU YILKİ FİNANS (PROJECTS THIS YEAR) ==================== -->
                <div
                    v-else-if="wId === 'yillik_finans' && isWidgetVisible('yillik_finans')"
                    :class="[getColSpanClass('yillik_finans'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'yillik_finans')"
                    @dragover.prevent="onWidgetDragOver($event, 'yillik_finans')"
                    @drop="onWidgetDrop($event, 'yillik_finans')"
                    class="bg-[#212738] rounded-[28px] p-6 border border-white/[0.07] shadow-[0_14px_34px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between min-h-[320px] transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="yillik_finans"
                        title-key="Sürükle: Bu Yılki Finans"
                        :span="getWidgetSpan('yillik_finans')"
                        @set-span="setWidgetSpan('yillik_finans', $event)"
                        @toggle="toggleWidget('yillik_finans')"
                    />

                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">
                            {{ $t('Bu Yılki Finans') }}
                        </h2>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="toggleWidget('yillik_finans')"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer border border-white/[0.06]"
                                :title="$t('Bu Kartı Gizle')"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                            <router-link
                                to="/raporlar"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer border border-white/[0.06]"
                                :title="$t('Finans Raporlarına Git')"
                            >
                                <ArrowUpRight class="w-4 h-4" />
                            </router-link>
                        </div>
                    </div>

                    <div class="space-y-6 my-auto">
                        <div>
                            <p class="text-xs sm:text-sm font-medium text-[#94A3B8] mb-1">
                                {{ $t('Ortalama işlem değeri') }}
                            </p>
                            <div class="flex flex-wrap items-baseline gap-2.5">
                                <span class="text-3xl sm:text-4xl font-extrabold text-[#B9FF66] tracking-tight">
                                    {{ ortalamaIslemDegeri }}
                                </span>
                                <span class="text-xs font-medium text-[#64748B]">
                                    {{ gecenYilOrtalamaIslem }} {{ $t('geçen yıla göre fark') }}
                                </span>
                            </div>
                        </div>

                        <div>
                            <p class="text-xs sm:text-sm font-medium text-[#94A3B8] mb-1">
                                {{ $t('Cari başına ortalama işlem') }}
                            </p>
                            <div class="flex flex-wrap items-baseline gap-2.5">
                                <span class="text-3xl sm:text-4xl font-extrabold text-[#B9FF66] tracking-tight">
                                    {{ cariBasiOrtalama }}
                                </span>
                                <span class="text-xs font-medium text-[#64748B]">
                                    {{ gecenYilCariBasiOrtalama }} {{ $t('geçen yıl ortalaması') }}
                                </span>
                            </div>
                        </div>

                        <div>
                            <p class="text-xs sm:text-sm font-medium text-[#94A3B8] mb-1">
                                {{ $t('Yeni cariler & sözleşmeler') }}
                            </p>
                            <div class="flex flex-wrap items-baseline gap-2.5">
                                <span class="text-3xl sm:text-4xl font-extrabold text-[#B9FF66] tracking-tight">
                                    {{ yeniCariSayisi }}
                                </span>
                                <span class="text-xs font-medium text-[#64748B]">
                                    {{ gecenYilYeniCari }} {{ $t('geçen yıl kaydı') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: YILLIK KÂR ANALİZİ (YEARLY PROFIT) ==================== -->
                <div
                    v-else-if="wId === 'revenue_trend' && isWidgetVisible('revenue_trend')"
                    :class="[getColSpanClass('revenue_trend'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'revenue_trend')"
                    @dragover.prevent="onWidgetDragOver($event, 'revenue_trend')"
                    @drop="onWidgetDrop($event, 'revenue_trend')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_14px_34px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between min-h-[320px] transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="revenue_trend"
                        title-key="Sürükle: Yıllık Kâr Analizi"
                        :span="getWidgetSpan('revenue_trend')"
                        @set-span="setWidgetSpan('revenue_trend', $event)"
                        @toggle="toggleWidget('revenue_trend')"
                    />

                    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                        <div class="flex items-center gap-1.5">
                            <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">
                                {{ karAnaliziBaslik }}
                            </h2>
                            <span class="text-xs font-bold text-[#64748B] -mt-2">{ {{ karAnaliziOran }} }</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="cycleProfitMode"
                                class="px-3 py-1 rounded-full bg-[#191E2C]/80 hover:bg-white/10 text-[11px] font-bold text-[#B9FF66] border border-white/[0.07] transition-colors cursor-pointer flex items-center gap-1.5"
                                :title="$t('Analiz Modunu Değiştir')"
                            >
                                <SlidersHorizontal class="w-3 h-3" />
                                <span>{{ profitModeLabel }}</span>
                            </button>
                            <button
                                type="button"
                                @click="toggleWidget('revenue_trend')"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer border border-white/[0.06]"
                                :title="$t('Bu Kartı Gizle')"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                            <router-link
                                to="/raporlar"
                                class="w-8 h-8 rounded-full bg-[#191E2C]/70 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer border border-white/[0.06]"
                                :title="$t('Detaylı Raporlar')"
                            >
                                <ArrowUpRight class="w-4 h-4" />
                            </router-link>
                        </div>
                    </div>

                    <div class="grid grid-cols-8 gap-2 sm:gap-3 mb-2">
                        <button
                            v-for="b in kapsulBarlari"
                            :key="b.kod"
                            type="button"
                            @click="seciliAy = b.kod"
                            class="py-1.5 px-1 rounded-full text-[11px] sm:text-xs font-semibold transition-all cursor-pointer text-center truncate border"
                            :class="seciliAy === b.kod
                                ? 'bg-[#2E364F] text-white border-white/20 shadow-sm'
                                : 'bg-[#191E2C]/70 text-[#94A3B8] hover:text-white border-white/[0.05]'"
                        >
                            {{ b.ad }}
                        </button>
                    </div>

                    <div class="relative pt-14 pb-1">
                        <div
                            class="absolute top-1 transition-all duration-300 flex flex-col items-center z-20"
                            :style="{ left: tooltipSolKonum + '%', transform: 'translateX(-50%)' }"
                        >
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <span class="px-3 py-1 rounded-full bg-[#2A3146] border border-white/10 text-[11px] font-medium text-[#94A3B8] shadow-lg">
                                    {{ $t('İşlem') }} <b class="text-white font-bold">{{ seciliBarObj.islem }}</b>
                                </span>
                                <span class="px-3 py-1 rounded-full bg-[#B9FF66] text-[#131722] text-xs font-extrabold shadow-[0_0_20px_rgba(185,255,102,0.5)]">
                                    {{ seciliBarObj.tutar }}
                                </span>
                            </div>

                            <div class="flex items-center gap-3 mt-1 text-[#94A3B8]">
                                <button
                                    type="button"
                                    @click="stepSelectedMonth(-1)"
                                    class="hover:text-white cursor-pointer text-xs leading-none px-1"
                                    :title="$t('Önceki Ay')"
                                >
                                    ‹
                                </button>
                                <span class="w-1.5 h-1.5 rounded-full bg-[#B9FF66]" />
                                <button
                                    type="button"
                                    @click="stepSelectedMonth(1)"
                                    class="hover:text-white cursor-pointer text-xs leading-none px-1"
                                    :title="$t('Sonraki Ay')"
                                >
                                    ›
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-8 gap-2.5 sm:gap-4 items-end h-44 pt-2">
                            <div
                                v-for="b in kapsulBarlari"
                                :key="b.kod"
                                @click="seciliAy = b.kod"
                                class="flex flex-col items-center justify-end h-full cursor-pointer group"
                            >
                                <div
                                    class="w-full rounded-t-[22px] sm:rounded-t-[26px] rounded-b-[18px] transition-all duration-300 relative overflow-hidden"
                                    :class="[
                                        b.kod === seciliAy
                                            ? 'bg-gradient-to-b from-[#B9FF66] via-[#A8F250] to-[#78C828] shadow-[0_0_28px_rgba(185,255,102,0.4)]'
                                            : 'bg-gradient-to-b from-[#557A5A]/55 via-[#2C3748]/50 to-[#1B2030] border-t border-x border-white/10 group-hover:from-[#6A9970]/65',
                                    ]"
                                    :style="{ height: b.yuzde + '%' }"
                                />

                                <div
                                    class="w-full h-11 sm:h-12 -mt-5 rounded-[18px] sm:rounded-[22px] relative z-10 border transition-all overflow-hidden shrink-0"
                                    :class="[
                                        b.kod === seciliAy
                                            ? 'bg-[#435E37]/95 border-[#B9FF66]/50 shadow-inner'
                                            : 'bg-[#1D2333]/95 border-white/[0.07] group-hover:border-white/20',
                                    ]"
                                >
                                    <div
                                        class="w-full h-full opacity-25"
                                        style="background-image: radial-gradient(rgba(255,255,255,0.45) 1px, transparent 1px); background-size: 5px 5px;"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: DÖVİZ KURLARI (FX RATES) ==================== -->
                <div
                    v-else-if="wId === 'fx_rates' && isWidgetVisible('fx_rates')"
                    :class="[getColSpanClass('fx_rates'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'fx_rates')"
                    @dragover.prevent="onWidgetDragOver($event, 'fx_rates')"
                    @drop="onWidgetDrop($event, 'fx_rates')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="fx_rates"
                        title-key="Sürükle: Döviz Kurları"
                        :span="getWidgetSpan('fx_rates')"
                        @set-span="setWidgetSpan('fx_rates', $event)"
                        @toggle="toggleWidget('fx_rates')"
                    />

                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-[#B9FF66]/15 flex items-center justify-center text-[#B9FF66]">
                                <TrendingUp class="w-4 h-4" />
                            </div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('Döviz Kurları (USD & EUR)') }}
                            </h3>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="refreshRates"
                                :disabled="refreshingRates"
                                class="w-7 h-7 rounded-full bg-white/5 hover:bg-white/10 text-[#94A3B8] hover:text-white flex items-center justify-center transition-colors cursor-pointer disabled:opacity-40"
                                :title="$t('Kurları Şimdi Güncelle')"
                            >
                                <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin text-[#B9FF66]': refreshingRates }" />
                            </button>
                            <button
                                type="button"
                                @click="toggleWidget('fx_rates')"
                                class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                                :title="$t('Bu Kartı Gizle')"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    <div class="space-y-2.5 grow">
                        <div
                            v-for="fx in fxRatesList"
                            :key="fx.currency"
                            class="flex items-center justify-between p-3 rounded-2xl bg-[#191E2C]/90 border border-white/[0.06] hover:border-white/15 transition-all"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl bg-white/10 flex items-center justify-center font-black text-xs text-[#B9FF66]">
                                    {{ fx.symbol }}
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-white">{{ fx.currency }} / TRY</div>
                                    <div class="text-[10px] text-[#64748B]">{{ fx.rate_date || 'Güncel' }} · {{ (fx.source || 'TCMB').toUpperCase() }}</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-black text-white tracking-tight">
                                    ₺{{ formatKur(fx.rate) }}
                                </div>
                                <div class="text-[10px] font-extrabold text-[#B9FF66]">
                                    Canlı Kur
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-white/[0.07] mt-3 flex items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-1.5">
                            <input
                                v-model.number="converterAmount"
                                type="number"
                                min="1"
                                class="w-16 px-2 py-1 rounded-lg bg-[#191E2C] border border-white/10 text-white font-bold text-xs focus:outline-none focus:border-[#B9FF66]"
                            />
                            <button
                                type="button"
                                @click="converterCurrency = converterCurrency === 'USD' ? 'EUR' : 'USD'"
                                class="px-2 py-1 rounded-lg bg-white/10 text-white font-bold text-xs cursor-pointer hover:bg-[#B9FF66] hover:text-[#131722] transition-colors"
                            >
                                {{ converterCurrency }} ⇄
                            </button>
                        </div>
                        <span class="font-black text-[#B9FF66] text-sm">
                            = ₺{{ formatKur((converterAmount || 0) * (converterCurrency === 'USD' ? usdRate : eurRate)) }}
                        </span>
                    </div>
                </div>

                <!-- ==================== BENTO: KRİTİK STOK (LOW STOCK) ==================== -->
                <div
                    v-else-if="wId === 'low_stock' && isWidgetVisible('low_stock')"
                    :class="[getColSpanClass('low_stock'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'low_stock')"
                    @dragover.prevent="onWidgetDragOver($event, 'low_stock')"
                    @drop="onWidgetDrop($event, 'low_stock')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="low_stock"
                        title-key="Sürükle: Kritik Stok"
                        :span="getWidgetSpan('low_stock')"
                        @set-span="setWidgetSpan('low_stock', $event)"
                        @toggle="toggleWidget('low_stock')"
                    />

                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-[#DC6F31]/20 flex items-center justify-center text-[#DC6F31]">
                                <Boxes class="w-4 h-4" />
                            </div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('Kritik Stok') }}
                            </h3>
                            <span class="text-xs font-bold text-[#64748B]">{ {{ lowStockList.length }} }</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="toggleWidget('low_stock')"
                                class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                                :title="$t('Bu Kartı Gizle')"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                            <router-link
                                to="/stok"
                                class="w-7 h-7 rounded-full bg-white/10 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer"
                                :title="$t('Stok Yönetimi')"
                            >
                                <ArrowUpRight class="w-3.5 h-3.5" />
                            </router-link>
                        </div>
                    </div>

                    <div class="space-y-2.5 grow">
                        <div
                            v-for="p in lowStockList.slice(0, 3)"
                            :key="p.product_id"
                            class="p-3 rounded-2xl bg-[#191E2C]/90 border border-white/[0.06] flex items-center justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-white truncate">{{ p.name }}</p>
                                <p class="text-[10px] text-[#94A3B8]">{{ $t('Min. Stok') }}: {{ p.minimum }} adet</p>
                            </div>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-[#C55050]/20 text-[#FFA0A0] border border-[#C55050]/30 shrink-0">
                                {{ p.stock }} {{ $t('kaldı') }}
                            </span>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-white/[0.07] mt-3">
                        <router-link
                            to="/alimlar"
                            class="w-full py-2 rounded-xl bg-white/5 hover:bg-[#B9FF66] text-white hover:text-[#131722] text-xs font-bold flex items-center justify-center gap-1.5 transition-colors"
                        >
                            <Plus class="w-3.5 h-3.5" />
                            <span>{{ $t('Tedarik & Alım Siparişi Oluştur') }}</span>
                        </router-link>
                    </div>
                </div>

                <!-- ==================== BENTO: SON İŞLEMLER (RECENT ACTIVITY) ==================== -->
                <div
                    v-else-if="wId === 'recent_activity' && isWidgetVisible('recent_activity')"
                    :class="[getColSpanClass('recent_activity'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'recent_activity')"
                    @dragover.prevent="onWidgetDragOver($event, 'recent_activity')"
                    @drop="onWidgetDrop($event, 'recent_activity')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="recent_activity"
                        title-key="Sürükle: Son İşlemler"
                        :span="getWidgetSpan('recent_activity')"
                        @set-span="setWidgetSpan('recent_activity', $event)"
                        @toggle="toggleWidget('recent_activity')"
                    />

                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-[#BC5FCF]/20 flex items-center justify-center text-[#BC5FCF]">
                                <Clock class="w-4 h-4" />
                            </div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('Son İşlemler') }}
                            </h3>
                            <span class="text-xs font-bold text-[#64748B]">{ {{ recentActivities.length }} }</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="toggleWidget('recent_activity')"
                                class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                                :title="$t('Bu Kartı Gizle')"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                            <router-link
                                to="/raporlar"
                                class="w-7 h-7 rounded-full bg-white/10 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer"
                                :title="$t('Raporlar')"
                            >
                                <ArrowUpRight class="w-3.5 h-3.5" />
                            </router-link>
                        </div>
                    </div>

                    <div class="space-y-2.5 grow">
                        <div
                            v-for="(a, idx) in recentActivities.slice(0, 3)"
                            :key="idx"
                            class="flex items-center justify-between p-3 rounded-2xl bg-[#191E2C]/90 border border-white/[0.06]"
                        >
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-7 h-7 rounded-full bg-white/10 flex items-center justify-center text-[10px] font-black text-[#B9FF66] shrink-0">
                                    {{ a.user_name ? a.user_name[0].toUpperCase() : 'S' }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-white truncate">{{ a.action_label }}</p>
                                    <p class="text-[10px] text-[#94A3B8] truncate">{{ a.user_name || 'Sistem' }}</p>
                                </div>
                            </div>
                            <span class="text-[10px] font-mono text-[#64748B] shrink-0">{{ a.created_at || 'Bugün' }}</span>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-white/[0.07] mt-3 flex items-center justify-between text-[11px] text-[#94A3B8]">
                        <span>{{ $t('Denetim Kayıtları') }}</span>
                        <span class="font-extrabold text-[#B9FF66]">✓ Senkronize</span>
                    </div>
                </div>

                <!-- ==================== BENTO: NAKİT AKIŞI DALGA GRAFİĞİ (CASH FLOW WAVE) ==================== -->
                <div
                    v-else-if="wId === 'cash_flow_wave' && isWidgetVisible('cash_flow_wave')"
                    :class="[getColSpanClass('cash_flow_wave'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'cash_flow_wave')"
                    @dragover.prevent="onWidgetDragOver($event, 'cash_flow_wave')"
                    @drop="onWidgetDrop($event, 'cash_flow_wave')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="cash_flow_wave"
                        title-key="Sürükle: Nakit Akışı Dalga Grafiği"
                        :span="getWidgetSpan('cash_flow_wave')"
                        @set-span="setWidgetSpan('cash_flow_wave', $event)"
                        @toggle="toggleWidget('cash_flow_wave')"
                    />

                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base sm:text-lg font-extrabold text-white tracking-tight">
                                    {{ $t('Nakit Akışı & Dalga Analizi') }}
                                </h3>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-[#B9FF66]/15 text-[#B9FF66] border border-[#B9FF66]/30">
                                    +24.6% Net
                                </span>
                            </div>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Tahsilat (Yeşil Dalga) vs Ödeme Çıkışları (Turuncu Dalga)') }}</p>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                @click="toggleWidget('cash_flow_wave')"
                                class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                                :title="$t('Bu Kartı Gizle')"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                            <router-link
                                to="/borc-alacak"
                                class="w-7 h-7 rounded-full bg-white/10 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer"
                            >
                                <ArrowUpRight class="w-3.5 h-3.5" />
                            </router-link>
                        </div>
                    </div>

                    <!-- SVG Çift Katmanlı Yumuşak Dalga Grafiği -->
                    <div class="relative h-44 w-full bg-[#191E2C]/90 rounded-2xl border border-white/[0.06] p-3 overflow-hidden flex flex-col justify-between">
                        <div class="flex items-center justify-between text-[11px] z-10">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center gap-1.5 font-bold text-[#B9FF66]">
                                    <span class="w-2.5 h-2.5 rounded-full bg-[#B9FF66]"></span>
                                    {{ $t('Giren Nakit') }}: {{ formatMoney(cashFlowIn, 'TRY') }}
                                </span>
                                <span class="inline-flex items-center gap-1.5 font-bold text-[#DC6F31]">
                                    <span class="w-2.5 h-2.5 rounded-full bg-[#DC6F31]"></span>
                                    {{ $t('Çıkan Nakit') }}: {{ formatMoney(cashFlowOut, 'TRY') }}
                                </span>
                            </div>
                            <span class="font-mono text-[10px] text-[#94A3B8]">Son 6 Ay</span>
                        </div>

                        <svg viewBox="0 0 600 140" class="w-full h-28 overflow-visible">
                            <defs>
                                <linearGradient id="dashWaveIn" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#B9FF66" stop-opacity="0.45" />
                                    <stop offset="100%" stop-color="#B9FF66" stop-opacity="0.0" />
                                </linearGradient>
                                <linearGradient id="dashWaveOut" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#DC6F31" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#DC6F31" stop-opacity="0.0" />
                                </linearGradient>
                            </defs>
                            <!-- Giren Nakit Dalga Alanı -->
                            <path
                                v-if="cashWaveInPath"
                                :d="`${cashWaveInPath} L600,140 L0,140 Z`"
                                fill="url(#dashWaveIn)"
                            />
                            <path
                                v-if="cashWaveInPath"
                                :d="cashWaveInPath"
                                fill="none"
                                stroke="#B9FF66"
                                stroke-width="3.5"
                                stroke-linecap="round"
                            />
                            <!-- Çıkan Nakit Dalga Alanı -->
                            <path
                                v-if="cashWaveOutPath"
                                :d="`${cashWaveOutPath} L600,140 L0,140 Z`"
                                fill="url(#dashWaveOut)"
                            />
                            <path
                                v-if="cashWaveOutPath"
                                :d="cashWaveOutPath"
                                fill="none"
                                stroke="#DC6F31"
                                stroke-width="2.5"
                                stroke-dasharray="5 4"
                                stroke-linecap="round"
                            />
                            <!-- Parlak Noktalar -->
                            <circle cx="180" cy="55" r="4.5" fill="#131722" stroke="#B9FF66" stroke-width="3" />
                            <circle cx="360" cy="35" r="4.5" fill="#131722" stroke="#B9FF66" stroke-width="3" />
                            <circle cx="540" cy="25" r="5.5" fill="#B9FF66" />
                        </svg>

                        <div class="grid grid-cols-6 text-[10px] font-bold text-[#64748B] text-center">
                            <span>Haz</span><span>Tem</span><span>Ağu</span><span>Eyl</span><span>Eki</span><span class="text-[#B9FF66]">Kas</span>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: KATEGORİ PASTA / DONUT GRAFİĞİ (CATEGORY PIE) ==================== -->
                <div
                    v-else-if="wId === 'category_pie' && isWidgetVisible('category_pie')"
                    :class="[getColSpanClass('category_pie'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'category_pie')"
                    @dragover.prevent="onWidgetDragOver($event, 'category_pie')"
                    @drop="onWidgetDrop($event, 'category_pie')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="category_pie"
                        title-key="Sürükle: Kategori Pasta Grafiği"
                        :span="getWidgetSpan('category_pie')"
                        @set-span="setWidgetSpan('category_pie', $event)"
                        @toggle="toggleWidget('category_pie')"
                    />

                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('Kategori Ciro Pasta Analizi') }}
                            </h3>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Sektörel dağılım') }}</p>
                        </div>
                        <button
                            type="button"
                            @click="toggleWidget('category_pie')"
                            class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                        >
                            <X class="w-3.5 h-3.5" />
                        </button>
                    </div>

                    <div class="flex items-center gap-4 my-auto py-2">
                        <!-- SVG Donut / Pasta Grafiği — GERÇEK kategori dağılımı (widget: category_mix) -->
                        <div class="relative w-32 h-32 shrink-0 flex items-center justify-center">
                            <svg viewBox="0 0 42 42" class="w-full h-full -rotate-90">
                                <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#191E2C" stroke-width="6" />
                                <circle
                                    v-for="dilim in categoryMixItems"
                                    :key="dilim.name"
                                    cx="21"
                                    cy="21"
                                    r="15.915"
                                    fill="transparent"
                                    :stroke="dilim.color"
                                    stroke-width="6"
                                    :stroke-dasharray="dilim.dasharray"
                                    :stroke-dashoffset="dilim.dashoffset"
                                />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-xs font-black text-white">{{ categoryMixToplam }}%</span>
                                <span class="text-[9px] font-bold text-[#94A3B8]">{{ $t('Dağılım') }}</span>
                            </div>
                        </div>

                        <div class="space-y-2 flex-1 min-w-0 text-xs">
                            <div v-for="dilim in categoryMixItems" :key="dilim.name" class="flex items-center justify-between">
                                <span class="inline-flex items-center gap-1.5 text-white font-bold truncate">
                                    <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: dilim.color }"></span>{{ dilim.name }}
                                </span>
                                <span class="font-black" :style="{ color: dilim.color }">{{ dilim.pct }}%</span>
                            </div>
                            <p v-if="categoryMixItems.length === 0" class="text-xs text-[#64748B]">
                                {{ $t('Veri yok') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: ALACAK & BORÇ YAŞLANDIRMA SÜTUNLARI (DEBT AGING) ==================== -->
                <div
                    v-else-if="wId === 'debt_aging' && isWidgetVisible('debt_aging')"
                    :class="[getColSpanClass('debt_aging'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'debt_aging')"
                    @dragover.prevent="onWidgetDragOver($event, 'debt_aging')"
                    @drop="onWidgetDrop($event, 'debt_aging')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="debt_aging"
                        title-key="Sürükle: Alacak & Borç Sütunları"
                        :span="getWidgetSpan('debt_aging')"
                        @set-span="setWidgetSpan('debt_aging', $event)"
                        @toggle="toggleWidget('debt_aging')"
                    />

                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('Alacak & Borç Yaşlandırma') }}
                            </h3>
                            <p class="text-xs text-[#94A3B8]">{{ $t('Vade dilimlerine göre açık bakiye sütunları') }}</p>
                        </div>
                        <button
                            type="button"
                            @click="toggleWidget('debt_aging')"
                            class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                        >
                            <X class="w-3.5 h-3.5" />
                        </button>
                    </div>

                    <div class="space-y-3 my-auto">
                        <div v-for="bar in debtAgingBars" :key="bar.label" class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold text-white">{{ bar.label }}</span>
                                <span class="font-black" :style="{ color: bar.color }">{{ bar.amount }}</span>
                            </div>
                            <div class="w-full h-2.5 rounded-full bg-[#191E2C] overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    :style="{ width: Math.round((bar.raw / debtAgingMax) * 100) + '%', backgroundColor: bar.color }"
                                ></div>
                            </div>
                        </div>
                        <p v-if="debtAgingBars.length === 0" class="text-xs text-[#64748B]">
                            {{ $t('Veri yok') }}
                        </p>
                    </div>
                </div>

                <!-- ==================== BENTO: EN ÇOK CİRO YAPAN CARİLER (TOP CUSTOMERS) ==================== -->
                <div
                    v-else-if="wId === 'top_customers' && isWidgetVisible('top_customers')"
                    :class="[getColSpanClass('top_customers'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'top_customers')"
                    @dragover.prevent="onWidgetDragOver($event, 'top_customers')"
                    @drop="onWidgetDrop($event, 'top_customers')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="top_customers"
                        title-key="Sürükle: Lider Cariler"
                        :span="getWidgetSpan('top_customers')"
                        @set-span="setWidgetSpan('top_customers', $event)"
                        @toggle="toggleWidget('top_customers')"
                    />

                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('Lider Müşteriler & Cariler') }}
                            </h3>
                            <p class="text-xs text-[#94A3B8]">{{ $t('En yüksek hacimli iş ortakları') }}</p>
                        </div>
                        <router-link
                            to="/musteriler"
                            class="w-7 h-7 rounded-full bg-white/10 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer"
                        >
                            <ArrowUpRight class="w-3.5 h-3.5" />
                        </router-link>
                    </div>

                    <div class="space-y-2.5 my-auto">
                        <div
                            v-for="(cust, cIdx) in topCustomersList"
                            :key="cIdx"
                            class="flex items-center justify-between p-2.5 rounded-2xl bg-[#191E2C]/90 border border-white/[0.06]"
                        >
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white shrink-0" :style="{ backgroundColor: cust.color }">
                                    {{ cust.name[0] }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-extrabold text-white truncate">{{ cust.name }}</p>
                                    <p class="text-[10px] text-[#94A3B8]">{{ cust.city }} · {{ cust.orders }} {{ $t('işlem') }}</p>
                                </div>
                            </div>
                            <span class="text-xs font-black text-[#B9FF66] shrink-0">{{ cust.volume }}</span>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: İK & BAŞVURU HAVUZU (HR APPLICATIONS) ==================== -->
                <div
                    v-else-if="wId === 'hr_applications' && isWidgetVisible('hr_applications')"
                    :class="[getColSpanClass('hr_applications'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'hr_applications')"
                    @dragover.prevent="onWidgetDragOver($event, 'hr_applications')"
                    @drop="onWidgetDrop($event, 'hr_applications')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="hr_applications"
                        title-key="Sürükle: İK & Başvurular"
                        :span="getWidgetSpan('hr_applications')"
                        @set-span="setWidgetSpan('hr_applications', $event)"
                        @toggle="toggleWidget('hr_applications')"
                    />

                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-base font-extrabold text-white tracking-tight">
                                {{ $t('İK & Katılım Başvuruları') }}
                            </h3>
                            <p class="text-xs text-[#94A3B8]">{{ $t('CV & başvuru formu merkezi') }}</p>
                        </div>
                        <router-link
                            to="/kullanicilar"
                            class="w-7 h-7 rounded-full bg-white/10 hover:bg-[#B9FF66] text-white hover:text-[#131722] flex items-center justify-center transition-all cursor-pointer"
                        >
                            <ArrowUpRight class="w-3.5 h-3.5" />
                        </router-link>
                    </div>

                    <div class="p-4 rounded-2xl bg-[#191E2C]/90 border border-white/[0.06] space-y-3 my-auto">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-[#94A3B8]">{{ $t('Başvuru Formu Durumu') }}</span>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-[#4E956A]/25 text-[#B9FF66] border border-[#B9FF66]/30">
                                {{ $t('Aktif · CV Yüklü') }}
                            </span>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div class="rounded-xl bg-white/5 border border-white/[0.06] px-2 py-2 text-center">
                                <div class="text-lg font-extrabold text-[#B9FF66]">{{ hrApplications.pending || 0 }}</div>
                                <div class="text-[9px] font-bold text-[#94A3B8]">{{ $t('Bekleyen') }}</div>
                            </div>
                            <div class="rounded-xl bg-white/5 border border-white/[0.06] px-2 py-2 text-center">
                                <div class="text-lg font-extrabold text-[#7DD3FC]">{{ hrApplications.approved || 0 }}</div>
                                <div class="text-[9px] font-bold text-[#94A3B8]">{{ $t('Kabul Edildi') }}</div>
                            </div>
                            <div class="rounded-xl bg-white/5 border border-white/[0.06] px-2 py-2 text-center">
                                <div class="text-lg font-extrabold text-[#FDBA74]">{{ hrApplications.with_cv || 0 }}</div>
                                <div class="text-[9px] font-bold text-[#94A3B8]">CV</div>
                            </div>
                        </div>

                        <p class="text-xs text-white/90 leading-relaxed">
                            {{ $t('Firmanıza gelen iş ve ekip başvurularını CV dosyalarıyla birlikte inceleyin veya başvuru formunu form kurucu ile özelleştirin.') }}
                        </p>
                        <router-link
                            to="/kullanicilar"
                            class="w-full py-2 rounded-xl bg-[#B9FF66] text-[#131722] text-xs font-extrabold flex items-center justify-center gap-1.5 hover:brightness-105 transition-all"
                        >
                            <span>{{ $t('Başvuru Formunu & Adayları Yönet') }}</span>
                            <ArrowUpRight class="w-3.5 h-3.5" />
                        </router-link>
                    </div>
                </div>

                <!-- ==================== BENTO: MODÜLER PLUGİN & EKLENTİ MERKEZİ (PLUGINS HUB) ==================== -->
                <div
                    v-else-if="wId === 'plugins_hub' && isWidgetVisible('plugins_hub')"
                    :class="[getColSpanClass('plugins_hub'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'plugins_hub')"
                    @dragover.prevent="onWidgetDragOver($event, 'plugins_hub')"
                    @drop="onWidgetDrop($event, 'plugins_hub')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_10px_30px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="plugins_hub"
                        title-key="Sürükle: Modüler Plugin & Eklenti Merkezi"
                        :span="getWidgetSpan('plugins_hub')"
                        @set-span="setWidgetSpan('plugins_hub', $event)"
                        @toggle="toggleWidget('plugins_hub')"
                    />

                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-[#B9FF66] text-[#131722] uppercase">
                                    Plugin Mimarisi
                                </span>
                                <h3 class="text-base sm:text-lg font-extrabold text-white tracking-tight">
                                    {{ $t('Tak-Çıkar Modüler Eklenti & Plugin Merkezi') }}
                                </h3>
                            </div>
                            <p class="text-xs text-[#94A3B8] mt-0.5">
                                {{ $t('Sistemi yeniden güncellemeye gerek kalmadan yeni modül/plugin ekleyip çıkartabilir, istediğiniz eklentiyi tek tıkla aktif edebilirsiniz.') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <router-link
                                to="/ayarlar"
                                class="px-3.5 py-1.5 rounded-full bg-white/10 hover:bg-[#B9FF66] text-white hover:text-[#131722] text-xs font-extrabold transition-all"
                            >
                                {{ $t('Tüm Modülleri Yönet') }}
                            </router-link>
                            <button
                                type="button"
                                @click="toggleWidget('plugins_hub')"
                                class="w-7 h-7 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center transition-colors cursor-pointer"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3.5">
                        <div
                            v-for="plg in pluginModules"
                            :key="plg.key"
                            class="p-4 rounded-2xl bg-[#191E2C]/90 border transition-all flex flex-col justify-between gap-3"
                            :class="plg.enabled ? 'border-[#B9FF66]/40 shadow-[0_0_20px_rgba(185,255,102,0.08)]' : 'border-white/[0.06] opacity-75'"
                        >
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-1.5">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase" :class="plg.source === 'plugin' ? 'bg-[#BC5FCF]/20 text-[#E9D5FF]' : 'bg-[#459BBE]/20 text-[#7DD3FC]'">
                                        {{ plg.source === 'plugin' ? 'Harici Plugin' : 'Çekirdek Modül' }} · v{{ plg.version || '1.0' }}
                                    </span>
                                    <span class="w-2.5 h-2.5 rounded-full" :class="plg.enabled ? 'bg-[#B9FF66]' : 'bg-[#64748B]'"></span>
                                </div>
                                <h4 class="text-sm font-black text-white">{{ plg.name }}</h4>
                                <p class="text-[11px] text-[#94A3B8] mt-1 line-clamp-2">{{ plg.description }}</p>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t border-white/[0.06]">
                                <span class="text-[11px] font-bold" :class="plg.enabled ? 'text-[#B9FF66]' : 'text-[#64748B]'">
                                    {{ plg.enabled ? $t('Aktif') : $t('Devre Dışı') }}
                                </span>
                                <button
                                    v-if="!plg.always_on && isAdmin"
                                    type="button"
                                    @click="togglePluginModule(plg)"
                                    class="px-3 py-1 rounded-full text-[11px] font-extrabold transition-all cursor-pointer"
                                    :class="plg.enabled
                                        ? 'bg-white/10 text-white hover:bg-[#C55050]/30 hover:text-[#FFA0A0]'
                                        : 'bg-[#B9FF66] text-[#131722]'"
                                >
                                    {{ plg.enabled ? $t('Çıkar / Kapat') : $t('+ Etkinleştir') }}
                                </button>
                                <span v-else-if="plg.always_on" class="text-[10px] font-bold text-[#64748B]">{{ $t('Sistem Çekirdeği') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== BENTO: SENKRON SAĞLIĞI (SYNC HEALTH) ==================== -->
                <div
                    v-else-if="wId === 'sync_health' && isWidgetVisible('sync_health')"
                    :class="[getColSpanClass('sync_health'), editing ? 'ring-2 ring-dashed ring-[#B9FF66]/70 relative' : 'card-3d-interactive']"
                    :draggable="editing"
                    @dragstart="onWidgetDragStart($event, 'sync_health')"
                    @dragover.prevent="onWidgetDragOver($event, 'sync_health')"
                    @drop="onWidgetDrop($event, 'sync_health')"
                    class="bg-[#212738] rounded-[28px] p-5 sm:p-6 border border-white/[0.07] shadow-[0_14px_34px_-10px_rgba(0,0,0,0.5)] flex flex-col justify-between transition-all duration-300 min-h-[260px]"
                >
                    <WidgetEditBar
                        v-if="editing"
                        widget-id="sync_health"
                        title-key="Sürükle: Senkron Sağlığı"
                        :span="getWidgetSpan('sync_health')"
                        @set-span="setWidgetSpan('sync_health', $event)"
                        @toggle="toggleWidget('sync_health')"
                    />

                    <div class="flex items-center justify-between gap-2 mb-4">
                        <div class="min-w-0">
                            <h2 class="text-lg sm:text-xl font-extrabold text-white tracking-tight">
                                {{ $t('Senkron Sağlığı') }}
                            </h2>
                            <p class="text-[11px] text-[#64748B] mt-0.5">{{ $t('Son işlem sonuçları') }}</p>
                        </div>
                        <span
                            class="px-2.5 py-1 rounded-full text-[10px] font-black border shrink-0"
                            :class="syncStatusTone(syncHealthOverall) === 'ok'
                                ? 'bg-[#4E956A]/25 text-[#B9FF66] border-[#B9FF66]/30'
                                : syncStatusTone(syncHealthOverall) === 'error'
                                    ? 'bg-[#C55050]/20 text-[#FFA0A0] border-[#C55050]/40'
                                    : 'bg-amber-500/15 text-amber-300 border-amber-500/40'"
                        >
                            {{ syncStatusLabel(syncHealthOverall) }}
                        </span>
                    </div>

                    <div v-if="syncHealthItems.length" class="space-y-2">
                        <div
                            v-for="item in syncHealthItems"
                            :key="item.entity"
                            class="flex items-center justify-between gap-2 px-3 py-2 rounded-2xl bg-[#191E2C]/70 border border-white/[0.05]"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-white truncate">{{ syncEntityLabel(item.entity) }}</span>
                                    <span
                                        class="px-1.5 py-0.5 rounded-full text-[10px] font-black border shrink-0"
                                        :class="syncStatusTone(item.status) === 'ok'
                                            ? 'bg-[#4E956A]/25 text-[#B9FF66] border-[#B9FF66]/30'
                                            : syncStatusTone(item.status) === 'error'
                                                ? 'bg-[#C55050]/20 text-[#FFA0A0] border-[#C55050]/40'
                                                : 'bg-amber-500/15 text-amber-300 border-amber-500/40'"
                                    >
                                        {{ syncStatusLabel(item.status) }}
                                    </span>
                                </div>
                                <p v-if="item.summary" class="text-[10px] text-[#64748B] truncate mt-0.5">{{ item.summary }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-[10px] font-semibold text-[#94A3B8]">{{ syncLastRun(item) }}</p>
                                <p v-if="item.duration_ms !== null" class="text-[10px] text-[#64748B]">{{ item.duration_ms }} ms</p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex-1 flex flex-col items-center justify-center text-center py-6">
                        <div class="w-10 h-10 rounded-2xl bg-[#B9FF66]/10 text-[#B9FF66] flex items-center justify-center mb-2">
                            <RefreshCw class="w-5 h-5" />
                        </div>
                        <p class="text-xs font-bold text-white">{{ $t('Henüz senkronizasyon kaydı yok.') }}</p>
                    </div>

                    <div class="pt-2 mt-2 border-t border-white/[0.06] flex items-center justify-between text-[10px] text-[#64748B]">
                        <span>{{ $t('Son çalışmalar entegrasyon günlüğünden okunur.') }}</span>
                        <button
                            type="button"
                            @click="toggleWidget('sync_health')"
                            class="w-6 h-6 rounded-full bg-white/5 hover:bg-[#C55050]/20 text-[#64748B] hover:text-[#FFA0A0] flex items-center justify-center cursor-pointer"
                            :title="$t('Bu Kartı Gizle')"
                        >
                            <X class="w-3 h-3" />
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { t } from '../i18n';
import client from '../api/client';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import WidgetEditBar from '../components/dashboard/WidgetEditBar.vue';
import {
    Plus,
    AlertCircle,
    Settings2,
    Move,
    RotateCcw,
    Wallet,
    CheckCircle2,
    SlidersHorizontal,
    ArrowUpRight,
    TrendingUp,
    Boxes,
    ShoppingCart,
    Clock,
    RefreshCw,
    MoreHorizontal,
    ChevronLeft,
    ChevronRight,
    X,
} from 'lucide-vue-next';
import { formatMoney } from '../utils/format';

const router = useRouter();

const widgets = ref([]);
const catalog = ref([]);
const loading = ref(true);
const errorMsg = ref('');
const editing = ref(false);
const editScope = ref('user');
const draft = ref([]);
const saving = ref(false);
const saveError = ref('');
const saveOk = ref(false);
const isAdmin = ref(false);
const refreshingRates = ref(false);

// Döviz Çevirici Durumu
const converterAmount = ref(100);
const converterCurrency = ref('USD');

// ==================== CANLI SÜRÜKLE & BOYUTLANDIR SİSTEMİ ====================
const STORAGE_KEY = 'mrc_dashboard_hidden_widgets_v2';
const LAYOUT_STORAGE_KEY = 'mrc_dashboard_layout_v2';

const DEFAULT_BENTO_ORDER = [
    'satis_siparisler',
    'due_calendar',
    'yillik_finans',
    'revenue_trend',
    'cash_flow_wave',
    'category_pie',
    'debt_aging',
    'top_customers',
    'hr_applications',
    'fx_rates',
    'low_stock',
    'recent_activity',
    'sync_health',
    'plugins_hub',
];

const DEFAULT_SPANS = {
    satis_siparisler: 8,
    due_calendar: 4,
    yillik_finans: 4,
    revenue_trend: 8,
    cash_flow_wave: 8,
    category_pie: 4,
    debt_aging: 4,
    top_customers: 4,
    hr_applications: 4,
    fx_rates: 4,
    low_stock: 4,
    recent_activity: 4,
    sync_health: 4,
    plugins_hub: 12,
};

const hiddenWidgets = ref([]);
const orderedBentoIds = ref([...DEFAULT_BENTO_ORDER]);
const widgetSpans = ref({ ...DEFAULT_SPANS });
const draggedWidgetId = ref(null);

const isLayoutCustomized = computed(() => {
    if (hiddenWidgets.value.length > 0) return true;
    if (JSON.stringify(orderedBentoIds.value) !== JSON.stringify(DEFAULT_BENTO_ORDER)) return true;
    for (const k of Object.keys(DEFAULT_SPANS)) {
        if (widgetSpans.value[k] !== DEFAULT_SPANS[k]) return true;
    }
    return false;
});

function loadHiddenWidgets() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            hiddenWidgets.value = JSON.parse(raw);
        }
    } catch {
        hiddenWidgets.value = [];
    }

    try {
        const rawLayout = localStorage.getItem(LAYOUT_STORAGE_KEY);
        if (rawLayout) {
            const parsed = JSON.parse(rawLayout);
            if (Array.isArray(parsed.order) && parsed.order.length) {
                const merged = [...parsed.order];
                for (const defId of DEFAULT_BENTO_ORDER) {
                    if (!merged.includes(defId)) merged.push(defId);
                }
                orderedBentoIds.value = merged;
            }
            if (parsed.spans && typeof parsed.spans === 'object') {
                widgetSpans.value = { ...DEFAULT_SPANS, ...parsed.spans };
            }
        }
    } catch {
        orderedBentoIds.value = [...DEFAULT_BENTO_ORDER];
        widgetSpans.value = { ...DEFAULT_SPANS };
    }
}

function saveHiddenWidgets() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(hiddenWidgets.value));
        localStorage.setItem(
            LAYOUT_STORAGE_KEY,
            JSON.stringify({
                order: orderedBentoIds.value,
                spans: widgetSpans.value,
            })
        );
    } catch {
        // ignore
    }
}

function getWidgetSpan(id) {
    return widgetSpans.value[id] || DEFAULT_SPANS[id] || 4;
}

function setWidgetSpan(id, span) {
    widgetSpans.value = { ...widgetSpans.value, [id]: span };
    saveHiddenWidgets();
}

function spanLabel(span) {
    if (span === 4) return '1/3';
    if (span === 6) return '1/2';
    if (span === 8) return '2/3';
    return t('Tam');
}

function getColSpanClass(id) {
    const sp = getWidgetSpan(id);
    if (sp === 12) return 'xl:col-span-12';
    if (sp === 8) return 'xl:col-span-8';
    if (sp === 6) return 'xl:col-span-6';
    return 'xl:col-span-4';
}

function onWidgetDragStart(e, id) {
    draggedWidgetId.value = id;
    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', id);
    }
}

function onWidgetDragOver(e) {
    if (e.dataTransfer) {
        e.dataTransfer.dropEffect = 'move';
    }
}

function onWidgetDrop(e, targetId) {
    const sourceId = draggedWidgetId.value || e.dataTransfer?.getData('text/plain');
    draggedWidgetId.value = null;
    if (!sourceId || sourceId === targetId) return;

    const list = [...orderedBentoIds.value];
    const fromIdx = list.indexOf(sourceId);
    const toIdx = list.indexOf(targetId);
    if (fromIdx === -1 || toIdx === -1) return;

    list.splice(fromIdx, 1);
    list.splice(toIdx, 0, sourceId);
    orderedBentoIds.value = list;
    saveHiddenWidgets();
}

function resetToOriginalLayout() {
    hiddenWidgets.value = [];
    orderedBentoIds.value = [...DEFAULT_BENTO_ORDER];
    widgetSpans.value = { ...DEFAULT_SPANS };
    saveHiddenWidgets();
    draft.value.forEach((d) => { d.enabled = true; });
}

const widgetToggleDefs = [
    { id: 'kpi', title: 'Üst Ciro & İşlem Özeti', subtitle: 'Bütçe ve tamamlanan işlem kapsülleri' },
    { id: 'fx_rates', title: 'Dolar & Euro Döviz Kurları', subtitle: 'Üst USD/EUR barı ve TCMB kur kartı' },
    { id: 'satis_siparisler', title: 'Satış & Siparişler', subtitle: 'Renkli kategori kartları (Projects)' },
    { id: 'due_calendar', title: 'Vade & Ödeme Takvimi', subtitle: 'Aylık vade takvimi (Calendar)' },
    { id: 'yillik_finans', title: 'Bu Yılki Finans', subtitle: '3\'lü neon lime yıllık finans metrikleri' },
    { id: 'revenue_trend', title: 'Yıllık Kâr Analizi', subtitle: '8 aylık kapsül grafik (Yearly profit)' },
    { id: 'cash_flow_wave', title: 'Nakit Akışı Dalga Grafiği', subtitle: 'Giren & çıkan nakit dalga analizi' },
    { id: 'category_pie', title: 'Kategori Pasta Grafiği', subtitle: 'Sektörel ciro dağılımı (Donut)' },
    { id: 'debt_aging', title: 'Alacak & Borç Sütunları', subtitle: 'Vade dilimlerine göre yaşlandırma' },
    { id: 'top_customers', title: 'Lider Cariler', subtitle: 'En yüksek hacimli iş ortakları' },
    { id: 'hr_applications', title: 'İK & Katılım Başvuruları', subtitle: 'CV & başvuru formu özeti' },
    { id: 'low_stock', title: 'Kritik Stok Uyarıları', subtitle: 'Minimum stok altı ürünler' },
    { id: 'recent_activity', title: 'Son İşlemler', subtitle: 'Denetim izi ve son hareketler' },
    { id: 'sync_health', title: 'Senkron Sağlığı', subtitle: 'Son işlem sonuçları' },
    { id: 'plugins_hub', title: 'Modüler Plugin Merkezi', subtitle: 'Tak-çıkar eklenti ve modül yönetimi' },
];

function isWidgetVisible(id) {
    return !hiddenWidgets.value.includes(id);
}

function toggleWidget(id) {
    if (hiddenWidgets.value.includes(id)) {
        hiddenWidgets.value = hiddenWidgets.value.filter((x) => x !== id);
    } else {
        hiddenWidgets.value = [...hiddenWidgets.value, id];
    }
    saveHiddenWidgets();
}

const hiddenWidgetCount = computed(() => hiddenWidgets.value.length);

// Renk Paleti
const PALETTE = {
    cobalt: '#3D55B6',
    orange: '#DC6F31',
    violet: '#BC5FCF',
    forest: '#4E956A',
    coral: '#C55050',
    cyan: '#459BBE',
};

// Üst KPI Değerleri
const topCiroTutari = computed(() => {
    const kpiWidget = widgets.value.find((w) => w.key === 'kpi');
    const ciro = (kpiWidget?.data?.revenue_month || [])[0];
    if (ciro && ciro.total) {
        return formatMoney(ciro.total, ciro.currency);
    }
    return '₺0,00';
});

const topBelgeSayisi = computed(() => {
    const kpiWidget = widgets.value.find((w) => w.key === 'kpi');
    const ciro = (kpiWidget?.data?.revenue_month || [])[0];
    if (ciro && ciro.documents !== undefined && ciro.documents !== null) {
        return String(ciro.documents);
    }
    return '0';
});

// Döviz Kurları (TCMB USD + EUR + GBP)
// Kayıt yoksa UYDURMA kur gösterilmez — liste boş kalır, kullanıcı
// "Kurları Şimdi Güncelle" ile çekebilir.
const fxWidget = computed(() => widgets.value.find((w) => w.key === 'fx_rates'));
const fxRatesList = computed(() => fxWidget.value?.data?.items || []);

const usdRate = computed(() => fxRatesList.value.find((r) => r.currency === 'USD')?.rate || null);
const eurRate = computed(() => fxRatesList.value.find((r) => r.currency === 'EUR')?.rate || null);
const gbpRate = computed(() => fxRatesList.value.find((r) => r.currency === 'GBP')?.rate || null);

function formatKur(val) {
    if (val === null || val === undefined || isNaN(val)) return '—';
    return Number(val).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function refreshRates() {
    refreshingRates.value = true;
    try {
        await client.post('/exchange-rates/fetch');
        await fetchGrid();
    } catch {
        // Mevcut kurları koru
    } finally {
        refreshingRates.value = false;
    }
}

// BENTO 1: Satış & Siparişler (Projects {88}) + Kategori Filtreleme
const projectMenuOpen = ref(false);
const projectFilterOpen = ref(false);
const selectedProjectTag = ref('ALL');
const projectTags = ['ALL', 'Satış', 'Toptan', 'Perakende', 'İhracat', 'Lojistik', 'Hizmet'];

const projectCards = computed(() => {
    const dueWidget = widgets.value.find((w) => w.key === 'due_calendar');
    const cards = [];

    const docs = dueWidget?.data?.items || [];
    if (docs.length > 0) {
        const renkler = [PALETTE.cobalt, PALETTE.orange, PALETTE.violet, PALETTE.forest, PALETTE.coral, PALETTE.cyan];
        const etiketler = ['Satış', 'Toptan', 'Perakende', 'İhracat', 'Lojistik', 'Hizmet'];

        docs.slice(0, 6).forEach((d, idx) => {
            cards.push({
                bg: renkler[idx % renkler.length],
                tag: etiketler[idx % etiketler.length],
                title: d.party_name || `Belge ${d.serial}`,
                subtitle: `${d.serial} · ${t('Vade')}: ${d.due_date}`,
                amount: formatMoney(d.open_amount, d.currency),
                to: '/satislar',
                avatars: [
                    { letter: (d.party_name || 'M')[0].toUpperCase(), bg: '#191E2C' },
                    { letter: 'TR', bg: '#334155' },
                ],
                extraAvatars: idx === 0 ? 12 : idx === 3 ? 4 : idx === 4 ? 2 : null,
            });
        });
        return cards;
    }

    // Hiç belge veya açık sipariş yoksa boş liste döner (uydurma sahte cari eklenmez)
    return [];
});

const filteredProjectCards = computed(() => {
    if (selectedProjectTag.value === 'ALL') return projectCards.value;
    return projectCards.value.filter((c) => c.tag === selectedProjectTag.value);
});

// BENTO 2: İnteraktif Vade Takvimi (Ay Değiştirme & Gün Seçimi)
const calendarOffsetMonths = ref(0);
const onlyDueHighlight = ref(false);
const seciliTakvimGunu = ref(new Date().toISOString().slice(0, 10));

function changeCalendarMonth(delta) {
    calendarOffsetMonths.value += delta;
}

function selectCalendarDay(iso) {
    seciliTakvimGunu.value = iso;
}

const takvimAyi = computed(() => {
    const simdi = new Date();
    const d = new Date(simdi.getFullYear(), simdi.getMonth() + calendarOffsetMonths.value, 1);
    return { yil: d.getFullYear(), ay: d.getMonth() };
});

const takvimAyAdi = computed(() => {
    const adlar = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    return `${adlar[takvimAyi.value.ay]}`;
});

const takvimGunleri = computed(() => {
    const { yil, ay } = takvimAyi.value;
    const ilk = (new Date(yil, ay, 1).getDay() + 6) % 7;
    const sayi = new Date(yil, ay + 1, 0).getDate();
    const gunler = [];
    for (let i = 0; i < ilk; i++) gunler.push(null);
    for (let g = 1; g <= sayi; g++) {
        const iso = `${yil}-${String(ay + 1).padStart(2, '0')}-${String(g).padStart(2, '0')}`;
        gunler.push({ gun: g, iso });
    }
    return gunler;
});

const dueWidgetRef = computed(() => widgets.value.find((w) => w.key === 'due_calendar') || null);

const vadeGunHaritasi = computed(() => {
    const harita = {};
    for (const d of dueWidgetRef.value?.data?.items || []) {
        if (!d.due_date) continue;
        (harita[d.due_date] = harita[d.due_date] || []).push(d);
    }
    return harita;
});

const seciliGunVadeleri = computed(() => {
    return vadeGunHaritasi.value[seciliTakvimGunu.value] || [];
});

function takvimBugunMu(iso) {
    return iso === new Date().toISOString().slice(0, 10);
}

function takvimHalkasi(iso) {
    const renkler = [
        'border-[#3D55B6]',
        'border-[#DC6F31]',
        'border-[#BC5FCF]',
        'border-[#4E956A]',
        'border-[#C55050]',
        'border-[#459BBE]',
    ];
    const gun = parseInt(iso.slice(8), 10);
    return renkler[gun % renkler.length];
}

// BENTO 3: Yıllık Finans Metrikleri
//
// NOT (07 Kas): bu kart daha önce istemcide UYDURMA sayılar gösteriyordu
// ("₺321,339 geçen yıla göre fark", "89,3 cari başına ortalama", "76 yeni cari").
// Artık veri sunucudan gelir (widget: year_finance); kayıt yokken 0 gösterilir.
const yearFinanceWidget = computed(() => widgets.value.find((w) => w.key === 'year_finance'));
const yearFinance = computed(() => yearFinanceWidget.value?.data || {});
const yearFinanceCurrency = computed(() => yearFinance.value.revenue?.[0]?.currency || 'TRY');

const ortalamaIslemDegeri = computed(() => {
    const deger = Number(yearFinance.value.avg_transaction);
    if (deger > 0) {
        return formatMoney(deger, yearFinanceCurrency.value);
    }

    const kpi = widgets.value.find((w) => w.key === 'kpi');
    const ciro = (kpi?.data?.revenue_month || [])[0];
    if (ciro && ciro.total && ciro.documents && ciro.documents > 0) {
        return formatMoney(ciro.total / ciro.documents, ciro.currency);
    }

    return '₺0,00';
});

const gecenYilOrtalamaIslem = computed(() => {
    const deger = Number(yearFinance.value.last_year_avg_transaction);
    return deger > 0 ? formatMoney(deger, yearFinanceCurrency.value) : '—';
});

const cariBasiOrtalama = computed(() => {
    const deger = Number(yearFinance.value.avg_per_account);
    return deger > 0 ? formatMoney(deger, yearFinanceCurrency.value) : '—';
});

const gecenYilCariBasiOrtalama = computed(() => {
    const deger = Number(yearFinance.value.last_year_avg_per_account);
    return deger > 0 ? formatMoney(deger, yearFinanceCurrency.value) : '—';
});

const yeniCariSayisi = computed(() => Number(yearFinance.value.new_accounts || 0));
const gecenYilYeniCari = computed(() => Number(yearFinance.value.last_year_new_accounts || 0));

// BENTO 4: Yıllık Kâr Analizi (Yearly profit {28%})
const profitMode = ref('profit');
function cycleProfitMode() {
    if (profitMode.value === 'profit') profitMode.value = 'revenue';
    else if (profitMode.value === 'revenue') profitMode.value = 'collection';
    else profitMode.value = 'profit';
}

const profitModeLabel = computed(() => {
    if (profitMode.value === 'revenue') return t('Brüt Ciro');
    if (profitMode.value === 'collection') return t('Tahsilat');
    return t('Net Kâr');
});

const karAnaliziBaslik = computed(() => {
    if (profitMode.value === 'revenue') return t('Yıllık Ciro Analizi');
    if (profitMode.value === 'collection') return t('Yıllık Tahsilat Analizi');
    return t('Yıllık Kâr Analizi');
});

const karAnaliziOran = computed(() => {
    const aylar = kapsulBarlari.value;
    if (aylar.length === 0) return '—';

    // Seçili ayın yıl içindeki payı (gerçek veriden).
    const secili = seciliBarObj.value;
    if (!secili) return '—';

    // `tutar` görüntüleme amaçlı biçimlendirilmiş metindir (₺/€ ve ayırıcılar);
    // oran hesabı ham sayısal değerlerden yapılmalı, aksi hâlde NaN% görünür.
    const toplam = aylar.reduce((t, a) => t + Number(a.ham || 0), 0);
    if (! Number.isFinite(toplam) || toplam <= 0) return '—';

    return `${Math.round((Number(secili.ham || 0) / toplam) * 100)}%`;
});

// Ay kapsülleri: son 12 ayın GERÇEK ciro/tahsilat/kâr kırılımı (widget: year_months).
// Önceden istemcide sabit listeler vardı (640 işlem / ₺48,200 gibi).
const yearMonthsWidget = computed(() => widgets.value.find((w) => w.key === 'year_months'));
const yearMonthsList = computed(() => yearMonthsWidget.value?.data?.months || []);

const seciliAy = ref('');

// Ayın baskın para birimi: mutlak cirosu en büyük olan. Tsoft siparişleri
// çoğunlukla EUR olduğundan tek bir ₺ etiketiyle gösterilmesi yanıltıcı olur.
// currencies yoksa/boşsa yıllık finans için kullanılan para birimine düşülür.
function ayinParaBirimi(a) {
    const kirilim = a?.currencies || {};
    let secili = '';
    let enBuyuk = -1;
    for (const [kod, deger] of Object.entries(kirilim)) {
        const mutlak = Math.abs(Number(deger?.revenue || 0));
        if (mutlak > enBuyuk) {
            enBuyuk = mutlak;
            secili = kod;
        }
    }
    return secili || yearFinanceCurrency.value;
}

const kapsulBarlari = computed(() => {
    const aylar = yearMonthsList.value;
    if (aylar.length === 0) return [];

    const alan = profitMode.value === 'revenue'
        ? 'revenue'
        : (profitMode.value === 'collection' ? 'collection' : 'profit');

    const liste = aylar.map((a) => {
        const para = ayinParaBirimi(a);
        return {
            kod: String(a.month || '').slice(5),
            ad: a.label,
            yuzde: 0,
            islem: a.documents || 0,
            tutar: formatMoney(a[alan] || 0, para),
            para,
            ham: a[alan] || 0,
        };
    });

    // Çubuk yüksekliği: serinin en büyük değerine göre oran.
    const enBuyuk = liste.reduce((m, a) => Math.max(m, Number(a.ham || 0)), 0);
    if (enBuyuk > 0) {
        liste.forEach((a) => {
            a.yuzde = Math.max(4, Math.round((Number(a.ham || 0) / enBuyuk) * 100));
        });
    }

    return liste;
});

// Seçili ay: veri gelince içinde bulunulan ay (yoksa son ay).
watch([yearMonthsList, profitMode], () => {
    const liste = kapsulBarlari.value;
    if (liste.length === 0) {
        seciliAy.value = '';
        return;
    }
    const buAy = String(new Date().getMonth() + 1).padStart(2, '0');
    if (!liste.some((b) => b.kod === seciliAy.value)) {
        seciliAy.value = liste.find((b) => b.kod === buAy)?.kod || liste[liste.length - 1].kod;
    }
}, { immediate: true });

const seciliBarObj = computed(() => {
    const liste = kapsulBarlari.value;
    if (liste.length === 0) return null;
    return liste.find((b) => b.kod === seciliAy.value) || liste[liste.length - 1];
});

function stepSelectedMonth(dir) {
    const list = kapsulBarlari.value;
    const idx = list.findIndex((b) => b.kod === seciliAy.value);
    const nextIdx = (idx + dir + list.length) % list.length;
    seciliAy.value = list[nextIdx].kod;
}

const tooltipSolKonum = computed(() => {
    const liste = kapsulBarlari.value;
    const idx = liste.findIndex((m) => m.kod === seciliAy.value);
    if (idx === -1 || liste.length === 0) return 50;
    const genislik = 100 / liste.length;
    return Math.min(92, Math.max(6, (idx + 0.5) * genislik));
});

// Kritik Stok Listesi
const lowStockWidget = computed(() => widgets.value.find((w) => w.key === 'low_stock'));
const lowStockList = computed(() => {
    const items = lowStockWidget.value?.data?.items || [];
    return items.length > 0 ? items : [];
});

// Son İşlemler & Denetim İzi
const recentWidget = computed(() => widgets.value.find((w) => w.key === 'recent_activity'));
const recentActivities = computed(() => recentWidget.value?.data?.items || []);

// Senkron Sağlığı — son entegrasyon sonuçları (widget: sync_health).
const syncHealthWidget = computed(() => widgets.value.find((w) => w.key === 'sync_health'));
const syncHealthOverall = computed(() => syncHealthWidget.value?.data?.overall || 'ok');
const syncHealthItems = computed(() => syncHealthWidget.value?.data?.items || []);

const SYNC_ENTITY_LABELS = {
    orders: 'Siparişler',
    catalog: 'Katalog',
    writeback: 'Geri Yazma',
    translation: 'Çeviri',
    resync: 'Yeniden Senkronizasyon',
};

function syncEntityLabel(entity) {
    return t(SYNC_ENTITY_LABELS[entity] || entity);
}

function syncStatusTone(status) {
    if (['success', 'ok', 'synced', 'done'].includes(status)) return 'ok';
    if (['failed', 'error', 'exception', 'drift'].includes(status)) return 'error';
    return 'warning';
}

function syncStatusLabel(status) {
    const tone = syncStatusTone(status);
    if (tone === 'ok') return t('Başarılı');
    if (tone === 'error') return t('Başarısız');
    return status === 'none' ? t('Beklemede') : t('Uyarı');
}

function syncLastRun(item) {
    if (!item.created_at) return t('Hiç çalışmadı');
    if (typeof item.age_minutes === 'number' && item.age_minutes < 60) {
        return item.age_minutes + ' ' + t('dk önce');
    }
    return item.created_at;
}

// Alacak & Borç Yaşlandırma Sütunları — GERÇEK açık bakiye (widget: debt_aging).
const debtAgingWidget = computed(() => widgets.value.find((w) => w.key === 'debt_aging'));
const debtAgingBars = computed(() => {
    const renkler = ['#B9FF66', '#459BBE', '#DC6F31', '#C55050'];
    const buckets = debtAgingWidget.value?.data?.buckets || [];

    return buckets.map((b, i) => ({
        label: t(b.label_key || ''),
        amount: formatMoney(Number(b.receivable || 0), 'TRY'),
        payable: formatMoney(Number(b.payable || 0), 'TRY'),
        pct: 0,
        raw: Number(b.receivable || 0),
        color: renkler[i % renkler.length],
    }));
});

const debtAgingMax = computed(() => {
    const enBuyuk = Math.max(...debtAgingBars.value.map((b) => b.raw), 0.0001);
    return enBuyuk;
});

// Lider Müşteriler & Cariler — GERÇEK veri (widget: top_customers).
const topCustomersWidget = computed(() => widgets.value.find((w) => w.key === 'top_customers'));
const topCustomersList = computed(() => {
    const items = topCustomersWidget.value?.data?.items || [];
    const renkler = ['#3D55B6', '#DC6F31', '#BC5FCF', '#4E956A', '#459BBE'];

    return items.map((item, idx) => ({
        name: item.name,
        city: item.city || 'Kayıtlı Cari',
        orders: item.documents || 0,
        volume: formatMoney(item.volume || 0, item.currency || 'TRY'),
        color: renkler[idx % renkler.length],
    }));
});

// Kategori Ciro Pasta Analizi — GERÇEK kategori dağılımı (widget: category_mix).
const categoryMixWidget = computed(() => widgets.value.find((w) => w.key === 'category_mix'));
const categoryMixItems = computed(() => {
    const renkler = ['#B9FF66', '#3D55B6', '#DC6F31', '#BC5FCF', '#4E956A', '#459BBE'];
    const items = categoryMixWidget.value?.data?.items || [];

    let offset = 0;

    return items.map((it, i) => {
        const pct = Math.max(0, Math.min(100, Math.round(Number(it.pct || 0))));
        const dilim = {
            name: it.category,
            pct,
            color: renkler[i % renkler.length],
            dasharray: `${pct} ${100 - pct}`,
            dashoffset: -offset,
            total: formatMoney(it.total || 0, 'TRY'),
        };
        offset += pct;
        return dilim;
    });
});

const categoryMixToplam = computed(() => {
    const toplam = categoryMixItems.value.reduce((t, d) => t + d.pct, 0);
    return Math.min(100, Math.round(toplam));
});

// İK & Katılım Başvuruları — GERÇEK başvuru sayıları (widget: hr_applications).
const hrApplicationsWidget = computed(() => widgets.value.find((w) => w.key === 'hr_applications'));
const hrApplications = computed(() => hrApplicationsWidget.value?.data || {});

// Nakit Akışı Dalga Grafiği — son 6 ayın GERÇEK giren/çıkan nakdi (widget: cash_flow).
// Önceden "₺418,920 / ₺194,350" gibi sabit değerler ve elle çizilmiş SVG yolu vardı.
const cashFlowWidget = computed(() => widgets.value.find((w) => w.key === 'cash_flow'));
const cashFlowMonths = computed(() => cashFlowWidget.value?.data?.months || []);
const cashFlowIn = computed(() => Number(cashFlowWidget.value?.data?.total_in || 0));
const cashFlowOut = computed(() => Number(cashFlowWidget.value?.data?.total_out || 0));

/**
 * Seriden yumuşak SVG yolu üretir (Catmull-Rom → cubic bezier).
 * viewBox 600x140; değerler 0..1 aralığına normalize edilir.
 */
function dalgaYolu(degerler, yukseklik = 140) {
    const n = degerler.length;
    if (n === 0) return '';

    const enBuyuk = Math.max(...degerler, 0.0001);
    const noktalar = degerler.map((v, i) => ({
        x: (i * 600) / Math.max(1, n - 1),
        y: yukseklik - 20 - (Math.max(0, v) / enBuyuk) * (yukseklik - 45),
    }));

    if (noktalar.length === 1) {
        const p = noktalar[0];
        return `M0,${p.y.toFixed(1)} L600,${p.y.toFixed(1)}`;
    }

    let d = `M${noktalar[0].x.toFixed(1)},${noktalar[0].y.toFixed(1)}`;

    for (let i = 0; i < noktalar.length - 1; i++) {
        const p0 = noktalar[i - 1] || noktalar[i];
        const p1 = noktalar[i];
        const p2 = noktalar[i + 1];
        const p3 = noktalar[i + 2] || p2;

        const c1x = p1.x + (p2.x - p0.x) / 6;
        const c1y = p1.y + (p2.y - p0.y) / 6;
        const c2x = p2.x - (p3.x - p1.x) / 6;
        const c2y = p2.y - (p3.y - p1.y) / 6;

        d += ` C${c1x.toFixed(1)},${c1y.toFixed(1)} ${c2x.toFixed(1)},${c2y.toFixed(1)} ${p2.x.toFixed(1)},${p2.y.toFixed(1)}`;
    }

    return d;
}

const cashWaveInPath = computed(() => dalgaYolu(cashFlowMonths.value.map((m) => Number(m.in || 0))));
const cashWaveOutPath = computed(() => dalgaYolu(cashFlowMonths.value.map((m) => Number(m.out || 0))));

// Modüler Plugin & Eklenti Merkezi
const pluginModules = ref([]);

async function togglePluginModule(plg) {
    try {
        const nextState = !plg.enabled;
        await client.patch(`/modules/${plg.key}`, { enabled: nextState });
        plg.enabled = nextState;
    } catch {
        // ignore
    }
}

// Düzenleme Fonksiyonları
const scopes = computed(() => {
    const list = [{ value: 'user', key: 'Benim panelim' }];
    if (isAdmin.value) {
        list.push(
            { value: 'role:Admin', key: 'Rol: Admin' },
            { value: 'role:Personel', key: 'Rol: Personel' },
            { value: 'company', key: 'Firma varsayılanı' },
        );
    }
    return list;
});

async function fetchGrid() {
    loading.value = true;
    errorMsg.value = '';
    try {
        const [grid, me, mods] = await Promise.all([
            client.get('/dashboard/widgets'),
            client.get('/me').catch(() => null),
            client.get('/modules').catch(() => null),
        ]);
        widgets.value = grid.data?.data || [];
        const roles = me?.data?.user?.roles || [];
        isAdmin.value = roles.includes('Admin');
        const allMods = mods?.data?.modules || [];
        pluginModules.value = allMods.filter((m) => m.source === 'plugin' || !m.always_on).slice(0, 8);
    } catch (err) {
        errorMsg.value = err.response?.data?.message || t('Panel yüklenemedi.');
    } finally {
        loading.value = false;
    }
}

async function openEditor() {
    saveError.value = '';
    saveOk.value = false;
    try {
        const res = await client.get('/dashboard/widget-catalog');
        catalog.value = res.data?.data || [];
    } catch (err) {
        errorMsg.value = err.response?.data?.message || t('Katalog yüklenemedi.');
        editing.value = false;
        return;
    }
    buildDraft();
}

function buildDraft() {
    const visible = new Map(widgets.value.map((w, i) => [w.key, i]));
    draft.value = catalog.value.map((c, i) => ({
        key: c.key,
        enabled: visible.has(c.key) && !hiddenWidgets.value.includes(c.key),
        sort: orderedBentoIds.value.indexOf(c.key) !== -1
            ? orderedBentoIds.value.indexOf(c.key)
            : (visible.has(c.key) ? visible.get(c.key) : 900 + i),
    })).sort((a, b) => a.sort - b.sort);
}

async function saveLayout() {
    saving.value = true;
    saveError.value = '';
    saveOk.value = false;
    try {
        saveHiddenWidgets();
        buildDraft();
        if (draft.value.length > 0) {
            const rows = draft.value.map((d, i) => ({ key: d.key, enabled: d.enabled, sort: i }));
            if (editScope.value === 'user') {
                await client.put('/dashboard/layout', { widgets: rows });
            } else if (editScope.value === 'company') {
                await client.patch('/dashboard/scopes', {
                    rows: rows.map((r) => ({ scope: 'company', widget_key: r.key, enabled: r.enabled, sort: r.sort })),
                });
            } else if (editScope.value.startsWith('role:')) {
                const role = editScope.value.slice(5);
                await client.patch('/dashboard/scopes', {
                    rows: rows.map((r) => ({ scope: 'role', subject: role, widget_key: r.key, enabled: r.enabled, sort: r.sort })),
                });
            }
        }
        saveOk.value = true;
        await fetchGrid();
    } catch (err) {
        const bag = err.response?.data?.errors || {};
        saveError.value = Object.values(bag).flat()[0]
            || err.response?.data?.message
            || t('Kaydedilemedi.');
    } finally {
        saving.value = false;
    }
}

let catalogLoaded = false;
async function watchEditing() {
    if (editing.value && !catalogLoaded) {
        catalogLoaded = true;
        await openEditor();
    } else if (editing.value) {
        buildDraft();
    }
}

watch(editing, watchEditing);
onMounted(() => {
    loadHiddenWidgets();
    fetchGrid();
});
</script>
