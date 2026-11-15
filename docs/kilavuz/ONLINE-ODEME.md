# Online Ödeme Alma (PayTR / iyzico)

## Bu ekran ne işe yarar?

Müşterinden kartla tahsilat yapmanı sağlar — IBAN yazıp „havale bekleme" devri kapanır.
Faturaya bir **ödeme bağlantısı** üretirsin, müşteriye gönderirsin (WhatsApp/e-posta);
müşteri kendi kartıyla öder, para gelince tahsilat **kendiliğinden** deftere işlenir ve
fatura durumu güncellenir. İki sağlayıcı desteklenir: **PayTR** ve **iyzico**.
Hangisini kullanacağın [Ayarlar](Ayarlar) → **Ödeme Ayarları** kartında hangisini
tanımladığına bağlıdır; ikisini birden tanımlayıp faturadan faturaya seçebilirsin.

## Adım adım

### 1) Hesabı bir kez tanımla (yönetici işi)

1. [Ayarlar](Ayarlar) → **Ödeme Ayarları** kartını aç (yalnız Admin görür).
2. PayTR için **Mağaza numarası / Mağaza anahtarı / Güvenlik parolası (salt)**,
   iyzico için **API anahtarı / Gizli anahtar** gir; **Tahsilat hesabı** seç;
   **Deneme modu** ile başla, hazır olunca kapatıp **Hesap etkin** ile **Kaydet**.
3. Karttaki **bildirim adresini** ödeme kuruluşunun paneline aynen yaz
   (PayTR: mağaza panelindeki **Bildirim URL** alanı).

### 2) Faturaya bağlantı üret

1. [Satislar](Satislar) → **Fatura** sekmesi → ihraç edilmiş faturanın detayını aç
   (teklif/sipariş/taslakta bağlantı bölümü **yoktur**).
2. En alttaki **Ödeme Bağlantıları** → **Yeni Bağlantı** → sağlayıcı seç, tutarı
   kontrol et (varsayılan = faturanın genel toplamı; kısmi tahsilat sonrası kalanını
   yazabilirsin), **Geçerlilik** gününü seç → **Bağlantı Üret**.
3. Beliren adresi **Kopyala** ile alıp müşteriye gönder.

### 3) Müşteri öder, sistem işler

1. Müşteri adresi açar: firma adı, tutar ve ödeme durumunu görür
   (`/odeme/…` sayfası giriş istemez).
2. Kart bilgilerini sağlayıcının sayfasında girer.
3. Ödeme onaylanınca tahsilat deftere işlenir: [Borc-Alacak](Borc-Alacak) ekranında
   `paytr`/`iyzico` yöntemiyle görünür, fatura durumu **Ödendi** (veya kısmi ise
   **Kısmen ödendi**) olur. Cari bakiye aynı anda düşer.
4. Henüz ödenmemiş bağlantıyı **Süresini doldur** ile kapatabilirsin.

## Alan açıklamaları

| Alan | Zorunlu | Boş bırakılırsa | Not |
| --- | --- | --- | --- |
| Sağlayıcı | Evet | Bağlantı üretilmez | Hesabı tanımlı ve etkin olanı seç |
| Tutar | Evet | Bağlantı üretilmez | Faturanın **kalan alacağını** aşamaz |
| Geçerlilik (gün) | Hayır | 7 | En fazla 90; süre dolan bağlantıda ödeme denenemez |
| Tahsilat hesabı (Ayarlar) | Hayır | hesapsız tahsilat | Kasa/Banka ekranında görünmesi için seç |

## Takıldığınızda

- **„PayTR ödeme hesabı tanımlı değil."** → [Ayarlar](Ayarlar) → **Ödeme Ayarları** kartında
  o sağlayıcının alanlarını doldurup **Hesap etkin** ile kaydet.
- **„Link tutarı belgenin kalan alacağından fazla olamaz."** → Fatura kısmen tahsil edilmiş;
  tutarı kalan tutara indir (kalanı detay penceresindeki toplamdan görürsün).
- **„Ödeme linki göndermek için caride geçerli bir e-posta adresi olmalı."** →
  ilgili cariyi [Cariler](Cariler) ekranında güncelle (e-posta ekle). iyzico ayrıca
  **telefon** ister.
- **Müşteri „ödeme sayfası açılamadı" diyor** → Hesap bilgileri yanlış olabilir;
  deneme modundaysan canlı kartla deneme yapılamaz (ve tersi). Ayarlardaki
  **Deneme modu** işaretini kontrol et.
- **Bağlantı „Ödeme alınamadı" durumunda** → Kart/banka reddetti; sebep satırda yazar
  (limit, bakiye, 3D Secure). Yeni bağlantı üretip tekrar gönder.
- **Para geldi ama fatura hâlâ „İhraç" görünüyor** → Bildirim adresi ödeme kuruluşunun
  paneline yanlış/eksik yazılmış olabilir; [Ayarlar](Ayarlar) kartındaki adresle paneldeki
  adresi karşılaştır. Sorun sürerse [HATA-MESAJLARI](HATA-MESAJLARI) tablosuna bak.

## Bilmenizde yarar var

- **Bağlantı üretmek tahsilat değildir:** kayıt, para gerçekten gelince ve sağlayıcının
  bildirimi imzası doğrulanarak kabul edilince doğar. Bu ekranda „ödendi" işaretleme yoktur.
- **İade bu ekrandan yapılmaz:** ödenmiş bağlantı kapatılamaz; iade ödeme kuruluşunun
  panelinden yürütülür.
- **Süre dolumu bilgidir, kapı değildir:** süresi dolmuş bir bağlantıda ödeme
  **tamamlanmışsa** para gerçektir ve tahsilat not düşülerek kaydedilir.
- **Taslak/iptal faturaya, teklife ve siparişe bağlantı açılmaz;** yalnız
  [Satislar](Satislar) faturasına — ve yalnız **kalan alacak** kadar.
- Müşterinin gördüğü sayfa asgari bilgi gösterir (tutarı ve durumu); cari detayları
  ve vergi numarası gibi bilgiler basılmaz.
