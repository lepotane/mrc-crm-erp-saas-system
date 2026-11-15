<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentCredential;
use App\Models\PaymentLink;
use App\Support\Audit;
use App\Support\ExchangeRates;
use App\Support\ReportCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ödeme linki ödendiğinde deftere tahsilat işleyen TEK yer .
 *
 * Neden servis: iki sağlayıcının bildirimi (PayTR sunucu POST'u, iyzico yönlendirmesi
 * + sunucu sorgusu) ve ileride olabilecek elle "ödendi" işaretleme aynı işi yapacak.
 * Kural burada olmazsa her kanal kendi Payment satırını uydurur; mükerrer bildirimde
 * iki tahsilat, geç bildirime ikinci kayıt doğar (para hareketinde kabul edilemez).
 *
 * İdempotentlik sözleşmesi:
 *  - Link satırı tek doğruluk kaydıdır (`payment_id` doluysa iş yapılmıştır).
 *  - Aynı bildirim ikinci kez gelse de TEK Payment satırı doğar; sayaç artar, log düşer.
 *  - İptal edilmiş linkin geciken bildirimi KAYIT ÜRETMEZ (iade sağlayıcıdan yürür).
 *
 * Tutar sözleşmesi: sağlayıcının söylediği tahsilat tutarı ne ise o yazılır
 * (`total_amount` / `paidPrice`). Link tutarından farklıysa (taksitli ödeme, kur,
 * kısmi ön provizyon) Payment farklı tutarla açılır ve note'a fark işlenir —
 * çünkü müşteri'nin kartından çekilen gerçek para odur.
 */
class OnlinePaymentRecorder
{
    /**
     * @param  array{outcome: string, amount: float|null, reference: string|null, reason: string|null, message: string|null}  $notice
     * @return array{recorded: bool, status: string, reason: string|null, payment_id: int|null, document_status: string|null}
     */
    public static function apply(PaymentLink $link, array $notice, PaymentCredential $credential): array
    {
        if (($notice['outcome'] ?? 'pending') === 'failed') {
            return static::markFailed($link, $notice['reason'] ?? null);
        }

        if (($notice['outcome'] ?? 'pending') !== 'paid') {
            return [
                'recorded' => false,
                'status' => $link->status,
                'reason' => $notice['message'] ?? 'not_paid',
                'payment_id' => $link->payment_id,
                'document_status' => null,
            ];
        }

        $paymentId = null;
        $documentStatus = null;
        $reason = null;

        DB::transaction(function () use ($link, $notice, $credential, &$paymentId, &$documentStatus, &$reason) {
            /** @var PaymentLink $fresh */
            $fresh = PaymentLink::where('id', $link->id)->lockForUpdate()->first();

            $fresh->notification_count = (int) $fresh->notification_count + 1;
            $fresh->last_notification_at = now();

            if ($fresh->status === PaymentLink::STATUS_CANCELLED) {
                $fresh->save();
                $reason = 'cancelled';

                Log::warning('Ödeme linki iptal edilmiş ama ödeme bildirimi geldi', [
                    'payment_link_id' => $fresh->id,
                    'company_id' => $fresh->company_id,
                    'provider' => $fresh->provider,
                    'amount' => $notice['amount'] ?? null,
                ]);

                return;
            }

            if ($fresh->status === PaymentLink::STATUS_PAID || $fresh->payment_id !== null) {
                $fresh->save();
                $reason = 'duplicate';
                $paymentId = $fresh->payment_id;

                return;
            }

            $amount = round((float) ($notice['amount'] ?? $fresh->amount), 4);

            if ($amount <= 0) {
                $amount = round((float) $fresh->amount, 4);
            }

            $note = __('Ödeme linki üzerinden tahsilat (:provider)', [
                'provider' => PaymentCredential::label($fresh->provider),
            ]);

            if (abs($amount - round((float) $fresh->amount, 4)) > 0.001) {
                $note .= ' · '.__('Sağlayıcının tahsil ettiği tutar link tutarından farklı.').
                    sprintf(' (%s %s)', number_format((float) $fresh->amount, 2, ',', ''), $fresh->currency_code);
            }

            if ($fresh->isExpired()) {
                $note .= ' · '.__('Bildirim linkin geçerlilik süresi dolduktan sonra geldi.');
            }

            // Sanal POS tahsilatı firmanın bir hesabına işlenir; hesap seçilmemişse
            // (veya para birimleri uyuşmuyorsa) Payments satırı hesapsız kalır ve
            // Kasa/Banka ekranı o parayı göstermez — bilinçli, uydurma hesap daha kötüdür.
            $bankAccountId = static::settlementAccountId($credential, $fresh, $note);

            $payment = Payment::create([
                'company_id' => $fresh->company_id,
                'party_id' => $fresh->party_id,
                'document_id' => $fresh->document_id,
                'bank_account_id' => $bankAccountId,
                'direction' => Payment::DIRECTION_IN,
                'amount' => $amount,
                'currency_code' => $fresh->currency_code,
                // Online tahsilatta kur fotoğrafı işlem anınındır (bugünkü kur);
                // sağlayıcı kendi kuruyla tahsil ettiği için fark nota işlenir.
                'exchange_rate' => $fresh->currency_code === 'TRY'
                    ? 1
                    : (ExchangeRates::rateFor($fresh->currency_code, now()->format('Y-m-d')) ?? 1),
                'method' => $fresh->provider, // paytr | iyzico (Payment::METHODS)
                'paid_at' => now()->format('Y-m-d'),
                // Referans: sağlayıcı işlem kimliği (token DEĞİL — token düz metin
            // saklanırsa DB sızıntısı ödeme sayfasını açar).
            'reference' => mb_substr((string) ($notice['reference'] ?? ('link-'.$fresh->id)), 0, 60),
                'note' => mb_substr($note, 0, 255),
                'created_by' => $fresh->created_by,
            ]);

            $fresh->status = PaymentLink::STATUS_PAID;
            $fresh->paid_at = now();
            $fresh->payment_id = $payment->id;
            $fresh->reference = mb_substr((string) ($notice['reference'] ?? ('link-'.$fresh->id)), 0, 60);
            $fresh->failure_reason = null;
            $fresh->save();

            if ($fresh->document_id !== null) {
                $document = Document::find($fresh->document_id);

                if ($document !== null) {
                    $documentStatus = DocumentSettlement::recalculate($document);
                }
            }

            // Webhook yolu controller'ı atlar: denetim + rapor-sürüm burada yazılır.
            // Fail-closed değil fail-open: kayıt yazılamazsa para kaydı GERİ ALINMAZ.
            ReportCache::bump($fresh->company_id);
            Audit::record('payment.created', $fresh->company_id, null, $payment, [
                'amount' => (float) $payment->amount,
                'direction' => $payment->direction,
                'method' => $payment->method,
                'via' => $fresh->provider,
                'payment_link_id' => $fresh->id,
            ]);

            $paymentId = $payment->id;
            $link->refresh();
        });

        return [
            'recorded' => $paymentId !== null && $reason === null,
            'status' => $link->refresh()->status,
            'reason' => $reason,
            'payment_id' => $paymentId,
            'document_status' => $documentStatus,
        ];
    }

    /**
     * Sağlayıcı ödemenin ONAYLAMADIĞINI bildirdi: tahsilat doğmaz, link hanesine sebep
     * yazılır. Link yeniden kullanılabilir kalmaz (müşteri tekrar link istemeli) —
     * sağlayıcı tarafında işlem kapanmıştır.
     *
     * @return array{recorded: bool, status: string, reason: string|null, payment_id: null, document_status: null}
     */
    public static function markFailed(PaymentLink $link, ?string $reason): array
    {
        DB::transaction(function () use ($link, $reason) {
            /** @var PaymentLink $fresh */
            $fresh = PaymentLink::where('id', $link->id)->lockForUpdate()->first();

            if ($fresh->status === PaymentLink::STATUS_PAID) {
                return; // ödenmiş linkin geç "başarısız" bildirimi kaydı geri almaz
            }

            $fresh->notification_count = (int) $fresh->notification_count + 1;
            $fresh->last_notification_at = now();
            $fresh->status = PaymentLink::STATUS_FAILED;
            $fresh->failure_reason = mb_substr((string) ($reason ?: __('Ödeme sağlayıcı işlemi onaylamadı.')), 0, 190);
            $fresh->save();
        });

        return [
            'recorded' => false,
            'status' => $link->refresh()->status,
            'reason' => 'failed',
            'payment_id' => null,
            'document_status' => null,
        ];
    }

    /**
     * Bildirim sayacı: imzasız/tanınmayan istekler de sayılır ki "bize ulaşmayan
     * bildirim var mı" sorusu veriyle cevaplanabilsin.
     */
    public static function touchNotification(PaymentLink $link): void
    {
        PaymentLink::where('id', $link->id)->update([
            'notification_count' => $link->notification_count + 1,
            'last_notification_at' => now(),
        ]);
    }

    /** Para birimi uyuşmuyorsa hesap atanmaz (uydurma muhasebe kaydı üretmemek için). */
    protected static function settlementAccountId(PaymentCredential $credential, PaymentLink $link, string &$note): ?int
    {
        if ($credential->bank_account_id === null) {
            return null;
        }

        $account = $credential->bankAccount;

        if ($account === null || ! $account->is_active) {
            $note .= ' · '.__('Tanımlı tahsilat hesabı artık etkin değil; ödeme hesaba işlenmedi.');

            return null;
        }

        if ($account->currency_code !== $link->currency_code) {
            $note .= sprintf(
                ' · %s',
                __('Tahsilat hesabının para birimi (:account) ile ödeme para birimi (:payment) farklı; ödeme hesaba işlenmedi.', [
                    'account' => $account->currency_code,
                    'payment' => $link->currency_code,
                ])
            );

            return null;
        }

        return (int) $account->id;
    }
}
