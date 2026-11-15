<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
use App\Services\OnlinePaymentRecorder;
use App\Support\Payments\GatewayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ödeme sağlayıcı bildirimleri — GİRİŞ İSTEMEZ .
 *
 * Bu uçlar internete AÇIKTIR ve üç sebebi vardır:
 *  1. PayTR bildirimi SUNUCUDAN SUNUCUYA gelir; oturum/token yoktur (doküman bunu
 *     açıkça söyler). Firma, `merchant_oid` = bizim link token'ımız üzerinden çözülür.
 *  2. iyzico'da müşteri tarayıcısı `token` ile geri döner. Buradan gelen hiçbir değer
 *     ÖDEME SONUCU OLARAK OKUNMAZ; sonuç iyzico'dan sunucu tarafında yeniden sorgulanır.
 *  3. Para bizim içimizden geçmediği için "ödendi" diyen tek yer sağlayıcıdır — ve o
 *     söz İMZA DOĞRULANARAK kabul edilir.
 *
 * Yanıt sözleşmeleri sağlayıcıya göredir: PayTR gövdede TAM OLARAK `OK` ister (bir
 * karakter bile fazlaysa bildirim tekrar denenir), iyzico'da yanıt müşteriye görünen
 * bir yönlendirmedir.
 */
class PaymentCallbackController extends Controller
{
    /**
     * PayTR Bildirim URL'si: `POST /api/paytr/callback`.
     *
     * Mağaza panelinde tanımlanan TEK ortak adrestir; hangi firmanın işlemi olduğu
     * `merchant_oid` (link token) ile çözülür.
     */
    public function paytr(Request $request)
    {
        $token = $request->input('merchant_oid');

        if (! is_string($token) || $token === '') {
            Log::warning('PayTR bildirimi merchant_oid taşımıyordu');

            return $this->paytrAnswer('', 'missing merchant_oid', 400);
        }

        // Token şifreli saklandığı için arama özeti (token_hash) üzerinden yapılır.
        $link = PaymentLink::where('token_hash', PaymentLink::hashToken($token))->first();

        if ($link === null) {
            // Tanımadığımız sipariş numarası: "OK" basıp konuyu kapatmak, PayTR tarafında
            // tahsilat olmuş olabilirken bizde hiç kayıt olmaması demek. Sessizliğe
            // tercih edilir: NON-OK → PayTR bildirimi tekrar dener ve panelde görünür kalır.
            Log::critical('PayTR bildirimi tanımadığımız bir merchant_oid taşıyor', [
                'merchant_oid' => $token,
                'status' => $request->input('status'),
            ]);

            return $this->paytrAnswer($token, 'unknown order', 404);
        }

        $credential = GatewayResolver::credentialForLink($link);

        if ($credential === null) {
            Log::critical('PayTR bildirimi geldi ama firmanın ödeme hesabı kayıtlı değil', [
                'company_id' => $link->company_id,
                'payment_link_id' => $link->id,
            ]);

            return $this->paytrAnswer($token, 'no account', 500);
        }

        $gateway = GatewayResolver::gateway($credential);
        $notice = $gateway->verifyNotification($request, $link);

        if (! ($notice['valid'] ?? false)) {
            // İmza tutmuyor: bu istek PayTR'dan gelmemiş olabilir (veya salt/anahtar
            // değişmiş). Tahsilat YAZILMAZ.
            Log::error('PayTR bildiriminin imzası doğrulanamadı', [
                'company_id' => $link->company_id,
                'payment_link_id' => $link->id,
                'message' => $notice['message'] ?? null,
            ]);

            return $this->paytrAnswer($token, 'bad hash', 403);
        }

        $outcome = OnlinePaymentRecorder::apply($link, $notice, $credential);

        Log::info('PayTR bildirimi işlendi', [
            'company_id' => $link->company_id,
            'payment_link_id' => $link->id,
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
            'link_status' => $outcome['status'],
            'document_status' => $outcome['document_status'],
        ]);

        return $this->paytrAnswer($token, null, 200);
    }

    /**
     * iyzico geri dönüşü: `GET|POST /api/iyzico/callback?token=…` (müşterinin tarayıcısı).
     *
     * Yönlendirmede SADECE token vardır ve imza taşımaz. Sonuç burada iyzico'dan
     * sunucu tarafında sorgulanır; sonra müşteri linkin halka açık sonuç sayfasına
     * yönlendirilir (para durumu orada veritabanından okunur).
     */
    public function iyzico(Request $request)
    {
        $token = $request->input('token');

        if (! is_string($token) || $token === '') {
            return redirect('/odeme');
        }

        // Sağlayıcı jetonu şifreli saklandığı için arama özeti üzerinden yapılır.
        $link = PaymentLink::where('provider_token_hash', PaymentLink::hashProviderToken($token))->first();

        if ($link === null) {
            Log::warning('iyzico geri dönüşü tanımadığımız bir token taşıyor', ['token' => $token]);

            return redirect('/odeme');
        }

        $credential = GatewayResolver::credentialForLink($link);

        if ($credential === null) {
            Log::critical('iyzico geri dönüşü geldi ama firmanın ödeme hesabı kayıtlı değil', [
                'company_id' => $link->company_id,
                'payment_link_id' => $link->id,
            ]);

            return redirect('/odeme/'.$link->token);
        }

        $gateway = GatewayResolver::gateway($credential);
        $notice = $gateway->verifyNotification($request, $link);

        if (! ($notice['valid'] ?? false)) {
            Log::error('iyzico sonuç sorgusu doğrulanamadı', [
                'company_id' => $link->company_id,
                'payment_link_id' => $link->id,
                'message' => $notice['message'] ?? null,
            ]);

            return redirect('/odeme/'.$link->token.'?durum=dogrulanamadi');
        }

        $outcome = OnlinePaymentRecorder::apply($link, $notice, $credential);

        Log::info('iyzico sonucu işlendi', [
            'company_id' => $link->company_id,
            'payment_link_id' => $link->id,
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
            'link_status' => $outcome['status'],
            'document_status' => $outcome['document_status'],
        ]);

        return redirect('/odeme/'.$link->token);
    }

    /**
     * PayTR'a dönüş: gövde YALNIZ "OK". Başka içerik olursa PayTR bildirimi
     * başarısız sayar ve tekrar dener (bu, mükerrer bildirimi idempotent tutmamızın
     * da sebebidir).
     */
    protected function paytrAnswer(string $token, ?string $code, int $status)
    {
        $body = $status === 200 ? 'OK' : 'ERROR'.($code ? ':'.$code : '');

        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
