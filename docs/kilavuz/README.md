# MRC — Kullanım Kılavuzu

Bu kılavuz, MRC iş yönetimi sistemini **hiç görmemiş** kullanıcılar içindir. Teknik bilgi varsaymaz;
her ekran ekran anlatılır. Takıldığın yerde ilgili sayfaya bak.

> **Not:** Kılavuz şu an **Türkçe**. İngilizce aynası sonra eklenecek (PLAN Bölüm 13).
> Yayın yeri: GitHub Wiki (repoya alındığında). Kaynak: bu klasör (`docs/kilavuz/`).

## Nereden başlamalı?

| Durumun | Bak |
| --- | --- |
| İlk kez kullanıyorum, boş ekran görüyorum | [HIZLI-BASLANGIC](HIZLI-BASLANGIC) |
| Bir kelimeyi bilmiyorum („cari", „ağırlıklı ortalama"…) | [TERIMLER](TERIMLER) |
| Ekranda bir hata/uyarı mesajı gördüm | [HATA-MESAJLARI](HATA-MESAJLARI) |
| Sürecin tamamını arıyorum (teklif→fatura gibi) | [SUREC-TEKLIF-SATIS](SUREC-TEKLIF-SATIS) · [SUREC-ALIM-STOK](SUREC-ALIM-STOK) |
| Personel ekliyorum / yetki arıyorum | [Kullanicilar-Roller](Kullanicilar-Roller) · [ROL-REHBERI](ROL-REHBERI) |
| „Şöyle olsa ne olur?" diyorum | [SIK-SORULAR](SIK-SORULAR) |

## Süreç ve rehber sayfaları

| Sayfa | Anlatılan | Durum |
| --- | --- | --- |
| [SUREC-TEKLIF-SATIS](SUREC-TEKLIF-SATIS) | Teklif → sipariş → fatura → tahsilat (stok/maliyet ne zaman hareket eder) | ✅ yazıldı |
| [ONLINE-ODEME](ONLINE-ODEME) | PayTR/iyzico hesabı, ödeme bağlantısı, müşteri ödemesi, tahsilatın deftere işlemesi | ✅ yazıldı |
| [SUREC-ALIM-STOK](SUREC-ALIM-STOK) | Alım faturası → stok girişi → ortalama maliyet → tedarikçi ödemesi | ✅ yazıldı |
| [SUREC-BAKIYE-TAKIBI](SUREC-BAKIYE-TAKIBI) | Vade takibi, yaşlandırma, tahsilat/ödeme, kısmi tahsilat, kasa/banka | ✅ yazıldı |
| [ROL-REHBERI](ROL-REHBERI) | Admin ile Personel arasındaki yetki farkları (sunucuda denetlenen kurallar) | ✅ yazıldı |
| [SIK-SORULAR](SIK-SORULAR) | Dil, firma izolasyonu, numaralar, kâr hesabı, çoklu para birimi | ✅ yazıldı |

## Modül sayfaları

| Sayfa | Ne anlatır | Durum |
| --- | --- | --- |
| [Panel](Panel) | Karşılama ekranı, özet haplar, hızlı erişim | ✅ modül kullanımda · ✅ kılavuz yazıldı |
| [Cariler](Cariler) | Müşteri/tedarik hesapları, iletişim kişileri | ✅ modül kullanımda · ✅ kılavuz yazıldı |
| [Urunler](Urunler) | Ürün/hizmet kartları, kategoriler, birimler | ✅ modül kullanımda · ✅ kılavuz yazıldı |
| [Stok](Stok) | Stok hareketleri, depolar, kritik stok | ✅ modül kullanımda · ✅ kılavuz yazıldı |
| [Satislar](Satislar) | Teklif → sipariş → fatura, ihraç, dönüştürme, PDF | ✅ kullanımda · ✅ kılavuz yazıldı |
| [HizliSatis](HizliSatis) | Kasada hızlı satış: sepet, ödeme, para üstü, fiş PDF | ✅ kullanımda · ✅ kılavuz yazıldı |
| [Alimlar](Alimlar) | Alım faturası, stoğun girmesi, maliyetin değişmesi | ✅ kullanımda · ✅ kılavuz yazıldı |
| [Borc-Alacak](Borc-Alacak) | Cari bakiye, tahsilat/ödeme, vade takibi, kasa/banka | ✅ kullanımda · ✅ kılavuz yazıldı |
| [Raporlar](Raporlar) | Ciro, KDV, cari bakiye, stok değeri, stok hareketleri, CSV/Yazdır | ✅ kullanımda · ✅ kılavuz yazıldı |
| [Ayarlar](Ayarlar) | Firma bilgileri, **dil**, para birimi, ayarlar, **API erişim anahtarları** | ✅ modül kullanımda · ✅ kılavuz yazıldı |
| [Kullanicilar-Roller](Kullanicilar-Roller) | Davet, rol, yetki, **daveti kabul etme** | ✅ modül kullanımda · ✅ kılavuz yazıldı |

> Kılavuz, sistemle birlikte büyür: bir modülün ekranı açıldığı fazda o modülün sayfası da
> yazılır (PLAN §10 takip kuralı). Aşağıdaki tüm sayfalar **bugünkü sürümde gördüğün ekranı**
> anlatır; bir özellik henüz yoksa sayfada „bu sürümde yok" diye açıkça yazar.

## Sayfaların ortak yapısı

Her modül sayfası aynı 5 başlıkla yazılır (bu kılavuzda her ekran aynı düzeni izler):

1. **Bu ekran ne işe yarar?**
2. **Adım adım** — buton ve alan adları ekrandakiyle birebir aynı
3. **Alan açıklamaları** — zorunlu alanlar `*` ile işaretli
4. **Takıldığınızda** — o ekranda sık olan durum ve çözümü
5. **Bilmenizde yarar var** — sistem kuralları

## Ekran görüntüleri

Her modül sayfasında o ekranın **gerçek görüntüsü** durur (görseller `images/` klasöründedir,
adları küçük harf + tire). Görüntüler sistemin **çalışan demo verisiyle** çekilir; bu yüzden
üzerlerinde gördüğün adlar, numaralar ve tutarlar örnek firmaya aittir — ekranın **yerleşimini
ve buton adlarını** örnek al, sayıları kendi verin farklı olur.

## Bu kılavuz nasıl güncellenir?

Kılavuz, sistemle aynı depoda ve aynı sürümde durur. Bir ekran değiştiğinde veya ekrana yeni bir
uyarı mesajı eklendiğinde ilgili kılavuz sayfası da aynı gün güncellenir (PLAN §10 takip kuralı).
