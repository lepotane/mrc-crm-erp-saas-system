<!--
    Yazdırma şablonları tasarımcısı .
    Belge türleri: solda eleman paleti + sayfa ayarları, ortada tıklanabilir
    HTML tuval (gerçek çıktı), sağda navigator + seçili öğe özellikleri.
    Etiket türü: serbest yerleştirme tuvali + canlı PNG önizleme (aynen).
    Önizleme her değişiklikte sunucudan gelir (baskıyla birebir).
-->
<template>
    <div class="space-y-6 max-w-[110rem] mx-auto pb-12">
        <div>
            <div class="flex items-center gap-2 text-xs text-[#94A3B8] mb-1">
                <router-link to="/" class="hover:text-white transition-colors">{{ $t('Dashboard') }}</router-link>
                <span>/</span>
                <span class="text-white font-medium">{{ $t('Yazdırma Şablonları') }}</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">{{ $t('Yazdırma Şablonları') }}</h1>
            <p class="text-xs text-[#94A3B8] mt-1">{{ $t('Fatura, fiş, irsaliye ve etiketlerin görünümünü buradan düzenleyin. Değişiklikler tüm yeni çıktılarda kullanılır.') }}</p>
        </div>

        <div v-if="globalError" class="px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 text-sm">{{ globalError }}</div>
        <div v-if="globalSuccess" class="px-4 py-3 rounded-xl bg-[#B9FF66]/10 border border-[#B9FF66]/30 text-[#B9FF66] text-sm">{{ globalSuccess }}</div>

        <!-- Tür sekmeleri -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="k in TUR_SIRASI"
                :key="k"
                @click="turDegistir(k)"
                class="px-4 py-2 rounded-xl text-xs font-bold transition-colors"
                :class="tur === k ? 'bg-[#B9FF66] text-black' : 'bg-[#212738] text-[#94A3B8] hover:text-white border border-white/10'"
            >{{ turAdi(k) }}</button>
        </div>

        <div v-if="modulKapali" class="p-6 rounded-2xl bg-[#212738] border border-white/10 text-sm text-[#94A3B8]">
            {{ $t('Belgeler modülü kapalı olduğu için şablonlar kullanılamıyor.') }}
        </div>

        <!-- ETİKET: görsel kurucu (ayarlar | tuval | gezgin) -->
        <div v-else-if="etiketTuru" class="grid grid-cols-1 gap-4" :class="onizlemeBuyuk ? '' : 'xl:grid-cols-[248px_minmax(0,1fr)_296px]'">
            <!-- SOL: şablon + ölçü -->
            <Card v-if="!onizlemeBuyuk" rounded="2xl" padding="lg" class="xl:sticky xl:top-4 self-start max-h-[calc(100vh-6rem)] overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-bold text-white">{{ turAdi(tur) }} — {{ $t('Şablonlar') }}</h2>
                    <PillButton size="sm" variant="secondary" @click="yeniSablon">{{ $t('Yeni') }}</PillButton>
                </div>

                <div v-if="sablonlar.length" class="space-y-1.5 mb-3">
                    <button
                        v-for="s in sablonlar"
                        :key="s.id"
                        @click="sablonSec(s)"
                        class="w-full flex items-center gap-2 px-3 py-2 rounded-xl border text-left text-xs transition-colors"
                        :class="aktifId === s.id ? 'border-[#B9FF66]/60 bg-[#B9FF66]/5 text-white' : 'border-white/10 text-[#CBD5E1] hover:bg-white/5'"
                    >
                        <span class="flex-1 font-semibold truncate">{{ s.name }}</span>
                        <span v-if="s.is_default" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#B9FF66]/15 text-[#B9FF66]">★</span>
                        <span v-else @click.stop="varsayilanYap(s)" class="text-[10px] text-[#64748B] hover:text-white">★</span>
                    </button>
                </div>
                <p v-else class="text-xs text-[#64748B] mb-3">{{ $t('Bu türde şablon yok. Kaydedince ilk şablon varsayılan olur.') }}</p>

                <div>
                    <label class="block text-xs text-[#94A3B8] mb-1">{{ $t('Şablon adı') }}</label>
                    <input v-model="taslakAd" type="text" maxlength="80"
                        class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                </div>

                <div class="relative">
                    <label class="block text-xs text-[#94A3B8] mb-1">{{ etiketTuru ? $t('Önizleme ürünü (gerçek adıyla dene)') : $t('Önizleme belgesi (gerçek veriyle dene)') }}</label>
                    <div v-if="(etiketTuru ? onizlemeUrun : onizlemeBelge)" class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[#B9FF66]/10 border border-[#B9FF66]/30 text-xs text-white">
                        <span class="flex-1 font-semibold truncate">{{ etiketTuru ? onizlemeUrun.name : onizlemeBelge.serial + ' · ' + (onizlemeBelge.party_name || '') }}</span>
                        <button type="button" @click="onizlemeSeciminiTemizle" class="text-[#94A3B8] hover:text-white">✕</button>
                    </div>
                    <input v-if="etiketTuru" v-model="aramaUrun"
                        @input="urunAra()"
                        type="text" :placeholder="$t('Yazmaya başlayın… (en az 2 harf)')"
                        class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    <input v-else
                        v-model="aramaBelge"
                        @input="belgeAra()"
                        type="text" :placeholder="$t('Yazmaya başlayın… (en az 2 harf)')"
                        class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    <div v-if="(etiketTuru ? urunSonuclar : belgeSonuclar).length"
                        class="absolute z-30 left-0 right-0 mt-1 rounded-xl border border-white/10 bg-[#131722] shadow-2xl overflow-hidden">
                        <button v-for="o in (etiketTuru ? urunSonuclar : belgeSonuclar)" :key="o.id" type="button" @click="etiketTuru ? urunSec(o) : belgeSec(o)"
                            class="w-full text-left px-3 py-2 text-xs text-[#CBD5E1] hover:bg-white/5 transition-colors">
                            <span class="font-semibold text-white">{{ etiketTuru ? o.name : o.serial }}</span>
                            <span class="text-[#64748B]"> · {{ etiketTuru ? (o.sku || '') : (o.party_name || '') }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mt-3">
                    <label class="block text-xs text-[#94A3B8]">{{ $t('Etiket ölçüsü') }}
                        <select v-model="taslak.size" @change="olcuDegisti"
                            class="mt-1 w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]">
                            <option value="38x25">38 × 25</option>
                            <option value="40x30">40 × 30</option>
                            <option value="50x30">50 × 30</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-white mt-5">
                        <input v-model="taslak.showPrice" @change="onizlemeYenile()" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                        {{ $t('Fiyat alanı') }}
                    </label>
                </div>

                <div v-if="Object.keys(hataAlanlari).length" class="text-xs text-red-300 space-y-1 mt-3">
                    <p v-for="(m, k) in hataAlanlari" :key="k">{{ k }}: {{ Array.isArray(m) ? m.join(', ') : m }}</p>
                </div>

                <div class="flex flex-wrap gap-2 pt-3">
                    <PillButton size="sm" variant="primary" :loading="kaydediliyor" @click="kaydet">{{ $t('Kaydet') }}</PillButton>
                    <button
                        type="button"
                        @click="orijinaleDon"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/15 border border-amber-500/40 text-amber-300 hover:bg-amber-500/25 text-xs font-bold transition-colors cursor-pointer"
                        :title="$t('Bozulan veya değiştirilen tasarımı fabrika ayarlarına döndür')"
                    >
                        <RotateCcw class="w-3.5 h-3.5" />
                        <span>{{ $t('Orijinale Dön') }}</span>
                    </button>
                    <PillButton v-if="aktifId" size="sm" variant="ghost" @click="varsayilanYap(aktifSablon)">{{ $t('Varsayılan') }}</PillButton>
                    <PillButton v-if="aktifId" size="sm" variant="danger" @click="sil">{{ $t('Sil') }}</PillButton>
                </div>

                <!-- Hızlı Etiket Modu Seçici (Barkod / QR / Hibrit) -->
                <div v-if="etiketTuru" class="mt-4 pt-3 border-t border-white/10 space-y-1.5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Hızlı Barkod / QR Yerleşimi') }}</p>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button type="button" @click="etiketModuUygula('barcode')" class="px-2 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[11px] font-bold text-white cursor-pointer">
                            {{ $t('1D Barkod') }}
                        </button>
                        <button type="button" @click="etiketModuUygula('qr')" class="px-2 py-1.5 rounded-lg bg-[#B9FF66]/15 hover:bg-[#B9FF66]/25 border border-[#B9FF66]/40 text-[11px] font-bold text-[#B9FF66] cursor-pointer">
                            {{ $t('QR Kodlu') }}
                        </button>
                        <button type="button" @click="etiketModuUygula('both')" class="px-2 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[11px] font-bold text-white cursor-pointer">
                            {{ $t('Barkod + QR') }}
                        </button>
                    </div>
                </div>
            </Card>

            <!-- ORTA: tuval -->
            <Card rounded="2xl" padding="md" class="min-w-0">
                <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                    <div class="flex items-center gap-1.5">
                        <button type="button" @click="gorunum = 'tuval'"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                            :class="gorunum === 'tuval' ? 'bg-[#B9FF66] text-black' : 'bg-white/5 text-[#94A3B8] hover:text-white border border-white/10'">{{ $t('Tuval') }}</button>
                        <button type="button" @click="gorunum = 'pdf'; pdfIste()"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                            :class="gorunum === 'pdf' ? 'bg-[#B9FF66] text-black' : 'bg-white/5 text-[#94A3B8] hover:text-white border border-white/10'">PDF</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <select v-model="onizlemeDili" @change="onizlemeYenile(true)"
                            class="px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-xs">
                            <option value="tr">TR</option>
                            <option value="en">EN</option>
                        </select>
                        <button type="button" @click="yeniSekmedeAc" :disabled="!onizlemeUrl" :title="$t('Yeni sekmede aç (PDF)')"
                            class="p-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white disabled:opacity-40">
                            <ExternalLink class="w-4 h-4" />
                        </button>
                        <button type="button" @click="onizlemeBuyuk = !onizlemeBuyuk" :title="onizlemeBuyuk ? $t('Tasarımcıya dön') : $t('Tam ekran önizleme')"
                            class="p-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white">
                            <Minimize2 v-if="onizlemeBuyuk" class="w-4 h-4" />
                            <Maximize2 v-else class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <p v-if="gorunum === 'tuval'" class="text-[11px] text-[#64748B] mb-2">{{ $t('Alanları sürükleyerek yerleştirin') }}</p>
                <div v-if="onizlemeYukleniyor" class="h-96 flex items-center justify-center text-xs text-[#64748B]">{{ $t('Hazırlanıyor…') }}</div>

                <div v-show="!onizlemeYukleniyor && gorunum === 'tuval'" ref="tuval"
                    class="relative w-full max-w-[520px] mx-auto rounded-xl border border-white/10 bg-white overflow-hidden select-none"
                    :style="{ aspectRatio: tuvalOran }" @pointermove="alanSurukle" @pointerup="alanBirak" @pointerleave="alanBirak">
                    <div
                        v-for="(f, i) in taslak.fields"
                        v-show="f.visible"
                        :key="f.key"
                        @pointerdown="alanTut($event, i)"
                        class="absolute border text-[10px] leading-tight overflow-hidden cursor-move px-0.5 text-[#111827] font-medium"
                        :class="seciliAlan === i ? 'border-[#B9FF66] bg-[#B9FF66]/30 z-10' : 'border-dashed border-gray-500 bg-gray-100/70'"
                        :style="alanStil(f)"
                    >{{ alanOrnek(f.key) }}</div>
                    <!-- Kılavuz çizgileri (Photoshop mavisi) -->
                    <template v-for="(k, gi) in kilavuzlar" :key="gi">
                        <div v-if="k.o === 'v'" class="absolute top-0 bottom-0 w-px bg-[#3D9BFF] z-20 pointer-events-none" :style="{ left: k.p + '%' }"></div>
                        <div v-else class="absolute left-0 right-0 h-px bg-[#3D9BFF] z-20 pointer-events-none" :style="{ top: k.p + '%' }"></div>
                    </template>
                    <!-- Sürüklerken koordinat rozeti -->
                    <div v-if="suruklenen >= 0 && taslak.fields[suruklenen]"
                        class="absolute z-20 px-1.5 py-0.5 rounded-md bg-[#131722] text-[#B9FF66] text-[10px] font-mono pointer-events-none"
                        :style="{ left: Math.min(82, taslak.fields[suruklenen].x) + '%', top: Math.min(88, taslak.fields[suruklenen].y) + '%' }">
                        x:{{ Math.round(taslak.fields[suruklenen].x) }} y:{{ Math.round(taslak.fields[suruklenen].y) }}
                    </div>
                </div>
                <iframe v-show="!onizlemeYukleniyor && gorunum === 'pdf'" :src="onizlemeUrl"
                    class="w-full h-[52rem] rounded-xl bg-white border border-white/10"></iframe>
            </Card>

            <!-- SAĞ: gezgin + özellikler -->
            <Card v-if="!onizlemeBuyuk" rounded="2xl" padding="lg" class="xl:sticky xl:top-4 self-start max-h-[calc(100vh-6rem)] overflow-y-auto">
                <p class="text-[11px] font-bold uppercase tracking-widest text-[#64748B] mb-2">{{ $t('Gezgin') }}</p>
                <div class="space-y-1 mb-3">
                    <div
                        v-for="(f, i) in taslak.fields"
                        :key="f.key"
                        @click="seciliAlan = i"
                        class="flex items-center gap-2 px-2.5 py-2 rounded-xl border text-xs cursor-pointer transition-colors"
                        :class="[
                            seciliAlan === i ? 'border-[#B9FF66]/60 bg-[#B9FF66]/10 text-white' : 'border-white/10 text-[#CBD5E1] hover:bg-white/5',
                            !f.visible ? 'opacity-50' : '',
                        ]"
                    >
                        <component :is="alanSimge(f.key)" class="w-3.5 h-3.5 text-[#64748B] shrink-0" />
                        <span class="flex-1 font-medium truncate">{{ alanAdi(f.key) }}</span>
                        <button type="button" @click.stop="f.visible = !f.visible; onizlemeYenile()" class="p-0.5" :title="$t('Görünürlük')">
                            <Eye v-if="f.visible" class="w-3.5 h-3.5 text-[#B9FF66]" />
                            <EyeOff v-else class="w-3.5 h-3.5 text-[#64748B]" />
                        </button>
                    </div>
                </div>

                <div v-if="seciliAlanBilgi" class="border-t border-white/[0.08] pt-3">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-[#64748B] mb-2">{{ $t('Seçili alan') }}: {{ alanAdi(seciliAlanBilgi.key) }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-[11px] text-[#94A3B8]">{{ $t('Konum X %') }}
                            <input v-model.number="seciliAlanBilgi.x" @input="onizlemeYenile()" type="number" min="0" max="95" step="0.5"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs" />
                        </label>
                        <label class="text-[11px] text-[#94A3B8]">{{ $t('Konum Y %') }}
                            <input v-model.number="seciliAlanBilgi.y" @input="onizlemeYenile()" type="number" min="0" max="95" step="0.5"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs" />
                        </label>
                        <label class="text-[11px] text-[#94A3B8]">{{ $t('Yazı') }}
                            <input v-model.number="seciliAlanBilgi.font" @input="onizlemeYenile()" type="number" min="6" max="16"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs" />
                        </label>
                        <label class="text-[11px] text-[#94A3B8]">{{ $t('Hiza') }}
                            <select v-model="seciliAlanBilgi.align" @change="onizlemeYenile()"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs">
                                <option value="left">{{ $t('Sol') }}</option>
                                <option value="center">{{ $t('Orta') }}</option>
                                <option value="right">{{ $t('Sağ') }}</option>
                            </select>
                        </label>
                        <label class="text-[11px] text-[#94A3B8]">{{ $t('Genişlik %') }}
                            <input v-model.number="seciliAlanBilgi.w" @input="onizlemeYenile()" type="number" min="5" max="100"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs" />
                        </label>
                        <label class="text-[11px] text-[#94A3B8]">{{ $t('Yükseklik % (0=otomatik)') }}
                            <input v-model.number="seciliAlanBilgi.h" @input="onizlemeYenile()" type="number" min="0" max="100"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs" />
                        </label>
                        <label v-if="seciliAlanBilgi.key === 'name'" class="col-span-2 text-[11px] text-[#94A3B8]">{{ $t('En çok satır') }}
                            <select v-model.number="seciliAlanBilgi.maxLines" @change="onizlemeYenile()"
                                class="mt-1 w-full px-2 py-1.5 bg-black/40 border border-white/10 rounded-lg text-white text-xs">
                                <option :value="1">1</option>
                                <option :value="2">2</option>
                                <option :value="3">3</option>
                            </select>
                        </label>
                    </div>
                </div>
                <p v-else class="text-xs text-[#64748B] border-t border-white/[0.08] pt-3">{{ $t('Özellikler için bir alan seçin.') }}</p>
            </Card>
        </div>

        <!-- BELGE: görsel kurucu (palet | tuval | navigator) -->
        <div v-else class="grid grid-cols-1 gap-4" :class="onizlemeBuyuk ? '' : 'xl:grid-cols-[248px_minmax(0,1fr)_296px]'">
            <!-- SOL: palet + sayfa ayarları -->
            <Card v-if="!onizlemeBuyuk" rounded="2xl" padding="lg" class="xl:sticky xl:top-4 self-start max-h-[calc(100vh-6rem)] overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-bold text-white">{{ $t('Öğeler') }}</h2>
                    <PillButton size="sm" variant="secondary" @click="yeniSablon">{{ $t('Yeni') }}</PillButton>
                </div>
                <div v-if="sablonlar.length" class="space-y-1.5 mb-3">
                    <button
                        v-for="s in sablonlar"
                        :key="s.id"
                        @click="sablonSec(s)"
                        class="w-full flex items-center gap-2 px-3 py-2 rounded-xl border text-left text-xs transition-colors"
                        :class="aktifId === s.id ? 'border-[#B9FF66]/60 bg-[#B9FF66]/5 text-white' : 'border-white/10 text-[#CBD5E1] hover:bg-white/5'"
                    >
                        <span class="flex-1 font-semibold truncate">{{ s.name }}</span>
                        <span v-if="s.is_default" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#B9FF66]/15 text-[#B9FF66]">★</span>
                        <span v-else @click.stop="varsayilanYap(s)" class="text-[10px] text-[#64748B] hover:text-white">★</span>
                    </button>
                </div>

                <div class="mb-3">
                    <label class="block text-xs text-[#94A3B8] mb-1">{{ $t('Şablon adı') }}</label>
                    <input v-model="taslakAd" type="text" maxlength="80"
                        class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                </div>

                <div class="relative mb-3">
                    <label class="block text-xs text-[#94A3B8] mb-1">{{ $t('Önizleme belgesi (gerçek veriyle dene)') }}</label>
                    <div v-if="onizlemeBelge" class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[#B9FF66]/10 border border-[#B9FF66]/30 text-xs text-white">
                        <span class="flex-1 font-semibold truncate">{{ onizlemeBelge.serial }} · {{ onizlemeBelge.party_name || '' }}</span>
                        <button type="button" @click="onizlemeSeciminiTemizle" class="text-[#94A3B8] hover:text-white">✕</button>
                    </div>
                    <input v-else
                        v-model="aramaBelge"
                        @input="belgeAra()"
                        type="text" :placeholder="$t('Belge no / cari ile ara…')"
                        class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    <div v-if="belgeSonuclar.length"
                        class="absolute z-30 left-0 right-0 mt-1 rounded-xl border border-white/10 bg-[#131722] shadow-2xl overflow-hidden max-h-56 overflow-y-auto">
                        <button v-for="o in belgeSonuclar" :key="o.id" type="button" @click="belgeSec(o)"
                            class="w-full text-left px-3 py-2 text-xs text-[#CBD5E1] hover:bg-white/5 transition-colors">
                            <span class="font-semibold text-white">{{ o.serial }}</span>
                            <span class="text-[#64748B]"> · {{ o.party_name || '' }} · {{ o.grand_total }} {{ o.currency_code }}</span>
                        </button>
                    </div>
                </div>

                <p class="text-[11px] font-bold uppercase tracking-widest text-[#64748B] mb-1.5">{{ $t('Öğe ekle') }}</p>
                <div class="grid grid-cols-3 gap-1.5 mb-3">
                    <button type="button" @click="ozelEkle('metin')"
                        class="flex flex-col items-center gap-1 px-1 py-2.5 rounded-xl bg-white/5 border border-white/10 hover:border-[#B9FF66]/50 hover:bg-white/10 transition-colors">
                        <Type class="w-4 h-4 text-[#B9FF66]" />
                        <span class="text-[10px] text-[#CBD5E1]">{{ $t('Metin') }}</span>
                    </button>
                    <button type="button" @click="ozelEkle('cizgi')"
                        class="flex flex-col items-center gap-1 px-1 py-2.5 rounded-xl bg-white/5 border border-white/10 hover:border-[#B9FF66]/50 hover:bg-white/10 transition-colors">
                        <Minus class="w-4 h-4 text-[#B9FF66]" />
                        <span class="text-[10px] text-[#CBD5E1]">{{ $t('Çizgi') }}</span>
                    </button>
                    <button type="button" @click="ozelEkle('bosluk')"
                        class="flex flex-col items-center gap-1 px-1 py-2.5 rounded-xl bg-white/5 border border-white/10 hover:border-[#B9FF66]/50 hover:bg-white/10 transition-colors">
                        <ArrowDownWideNarrow class="w-4 h-4 text-[#B9FF66]" />
                        <span class="text-[10px] text-[#CBD5E1]">{{ $t('Boşluk') }}</span>
                    </button>
                </div>

                <p class="text-[11px] font-bold uppercase tracking-widest text-[#64748B] mb-1.5">{{ $t('Sayfa ayarları') }}</p>
                <div class="space-y-2.5">
                    <label class="block text-xs text-[#94A3B8]">{{ $t('Logo konumu') }}
                        <select v-model="taslak.style.logoPos" @change="onizlemeYenile()"
                            class="mt-1 w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]">
                            <option value="left">{{ $t('Sol') }}</option>
                            <option value="right">{{ $t('Sağ') }}</option>
                        </select>
                    </label>
                    <label class="block text-xs text-[#94A3B8]">{{ $t('Başlık rengi') }}
                        <div class="mt-1.5 flex items-center gap-2">
                            <input
                                v-model="taslak.style.headerColor"
                                @input="onizlemeYenile()"
                                type="color"
                                class="w-9 h-9 rounded-lg border border-white/15 bg-transparent cursor-pointer shrink-0"
                            />
                            <select v-model="taslak.style.headerColor" @change="onizlemeYenile()"
                                class="flex-1 px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]">
                                <option v-for="r in RENKLER" :key="r.v" :value="r.v">{{ $t(r.a) }}</option>
                            </select>
                        </div>
                    </label>
                    <label class="block text-xs text-[#94A3B8]">{{ $t('Yazı boyu') }}
                        <select v-model="taslak.style.fontScale" @change="onizlemeYenile()"
                            class="mt-1 w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-[#B9FF66]">
                            <option value="compact">{{ $t('Kompakt') }}</option>
                            <option value="normal">{{ $t('Normal') }}</option>
                            <option value="large">{{ $t('Geniş') }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-white">
                        <input v-model="taslak.style.logoVisible" @change="onizlemeYenile()" type="checkbox" class="w-4 h-4 accent-[#B9FF66]" />
                        {{ $t('Logoyu göster') }}
                    </label>
                    <label class="block text-xs text-[#94A3B8]">{{ $t('Alt bilgi (boşsa varsayılan yazı)') }}
                        <input v-model="taslak.style.footerText" @input="onizlemeYenile()" type="text" maxlength="140"
                            class="mt-1 w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-sm placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]" />
                    </label>

                    <div v-if="tur !== 'receipt' && tur !== 'delivery'">
                        <p class="text-xs text-[#94A3B8] mb-1.5">{{ $t('Tablo sütunları') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <label v-for="s in SUTUNLAR" :key="s.v" class="flex items-center gap-1 text-[11px] text-white px-2 py-1 rounded-lg bg-white/5 border border-white/10 cursor-pointer">
                                <input v-model="taslak.style.columns[s.v]" @change="onizlemeYenile()" type="checkbox" class="w-3 h-3 accent-[#B9FF66]" />
                                {{ $t(s.a) }}
                            </label>
                        </div>
                        <p class="text-xs text-[#94A3B8] mt-2.5 mb-1.5">{{ $t('Toplam satırları') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <label v-for="s in TOPLAMLAR" :key="s.v" class="flex items-center gap-1 text-[11px] text-white px-2 py-1 rounded-lg bg-white/5 border border-white/10 cursor-pointer">
                                <input v-model="taslak.style.totals[s.v]" @change="onizlemeYenile()" type="checkbox" class="w-3 h-3 accent-[#B9FF66]" />
                                {{ $t(s.a) }}
                            </label>
                        </div>
                    </div>
                </div>

                <div v-if="Object.keys(hataAlanlari).length" class="text-xs text-red-300 space-y-1 mt-3">
                    <p v-for="(m, k) in hataAlanlari" :key="k">{{ k }}: {{ Array.isArray(m) ? m.join(', ') : m }}</p>
                </div>

                <div class="flex flex-wrap gap-2 pt-3">
                    <PillButton size="sm" variant="primary" :loading="kaydediliyor" @click="kaydet">{{ $t('Kaydet') }}</PillButton>
                    <button
                        type="button"
                        @click="orijinaleDon"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/15 border border-amber-500/40 text-amber-300 hover:bg-amber-500/25 text-xs font-bold transition-colors cursor-pointer"
                        :title="$t('Bozulan veya değiştirilen tasarımı fabrika ayarlarına döndür')"
                    >
                        <RotateCcw class="w-3.5 h-3.5" />
                        <span>{{ $t('Orijinale Dön') }}</span>
                    </button>
                    <PillButton v-if="aktifId" size="sm" variant="ghost" @click="varsayilanYap(aktifSablon)">{{ $t('Varsayılan') }}</PillButton>
                    <PillButton v-if="aktifId" size="sm" variant="danger" @click="sil">{{ $t('Sil') }}</PillButton>
                </div>

                <!-- Hızlı Etiket Modu Seçici (Barkod / QR / Hibrit) -->
                <div v-if="etiketTuru" class="mt-4 pt-3 border-t border-white/10 space-y-1.5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#94A3B8]">{{ $t('Hızlı Barkod / QR Yerleşimi') }}</p>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button type="button" @click="etiketModuUygula('barcode')" class="px-2 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[11px] font-bold text-white cursor-pointer">
                            {{ $t('1D Barkod') }}
                        </button>
                        <button type="button" @click="etiketModuUygula('qr')" class="px-2 py-1.5 rounded-lg bg-[#B9FF66]/15 hover:bg-[#B9FF66]/25 border border-[#B9FF66]/40 text-[11px] font-bold text-[#B9FF66] cursor-pointer">
                            {{ $t('QR Kodlu') }}
                        </button>
                        <button type="button" @click="etiketModuUygula('both')" class="px-2 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[11px] font-bold text-white cursor-pointer">
                            {{ $t('Barkod + QR') }}
                        </button>
                    </div>
                </div>
            </Card>

            <!-- ORTA: tuval -->
            <Card rounded="2xl" padding="md" class="min-w-0">
                <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                    <div class="flex items-center gap-1.5">
                        <button type="button" @click="gorunum = 'tuval'"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                            :class="gorunum === 'tuval' ? 'bg-[#B9FF66] text-black' : 'bg-white/5 text-[#94A3B8] hover:text-white border border-white/10'">{{ $t('Tuval') }}</button>
                        <button type="button" @click="gorunum = 'pdf'; pdfIste()"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                            :class="gorunum === 'pdf' ? 'bg-[#B9FF66] text-black' : 'bg-white/5 text-[#94A3B8] hover:text-white border border-white/10'">PDF</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <template v-if="gorunum === 'tuval'">
                            <button type="button" @click="yakUzaklastir" class="p-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white" title="−"><Minus class="w-3.5 h-3.5" /></button>
                            <span class="text-[11px] text-[#94A3B8] w-11 text-center">%{{ Math.round(yak * 100) }}</span>
                            <button type="button" @click="yakYakinlastir" class="p-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white" title="+"><Plus class="w-3.5 h-3.5" /></button>
                            <button type="button" @click="yak = 1" class="px-2 py-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white text-[11px] font-bold">1:1</button>
                        </template>
                        <select v-model="onizlemeDili" @change="onizlemeYenile(true)"
                            class="px-2 py-1.5 bg-[#131722] border border-white/10 rounded-lg text-white text-xs">
                            <option value="tr">TR</option>
                            <option value="en">EN</option>
                        </select>
                        <button type="button" @click="yeniSekmedeAc" :disabled="!onizlemeUrl" :title="$t('Yeni sekmede aç (PDF)')"
                            class="p-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white disabled:opacity-40">
                            <ExternalLink class="w-4 h-4" />
                        </button>
                        <button type="button" @click="onizlemeBuyuk = !onizlemeBuyuk" :title="onizlemeBuyuk ? $t('Tasarımcıya dön') : $t('Tam ekran önizleme')"
                            class="p-1.5 rounded-lg border border-white/10 text-[#94A3B8] hover:text-white">
                            <Minimize2 v-if="onizlemeBuyuk" class="w-4 h-4" />
                            <Maximize2 v-else class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <p v-if="gorunum === 'tuval'" class="text-[11px] text-[#64748B] mb-2">{{ $t('Tuvaldeki bir bloğa tıklayın — sağda özellikleri açılır. Kaydırıp yakınlaştırabilirsiniz.') }}</p>

                <div v-if="onizlemeYukleniyor" class="h-96 flex items-center justify-center text-xs text-[#64748B]">{{ $t('Hazırlanıyor…') }}</div>

                <!-- HTML tuval: gerçek çıktı, tıklanabilir -->
                <div v-show="!onizlemeYukleniyor && gorunum === 'tuval'" class="rounded-xl overflow-auto bg-[#0F1420] border border-white/10 max-h-[62rem]">
                    <div class="mx-auto bg-white my-3 rounded-sm shadow-2xl overflow-hidden" style="width: calc(100% - 1.5rem); aspect-ratio: 1 / 1.35;">
                        <iframe ref="tuvalCer" :srcdoc="onizlemeHtml" @load="tuvalHazir"
                            class="w-full h-full border-0" :style="{ zoom: yak }" title="tuval"></iframe>
                    </div>
                </div>
                <iframe v-show="!onizlemeYukleniyor && gorunum === 'pdf'" :src="onizlemeUrl"
                    class="w-full h-[52rem] rounded-xl bg-white border border-white/10"></iframe>
            </Card>

            <!-- SAĞ: navigator + özellikler -->
            <Card v-if="!onizlemeBuyuk" rounded="2xl" padding="lg" class="xl:sticky xl:top-4 self-start max-h-[calc(100vh-6rem)] overflow-y-auto">
                <p class="text-[11px] font-bold uppercase tracking-widest text-[#64748B] mb-2">{{ $t('Gezgin') }}</p>
                <div class="space-y-1 mb-4">
                    <div
                        v-for="(b, i) in (taslak?.blocks || [])"
                        :key="'nav-' + (b.key === 'ozel' ? 'ozel-' + i : b.key)"
                        draggable="true"
                        @dragstart="surukle = i"
                        @dragover.prevent
                        @drop="birak(i)"
                        @click="seciliBlok = i"
                        class="flex items-center gap-2 px-2.5 py-2 rounded-xl border text-xs cursor-pointer transition-colors"
                        :class="[
                            seciliBlok === i ? 'border-[#B9FF66]/70 bg-[#B9FF66]/10 text-white' : 'border-white/10 text-[#CBD5E1] hover:bg-white/5',
                            !b.visible ? 'opacity-50' : '',
                        ]"
                    >
                        <component :is="blokSimge(b)" class="w-3.5 h-3.5 text-[#64748B] shrink-0" />
                        <span class="flex-1 font-medium truncate">{{ blokAdi(b) }}</span>
                        <button v-if="b.key === 'ozel'" type="button" @click.stop="blokSil(i)" class="p-0.5 text-[#64748B] hover:text-red-400" :title="$t('Sil')">
                            <Trash2 class="w-3.5 h-3.5" />
                        </button>
                        <button type="button" @click.stop="b.visible = !b.visible; onizlemeYenile()" class="p-0.5" :title="$t('Görünürlük')">
                            <Eye v-if="b.visible" class="w-3.5 h-3.5 text-[#B9FF66]" />
                            <EyeOff v-else class="w-3.5 h-3.5 text-[#64748B]" />
                        </button>
                    </div>
                </div>

                <div v-if="seciliBlokBilgi" class="border-t border-white/[0.08] pt-3">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-[#64748B] mb-2">{{ $t('Seçili öğe') }}: {{ blokAdi(seciliBlokBilgi) }}</p>
                    <div v-if="seciliBlokBilgi.key === 'ozel' && (seciliBlokBilgi.tip || 'metin') === 'metin'">
                        <label class="block text-xs text-[#94A3B8] mb-1">{{ $t('Metin') }}</label>
                        <textarea v-model="seciliBlokBilgi.text" @input="onizlemeYenile()" rows="3" maxlength="500" :placeholder="$t('Örn: Banka: TR… / Kaşe açıklaması')"
                            class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-xs placeholder-[#64748B] focus:outline-none focus:ring-2 focus:ring-[#B9FF66]"></textarea>
                    </div>
                    <div v-else-if="seciliBlokBilgi.key === 'ozel' && seciliBlokBilgi.tip === 'bosluk'">
                        <label class="block text-xs text-[#94A3B8] mb-1">{{ $t('Yükseklik (mm)') }}: {{ seciliBlokBilgi.yukseklik || 8 }}</label>
                        <input v-model.number="seciliBlokBilgi.yukseklik" @input="onizlemeYenile()" type="range" min="2" max="30"
                            class="w-full accent-[#B9FF66]" />
                    </div>
                    <p v-else-if="seciliBlokBilgi.key === 'ozel'" class="text-xs text-[#94A3B8]">{{ $t('Ayraç çizgisi — ek ayar yok.') }}</p>
                    <p v-else class="text-xs text-[#94A3B8]">{{ $t('Veri bloğu — içerik belgeden gelir; görünürlüğü ve sırayı değiştirin.') }}</p>
                    <div class="flex gap-2 mt-3">
                        <button type="button" @click="blokTasi(seciliBlok, -1)" :disabled="seciliBlok <= 0" class="flex-1 px-2 py-1.5 rounded-lg bg-white/5 border border-white/10 text-xs text-white disabled:opacity-30">↑</button>
                        <button type="button" @click="blokTasi(seciliBlok, 1)" :disabled="seciliBlokBilgi && seciliBlok >= taslak.blocks.length - 1" class="flex-1 px-2 py-1.5 rounded-lg bg-white/5 border border-white/10 text-xs text-white disabled:opacity-30">↓</button>
                        <button v-if="seciliBlokBilgi.key === 'ozel'" type="button" @click="blokSil(seciliBlok); seciliBlok = -1" class="flex-1 px-2 py-1.5 rounded-lg bg-red-500/10 border border-red-500/30 text-xs text-red-300">{{ $t('Sil') }}</button>
                    </div>
                </div>
                <p v-else class="text-xs text-[#64748B] border-t border-white/[0.08] pt-3">{{ $t('Özellikler için tuvalden ya da gezginden bir öğe seçin.') }}</p>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import {
    GripVertical, Eye, EyeOff, Trash2, ExternalLink, Maximize2, Minimize2,
    Type, Minus, ArrowDownWideNarrow, Plus, LayoutList, Table2, Sigma, StickyNote, PenLine, ReceiptText, ListOrdered,
    Barcode, QrCode, RotateCcw, Tag, Banknote,
} from 'lucide-vue-next';
import Card from '../components/ui/Card.vue';
import PillButton from '../components/ui/PillButton.vue';
import client from '../api/client';
import { t } from '../i18n';

const TUR_SIRASI = ['invoice', 'quote', 'sales_order', 'purchase', 'delivery', 'receipt', 'label'];
const RENKLER = [
    { v: '#1e3a5f', a: 'Lacivert' },
    { v: '#0f766e', a: 'Petrol' },
    { v: '#7c2d12', a: 'Bordo' },
    { v: '#3f3f46', a: 'Grafit' },
];
const SUTUNLAR = [
    { v: 'miktar', a: 'Miktar' },
    { v: 'birim', a: 'Birim' },
    { v: 'birim_fiyat', a: 'Birim fiyat' },
    { v: 'iskonto', a: 'İskonto' },
    { v: 'kdv', a: 'KDV' },
    { v: 'satir_toplam', a: 'Satır toplamı' },
];
const TOPLAMLAR = [
    { v: 'ara_toplam', a: 'Ara toplam' },
    { v: 'iskonto', a: 'İskonto' },
    { v: 'kdv', a: 'KDV' },
];

const tur = ref('invoice');
const sablonlar = ref([]);
const aktifId = ref(null);
const taslakAd = ref('');
const taslak = ref(null);
const surukle = ref(-1);
const seciliAlan = ref(0);
const seciliBlok = ref(-1);
const suruklenen = ref(-1);
const tuval = ref(null);
const tuvalCer = ref(null);
const gorunum = ref('tuval');
const yak = ref(1);
const onizlemeUrl = ref('');
const onizlemeHtml = ref('');
const onizlemeBuyuk = ref(false);
const onizlemeDili = ref('tr');
// Gerçek verili önizleme seçimleri (kaydedilmez; yalnız önizlemeyi besler).
const aramaUrun = ref('');
const urunSonuclar = ref([]);
const onizlemeUrun = ref(null);
const aramaBelge = ref('');
const belgeSonuclar = ref([]);
const onizlemeBelge = ref(null);
let aramaZaman = null;
const onizlemeYukleniyor = ref(false);
const kaydediliyor = ref(false);
const modulKapali = ref(false);
const globalError = ref('');
const globalSuccess = ref('');
const hataAlanlari = ref({});
let zamanlayici = null;

const etiketTuru = computed(() => tur.value === 'label');
const aktifSablon = computed(() => sablonlar.value.find((s) => s.id === aktifId.value) || null);
const seciliAlanBilgi = computed(() => (etiketTuru.value && taslak.value ? taslak.value.fields[seciliAlan.value] : null) || null);
const seciliBlokBilgi = computed(() => (!etiketTuru.value && taslak.value && seciliBlok.value >= 0 ? taslak.value.blocks[seciliBlok.value] : null) || null);
const tuvalOran = computed(() => {
    if (!etiketTuru.value || !taslak.value) return '3 / 2';
    const [en, boy] = (taslak.value.size || '38x25').split('x').map(Number);
    return `${en} / ${boy}`;
});

function turAdi(k) {
    return { invoice: t('Fatura'), quote: t('Teklif'), sales_order: t('Sipariş'), purchase: t('Alım faturası'), delivery: t('İrsaliye'), receipt: t('Fiş'), label: t('Etiket') }[k] || k;
}
function blokAdi(b) {
    if (!b) return '';
    if (b.key === 'ozel') {
        return { metin: t('Serbest metin'), cizgi: t('Çizgi'), bosluk: t('Boşluk') }[b.tip || 'metin'];
    }
    return { parti: t('Cari / Belge bilgileri'), kalemler: t('Satırlar'), toplam: t('Toplamlar'), notlar: t('Notlar'), imza: t('İmza'), odeme: t('Ödeme'), altbilgi: t('Alt bilgi') }[b.key] || b.key;
}
function blokSimge(b) {
    if (!b) return LayoutList;
    if (b.key === 'ozel') return { metin: Type, cizgi: Minus, bosluk: ArrowDownWideNarrow }[b.tip || 'metin'];
    return { parti: LayoutList, kalemler: Table2, toplam: Sigma, notlar: StickyNote, imza: PenLine, odeme: ReceiptText, altbilgi: ListOrdered }[b.key] || LayoutList;
}
function alanAdi(k) {
    return { name: t('Ürün adı'), barcode: t('Barkod (1D)'), qr: t('QR Kod (2D)'), code: t('Kod'), price: t('Fiyat') }[k] || k;
}
function alanSimge(k) {
    return { name: Type, barcode: Barcode, qr: QrCode, code: Tag, price: Banknote }[k] || LayoutList;
}
function alanOrnek(k) {
    // Önizleme ürünü seçiliyse tuvalde GERÇEK adı göster (çıktı sürprizi olmasın).
    if (k === 'name' && onizlemeUrun.value?.name) return onizlemeUrun.value.name;
    return { name: 'Volvo Smart 433MHz', barcode: '||||| || |||', qr: '[QR ▣]', code: '240000000001', price: '1.750,00' }[k] || k;
}

function bosTaslak(k, olcu = '38x25') {
    if (k === 'label') {
        // Ölçüye göre hazır yerleşim (gerçek metrikle sıkı: taşma payı bırakıldı + QR kod alanı).
        const HAZIR = {
            '38x25': [
                { key: 'name', x: 4, y: 3, w: 92, h: 30, font: 9, maxLines: 2, align: 'left', visible: true },
                { key: 'barcode', x: 7, y: 35, w: 86, h: 33, font: 8, align: 'left', visible: true },
                { key: 'qr', x: 68, y: 32, w: 26, h: 36, font: 8, align: 'center', visible: false },
                { key: 'code', x: 4, y: 70, w: 50, h: 0, font: 6, align: 'left', visible: true },
                { key: 'price', x: 56, y: 70, w: 40, h: 0, font: 9, align: 'right', visible: true },
            ],
            '40x30': [
                { key: 'name', x: 4, y: 3, w: 92, h: 26, font: 9, maxLines: 2, align: 'left', visible: true },
                { key: 'barcode', x: 7, y: 31, w: 86, h: 0, font: 8, align: 'left', visible: true },
                { key: 'qr', x: 68, y: 30, w: 26, h: 34, font: 8, align: 'center', visible: false },
                { key: 'code', x: 4, y: 68, w: 50, h: 0, font: 6, align: 'left', visible: true },
                { key: 'price', x: 56, y: 68, w: 40, h: 0, font: 9, align: 'right', visible: true },
            ],
            '50x30': [
                { key: 'name', x: 4, y: 3, w: 92, h: 30, font: 10, maxLines: 2, align: 'left', visible: true },
                { key: 'barcode', x: 7, y: 35, w: 86, h: 33, font: 8, align: 'left', visible: true },
                { key: 'qr', x: 70, y: 33, w: 24, h: 36, font: 8, align: 'center', visible: false },
                { key: 'code', x: 4, y: 78, w: 45, h: 0, font: 7, align: 'left', visible: true },
                { key: 'price', x: 56, y: 78, w: 40, h: 0, font: 11, align: 'right', visible: true },
            ],
        };
        return {
            version: 1, size: olcu, showPrice: true,
            fields: HAZIR[olcu] || HAZIR['38x25'],
        };
    }
    const bloklar = {
        invoice: ['parti', 'kalemler', 'toplam', 'notlar'],
        quote: ['parti', 'kalemler', 'toplam', 'notlar'],
        sales_order: ['parti', 'kalemler', 'toplam', 'notlar'],
        purchase: ['parti', 'kalemler', 'toplam', 'notlar'],
        delivery: ['parti', 'kalemler', 'imza', 'notlar'],
        receipt: ['kalemler', 'toplam', 'odeme', 'altbilgi'],
    }[k] || ['parti', 'kalemler', 'toplam', 'notlar'];
    return {
        version: 1,
        blocks: bloklar.map((key) => ({ key, visible: true })),
        style: {
            logoPos: 'left', headerColor: '#1e3a5f', footerText: '',
            fontScale: 'normal', logoVisible: true,
            columns: { miktar: true, birim: true, birim_fiyat: true, iskonto: true, kdv: true, satir_toplam: true },
            totals: { ara_toplam: true, iskonto: true, kdv: true },
        },
    };
}

function ozelEkle(tip = 'metin') {
    if (!taslak.value || etiketTuru.value) return;
    const yeni = tip === 'metin'
        ? { key: 'ozel', tip, text: '', visible: true }
        : tip === 'bosluk'
            ? { key: 'ozel', tip, yukseklik: 8, visible: true }
            : { key: 'ozel', tip, visible: true };
    const konum = seciliBlok.value >= 0 ? seciliBlok.value + 1 : taslak.value.blocks.length;
    taslak.value.blocks.splice(konum, 0, yeni);
    seciliBlok.value = konum;
    onizlemeYenile();
}

function blokSil(i) {
    if (!taslak.value) return;
    taslak.value.blocks.splice(i, 1);
    if (seciliBlok.value >= taslak.value.blocks.length) seciliBlok.value = -1;
    onizlemeYenile();
}

function blokTasi(i, yon) {
    if (!taslak.value) return;
    const j = i + yon;
    if (j < 0 || j >= taslak.value.blocks.length) return;
    const [b] = taslak.value.blocks.splice(i, 1);
    taslak.value.blocks.splice(j, 0, b);
    seciliBlok.value = j;
    onizlemeYenile();
}

function birak(i) {
    if (surukle.value < 0 || surukle.value === i || !taslak.value) return;
    const [b] = taslak.value.blocks.splice(surukle.value, 1);
    taslak.value.blocks.splice(i, 0, b);
    surukle.value = -1;
    onizlemeYenile();
}

function yakUzaklastir() {
    yak.value = Math.max(0.4, Math.round((yak.value - 0.15) * 100) / 100);
}
function yakYakinlastir() {
    yak.value = Math.min(2.5, Math.round((yak.value + 0.15) * 100) / 100);
}

function pdfIste() {
    if (!onizlemeUrl.value) onizlemeCalistir();
}

// Etiket tuvali: yüzde koordinatla sürükle + kılavuz çizgilerine yapış
// (Photoshop mantığı: kenar/orta çizgileri + tuval ortası eşleşince kilitlenir).
const ESİK = 1.8;
const kilavuzlar = ref([]);

function alanTut(e, i) {
    seciliAlan.value = i;
    suruklenen.value = i;
    e.target.setPointerCapture?.(e.pointerId);
}
function alanSurukle(e) {
    if (suruklenen.value < 0 || !tuval.value || !taslak.value) return;
    const r = tuval.value.getBoundingClientRect();
    if (r.width <= 0 || r.height <= 0) return;
    const f = taslak.value.fields[suruklenen.value];
    f.x = Math.round(Math.min(95, Math.max(0, ((e.clientX - r.left) / r.width) * 100)) * 10) / 10;
    f.y = Math.round(Math.min(95, Math.max(0, ((e.clientY - r.top) / r.height) * 100)) * 10) / 10;
    kilavuzlariHesapla(f, suruklenen.value);
    onizlemeYenile();
}
function alanBirak() {
    suruklenen.value = -1;
    kilavuzlar.value = [];
}
function kilavuzlariHesapla(f, idx) {
    const diger = (taslak.value?.fields || []).filter((o, j) => j !== idx && o.visible !== false);
    const adayX = [0, 50, 100];
    const adayY = [0, 50, 100];
    for (const o of diger) {
        adayX.push(o.x, o.x + o.w / 2, o.x + o.w);
        adayY.push(o.y, o.y + (o.h || 0) / 2, o.y + (o.h || 0));
    }
    const kil = [];
    const ciftX = [f.x, f.x + f.w / 2, f.x + f.w];
    for (const nx of ciftX) {
        const hedef = adayX.find((a) => Math.abs(nx - a) <= ESİK);
        if (hedef !== undefined) {
            f.x = Math.round(Math.min(95, Math.max(0, f.x + (hedef - nx))) * 10) / 10;
            kil.push({ o: 'v', p: hedef });
            break;
        }
    }
    const hh = f.h || 0;
    const ciftY = [f.y, f.y + hh / 2, f.y + hh];
    for (const ny of ciftY) {
        const hedef = adayY.find((a) => Math.abs(ny - a) <= ESİK);
        if (hedef !== undefined) {
            f.y = Math.round(Math.min(95, Math.max(0, f.y + (hedef - ny))) * 10) / 10;
            kil.push({ o: 'h', p: hedef });
            break;
        }
    }
    kilavuzlar.value = kil;
}
function alanStil(f) {
    return {
        left: f.x + '%',
        top: f.y + '%',
        width: Math.max(5, f.w) + '%',
        textAlign: f.align,
        fontSize: Math.max(8, f.font) + 'px',
        fontWeight: f.key === 'name' || f.key === 'price' ? 'bold' : 'normal',
    };
}

function olcuDegisti() {
    if (!aktifId.value && taslak.value) {
        const simdiki = taslak.value.size;
        const onceki = Object.keys({ '38x25': 1, '40x30': 1, '50x30': 1 })
            .find((o) => JSON.stringify((bosTaslak('label', o)).fields) === JSON.stringify(taslak.value.fields));
        if (onceki) {
            taslak.value.fields = bosTaslak('label', simdiki).fields;
            seciliAlan.value = 0;
        }
    }
    onizlemeYenile();
}

function urunAra() {
    clearTimeout(aramaZaman);
    aramaZaman = setTimeout(async () => {
        if ((aramaUrun.value || '').trim().length < 2) { urunSonuclar.value = []; return; }
        try {
            const res = await client.get('/search', { params: { q: aramaUrun.value.trim() } });
            urunSonuclar.value = (res.data?.products || []).slice(0, 6);
        } catch { urunSonuclar.value = []; }
    }, 400);
}

function belgeAra() {
    clearTimeout(aramaZaman);
    aramaZaman = setTimeout(async () => {
        if ((aramaBelge.value || '').trim().length < 2) { belgeSonuclar.value = []; return; }
        try {
            const res = await client.get('/search', { params: { q: aramaBelge.value.trim() } });
            belgeSonuclar.value = (res.data?.documents || []).slice(0, 6);
        } catch { belgeSonuclar.value = []; }
    }, 400);
}

function urunSec(o) {
    onizlemeUrun.value = o;
    aramaUrun.value = '';
    urunSonuclar.value = [];
    onizlemeYenile(true);
}

function belgeSec(o) {
    onizlemeBelge.value = o;
    aramaBelge.value = '';
    belgeSonuclar.value = [];
    onizlemeYenile(true);
}

function onizlemeSeciminiTemizle() {
    onizlemeUrun.value = null;
    onizlemeBelge.value = null;
    aramaUrun.value = '';
    aramaBelge.value = '';
    urunSonuclar.value = [];
    belgeSonuclar.value = [];
    onizlemeYenile(true);
}

function hata(msg) {
    globalError.value = msg;
    globalSuccess.value = '';
    setTimeout(() => { globalError.value = ''; }, 5000);
}
function basari(msg) {
    globalSuccess.value = msg;
    globalError.value = '';
    setTimeout(() => { globalSuccess.value = ''; }, 4000);
}

async function yukle() {
    try {
        const res = await client.get('/print-templates', { params: { kind: tur.value } });
        sablonlar.value = res.data?.data || [];
        modulKapali.value = false;
        const varsayilan = sablonlar.value.find((s) => s.is_default) || sablonlar.value[0];
        if (varsayilan) sablonSec(varsayilan, true);
        else yeniSablon(true);
    } catch (err) {
        if (err.response?.data?.code === 'module_disabled') modulKapali.value = true;
        else hata(err.response?.data?.message || t('Şablonlar yüklenemedi.'));
    }
}

function turDegistir(k) {
    tur.value = k;
    sablonlar.value = [];
    seciliBlok.value = -1;
    onizlemeUrun.value = null;
    onizlemeBelge.value = null;
    aramaUrun.value = '';
    aramaBelge.value = '';
    urunSonuclar.value = [];
    belgeSonuclar.value = [];
    onizlemeUrl.value = '';
    onizlemeHtml.value = '';
    yukle();
}

function sablonSec(s, sessiz = false) {
    aktifId.value = s.id;
    taslakAd.value = s.name;
    taslak.value = JSON.parse(JSON.stringify(s.layout));
    // Eski kayıt uyumu: eksik alanlara güncel varsayılanlar (+ QR alanı yoksa ekle).
    if (taslak.value && tur.value === 'label' && Array.isArray(taslak.value.fields)) {
        for (const f of taslak.value.fields) {
            if (f.maxLines === undefined) f.maxLines = 2;
            if (f.h === undefined) f.h = 0;
        }
        if (!taslak.value.fields.some((f) => f.key === 'qr')) {
            taslak.value.fields.push({ key: 'qr', x: 68, y: 32, w: 26, h: 36, font: 8, maxLines: 1, align: 'center', visible: false });
        }
    }
    if (taslak.value && taslak.value.style) {
        const st = taslak.value.style;
        if (st.fontScale === undefined) st.fontScale = 'normal';
        if (st.logoVisible === undefined) st.logoVisible = true;
        if (st.columns === undefined) st.columns = {};
        if (st.totals === undefined) st.totals = {};
        for (const k of SUTUNLAR.map((x) => x.v)) if (st.columns[k] === undefined) st.columns[k] = true;
        for (const k of TOPLAMLAR.map((x) => x.v)) if (st.totals[k] === undefined) st.totals[k] = true;
        // Eski 'ozel metin' kayıtları yeni tipe taşınır.
        if (Array.isArray(taslak.value.blocks)) {
            for (const b of taslak.value.blocks) {
                if (b.key === 'ozel' && b.tip === undefined) b.tip = 'metin';
                if (b.key === 'ozel' && b.tip === 'bosluk' && b.yukseklik === undefined) b.yukseklik = 8;
            }
        }
    }
    seciliAlan.value = 0;
    seciliBlok.value = -1;
    hataAlanlari.value = {};
    if (!sessiz) onizlemeYenile(true);
    else onizlemeYenile();
}

function yeniSablon(sessiz = false) {
    aktifId.value = null;
    taslakAd.value = '';
    taslak.value = bosTaslak(tur.value, taslak.value?.size || '38x25');
    seciliAlan.value = 0;
    seciliBlok.value = -1;
    hataAlanlari.value = {};
    if (!sessiz) onizlemeYenile(true);
    else onizlemeYenile();
}

function orijinaleDon() {
    taslak.value = bosTaslak(tur.value, taslak.value?.size || '38x25');
    seciliAlan.value = 0;
    seciliBlok.value = -1;
    hataAlanlari.value = {};
    basari(t('Tasarım orijinal fabrika varsayılanına sıfırlandı.'));
    onizlemeYenile(true);
}

function etiketModuUygula(mode) {
    if (!etiketTuru.value || !taslak.value) return;
    if (!taslak.value.fields.some((f) => f.key === 'qr')) {
        taslak.value.fields.push({ key: 'qr', x: 68, y: 32, w: 26, h: 36, font: 8, maxLines: 1, align: 'center', visible: false });
    }
    const bc = taslak.value.fields.find((f) => f.key === 'barcode');
    const qr = taslak.value.fields.find((f) => f.key === 'qr');
    if (mode === 'barcode') {
        if (bc) { bc.visible = true; bc.x = 7; bc.y = 35; bc.w = 86; }
        if (qr) qr.visible = false;
    } else if (mode === 'qr') {
        if (bc) bc.visible = false;
        if (qr) { qr.visible = true; qr.x = 34; qr.y = 30; qr.w = 32; qr.h = 38; }
    } else if (mode === 'both') {
        if (bc) { bc.visible = true; bc.x = 4; bc.y = 35; bc.w = 60; }
        if (qr) { qr.visible = true; qr.x = 68; qr.y = 32; qr.w = 26; qr.h = 36; }
    }
    onizlemeYenile(true);
}

function onizlemeYenile(hemen = false) {
    clearTimeout(zamanlayici);
    if (hemen) {
        onizlemeCalistir();
        return;
    }
    zamanlayici = setTimeout(onizlemeCalistir, 700);
}

async function onizlemeCalistir() {
    if (!taslak.value) return;
    onizlemeYukleniyor.value = true;
    try {
        if (etiketTuru.value) {
            const res = await client.post('/print-templates/preview',
                { kind: tur.value, layout: taslak.value, lang: onizlemeDili.value, product_id: onizlemeUrun.value?.id },
                { responseType: 'blob' });
            if (onizlemeUrl.value.startsWith('blob:')) URL.revokeObjectURL(onizlemeUrl.value);
            onizlemeUrl.value = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
        } else {
            // Tuval (HTML) + PDF paralel: tuval hızlı gelir, PDF arkada hazırlanır.
            const istek = { kind: tur.value, layout: taslak.value, lang: onizlemeDili.value, document_id: onizlemeBelge.value?.id };
            const [h, p] = await Promise.all([
                client.post('/print-templates/preview-html', istek).catch(() => null),
                client.post('/print-templates/preview', istek, { responseType: 'blob' }).catch(() => null),
            ]);
            if (h?.data?.html) {
                onizlemeHtml.value = h.data.html + '<style>[data-blok]{cursor:pointer}[data-blok].mrc-sec{outline:3px solid #B9FF66 !important;outline-offset:3px}</style>';
            }
            if (p?.data) {
                if (onizlemeUrl.value.startsWith('blob:')) URL.revokeObjectURL(onizlemeUrl.value);
                onizlemeUrl.value = URL.createObjectURL(new Blob([p.data], { type: 'application/pdf' }));
            }
        }
    } catch {
        // Önizleme hatası sessizdir; kaydetmede sunucu mesajı gösterilir.
    } finally {
        onizlemeYukleniyor.value = false;
    }
}

// Tuval tıklama-seçim: srcdoc aynı kökten, doğrudan erişilir.
function tuvalHazir() {
    const f = tuvalCer.value;
    if (!f) return;
    try {
        const doc = f.contentDocument;
        if (!doc) return;
        doc.addEventListener('click', (e) => {
            const el = e.target?.closest?.('[data-blok]');
            if (!el) return;
            e.preventDefault();
            seciliBlok.value = Number(el.getAttribute('data-idx'));
            doc.querySelectorAll('[data-blok].mrc-sec').forEach((x) => x.classList.remove('mrc-sec'));
            el.classList.add('mrc-sec');
            el.scrollIntoView({ block: 'nearest' });
        });
    } catch {
        // srcdoc erişilemezse seçim çalışmaz; önizleme yine görünür.
    }
}

function yeniSekmedeAc() {
    if (onizlemeUrl.value) window.open(onizlemeUrl.value, '_blank', 'noopener');
}

async function kaydet() {
    if (!taslakAd.value.trim()) {
        hata(t('Şablon adı gerekli.'));
        return;
    }
    kaydediliyor.value = true;
    hataAlanlari.value = {};
    try {
        const govde = { name: taslakAd.value.trim(), layout: taslak.value };
        let kayit;
        if (aktifId.value) {
            kayit = (await client.patch(`/print-templates/${aktifId.value}`, govde)).data?.data;
        } else {
            govde.kind = tur.value;
            kayit = (await client.post('/print-templates', govde)).data?.data;
        }
        basari(t('Şablon kaydedildi.'));
        await yukle();
        if (kayit) {
            const bulunan = sablonlar.value.find((s) => s.id === kayit.id);
            if (bulunan) sablonSec(bulunan, true);
        }
    } catch (err) {
        hataAlanlari.value = err.response?.data?.errors || {};
        hata(err.response?.data?.message || t('Şablon kaydedilemedi.'));
    } finally {
        kaydediliyor.value = false;
    }
}

async function varsayilanYap(s) {
    if (!s) return;
    try {
        await client.post(`/print-templates/${s.id}/default`);
        basari(t('Varsayılan şablon değiştirildi.'));
        await yukle();
    } catch (err) {
        hata(err.response?.data?.message || t('İşlem yapılamadı.'));
    }
}

async function sil() {
    if (!aktifId.value || !confirm(t('Bu şablon silinsin mi?'))) return;
    try {
        await client.delete(`/print-templates/${aktifId.value}`);
        basari(t('Şablon silindi.'));
        await yukle();
    } catch (err) {
        hata(err.response?.data?.message || t('Şablon silinemedi.'));
    }
}

onMounted(yukle);
onBeforeUnmount(() => {
    clearTimeout(zamanlayici);
    if (onizlemeUrl.value.startsWith('blob:')) URL.revokeObjectURL(onizlemeUrl.value);
});
</script>
