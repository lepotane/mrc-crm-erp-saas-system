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

    /** Official muhasebe fişi upload screen accepts at most 50 vouchers per file. */
    private const MAX_VOUCHERS_PER_FILE = 50;

    /**
     * Official "Excel Veri Aktarımı" muhasebe fişi template: exactly 11 columns.
     * Delimiter is ';' (Türkçe) on the voucher upload screen, not a comma.
     */
    private const VOUCHER_COLUMNS = [
        'Fiş No', 'Fiş Tarihi', 'Hesap Kodu', 'Evrak No', 'Evrak Tarihi',
        'Açıklama', 'Borç', 'Alacak', 'Miktar', 'Döviz', 'Kur',
    ];

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

    /**
     * Build official "Muhasebe Fişi" (Excel Veri Aktarımı) CSV files.
     *
     * Official format: exactly 11 columns in fixed order, ';' delimiter,
     * gg.AA.yyyy dates and Turkish decimals (thousands '.', decimal ',').
     * A file is closed when either 50 vouchers or 400 detail rows is reached.
     * A voucher is never split across files.
     *
     * @param  array<int, CanonicalVoucher>  $vouchers
     * @return array<int, array{path:string,rows:int,vouchers:int}>
     */
    public function buildVoucherCsv(array $vouchers): array
    {
        $files = [];
        $groups = [];
        $groupVouchers = 0;
        $groupRows = 0;

        $flush = function () use (&$files, &$groups, &$groupVouchers, &$groupRows): void {
            if ($groups === []) {
                return;
            }
            $files[] = $this->writeVoucherFile($groups, $groupRows, $groupVouchers);
            $groups = [];
            $groupVouchers = 0;
            $groupRows = 0;
        };

        foreach ($vouchers as $voucher) {
            if (! $voucher instanceof CanonicalVoucher) {
                continue;
            }

            $lines = array_values($voucher->lines);
            $rowCount = count($lines);

            if ($rowCount > self::MAX_ROWS) {
                throw new \RuntimeException(
                    'Voucher '.$voucher->voucherNumber.' ('.$voucher->externalRef.') has '.$rowCount
                    .' rows; the Luca voucher template allows at most '.self::MAX_ROWS.' rows per file.'
                );
            }

            if ($groupVouchers >= self::MAX_VOUCHERS_PER_FILE
                || $groupRows + $rowCount > self::MAX_ROWS) {
                $flush();
            }

            $groups[] = [$voucher, $lines];
            $groupVouchers++;
            $groupRows += $rowCount;
        }

        $flush();

        return $files;
    }

    /**
     * @param  array<int, array{0:CanonicalVoucher,1:array<int,CanonicalVoucherLine>}>  $groups
     * @return array{path:string,rows:int,vouchers:int}
     */
    private function writeVoucherFile(array $groups, int $rows, int $voucherCount): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create temporary CSV stream for the Luca voucher file.');
        }

        fputcsv($handle, self::VOUCHER_COLUMNS, ';', '"');
        foreach ($groups as [$voucher, $lines]) {
            foreach ($lines as $line) {
                fputcsv($handle, $this->voucherRow($voucher, $line), ';', '"');
            }
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        // Character encoding is NOT documented in the official Luca guide.
        // Keep it tenant-configurable and confirm it with a 1-row test before production use.
        $encoding = (string) config('integrations.channels.luca.csv_encoding', 'Windows-1254');
        if (function_exists('mb_convert_encoding')
            && $encoding !== ''
            && strtoupper($encoding) !== 'UTF-8') {
            $content = mb_convert_encoding($content, $encoding, 'UTF-8');
        }

        $directory = 'integrations/luca/vouchers/'.$this->companyId();
        $path = $directory.'/voucher_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.csv';
        Storage::disk('local')->put($path, $content);

        return ['path' => $path, 'rows' => $rows, 'vouchers' => $voucherCount];
    }

    /** @return array<int, string> exactly 11 official columns */
    private function voucherRow(CanonicalVoucher $voucher, CanonicalVoucherLine $line): array
    {
        $debit = $this->numeric($line->debit);
        $credit = $this->numeric($line->credit);

        // Standard Turkish accounting import practice: leave the zero side blank, never '0'.
        $debitCell = $this->isZero($debit) ? '' : $this->turkishAmount($debit);
        $creditCell = $this->isZero($credit) ? '' : $this->turkishAmount($credit);

        return [
            $voucher->voucherNumber,                 // Fiş No
            $voucher->date->format('d.m.Y'),         // Fiş Tarihi (gg.AA.yyyy)
            $line->accountCode,                      // Hesap Kodu (must exist in Luca's plan)
            (string) ($voucher->externalRef ?? ''),  // Evrak No
            $voucher->date->format('d.m.Y'),         // Evrak Tarihi
            (string) ($line->description ?? ''),     // Açıklama
            $debitCell,                              // Borç
            $creditCell,                             // Alacak
            '',                                      // Miktar (quantity not modelled)
            (string) ($voucher->currency ?? ''),     // Döviz
            '',                                      // Kur (blank for a single-currency import)
        ];
    }

    private function isZero(string $value): bool
    {
        $value = $this->numeric($value);

        return function_exists('bccomp')
            ? bccomp($value, '0', 4) === 0
            : abs((float) $value) < 0.000001;
    }

    /** Turkish decimal formatting without a float round-trip on the stored amount. */
    private function turkishAmount(string $value): string
    {
        $value = $this->numeric($value);

        // bcmath keeps the value as a decimal string; only the final display
        // uses number_format, so cents are not lost to binary float precision.
        if (function_exists('bcadd')) {
            $value = bcadd($value, '0', 2);
        }

        $parts = array_pad(explode('.', $value, 2), 2, '');
        $normalized = $parts[0].'.'.str_pad(substr($parts[1], 0, 2), 2, '0');

        return number_format((float) $normalized, 2, ',', '.');
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
