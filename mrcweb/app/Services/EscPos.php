<?php

namespace App\Services;

use App\Models\Document;

/**
 * Minimal ESC/POS fiş üretici .
 *
 * Termal yazıcılar Türkçe glif taşımaz: metin ASCII'ye indirgenir
 * (ç→c, ğ→g, ı→i, ö→o, ş→s, ü→u; ₺→TL). Logo/QR yok — metin fiş.
 * Ham komutlar base64'lenir (`print_jobs.payload`).
 */
class EscPos
{
    public const COLS = 32;

    public static function buildReceipt(Document $document, array $opts = []): string
    {
        $document->loadMissing(['items.product', 'company', 'party']);
        $lang = isset($opts['lang']) && $opts['lang'] === 'en' ? 'en' : 'tr';

        $out = self::init();
        $out .= self::align('center');
        $out .= self::boldOn();
        $out .= self::line(self::ascii((string) ($document->company?->name ?? '')));
        $out .= self::boldOff();
        $out .= self::line(self::ascii('Satis fisi'));
        $out .= self::line(str_repeat('-', self::COLS));
        $out .= self::align('left');
        $out .= self::line(self::ascii('Fis no: '.$document->serial));
        $out .= self::line(self::ascii('Tarih: '.substr((string) $document->issue_date, 0, 10)));
        $out .= self::line(self::ascii('Musteri: '.($document->party?->name ?? '-')));
        $out .= self::line(str_repeat('-', self::COLS));

        foreach ($document->items as $item) {
            $ad = $item->product?->localizedName($lang) ?? (string) $item->name;
            $out .= self::line(self::ascii($ad));
            $qty = rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.');
            $sol = $qty.' x '.number_format((float) $item->unit_price, 2, '.', '');
            $sag = number_format((float) $item->line_total, 2, '.', '');
            $out .= self::twoCol($sol, $sag);
        }

        $out .= self::line(str_repeat('-', self::COLS));
        $out .= self::twoCol('Ara toplam', number_format((float) $document->subtotal, 2, '.', ''));
        if ((float) $document->discount_total > 0) {
            $out .= self::twoCol('Iskonto', '-'.number_format((float) $document->discount_total, 2, '.', ''));
        }
        $out .= self::twoCol('KDV', number_format((float) $document->tax_total, 2, '.', ''));
        $out .= self::boldOn();
        $out .= self::twoCol('TOPLAM ('.$document->currency_code.')', number_format((float) $document->grand_total, 2, '.', ''));
        $out .= self::boldOff();

        if (! empty($opts['tendered'])) {
            $out .= self::twoCol('Alinan', number_format((float) $opts['tendered'], 2, '.', ''));
            $out .= self::twoCol('Para ustu', number_format(max(0, (float) $opts['tendered'] - (float) $document->grand_total), 2, '.', ''));
        }

        $out .= self::feed(2);
        $out .= self::align('center');
        $out .= self::line(self::ascii('Tesekkurler'));
        $out .= self::feed(3);
        $out .= self::cut();

        return $out;
    }

    public static function ascii(string $text): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'I' => 'I',
            'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U', '₺' => 'TL', '€' => 'EUR', '’' => "'",
            '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-',
        ];

        return strtr($text, $map);
    }

    protected static function twoCol(string $left, string $right): string
    {
        $left = self::ascii($left);
        $right = self::ascii($right);
        $bos = max(1, self::COLS - mb_strlen($left) - mb_strlen($right));

        return $left.str_repeat(' ', $bos).$right."\n";
    }

    protected static function line(string $text): string
    {
        $chunks = mb_str_split(self::ascii($text), self::COLS) ?: [''];

        return implode("\n", $chunks)."\n";
    }

    protected static function init(): string
    {
        return "\x1B\x40";
    }

    protected static function align(string $where): string
    {
        return "\x1B\x61".($where === 'center' ? "\x01" : ($where === 'right' ? "\x02" : "\x00"));
    }

    protected static function boldOn(): string
    {
        return "\x1B\x45\x01";
    }

    protected static function boldOff(): string
    {
        return "\x1B\x45\x00";
    }

    protected static function feed(int $n): string
    {
        return "\x1Bd".chr(max(0, min(255, $n)));
    }

    protected static function cut(): string
    {
        return "\x1D\x56\x00";
    }
}
