<?php

namespace App\Integration\Luca;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;
use App\Integration\Canonical\SyncResult;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Luca file driver: 15-column CSV, comma separated, Windows-1254 encoded,
 * at most 400 detail rows per file, separated by document type.
 *
 * The accountant can use this driver with no credentials at all.
 */
final class FileDriver
{
    private const MAX_ROWS = 400;

    private const COLUMNS = [
        'Evrak Tarihi', 'Evrak No', 'VKN/TCKN', 'Unvan', 'Aciklama',
        'Matrah %0', 'Matrah %1', 'Matrah %10', 'Matrah %20',
        'KDV %1', 'KDV %10', 'KDV %20', 'Toplam', 'Tevkifat KDV', 'Karsi Hesap',
    ];

    public function __construct(private readonly Company|int $company) {}

    /** @return array<int, string> Storage-relative paths of the generated files */
    public function pushVoucher(CanonicalVoucher $voucher): SyncResult
    {
        $groups = [];
        foreach ($voucher->lines as $line) {
            $type = trim((string) ($line->documentType ?? ''));
            $key = $type !== '' ? $this->slug($type) : 'invoice';
            $groups[$key][] = $line;
        }
        if ($groups === []) {
            $groups['invoice'] = [];
        }

        $paths = [];
        $directory = 'integrations/luca/out/'.$this->companyId();

        foreach ($groups as $type => $lines) {
            foreach (array_chunk($lines, self::MAX_ROWS) as $index => $chunk) {
                $handle = fopen('php://temp', 'r+');
                if ($handle === false) {
                    return SyncResult::failure('Unable to create temporary CSV stream.');
                }

                fputcsv($handle, self::COLUMNS, ',', '"');
                foreach ($chunk as $line) {
                    fputcsv($handle, $this->row($voucher, $line), ',', '"');
                }

                rewind($handle);
                $csv = (string) stream_get_contents($handle);
                fclose($handle);

                // mbstring is optional: without it the file stays UTF-8.
                if (function_exists('mb_convert_encoding')) {
                    $csv = mb_convert_encoding($csv, 'Windows-1254', 'UTF-8');
                }

                $reference = $this->slug($voucher->externalRef !== '' ? $voucher->externalRef : $voucher->voucherNumber);
                $path = $directory.'/luca_'.$type.'_'.($index + 1).'_'.$reference.'.csv';
                Storage::disk('local')->put($path, $csv);
                $paths[] = $path;
            }
        }

        return SyncResult::success($paths[0] ?? null, count($paths).' Luca CSV file(s) generated.');
    }

    /** @return array<int, string> exactly 15 columns */
    private function row(CanonicalVoucher $voucher, CanonicalVoucherLine $line): array
    {
        $base = $this->amount($line);
        $rate = $this->rateKey($line->taxRate);
        $vat = $rate === null ? '0.00' : $this->multiply($base, $this->rateValue($line->taxRate) / 100);
        $total = $this->add($base, $vat);

        return [
            $voucher->date->format('d.m.Y'),                 // Evrak Tarihi
            $voucher->voucherNumber,                         // Evrak No
            (string) ($voucher->taxId ?? ''),                // VKN/TCKN (no other personal data)
            (string) ($voucher->description ?? ''),          // Unvan
            (string) ($line->description ?? ''),             // Aciklama
            $rate === '0' ? $base : '',                      // Matrah %0
            $rate === '1' ? $base : '',                      // Matrah %1
            $rate === '10' ? $base : '',                     // Matrah %10
            $rate === '20' ? $base : '',                     // Matrah %20
            $rate === '1' ? $vat : '',                       // KDV %1
            $rate === '10' ? $vat : '',                      // KDV %10
            $rate === '20' ? $vat : '',                      // KDV %20
            $total,                                          // Toplam
            '',                                              // Tevkifat KDV (not modelled)
            $line->accountCode,                              // Karsi Hesap (tenant configuration)
        ];
    }

    private function amount(CanonicalVoucherLine $line): string
    {
        $debit = $this->numeric($line->debit);
        $credit = $this->numeric($line->credit);
        $useDebit = function_exists('bccomp')
            ? bccomp($debit, $credit, 4) >= 0
            : (float) $debit >= (float) $credit;

        return $this->money($useDebit ? $debit : $credit);
    }

    private function rateKey(?string $taxRate): ?string
    {
        $rate = $this->rateValue($taxRate);
        if (abs($rate) < 0.0001) {
            return '0';
        }

        return in_array((int) $rate, [1, 10, 20], true) ? (string) (int) $rate : null;
    }

    private function rateValue(?string $taxRate): float
    {
        $value = (float) str_replace(['%', ','], ['', '.'], trim((string) ($taxRate ?? '')));
        // Some sources store 0.20 instead of 20.
        if ($value > 0 && $value <= 1) {
            $value *= 100;
        }

        return $value;
    }

    private function numeric(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : '0';
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function multiply(string $base, float $rate): string
    {
        if (function_exists('bcmul')) {
            return $this->money(bcmul($this->numeric($base), (string) $rate, 4));
        }

        return $this->money((string) ((float) $base * $rate));
    }

    private function add(string $base, string $vat): string
    {
        if (function_exists('bcadd')) {
            return $this->money(bcadd($this->numeric($base), $this->numeric($vat), 4));
        }

        return $this->money((string) ((float) $base + (float) $vat));
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value));

        return $slug === '' || $slug === null ? 'doc' : $slug;
    }

    private function companyId(): int
    {
        return $this->company instanceof Company ? (int) $this->company->id : (int) $this->company;
    }
}
