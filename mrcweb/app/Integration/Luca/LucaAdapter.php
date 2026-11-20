<?php

namespace App\Integration\Luca;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\SyncResult;
use App\Integration\Contracts\ChannelAdapterInterface;
use App\Integration\Contracts\VoucherSinkInterface;
use App\Integration\Enums\Channel;
use App\Integration\Support\CredentialVault;
use App\Models\AccountantTransfer;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Luca channel adapter. The concrete driver is tenant configuration
 * (tenant_integrations.driver = file | rest | soap), never a code constant.
 */
final class LucaAdapter implements ChannelAdapterInterface, VoucherSinkInterface
{
    public function __construct(
        private readonly Company|int $company,
        private readonly CredentialVault $vault,
    ) {}

    public function channel(): Channel
    {
        return Channel::Luca;
    }

    public function supports(string $operation): bool
    {
        return $operation === 'push_voucher';
    }

    public function pushVoucher(CanonicalVoucher $voucher): SyncResult
    {
        $driver = $this->driverName();

        $result = match ($driver) {
            'rest' => (new RestDriver($this->company, $this->vault))->pushVoucher($voucher),
            'soap' => (new SoapDriver($this->company, $this->vault))->pushVoucher($voucher),
            default => (new FileDriver($this->company))->pushVoucher($voucher),
        };

        AccountantTransfer::create([
            'company_id' => $this->companyId(),
            'voucher_ids' => [$voucher->externalRef !== '' ? $voucher->externalRef : $voucher->voucherNumber],
            'driver' => $driver,
            'status' => $result->ok ? 'transferred' : 'failed',
            'approved_by' => auth()->id(),
            'transferred_at' => $result->ok && ! $result->skipped ? now() : null,
            'external_ref' => $result->externalRef !== null
                ? Str::limit($result->externalRef, 190, '')
                : null,
            'error' => $result->ok ? null : $result->message,
        ]);

        return $result;
    }

    private function driverName(): string
    {
        $driver = DB::table('tenant_integrations')
            ->where('company_id', $this->companyId())
            ->where('channel', 'luca')
            ->value('driver');

        return in_array($driver, ['file', 'rest', 'soap'], true) ? (string) $driver : 'file';
    }

    private function companyId(): int
    {
        return $this->company instanceof Company ? (int) $this->company->id : (int) $this->company;
    }
}
