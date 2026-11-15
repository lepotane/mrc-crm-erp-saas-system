<?php

namespace App\Support\Payments;

use App\Models\PaymentCredential;
use Illuminate\Support\Facades\Http;

/**
 * Sağlayıcı istemcilerinin ortak gövdesi: kimlik + timeout + sırlara erişim.
 *
 * Timeout bilinçli: ödeme sayfası üretirken sağlayıcıya bağlanamamak "link yok" demek
 * değildir; satıcıya bekletmeden anlaşılır bir mesaj verilmek zorundadır (aksi hâlde
 * buton sonsuza kadar döner).
 */
abstract class BaseGateway implements PaymentGateway
{
    public function __construct(protected PaymentCredential $credential)
    {
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(25)->connectTimeout(8);
    }

    /**
     * Kimlik dışı seçenekler (taksit sınırı, oturum süresi gibi). Sır olmayan alanlar
     * da şifreli kolonda durur; önemli olan TEK yerde durmasıdır.
     */
    protected function option(string $key, int|bool|string|null $default = null): int|bool|string|null
    {
        $value = $this->credential->credentials[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    protected function ok(?string $url, ?string $token): array
    {
        return ['ok' => true, 'pay_url' => $url, 'provider_token' => $token, 'error' => null];
    }

    protected function fail(string $error, ?string $message = null): array
    {
        return ['ok' => false, 'pay_url' => null, 'provider_token' => null, 'error' => $error, 'message' => $message];
    }

    /** Tanımadığımız/ele alınacak bir bildirim için ortak gövde. */
    protected function unrecognized(): array
    {
        return [
            'recognized' => false,
            'valid' => false,
            'outcome' => 'pending',
            'amount' => null,
            'reference' => null,
            'reason' => null,
            'message' => null,
        ];
    }
}
