# Hata ve UyarÄ± MesajlarÄ± â€” ne anlama gelir, ne yapmalÄ±sÄ±n

AÅŸaÄŸÄ±daki mesajlar ekranda **birebir bu ÅŸekilde** gÃ¶rÃ¼nÃ¼r. Ä°ngilizce arayÃ¼zde aynÄ± mesajlarÄ±n
Ä°ngilizce karÅŸÄ±lÄ±ÄŸÄ± Ã§Ä±kar.

## Stok ve depolar

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **GiriÅŸ/Ã§Ä±kÄ±ÅŸ miktarÄ± sÄ±fÄ±rdan bÃ¼yÃ¼k olmalÄ±dÄ±r.** | â€GiriÅŸ" veya â€Ã‡Ä±kÄ±ÅŸ" seÃ§ip miktarÄ± 0 ya da eksi girdin. | MiktarÄ± pozitif yaz. Eksi gereken iÅŸlem (kÄ±rÄ±k, fire, sayÄ±m farkÄ±) iÃ§in **DÃ¼zeltme** tipini seÃ§. |
| **DÃ¼zeltme miktarÄ± 0 olamaz.** | DÃ¼zeltme iÃ§in 0 girdin. | DÃ¼zeltme, stoÄŸu gerÃ§ek sayÄ±ya Ã§eken iÅŸareti deÄŸerdir; 0'Ä±n farklÄ±sÄ± olmalÄ±. |
| **Bu depoda stok hareketi mevcut; silinemez. Depoyu pasif hale getirmek iÃ§in bÃ¼yÃ¼k depolara aktarÄ±m yapÄ±n.** | Silmeye Ã§alÄ±ÅŸtÄ±ÄŸÄ±n depoda geÃ§miÅŸ hareket var. | Depoyu silme; Ã¶nce oradaki malÄ± diÄŸer depoya aktar (Ã‡Ä±kÄ±ÅŸ + GiriÅŸ) ya da depoyu kullanmayÄ± bÄ±rak. GeÃ§miÅŸ kayÄ±tlarÄ±n bozulmamasÄ± iÃ§in bu kural var. |
| **Bu Ã¼rÃ¼ne ait stok hareketi mevcut; silinemez.** | SatÄ±lmÄ±ÅŸ/alÄ±nmÄ±ÅŸ bir Ã¼rÃ¼nÃ¼ siliyorsun. | ÃœrÃ¼nÃ¼ silmek yerine dÃ¼zenleyip kullanmayÄ± bÄ±rak; ya da â€Stok takibi" anahtarÄ±nÄ± kapat. |
| **Depo bulunamadÄ±.** / **Stok iÅŸlemi iÃ§in depo gerekli; varsayÄ±lan depo bulunamadÄ±.** | Firmanda tanÄ±mlÄ± depo yok ya da seÃ§ili depo silinmiÅŸ. | **Stok â†’ Depolar** kÄ±smÄ±ndan depo ekle (adÄ± â€Ana Depo" olabilir) ve â€VarsayÄ±lan depo" kutusunu iÅŸaretle. |
| **ÃœrÃ¼n bulunamadÄ±.** / **Kategori bulunamadÄ±.** / **Birim bulunamadÄ±.** / **Depo bulunamadÄ±.** / **Cari kaydÄ± bulunamadÄ±.** | KayÄ±t senin firmana ait deÄŸil veya az Ã¶nce silinmiÅŸ. | Listeyi yenile; baÅŸka firmada mÄ±sÄ±n diye Ã¼stteki firma adÄ±na bak. |

## Belgeler (teklif / sipariÅŸ / fatura / alÄ±m)

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **YalnÄ±zca taslak belgeler dÃ¼zenlenebilir.** | Belge zaten ihraÃ§/onaylanmÄ±ÅŸ; iÃ§eriÄŸi deÄŸiÅŸmez. | YanlÄ±ÅŸsa yeni belge kes (dÃ¼zeltme/fiyat farkÄ±), belgenin kendisini dÃ¼zenlemeye Ã§alÄ±ÅŸma. |
| **YalnÄ±zca taslak belgeler silinebilir.** | Taslak DIÅI belgeyi doÄŸrudan silmeye Ã§alÄ±ÅŸtÄ±n (makine kuralÄ±). | Ã–nce belgeyi **Ä°ptal et** (stok aynaya dÃ¼ÅŸer), sonra **Sil**. Ä°ptal edilmiÅŸ belge de koÅŸullu silinir: Ã¶demesiz + dÃ¶nÃ¼ÅŸtÃ¼rÃ¼lmemiÅŸ + iade baÄŸlantÄ±sÄ±z olmalÄ±. |
| **Ã–demesi olan belge iptal edilemez. Ã–nce Ã¶demeyi silin.** | KesilmiÅŸ belgeyi iptal etmeye Ã§alÄ±ÅŸtÄ±n ama Ã¼zerinde tahsilat/Ã¶deme var. | BorÃ§-Alacak ekranÄ±ndan ilgili Ã¶demeyi sil, sonra belgeyi iptal et (stok aynaya dÃ¼ÅŸer). DoÄŸrudan **silmek** istersen iptale gerek yok â€” silme Ã¶demeyi de denetim iziyle birlikte dÃ¼ÅŸÃ¼rÃ¼r. |
| **Bu belge Taslak durumundan Ã–dendi durumuna geÃ§irilemez.** | Olmayan bir durum sÄ±Ã§ramasÄ± denedin (Ã¶nce kesim, sonra Ã¶deme). | SÄ±rayÄ± takip et: Taslak â†’ Ä°hraÃ§ â†’ KÄ±smen/Tam Ã¶dendi. |
| **Teklif doÄŸrudan faturaya dÃ¶nÃ¼ÅŸtÃ¼rÃ¼lemez; Ã¶nce sipariÅŸe dÃ¶nÃ¼ÅŸtÃ¼rÃ¼n.** | Teklifâ†’fatura kÄ±sayolu kapalÄ±. | Teklifi **SipariÅŸe dÃ¶nÃ¼ÅŸtÃ¼r**, sonra sipariÅŸi **Faturaya dÃ¶nÃ¼ÅŸtÃ¼r**. |
| **SatÄ±r adÄ± zorunludur.** | Belgeye satÄ±r ekledin ama adÄ± boÅŸ. | Her satÄ±rda bir ad olmalÄ± (Ã¼rÃ¼n seÃ§ersen ad otomatik gelir). |
| **SatÄ±r miktarÄ± 0 olamaz.** | MiktarsÄ±z satÄ±r kaydedilemez. | Miktar gir ya da satÄ±rÄ± Ã§Ä±kar. |
| **Belge bulunamadÄ±.** | Belge sana/ firmana ait deÄŸil veya silinmiÅŸ. | Listeden ara; firma baÄŸlamÄ±nÄ± kontrol et. |
| **Bu belge Taslak durumundan Ä°hraÃ§ durumuna geÃ§irilemez.** | Durum makinesinin izin vermediÄŸi bir geÃ§iÅŸ denedin (Ã¶r. teklifi â€Ä°hraÃ§", faturayÄ± â€OnaylandÄ±" yapmaya Ã§alÄ±ÅŸmak). | SÄ±rayÄ± takip et: Taslak â†’ Ä°hraÃ§ â†’ KÄ±smen/Tam Ã¶dendi. Teklif ve sipariÅŸ â€ihraÃ§" edilmez; **GÃ¶nderildi yap / Kabul et / Onayla** ve **SipariÅŸe dÃ¶nÃ¼ÅŸtÃ¼r / Faturaya dÃ¶nÃ¼ÅŸtÃ¼r** ile ilerler. (Butonlar tÃ¼re gÃ¶re gÃ¶sterilir; yanlÄ±ÅŸ fiili gÃ¶rmek iÃ§in eski bir ekranÄ± yenilemeden kullanman gerekir.) |
| **Bu belge bu tÃ¼re dÃ¶nÃ¼ÅŸtÃ¼rÃ¼lemez.** | Olmayan bir dÃ¶nÃ¼ÅŸÃ¼m denedin (Ã¶r. alÄ±m faturasÄ±nÄ± teklife Ã§evirmek). | Ä°zinli dÃ¶nÃ¼ÅŸÃ¼mler: Teklif â†’ SipariÅŸ, SipariÅŸ â†’ Fatura. |
| **İhraç edilen belge iptal edilemez; düzeltme için yeni belge kesin.** | (Eski kural) Bu mesaj artık görünmez; kesilmiş belge **İptal et** düğmesiyle iptal edilir (stok aynaya düşer). | İptal et, gerekiyorsa doğrusunu taşıyan yeni belge kes. |
| **Belge dÃ¶nÃ¼ÅŸtÃ¼rÃ¼lemedi.** | DÃ¶nÃ¼ÅŸÃ¼m sunucuda reddedildi (durum/tip uyuÅŸmazlÄ±ÄŸÄ±). | Kaynak belgenin tipini ve durumunu kontrol et; **Teklif** iÃ§in Ã¶nce **SipariÅŸe dÃ¶nÃ¼ÅŸtÃ¼r**. |

## KullanÄ±cÄ±, davet ve yetki

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **Bu iÅŸlem iÃ§in yÃ¶netici yetkisi gereklidir.** | Personel rolÃ¼yle ayar/kullanÄ±cÄ±/silme iÅŸlemi yapÄ±yorsun. | Ä°ÅŸlemi Admin yapmasÄ±nÄ± iste. |
| **Kimlik doÄŸrulamasÄ± gerekli.** | Oturumun dÃ¼ÅŸmÃ¼ÅŸ (uzun sÃ¼re veya Ã§Ä±kÄ±ÅŸ). | GiriÅŸ ekranÄ±ndan tekrar giriÅŸ yap; formdaki verilerin kaybolmuÅŸsa yeniden doldur. |
| **Bu e-posta iÃ§in bekleyen bir davet zaten var.** | AynÄ± kiÅŸiye ikinci davet deneniyor. | Eski daveti sil (**KullanÄ±cÄ±lar & Roller**) ve yeniden davet oluÅŸtur. |
| **Bu davet zaten kullanÄ±ldÄ±.** / **Davet sÃ¼resi geÃ§miÅŸ.** | Davet linki tek kullanÄ±mlÄ±ktÄ±r ve 7 gÃ¼n geÃ§erlidir. | Yeni davet oluÅŸtur. |
| **Bu davet farklÄ± bir e-posta adresine gÃ¶nderildi.** | Davet baÅŸka bir e-postaya kesilmiÅŸ. | Davet edilen e-postayla giriÅŸ yap ya da doÄŸru e-posta ile yeniden davet oluÅŸtur. |
| **Bu davet senin e-postana gÃ¶nderilmemiÅŸ, sÃ¼resi geÃ§miÅŸ ya da zaten kabul edilmiÅŸ gÃ¶rÃ¼nÃ¼yor.** | Åeride dÃ¼ÅŸen bir `?invite=<token>` baÄŸlantÄ±sÄ± aÃ§Ä±ldÄ±; davet bu hesaba ait deÄŸil ya da dÃ¼ÅŸtÃ¼ (7 gÃ¼n / tek kullanÄ±m). | DoÄŸru e-postayla giriÅŸ yap; davet hÃ¢lÃ¢ bekliyorsa ÅŸeritteki **Kabul et** yeterlidir, baÄŸlantÄ±ya gerek yok. |
| **Davet kabul edilemedi.** / **Firmaya geÃ§ilemedi.** | Oturum dÃ¼ÅŸtÃ¼ ya da davet bu arada iptal edildi. | Yeniden giriÅŸ yap ve ÅŸeritten tekrar dene; hÃ¢lÃ¢ olmuyorsa Admin **KullanÄ±cÄ±lar & Roller**den daveti yenilesin. |
| **Bu firmanÄ±n Ã¼yesi deÄŸilsiniz.** | EriÅŸmeye Ã§alÄ±ÅŸtÄ±ÄŸÄ±n firma senin hesabÄ±na baÄŸlÄ± deÄŸil. | Ãœstteki firma seÃ§icisinden kendi firmanÄ± seÃ§. |
| **Ana para birimi kapatÄ±lamaz.** | TRY'yi pasif yapmaya Ã§alÄ±ÅŸtÄ±n (Ayarlar â†’ Para Birimleri ve Kurlar). | TRY hep aÃ§Ä±k kalÄ±r; kullanmadÄ±ÄŸÄ±n dÃ¶vizi kapatabilirsin. |
| **Bu modÃ¼l kapalÄ±.** | KapatÄ±lmÄ±ÅŸ bir modÃ¼lÃ¼n ekranÄ±na girdin (Ã¶rn. Stok kapatÄ±lmÄ±ÅŸken /stok). | ModÃ¼lÃ¼ Ayarlar â†’ **ModÃ¼ller** sekmesinden **AÃ§**; ya da ilgili iÅŸi baÅŸka ekrandan yap. |
| **AlÄ±nan tutar toplamdan az olamaz.** | HÄ±zlÄ± SatÄ±ÅŸ'ta mÃ¼ÅŸteriden aldÄ±ÄŸÄ±n parayÄ± toplamdan az yazdÄ±n. | DoÄŸru tutarÄ± yaz ya da AlÄ±nan kutusunu boÅŸ bÄ±rak (para Ã¼stÃ¼ 0 olur, satÄ±ÅŸ yine tamamlanÄ±r). |
| **YalnÄ±z kesilmiÅŸ faturadan iade aÃ§Ä±lÄ±r.** | Taslak/iptal belgeden ya da fatura dÄ±ÅŸÄ± tÃ¼rden iade denedin. | Ã–nce belgeyi ihraÃ§ et (fatura/alÄ±ÅŸ); tekliften iade aÃ§Ä±lmaz. |
| **Ä°ade miktarÄ± kaynaÄŸÄ± aÅŸamaz.** | Ä°ade satÄ±rÄ±nda kaynaktaki miktardan fazla yazdÄ±n. | MiktarÄ± kalan kadar yaz ya da kalanÄ± ayrÄ± iade olarak aÃ§. |
| **Ä°ade edilecek satÄ±r kalmadÄ±.** | FaturanÄ±n tamamÄ± zaten iade edilmiÅŸ. | Yeni iade aÃ§Ä±lamaz; listede `IAD-â€¦` belgeleri kontrol et. |
| **Kaynak depoda yeterli stok yok.** | Transferde kaynaktaki miktar yetersiz. | MiktarÄ± dÃ¼ÅŸÃ¼r ya da Ã¶nce kaynak depoya giriÅŸ yap. |
| **Kaynak ve hedef depo aynÄ± olamaz.** | Transferde iki kutuya aynÄ± depo seÃ§ildi. | Hedefi farklÄ± depo yap. |
| **YalnÄ±z taslak sayÄ±m onaylanabilir / iptal edilebilir.** | OnaylanmÄ±ÅŸ/iptal sayÄ±mÄ± yeniden iÅŸletmeye Ã§alÄ±ÅŸtÄ±n. | Yeni sayÄ±m aÃ§; bitmiÅŸ oturum deÄŸiÅŸmez. |

## Ä°ki faktÃ¶rlÃ¼ giriÅŸ

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **Kod doÄŸrulanamadÄ±. Telefonundaki gÃ¼ncel kodu girin.** | Kurulum onayÄ±nda eski/yanlÄ±ÅŸ kod girdin (kod her 30 sn yenilenir). | Telefonundaki gÃ¼ncel 6 haneli kodu gir. |
| **Kod hatalÄ±. Kalan deneme: :count** | GiriÅŸ sÄ±namasÄ±nda yanlÄ±ÅŸ kod. 5 yanlÄ±ÅŸta sÄ±nama Ã¶lÃ¼r. | GÃ¼ncel kodu gir; 5'i doldurursan baÅŸtan giriÅŸ yap. |
| **Ã‡ok fazla yanlÄ±ÅŸ deneme. Yeniden giriÅŸ yapÄ±n.** | SÄ±nama anahtarÄ± 5 yanlÄ±ÅŸla Ã¶ldÃ¼. | E-posta + ÅŸifreyle yeniden giriÅŸ yap, yeni kodla dene. |
| **Åifre hatalÄ±.** | 2FA kapatma/yenilemede yanlÄ±ÅŸ ÅŸifre. | DoÄŸru ÅŸifrenle tekrar dene. |
| **Ä°ki faktÃ¶rlÃ¼ giriÅŸ zaten etkin.** | Zaten aÃ§Ä±kken yeniden kurulum/onay denedin. | Ã–nce kapat, sonra yeniden kur. |

## Ã–demeler, kasa/banka ve borÃ§-alacak

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **Tutar sÄ±fÄ±rdan bÃ¼yÃ¼k olmalÄ±dÄ±r.** | Tahsilat/Ã¶deme tutarÄ±nÄ± 0 veya eksi girdin. | YÃ¶nÃ¼ zaten **Tahsilat/Ã–deme** seÃ§imi belirler; tutarÄ± pozitif yaz. |
| **Bu cari hesap tipi iÃ§in bu yÃ¶nde Ã¶deme kaydedilemez.** | MÃ¼ÅŸteri carisine â€Ã–deme", tedarikÃ§i carisine â€Tahsilat" yazdÄ±n. | Cari tipini dÃ¼zenleyip **Her Ä°kisi** yap ya da doÄŸru yÃ¶nÃ¼ seÃ§. |
| **SeÃ§ilen belge bu Ã¶deme iÃ§in uygun deÄŸil.** | Ã–demeyi taslak/iptal bir belgeye baÄŸlamaya Ã§alÄ±ÅŸÄ±yorsun, ya da tahsilatÄ± alÄ±m faturasÄ±na, Ã¶demeyi satÄ±ÅŸ faturasÄ±na baÄŸlÄ±yorsun. | Ã–nce belgeyi **Ä°hraÃ§ et**; tahsilat satÄ±ÅŸ faturasÄ±na, Ã¶deme alÄ±m faturasÄ±na baÄŸlanÄ±r. Belgeye hiÃ§ baÄŸlamadan da kaydedebilirsin (aÃ§Ä±k hesap tahsilatÄ±). |
| **SeÃ§ilen belge bu cari hesaba ait deÄŸil.** | BaÅŸka mÃ¼ÅŸterinin/tedarikÃ§inin faturasÄ±nÄ± baÄŸlÄ± belge olarak seÃ§tin. | Ã–nce doÄŸru cariyi seÃ§; liste o carinin aÃ§Ä±k belgelerini gÃ¶sterir. |
| **Ã–deme para birimi hesap para birimiyle aynÄ± olmalÄ±dÄ±r.** | DÃ¶viz Ã¶demezi TL kasaya/bankaya (veya tersi) iÅŸlemeye Ã§alÄ±ÅŸÄ±yorsun. | O para biriminde ayrÄ± bir kasa/banka hesabÄ± aÃ§ ve onu seÃ§. Sistem dÃ¶vizleri birbirine Ã§evirmez. |
| **Bu cari hesap silinemez: baÄŸlÄ± belge veya Ã¶deme kaydÄ± var.** | Cariye ait fatura/alÄ±m/Ã¶deme kaydÄ± olduÄŸu iÃ§in silme engellenir (geÃ§miÅŸ kaybolmasÄ±n). | Silme; cariyi kullanmayÄ± bÄ±rak veya kaydÄ± dÃ¼zenle. Ä°ÅŸlem gÃ¶rmÃ¼ÅŸ kasa/banka ve hareketli Ã¼rÃ¼n/depo iÃ§in de aynÄ± kural var. |
| **Ã–deme para birimi belge para birimiyle aynÄ± olmalÄ±dÄ±r.** | Belge USD iken Ã¶deme TRY girildi. | Ã–demenin para birimini belgenin para birimiyle eÅŸitle. (DÃ¶vizli belgede kur seÃ§imi ayrÄ± iÅŸtir: formdaki **Hangi kurla iÅŸlensin?** sorusuna bak.) |
| **Belgeye baÄŸlÄ± olmayan Ã¶demede belge kuru seÃ§ilemez.** | Belgesiz tahsilatta â€Belge kuru" iÅŸaretlendi. | Belgesiz kayÄ±tta belge kuru yoktur; **GÃ¼ncel kur** seÃ§ veya belgeye baÄŸla. |
| **Pasif hesaba Ã¶deme kaydedilemez.** | KapattÄ±ÄŸÄ±n kasa/banka hesabÄ±na kayÄ±t yazÄ±yorsun. | HesabÄ± **Aktif** yap veya baÅŸka hesabÄ± seÃ§. |

## Online Ã¶deme (PayTR / iyzico)

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **PayTR Ã¶deme hesabÄ± tanÄ±mlÄ± deÄŸil. / iyzico Ã¶deme hesabÄ± tanÄ±mlÄ± deÄŸil.** | O saÄŸlayÄ±cÄ±nÄ±n bilgileri hiÃ§ girilmemiÅŸ. | [Ayarlar](Ayarlar) â†’ **Ã–deme AyarlarÄ±** kartÄ±nda ilgili saÄŸlayÄ±cÄ±yÄ± doldur (detay: [ONLINE-ODEME](ONLINE-ODEME) Â§1). |
| **Ã¶deme hesabÄ± eksik: â€¦ / Ã–deme hesabÄ±nÄ± etkinleÅŸtirmek iÃ§in eksik alanlarÄ± doldurun.** | Alanlar yarÄ±m kalmÄ±ÅŸ (Ã¶rn. salt girilmeden etkinleÅŸtirme denendi). | Eksik yazan alanÄ± tamamla; anahtarlarÄ± Ã¶deme kuruluÅŸunun panelinden kopyala. |
| **Ã¶deme hesabÄ± etkin deÄŸil.** | Bilgiler girilmiÅŸ ama hesap aÃ§Ä±lmamÄ±ÅŸ. | **Hesap etkin** iÅŸaretini aÃ§Ä±p **Kaydet**. |
| **Ã–deme linki yalnÄ±z satÄ±ÅŸ faturasÄ± iÃ§in oluÅŸturulabilir.** | Teklife, sipariÅŸe veya alÄ±m faturasÄ±na baÄŸlantÄ± aÃ§maya Ã§alÄ±ÅŸtÄ±n. | Ã–nce sipariÅŸi faturaya Ã§evir ([Satislar](Satislar) Â§5); taslak faturayÄ± **Ä°hraÃ§ et**. |
| **â€¦durumundaki belge iÃ§in Ã¶deme linki oluÅŸturulamaz.** | Fatura taslak veya iptal durumda. | Ä°hraÃ§ edilmiÅŸ faturada dene. |
| **Link tutarÄ± belgenin kalan alacaÄŸÄ±ndan fazla olamaz.** | Fatura kÄ±smen tahsil edilmiÅŸ; yazdÄ±ÄŸÄ±n tutar kalanÄ± aÅŸÄ±yor. | TutarÄ± kalan alacaÄŸa indir. |
| **Ã–deme linki gÃ¶ndermek iÃ§in caride geÃ§erli bir e-posta adresi olmalÄ±.** | PayTR e-postayÄ± zorunlu tutar. | Cariyi [Cariler](Cariler) ekranÄ±nda gÃ¼ncelle. |
| **iyzico ile Ã¶deme linki iÃ§in caride telefon numarasÄ± olmalÄ±.** | iyzico alÄ±cÄ± telefonu ister. | Cariye telefon ekle. |
| **PayTR/iyzico Ã¶deme sayfasÄ± aÃ§Ä±lamadÄ±.** | SaÄŸlayÄ±cÄ±ya ulaÅŸÄ±lamadÄ± ya da hesap bilgileri reddedildi. | BirkaÃ§ dakika bekleyip tekrar dene; sÃ¼rerse Ayarlar'daki bilgileri ve **Deneme modu** iÅŸaretini kontrol et. |
| **Bu ayki Ã¶deme linki limitine ulaÅŸtÄ±nÄ±z.** | PlanÄ±nÄ±n aylÄ±k baÄŸlantÄ± hakkÄ± doldu. | SÃ¼resi dolmuÅŸ baÄŸlantÄ±larÄ± kapatÄ±p ayÄ± bekle ya da planÄ± yÃ¼kselt ([Ayarlar](Ayarlar) Â§6). |
| **Bu hesapta Ã¶deme kaydÄ± mevcut; silinemez.** | Hareket gÃ¶rmÃ¼ÅŸ kasa/bankayÄ± siliyorsun. | HesabÄ± silme; **pasife al**. GeÃ§miÅŸ kayÄ±tlar bozulmasÄ±n diye bu kural var. |
| **Kasa/banka hesabÄ± bulunamadÄ±.** / **Ã–deme bulunamadÄ±.** | KayÄ±t senin firmandan deÄŸil veya silinmiÅŸ. | Listeyi yenile; firma baÄŸlamÄ±nÄ± kontrol et. |
| **Tutarlar para birimi bazÄ±nda ayrÄ±dÄ±r; para birimi Ã§evirimi sonraki bir sÃ¼rÃ¼mde eklenecektir.** | Rapor/Ã¶zet ekranÄ±nda birden fazla para birimi var. | SayÄ±larÄ± **para birimi bazÄ±nda** oku; dÃ¶vizli toplamlar tek sayÄ±da toplanmÄ±ÅŸ gibi gÃ¶sterilmez. |

## SatÄ±ÅŸ/AlÄ±m ekranÄ±ndan Ã§Ä±kan uyarÄ±lar

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **â€SatÄ±r miktarÄ± 0'dan bÃ¼yÃ¼k olmalÄ±dÄ±r."** | SatÄ±r miktarÄ±nÄ± 0 bÄ±raktÄ±n. | MiktarÄ± yaz ya da **SatÄ±r sil** ile satÄ±rÄ± Ã§Ä±kar. |
| **â€SatÄ±r miktarÄ± negatif olamaz."** | Miktara eksi deÄŸer girdin (iade mantÄ±ÄŸÄ±). | Ä°ade/iskonto belgesi bu sÃ¼rÃ¼mde yok: dÃ¼zeltmeyi yeni belgeyle yap; sayÄ±m farkÄ±nÄ± [Stok](Stok) â†’ **DÃ¼zeltme** ile iÅŸle. |
| **â€Belge listesi yÃ¼klenemedi." / â€Belge detayÄ± yÃ¼klenemedi."** | BaÄŸlantÄ± hatasÄ± veya oturum dÃ¼ÅŸmÃ¼ÅŸ. | SayfayÄ± yenile; Ã§Ä±kÄ±ÅŸ yaptÄ±ysan yeniden giriÅŸ yap. |
| **â€PDF indirilemedi."** | PDF Ã¼retimi tamamlanamadÄ± (baÄŸlantÄ±/oturum). | SayfayÄ± yenileyip tekrar dene; hÃ¢lÃ¢ olmazsa belge durumunu kontrol et. |
| **â€Belge bulunamadÄ±" (liste baÅŸlÄ±ÄŸÄ±nda)** | Sekme/filtre boÅŸ sonuÃ§ veriyor; hata deÄŸildir. | Tip sekmesini deÄŸiÅŸtir, durum filtresini **TÃ¼mÃ¼** yap, tarihleri temizle. |
| **â€YazdÄ±rma kuyruÄŸa alÄ±namadÄ±."** | YazÄ±cÄ± cihazda kayÄ±tlÄ± deÄŸil ya da belge kesilmemiÅŸ satÄ±ÅŸ belgesi deÄŸil. | [Ayarlar](Ayarlar) â†’ **YazÄ±cÄ±lar** sekmesinde yazÄ±cÄ±nÄ±n cihazda gÃ¶rÃ¼ndÃ¼ÄŸÃ¼nÃ¼ doÄŸrula; belgenin ihraÃ§ edilmiÅŸ satÄ±ÅŸ faturasÄ± olduÄŸunu kontrol et. |
| **â€Bu yazÄ±cÄ± cihazda kayÄ±tlÄ± deÄŸil."** | KuyruÄŸa atarken seÃ§ilen yazÄ±cÄ± adÄ± cihazÄ±n bildirdiÄŸi listede yok. | KÃ¶prÃ¼ uygulamasÄ±nda yazÄ±cÄ± adÄ±nÄ± kontrol et; cihazÄ± yeniden eÅŸleÅŸtir. |
| **â€YalnÄ±z kuyruktaki iÅŸ iptal edilir."** | BasÄ±lmaya baÅŸlanmÄ±ÅŸ (`claimed`) ya da bitmiÅŸ iÅŸi silmeye Ã§alÄ±ÅŸtÄ±n. | Kuyrukta bekleyen iÅŸi iptal edebilirsin; baÅŸlamÄ±ÅŸ iÅŸi kÃ¶prÃ¼den takip et. |

## Plan limitleri (abonelik)

Bu mesajlar kaydÄ± **sen deÄŸil sistem durdurur**: planÄ±nÄ±n sayÄ±sÄ±na ulaÅŸtÄ±n. SayÄ±lar
[Ayarlar](Ayarlar) â†’ **Plan ve Limitler** kartÄ±nda gÃ¶rÃ¼nÃ¼r.

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **â€Bu ayki belge limitine ulaÅŸtÄ±nÄ±z (:usage/:limit). AyÄ±n :day gÃ¼nÃ¼nde limit yenilenir."** | O takvim ayÄ±nda kesebileceÄŸinden fazla belge denedin (teklif, sipariÅŸ, fatura, alÄ±m hepsi sayÄ±lÄ±r; **dÃ¶nÃ¼ÅŸtÃ¼rme de yeni belgedir**). | AyÄ±n o gÃ¼nÃ¼ bekleyip kes ya da planÄ±nÄ± yÃ¼kselt. Ã–nceki ayÄ±n belgeleri bu sayaca girmez. |
| **â€Bu planÄ±n kullanÄ±cÄ± limitine ulaÅŸtÄ±nÄ±z (:usage/:limit). Yeni Ã¼ye davet edemezsiniz."** | Ãœye sayÄ±sÄ± + **kabul bekleyen davetler** planÄ±n kullanÄ±cÄ± sayÄ±sÄ±nÄ± geÃ§ti. | KullanÄ±lmayan davetleri **Ä°ptal et**, ayrÄ±lan Ã¼yeyi listeden Ã§Ä±kar veya plan yÃ¼kselt. |
| **â€Bu planÄ±n Ã¼rÃ¼n limitine ulaÅŸtÄ±nÄ±z (:usage/:limit)."** | Katalogda izin verilen Ã¼rÃ¼n kartÄ± sayÄ±sÄ±nÄ± aÅŸtÄ±n. | ArtÄ±k kullanmadÄ±ÄŸÄ±n Ã¼rÃ¼nleri sil (hareket gÃ¶rmÃ¼ÅŸler silinmez, pasifte kalÄ±r) veya plan yÃ¼kselt. |
| **â€Bu ayki Ã¶deme linki limitine ulaÅŸtÄ±nÄ±z (:usage/:limit)."** | O ay izin verilen Ã¶deme linki sayÄ±sÄ±nÄ± doldurdun. | Linki Ã¶denmemiÅŸse **SÃ¼resini doldur** yapÄ±p ayÄ± bekle; yoÄŸun kullanÄ±mda plan yÃ¼kselt. |

> Limit mesajlarÄ± **her dilde senin gÃ¶rdÃ¼ÄŸÃ¼n dille** yazar; rakamlar kaÃ§/kaÃ§ biÃ§imindedir.
> Limit aÅŸÄ±mÄ± kaydÄ± **oluÅŸturmaz** â€” yani yarÄ±m kalmÄ±ÅŸ/bozuk kayÄ±t doÄŸmaz.

## Form alanÄ± hatalarÄ± (kÄ±rmÄ±zÄ± satÄ±rlar)

Formu gÃ¶nderince alan altÄ±nda kÄ±sa kÄ±rmÄ±zÄ± yazÄ± Ã§Ä±kar. SÄ±k olanlar:

| GÃ¶rdÃ¼ÄŸÃ¼n | AnlamÄ± |
| --- | --- |
| **â€â€¦ alanÄ± zorunludur."** | O alan boÅŸ; `*` ile iÅŸaretli alanlar zorunludur. |
| **â€â€¦ zaten kayÄ±tlÄ±."** | AynÄ± ad/kod firmanda mevcut (Ã¶r. â€GÄ±da" kategorisi iki kez eklenemez). |
| **â€â€¦ geÃ§erli bir e-posta adresi olmalÄ±dÄ±r."** | YazÄ±m hatasÄ± var (`@` veya nokta eksik). |
| **â€â€¦ en az 8 karakter olmalÄ±dÄ±r."** | Parola kÄ±sa. |
| **â€â€¦ sayÄ± olarak girilmelidir."** | Fiyat/miktar alanÄ±na harf veya nokta-virgÃ¼l karÄ±ÅŸmÄ±ÅŸ; `25.5` gibi nokta ile yaz (gÃ¶sterim `,` olabilir). |
| **â€SeÃ§ilen â€¦ geÃ§ersiz."** | AÃ§Ä±lÄ±r listeden seÃ§enek seÃ§ilmemiÅŸ veya liste yenilenmemiÅŸ. |

## GiriÅŸ, oturum ve eriÅŸim anahtarlarÄ±

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **E-posta veya ÅŸifre hatalÄ±.** | GiriÅŸ bilgileri eÅŸleÅŸmedi. GÃ¼venlik iÃ§in â€bÃ¶yle bir kullanÄ±cÄ± yok" / â€ÅŸifre yanlÄ±ÅŸ" ayrÄ±mÄ± yapÄ±lmaz. | E-postayÄ± ve ÅŸifreyi kontrol et. Åifreni hiÃ§ bilmiyorsan giriÅŸ ekranÄ±ndaki **Åifremi unuttum** ile kendine sÄ±fÄ±rlama baÄŸlantÄ±sÄ± gÃ¶nder. |
| **E-postan doÄŸrulanmadÄ±.** (Ã¼st ÅŸerit) | HesabÄ±n e-postasÄ± henÃ¼z doÄŸrulanmamÄ±ÅŸ. | KayÄ±t postasÄ±ndaki dÃ¼ÄŸmeye bas ya da ÅŸeritteki **DoÄŸrulama postasÄ±nÄ± gÃ¶nder** ile yenisini iste (spam klasÃ¶rÃ¼ne de bak). |
| **Åifre ve ÅŸifre tekrarÄ± uyuÅŸmuyor.** | KayÄ±t/dÃ¼zenleme formunda iki ÅŸifre alanÄ± farklÄ± yazÄ±lmÄ±ÅŸ. | Ä°ki alanÄ± yeniden yaz; ÅŸifre en az **8 karakter** olmalÄ±. |
| **Girilen bilgiler doÄŸrulanamadÄ±.** | KayÄ±t formundaki e-posta kabul edilmedi (zaten kayÄ±tlÄ± veya geÃ§ersiz biÃ§im). | BaÅŸka bir e-posta dene; yazÄ±mÄ± kontrol et (`@` ve nokta eksik olmasÄ±n). |
| **Ã‡ok fazla istek gÃ¶nderdiniz. LÃ¼tfen kÄ±sa bir sÃ¼re bekleyip tekrar deneyin.** | GÃ¼venlik sÄ±nÄ±rÄ±: kÄ±sa sÃ¼rede Ã§ok istek (genelde entegrasyon/masaÃ¼stÃ¼ program). | Bir dakika bekle. Program kullanÄ±yorsan istek sayÄ±sÄ±nÄ± azalt; her kayÄ±t iÃ§in ayrÄ± anahtar kullanma. |
| **Bu eriÅŸim anahtarÄ±nÄ±n bu iÅŸlem iÃ§in yetkisi yok.** | **Salt okuma** anahtarÄ± ile kayÄ±t yazmaya Ã§alÄ±ÅŸÄ±yorsun. | MasaÃ¼stÃ¼ kasa/terminal iÃ§in **Yazma yetkisi** olan anahtar Ã¼ret: [Ayarlar](Ayarlar). |
| **Bu eriÅŸim anahtarÄ± ÅŸu anki oturumunundur; Ã¶nce Ã§Ä±kÄ±ÅŸ yapÄ±n.** | Åu anda kullandÄ±ÄŸÄ±n tarayÄ±cÄ± oturumunun anahtarÄ±nÄ± silmeye Ã§alÄ±ÅŸÄ±yorsun. | Ã–nce **GÃ¼venli Ã‡Ä±kÄ±ÅŸ** yap, sonra o satÄ±rÄ± **Ä°ptal et**. |
| **EriÅŸim anahtarÄ± bulunamadÄ±.** | Anahtar silinmiÅŸ, sÃ¼resi dolmuÅŸ veya sana ait deÄŸil. | Listeden yeni anahtar Ã¼ret; masaÃ¼stÃ¼ programa yapÄ±ÅŸtÄ±rÄ±rken sonundaki `â€¦` kÄ±smÄ±nÄ±n eksik kalmadÄ±ÄŸÄ±ndan emin ol. |
| **Bu uÃ§ nokta mevcut deÄŸil.** / **Bu uÃ§ nokta GET isteÄŸi iÃ§in kullanÄ±lamaz.** | Adres yanlÄ±ÅŸ veya baÄŸlantÄ± tÃ¼rÃ¼ hatalÄ± (genelde entegrasyon ayarÄ±nda). | Entegrasyon/masaÃ¼stÃ¼ programÄ±ndaki adresi kontrol et; arayÃ¼zde menÃ¼den yeniden gir. |
| **DokÃ¼manÄ± gÃ¶rmek iÃ§in giriÅŸ yapÄ±n.** / **DokÃ¼man anahtarÄ± geÃ§ersiz.** | API dokÃ¼man sayfasÄ± (`/api-docs`) kapalÄ± ya da anahtarÄ± yanlÄ±ÅŸ. | Normal kullanÄ±m iÃ§in gerekmez; yÃ¶neticinden anahtarÄ± iste. |
| **API dokÃ¼manÄ± Ã¼retilmemiÅŸ. Ã–nce `npm run openapi` Ã§alÄ±ÅŸtÄ±rÄ±n.** | DokÃ¼man sayfasÄ± daha Ã¶nce Ã¼retilmemiÅŸ (teknik bir eksik). | KullanÄ±cÄ± hatasÄ± deÄŸildir; teknik ekibe bildir. |

## Resim yÃ¼kleme (firma logosu)

| Mesaj | Neden Ã§Ä±kar | Ne yapmalÄ±sÄ±n |
| --- | --- | --- |
| **Dosya boyutu 2 MB Ã¼zerinde olamaz.** | SeÃ§tiÄŸin resim bÃ¼yÃ¼k. | Resmi kÃ¼Ã§Ã¼lt (ekran gÃ¶rÃ¼ntÃ¼sÃ¼ genellikle yeterlidir) ve yeniden seÃ§. |
| **YalnÄ±zca PNG, JPG veya WebP formatÄ±nda gÃ¶rsel yÃ¼kleyebilirsiniz.** | BaÅŸka tÃ¼r dosya seÃ§tin (PDF, HEIC, BMPâ€¦). | Resmi PNG/JPG/WebP olarak kaydedip tekrar yÃ¼kle. |
| **Logo yÃ¼klenirken bir hata oluÅŸtu.** | BaÄŸlantÄ± koptu veya sunucu dosyayÄ± kabul etmedi. | SayfayÄ± yenile, dosyayÄ± yeniden seÃ§. Personel rolÃ¼ndeysen logoyu yalnÄ±z **Admin** deÄŸiÅŸtirebilir. |

## â€â€¦ yÃ¼klenemedi" ailesi

Åu mesajlarÄ±n hepsi aynÄ± anlama gelir: **veriyi alamadÄ±k** (baÄŸlantÄ± koptu, oturum dÃ¼ÅŸtÃ¼ ya da
sunucu geÃ§ cevap verdi). Hepsinde ilk denenecek ÅŸey **sayfayÄ± yenilemek**tir:

**Cari listesi yÃ¼klenemedi.** Â· **Cari detayÄ± yÃ¼klenemedi.** Â· **Cari bakiyeler yÃ¼klenemedi.** Â·
**Ã–demeler yÃ¼klenemedi.** Â· **YaÅŸlandÄ±rma yÃ¼klenemedi.** Â· **ÃœrÃ¼n listesi yÃ¼klenemedi.** Â·
**Kategoriler yÃ¼klenemedi.** Â· **Birimler yÃ¼klenemedi.** Â· **ÃœrÃ¼n seÃ§enekleri yÃ¼klenemedi.** Â·
**Depolar yÃ¼klenemedi.** Â· **Stok hareketleri yÃ¼klenemedi.** Â· **SeÃ§im listeleri yÃ¼klenemedi.** Â·
**Belge listesi yÃ¼klenemedi.** Â· **Belge detayÄ± yÃ¼klenemedi.** Â· **SatÄ±ÅŸ Ã¶zeti yÃ¼klenemedi.** Â·
**KDV Ã¶zeti yÃ¼klenemedi.** Â· **Stok deÄŸeri yÃ¼klenemedi.** Â· **Rapor yÃ¼klenemedi.** Â·
**Panel yÃ¼klenemedi.** Â· **EriÅŸim anahtarlarÄ± yÃ¼klenemedi.** Â· **CSV oluÅŸturulamadÄ±.**

KayÄ±t yazarken Ã§Ä±kan **Cari oluÅŸturulamadÄ±.** / **ÃœrÃ¼n kaydedilemedi.** / **Belge oluÅŸturulamadÄ±.** /
**Ã–deme kaydedilemedi.** / **Hesap kaydedilemedi.** / **Depo kaydedilemedi.** mesajlarÄ±nda ise
Ã¶nce formdaki **kÄ±rmÄ±zÄ± alanlara** bak; neredeyse her sebebi bir alan hatasÄ± oluÅŸturur. AynÄ± mesaj
Ä±srarla tekrarlanÄ±yorsa sayfayÄ± yenileyip iÅŸlemi baÅŸtan yap (kayÄ±t oluÅŸmuÅŸ olabilir; listeden kontrol et).

## Hata olmayan ama yadÄ±rganan yazÄ±lar

| GÃ¶rdÃ¼ÄŸÃ¼n | AnlamÄ± |
| --- | --- |
| **SilinmiÅŸ Ã¼rÃ¼n** / **SilinmiÅŸ depo** | Belge veya hareket tarihinde o kayÄ±t artÄ±k yok. KayÄ±t silinmez; belge/satÄ±r geÃ§miÅŸte kaldÄ±ÄŸÄ± iÃ§in etiketi bÃ¶yle gÃ¶rÃ¼nÃ¼r. |
| **BaÄŸlantÄ±sÄ±z** | Rapor satÄ±rÄ±nÄ±n kategorisi/Ã¼rÃ¼nÃ¼ seÃ§ili deÄŸil (Ã¶r. kategori sonradan silinmiÅŸ). |
| **Bu hesapta :n Ã¶deme hareketi var. Silmek yerine pasife almayÄ± dÃ¼ÅŸÃ¼nÃ¼n.** | Silme onayÄ±nÄ±n uyarÄ±sÄ±. **VazgeÃ§** de, hesabÄ± **Pasif** yap. |
| **Davet bulunamadÄ±.** | Davet ÅŸeridi/baÄŸlantÄ±sÄ± artÄ±k yok (iptal edilmiÅŸ veya dÃ¼ÅŸmÃ¼ÅŸ). Admin'den yeni davet iste. |

## HiÃ§birini bulamadÄ±ysan

1. MesajÄ±n tamamÄ±nÄ± kopyala, bu sayfada ara.
2. Yoksa: ekranÄ±n Ã¼stÃ¼ndeki **firma adÄ±na** ve rolÃ¼ne (**Admin/Personel**) bak â€” en sÄ±k sebebi baÄŸlam hatasÄ±dÄ±r.
3. Yine Ã§Ã¶zÃ¼lmediyse ekran gÃ¶rÃ¼ntÃ¼sÃ¼ ile destek iste; kÄ±lavuz eksikse tamamlanacak.
