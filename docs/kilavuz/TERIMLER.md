# Terimler Sözlüğü

Ekranda gördüğün kelimelerin düz karşılığı. Arayüzde hangi kelime geçiyorsa kılavuzda da o kelime
kullanılır (ör. „müşteri hesabı" değil, **cari**).

## Cari ve hesaplar

| Terim | Anlamı |
| --- | --- |
| **Cari** | Sana mal satan veya senden mal alan firma/kişi kaydı. Müşteri ve tedarikçi aynı kayıt türüdür. |
| **Tip: Müşteri / Tedarikçi / Her İkisi** | Cariyi nerede kullanabileceğini belirler. „Her İkisi" seçiliyse aynı cariye hem satış hem alım yapılabilir. |
| **Cari kodu** | Sistemın verdiği sıra no (`00001`). Firma içinde benzersizdir, elle yazılmaz. |
| **Açılış bakiyesi** | Sistemi kullanmaya başladığın tarihte o carinin sana borcu/alacağı. Sonraki hesaplamalara eklenir. |
| **Kredi limiti** | O cariye ne kadar açık verebileceğin. Uyarı amaçlıdır. |
| **İletişim kişisi** | Carinin içindeki yetkili kişi (telefon/e-posta). **Birincil** işaretli kişi varsayılan muhattaptır; bir caride tek birincil olur. |
| **Bakiye** | Carinin sana borcu (+) veya senin ona borcun (−). Faturalar ve tahsilat/ödemelerle hesaplanır. |

## Ürün, birim, kategori

| Terim | Anlamı |
| --- | --- |
| **Ürün / Hizmet** | Satışa konu kalem. Stok takibi kapalıysa hizmet gibi davranır (stok düşmez). |
| **SKU** | Senin verdiğin stok kodu. |
| **Barkod** | Okutularak bulunan kod. |
| **Kategori** | Ürün grubu; listede renk etiketiyle görünür. |
| **Birim** | Adet, kg, litre… Her ürünün bir birimi vardır. |
| **Stok takibi** | Anahtarı kapalıysa o ürünün stoğu sayılmaz; satışta stok düşümü aranmaz. |

## Stok ve maliyet

| Terim | Anlamı |
| --- | --- |
| **Depo / Varsayılan depo** | Malın tutulduğu yer. Yeni hareketlerde varsayılan depo seçili gelir. |
| **Anlık stok** | Depodaki mevcut miktar. Tüm giriş/çıkış/düzeltmelerin toplamıdır. |
| **Kritik stok** | Stoğu „Min. Stok" eşiğinin altına düşmüş ürün. Ürün listesinde uyarıyla gösterilir. |
| **Stok hareketi** | Stoğu değiştiren kayıt. Üç tip: **Giriş** (+), **Çıkış** (−), **Düzeltme** (sayılan gerçek miktar; işareti sen belirlersin). |
| **Düzeltme neden negatif olabilir?** | Örneğin kırık/çalıntı mal için eksi değer girilir; sistem stoğu gerçek sayıya çeker. |
| **Alış fiyatı / Ağırlıklı ortalama maliyet** | Ürünün eline maliyeti. Her alımda yeniden hesaplanır: (eski stok × eski maliyet + gelen miktar × gelen maliyet) ÷ yeni toplam stok. Bu yüzden bir alımdan sonra maliyet değişir. |
| **Maliyet (COGS)** | Bir satış satırının o üründeki maliyeti; fatura kesildiği andaki ortalama maliyet olarak **kayda geçer** ve sonradan değişmez. Kâr hesabının temeli budur. |

## Belgeler (satış / satınalma)

| Terim | Anlamı |
| --- | --- |
| **Belge** | Teklif, sipariş, fatura veya alım faturasının ortak adı. Numarası otomatiktir: `TEK-26-00001` (teklif), `SIP-` (sipariş), `FTR-` (fatura), `ALM-` (alım). Faturanın öneki [Ayarlar](Ayarlar) → **Fatura Seri Öneki** ile değiştirilebilir (örn. `MS-26-00001`). |
| **Taslak** | Henüz kesilmemiş belge. Düzenlenebilir ve silinebilir. |
| **Gönderildi** | Teklifin müşteriye iletildiğini işaretler. **Gönderildi yap** butonuyla geçilir; teklif bu aşamadayken hâlâ iptal edilebilir. |
| **Kabul edildi / Reddedildi** | Gönderilen teklifin sonucu. **Kabul et / Reddet** butonlarıyla verilir; karardan sonra durum kilitlenir, yeni teklif kesmen gerekir. |
| **Onaylandı** | Siparişin kesinleştiği durum (**Onayla** butonu). Stok değişmez; mal faturayla hareket eder. |
| **İhraç** | Fatura/alım faturasının kesinleştiği an. **Bu anda stok düşer (alımda girer)** ve maliyet kayda geçer; artık düzenlenemez ve iptal edilemez. |
| **Ödendi / Kısmen ödendi** | Tahsilat durumunu gösterir. |
| **İptal** | Taslak belgeler ve gönderilmiş ama sonucu bağlanmamış teklifler için geçerlidir; işlem görmüş (ihraç edilmiş) belge iptal edilmez. |
| **Vade tarihi** | Ödemenin beklenlendiği tarih. Vadesi geçenler raporlarda ayrılır. |
| **Dönüştürme** | Teklifin siparişe, siparişin fatraya kopyalanması. Belge numarası yenilenir, eski belge kayıt olarak kalır. |

## Ödemeler

| Terim | Anlamı |
| --- | --- |
| **Tahsilat** | Cari paradan **sana** giren (müşteri ödemesi). |
| **Ödeme** | Senden cariya **çıkan** (tedarikçi ödemesi). |
| **Kasa / Banka hesabı** | Paranın bulunduğu yer (eldeki kasa, banka, kart posu). Tahsilat/ödeme bir hesaba bağlanabilir. |
| **Açık hesap ödemesi** | Belgeye bağlı olmadan yapılan tahsilat/ödeme; carinin bakiyesini düşürür/kapatır. |
| **Kur (döviz kuru)** | 1 birim dövizin kaç TRY ettiği (TCMB alış). Her sabah otomatik çekilir; dövizli belge kesim gününün kuruyla „fotoğraflanır", kur sonradan değişse belge değişmez. |

## Yetki ve hesap

| Terim | Anlamı |
| --- | --- |
| **Firma** | Verilerinin bulunduğu ayrı alan (şube/marksa ayrı firma açabilirsin). İki firmanın verisi birbirine karışmaz. |
| **Rol: Admin** | Her şeyi görür; ayar, kullanıcı ve silme işlemlerini yapabilir. |
| **Rol: Personel** | Günlük işlemleri yapar; firma ayarlarını ve kullanıcıları değiştiremez. |
| **Davet** | Yeni kullanıcı bağlantısı; 7 gün geçerlidir, tek kullanımlıktır. |
| **Dil** | Arayüz ve hata mesajlarının dili (Türkçe / English). Tercihin hesabına kaydedilir. |
