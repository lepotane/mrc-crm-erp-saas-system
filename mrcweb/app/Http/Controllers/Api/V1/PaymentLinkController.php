<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Party;
use App\Models\PaymentCredential;
use App\Models\PaymentLink;
use App\Services\DocumentSettlement;
use App\Support\Audit;
use App\Support\Payments\GatewayResolver;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ödeme linkleri .
 *
 * Satış akışının eksik halkasıydı: fatura kesiliyor, tahsilat için müşteriye IBAN
 * yazılıyor ya da elle "ödendi" işaretleniyordu. Link üretildiğinde müşteri kendi
 * kartıyla ödeme yapar, tahsilat IMZASI DOĞRULANMIŞ bildirimle kendiliğinden deftere işler.
 *
 * Kapı sırası: kiracı (404) → yetki/iş kuralı (422) → plan limiti (422).
 *
 * ÖNEMLİ ayrım: link üretmek PARA TAHSİLATI DEĞİLDİR; yalnızca ödeme sayfası açmaktır.
 * Tahsilat `OnlinePaymentRecorder` ile bildirime dayanır. Bu yüzden link satırı
 * başarısız olursa (sağlayıcı hattı kapalı) geride yarım kayıt bırakmıyoruz.
 */
class PaymentLinkController extends Controller
{
    use ResolvesTenant, PresentsPaginator;

    /** Link para birimleri: belge para birimiyle aynı üçlü (bkz. settings.currency_code). */
    public const CURRENCIES = ['TRY', 'USD', 'EUR'];

    /**
     * Ödeme linkleri listesi.
     *
     * @response array{data: array<int, array{id: int, token: string, provider: string, provider_label: string, party_id: int, party_name: string|null, document_id: int|null, document_serial: string|null, amount: float, currency_code: string, status: string, status_label: string, pay_url: string|null, reference: string|null, failure_reason: string|null, payment_id: int|null, is_expired: bool, note: string|null, paid_at: string|null, expires_at: string|null, created_at: string|null, created_by_name: string|null}>, links: array, meta: array}
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $query = PaymentLink::with(['party', 'document', 'creator'])
            ->where('company_id', $companyId);

        if (($status = $request->query('status')) && in_array($status, PaymentLink::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (($provider = $request->query('provider')) && in_array($provider, PaymentCredential::PROVIDERS, true)) {
            $query->where('provider', $provider);
        }

        if ($request->filled('document_id')) {
            $query->where('document_id', (int) $request->query('document_id'));
        }

        if ($request->filled('party_id')) {
            $query->where('party_id', (int) $request->query('party_id'));
        }

        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $query->where(function ($q) use ($search) {
                // Token şifreli saklandığı için yalnız tam eşleşme aranabilir
                // (sha256 özeti üzerinden).
                $q->where('token_hash', PaymentLink::hashToken($search))
                    ->orWhereHas('party', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('document', fn ($d) => $d->where('serial', 'like', "%{$search}%"));
            });
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        $paginator->getCollection()->transform(fn (PaymentLink $link) => $link->toRow());

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Ödeme linki üret (PayTR veya iyzico ödeme sayfası açılır).
     *
     * @response array{payment_link: array{id: int, token: string, provider: string, provider_label: string, party_id: int, party_name: string|null, document_id: int|null, document_serial: string|null, amount: float, currency_code: string, status: string, status_label: string, pay_url: string|null, reference: string|null, failure_reason: string|null, payment_id: int|null, is_expired: bool, note: string|null, paid_at: string|null, expires_at: string|null, created_at: string|null, created_by_name: string|null}, message: string}
     */
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);
        $company = $this->tenantCompany($request);

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(PaymentCredential::PROVIDERS)],
            'document_id' => ['nullable', 'integer'],
            'party_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'currency_code' => ['nullable', 'string', Rule::in(self::CURRENCIES)],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // --- kiracı kapısı (404) ---
        $document = null;

        if (! empty($data['document_id'])) {
            $document = Document::where('company_id', $companyId)->find((int) $data['document_id']);

            if ($document === null) {
                abort(404, __('Belge bulunamadı.'));
            }
        }

        $partyId = $document?->party_id ?? ($data['party_id'] ?? null);

        if ($partyId === null) {
            throw ValidationException::withMessages([
                'party_id' => [__('Ödeme linki için cari hesap seçin (veya bir belgeye bağlayın).')],
            ]);
        }

        $party = Party::where('company_id', $companyId)->find((int) $partyId);

        if ($party === null) {
            abort(404, __('Cari kaydı bulunamadı.'));
        }

        // --- iş kuralları (422) ---
        $currency = (string) ($data['currency_code'] ?? $document?->currency_code ?? $this->companyCurrency($request));

        if ($document !== null) {
            $this->ensureDocumentLinkable($document, $currency);
        }

        // Para direction'ı 'in' olacağı için cari "bize borçlu" taraftan olmalı —
        // PaymentController.ensureDirectionMatchesParty ile aynı kural.
        if (! in_array($party->type, ['customer', 'both'], true)) {
            throw ValidationException::withMessages([
                'party_id' => [__('Bu cari hesap tipine ödeme linki gönderilemez (yalnız müşteri carileri).')],
            ]);
        }

        $amount = round((float) $data['amount'], 2);

        if ($document !== null) {
            $open = DocumentSettlement::openAmount($document);

            if ($amount > $open + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => [__(
                        'Link tutarı belgenin kalan alacağından fazla olamaz (kalan: :open :currency).',
                        ['open' => number_format($open, 2, ',', '.'), 'currency' => $document->currency_code]
                    )],
                ]);
            }
        }

        // Müşterinin ödeme sayfasında adı/iletişimi görünür; e-posta PayTR'da ZORUNLU,
        // iyzico'da alıcı bilgilerinin parçası. Eksikse sağlayıcı reddeder ve kullanıcı
        // "neden açılmadı" anlamaz — burada, linki üretmeden söylüyoruz.
        $email = trim((string) ($party->email ?: $company->email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'party_id' => [__('Ödeme linki göndermek için caride geçerli bir e-posta adresi olmalı. Cariyi güncelleyin.')],
            ]);
        }

        if ($data['provider'] === PaymentCredential::PROVIDER_IYZICO && preg_replace('/\D+/', '', (string) $party->phone) === '') {
            throw ValidationException::withMessages([
                'party_id' => [__('iyzico ile ödeme linki için caride telefon numarası olmalı. Cariyi güncelleyin.')],
            ]);
        }

        // --- plan limiti (422) ---
        PlanLimits::for($company)->assertWithinLimit('payment_links_per_month', $company);

        // --- sağlayıcı hesabı (422) ---
        $credential = GatewayResolver::usableCredential($company, (string) $data['provider']);

        $days = (int) ($data['valid_days'] ?? PaymentLink::DEFAULT_VALID_DAYS);

        $link = DB::transaction(function () use ($companyId, $party, $document, $amount, $currency, $data, $days, $request) {
            $token = PaymentLink::generateToken();

            return PaymentLink::create([
                'company_id' => $companyId,
                // Token şifrelenir (encrypted cast); arama özeti ayrı kolonda.
                'token' => $token,
                'token_hash' => PaymentLink::hashToken($token),
                'provider' => $data['provider'],
                'party_id' => $party->id,
                'document_id' => $document?->id,
                'amount' => $amount,
                'currency_code' => $currency,
                'status' => PaymentLink::STATUS_PENDING,
                'expires_at' => now()->addDays($days),
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        $gateway = GatewayResolver::gateway($credential);
        $result = $gateway->createCheckout($link, $request->ip());

        if (! ($result['ok'] ?? false)) {
            // Yarım kayıt bırakmıyoruz: listelerde "bağlantısı olmayan link" görünür ve
            // satıcı hangisinin gerçek olduğunu ayırt edemez. Sebeb log'a düşer.
            Log::warning('Ödeme linki üretilemedi', [
                'company_id' => $companyId,
                'provider' => $data['provider'],
                'payment_link_id' => $link->id,
                'message' => $result['message'] ?? null,
            ]);

            $link->delete();

            throw ValidationException::withMessages([
                'provider' => [ (string) ($result['error'] ?? __('Ödeme sayfası açılamadı. Birazdan yeniden deneyin.')) ],
            ]);
        }

        $providerToken = $result['provider_token'] ?? null;

        $link->update([
            // Sağlayıcı jetonu da şifrelenir; arama özeti ayrı kolonda.
            'provider_token' => $providerToken,
            'provider_token_hash' => $providerToken !== null
                ? PaymentLink::hashProviderToken((string) $providerToken)
                : null,
            'pay_url' => $result['pay_url'] ?? null,
        ]);

        Audit::fromRequest($request, 'payment_link.created', $companyId, $link->refresh(), [
            'provider' => $link->provider,
            'amount' => (float) $link->amount,
            'document_serial' => $document?->serial,
        ]);

        return response()->json([
            'payment_link' => $link->refresh()->load(['party', 'document', 'creator'])->toRow(),
            'message' => __('Ödeme linki hazır. Bağlantıyı müşteriye gönderin.'),
        ], 201);
    }

    /**
     * Tek link.
     *
     * @response array{payment_link: array{id: int, token: string, provider: string, provider_label: string, party_id: int, party_name: string|null, document_id: int|null, document_serial: string|null, amount: float, currency_code: string, status: string, status_label: string, pay_url: string|null, reference: string|null, failure_reason: string|null, payment_id: int|null, is_expired: bool, note: string|null, paid_at: string|null, expires_at: string|null, created_at: string|null, created_by_name: string|null}}
     */
    public function show(Request $request, PaymentLink $paymentLink)
    {
        $this->ensureTenant($request, $paymentLink);

        return response()->json([
            'payment_link' => $paymentLink->load(['party', 'document', 'creator'])->toRow(),
        ]);
    }

    /**
     * Linki kapat (iptal). Ödenmiş link kapatılamaz; iade gerekiyorsa sağladan yürür.
     *
     * @response array{payment_link: array{id: int, token: string, provider: string, provider_label: string, party_id: int, party_name: string|null, document_id: int|null, document_serial: string|null, amount: float, currency_code: string, status: string, status_label: string, pay_url: string|null, reference: string|null, failure_reason: string|null, payment_id: int|null, is_expired: bool, note: string|null, paid_at: string|null, expires_at: string|null, created_at: string|null, created_by_name: string|null}, message: string}
     */
    public function cancel(Request $request, PaymentLink $paymentLink)
    {
        $this->ensureTenant($request, $paymentLink);

        if ($paymentLink->status === PaymentLink::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => [__('Ödenmiş ödeme linki iptal edilemez. İade işlemi ödeme kuruluşundan yürütülür.')],
            ]);
        }

        if ($paymentLink->status === PaymentLink::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => [__('Bu ödeme linki zaten iptal edilmiş.')],
            ]);
        }

        $paymentLink->update(['status' => PaymentLink::STATUS_CANCELLED]);

        Audit::fromRequest($request, 'payment_link.cancelled', $paymentLink->company_id, $paymentLink, [
            'provider' => $paymentLink->provider,
            'amount' => (float) $paymentLink->amount,
        ]);

        return response()->json([
            'payment_link' => $paymentLink->refresh()->load(['party', 'document', 'creator'])->toRow(),
            'message' => __('Ödeme linki iptal edildi.'),
        ]);
    }

    /** Belge → link uyumu: Para Hareketi Doğuran belge + ihraç edilmiş + aynı para birimi. */
    protected function ensureDocumentLinkable(Document $document, string $currency): void
    {
        if ($document->type !== Document::TYPE_INVOICE) {
            throw ValidationException::withMessages([
                'document_id' => [__('Ödeme linki yalnız satış faturası için oluşturulabilir.')],
            ]);
        }

        if (in_array($document->status, ['draft', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'document_id' => [__(
                    ':status durumundaki belge için ödeme linki oluşturulamaz. Önce belgeyi ihraç edin.',
                    ['status' => Document::statusLabel($document->status)]
                )],
            ]);
        }

        if ($document->currency_code !== $currency) {
            throw ValidationException::withMessages([
                'currency_code' => [__('Ödeme para birimi belge para birimiyle aynı olmalıdır.')],
            ]);
        }
    }

    /** Kiracı kapısı: başka firmanın linki YOK sayılır (404), yetki hatası değil. */
    protected function ensureTenant(Request $request, PaymentLink $link): void
    {
        abort_if($link->company_id !== $this->tenantId($request), 404, __('Ödeme linki bulunamadı.'));
    }

    protected function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 20)));
    }
}
