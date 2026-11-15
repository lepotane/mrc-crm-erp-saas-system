<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * API hata govdesi metinleri .
 *
 * Amac: framework'un ham exception metni yerine kullaniciyi/istemciyi okuyabilecegi,
 * dile gore cevrilmis, TEK sozdizimli {"message": "..."} uretmek. Route-model
 * binding basarisiz oldugunda Laravel "No query results for model
 * [App\Models\Product] 999..." yaziyor ve APP_DEBUG aciksa stack trace de
 * govdeye doluyordu — hem kiraci sirri hem de masaustu istemcinin parse
 * edemeyecegi bir govde.
 */
final class ApiErrorMessages
{
    /**
     * Rota parametresi → kullanıcı diliyle "bulunamadı" mesajı.
     * Anahtarlar routes/api.php içindeki binding adlarıdır.
     */
    private const PARAM_MESSAGES = [
        'bankAccount' => 'Kasa/banka hesabı bulunamadı.',
        'category' => 'Kategori bulunamadı.',
        'document' => 'Belge bulunamadı.',
        'invitation' => 'Davet bulunamadı.',
        'party' => 'Cari kaydı bulunamadı.',
        'payment' => 'Ödeme bulunamadı.',
        'product' => 'Ürün bulunamadı.',
        'unit' => 'Birim bulunamadı.',
        'warehouse' => 'Depo bulunamadı.',
    ];

    /**
     * Kayıt bulunamadığında gösterilecek mesaj. Controller'ın açık mesajı
     * (abort(404, '...')) bootstrap'da önceliklidir; buraya yalnız route-model
     * binding başarısız olduğunda düşülür ve hangi kaynağın arandığı rota
     * parametresinden çözülür. Başka firmanın kaydı için de aynı "bulunamadı"
     * cevabı döner (varlığın kendisi sızdırılmaz).
     */
    public static function notFound(Request $request): string
    {
        $route = $request->route();

        if ($route !== null) {
            foreach ($route->parameters() as $name => $value) {
                if (isset(self::PARAM_MESSAGES[$name])) {
                    return __(self::PARAM_MESSAGES[$name]);
                }
            }
        }

        return __('Kayıt bulunamadı.');
    }

    /** Rota hiç eşleşmediğinde (api fallback). */
    public static function routeNotFound(): string
    {
        return __('Bu uç nokta mevcut değil.');
    }

    /**
     * Laravel'in KENDİ ürettiği 404 metinleri ("The route ... could not be found.",
     * "No query results for model ..."). Bunlar kullanıcıya gösterilemez; hem
     * iç bilgi taşır hem de çevrilmemiştir. Bos mesaj da buraya girer: `abort(404)`
     * (ör. kapali doküman ucu) mesaj üretmez, oysa JSON sozlesmesi bos olmayan,
     * cevrilmis bir mesaj ister (tests/Feature/ApiDocsTest).
     */
    public static function isFrameworkNoise(string $message): bool
    {
        return $message === ''
            || str_starts_with($message, 'No query results for model')
            || str_starts_with($message, 'The route ');
    }
}
