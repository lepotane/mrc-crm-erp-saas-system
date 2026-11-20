<?php

namespace Tests\Unit\Integration;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;
use App\Integration\Luca\FileDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LucaFileDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_writes_15_columns_in_windows_1254(): void
    {
        $result = (new FileDriver(7))->pushVoucher($this->voucher(1, 'Işık A.Ş.'));

        $this->assertTrue($result->ok);
        $path = $result->externalRef;
        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk('local')->exists($path));

        $raw = (string) Storage::disk('local')->get($path);
        $utf8 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($raw, 'UTF-8', 'Windows-1254')
            : $raw;

        $header = str_getcsv(strtok($utf8, "\n") ?: '', ',', '"');
        $this->assertCount(15, $header);
        $this->assertSame('Evrak Tarihi', $header[0]);
        $this->assertSame('Karsi Hesap', $header[14]);

        if (function_exists('mb_convert_encoding')) {
            $this->assertStringContainsString('Işık A.Ş.', $utf8);
            $this->assertNotSame($raw, $utf8);
        }
    }

    public function test_it_chunks_405_lines_into_two_files(): void
    {
        $result = (new FileDriver(7))->pushVoucher($this->voucher(405));

        $this->assertTrue($result->ok);
        $files = Storage::disk('local')->files('integrations/luca/out/7');
        $this->assertCount(2, $files);

        $first = (string) Storage::disk('local')->get($files[0]);
        $rowCount = count(array_filter(explode("\n", trim($first))));
        $this->assertSame(401, $rowCount, 'Each file has one header plus 400 rows.');
    }

    /** @return array<int, CanonicalVoucherLine> */
    private function lines(int $count): array
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = new CanonicalVoucherLine('100', '10.00', '0.00', 'Satır '.$i, '20', 'satis');
        }

        return $lines;
    }

    private function voucher(int $lineCount, string $description = 'Test'): CanonicalVoucher
    {
        return new CanonicalVoucher(
            'ext-1',
            'FTR-2026-0001',
            Carbon::parse('2026-11-20'),
            'TRY',
            $this->lines($lineCount),
            taxId: '1234567890',
            description: $description,
        );
    }
}
