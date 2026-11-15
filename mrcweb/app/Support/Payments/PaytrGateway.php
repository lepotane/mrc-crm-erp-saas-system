<?php

namespace App\Support\Payments;

use App\Models\PaymentCredential;
use App\Models\PaymentLink;
use Illuminate\Http\Request;

/**
 * PayTR — iFrame API entegrasyonu .
 *
 * Doğrulanmış akış (dev.paytr.com → iFrame API 1. ve 2. Adım):
 *  1. Adım: sunucudan `POST /odeme/api/get-token` (form). Yanıt JSON:
 *     `{"status":"success","token":"…"}` ya da `{"status":"failed","reason":"…"}`.
 *     Ödeme sayfası: `<base>/odeme/guvenli/{token}` (iframe veya tam sayfa yönlendirme).
 *     İstek imzası: base64( hmac_sha256(
 *        merchant_id + user_ip + merchant_oid + email + payment_amount + user_basket
 *        + no_installment + max_installment + currency + test_mode + merchant_salt,
 *        merchant_key, raw ) )
 *  2. Adım: sonuç MAĞAZA PANELİNDE tanımlanan Bildirim URL'sine POST edilir
 *     (merchant_oid, status, total_amount, hash, payment_type, currency, payment_amount,
 *      failed_reason_code/msg, test_mode). İmza:
 *        base64( hmac_sha256( merchant_oid + merchant_salt + status + total_amount, merchant_key, raw ) )
 *     Yanıt GÖVDESİ tam olarak `OK` olmalı; aksi hâlde PayTR bildirimi tekrar dener.
 *
 * ÖNEMLİ: Bildirim URL mağaza panelinde TANIMLANIR, istekte gönderilmez. Çok kiracılı
 * yapıda tek bir ortak URL kullanılır (`/api/paytr/callback`); hangi firmaya ait olduğu
 * `merchant_oid` = bizim link `token`ımız üzerinden çözülür.
 *
 * `payment_amount` ve `total_amount` PARA BİRİMİNİN 100 KATI cinsindendir (34.56 → 3456);
 * taksit seçilince total_amount, payment_amount'tan büyük olabilir — deftere İŞLENEN
 * tutar total_amount'dır (dokümanın kendi uyarısı).
 */
class PaytrGateway extends BaseGateway
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.paytr.base_url', 'https://www.paytr.com'), '/');
    }

    /** PayTR'da ayrı sandbox sunucusu YOKTUR; deneme `test_mode=1` ile yapılır. */
    protected function isTestMode(): bool
    {
        return (bool) $this->credential->is_sandbox;
    }

    public function createCheckout(PaymentLink $link, ?string $payerIp): array
    {
        $merchantId = $this->credential->field('merchant_id');
        $merchantKey = $this->credential->field('merchant_key');
        $merchantSalt = $this->credential->field('merchant_salt');

        if ($merchantId === null || $merchantKey === null || $merchantSalt === null) {
            return $this->fail(__('PayTR hesap bilgileri eksik. Ayarlar → Ödeme Ayarları bölümünden tamamlayın.'));
        }

        $testMode = $this->isTestMode() ? 1 : 0;
        $noInstallment = (int) ($this->option('no_installment', 0) ? 1 : 0);
        $maxInstallment = (int) $this->option('max_installment', 0);
        $timeoutLimit = (int) $this->option('timeout_limit', 30);

        $userBasket = base64_encode(json_encode([
            [$link->basketLabel(), (float) $link->amount, 1],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $userIp = $payerIp ?: '127.0.0.1';
        $merchantOid = $link->token;
        $email = (string) ($link->party?->email ?: $link->company?->email ?: '');
        $paymentAmount = $link->paytrAmount();
        $currency = $link->paytrCurrency();

        // İmza gövdesi DOKÜMANDaki sırayla birebir kurulur; sıra değişirse PayTR "yetki hatası"
        // verir ve hata sağladığın tarafında kalır — bu yüzden burada yorumla sabitlendi.
        $hashString = $merchantId.$userIp.$merchantOid.$email.$paymentAmount
            .$userBasket.$noInstallment.$maxInstallment.$currency.$testMode.$merchantSalt;

        $paytrToken = base64_encode(hash_hmac('sha256', $hashString, $merchantKey, true));

        $response = $this->http()->asForm()->post($this->baseUrl().'/odeme/api/get-token', [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_amount' => $paymentAmount,
            'paytr_token' => $paytrToken,
            'user_basket' => $userBasket,
            'debug_on' => $testMode, // deneme modunda hata mesajı geri gelir; canlıda kapalı
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => mb_substr((string) ($link->party?->name ?: __('Misafir')), 0, 60),
            'user_address' => mb_substr((string) ($link->party?->address ?: $link->company?->address ?: '-'), 0, 400),
            'user_phone' => mb_substr(preg_replace('/\D+/', '', (string) ($link->party?->phone ?: '')) ?: '0000000000', 0, 20),
            'merchant_ok_url' => url('/odeme/'.$link->token),
            'merchant_fail_url' => url('/odeme/'.$link->token),
            'timeout_limit' => $timeoutLimit,
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => app()->getLocale() === 'en' ? 'en' : 'tr',
        ]);

        if ($response->failed() && ! $response->json()) {
            return $this->fail(
                __('PayTR ödeme sayfası açılamadı. Birazdan yeniden deneyin.'),
                'http '.$response->status().' '.$response->body()
            );
        }

        $body = $response->json() ?? [];

        if (($body['status'] ?? null) === 'success' && ! empty($body['token'])) {
            return $this->ok(
                $this->baseUrl().'/odeme/guvenli/'.$body['token'],
                (string) $body['token']
            );
        }

        // PayTR hatayı `reason` ile söyler (eksik alan / yetki / limit). Sebebi loglarız,
        // kullanıcıya kendi cümlemizi basarız: sağlayıcının ham metni kullanıcıya bir şey
        // ifade etmez ve hangi alanı düzelteceğini söylemez.
        return $this->fail(
            __('PayTR ödeme sayfası açılamadı. Hesap bilgilerinizi ve limitinizi kontrol edin.'),
            (string) ($body['reason'] ?? json_encode($body))
        );
    }

    public function notificationToken(Request $request): ?string
    {
        $oid = $request->input('merchant_oid');

        return is_string($oid) && $oid !== '' ? $oid : null;
    }

    public function verifyNotification(Request $request, PaymentLink $link): array
    {
        $merchantKey = $this->credential->field('merchant_key');
        $merchantSalt = $this->credential->field('merchant_salt');

        $status = (string) $request->input('status', '');
        $totalAmount = (string) $request->input('total_amount', '');
        $receivedHash = (string) $request->input('hash', '');

        if ($merchantKey === null || $merchantSalt === null || $status === '' || $receivedHash === '') {
            return $this->unrecognized();
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $link->token.$merchantSalt.$status.$totalAmount,
            $merchantKey,
            true
        ));

        if (! hash_equals($expected, $receivedHash)) {
            // İmza tutmayan istek: tahsilat YAZILMAZ. PayTR'a "OK" basmayız ki
            // bildirim tekrar denensin ve panelde "Devam Ediyor" olarak görünsün.
            return [
                'recognized' => true,
                'valid' => false,
                'outcome' => 'pending',
                'amount' => null,
                'reference' => null,
                'reason' => null,
                'message' => 'paytr hash mismatch',
            ];
        }

        $amount = $totalAmount === '' ? null : round(((int) $totalAmount) / 100, 4);
        $isTestNotice = (string) $request->input('test_mode', '0') === '1';

        if (! $this->credential->is_sandbox && $isTestNotice) {
            // Canlı hesapta test bildirimi = gerçek para değil. Deftere gelir kaydı koymak
            // muhasebeyi kirletir; kayıt bekletilir, log'a düşer.
            return [
                'recognized' => true,
                'valid' => true,
                'outcome' => 'pending',
                'amount' => $amount,
                'reference' => (string) $request->input('payment_type', ''),
                'reason' => null,
                'message' => 'paytr test_mode notification on live credential',
            ];
        }

        return [
            'recognized' => true,
            'valid' => true,
            'outcome' => $status === self::STATUS_SUCCESS ? 'paid' : 'failed',
            'amount' => $amount,
            // Referans TAHSİLAT KİMLİĞİDİR — bearer token DEĞİLDİR.
            // Token buraya yazılırsa `payments.reference` ve `payment_links.reference`
            // kolonlarında DÜZ METİN kalır ve veritabanı sızıntısında ödeme
            // sayfası açılabilir. Bu yüzden link id'si + durum yazılır.
            'reference' => 'link-'.$link->id.':'.$status.':'.(string) $request->input('payment_type', ''),
            'reason' => $status === self::STATUS_SUCCESS
                ? null
                : (trim(sprintf(
                    '%s %s',
                    (string) $request->input('failed_reason_code', ''),
                    (string) $request->input('failed_reason_msg', '')
                )) ?: __('Ödeme sağlayıcı işlemi onaylamadı.')),
            'message' => null,
        ];
    }

    /**
     * PayTR'da sonuç ZATEN sunucuya POST edilir; ayrıca sorgulanacak bir uç yok
     * (Link API ayrı bir üründür ve mağaza panelinde ayrıca tanımlanır).
     */
    public function supportsPullResult(): bool
    {
        return false;
    }

    public static function provider(): string
    {
        return PaymentCredential::PROVIDER_PAYTR;
    }
}
