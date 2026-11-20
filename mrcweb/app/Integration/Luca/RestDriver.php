<?php

namespace App\Integration\Luca;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\Channel;
use App\Integration\Support\CredentialVault;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Luca public e-document REST driver.
 *
 * Login (IdentificationNumber + Password) -> Token; SaveInvoice creates a
 * DRAFT in Luca. SendStagingInvoice is NEVER called: MRC must not submit a
 * draft invoice to GIB. Writes are never retried to avoid duplicate invoices.
 * Credentials are read from the tenant vault, never stored by this class.
 */
final class RestDriver
{
    private const BASE_URL = 'https://einvoiceapiturmob.luca.com.tr';

    public function __construct(
        private readonly Company|int $company,
        private readonly CredentialVault $vault,
    ) {}

    public function pushVoucher(CanonicalVoucher $voucher): SyncResult
    {
        $token = $this->token();
        if ($token === null) {
            return SyncResult::failure('Luca credentials not configured');
        }

        $companyRef = $this->credential('company_id') ?? $this->setting('company_id');
        if ($companyRef === null || $companyRef === '') {
            return SyncResult::failure('Luca company id not configured');
        }

        $products = [];
        foreach ($voucher->lines as $line) {
            $amount = $this->amount($line->debit, $line->credit);
            $products[] = [
                'Name' => (string) ($line->description ?? $line->accountCode),
                'Quantity' => 1,
                'UnitPrice' => $amount,
                'TaxRate' => (float) str_replace('%', '', (string) ($line->taxRate ?? '0')),
                'Amount' => $amount,
            ];
        }

        $body = [
            'CompanyId' => is_numeric($companyRef) ? (int) $companyRef : $companyRef,
            'InvoiceDate' => $voucher->date->toDateString(),
            'InvoiceNumber' => $voucher->voucherNumber,
            'ReceiverTaxCode' => (string) ($voucher->taxId ?? ''),
            'CurrencyCode' => $voucher->currency !== '' ? $voucher->currency : 'TRY',
            'Products' => $products,
        ];

        $crossRate = $this->setting('cross_rate');
        if ($crossRate !== null && $crossRate !== '') {
            $body['CrossRate'] = is_numeric($crossRate) ? (float) $crossRate : $crossRate;
        }

        try {
            // Single write attempt. No retry, no queue replay for SaveInvoice.
            $response = Http::timeout(10)
                ->withToken($token)
                ->asJson()
                ->post(self::BASE_URL.'/api/Invoice/SaveInvoice', $body);
        } catch (\Throwable $e) {
            Log::error('Luca SaveInvoice request failed', [
                'company_id' => $this->companyId(),
                'error' => $e->getMessage(),
            ]);

            return SyncResult::failure('Luca SaveInvoice failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return SyncResult::failure('Luca SaveInvoice rejected the voucher (HTTP '.$response->status().').');
        }

        $json = $response->json();
        $externalRef = null;
        if (is_array($json)) {
            $externalRef = $json['ETTN']
                ?? $json['ettn']
                ?? $json['InvoiceNumber']
                ?? (is_array($json['Data'] ?? null) ? ($json['Data']['ETTN'] ?? $json['Data']['InvoiceNumber'] ?? null) : null);
        }

        return SyncResult::success(
            is_scalar($externalRef) ? (string) $externalRef : $voucher->ettn,
            'Luca draft invoice saved.',
        );
    }

    private function token(): ?string
    {
        $cacheKey = 'luca.token.'.$this->companyId();
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $identification = $this->credential('identification_number');
        $password = $this->credential('password');
        if ($identification === null || $password === null) {
            return null;
        }

        try {
            $response = Http::timeout(10)->asJson()->post(self::BASE_URL.'/api/Account/Login', [
                'IdentificationNumber' => $identification,
                'Password' => $password,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Luca login request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Luca login rejected', ['status' => $response->status()]);

            return null;
        }

        $json = $response->json();
        $data = is_array($json['Data'] ?? null) ? $json['Data'] : [];
        $token = $json['Token'] ?? $json['token'] ?? $data['Token'] ?? $data['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresOn = $json['ExpiresOn'] ?? $json['expiresOn'] ?? $data['ExpiresOn'] ?? null;
        $expiresAt = $this->expiryTimestamp($expiresOn) ?? (now()->timestamp + 3600);
        $ttl = max(60, $expiresAt - now()->timestamp - 300);
        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    private function expiryTimestamp(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value > 1_000_000_000 ? (int) $value : now()->timestamp + (int) $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/\/Date\((\d+)\)/', $value, $match) === 1) {
            return (int) ((int) $match[1] / 1000);
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function amount(string $debit, string $credit): string
    {
        $debit = $this->numeric($debit);
        $credit = $this->numeric($credit);
        $useDebit = function_exists('bccomp')
            ? bccomp($debit, $credit, 4) >= 0
            : (float) $debit >= (float) $credit;

        return number_format((float) ($useDebit ? $debit : $credit), 2, '.', '');
    }

    private function numeric(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : '0';
    }

    /**
     * Credentials are held by the tenant vault. The reflection-based lookup
     * keeps this driver compatible with either a Channel-based or a plain
     * key-based vault accessor; no credential value is ever persisted here.
     */
    private function credential(string $key): ?string
    {
        foreach (['get', 'credential', 'getCredential', 'secret', 'value'] as $method) {
            if (! method_exists($this->vault, $method)) {
                continue;
            }
            foreach ([[Channel::Luca, $key], ['luca', $key], [$key]] as $arguments) {
                try {
                    $value = $this->vault->{$method}(...$arguments);
                    if (is_scalar($value) && (string) $value !== '') {
                        return (string) $value;
                    }
                } catch (\Throwable) {
                    // Try the next supported signature.
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        foreach (['settings', 'getSettings', 'all'] as $method) {
            if (! method_exists($this->vault, $method)) {
                continue;
            }
            foreach ([[Channel::Luca], ['luca'], []] as $arguments) {
                try {
                    $value = $this->vault->{$method}(...$arguments);
                    if (is_array($value)) {
                        return $value;
                    }
                } catch (\Throwable) {
                    // Try the next supported signature.
                }
            }
        }

        $raw = DB::table('tenant_integrations')
            ->where('company_id', $this->companyId())
            ->where('channel', 'luca')
            ->value('settings');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function setting(string $key): ?string
    {
        $settings = $this->settings();
        $value = $settings[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function companyId(): int
    {
        return $this->company instanceof Company ? (int) $this->company->id : (int) $this->company;
    }
}
