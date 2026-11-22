<?php

use App\Http\Controllers\Api\V1\IntegrationPriceController;
use App\Http\Controllers\Api\V1\IntegrationHealthController;
use App\Http\Controllers\Api\V1\AccountantController;
use App\Http\Controllers\Api\V1\IntegrationWebhookController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentPdfController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\JoinRequestController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use App\Http\Controllers\Api\V1\PartyController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentLinkController;
use App\Http\Controllers\Api\V1\PaymentSettingController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductPriceController;
use App\Http\Controllers\Api\V1\DocumentPriceClassController;
use App\Http\Controllers\Api\V1\PrintJobController;
use App\Http\Controllers\Api\V1\PrintTemplateController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\BridgeController;
use App\Http\Controllers\Api\V1\QuickSaleController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Kimlik doğrulama (token gerektirmeyen) — brute-force'a karşı ayrı düşük bütçe
    // (config/api.php · RateLimiter 'auth': IP + e-posta başına).
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

    // Güvenli bulanık firma doğrulama ve personel katılım başvurusu (Katıl / Join)
    Route::get('public/companies', [JoinRequestController::class, 'publicCompanies'])
        ->middleware(['throttle:30,1', 'locale']);
    Route::post('public/company-lookup', [JoinRequestController::class, 'lookupCompany'])
        ->middleware(['throttle:30,1', 'locale']);
    Route::post('public/join-requests', [JoinRequestController::class, 'store'])
        ->middleware(['throttle:10,1', 'locale']);
    Route::get('auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('auth/2fa/challenge', [AuthController::class, 'challenge'])
        ->middleware(['throttle:auth', 'auth:sanctum', 'locale']);

    // Davetli taraf: bu uçlar firma bağlamı gerektirmez (davet edilen kişi henüz
    // hiçbir firmaya bağlı olmayabilir); çeviri için locale middleware’i burada da geçerli.
    // token.scope: PAT yetenekleri ('read' anahtarı yazma isteklerinde 403 alır).
    Route::middleware(['auth:sanctum', 'token.scope', 'locale'])->group(function () {
        Route::post('auth/email/verification-notification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');
        Route::get('invitations/mine', [InvitationController::class, 'mine']);
        Route::post('invitations/accept', [InvitationController::class, 'accept']);

        // Erişim anahtarı (PAT) yönetimi — masaüstü istemci / entegrasyon sözleşmesi.
        Route::get('me/tokens', [TokenController::class, 'index']);
        Route::post('me/tokens', [TokenController::class, 'store']);
        Route::delete('me/tokens/others', [TokenController::class, 'destroyOthers']);
        Route::delete('me/tokens/{tokenId}', [TokenController::class, 'destroy']);

        // İki faktörlü giriş : kullanıcıya ait, firma bağlamı gerekmez.
        Route::post('me/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('me/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('me/2fa/disable', [TwoFactorController::class, 'disable']);
        Route::post('me/2fa/recovery-codes', [TwoFactorController::class, 'regenerateCodes']);
    });

    /*
    | MRC Bridge cihaz protokolü  — GİRİŞ İSTEMEZ (cihaz anahtarıyla).
    | Eşleşme, yoklama, ACK ve anahtar döndürme kullanıcı oturumu taşımaz;
    | kiracı cihaz satırından çözülür. v1 öneki TAŞIR ama company.context
    | grubunun DIŞINDADIR: bağlam cihazdandır, kullanıcıdan değil.
    */
    Route::post('bridge/pair', [BridgeController::class, 'pair'])->middleware('throttle:60,1');

    Route::middleware('bridge.device')->group(function () {
        Route::get('bridge/poll', [BridgeController::class, 'poll']);
        Route::post('bridge/jobs/{job}/ack', [BridgeController::class, 'ack']);
        Route::post('bridge/rotate', [BridgeController::class, 'rotate']);
    });

    /*
    | Entegrasyon webhook'u — GİRİŞ İSTEMEZ, HMAC imzasıyla doğrulanır.
    | Kiracı webhook gövdesinden/header'ından çözülür; ağır iş kuyruğa alınır.
    */
    Route::post('integrations/tsoft/webhook', [IntegrationWebhookController::class, 'handle'])
        ->middleware('throttle:120,1');

    // Kimlikli uçlar
    // Kapalı modülün API yüzeyi `module.enabled` ile kapanır : yol öneki
    // ModuleRegistry eşleşmesine düşen istek, firma için kapalıysa 403
    // {message, code: module_disabled} alır. Satır yoksa modül AÇIK varsayılır.
    Route::middleware(['auth:sanctum', 'token.scope', 'company.context', 'module.enabled', 'locale'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('me', [MeController::class, 'me']);
        Route::patch('me/profile', [MeController::class, 'updateProfile']);
        Route::patch('me/locale', [MeController::class, 'updateLocale']);
        Route::get('companies', [MeController::class, 'companies']);
        Route::post('companies/select', [MeController::class, 'select']);

        // Firma profili, logo, üyeler ve katılım başvuruları
        Route::get('company', [CompanyController::class, 'show']);
        Route::patch('company', [CompanyController::class, 'update']);
        Route::post('company/logo', [CompanyController::class, 'uploadLogo']);
        Route::get('company/members', [CompanyController::class, 'members']);
        Route::patch('company/members/{user}', [CompanyController::class, 'updateMember']);

        Route::get('join-requests', [JoinRequestController::class, 'index']);
        Route::get('join-requests/form-schema', [JoinRequestController::class, 'formSchema']);
        Route::put('join-requests/form-schema', [JoinRequestController::class, 'updateFormSchema']);
        Route::get('join-requests/{joinRequest}/cv', [JoinRequestController::class, 'downloadCv']);
        Route::post('join-requests/{joinRequest}/approve', [JoinRequestController::class, 'approve']);
        Route::post('join-requests/{joinRequest}/reject', [JoinRequestController::class, 'reject']);

        // Bildirim Merkezi (çan ikonu + okundu/silme + sağ üst popup senkronu)
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('notifications/clear-all', [NotificationController::class, 'clearAll']);
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

        // Plan ve limit kullanimi 
        Route::get('company/plan', [CompanyController::class, 'plan']);

        // Firma bazlı ayarlar
        Route::get('settings', [SettingsController::class, 'index']);
        Route::patch('settings', [SettingsController::class, 'update']);

        // Ekip davetleri
        Route::get('invitations', [InvitationController::class, 'index']);
        Route::post('invitations', [InvitationController::class, 'store']);
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy']);

        // Faz 2 — Cari hesaplar + iletişim kişileri
        Route::get('parties', [PartyController::class, 'index']);
        Route::post('parties', [PartyController::class, 'store']);
        Route::get('parties/{party}', [PartyController::class, 'show']);
        Route::patch('parties/{party}', [PartyController::class, 'update']);
        Route::delete('parties/{party}', [PartyController::class, 'destroy']);
        Route::get('parties/{party}/contacts', [PartyController::class, 'contactsIndex']);
        Route::post('parties/{party}/contacts', [PartyController::class, 'contactsStore']);
        Route::delete('parties/{party}/contacts/{contactId}', [PartyController::class, 'contactsDestroy']);

        // Faz 2 — Ürün kataloğu
        Route::get('products', [ProductController::class, 'index']);
        Route::post('products', [ProductController::class, 'store']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::patch('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);

        // Fiyat sınıfları (para birimi bazlı fiyat listesi)
        Route::put('products/{product}/prices', [ProductPriceController::class, 'update']);
        Route::post('products/{product}/prices/sync', [ProductPriceController::class, 'sync']);
        Route::delete('products/{product}/prices/{currency}', [ProductPriceController::class, 'destroy']);

        Route::get('categories', [CategoryController::class, 'index']);
        Route::post('categories', [CategoryController::class, 'store']);
        Route::patch('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

        Route::get('units', [UnitController::class, 'index']);
        Route::post('units', [UnitController::class, 'store']);
        Route::delete('units/{unit}', [UnitController::class, 'destroy']);

        // Faz 2 — Depolar + stok hareketleri
        Route::get('warehouses', [WarehouseController::class, 'index']);
        Route::post('warehouses', [WarehouseController::class, 'store']);
        Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy']);

        Route::get('warehouses/{warehouse}/products', [WarehouseController::class, 'products']);

        Route::get('stock-movements', [StockMovementController::class, 'index']);
        Route::post('stock-movements', [StockMovementController::class, 'store']);

        // V2-3 — Depo pro: transferler + sayım oturumları.
        Route::get('stock-transfers', [StockTransferController::class, 'index']);
        Route::post('stock-transfers', [StockTransferController::class, 'store']);

        Route::get('stock-counts', [StockCountController::class, 'index']);
        Route::post('stock-counts', [StockCountController::class, 'store']);
        Route::get('stock-counts/{count}', [StockCountController::class, 'show']);
        Route::post('stock-counts/{count}/approve', [StockCountController::class, 'approve']);
        Route::post('stock-counts/{count}/cancel', [StockCountController::class, 'cancel']);

        // Faz 3 — Belgeler (teklif → sipariş → satış faturası + alım faturası)
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::patch('documents/{document}', [DocumentController::class, 'update']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
        Route::post('documents/{document}/status', [DocumentController::class, 'changeStatus']);
        Route::post('documents/{document}/convert', [DocumentController::class, 'convert']);
        Route::post('documents/{document}/duplicate', [DocumentController::class, 'duplicate']);
        Route::post('documents/{document}/return', [DocumentController::class, 'returnFrom']);
        // Fiyat sınıfı (toplu veya kalem bazlı)
        Route::post('documents/{document}/price-class', [DocumentPriceClassController::class, 'update']);
        Route::get('documents/{document}/pdf', [DocumentPdfController::class, 'show']);
        Route::get('documents/{document}/delivery-note', [DocumentPdfController::class, 'deliveryNote']);

        // V2-5 — Hızlı satış (POS): tek çağrıda fatura + stok + tahsilat + fiş.
        Route::post('quick-sales', [QuickSaleController::class, 'store']);
        Route::get('quick-sales/{document}/receipt.pdf', [QuickSaleController::class, 'receipt']);

        // Faz 3 — Kasa/Banka ve Ödemeler
        Route::get('bank-accounts', [BankAccountController::class, 'index']);
        Route::post('bank-accounts', [BankAccountController::class, 'store']);
        Route::patch('bank-accounts/{bankAccount}', [BankAccountController::class, 'update']);
        Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store']);
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);

        // V2-2 — Barkod üretimi + etiket PDF (ürün uçları; `urunler` modülüne bağlı).
        Route::get('products/{product}/barcode', [BarcodeController::class, 'show']);
        Route::post('products/{product}/barcode/assign', [BarcodeController::class, 'assign']);
        Route::post('products/labels', [BarcodeController::class, 'labels']);

        // Faz 7 — Online tahsilat (PayTR / iyzico). Hesap bilgileri YALNIZ Admin;
        // link üretmek her üye (Personel satış takip eder).
        Route::get('payment-settings', [PaymentSettingController::class, 'index']);
        Route::put('payment-settings/{provider}', [PaymentSettingController::class, 'update']);
        Route::delete('payment-settings/{provider}', [PaymentSettingController::class, 'destroy']);

        Route::get('payment-links', [PaymentLinkController::class, 'index']);
        Route::post('payment-links', [PaymentLinkController::class, 'store']);
        Route::get('payment-links/{paymentLink}', [PaymentLinkController::class, 'show']);
        Route::post('payment-links/{paymentLink}/cancel', [PaymentLinkController::class, 'cancel']);

        Route::get('parties/{party}/balance', [PaymentController::class, 'partyBalance']);

        // Hızlı arama (üst bar): cari + belge + ürün, tek uçta, kiracı izole.
        Route::get('search', SearchController::class);

        // V2-9 — Para birimleri + kurlar (liste herkese açık, tanım YALNIZ Admin).
        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::patch('currencies/{code}', [CurrencyController::class, 'update']);
        Route::post('exchange-rates/fetch', [CurrencyController::class, 'fetch']);
        Route::get('exchange-rates', [CurrencyController::class, 'rates']);

        // V2-7 — Dashboard widget ızgarası (çözümlenmiş ızgara herkese; katalog/kapsam Admin).
        Route::get('dashboard/widgets', [DashboardController::class, 'widgets']);
        Route::get('dashboard/widget-catalog', [DashboardController::class, 'catalog']);
        Route::put('dashboard/layout', [DashboardController::class, 'layout']);
        Route::patch('dashboard/scopes', [DashboardController::class, 'scopes']);

        // V2-8 — Modüller + eklentiler (liste herkese; yazma YALNIZ Admin).
        Route::get('modules', [ModuleController::class, 'index']);
        Route::patch('modules/{key}', [ModuleController::class, 'update']);
        Route::post('plugins/discover', [ModuleController::class, 'discover']);
        Route::post('plugins/install', [ModuleController::class, 'install']);
        Route::delete('plugins/{key}', [ModuleController::class, 'uninstall']);

        // V2-6 — MRC Bridge: cihazlar + yazdırma kuyruğu (cihaz yönetimi YALNIZ Admin).
        Route::get('devices', [PrintJobController::class, 'devices']);
        Route::post('devices', [PrintJobController::class, 'pairDevice']);
        Route::delete('devices/{device}', [PrintJobController::class, 'destroyDevice']);

        Route::get('print-jobs', [PrintJobController::class, 'index']);
        Route::post('print-jobs', [PrintJobController::class, 'store']);
        Route::delete('print-jobs/{printJob}', [PrintJobController::class, 'destroy']);

        // Karar 48 — Yazdırma şablonları (tasarımcı): türde çok şablon, biri varsayılan.
        Route::get('print-templates', [PrintTemplateController::class, 'index']);
        Route::post('print-templates', [PrintTemplateController::class, 'store']);
        Route::post('print-templates/preview', [PrintTemplateController::class, 'preview']);
        Route::post('print-templates/preview-html', [PrintTemplateController::class, 'previewHtml']);
        Route::get('print-templates/{template}', [PrintTemplateController::class, 'show']);
        Route::patch('print-templates/{template}', [PrintTemplateController::class, 'update']);
        Route::delete('print-templates/{template}', [PrintTemplateController::class, 'destroy']);
        Route::post('print-templates/{template}/default', [PrintTemplateController::class, 'setDefault']);

        // Faz 7 — Denetim kaydı : salt okunur, YALNIZ Admin.
        Route::get('audit', [AuditController::class, 'index']);

        // Entegrasyon sağlık paneli (Tsoft / GİB / Luca) — kiracı bağlamlı, salt okunur.
        Route::get('integrations/health', [IntegrationHealthController::class, 'index']);

        // Fiyat senkronu: tek ürün / seçili ürünler / tüm ürünler, iki yönlü.
        Route::post('integrations/prices/sync', [IntegrationPriceController::class, 'sync']);

        // Mali müşavir paneli — fiş onayı, hesap eşleme, GİB dosyaları, Luca aktarımı.
        Route::get('integrations/accountant', [AccountantController::class, 'index']);
        Route::post('integrations/accountant/approve', [AccountantController::class, 'approve']);

        // Faz 3 — Raporlar (önbellekli: report.cache; yazma sonrası sürüm artar)
        Route::get('reports/sales-summary', [ReportController::class, 'salesSummary'])->middleware('report.cache');
        Route::get('reports/tax-summary', [ReportController::class, 'taxSummary'])->middleware('report.cache');
        Route::get('reports/party-balances', [ReportController::class, 'partyBalances'])->middleware('report.cache');
        Route::get('reports/receivables-aging', [ReportController::class, 'receivablesAging'])->middleware('report.cache');
        Route::get('reports/payables-aging', [ReportController::class, 'payablesAging'])->middleware('report.cache');
        Route::get('reports/stock-valuation', [ReportController::class, 'stockValuation'])->middleware('report.cache');
        Route::get('reports/stock-movements-summary', [ReportController::class, 'stockMovementsSummary'])->middleware('report.cache');

        // V2-1 — Kâr raporu: ciro − COGS, iadeler netlenir.
        Route::get('reports/profit', [ReportController::class, 'profit'])->middleware('report.cache');

        // V2-3 — Depo pro raporları (tedarik önerisi, ABC, devir hızı).
        Route::get('reports/stock-replenishment', [ReportController::class, 'stockReplenishment'])->middleware('report.cache');
        Route::get('reports/stock-abc', [ReportController::class, 'stockAbc'])->middleware('report.cache');
        Route::get('reports/stock-turnover', [ReportController::class, 'stockTurnover'])->middleware('report.cache');
    });

    // Gizli SüperAdmin Yönetim Üssü (`superadmin.stealth` — yetkisiz erişime 404 döner)
    Route::prefix('sa-core')->middleware(['auth:sanctum', 'superadmin.stealth', 'locale'])->group(function () {
        Route::get('overview', [SuperAdminController::class, 'overview']);
        Route::patch('companies/{company}', [SuperAdminController::class, 'updateCompany']);
        Route::delete('companies/{company}', [SuperAdminController::class, 'destroyCompany']);
        Route::patch('credentials', [SuperAdminController::class, 'updateCredentials']);
        Route::post('superadmins', [SuperAdminController::class, 'storeSuperAdmin']);
        Route::delete('superadmins/{user}', [SuperAdminController::class, 'revokeSuperAdmin']);
        Route::post('plugins', [SuperAdminController::class, 'createPlugin']);
    });
});

/*
|--------------------------------------------------------------------------
| Ödeme sağlayıcı bildirimleri  — GİRİŞ İSTEMEZ
|--------------------------------------------------------------------------
| PayTR'ın Bildirim URL'si mağaza panelinde tanımlanır ve oturum TAŞIMAZ; iyzico'da
| müşteri tarayıcısı `token` ile buraya döner. Kiracı çözümü link satırından yapılır
| (merchant_oid / provider_token = bizim token'ımız). Bu yüzden v1 öneki DIŞINDADIR:
| sağlayıcıya tek sabit adres veriyoruz, firma başına adres üretmiyoruz.
|
| Hız sınırı: 'api' sınırlayıcısı (config/api.php) IP başına yeterli; yine de tek
| IP'den gelen bildirim seli link aramasını yavaşlatmasın diye ayrı, geniş bir bütçe.
*/
Route::post('paytr/callback', [PaymentCallbackController::class, 'paytr'])
    ->middleware('throttle:60,1');

Route::match(['get', 'post'], 'iyzico/callback', [PaymentCallbackController::class, 'iyzico'])
    ->middleware('throttle:60,1');

