<?php

namespace App\Http\Controllers;

use App\Models\PaymentLink;
use Illuminate\Http\Request;

/**
 * Ödeme linkinin MÜŞTERİYE görünen sonuç sayfası (`/odeme/{token}`) — giriş istemez.
 *
 * Neden var: PayTR'ın `merchant_ok_url` / `merchant_fail_url` alanları ZORUNLUDUR ve
 * bu adreslere hiçbir veri POST edilmez (dokümanın uyarısı: orada sipariş onaylamayın).
 * Yani müşterinin indiği sayfa durumu KENDİ veritabanımızdan okumalı. Aynı sayfa
 * iyzico dönüşünde de kullanılır; iki sağlayıcının müşteri deneyimi böylece tek yerde durur.
 *
 * Güvenlik notu: link token'ı 32 karakter rastgele değerdir; sayfa token'ı bilene
 * yalnız "şu firma, şu tutar, şu durumda" bilgisini verir. Cari adı/vergi no gibi
 * kişisel veri BASMAZ — link e-posta/WhatsApp ile üçüncü kişilerin görebileceği
 * yerlere gönderilebildiği için bilinçli asgari düzey.
 */
class PaymentLinkPageController extends Controller
{
    public function show(Request $request, string $token)
    {
        $this->chooseLocale($request);

        // Token şifreli saklandığı için arama özeti (token_hash) üzerinden yapılır.
        $link = PaymentLink::with(['company', 'document', 'party'])
            ->where('token_hash', PaymentLink::hashToken($token))
            ->first();

        return view('payment-result', [
            'link' => $link,
            'refresh' => $link !== null && $link->status === PaymentLink::STATUS_PENDING,
        ]);
    }

    /**
     * Sayfanın kendini yenilediği uc (JSON). Bildirim ile ödeme arasında birkaç saniye
     * olabildiği için müşteri "ödendi" yazısını beklemeden görmek zorunda kalmasın.
     */
    public function status(Request $request, string $token)
    {
        $this->chooseLocale($request);

        $link = PaymentLink::with(['company'])->where('token_hash', PaymentLink::hashToken($token))->first();

        if ($link === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        return response()->json([
            'status' => $link->status,
            'status_label' => PaymentLink::statusLabel($link->status),
            'amount' => (float) $link->amount,
            'currency_code' => $link->currency_code,
            'company_name' => $link->company?->name,
            'failure_reason' => $link->failure_reason,
            'is_expired' => $link->isExpired(),
        ]);
    }

    /**
     * Dil: halka açık sayfada kullanıcı tercihi yok; `?dil=en` → İngilizce, olmazsa
     * tarayıcının Accept-Language'i, o da tanımsızsa Türkçe (ürünün ana dili).
     */
    protected function chooseLocale(Request $request): void
    {
        $wanted = mb_strtolower((string) $request->query('dil', ''));

        if ($wanted === '') {
            $header = mb_strtolower(explode(',', (string) $request->header('Accept-Language', ''))[0]);
            $wanted = str_starts_with($header, 'en') ? 'en' : '';
        }

        app()->setLocale(in_array($wanted, ['en', 'tr'], true) ? $wanted : config('app.locale', 'tr'));
    }
}
