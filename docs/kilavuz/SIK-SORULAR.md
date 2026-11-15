# Sık Sorulan Sorular

## Hesap ve firmalar

**İki firmam var, veriler karışıyor mu?**
Hayır. Her ekran **üstte seçili firmayı** gösterir. Bir firma diğerinin cari/ürün/belge
kayıtlarını göremez. Panel ve rapor sayıları da yalnız seçili firmadır.

**Aynı kişi iki firmada çalışabilir mi?**
Evet. Kullanıcı birden fazla firmanın üyesi olabilir; her seferinde tek firma bağlamında çalışır.

**Kişi sayısı / ekip nasıl büyür?**
[Kullanicilar-Roller](Kullanicilar-Roller) ekranından **Yeni Üye Davet Et**. Rol seçimini davet
ederken yaparsın. Yetki farkları için [ROL-REHBERI](ROL-REHBERI).

## Dil

**Dili nereden değiştiririm?**
[Ayarlar](Ayarlar) → **Dil / Language** → **Türkçe** / **English**.

**Değişince ne olur, ne olmaz?**
Başlıklar, butonlar, menüler, hata mesajları ve **sayı/tarih biçimi** (`30 Eki 2026` ↔
`Oct 30, 2026`, `1.250,50` ↔ `1,250.50`) değişir. Sayfayı yenilemene gerek yoktur.
**Kayıtların kendisi, numaralar ve toplamlar değişmez.**

**Tercih kimin için geçerli?**
Her kullanıcı için ayrıdır (hesabına yazılır). Aynı firmada iki kişi farklı dil kullanabilir.

**İngilizce bazı yazılar hâlâ Türkçe**
O metin için çeviri tanımlı olmayabilir; sistem bilinçli olarak Türkçe'ye düşer (bozuk cümle
üretmez). Ekran görüntüsüyle bildir, çeviri tamamlanır.

## Belgeler ve numaralar

**Belge numarasını değiştirebilir miyim?**
Hayır. Numara otomatiktir: `TEK-26-00001`, `SIP-26-00002`, `FTR-26-00003`, `ALM-26-00001`.
Seri önekini [Ayarlar](Ayarlar) → **Fatura Seri Öneki**nden değiştirebilirsin; **eski belgelerin
numaraları değişmez**, yeni belgeler yeni önekle alınır.

**Yanlış kestiğim belgeyi silebilir miyim?**
Yalnız **Taslak** belgeler silinir. İhraç edilen belge silinmez; yeni belge ile düzelt
(fiyat farkı, iskonto, eksik/fazla alım satırı).

**Teklif doğrudan fatura olabilir mi?**
Hayır: **Teklif → Sipariş → Fatura**. (Küçük işlerde baştan Fatura kesebilirsin.)

**Stok ne zaman düşer?**
Fatura **İhraç** edildiğinde. Teklif ve sipariş stoğu etkilemez.

## Stok ve maliyet

**Stoğu elle düzeltebilir miyim?**
Sayım farkı için [Stok](Stok) → **Manuel Hareket → Düzeltme** (eksi değer serbest: kırık, fire).
Alım/satış hareketlerini elle yazma — belgeler zaten kendiliğinden işler, çift kayıt olur.

**Ürün kartındaki „Alış" fiyatı ne?**
Ürünün **ağırlıklı ortalama maliyeti**. Her alım ihraç edildikçe güncellenir; elle de değiştirilebilir.

**Kâr nasıl hesaplanıyor?**
`kâr = net satış (satır tutarı − iskonto) − (ürün satırlarının birim maliyeti × miktarı)`.
Satır maliyeti, satış faturası kesildiği andaki ortalama maliyettir ve **bir daha değişmez**.

**Stok eksi görünüyor, yanlış mı?**
Sistem satışı engellemez; eksi stok „borç mal" demektir. Gerçeğe çekmek için **Düzeltme** yap.

## Borç, alacak, ödeme

**Bakiyede „+850" görünüyor — kim kime borçlu?**
Pozitif bakiye **bizim alacağımız** (müşteri bize borçlu). Negatif bakiye **bizim borcumuz**.

**Tahsilatı nereye yazarım?**
[Borç-Alacak](Borc-Alacak) → **Tahsilat Al**. Dilersen belirli bir faturaya bağla; faturanın durumu
**Kısmen ödendi / Ödendi** olarak kendiliğinden değişir.

**Vadesi geçen alacaklarımı nasıl görürüm?**
Yaşlandırma (**Vadesi Gelmemiş · 1-30 · 31-60 · 61-90 · 90+**) hem cari detayında hem
[Borç-Alacak](Borc-Alacak) ekranındadır. Vade tarihini belgede **Vade** alanından girersin.

**Birden fazla para birimi kullanabilir miyim?**
Belge ve ödeme para birimi seçilebilir; tutunmalar **para birimi bazında** gösterilir.
Farklı para birimlerini **toplama/çevirme** desteği sonraki sürümde gelecektir; bu yüzden
özetlerde „tutarlar para birimi bazında ayrıdır" notu yer alır.

**Kasam/banka hesabım için ne yapabilirim?**
[Borç-Alacak](Borc-Alacak) → **Kasa / Banka**: hesap aç, IBAN/hesap no gir, **Varsayılan** işaretle,
gerekiyorsa **pasife al**. Hareket görmüş hesap silinmez.

## Raporlar

**Rakamlar neden listelerle aynı değil?**
Raporlar yalnız **işlem görmüş** belgeleri sayar: **Taslak** ve **İptal** belgeler hiçbir raporda
yoktur. Panellerdeki genel sayılar filtrelerden bağımsızdır.

**„Maliyeti eksik satır" ne demek?**
Serbest yazılan satırların (hizmet, nakliye) ürün maliyeti olmaz; sistem bunları ayrı sayar ki
kârı okurken bilebilesin.

## Teknik

**İnternet koptu, formdaki verim kaybolur mu?**
Kaydetmeden sayfayı yenilersen form içeriği gider. Bağlantı hatasında ekranda
„… yüklenemedi." uyarısı çıkar; ağ gelince tekrar dene.

**Oturumum düşüyor**
Uzun süre beklersen „Kimlik doğrulaması gerekli." görürsün; yeniden giriş yap.

**Cihaz değiştirince dil/ayarım gelir mi?**
Dil tercihi hesabına yazılır, cihazdan bağımsızdır. Tema/görünüm tercihleri cihazda tutulur.
