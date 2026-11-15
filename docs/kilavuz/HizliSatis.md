# Hızlı Satış

## Bu ekran ne işe yarar?

Kasada bekleyen müşteriyi oyalamadan satışı bitirirsin: ürünleri sepete atarsın,
ödemeyi alırsın, para üstünü görürsün, fişi yazdırır ya da paylaşırsın. Tek ekranda
fatura + stok düşüşü + tahsilat birlikte olur (ayrı ayrı belge kesmene gerek yok).

Ekran mobil önceliklidir: telefonda kasa gibi kullanılır; barkod okuyucu
klavye gibi yazdığı için arama kutusuna okutup `Enter`'a basman yeter.

## Adım adım

1. Sol menüden **Hızlı Satış** (yıldırım simgesi) ekranını aç.
2. **Müşteri** seç (perakende satışta sabit bir „Perakende" cari açıp hep onu seçebilirsin).
3. **Barkod okut (kameralı cihazda):** **Ürün ara / barkod okut** kutusunun yanındaki
   yeşil tarama düğmesine bas → tam ekran kamera açılır. Barkodu çerçevenin içine tut;
   okuyunca bip sesi + titreşim gelir ve ürün sepete düşer. Işık yetersizse **Fener**
   düğmesi (destekleyen cihazda) yanar. Eski cihazda (iOS Safari) uyumluluk modu
   otomatik devreye girer — biraz daha yavaş okur.
   - Kamera açılmazsa („Kamera izni verilmedi") tarayıcı ayarından izin ver.
     „Güvenli bağlantı" uyarısı görürsen adresin `https` olduğundan emin ol
     (kamera güvensiz bağlantıda çalışmaz; `localhost` denemede serbesttir).
   - Okunmayan barkodu **Elle barkod / kod yazın** kutusuna yaz → **Bul**.
4. Sepette her satırda **adet** (−/+), **birim fiyat** ve **% indirim** kutuları var.
   Toplam (ara toplam − iskonto + KDV) anında güncellenir — ama nihai hesap
   sunucudadır, ekrandaki yalnız önizlemedir.
5. **Ödeme** yöntemini seç: **Nakit / Kart / Banka / Havale-EFT**. Karta/havaleye
   hesaba işlensin istiyorsan **Tahsilat hesabı** seç (seçmezsen hesapsız kaydedilir).
6. Nakit alındıysa **Alınan** kutusuna parayı yaz → **Para üstü** anında çıkar.
   Alınan tutar toplamdan azsa satış **oluşmaz** („Alınan tutar toplamdan az olamaz.").
7. **Satışı Bitir** → fatura kesilir (`issued`), stok düşer, tahsilat işlenir
   (belge `Ödendi` olur). Ekranda **Satış tamamlandı** + fiş no görünür.
8. **Fiş yazdır (80 mm)** → fiş yeni sekmede açılır (termal yazıcıya gönder);
   **58 mm** dar rulo içindir; **A5 PDF** normal yazıcı içindir.
   **Paylaş** → telefonda WhatsApp/mail paylaşım sayfası açılır.
9. Kasada köprü yazıcısı varsa **Köprü yazıcısı** listesinden `Cihaz → Yazıcı` seç →
   **Yazıcıya gönder** → fiş kuyruğa düşer, köprü uygulaması termal yazıcıdan basar.
   Cihaz/yazıcı kurulumu: [Ayarlar](Ayarlar) §10.

## Alan açıklamaları

| Alan | Zorunlu | Boş bırakılırsa | Not |
| --- | --- | --- | --- |
| Müşteri | Evet | Satış bitmez | Fatura bir cariye kesilir; anonim satış yoktur |
| Ürün satırı | Evet (en az 1) | Satış bitmez | Ürünsüz satır adı da yazılabilir (hizmet gibi) |
| Birim fiyat | Evet | Satış bitmez | Ürünün satış fiyatı otomatik gelir, kasada değiştirilebilir |
| İndirim (%) | Hayır | 0 | Satıra uygulanır; toplamda ayrıca gösterilir |
| Tahsilat hesabı | Hayır | Hesapsız kaydedilir | Kart/banka tahsilatının hangi hesaba girdiği bilinmez |
| Alınan | Hayır | Para üstü 0 | Toplamdan az yazılırsa satış oluşmaz |

## Takıldığınızda

- **„Alınan tutar toplamdan az olamaz."** → Müşteriden aldığın parayı eksik yazdın.
  Doğru tutarı yaz ya da kutuyu boş bırak (para üstü 0 olur).
- **„Müşteri seçin" uyarısı** → Satış Bitir düğmesi müşterisiz çalışmaz.
- **Ürün aramada sonuç yok** → En az 2 harf yaz; barkod okuttuysan ürün kartında
  barkod alanının dolu olduğunu [Urunler](Urunler) ekranında kontrol et.
- **Stok yetersiz uyarısı yok ama satış sonrası stok eksiye düştü** → Sistem satışı
  engellemez (negatif stok bu sürümde serbest); sayım farkı [Stok](Stok) → Düzeltme ile kapatılır.
- **Fiş açılmadı / indirilmedi** → Tarayıcının açılır pencere engelleyicisi yeni sekmeyi
  tutmuş olabilir; engeli kaldır ya da düğmeye tekrar bas (fiş yeniden yazdırılabilir).

## Bilmenizde yarar var

- Hızlı satış **fatura** keser: [Satislar](Satislar) listesinde normal fatura gibi görünür,
  raporlara ve KDV özetine girer.
- Fiş, fatura PDF'inden farklıdır: dar rulo (58/80 mm) düzenindedir ve
  **„Bu fiş mali belge yerine geçmez."** notunu taşır.
- Satış anında o günün kuru belgeye işlenir (dövizli satışta [Ayarlar](Ayarlar) §8 kuralı).
- Hızlı Satış menüsü Ayarlar → **Modüller** sekmesinden kapatılabilir;
  kapalıysa ekran adresi „Bu modül kapalı." der.
