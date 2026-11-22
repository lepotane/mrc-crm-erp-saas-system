<?php

namespace Tests\Unit\Integration;

use App\Integration\Accountant\LucaAccountPlanImporter;
use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;
use App\Integration\Luca\FileDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class LucaOfficialFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_writes_official_11_column_semicolon_voucher_csv(): void
    {
        $files = (new FileDriver(7))->buildVoucherCsv([
            $this->voucher('FIS-2026-0001'),
            $this->voucher('FIS-2026-0002'),
        ]);

        $this->assertCount(1, $files);
        $this->assertSame(6, $files[0]['rows']);
        $this->assertSame(2, $files[0]['vouchers']);
        $this->assertTrue(Storage::disk('local')->exists($files[0]['path']));

        $utf8 = $this->decode((string) Storage::disk('local')->get($files[0]['path']));
        $lines = array_values(array_filter(explode("\n", trim($utf8)), static fn (string $line): bool => trim($line) !== ''));
        $this->assertCount(7, $lines, 'One header plus six detail rows.');

        $header = str_getcsv($lines[0], ';', '"');
        $this->assertCount(11, $header);
        $this->assertSame('Fiş No', $header[0]);
        $this->assertSame('Hesap Kodu', $header[2]);
        $this->assertSame('Borç', $header[6]);
        $this->assertSame('Kur', $header[10]);
        // A comma-delimited parse must NOT split the row into 11 columns.
        $this->assertCount(1, str_getcsv($lines[0], ',', '"'));

        $row = str_getcsv($lines[1], ';', '"');
        $this->assertCount(11, $row);
        $this->assertSame('FIS-2026-0001', $row[0]);
        $this->assertSame('20.11.2026', $row[1]);
        $this->assertSame('100', $row[2]);
        $this->assertSame('Satır 0', $row[5]);
        $this->assertSame('1.234,56', $row[6]);
        $this->assertSame('', $row[7], 'A zero credit cell is blank, not 0.');
        $this->assertSame('', $row[10], 'Kur is blank.');
    }

    public function test_it_splits_51_vouchers_into_50_plus_1(): void
    {
        $vouchers = [];
        for ($i = 1; $i <= 51; $i++) {
            $vouchers[] = $this->voucher('FIS-2026-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $files = (new FileDriver(7))->buildVoucherCsv($vouchers);

        $this->assertCount(2, $files);
        $this->assertSame(50, $files[0]['vouchers']);
        $this->assertSame(150, $files[0]['rows']);
        $this->assertSame(1, $files[1]['vouchers']);
        $this->assertSame(3, $files[1]['rows']);
        $this->assertSame(
            [50, 1],
            array_map(static fn (array $file): int => $file['vouchers'], $files)
        );
    }

    public function test_it_rejects_a_voucher_with_401_lines(): void
    {
        $voucher = $this->voucher('FIS-2026-9999', 401);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FIS-2026-9999');

        (new FileDriver(7))->buildVoucherCsv([$voucher]);
    }

    public function test_importer_accepts_official_header_and_ignores_extra_columns(): void
    {
        $csv = "Hesap Kodu*;Hesap Adı*;Vergi No;Vergi Dairesi;T.C. Kimlik No;Adres\n"
            ."100;Kasa;1234567890;Merkez;12345678901;Ataturk Cad. No: 1\n";

        $parsed = (new LucaAccountPlanImporter)->parseCsv($csv);

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('100', $parsed['rows'][0]['code']);
        $this->assertSame('Kasa', $parsed['rows'][0]['name']);
        $this->assertNull($parsed['rows'][0]['parent_code']);
        $this->assertTrue($parsed['rows'][0]['is_active']);
    }

    /** @return array<int, CanonicalVoucherLine> */
    private function lines(int $count): array
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = new CanonicalVoucherLine('100', '1234.56', '0.00', 'Satır '.$i);
        }

        return $lines;
    }

    private function voucher(string $number, int $lineCount = 3): CanonicalVoucher
    {
        return new CanonicalVoucher(
            'ext-'.$number,
            $number,
            Carbon::parse('2026-11-20'),
            'TRY',
            $this->lines($lineCount),
        );
    }

    private function decode(string $raw): string
    {
        if (! function_exists('mb_convert_encoding')) {
            return $raw;
        }

        $encoding = (string) config('integrations.channels.luca.csv_encoding', 'Windows-1254');

        return strtoupper($encoding) === 'UTF-8'
            ? $raw
            : mb_convert_encoding($raw, 'UTF-8', $encoding);
    }
}
