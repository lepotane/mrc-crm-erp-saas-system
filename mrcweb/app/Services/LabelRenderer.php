<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Rulo etiket bestecisi : tasarımcıdaki serbest alan yerleşimini
 * (% koordinat) TEK PNG'ye basar; blade yalnız resmi tam ölçüyle gömer.
 *
 * Neden GD (blade yerine): dompdf img'de boy+yükseklik birlikte verilince
 * genişliği yok sayar, mutlak konumlandırması sayfalar arası seker; akış
 * yerleşimi ise sürükle-bırak özgürlüğünü öldürür. GD'de koordinat neyse
 * çıktı odur — önizleme ile baskı birebir aynıdır (aynı kod yolu).
 *
 * Ölçüler: S px/mm çözünürlükte; barkod modülleri tam px'e denk getirilir
 * (yarım piksel bulanıklaştırır, tarayıcıyı yorar).
 */
class LabelRenderer
{
    /** px/mm (456×300px @38×25 — keskinlik/bellek dengesi). */
    public const SCALE = 12;

    public static function available(): bool
    {
        return function_exists('imagettftext') && function_exists('imagecreatetruecolor');
    }

    protected static function fontDir(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts');
    }

    /**
     * @param array{0:float,1:float} $dims [en_mm, boy_mm]
     * @param array<int,array{key:string,x:float,y:float,w:float,h:float,font:int,align:string,visible:bool}> $fields
     * @param array{name:string,code:string,price:float,barcode:string,showPrice:bool,lang:string} $data
     */
    public static function compose(array $dims, array $fields, array $data): string
    {
        [$enMm, $boyMm] = $dims;
        $s = self::SCALE;
        $W = (int) round($enMm * $s);
        $H = (int) round($boyMm * $s);

        $img = imagecreatetruecolor($W, $H);
        $beyaz = imagecolorallocate($img, 255, 255, 255);
        $siyah = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $W, $H, $beyaz);

        $tr = ($data['lang'] ?? 'tr') === 'tr';
        $money = static fn ($v): string => $tr
            ? number_format((float) $v, 2, ',', '.')
            : number_format((float) $v, 2, '.', ',');

        foreach ($fields as $f) {
            if (! ($f['visible'] ?? true)) {
                continue;
            }
            $fx = (float) $f['x'] / 100 * $W;
            $fy = (float) $f['y'] / 100 * $H;
            $fw = max(1, (float) $f['w'] / 100 * $W);
            $fh = max(0, (float) ($f['h'] ?? 0) / 100 * $H);
            $pt = max(6, min(16, (int) ($f['font'] ?? 8)));
            $px = $pt * $s / 3.7795;
            $hiza = $f['align'] ?? 'left';

            switch ($f['key']) {
                case 'barcode':
                    self::cizBarkod($img, $data['barcode'], (int) round($fx), (int) round($fy), (int) round($fw), $fh > 0 ? (int) round($fh) : 0);
                    break;
                case 'qr':
                    self::cizQrKod($img, $data['barcode'], (int) round($fx), (int) round($fy), (int) round($fw), $fh > 0 ? (int) round($fh) : (int) round($fw));
                    break;
                case 'name':
                    self::cizYazi($img, (string) $data['name'], $siyah, self::fontDir() . '/DejaVuSans-Bold.ttf',
                        $px, (int) round($fx), (int) round($fy), (int) round($fw), $fh, $hiza,
                        max(1, min(3, (int) ($f['maxLines'] ?? 2))));
                    break;
                case 'code':
                    self::cizYazi($img, (string) $data['code'], $siyah, self::fontDir() . '/DejaVuSansMono.ttf',
                        $px, (int) round($fx), (int) round($fy), (int) round($fw), $fh, $hiza, 1);
                    break;
                case 'price':
                    if (($data['showPrice'] ?? true)) {
                        self::cizYazi($img, $money($data['price']), $siyah, self::fontDir() . '/DejaVuSans-Bold.ttf',
                            $px, (int) round($fx), (int) round($fy), (int) round($fw), $fh, $hiza, 1);
                    }
                    break;
            }
        }

        ob_start();
        imagepng($img);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /**
     * Kelime sarımlı metin (en çok $maxSatir).
     *
     * KUTUYA SIĞMA GARANTİSİ : metin kutuya sığmazsa yazı boyu
     * otomatik küçültülür (en az $minPx); yine sığmazsa taşan atılır.
     * Böylece tasarımda sığan, baskıda da sığar — uzun ürün adı asla
     * "yok olmaz"; en kötü küçük basılır.
     *
     * $px: GD pikseli. Tasarımcıdaki font sayısı 96dpi px kabul edilir
     * (ölçek: px = font × S / 3.7795); böylece sayı tanıdık davranır.
     */
    protected static function cizYazi($img, string $metin, int $renk, string $font, float $px,
        int $x, int $y, int $w, int $h, string $hiza, int $maxSatir): void
    {
        if ($w <= 0 || $px <= 0) {
            return;
        }

        ['lines' => $satirlar, 'px' => $px] = self::sigdir($metin, $font, $px, $w, $maxSatir);

        $olcu = static fn (string $s, float $p): float => (float) ((imagettfbbox($p, 0, $font, $s) ?: [0])[2] ?? 0);
        $yukseklik = $px * 1.25;
        $kutuH = $h > 0 ? $h : count($satirlar) * $yukseklik;
        // Dikey: kutu yüksekliği kadar satır sığar (kutu yoksa hepsi).
        $sigar = $h > 0 ? max(1, (int) floor($kutuH / $yukseklik)) : count($satirlar);
        $satirlar = array_slice($satirlar, 0, $sigar);

        foreach ($satirlar as $i => $satir) {
            $gen = $olcu($satir, $px);
            $sx = $x;
            if ($hiza === 'center') {
                $sx = (int) round($x + ($w - $gen) / 2);
            } elseif ($hiza === 'right') {
                $sx = (int) round($x + $w - $gen);
            }
            $sy = (int) round($y + $px + $i * $yukseklik);
            imagettftext($img, $px, 0, $sx, $sy, $renk, $font, $satir);
        }
    }

    /**
     * Metni kutuya sığdır: sığmazsa yazıyı küçült (en az $minPx).
     *
     * @return array{lines: list<string>, px: float, tam: bool}
     */
    public static function sigdir(string $metin, string $font, float $px, float $w, int $maxSatir, float $minPx = 12.0): array
    {
        $dene = $px;
        while (true) {
            [$satirlar, $tam] = self::sar($metin, $font, $dene, $w, $maxSatir);
            if ($tam || $dene <= $minPx) {
                return ['lines' => $satirlar, 'px' => $dene, 'tam' => $tam];
            }
            $dene = max($minPx, $dene * 0.88);
        }
    }

    /**
     * @return array{list<string>, bool} [satırlar, hepsi sığdı mı]
     */
    protected static function sar(string $metin, string $font, float $px, float $w, int $maxSatir): array
    {
        $olcu = static fn (string $s): float => (float) ((imagettfbbox($px, 0, $font, $s) ?: [0])[2] ?? 0);

        // Kelime kelime sar (tek uzun kelime harf harf bölünür).
        $satirlar = [];
        $aktif = '';
        $kesti = false;
        foreach (preg_split('/\s+/u', $metin) as $kelime) {
            if ($kelime === '') {
                continue;
            }
            $aday = $aktif === '' ? $kelime : $aktif . ' ' . $kelime;
            if ($olcu($aday) <= $w || $aktif === '') {
                if ($olcu($aday) > $w && $aktif === '') {
                    // Sığmayan tek kelime: harf harf böl.
                    $parca = '';
                    foreach (preg_split('//u', $kelime, -1, PREG_SPLIT_NO_EMPTY) as $harf) {
                        if ($olcu($parca . $harf) > $w && $parca !== '') {
                            $satirlar[] = $parca;
                            $parca = $harf;
                        } else {
                            $parca .= $harf;
                        }
                        if (count($satirlar) >= $maxSatir) {
                            $kesti = true;
                            break 2;
                        }
                    }
                    $aktif = $parca;
                } else {
                    $aktif = $aday;
                }
            } else {
                $satirlar[] = $aktif;
                $aktif = $kelime;
                if (count($satirlar) >= $maxSatir) {
                    $aktif = '';
                    $kesti = true;
                    break;
                }
            }
        }
        if ($aktif !== '' && count($satirlar) < $maxSatir) {
            $satirlar[] = $aktif;
            $aktif = '';
        } elseif ($aktif !== '') {
            // Satır hakkı dolu, artan metin var.
            $kesti = true;
        }
        if (count($satirlar) > $maxSatir) {
            $satirlar = array_slice($satirlar, 0, max(1, $maxSatir));
            $kesti = true;
        }

        return [$satirlar, ! $kesti];
    }

    /** Barkod: modüller tam px'e denk gelir (keskin çizgi). */
    protected static function cizBarkod($img, string $kod, int $x, int $y, int $w, int $h): void
    {
        if ($kod === '' || $w <= 0) {
            return;
        }
        $hedefH = $h > 0 ? $h : (int) round($w * 0.28);

        $ham = (new BarcodeGeneratorPNG)->getBarcode($kod, BarcodeGenerator::TYPE_CODE_128, 1, $hedefH);
        $kaynak = @imagecreatefromstring($ham);
        if ($kaynak === false) {
            return;
        }
        $moduller = imagesx($kaynak);
        $wf = max(1, (int) round($w / max(1, $moduller)));
        $net = (new BarcodeGeneratorPNG)->getBarcode($kod, BarcodeGenerator::TYPE_CODE_128, $wf, $hedefH);
        $netImg = @imagecreatefromstring($net);
        if ($netImg === false) {
            imagedestroy($kaynak);

            return;
        }

        imagecopyresampled($img, $netImg, $x, $y, 0, 0, $w, $hedefH, imagesx($netImg), imagesy($netImg));
        imagedestroy($kaynak);
        imagedestroy($netImg);
    }

    /** QR Kod alanı: GD tuvaline karekod çizer. */
    protected static function cizQrKod($img, string $kod, int $x, int $y, int $w, int $h): void
    {
        if ($kod === '' || $w <= 0) {
            return;
        }
        $boyut = max(24, min($w, $h > 0 ? $h : $w));
        $png = ProductBarcode::renderQrPng($kod, $boyut);
        $qrImg = @imagecreatefromstring($png);
        if ($qrImg === false) {
            return;
        }
        imagecopyresampled($img, $qrImg, $x, $y, 0, 0, $boyut, $boyut, imagesx($qrImg), imagesy($qrImg));
        imagedestroy($qrImg);
    }
}
