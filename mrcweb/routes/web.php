<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\PaymentLinkPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API dokümanı  — okunabilir sayfa + OpenAPI + Postman koleksiyonu
|--------------------------------------------------------------------------
| Erisim kapisi config('api.docs') + ApiDocsAccess ara yazilimindadir. Bu
| uclar SPA karsilayici rotasindan ONCE tanimlanir; aksi hâlde '{any}' onlari
| yutardi. (Not: path 'api' ile baslamaz, boylece api kisitiyla celismez.)
*/

Route::middleware('docs.access')->group(function () {
    Route::get('api-docs', [ApiDocsController::class, 'index'])->name('api-docs');
    Route::get('api-docs.json', [ApiDocsController::class, 'spec'])->name('api-docs.spec');
    Route::get('api-docs/postman', [ApiDocsController::class, 'postman'])->name('api-docs.postman');
});

/*
|--------------------------------------------------------------------------
| SPA Giriş Noktası
|--------------------------------------------------------------------------
| Vue 3 SPA (history mode): tüm web istekleri tek Blade görünümüne düşer.
| API uçları routes/api.php içinde (/api/*) hizmet eder.
|
| ÖNEMLİ: kısıt api yollarını DIŞLAR. Eskiden '.*' idi; /api/v1/... gibi
| eşleşmeyen api istekleri buraya düşüp 200 HTML basıyordu (masaüstü istemci
| bunu "parse error" sanıyordu). Artık api yolları eşleşmezse Laravel'in normal
| 404 yoluna düşer ve bootstrap/app.php JSON gövdesi üretir.
| Not: tam "api" de dışlanır — (?!api[/]) tek başına "/api"yi engellemez.
*/

/*
|--------------------------------------------------------------------------
| Ödeme linkinin müşteriye görünen sayfası 
|--------------------------------------------------------------------------
| PayTR'ın merchant_ok_url / merchant_fail_url alanları ZORUNLU ve veri POST etmez;
| iyzico dönüşü de buraya yönlendirilir. Sayfa durumu kendi veritabanımızdan okur.
| Giriş istemez, SPA değildir (ödeme yapan müşteri hesabımıza üye olmak zorunda değil).
| SPA karşılama rotasından ÖNCE tanımlanır, aksi hâlde '{any}' onu yutar.
*/

Route::get('odeme/{token}', [PaymentLinkPageController::class, 'show'])->where('token', '[a-f0-9]{32}');
Route::get('odeme/{token}/durum', [PaymentLinkPageController::class, 'status'])->where('token', '[a-f0-9]{32}');

Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api($|\/)).*$');