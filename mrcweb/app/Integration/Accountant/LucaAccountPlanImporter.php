<?php

namespace App\Integration\Accountant;

use SimpleXMLElement;
use Throwable;

/**
 * Luca hesap planı dosyalarını normalize satırlara ayrıştırır.
 *
 * Beklenen kolon sırası: code, name, parent_code, is_active.
 * Türkçe başlıklar (Kod / Hesap Kodu / Hesap Adı / Üst Hesap / Ana Hesap / Aktif) kabul edilir.
 * Resmî hesap planı şablonundaki Vergi No, Vergi Dairesi, T.C. Kimlik No ve Adres
 * kolonları okunur ancak kasıtlı olarak yok sayılır (henüz saklamıyoruz).
 * Hiçbir hesap kodu üretilmez; yalnızca dosyada bulunanlar taşınır.
 */
final class LucaAccountPlanImporter
{
    /**
     * Normalize edilmiş başlık -> alan eşlemesi. Resmî başlıklar yıldızlı
     * gelebilir (Hesap Kodu*); normalize() yıldızı temizler.
     *
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'code' => 'code',
        'kod' => 'code',
        'hesapkodu' => 'code',
        'hesap kodu' => 'code',
        'name' => 'name',
        'ad' => 'name',
        'hesapadi' => 'name',
        'hesap adi' => 'name',
        'parent_code' => 'parent_code',
        'usthesap' => 'parent_code',
        'ust hesap' => 'parent_code',
        'anastesap' => 'parent_code',
        'ana hesap' => 'parent_code',
        'is_active' => 'is_active',
        'aktif' => 'is_active',
        // Resmî şablonda bulunur, şimdilik saklanmaz: kasıtlı olarak yok sayılır.
        'vergi no' => 'ignore',
        'vergi dairesi' => 'ignore',
        't.c. kimlik no' => 'ignore',
        'tc kimlik no' => 'ignore',
        'adres' => 'ignore',
    ];

    /**
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    public function parseCsv(string $content): array
    {
        $rows = [];
        $errors = [];

        $content = $this->normalizeEncoding($content);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return ['rows' => [], 'errors' => ['CSV içeriği boş.']];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headerMap = null;

        foreach ($lines as $index => $line) {
            $cells = str_getcsv($line, $delimiter);
            $cells = array_map(static fn ($cell): string => is_string($cell) ? trim($cell) : '', $cells);

            // Başlık satırı atlanır; başlıklardan alan -> kolon eşlemesi çıkarılır.
            if ($index === 0 && $this->isHeader($cells)) {
                $headerMap = $this->buildHeaderMap($cells);

                continue;
            }

            $cell = function (string $field) use ($cells, $headerMap): ?string {
                if ($headerMap !== null) {
                    // Başlık varsa eşleşmeyen alan yok sayılır; sabit indekse düşülmez.
                    return isset($headerMap[$field]) ? ($cells[$headerMap[$field]] ?? null) : null;
                }

                // Başlık yoksa eski sabit kolon sırası korunur.
                return match ($field) {
                    'code' => $cells[0] ?? null,
                    'name' => $cells[1] ?? null,
                    'parent_code' => $cells[2] ?? null,
                    'is_active' => $cells[3] ?? null,
                    default => null,
                };
            };

            $code = trim((string) $cell('code'));
            $name = trim((string) $cell('name'));
            if ($code === '' && $name === '') {
                continue;
            }
            if ($code === '') {
                $errors[] = 'Satır '.($index + 1).': hesap kodu boş.';

                continue;
            }

            $parentCode = trim((string) $cell('parent_code'));
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'parent_code' => $parentCode !== '' ? $parentCode : null,
                'is_active' => $this->toBool($cell('is_active')),
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'CSV içinde okunabilir hesap satırı bulunamadı.';
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    public function parseXml(string $content): array
    {
        $rows = [];
        $errors = [];
        $content = $this->normalizeEncoding($content);

        if (trim($content) === '') {
            return ['rows' => [], 'errors' => ['XML içeriği boş.']];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content);
            if ($xml === false) {
                $messages = [];
                foreach (libxml_get_errors() as $error) {
                    $messages[] = trim($error->message);
                }
                $errors[] = 'XML ayrıştırılamadı.'
                    .($messages !== [] ? ' ('.implode(' | ', array_slice($messages, 0, 3)).')' : '');
            } else {
                $nodes = [];
                foreach ($xml->xpath('//Hesap') ?: [] as $node) {
                    $nodes[] = $node;
                }
                foreach ($xml->xpath('//Account') ?: [] as $node) {
                    $nodes[] = $node;
                }

                foreach ($nodes as $node) {
                    $code = $this->child($node, ['code', 'Kod', 'kod', 'CODE']);
                    if ($code === '') {
                        $errors[] = 'XML kaydında hesap kodu boş.';

                        continue;
                    }

                    $parentCode = $this->child($node, [
                        'parent_code', 'ParentCode', 'ust_code', 'ÜstHesap', 'UstHesap',
                    ]);
                    $active = $this->child($node, ['is_active', 'IsActive', 'Aktif', 'aktif']);

                    $rows[] = [
                        'code' => $code,
                        'name' => $this->child($node, ['name', 'Ad', 'ad', 'Name']),
                        'parent_code' => $parentCode !== '' ? $parentCode : null,
                        'is_active' => $active === '' ? true : $this->toBool($active),
                    ];
                }

                if ($nodes === [] && $errors === []) {
                    $errors[] = 'XML içinde Hesap veya Account kaydı bulunamadı.';
                }
            }
        } catch (Throwable $e) {
            // Bozuk girdi istisna değil, hata listesi döndürür.
            $errors[] = 'XML ayrıştırma hatası: '.get_class($e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    public function parse(string $content, string $extension): array
    {
        $extension = strtolower(ltrim(trim($extension), '.'));

        if ($extension === 'xml') {
            return $this->parseXml($content);
        }
        if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            return $this->parseCsv($content);
        }

        // Bilinmeyen uzantı: içeriğe göre güvenli geri dönüş.
        if (str_contains($content, '<')) {
            return $this->parseXml($content);
        }

        return $this->parseCsv($content);
    }

    private function detectDelimiter(string $line): string
    {
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /** @param array<int,string> $cells */
    private function isHeader(array $cells): bool
    {
        $first = $this->headerField($cells[0] ?? '');
        $second = $this->headerField($cells[1] ?? '');

        return $first === 'code' || $second === 'code' || ($first === 'name' && $second === 'name');
    }

    /**
     * @param  array<int,string>  $cells
     * @return array<string, int> field => column index
     */
    private function buildHeaderMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $index => $cell) {
            $field = $this->headerField($cell);
            if ($field !== null && $field !== 'ignore' && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    private function headerField(string $header): ?string
    {
        return self::HEADER_ALIASES[$this->normalize($header)] ?? null;
    }

    private function child(SimpleXMLElement $node, array $names): string
    {
        foreach ($names as $name) {
            if (isset($node->{$name})) {
                return trim((string) $node->{$name});
            }
        }

        return '';
    }

    private function normalizeEncoding(string $content): string
    {
        $encoding = (string) config('integrations.channels.luca.csv_encoding', 'Windows-1254');
        if ($encoding === '') {
            $encoding = 'Windows-1254';
        }
        if ($content === '' || strtoupper($encoding) === 'UTF-8' || mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Karakter kodlaması resmî belgede belirtilmez; kiracı ayarıdır ve
        // üretime almadan önce 1 satırlık test ile doğrulanmalıdır.
        $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);

        return is_string($converted) ? $converted : $content;
    }

    private function normalize(string $value): string
    {
        // Resmî şablon başlıkları yıldızlı gelebilir: "Hesap Kodu*".
        $value = trim($value, " \t\n\r\0\x0B*\"'");

        return strtr(mb_strtolower($value, 'UTF-8'), [
            'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'ğ' => 'g',
            'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        return in_array($this->normalize(trim((string) $value)), ['1', 'true', 'evet', 'yes', 'y', 'aktif'], true);
    }
}
