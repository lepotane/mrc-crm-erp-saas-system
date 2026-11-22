# MRC iş verisini Tsoft'tan yeniden kurma — çalışma talimatı

Bu dosya **yerel bir çalışma notudur**; herkese açık depoya gönderilmez.

Komut: `php artisan integration:mrc-resync-from-tsoft`

## 0. Güvenlik sözleşmesi (okumadan geçmeyin)

- Tsoft tarafı **salt okunur**: yalnızca `fetchAllProducts()` / `fetchProducts()` ve `fetchOrders()` çağrılır. Dış sisteme fiyat, stok, sipariş, ödeme veya durum **yazılmaz**.
- Varsayılan mod **dry-run**'dur. `--confirm` olmadan hiçbir satır silinmez, hiçbir kayıt yazılmaz ve Tsoft bile çağrılmaz.
- `--confirm` verilmeden silme **asla** yapılmaz. `--dry-run` ve `--confirm` birlikte verilirse yine silme yapılmaz.
- Silmeden önce `db:dump-php` ile yedek alınır; yedek dosyası üretilmezse işlem durur. `--skip-backup` yalnızca yerel geliştirmede kullanılır ve uyarı verilir.
- Silme + içe aktarma **tek transaction** içindedir. Hata olursa değişiklikler geri alınır; yine de yedekten dönüş prosedürü uygulanır.
- Kiracı izolasyonu `company_id` ile sağlanır. `--company` zorunludur.

## 1. Ön koşullar

1. `.env` içinde `DB_CONNECTION=mysql`, `DB_*` değerleri doğru ve `MRC_INTEGRATION_DRY_RUN` biliniyor.
2. Hedef firmanın `tenant_integrations` kaydı (channel = `tsoft`) ve kimlik bilgileri mevcut; adapter okuma yapabiliyor.
3. Yedek dizini yazılabilir: `config('backup.mrc.db_dump.dir')` (varsayılan `storage/app/db-dumps`). Diskte en az `MRC_DB_DUMP_MIN_FREE_MB` boşluk var.
4. `php artisan db:dump-php --keep=0` komutu **elle** bir kez denendi ve `storage/app/db-dumps/mrc-*.sql.gz` dosyası üretildiği doğrulandı.
5. Tsoft'ta okuma denemesi yapıldı; ürün ve son 1 yıllık siparişler erişilebilir durumda.

## 2. Yerel geliştirme adımları

```bash
cd F:\MRC-CRM-ERP-SAAS-SYSTEM\mrcweb

# 1) Planı gör (yazma yok, Tsoft çağrısı yok)
php artisan integration:mrc-resync-from-tsoft --company=1

# 2) Aynı şeyi açıkça dry-run ile
php artisan integration:mrc-resync-from-tsoft --company=1 --dry-run

# 3) Gerçek çalıştırma (yedek alınır, silme + içe aktarma yapılır)
php artisan integration:mrc-resync-from-tsoft --company=1 --confirm

# 4) Sadece yerelde, yedeksiz deneme (UYARI verir; üretimde ASLA)
php artisan integration:mrc-resync-from-tsoft --company=1 --confirm --skip-backup
```

Çıktıda `Mod=CALISTIRILDI` ve `Yedek=alindi: <dosya>` görülmesi gerekir. `Hata` sütunu 0 değilse komut `--confirm` kullanıldığı için `FAILURE` (çıkış kodu 1) döndürür.

## 3. Üretim (`autokeystore.online`) adımları

1. Bakım penceresi belirleyin; sipariş alımını geçici olarak durdurun.
2. Sunucuda `.env` yedeğini alın; `DB_CONNECTION=mysql` doğrulayın.
3. Elle yedek alın ve dosyayı doğrulayın:

```bash
php artisan db:dump-php --keep=0
ls -lh storage/app/db-dumps/*.sql.gz
```

4. Dry-run planını inceleyin; silinecek tablo sayıları makul mü?

```bash
php artisan integration:mrc-resync-from-tsoft --company=<FIRMA_ID> --dry-run
```

5. **Yedek dosyasını indirin / uzak kopyasını alın ve geri yüklenebilir olduğunu doğrulayın.** Bu adım atlanmadan devam etmeyin.
6. Son onay: aşağıdaki kontrol listesini bir yetkili ile birlikte tamamlayın.
7. Gerçek çalıştırma:

```bash
php artisan integration:mrc-resync-from-tsoft --company=<FIRMA_ID> --confirm
```

8. Çıktıyı ve `sync_logs` kaydını (`entity = mrc_resync`) kontrol edin; hata varsa durun ve rollback yapın.
9. Sipariş alımını yeniden açın.

### Üretim öncesi son onay kontrol listesi

- [ ] Doğru firma ID'si doğrulandı (`--company=<FIRMA_ID>`).
- [ ] Yeni yedek dosyası oluştu, boyutu sıfırdan büyük ve **yerel dışında bir kopyası** var.
- [ ] `--dry-run` çıktısındaki silinecek satır sayıları onaylandı.
- [ ] `--skip-backup` kullanılmıyor.
- [ ] `--confirm` yalnızca şu an veriliyor; yanlışlıkla başka firma/hedef DB seçilmedi.
- [ ] Tsoft'a yazma yapılmadığı tekrar hatırlatıldı (salt okunur).
- [ ] Sipariş alımı geçici olarak durduruldu.
- [ ] Geri yükleme komutu hazır (aşağıda) ve sorumlu kişi hazır.
- [ ] **Yetkili kullanıcı, çalıştırmadan hemen önce sözlü/yazılı olarak onay verdi.**

## 4. Silinenler (yalnızca bu firmanın `company_id` satırları)

FK-safe sıra:

1. `document_items`
2. `payments`
3. `stock_movements`
4. `product_prices`
5. `integration_id_map`
6. `integration_stock_state`
7. `sync_diffs`
8. `documents`
9. `products`
10. `contacts`
11. `parties`

**Asla silinmez:** `users`, `companies`, `roles`, `permissions`, `settings`, `print_templates`, `modules`, `audit_events`, `notifications`, `tenant_integrations`, `migrations`, `warehouses`, `units`, `product_categories`, `document_series`.

## 5. Yeniden oluşturulanlar

- **Ürünler**: Tsoft ürün okumasından `products` (SKU, barkod, ad, para birimi, satış fiyatı, vergi oranı).
- **Fiyatlar**: ham ürün fiyat dizilerinden `product_prices` (`PriceSyncService::pull`).
- **Stok**: her ürünün dış stok miktarı **açılış girişi** olarak `stock_movements` + `integration_stock_state` (`ParallelStockEngine::applyInbound`). Bu, işlem bazlı hareket değildir; yalnızca açılış bakiyesidir.
- **Siparişler**: son 1 yıl, en fazla 5000 sipariş; `documents` (taslak satış siparişi) + `document_items`. Faturalandırma daha sonra yapılır.
- **Cariler**: yalnızca siparişlerdeki vergi numaralarından `parties`. Ad/e-posta/telefon/adres uydurulmaz; ad alanı `Tsoft Müşteri` placeholder'dır.

Kişisel veri taşınmaz; loglara yalnızca sayılar yazılır.

## 6. Geri yükleme (rollback)

Transaction geri alma başarısız olduysa veya sonuç kabul edilebilir değilse:

```bash
# Yedek dosyasını sunucuya kopyaladıysanız (örnek)
gunzip -c storage/app/db-dumps/mrc-YYYY-MM-DD-HHMMSS.sql.gz \
  | mysql -u <kullanici> -p <veritabani>
```

Alternatif: dosyayı phpMyAdmin "İçe Aktar" ekranına doğrudan yükleyin (`.sql.gz` desteklenir).

Geri yükleme sonrası:

```bash
php artisan migrate:status
php artisan config:clear && php artisan cache:clear
```

Yedek, `add-drop-table` ile alındığı için geri yükleme eski tabloları düşürüp yeniden kurar; yedek alındıktan sonra oluşan veriler (ör. yeni kullanıcı oturumları) kaybolabilir. Bu nedenle işlem bakım penceresinde yapılır.
