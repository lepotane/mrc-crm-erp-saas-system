<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Currency;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
    use ResolvesTenant;

    /**
     * Kasa/banka hesaplarını listele (güncel bakiye ve ödeme sayısı ile)
     *
     * @response array{bank_accounts: array<int, array{id: int, name: string, type: string, iban: string, account_number: string|null, currency_code: string, opening_balance: string, is_default: bool, is_active: bool, payments_count: int, balance: float, note: string|null}>}
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);
        $type = $request->query('type');
        $active = $request->query('active');

        $accounts = BankAccount::where('company_id', $companyId)
            ->when($type && in_array($type, BankAccount::TYPES, true), fn ($q) => $q->where('type', $type))
            ->when($active === '1' || $active === '0', fn ($q) => $q->where('is_active', $active === '1'))
            ->withSum(['payments as in_total' => fn ($q) => $q->where('direction', 'in')], 'amount')
            ->withSum(['payments as out_total' => fn ($q) => $q->where('direction', 'out')], 'amount')
            ->withCount('payments')
            ->orderByDesc('is_default')->orderBy('name')
            ->get();

        return response()->json(['bank_accounts' => $accounts->map(fn (BankAccount $a) => $this->present($a))->values()]);
    }

    /**
     * Yeni kasa veya banka hesabi ac (ad, tip nakit/banka, opsiyonel IBAN/hesap no, para birimi, acilis bakiyesi)
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{bank_account: array{id: int, name: string, type: string, iban: string|null, account_number: string|null, currency_code: string, opening_balance: string, is_default: bool, is_active: bool, payments_count: int, balance: float, note: string|null}}',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'name',
        description: 'Hesap adı (firma içinde tek)',
        required: true,
        type: 'string',
    )]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'type', description: 'cash (kasa) | bank (banka)', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'account_number', description: 'Hesap numarası', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'iban', description: 'IBAN', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'currency_code', description: 'TRY | USD | EUR', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'opening_balance', description: 'Açılış bakiyesi', type: 'float')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'is_default', description: 'Varsayılan hesap', type: 'bool')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'is_active', description: 'Pasife almak için false', type: 'bool')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'note', description: 'Not', type: 'string')]
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);
        $data = $this->validated($request, $companyId, false);

        $account = DB::transaction(function () use ($data, $companyId) {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                BankAccount::where('company_id', $companyId)->update(['is_default' => false]);
            } elseif (BankAccount::where('company_id', $companyId)->count() === 0) {
                $isDefault = true; // firmanın ilk hesabı varsayılan
            }

            // ⚠ PHP’de $a + $b dizisinde SOL anahtarlar kazanır: $data içinde
            // is_default (false) geldiği için yukarıda hesaplanan değer ezilmesin.
            return BankAccount::create(array_merge($data, ['company_id' => $companyId, 'is_default' => $isDefault]));
        });

        return response()->json(['bank_account' => $this->present($account->refresh())], 201);
    }

    /**
     * Kasa/banka hesabını güncelle
     *
     * @response array{bank_account: array{id: int, name: string, type: string, iban: string|null, account_number: string|null, currency_code: string, opening_balance: string, is_default: bool, is_active: bool, payments_count: int, balance: float, note: string}}
     */
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'name', description: 'Hesap adı (firma içinde tek)', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'type', description: 'cash (kasa) | bank (banka)', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'account_number', description: 'Hesap numarası', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'iban', description: 'IBAN', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'currency_code', description: 'TRY | USD | EUR', type: 'string')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'opening_balance', description: 'Açılış bakiyesi', type: 'float')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'is_default', description: 'Varsayılan hesap', type: 'bool')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'is_active', description: 'Pasife almak için false', type: 'bool')]
    #[\Dedoc\Scramble\Attributes\BodyParameter(name: 'note', description: 'Not', type: 'string')]
    public function update(Request $request, BankAccount $bankAccount)
    {
        abort_if($bankAccount->company_id !== $this->tenantId($request), 404, __('Kasa/banka hesabı bulunamadı.'));

        $data = $this->validated($request, $bankAccount->company_id, true, $bankAccount->id);

        DB::transaction(function () use ($data, $request, $bankAccount) {
            if ($data['is_default'] ?? false) {
                BankAccount::where('company_id', $this->tenantId($request))
                    ->where('id', '!=', $bankAccount->id)
                    ->update(['is_default' => false]);
            }
            $bankAccount->update($data);
        });

        return response()->json(['bank_account' => $this->present($bankAccount->refresh())]);
    }

    /**
     * Kasa/banka hesabını sil (ödeme hareketi varsa silinemez)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, BankAccount $bankAccount)
    {
        abort_if($bankAccount->company_id !== $this->tenantId($request), 404, __('Kasa/banka hesabı bulunamadı.'));

        if (Payment::where('bank_account_id', $bankAccount->id)->exists()) {
            return response()->json([
                'message' => __('Bu hesapta ödeme kaydı mevcut; silinemez.'),
            ], 422);
        }

        $wasDefault = $bankAccount->is_default;
        $bankAccount->delete();

        if ($wasDefault) {
            BankAccount::where('company_id', $this->tenantId($request))->first()?->update(['is_default' => true]);
        }

        return response()->json(['message' => __('Kasa/banka hesabı silindi.')]);
    }

    protected function validated(Request $request, int $companyId, bool $partial, ?int $ignoreId = null): array
    {
        $uniqueName = Rule::unique('bank_accounts', 'name')->where('company_id', $companyId);
        if ($ignoreId !== null) {
            $uniqueName->ignore($ignoreId);
        }

        $rules = [
            'name' => ['required', 'string', 'max:120', $uniqueName],
            'type' => ['nullable', 'string', Rule::in(BankAccount::TYPES)],
            'account_number' => ['nullable', 'string', 'max:20'],
            'iban' => ['nullable', 'string', 'max:34'],
            'currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'opening_balance' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        $data = $request->validate($partial
            ? array_map(fn ($rule) => array_diff($rule, ['required']), $rules)
            : $rules);

        if (! $partial) {
            $data += ['type' => 'cash', 'currency_code' => 'TRY', 'opening_balance' => 0, 'is_active' => true];
        }

        return $data;
    }

    protected function present(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'iban' => $account->iban,
            'account_number' => $account->account_number,
            'currency_code' => $account->currency_code,
            'opening_balance' => $account->opening_balance,
            'is_default' => (bool) $account->is_default,
            'is_active' => (bool) $account->is_active,
            'payments_count' => (int) ($account->getAttribute('payments_count') ?? $account->payments()->count()),
            'balance' => $account->balance(),
            'note' => $account->note,
        ];
    }
}
