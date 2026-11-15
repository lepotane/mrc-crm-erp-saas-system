# MRC Masaüstü — Tauri pilotu (Faz 8)

İnce istemci: bütün iş mantığı sunucuda (API v1), bu program yalnız
tarayıcının yapamadıklarını yapar (cihaz kimliği, yazıcı listesi) +
API'yi tüketen küçük bir arayüz taşır.

## Çalıştırma

Önce API sunucusu ayakta olmalı (`mrcweb` klasöründe `php artisan serve --port=8000`).

```sh
cd mrcdesk
npm install
npm run tauri dev        # geliştirme (canlı yenileme)
npm run tauri build      # kurulumluk paket (src-tauri/target/release/bundle)
```

Gereksinim: Rust stable (MSVC hedefi) + WebView2 (Windows 10/11'de hazır gelir).
İlk derleme bağımlılıkları indirdiği için uzun sürer (5-15 dk), sonrakiler hızlıdır.

## Arayüzde ne var (pilot kapsamı)

- Sunucu adresi ayarı (varsayılan `http://127.0.0.1:8000/api/v1`)
- Giriş (e-posta + şifre; 2FA'lı hesapta 2. adım: telefon kodu)
- Cari listesi (iskelet modül — `GET /parties`)
- Bu cihaz (Rust köprüsü: hostname, OS, mimari, uygulama sürümü)
- Yazıcılar (Rust köprüsü: `Get-Printer` / `lpstat`)
- Çıkış

## Bilinçli pilot sınırları

- Token `localStorage`'da durur. Üretimde sistem anahtarlığına taşınmalı
  (`src/main.js` içinde `TODO(üretim)` olarak işaretli).
- Sessiz baskı (`list_printers` → yazdırma) pilot DIŞI: liste köprüsü kanıtlandı,
  baskı komutu ve sunucu-tarafı kuyruk bir sonraki adım.
- Canlı „program açık" takibi (heartbeat) pilot DIŞI: sunucuda `device_sessions`
  + nabız ucu ister; karar verildiğinde eklenir.
- Önyüz Vanilla JS'tir (bilerek): pilotun işi kabuğu kanıtlamak, ürün arayüzü
  Vue SPA'da kalır. Üretim kararı (Vue'yu Tauri'ye gömmek vs. ayrı önyüz) ayrıca verilir.
