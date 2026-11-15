<?php

namespace App\Support\Payments;

use App\Models\PaymentLink;
use Illuminate\Http\Request;

/**
 * Ödeme sağlayıcı sözleşmesi .
 *
 * İki sağlayıcıda para THEIR sunucudan geçer; biz yalnız iki şey yaparız:
 *   1) ödeme sayfasını açacak işlem kaydını (checkout) üretmek,
 *   2) ödeme sonucunu İMZASINI DOĞRULAYARAK kabul edip tahsilatı deftere işlemek.
 *
 * Akışları birebir aynı değil — sözleşme bu farkı gizlemeye çalışmaz:
 *   PayTR : sunucudan sunucuya POST (Bildirim URL) + hash. İmza yerelde doğrulanır.
 *   iyzico: müşterinin tarayıcısı `token` ile geri döner; SONUÇ sunucudan sorgulanır
 *           (tarayıcıdan gelen hiçbir değere güvenilmez).
 *
 * `verifyNotification()` yetkisiz isteği de güvenle karşılar: tanımadığı token için
 * `recognized: false` döner, imzasız istek için `valid: false`.
 */
interface PaymentGateway
{
    /**
     * Ödeme sayfasını açar.
     *
     * @return array{ok: bool, pay_url: string|null, provider_token: string|null, error: string|null}
     */
    public function createCheckout(PaymentLink $link, ?string $payerIp): array;

    /** Bildirim/geri dönüş isteğindeki işlem anahtarımız (bizim `token` alanımız). */
    public function notificationToken(Request $request): ?string;

    /**
     * İsteği doğrular ve ödeme sonucunu döner.
     *
     * @return array{
     *     recognized: bool,   // istek bizim linkimize mi ait
     *     valid: bool,        // imza/hash doğru mu (false → kayıt YAZILMAZ)
     *     outcome: string,    // paid|failed|pending
     *     amount: float|null, // sağlayıcının tahsil ettiği tutar
     *     reference: string|null,
     *     reason: string|null,
     *     message: string|null, // loglanacak teknik sebep (kullanıcıya basılmaz)
     * }
     */
    public function verifyNotification(Request $request, PaymentLink $link): array;
}
