<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Party;
use App\Models\Payment;
use Carbon\Carbon;

/**
 * Cari bakiye dökümü (tek hesaplama noktası: bakiye ucu + ödeme cevabındaki party_balance).
 *
 * İşaret sözleşmesi: balance pozitifse alacağımız (müşteri bize borçlu),
 * negatifse borcumuz. Formül:
 * balance = opening_balance + sales_issued_total − receipts_total
 *           − purchases_issued_total + payments_out_total
 * Yalnız issued/partially_paid/paid durumundaki belgeler sayılır.
 */
class PartyBalance
{
    public static function overall(Party $party): float
    {
        return round((float) static::for($party)['totals']['balance'], 4);
    }

    /**
     * @return array{totals: array, by_currency: array, aging: array, open_documents: array, paid_map: array}
     */
    public static function for(Party $party): array
    {
        $companyId = (int) $party->company_id;

        $docs = static::queryDocuments($companyId, [$party->id])->get();
        $payments = static::queryPayments($companyId, [$party->id])->get();

        return static::build($party, $docs, $payments);
    }

    /**
     * TOPLU döküm : cari bakiye raporu sayfa başına 100 cari işler;
     * her cari için iki sorgu (belge + ödeme) = 200 sorgu. Bu metot verilen cari kümesinin
     * belge ve ödemelerini FIRMA BAŞINA TEK iki sorguda çeker ve `build()` ile aynı satırı
     * paylaşır — tekil ve toplu sonuçların birbirinden sapması mümkün değil (aynı kod).
     *
     * Kiracı güvenliği: çağıran zaten kiracı süzgecinden geçmiş Party modelleri verir;
     * yine de firma eşleşmesi burada denetlenir, böylece dışarıdan sızan cari id'si
     * döküme sızmaz (`where('company_id')` + PHP tarafı filtre).
     *
     * @param  iterable<Party>  $parties
     * @return array<int, array{totals: array, by_currency: array, aging: array, open_documents: array, paid_map: array}>
     *         anahtar = party_id
     */
    public static function batch(iterable $parties): array
    {
        /** @var array<int, list<Party>> $firmaBazinda */
        $firmaBazinda = [];
        foreach ($parties as $party) {
            $firmaBazinda[(int) $party->company_id][] = $party;
        }

        $out = [];

        foreach ($firmaBazinda as $companyId => $group) {
            $ids = array_values(array_unique(array_map(static fn (Party $p) => (int) $p->id, $group)));

            $docs = static::queryDocuments($companyId, $ids)->get()->groupBy('party_id');
            $payments = static::queryPayments($companyId, $ids)->get()->groupBy('party_id');

            foreach ($group as $party) {
                if ((int) $party->company_id !== $companyId) {
                    continue; // kiracı dışı model bu döküme giremez
                }
                $out[(int) $party->id] = static::build(
                    $party,
                    $docs->get((int) $party->id, collect()),
                    $payments->get((int) $party->id, collect()),
                );
            }
        }

        return $out;
    }

    protected static function queryDocuments(int $companyId, array $partyIds)
    {
        return Document::where('company_id', $companyId)
            ->whereIn('party_id', $partyIds)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereIn('type', [Document::TYPE_INVOICE, Document::TYPE_PURCHASE])
            ->orderBy('due_date')
            ->orderBy('id');
    }

    protected static function queryPayments(int $companyId, array $partyIds)
    {
        return Payment::where('company_id', $companyId)->whereIn('party_id', $partyIds);
    }

    /**
     * Tek bir carinin dökümü: belge/ödeme kümelerinden hesaplama. `for()` ve `batch()`
     * buradan beslenir.
     *
     * @param  \Illuminate\Support\Collection<int, Document>  $docs
     * @param  \Illuminate\Support\Collection<int, Payment>   $payments
     */
    protected static function build(Party $party, $docs, $payments): array
    {
        $paidByDoc = [];
        foreach ($payments as $p) {
            if ($p->document_id === null) {
                continue;
            }
            $paidByDoc[$p->document_id] = round(($paidByDoc[$p->document_id] ?? 0) + (float) $p->amount, 4);
        }

        $totals = ['sales_total' => 0.0, 'purchases_total' => 0.0, 'receipts_total' => 0.0, 'payments_total' => 0.0, 'balance' => 0.0];
        $byCurrency = [];
        $openDocuments = [];
        $today = Carbon::today();

        $bucket = function (string $ccy): array {
            return ['sales' => 0.0, 'purchases' => 0.0, 'receipts' => 0.0, 'payments' => 0.0, 'balance' => 0.0];
        };

        foreach ($docs as $doc) {
            $ccy = $doc->currency_code;
            $byCurrency[$ccy] ??= $bucket($ccy);
            $grand = round((float) $doc->grand_total, 4);

            if ($doc->type === Document::TYPE_INVOICE) {
                $totals['sales_total'] = round($totals['sales_total'] + $grand, 4);
                $byCurrency[$ccy]['sales'] = round($byCurrency[$ccy]['sales'] + $grand, 4);
            } else {
                $totals['purchases_total'] = round($totals['purchases_total'] + $grand, 4);
                $byCurrency[$ccy]['purchases'] = round($byCurrency[$ccy]['purchases'] + $grand, 4);
            }

            $paidTotal = round($paidByDoc[$doc->id] ?? 0, 4);
            $openAmount = round($grand - $paidTotal, 4);

            if ($openAmount > 0) {
                $due = $doc->due_date ? Carbon::parse($doc->due_date->format('Y-m-d')) : null;
                $daysOverdue = $due ? max(0, (int) $today->diffInDays($due, false) * -1) : 0;

                $openDocuments[] = [
                    'id' => $doc->id,
                    'type' => $doc->type,
                    'serial' => $doc->serial,
                    'issue_date' => $doc->issue_date?->format('Y-m-d'),
                    'due_date' => $doc->due_date?->format('Y-m-d'),
                    'grand_total' => $grand,
                    'paid_total' => $paidTotal,
                    'open_amount' => $openAmount,
                    'days_overdue' => $daysOverdue,
                ];
            }
        }

        foreach ($payments as $p) {
            $ccy = $p->currency_code;
            $byCurrency[$ccy] ??= $bucket($ccy);
            $amount = round((float) $p->amount, 4);

            if ($p->direction === Payment::DIRECTION_IN) {
                $totals['receipts_total'] = round($totals['receipts_total'] + $amount, 4);
                $byCurrency[$ccy]['receipts'] = round($byCurrency[$ccy]['receipts'] + $amount, 4);
            } else {
                $totals['payments_total'] = round($totals['payments_total'] + $amount, 4);
                $byCurrency[$ccy]['payments'] = round($byCurrency[$ccy]['payments'] + $amount, 4);
            }
        }

        $opening = round((float) $party->opening_balance, 4);
        $totals['balance'] = round(
            $opening + $totals['sales_total'] - $totals['receipts_total'] - $totals['purchases_total'] + $totals['payments_total'],
            4
        );

        // Açılış bakiyesi carinin kendi para birimi kutusuna yazılır.
        $partyCcy = $party->currency_code ?: 'TRY';
        $byCurrency[$partyCcy] ??= $bucket($partyCcy);
        foreach ($byCurrency as $ccy => &$box) {
            $boxOpening = $ccy === $partyCcy ? $opening : 0.0;
            $box['balance'] = round($boxOpening + $box['sales'] - $box['receipts'] - $box['purchases'] + $box['payments'], 4);
        }
        unset($box);
        ksort($byCurrency);

        // Yaşlandırma: yalnız açık tutarı kalan satış faturaları, vade üzerinden.
        $aging = ['not_due' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_over_90' => 0.0];
        foreach ($openDocuments as $open) {
            if ($open['type'] !== Document::TYPE_INVOICE) {
                continue;
            }
            $overdue = $open['days_overdue'];
            $key = match (true) {
                $overdue <= 0 => 'not_due',
                $overdue <= 30 => 'days_1_30',
                $overdue <= 60 => 'days_31_60',
                $overdue <= 90 => 'days_61_90',
                default => 'days_over_90',
            };
            $aging[$key] = round($aging[$key] + $open['open_amount'], 4);
        }

        // Vade artan sırada; vadesizler sonda.
        usort($openDocuments, fn ($a, $b) => ($a['due_date'] ?? '9999-12-31') <=> ($b['due_date'] ?? '9999-12-31') ?: $a['id'] <=> $b['id']);

        return [
            'totals' => $totals,
            'by_currency' => $byCurrency,
            'aging' => $aging,
            'open_documents' => $openDocuments,
            'paid_map' => $paidByDoc,
        ];
    }
}
