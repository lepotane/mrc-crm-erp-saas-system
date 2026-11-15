# Rol Rehberi — Admin ve Personel kim neyi yapabilir?

Sistemde iki rol var: **Admin** ve **Personel**. Yetkiler ekranda gizlenmekle kalmaz,
**sunucuda da denetlenir** — yani yetkisiz bir işlem bağlantısı zorlansa bile engellenir ve
„Bu işlem için yönetici yetkisi gereklidir." mesajı döner.

## Hangi işlem kimde?

### Her iki rol de yapabilir

- **Cari**: müşteri/tedarik kaydı açma, düzenleme, arama, detay, iletişim kişisi ekleme.
- **Ürün & Hizmetler**: ürün kartı, kategori, birim tanımları.
- **Stok**: depo tanımları, **Giriş / Çıkış / Düzeltme** hareketleri, hareket dökümü.
- **Satışlar**: teklif/sipariş/fatura oluşturma, **İhraç et**, **İptal et**, dönüştürme, **PDF indir**,
  taslak belgeleri düzenleme/silme.
- **Alımlar**: alım faturası oluşturma ve ihraç etme.
- **Borç-Alacak**: tahsilat/ödeme kaydetme, ödeme silme, kasa/banka hesapları tanımlama.
- **Raporlar ve Panel**: tüm özetleri görme.
- **Kendi dili**: Ayarlar → **Dil / Language**.

### Yalnız Admin yapabilir

- **Ayarlar → Genel Ayarlar**: para birimi, varsayılan KDV oranı, fatura seri öneki, varsayılan vade.
- **Ayarlar → Firma Bilgileri**: firma adı, vergi no, adres, telefon, e-posta.
- **Ayarlar → Firma Logosu**: logo yükleme/değiştirme.
- **Kullanıcılar & Roller**: davet oluşturma, davet iptali.
- Firma bağlamını yönetme (firma değiştirme/ekleme işlemleri).

> Personel bu alanları **görür** ama kaydedemez; ekran „Bu alan için yönetici yetkisi gerekli."
> uyarısını gösterir ve kaydetme butonu çalışmaz.

## Silme kuralları (rolden bağımsız veri kuralları)

Kaydın tipi „işlem görmüşse" silinemez — bunu Admin de yapamaz:

| Kayıt | Silinemez çünkü |
| --- | --- |
| Cari | Ona bağlı belge/ödeme varsa |
| Ürün | Stok hareketi varsa (ürün kartını düzenle veya „Stok takibi açık"ı kapat) |
| Depo | İçinde stok hareketi varsa (malı taşı, depoyu kullanmayı bırak) |
| Kasa/banka hesabı | Hesapta ödeme kaydı varsa (pasife al) |
| Belge | Yalnız **Taslak** olanlar silinir; ihraç edilen belge silinmez |
| Ödeme | Silinebilir; belge durumu otomatik yeniden hesaplanır |

## Pratik öneriler

- **İş yeri sahibi** Admin kalsın; günlük işleri yürüten ekip üyeleri **Personel** olsun.
- Kasaya ödeme işleyen kişi Personel olabilir; **para birimi/KDV gibi ayarlar** sahibi ilgilendirir.
- Personel yanlış belge kesti ise: taslaktaysa **Sil**, ihraç edildiyse yeni belge ile düzelt
  (fiyat farkı/iskonto satırı). Geçmiş kayıtların silinmesi kasıtlı olarak engellenir.
- Rol değiştirmenin en temiz yolu: eski daveti iptal edip doğru rolle yeniden davet etmek.

## İlgili sayfalar

- [Kullanicilar-Roller](Kullanicilar-Roller) — davet akışı ekran ekran
- [Ayarlar](Ayarlar) — hangi ayar kimin değiştirebilir
- [HATA-MESAJLARI](HATA-MESAJLARI) — yetki uyarılarının anlamı
