<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Document;
use App\Models\Invitation;
use App\Models\PaymentLink;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Plan limitleri.
 *
 * Limitler config/plans.php'de TEK yerde durur; buradaki sınıfla okunur, denetlenir ve
 * Ayarlar ekranına rapor edilir.
 *
 * Denetleme yeri = kaydı yaratan uç (davet, ürün, belge, ödeme linki). Kapı sırası:
 * önce KİRACI (404), sonra YETKİ (403), en son LİMİT (422) —
 * limit mesajı başka firmanın kaydının varlığını söylememeli.
 */
class PlanLimits
{
    /** Bu dilimde denetlenen kaynaklar (ödeme linki dahil). */
    public const SOURCES = ['users', 'products', 'documents_per_month', 'payment_links_per_month'];

    public function __construct(
        public readonly string $plan,
        public readonly string $label,
        public readonly array $limits,
        public readonly bool $hasCustomLimits = false,
    ) {}

    /** Firmanın planı. Bilinmeyen plan adı = config'te yoksa VARSAYILANA düşer (sessiz sınırsızlık tehlikeli). */
    public static function for(Company $company): self
    {
        $plans = config('plans.plans', []);
        $plan = (string) ($company->plan ?: config('plans.default', 'standart'));

        if (! isset($plans[$plan])) {
            $plan = (string) config('plans.default', 'standart');
        }

        $baseLimits = (array) ($plans[$plan]['limits'] ?? []);

        // SüperAdmin tarafından firmaya özel belirlenen limitler (custom_limits) önceliklidir
        $customLimits = is_array($company->custom_limits) ? $company->custom_limits : [];
        $hasCustom = ! empty($customLimits);

        $mergedLimits = $baseLimits;
        foreach (self::SOURCES as $source) {
            if (array_key_exists($source, $customLimits)) {
                $val = $customLimits[$source];
                $mergedLimits[$source] = ($val === null || $val === '' || $val === 'unlimited') ? null : (int) $val;
            }
        }

        return new self(
            $plan,
            (string) ($plans[$plan]['label'] ?? $plan),
            $mergedLimits,
            $hasCustom,
        );
    }

    public function limitFor(string $resource): ?int
    {
        $limit = $this->limits[$resource] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Kaynağın şu anki kullanımı. `users` bekleyen davetleri de sayar: aksi hâlde
     * limitli planda davetlerlimit dışına taşırılıp sonra kabul ettirilebilirdi.
     *
     * @return array<string,int>
     */
    public function usage(Company $company): array
    {
        [$ayBaslangic, $aySonu] = $this->thisMonthBounds();

        return [
            'users' => $company->users()
                    ->where(function ($q) {
                        $q->whereNull('users.is_superadmin')->orWhere('users.is_superadmin', false);
                    })
                    ->where('users.is_superadmin', false)
                    ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'SuperAdmin'))
                    ->count()
                + Invitation::where('company_id', $company->id)->whereNull('accepted_at')->count(),
            'products' => Product::where('company_id', $company->id)->count(),
            'documents_per_month' => Document::where('company_id', $company->id)
                ->whereBetween('created_at', [$ayBaslangic, $aySonu])
                ->count(),
            'payment_links_per_month' => PaymentLink::where('company_id', $company->id)
                ->whereBetween('created_at', [$ayBaslangic, $aySonu])
                ->count(),
        ];
    }

    /**
     * Limitteysen 422 (doğrulama hatası biçiminde, çevrilmiş mesaj + anahtar).
     * ValidationException seçildi: SPA bu alanı zaten hata kutusu olarak basıyor ve
     * §6 sözleşmesi "hata gövdesi daima çevrilmiş message/errors" diyor.
     */
    public function assertWithinLimit(string $resource, Company $company, string $field = 'plan_limit'): void
    {
        $limit = $this->limitFor($resource);

        if ($limit === null) {
            return; // limitsiz plan
        }

        $usage = $this->usage($company)[$resource] ?? 0;

        if ($usage < $limit) {
            return;
        }

        $message = __($this->messageTemplate($resource), [
            'usage' => $usage,
            'limit' => $limit,
            'day' => (int) Carbon::now()->endOfMonth()->format('d'),
        ]);

        throw ValidationException::withMessages([$field => [$message]]);
    }

    /** Ayarlar ekranının "Plan ve Limitler" kutusu için düz dizi. */
    public function snapshot(Company $company): array
    {
        $usage = $this->usage($company);
        $limits = [];

        foreach (self::SOURCES as $resource) {
            $limits[] = [
                'resource' => $resource,
                'label' => __($this->labelTemplate($resource)),
                'used' => $usage[$resource] ?? 0,
                'limit' => $this->limitFor($resource),
                'remaining' => $this->limitFor($resource) === null
                    ? null
                    : max(0, $this->limitFor($resource) - ($usage[$resource] ?? 0)),
            ];
        }

        return [
            'plan' => $this->plan,
            'label' => $this->label,
            'period' => now()->format('Y-m'),
            'limits' => $limits,
            'has_custom_limits' => $this->hasCustomLimits,
        ];
    }

    protected function messageTemplate(string $resource): string
    {
        $messages = config('plans.messages', []);

        return (string) ($messages[$resource] ?? 'Bu planın limitine ulaştınız (:usage/:limit).');
    }

    protected function labelTemplate(string $resource): string
    {
        return match ($resource) {
            'users' => 'Kullanıcı',
            'products' => 'Ürün',
            'documents_per_month' => 'Aylık belge',
            'payment_links_per_month' => 'Aylık ödeme linki',
            default => $resource,
        };
    }

    /**
     * @return array{0: string, 1: string} takvim ayı sınırları (veritabanı yerel saati)
     */
    protected function thisMonthBounds(): array
    {
        return [
            now()->startOfMonth()->format('Y-m-d H:i:s'),
            now()->endOfMonth()->format('Y-m-d H:i:s'),
        ];
    }
}
