<?php

namespace App\Support\Payments;

use App\Models\PaymentCredential;
use App\Models\PaymentLink;
use Illuminate\Http\Request;

/**
 * iyzico — Ödeme Formu (CheckoutForm / CF) entegrasyonu .
 *
 * Doğrulanmış akış (docs.iyzico.com → CF Başlatma, CF Sorgulama, İmza Yanıtının
 * Doğrulanması, HMACSHA256 Kimlik Doğrulama):
 *
 *  Yetkilendirme (her API çağrısı):
 *     signature   = hex( hmac_sha256( randomKey + uri.path + request.body, secretKey ) )
 *     Authorization: "IYZWSv2 " + base64("apiKey:…&randomKey:…&signature:…")
 *  → İmzalanan gövde GÖNDERİLEN gövde ile bayt bayt aynı olmalı; bu yüzden JSON
 *    tek kez üretilir ve `withBody` ile aynısı gönderilir.
 *
 *  Başlatma: POST /payment/iyzipos/checkoutform/initialize/auth/ecom
 *     zorunlu: price, paidPrice, currency, callbackUrl, buyer, shippingAddress,
 *              billingAddress, basketItems
 *     yanıt  : token + paymentPageUrl (yanıta göre ödeme sayfası açılır)
 *     yanıt imzası (varsa): conversationId:token
 *
 *  Sonuç: müşteri `callbackUrl`'e YÖNLENDİRİLİR ve orada yalnız `token` bulunur;
 *  ödeme sonucu ORADAN OKUNMAZ. Sunucu `POST /payment/iyzipos/checkoutform/auth/ecom/detail`
 *  ile sonucu yeniden sorgular:
 *     yanıt imzası: paymentStatus:paymentId:currency:basketId:conversationId:paidPrice:price:token
 *     (fiyatlar ardıl sıfırlardan arındırılmış hâliyle: "10.50" → "10.5")
 *
 * Bu ayırımı bilmeden kurulan entegrasyon "müşteri geri döndü = para geldi" hatasına düşer;
 * yönlendirme sahtelenebilir. Bu sınıf sonucu her zaman DETAIL ucuyla doğrular.
 */
class IyzicoGateway extends BaseGateway
{
    private const PATH_INITIALIZE = '/payment/iyzipos/checkoutform/initialize/auth/ecom';

    private const PATH_DETAIL = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    protected function baseUrl(): string
    {
        $base = $this->credential->is_sandbox
            ? config('services.iyzico.sandbox_base_url', 'https://sandbox-api.iyzipay.com')
            : config('services.iyzico.base_url', 'https://api.iyzipay.com');

        return rtrim((string) $base, '/');
    }

    public function createCheckout(PaymentLink $link, ?string $payerIp): array
    {
        $apiKey = $this->credential->field('api_key');
        $secretKey = $this->credential->field('secret_key');

        if ($apiKey === null || $secretKey === null) {
            return $this->fail(__('iyzico hesap bilgileri eksik. Ayarlar → Ödeme Ayarları bölümünden tamamlayın.'));
        }

        $party = $link->party;
        $price = $this->iyzicoPrice((float) $link->amount);

        $body = [
            'locale' => app()->getLocale() === 'en' ? 'en' : 'tr',
            'conversationId' => $link->token,
            'price' => $price,
            'paidPrice' => $price,
            'currency' => $link->currency_code,
            'basketId' => $link->token,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => url(config('services.iyzico.callback_path', '/api/iyzico/callback')),
            'buyer' => [
                'id' => (string) $party?->id,
                'name' => $this->firstName((string) ($party?->name ?: __('Müşteri'))),
                'surname' => $this->lastName((string) ($party?->name ?: __('Müşteri'))),
                // iyzico kimlik/vkn alanını zorunlu tutar; elimizde yoksa boş değil
                // tarafsız bir değer gider (gerçek kimlik istemek bu ürünün işi değil).
                'identityNumber' => (string) ($party?->tax_number ?: '1111111111'),
                'email' => (string) ($party?->email ?: $link->company?->email ?: ''),
                'gsmNumber' => preg_replace('/\D+/', '', (string) ($party?->phone ?: '')) ?: '5550000000',
                'registrationAddress' => mb_substr((string) ($party?->address ?: $link->company?->address ?: '-'), 0, 100),
                'city' => (string) ($party?->city ?: $link->company?->city ?: 'Istanbul'),
                'country' => 'Turkey',
                'ip' => $payerIp ?: '127.0.0.1',
            ],
            // Sepet kalem tipi VIRTUAL: hizmet/ödeme tahsilatıdır, kargo yok.
            // Alan şemada "zorunlu" göründüğü için adres kopyalanır — uymazsa iyzico
            // hata mesajını `message` ile loglara taşırız.
            'shippingAddress' => $this->address($link),
            'billingAddress' => $this->address($link),
            'basketItems' => [[
                'id' => $link->token,
                'name' => mb_substr($link->basketLabel(), 0, 60),
                'category1' => '1000',
                'price' => $price,
                'itemType' => 1,
            ]],
        ];

        return $this->call(self::PATH_INITIALIZE, $body, $apiKey, $secretKey, function (array $res) use ($link) {
            if (! empty($res['token']) && ! empty($res['paymentPageUrl'])) {
                // Yanıt imzası varsa doğrula (conversationId:token). Yoksa da kabul ederiz:
                // yanıt TARAYICIDAN gelmiyor, bizim açtığımız kimlikli HTTPS çağrısından
                // geliyor — sahtelenmesi mümkün değil.
                $signature = (string) ($res['signature'] ?? '');

                if ($signature !== '') {
                    $expected = hash_hmac('sha256', $link->token.':'.$res['token'], $this->credential->field('secret_key'));

                    if (! hash_equals($expected, $signature)) {
                        return $this->fail(
                            __('iyzico ödeme sayfası açılamadı. Hesap bilgilerinizi kontrol edin.'),
                            'iyzico initialize signature mismatch'
                        );
                    }
                }

                return $this->ok((string) $res['paymentPageUrl'], (string) $res['token']);
            }

            return $this->fail(
                __('iyzico ödeme sayfası açılamadı. Hesap bilgilerinizi ve limitinizi kontrol edin.'),
                ($res['errorCode'] ?? '').' '.($res['errorMessage'] ?? json_encode($res))
            );
        });
    }

    public function notificationToken(Request $request): ?string
    {
        $token = $request->input('token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function verifyNotification(Request $request, PaymentLink $link): array
    {
        $providerToken = $link->provider_token;

        if (! $providerToken) {
            return $this->unrecognized();
        }

        $apiKey = $this->credential->field('api_key');
        $secretKey = $this->credential->field('secret_key');

        if ($apiKey === null || $secretKey === null) {
            return $this->unrecognized();
        }

        $result = $this->call(self::PATH_DETAIL, [
            'locale' => 'tr',
            'conversationId' => $link->token,
            'token' => $providerToken,
        ], $apiKey, $secretKey, fn (array $res) => ['ok' => true, 'raw' => $res]);

        if (! ($result['ok'] ?? false)) {
            return [
                'recognized' => true,
                'valid' => false,
                'outcome' => 'pending',
                'amount' => null,
                'reference' => null,
                'reason' => null,
                'message' => (string) ($result['message'] ?? 'iyzico detail call failed'),
            ];
        }

        $res = $result['raw'];

        // basketId/conversationId bizim gönderdiğimiz token olmalı: sağladan gelen
        // başka bir işlemin sonucu bu linkin hanesine yazılmaz.
        $ownsIt = ($res['basketId'] ?? null) === $link->token
            || ($res['conversationId'] ?? null) === $link->token
            || ($res['token'] ?? null) === $providerToken;

        if (! $ownsIt) {
            return [
                'recognized' => true,
                'valid' => false,
                'outcome' => 'pending',
                'amount' => null,
                'reference' => null,
                'reason' => null,
                'message' => 'iyzico detail does not match link token',
            ];
        }

        $signature = (string) ($res['signature'] ?? '');

        if ($signature !== '') {
            $expected = hash_hmac('sha256', implode(':', [
                $this->normalizeResponseValue($res['paymentStatus'] ?? ''),
                $this->normalizeResponseValue($res['paymentId'] ?? ''),
                $this->normalizeResponseValue($res['currency'] ?? ''),
                $this->normalizeResponseValue($res['basketId'] ?? ''),
                $this->normalizeResponseValue($res['conversationId'] ?? ''),
                $this->stripTrailingZeros($res['paidPrice'] ?? ''),
                $this->stripTrailingZeros($res['price'] ?? ''),
                $this->normalizeResponseValue($res['token'] ?? ''),
            ]), $secretKey);

            if (! hash_equals($expected, $signature)) {
                return [
                    'recognized' => true,
                    'valid' => false,
                    'outcome' => 'pending',
                    'amount' => null,
                    'reference' => null,
                    'reason' => null,
                    'message' => 'iyzico detail signature mismatch',
                ];
            }
        }

        $success = ($res['status'] ?? null) === 'success' && ($res['paymentStatus'] ?? null) === 'success';

        return [
            'recognized' => true,
            'valid' => true,
            'outcome' => $success ? 'paid' : 'failed',
            'amount' => isset($res['paidPrice']) ? round((float) $res['paidPrice'], 4) : null,
            'reference' => isset($res['paymentId']) ? (string) $res['paymentId'] : $providerToken,
            'reason' => $success ? null : (string) (($res['errorCode'] ?? '').' '.($res['errorMessage'] ?? __('Ödeme sağlayıcı işlemi onaylamadı.'))),
            'message' => null,
        ];
    }

    /**
     * İmzalı yetkilendirme başlığıyla POST. JSON TEK kez üretilir: imzalanan metin ile
     * gövde bayt bayt aynı kalmalı (docs.iyzico.com HMACSHA256 Kimlik Doğrulama).
     */
    protected function call(string $path, array $payload, string $apiKey, string $secretKey, callable $handler): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $randomKey = (string) (int) (microtime(true) * 1000).random_int(100000, 999999);

        $signature = hash_hmac('sha256', $randomKey.$path.$body, $secretKey);

        $authorization = 'IYZWSv2 '.base64_encode("apiKey:{$apiKey}&randomKey:{$randomKey}&signature:{$signature}");

        $response = $this->http()
            ->withHeaders([
                'Authorization' => $authorization,
                'x-iyzi-rnd' => $randomKey,
            ])
            ->withBody($body, 'application/json')
            ->post($this->baseUrl().$path, []);

        $res = $response->json();

        if (! is_array($res)) {
            return $this->fail(
                __('Ödeme sağlayıcıya ulaşılamadı. Birazdan yeniden deneyin.'),
                'http '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        return $handler($res);
    }

    /** iyzico fiyatı ondalık METİN ister ("100.00"); float gönderilmez. */
    protected function iyzicoPrice(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    protected function address(PaymentLink $link): array
    {
        $party = $link->party;

        return [
            'contactName' => mb_substr((string) ($party?->name ?: __('Müşteri')), 0, 40),
            'city' => (string) ($party?->city ?: $link->company?->city ?: 'Istanbul'),
            'country' => 'Turkey',
            'address' => mb_substr((string) ($party?->address ?: $link->company?->address ?: '-'), 0, 100),
            'zipCode' => '34000',
        ];
    }

    /** Türkçe ticaret unvanları "Ad Soyad" biçiminde olmayabilir; iyzico ad+soyad ister. */
    protected function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return mb_substr((string) ($parts[0] ?? __('Müşteri')), 0, 40);
    }

    protected function lastName(string $name): string
    {
        $parts = array_slice(preg_split('/\s+/u', trim($name)) ?: [], 1);

        return mb_substr($parts ? implode(' ', $parts) : __('Müşteri'), 0, 40);
    }

    protected function normalizeResponseValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * "Ardıl sıfırlar" kuralı (docs.iyzico.com → İmza Yanıtının Doğrulanması):
     * 10.50 → 10.5, 10.0 → 10. İmza, iyzico'nun yanıtındaki YAZIMLA değil bu
     * yalınlaştırılmış hâliyle hesaplanır; satıcı tarafında elle yapılır.
     */
    protected function stripTrailingZeros(mixed $value): string
    {
        $text = $this->normalizeResponseValue($value);

        if ($text === '' || ! str_contains($text, '.')) {
            return $text;
        }

        $trimmed = rtrim($text, '0');

        // "10.00" → rtrim('0') → "10." → nokta da atılır → "10"
        if (str_ends_with($trimmed, '.')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        return $trimmed === '' ? '0' : $trimmed;
    }

    public static function provider(): string
    {
        return PaymentCredential::PROVIDER_IYZICO;
    }
}
