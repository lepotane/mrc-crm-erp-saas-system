<?php

namespace App\Integration\Luca;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\Channel;
use App\Integration\Support\CredentialVault;
use App\Models\Company;
use App\Models\GibDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Luca SOAP driver (skeleton).
 *
 * Authentication is IP-whitelist based: there is deliberately NO username and
 * NO password, and no credential is sent in the envelope. The endpoint comes
 * from the tenant configuration key `wsdl_url`.
 *
 * Write safety rules for this channel:
 *  - writes are NEVER retried automatically (a retry can create a second
 *    invoice in Luca; the SOAP API has no idempotency key we control);
 *  - ETTN must be checked before any write - if a GIB document with that ETTN
 *    already exists, the voucher has already been submitted and is skipped.
 */
final class SoapDriver
{
    public function __construct(
        private readonly Company|int $company,
        private readonly CredentialVault $vault,
    ) {}

    public function pushVoucher(CanonicalVoucher $voucher): SyncResult
    {
        $endpoint = $this->setting('wsdl_url');
        if ($endpoint === null) {
            return SyncResult::failure('SOAP endpoint not configured');
        }

        $ettn = trim((string) ($voucher->ettn ?? ''));
        if ($ettn !== '' && $this->ettnExists($ettn)) {
            return SyncResult::skipped('ETTN already submitted; SOAP write skipped.');
        }

        $xml = $this->envelope($voucher, $ettn);

        try {
            // Exactly one write attempt. Never wrap this in a retry loop.
            $response = Http::withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
                ->withBody($xml, 'text/xml; charset=utf-8')
                ->timeout(10)
                ->post($endpoint);
        } catch (\Throwable $e) {
            Log::error('Luca SOAP write failed', [
                'company_id' => $this->companyId(),
                'ettn' => $ettn,
                'error' => $e->getMessage(),
            ]);

            return SyncResult::failure('Luca SOAP write failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return SyncResult::failure('Luca SOAP rejected the voucher (HTTP '.$response->status().').');
        }

        return SyncResult::success($ettn !== '' ? $ettn : $voucher->externalRef, 'Luca SOAP voucher accepted.');
    }

    /** True when a GIB document with the same ETTN already exists for the tenant. */
    public function ettnExists(string $ettn): bool
    {
        if (trim($ettn) === '') {
            return false;
        }

        return GibDocument::where('company_id', $this->companyId())
            ->where('ettN', $ettn)
            ->exists();
    }

    private function envelope(CanonicalVoucher $voucher, string $ettn): string
    {
        $escape = static fn (?string $value): string => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');

        $lines = '';
        foreach ($voucher->lines as $index => $line) {
            $lines .= '      <Product>'
                .'<LineNo>'.($index + 1).'</LineNo>'
                .'<Code>'.$escape($line->accountCode).'</Code>'
                .'<Description>'.$escape($line->description).'</Description>'
                .'</Product>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<SaveInvoice xmlns="urn:luca">'
            .'<ETTN>'.$escape($ettn).'</ETTN>'
            .'<InvoiceNumber>'.$escape($voucher->voucherNumber).'</InvoiceNumber>'
            .'<InvoiceDate>'.$escape($voucher->date->toDateString()).'</InvoiceDate>'
            .'<TaxCode>'.$escape($voucher->taxId).'</TaxCode>'
            .'<CurrencyCode>'.$escape($voucher->currency).'</CurrencyCode>'
            .'<Products>'.$lines.'</Products>'
            .'</SaveInvoice>'
            .'</soap:Body>'
            .'</soap:Envelope>';
    }

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

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function companyId(): int
    {
        return $this->company instanceof Company ? (int) $this->company->id : (int) $this->company;
    }
}
