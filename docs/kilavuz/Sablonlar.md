# Yazdırma Şablonları

## Bu ekran ne işe yarar?
Fatura, teklif, sipariş, alım faturası, fiş, irsaliye ve ürün etiketlerinin
görünümünü buradan değiştirirsiniz. Blokları sürükleyip sıralarsınız,
istemediklerinizi göz simgesiyle kapatırsınız, logo yerini ve başlık rengini
seçersiniz; etikette alanları parmağınızla tutup istediğiniz yere
bırakırsınız. Yaptığınız her değişiklik sağdaki canlı önizlemede gerçek PDF
olarak görünür; kaydedince tüm yeni çıktılar o tasarımla basılır.

## Adım adım
1. **Ayarlar** → **Yazdırma Şablonları** → **Tasarımcı** (ya da adres çubuğuna `/sablonlar`).
2. Üstten tür seçin (Fatura, Teklif, Sipariş, Alım faturası, İrsaliye, Fiş, Etiket).
3. Varsa listeden bir şablona tıklayın; yoksa **Yeni şablon** ile başlayın
   (adı yazmanız yeterli, ör. "Sade fatura").
4. Belge türlerinde ortada **tuvali** görürsünüz: gerçek çıktının ta kendisidir
   (PDF değil, yakınlaştırılabilir). Tuvaldeki bir bloğa tıklayın — sağda
   özellikleri açılır. Büyüteç düğmeleriyle yaklaşıp uzaklaşın, **Tuval/PDF**
   düğmesiyle baskı önizlemesine geçin, dışa-okla yeni sekmede açın.
   Soldaki **Öğe ekle** kutusundan sürüklemeden ekleyin: **Metin** (banka IBAN'ı,
   kaşe açıklaması, kargo notu…), **Çizgi** (ayraç), **Boşluk** (yüksekliği
   ayarlanır). Yeni öğe seçili bloğun altına gelir; metnini sağ panele yazın,
   çöp kutusuyla silin.
   Sağdaki **Gezgin** tüm blokları listeler: tıklayıp seçin, sürükleyip
   sıralayın, gözle açıp kapatın. **Seçili öğe** paneli türe göre düzenlenir.
   Logo konumu, Başlık rengi, Yazı boyu (Kompakt/Normal/Geniş), Logoyu göster
   ve Alt bilgi'yi soldan seçin. **Tablo sütunları**ndan istemediğiniz sütunu
   kapatın (ör. İskonto'suz sade liste); **Toplam satırları**ndan ara toplam
   ya da KDV satırını gizleyin (Genel toplam her zaman basılır).
   Etikette: alanı tutup tuvalde istediğiniz yere bırakın, altından yazı
   boyu, hizalama, genişlik, yükseklik, görünürlük ve (isimde) en çok satır
   sayısını ayarlayın; ölçüyü (38×25 / 40×30 / 50×30) seçin.
5. Sağdaki önizleme her değişiklikte kendini yeniler (örnek veriyle).
   Büyüteç düğmesiyle tam ekrana alın ya da dışa-ok düğmesiyle yeni sekmede
   açıp yakınlaştırarak inceleyin.
   Beğenmediyseniz kaydetmeden kapatın; hiçbir şey değişmez.
6. **Kaydet** → türün ilk şablonu otomatikman varsayılan olur. Eski çıktılar
   değişmez; yalnız bundan sonra basılanlar yeni tasarımla çıkar.
7. **Orijinale Dön (Fabrika Varsayılanı):** Tasarım üzerinde yaptığınız değişiklikleri veya sürükle-bırak blok yerleşimini sıfırlamak istediğinizde üst çubuktaki **Orijinale Dön** düğmesine basarak şablonu bozulmamış ilk hâline tek tıkla döndürebilirsiniz.

## Alan açıklamaları
| Alan | Zorunlu | Boş bırakılırsa | Not |
| --- | --- | --- | --- |
| Şablon adı | Evet | Kaydetmez ("Şablon adı gerekli") | Örn. "Logolu fatura" |
| Varsayılan yap | Hayır | — | Türde tek varsayılan olur; çıktı hep onu kullanır |
| Logo konumu | Hayır | Sol | Sağ seçilirse logo başlığın sağ üstüne geçer |
| Başlık rengi | Hayır | Lacivert | Lacivert, Petrol, Bordo, Grafit |
| Alt bilgi | Hayır | Hazır yazı basılır | En çok 140 karakter (fatura: en alt satır; fiş: teşekkür yazısı yerine) |
| Etiket ölçüsü | Hayır | 38×25 | Tasarım ölçüye yapılır; ölçü değişince hazır yerleşim de uyar |
| Fiyat alanı | Hayır | Fiyat basılır | Kapatılırsa etikette fiyat çıkmaz (barkod+kodu kalır) |
| En çok satır (isim) | Hayır | 2 | Uzun ürün adları 1-3 satıra sarılır; kutuya sığmayan satır atılır |
| Yükseklik % | Hayır | Otomatik | Doluysa alan o yüksekliğe kırpılır (barkod boyu buradan verilir) |

## Takıldığınızda
- **"Şablon adı gerekli."** → adı yazıp **Kaydet**'e basın.
- **"Blok yinelenemez." / "Bilinmeyen blok."** → şablon dosyasıyla oynamışsınızdır;
  sayfayı yenileyip yeniden açın (bkz. [HATA-MESAJLARI](HATA-MESAJLARI)).
- **"Metin bloğu 1-500 karakter olmalı."** → boş metin bloğunu doldurun ya da çöp kutusuyla silin.
- **"En az bir görünür alan gereklidir."** (etiket) → en az bir alanın gözü açık olmalı.
- **Önizleme boş geliyor** → önce şablon adını yazıp **Kaydet**'e basın, sonra bakın.
- **"Belgeler modülü kapalı…"** → Ayarlar → Modüller'den **Belgeler**'i açın.

## Bilmenizde yarar var
- Şablon silinirse türün en eski şablonu varsayılan olur; hiç şablon kalmazsa
  sistemin hazır tasarımı basılır — çıktı asla şablonsuz kalmaz.
- Etiket yerleşimi serbesttir ama kutular üst üste binerse üstteki alttakini
  kapatır; önizlemede kontrol edin.
- Barkod okunabilirliği için çizgileri çok daraltmayın; hazır yerleşimler
  tarayıcıda denenmiş ölçülerdir.
- Şablon değişikliği eski basılmış belgeleri değiştirmez; yalnız yeni
  çıktıları etkiler.
