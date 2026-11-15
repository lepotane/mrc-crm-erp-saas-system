<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Payment;

/**
 * Belgenin tahsilat durumunu bağlı ödemelerden yeniden hesaplar.
 *
 * Neden tek nokta: kurallar iki yerde sayılırsa (manuel ödeme + webhook) bir süre sonra
 * sapabilir. Webhook ve manuel ödemeler aynı durum motorunu kullanır.
 *
 * Kamuya açık durum makinesi sıkı kalır (issued → partially_paid → paid); burası
 * İÇ yeniden hesaplamadır, makineyi gevşetmez.
 */
class DocumentSettlement
{
    /** Yeniden hesaplanan durum. Ödeme toplamı ≥ grand_total → paid. */
    public static function recalculate(Document $document): string
    {
        if (! in_array($document->status, ['issued', 'partially_paid', 'paid'], true)) {
            return $document->status;
        }

        $total = round((float) Payment::where('document_id', $document->id)->sum('amount'), 4);
        $grand = round((float) $document->grand_total, 4);

        $to = $total >= $grand ? 'paid' : ($total > 0 ? 'partially_paid' : 'issued');

        if ($to !== $document->status) {
            $document->update(['status' => $to]);
        }

        return $document->refresh()->status;
    }

    /** Belge için tahsil edilmiş toplam (aynı ödeme kümesi, aynı tanım). */
    public static function paidTotal(Document $document): float
    {
        return round((float) Payment::where('document_id', $document->id)->sum('amount'), 4);
    }

    /** Kalan alacak: link tutarını buna göre öneriyoruz (eksik/taşkın tahsilat olmasın). */
    public static function openAmount(Document $document): float
    {
        return max(0, round((float) $document->grand_total - static::paidTotal($document), 4));
    }
}
