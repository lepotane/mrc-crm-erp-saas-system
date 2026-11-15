# Modül Sayfası Şablonu

Yeni bir modül sayfası yazarken bu 5 başlığı kullan. Başlık adlarını değiştirme; kılavuzun
geri kalanı bu yapıya göre okunur.

```markdown
# <Modül adı>

## Bu ekran ne işe yarar?
<2-3 cümle. Teknik terim yok. Kullanıcının işini anlat: „… kayıtlarını tutar, … yapmanı sağlar.">

## Adım adım
1. <Menü yolu> → <butonun ekrandaki adı>
2. <Alanı doldur> …
3. **Kaydet** → <ne olur, hangi satır/kutu değişir>

## Alan açıklamaları
| Alan | Zorunlu | Boş bırakılırsa | Not |
| --- | --- | --- | --- |
| <alan adı ekrandaki gibi> | Evet/Hayır | <etkisi> | <varsayılan değer, örnek> |

## Takıldığınızda
- **„<ekranda görünen mesaj>"** → <neden + çözüm> (bkz. [HATA-MESAJLARI](HATA-MESAJLARI))
- „<sık yapılan hata>" → <doğrusu>

## Bilmenizde yarar var
- <sistem kuralı — ör. „Taslak dışı belge düzenlenemez">
- <otomatik davranış — ör. „Cari kodu elle yazılmaz, sistem verir">
```

## Yazım kuralları

1. **Ekrandaki metni aynen kullan:** „Yeni Cari" butonuna basılır, „Cari Ekle"ye değil.
2. **Teknik dil yok:** „PATCH isteği" değil „kaydet", „endpoint" değil „ekran".
3. **Kısa cümle, numaralı adım.** Bir adımda tek iş.
4. **Terimler [TERIMLER](TERIMLER) ile aynı** olmalı; yeni terim ekliyorsan sözlüğe de ekle.
5. **Görsel placeholder:** `> [EKRAN GÖRÜNTÜSÜ: sayfa-kisasi]` — görsel yayın öncesi çekilir.
6. **Doğrulanmadan yazma:** Alan/buton adını ve hata mesajını sistemde gerçekten görerek yaz.
