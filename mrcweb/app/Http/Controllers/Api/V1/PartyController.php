<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Party;
use App\Models\Payment;
use App\Support\ReportCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartyController extends Controller
{
    use ResolvesTenant;
    use \App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;

    /**
     * Cari hesapları listele (ara, tipe göre süzgeç, sayfalı)
     *
     * @response array{
     *     data: array<int,
     *     array{id: int, company_id: int, type: string, code: string, name: string, tax_number: string, phone: string, email: string, address: string|null, city: string, currency_code: string, opening_balance: string, credit_limit: string|null, notes: string|null, is_active: bool, contacts_count: int, created_at: string, contacts: array}>,
     *     links: array<int,
     *     array{url: string|null, label: string, page: int|null, active: bool}>,
     *     meta: array{current_page: int, from: int, last_page: int, per_page: int, to: int, total: float},
     * }
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);
        $type = $request->query('type');
        $search = trim((string) $request->query('search'));

        $query = Party::where('company_id', $companyId);

        // customer/supplier istenirse both olanlar da görünür
        if ($type && in_array($type, ['customer', 'supplier'], true)) {
            $query->whereIn('type', [$type, 'both']);
        } elseif ($type === 'supplier-or-customer') {
            $query->whereIn('type', ['customer', 'supplier', 'both']);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('name')
            ->withCount('contacts')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (Party $p) => $this->present($p, $request));

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Yeni cari hesap aç: müşteri veya tedarikçi, kod otomatik üretilir
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{message: string|null, party: array{id: int, company_id: int, type: string, code: string, name: string, tax_number: string|null, phone: string|null, email: string|null, address: string|null, city: string|null, currency_code: string, opening_balance: string, credit_limit: string|null, notes: string|null, is_active: bool, contacts_count: int|null, created_at: string, contacts: array}}',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'name', description: 'Cari adı (zorunlu)', required: true, type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'type',
        description: 'customer | supplier | both — boşsa customer',
        type: 'string',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'tax_number', description: 'Vergi / TC kimlik numarası', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'phone', description: 'Telefon', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'email', description: 'E-posta', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'address', description: 'Adres', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'city', description: 'Şehir', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'currency_code',
        description: 'TRY | USD | EUR — boşsa firmanın para birimi',
        type: 'string',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'opening_balance',
        description: 'Açılış bakiyesi (pozitif = bizim alacağımız)',
        type: 'float',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'credit_limit', description: 'Kredi limiti', type: 'float')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'notes', description: 'Not', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'is_active', description: 'Pasife almak için false', type: 'bool')]
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $this->validated($request);
        $data['company_id'] = $companyId;
        // Para birimi verilmediyse firmanın ana para birimi (Ayarlar → Genel Ayarlar).
        $data['currency_code'] ??= $this->companyCurrency($request);
        $data['code'] = Party::nextCode($companyId);

        $party = Party::create($data);

        // Cari açılış bakiyesi raporları etkiler (stok değeri değil, bakiye/yaşlandırma).
        ReportCache::bump($companyId);

        // DB varsayılanları (currency_code vb.) modele yansımalı
        return response()->json(['party' => $this->present($party->refresh())], 201);
    }

    /**
     * Cari hesap detayı (iletişim kişileri dahil)
     *
     * @response array{party: array{id: int, company_id: int, type: string, code: string, name: string, tax_number: string, phone: string, email: string, address: string|null, city: string, currency_code: string, opening_balance: string, credit_limit: string|null, notes: string|null, is_active: bool, contacts_count: int|null, created_at: string, contacts: array<int, array{id: int, name: string, title: string|null, phone: string, email: string|null, is_primary: bool}>}}
     */
    public function show(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $party->load('contacts');

        return response()->json(['party' => $this->present($party)]);
    }

    /**
     * Cari hesap bilgilerini güncelle
     *
     * @response array{party: array{id: int, company_id: int, type: string, code: string, name: string, tax_number: string, phone: string, email: string, address: string|null, city: string, currency_code: string, opening_balance: string, credit_limit: string|null, notes: string|null, is_active: bool, contacts_count: int|null, created_at: string, contacts: array<int, array{id: int, name: string, title: string|null, phone: string, email: string|null, is_primary: bool}>}}
     */
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'name', description: 'Cari adı (zorunlu)', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'type',
        description: 'customer | supplier | both — boşsa customer',
        type: 'string',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'tax_number', description: 'Vergi / TC kimlik numarası', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'phone', description: 'Telefon', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'email', description: 'E-posta', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'address', description: 'Adres', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'city', description: 'Şehir', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'currency_code',
        description: 'TRY | USD | EUR — boşsa firmanın para birimi',
        type: 'string',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'opening_balance',
        description: 'Açılış bakiyesi (pozitif = bizim alacağımız)',
        type: 'float',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'credit_limit', description: 'Kredi limiti', type: 'float')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'notes', description: 'Not', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'is_active', description: 'Pasife almak için false', type: 'bool')]
    public function update(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $party->update($this->validated($request, true));

        ReportCache::bump($party->company_id);

        return response()->json(['party' => $this->present($party->refresh())]);
    }

    /**
     * Cari hesabı sil (belge veya ödeme bağlantısı varsa silinemez)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        // documents.party_id ve payments.party_id cascadeOnDelete: hareket görmüş cari
        // silinirse belgeleri ve ödeme geçmişi de gider (veri kaybı). Bu yüzden engellenir.
        // çöp kutusundaki (soft-delete) belgeler sayılmaz; onlar carıyla birlikte düşer.
        $linked = Document::where('company_id', $party->company_id)
            ->where('party_id', $party->id)->count()
            + Payment::where('company_id', $party->company_id)
            ->where('party_id', $party->id)->count();

        if ($linked > 0) {
            // Depo/ürün/hesap guard’larıyla aynı desen: üst düzey message (arayüz
            // silme hatalarını buradan okur), alan hatası değil.
            return response()->json([
                'message' => __('Bu cari hesap silinemez: bağlı belge veya ödeme kaydı var.'),
            ], 422);
        }

        $party->delete();

        ReportCache::bump($party->company_id);

        return response()->json(['message' => __('Cari hesap silindi.')]);
    }

    /**
     * Cariye bağlı iletişim kişilerini listele
     *
     * Cari altı iletişim kişileri (çoklu temas desteği).
     *
     * @response array{contacts: array<int, array{id: int, name: string, title: string|null, phone: string|null, email: string|null, is_primary: bool}>}
     */
    public function contactsIndex(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        return response()->json(['contacts' => $party->contacts()->get(['id', 'name', 'title', 'phone', 'email', 'is_primary'])]);
    }

    /**
     * Cariye iletişim kişisi ekle
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{contact: array{id: int, name: string, title: string|null, phone: string, email: string|null, is_primary: bool}}',
    )]
    public function contactsStore(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        if ($data['is_primary'] ?? false) {
            $party->contacts()->update(['is_primary' => false]);
        }

        $contact = $party->contacts()->create($data);

        return response()->json(['contact' => $contact->only(['id', 'name', 'title', 'phone', 'email', 'is_primary'])], 201);
    }

    /**
     * İletişim kişisini sil
     *
     * @response array{message: string}
     */
    public function contactsDestroy(Request $request, Party $party, $contactId)
    {
        $this->authorizeParty($request, $party);

        $party->contacts()->where('id', $contactId)->firstOrFail()->delete();

        return response()->json(['message' => __('İletişim kişisi silindi.')]);
    }

    protected function authorizeParty(Request $request, Party $party): void
    {
        abort_if(
            $party->company_id !== $this->tenantId($request),
            404,
            __('Cari kaydı bulunamadı.')
        );
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:190'],
            'type' => ['nullable', 'string', 'in:customer,supplier,both'], // varsayılan aşağıda: customer
            'tax_number' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:60'],
            'currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'opening_balance' => ['nullable', 'numeric', 'min:-999999999'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $data = $request->validate($partial
            ? array_map(fn ($rule) => array_diff($rule, ['required']), $rules)
            : $rules);

        if (! $partial) {
            $data += ['type' => 'customer', 'opening_balance' => 0, 'is_active' => true];
        }

        return $data;
    }

    protected function present(Party $party, ?Request $request = null): array
    {
        $data = $party->only([
            'id', 'company_id', 'type', 'code', 'name', 'tax_number', 'phone', 'email',
            'address', 'city', 'currency_code', 'opening_balance', 'credit_limit',
            'notes', 'is_active', 'contacts_count', 'created_at',
        ]);

        $data['contacts'] = $request
            ? []
            : $party->contacts->map(fn ($c) => $c->only(['id', 'name', 'title', 'phone', 'email', 'is_primary']))->values();

        return $data;
    }
}
