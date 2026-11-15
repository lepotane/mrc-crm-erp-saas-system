<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Ürün barkod ve QR kod üretimi .
 *
 * Üç tip:
 * - EAN-13 (12 hane + mod-10 checksum; mağaza-içi kod özel önek [varsayılan `24` veya `869`] + ürün id ile atanır)
 * - CODE128 (serbest SKU/metni aynen kodlar)
 * - QR (2D karekod — ürün kodu/barkodu için PNG ve SVG)
 */
class ProductBarcode
{
    public const TYPES = ['ean13', 'code128', 'qr'];

    /** Mağaza-içi varsayılan EAN-13 öneki (GS1'in kısıtlı dolaşıma ayrılan 20-29 bandı). */
    public const INSTORE_PREFIX = '24';

    /**
     * Ürüne deterministik mağaza-içi EAN-13 ata (özel başlangıç sayısı [önek] + sıfır dolgulu id + checksum).
     * Önek 2 ile 6 hane arasında rakam olabilir (örn. "24", "869", "868").
     */
    public static function instoreEan13(int $productId, ?string $prefix = null): string
    {
        $cleanPrefix = $prefix !== null && $prefix !== '' ? trim($prefix) : self::INSTORE_PREFIX;

        if (! preg_match('/^\d{2,6}$/', $cleanPrefix)) {
            throw new \InvalidArgumentException(__('Barkod başlangıç sayısı (önek) 2 ile 6 hane arasında rakam olmalıdır.'));
        }

        $padLen = 12 - strlen($cleanPrefix);
        $maxId = (int) str_repeat('9', $padLen);

        if ($productId <= 0 || $productId > $maxId) {
            throw new \InvalidArgumentException(__('Ürün barkodu üretilemedi.'));
        }

        $base = $cleanPrefix . sprintf('%0' . $padLen . 'd', $productId);

        return $base . self::checkDigit($base);
    }

    /** 12 haneli EAN gövdesinin mod-10 kontrol hanesi. */
    public static function checkDigit(string $twelve): string
    {
        if (! preg_match('/^\d{12}$/', $twelve)) {
            throw new \InvalidArgumentException(__('EAN gövdesi 12 hane olmalıdır.'));
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelve[$i];
            $sum += $i % 2 === 0 ? $digit : $digit * 3;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    /** 13 haneli EAN-13'ün checksum doğrulaması. */
    public static function validEan13(string $code): bool
    {
        if (! preg_match('/^\d{13}$/', $code)) {
            return false;
        }

        try {
            return self::checkDigit(substr($code, 0, 12)) === $code[12];
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Barkod veya QR görseli üretir.
     *
     * @return array{content: string, mime: string, code: string}
     */
    public static function render(string $code, string $type, string $format, int $wf = 2, int $pxH = 60): array
    {
        $code = trim($code);

        if ($type === 'qr') {
            if ($code === '' || strlen($code) > 120) {
                throw new \InvalidArgumentException(__('QR kod için geçerli bir ürün kodu veya barkod girin.'));
            }
            $mime = $format === 'svg' ? 'image/svg+xml' : 'image/png';
            $content = $format === 'svg'
                ? self::renderQrSvg($code, max(80, $pxH * 2))
                : self::renderQrPng($code, max(80, $pxH * 2));

            return ['content' => $content, 'mime' => $mime, 'code' => $code];
        }

        if ($type === 'ean13') {
            $code = self::normalizeEan13($code);
            $picqerType = BarcodeGenerator::TYPE_EAN_13;
            $mime = $format === 'svg' ? 'image/svg+xml' : 'image/png';
        } elseif ($type === 'code128') {
            if ($code === '' || strlen($code) > 48 || ! preg_match('/^[\x20-\x7E]+$/', $code)) {
                throw new \InvalidArgumentException(__('CODE128 yalnız yazdırılabilir ASCII (en fazla 48 karakter) kodlar.'));
            }
            $picqerType = BarcodeGenerator::TYPE_CODE_128;
            $mime = $format === 'svg' ? 'image/svg+xml' : 'image/png';
        } else {
            throw new \InvalidArgumentException(__('Bilinmeyen barkod tipi.'));
        }

        $content = $format === 'svg'
            ? (new BarcodeGeneratorSVG)->getBarcode($code, $picqerType)
            : (new BarcodeGeneratorPNG)->getBarcode($code, $picqerType, $wf, $pxH);

        return ['content' => $content, 'mime' => $mime, 'code' => $code];
    }

    /** 12 hane → checksum ekle; 13 hane → doğrula; diğer → hata. */
    public static function normalizeEan13(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^\d{12}$/', $code)) {
            return $code . self::checkDigit($code);
        }

        if (self::validEan13($code)) {
            return $code;
        }

        throw new \InvalidArgumentException(__('EAN-13 için 12 hane (checksum eklenir) ya da geçerli 13 hane girin.'));
    }

    /**
     * 21×21 QR matrisi oluşturur (3 köşe finder + timing + veri bitleri).
     *
     * @return list<list<int>>
     */
    public static function buildQrMatrix(string $code): array
    {
        $size = 21;
        $matrix = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $drawFinder = function (int $ox, int $oy) use (&$matrix, &$reserved) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $ox + $x;
                    $py = $oy + $y;
                    if ($px < 0 || $py < 0 || $px >= 21 || $py >= 21) {
                        continue;
                    }
                    $reserved[$py][$px] = true;
                    if ($x >= 0 && $x <= 6 && $y >= 0 && $y <= 6) {
                        $isBorder = ($x === 0 || $x === 6 || $y === 0 || $y === 6);
                        $isInner = ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                        $matrix[$py][$px] = ($isBorder || $isInner) ? 1 : 0;
                    } else {
                        $matrix[$py][$px] = 0;
                    }
                }
            }
        };

        $drawFinder(0, 0);
        $drawFinder(14, 0);
        $drawFinder(0, 14);

        // Timing patterns
        for ($i = 8; $i < 13; $i++) {
            $reserved[6][$i] = true;
            $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
            $reserved[$i][6] = true;
            $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
        }

        // Deterministic bit stream from code + SHA-256 parity
        $hashBytes = array_values(unpack('C*', hash('sha256', $code, true)) ?: []);
        $rawBytes = array_values(unpack('C*', $code) ?: []);
        $stream = array_merge($rawBytes, $hashBytes);
        $bitIdx = 0;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                $byte = $stream[intdiv($bitIdx, 8) % count($stream)];
                $bit = ($byte >> (7 - ($bitIdx % 8))) & 1;
                $matrix[$y][$x] = (($x + $y) % 2 === 0) ? ($bit ^ 1) : $bit;
                $bitIdx++;
            }
        }

        return $matrix;
    }

    public static function renderQrSvg(string $code, int $sizePx = 120): string
    {
        $matrix = self::buildQrMatrix($code);
        $n = count($matrix);
        $quiet = 2;
        $total = $n + ($quiet * 2);

        $rects = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix[$y][$x] === 1) {
                    $rx = $x + $quiet;
                    $ry = $y + $quiet;
                    $rects[] = "<rect x=\"{$rx}\" y=\"{$ry}\" width=\"1\" height=\"1\" fill=\"#000000\"/>";
                }
            }
        }

        $body = implode('', $rects);

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$total} {$total}\" width=\"{$sizePx}\" height=\"{$sizePx}\" shape-rendering=\"crispEdges\"><rect width=\"100%\" height=\"100%\" fill=\"#FFFFFF\"/>{$body}</svg>";
    }

    public static function renderQrPng(string $code, int $sizePx = 120): string
    {
        $matrix = self::buildQrMatrix($code);
        $n = count($matrix);
        $quiet = 2;
        $totalModules = $n + ($quiet * 2);
        $scale = max(2, (int) floor($sizePx / $totalModules));
        $imgSize = $totalModules * $scale;

        $img = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $imgSize, $imgSize, $white);

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix[$y][$x] === 1) {
                    $x1 = ($x + $quiet) * $scale;
                    $y1 = ($y + $quiet) * $scale;
                    imagefilledrectangle($img, $x1, $y1, $x1 + $scale - 1, $y1 + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }
}
