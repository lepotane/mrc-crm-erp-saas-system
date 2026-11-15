# MRC — Modern ERP, CRM & Hızlı Satış (POS) SaaS Platformu

[🇬🇧 English Documentation](README.md) | [📖 Kullanıcı Kılavuzu](docs/kilavuz/README.md)

Ön muhasebe, stok ve depo yönetimi, çoklu para birimli faturalama, hızlı satış (POS), cari bakiye takibi ve finansal raporlama için geliştirilmiş kurumsal bulut platformu.

---

## 🌟 Genel Bakış

MRC; üretim, toptan ve perakende ticaret yapan işletmelerin tüm operasyonel ihtiyaçlarını karşılamak üzere tasarlanmış, çok kiracılı (multi-tenant) bir işletme yönetim sistemidir. **Laravel 12**, **Vue 3**, **Vite** ve **Tailwind CSS** teknolojileri ile geliştirilen platform, masaüstü uygulama hızında modern bir tek sayfa uygulaması (SPA) ve kapsamlı REST API mimarisi sunar.

### Temel Yetenekler

- **Çok Kiracılı (Multi-Tenant) Mimari**: Firma bazında tam veri yalıtımı ve rol bazlı erişim yetkilendirmesi (Yönetici, Müdür, Personel, Muhasebe).
- **Satış ve Alım Süreçleri**: Tekliften siparişe, irsaliyeden faturaya ve tedarikçi iadesine kadar uçtan uca resmi evrak akışı.
- **Hızlı Satış (POS)**: Dokunmatik ekran ve barkod okuyucu uyumlu perakende satış arayüzü, termal fiş çıktısı (58mm, 80mm, A5) ve yerel yazıcı köprüsü.
- **Çok Depolu Stok Yönetimi**: Gerçek zamanlı stok hareketleri, depolar arası sevkıyat, sayım mutabakatı ve Ağırlıklı Ortalama Maliyet (AOM) hesaplaması.
- **Çoklu Para Birimi ve Fiyat Sınıfları**: Ürünlere para birimi bazında (TRY, USD, EUR, GBP) bağımsız satış ve alış fiyatları belirleme, TCMB kuru ile otomatik eşitleme.
- **Cari ve Finans Yönetimi**: Müşteri ve tedarikçi bakiyeleri, borç/alacak yaşlandırma raporları, nakit akışı ve banka hesap takibi.
- **Görsel Şablon ve Barkod Etiket Tasarımcısı**: Fatura, irsaliye ve ürün etiketleri için sürükle-bırak görsel tasarım alanı, piksel hassasiyetinde SVG/GD render motoru.
- **Online Ödeme Entegrasyonları**: PayTR ve iyzico ile güvenli ödeme linki üretimi ve webhook tabanlı otomatik tahsilat kapama.
- **Güvenlik ve Uyum**: TOTP iki faktörlü kimlik doğrulama (RFC 6238), aktif oturum/cihaz takibi ve değiştirilemez denetim izleri (audit logs).
- **Otomatik Yedekleme**: Zamanlanmış veritabanı dökümleri ve sıkıştırılmış dosya yedekleme altyapısı.

---

## 🏗️ Teknoloji Mimarisi

| Katman | Teknoloji | Açıklama |
|---|---|---|
| **Backend** | PHP 8.2+ / Laravel 12 | RESTful API (`/api/v1`), Sanctum token ve çerez kimlik doğrulama |
| **Frontend** | Vue 3 / Vite 7 / Tailwind CSS | Tek Sayfa Uygulaması (SPA), Pinia durum yönetimi |
| **Veritabanı** | MySQL 8.0+ / MariaDB 10.6+ | `utf8mb4_unicode_ci`, kiracı kapsamlı yabancı anahtarlar |
| **Belge Motoru** | DomPDF / Native GD | Yüksek çözünürlüklü PDF ve termal barkod grafik motoru |
| **Donanım Köprüsü** | Native ESC/POS Bridge | Yerel termal ve etiket yazıcıları için doğrudan kuyruk protokolü |

---

## 📁 Proje Dizin Yapısı

```
├── docs/
│   └── kilavuz/             # Son kullanıcı ekran kılavuzları ve yardım dokümanları
├── mrcweb/                  # Ana uygulama (Laravel 12 backend + Vue 3 SPA)
│   ├── app/                 # Kontrolcüler, modeller ve servis katmanı
│   ├── config/              # Modüler sistem konfigürasyonu
│   ├── database/            # Veritabanı migrasyonları ve tohumlayıcılar
│   ├── resources/           # Vue 3 SPA bileşenleri, arayüz ve stiller
│   ├── routes/              # API ve web rota tanımları
│   └── tests/               # Kapsamlı otomatik test süiti
├── README.md                # İngilizce dokümantasyon
├── README.tr.md             # Türkçe dokümantasyon
└── .gitignore
```

---

## 🚀 Hızlı Başlangıç (Geliştirme Ortamı)

### Gereksinimler

- PHP 8.2 veya üzeri (`pdo_mysql`, `gd`, `bcmath`, `mbstring`, `zip`, `intl` eklentileri etkin)
- Composer 2.x
- Node.js 20.x veya üzeri & npm
- MySQL 8.0+ veya MariaDB 10.6+

### Kurulum Adımları

1. **Depoyu klonlayın:**
   ```bash
   git clone https://github.com/lepotane/mrc-crm-erp-saas-system.git
   cd mrc-crm-erp-saas-system/mrcweb
   ```

2. **Bağımlılıkları yükleyin:**
   ```bash
   composer install
   npm ci
   ```

3. **Ortam değişkenlerini yapılandırın:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   `.env` dosyasında veritabanı bağlantı bilgilerinizi tanımlayın:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=mrc_veritabani
   DB_USERNAME=kullanici_adiniz
   DB_PASSWORD=parolaniz
   ```

4. **Veritabanını hazırlayın ve ilk yöneticiyi oluşturun:**
   ```bash
   php artisan migrate
   php artisan mrc:create-admin --email=admin@example.com --company="Örnek A.Ş." --password=Parola123!
   ```

5. **Geliştirme sunucularını başlatın:**
   ```bash
   # Terminal 1: Laravel arka plan API sunucusu
   php artisan serve

   # Terminal 2: Vite SPA arayüz sunucusu (Hot Module Reload)
   npm run dev
   ```

---

## 🧪 Test ve Kod Standartları

Proje, çok kiracılı veri yalıtımı ve finansal hesaplama doğruluğunu garanti eden kapsamlı bir otomatik test süiti içerir:

```bash
cd mrcweb

# Otomatik test süitini çalıştır
php artisan test

# Arayüz üretim derlemesini doğrula
npm run build
```

---

## ⚙️ Canlıya Dağıtım (Production)

### 1. Üretim Derlemesi ve Önbellek

```bash
cd mrcweb
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2. Zamanlanmış Görevler (Cron)

Sistem crontab dosyasına Laravel zamanlayıcısını ekleyin:

```crontab
* * * * * cd /uygulama-dizini/mrcweb && php artisan schedule:run >> /dev/null 2>&1
```

Zamanlanmış görevler otomatik olarak şunları yürütür:
- Günlük veritabanı dökümleri ve arşivleme
- TCMB döviz kurlarının periyodik çekilmesi
- Süresi dolan oturum ve yazıcı kuyruklarının temizlenmesi
- Aylık kullanım limitleri ve kotaların yenilenmesi

---

## 📄 Lisans

Bu yazılım ticari mülkiyet lisansı altında dağıtılmaktadır. Tüm hakları saklıdır.
