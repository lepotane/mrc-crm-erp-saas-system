<?php

namespace App\Integration\Luca;

use App\Integration\Orders\Money;
use App\Models\Company;
use App\Models\Document;
use App\Models\TenantIntegration;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Luca resmi "Excel Veri Aktarımı / Muhasebe Fişi" dosya modu dışa aktarımı.
 *
 * Dosya formatı (önceden doğrulanmış resmi şablondur):
 * - tam 11 kolon, sabit sıra, ayırıcı ';'
 * - tarihler gg.AA.yyyy
 * - ondalık ayırıcı ',', binlik '.'
 * - UTF-8 BOM ile yazılır
 *
 * KVKK: satır açıklamasında yalnızca belge seri numarası kullanılır. Müşteri
 * adı/unvanı, VKN/TCKN, e-posta, telefon ve adres ASLA dosyaya veya loga yazılmaz.
 */
final class LucaFisExporter
{
    /** Resmi fiş şablonu: tam 11 kolon, değiştirilmez. */
    private const COLUMNS = [
        'Fiş No', 'Fiş Tarihi', 'Hesap Kodu', 'Evrak No', 'Evrak Tarihi',
        'Açıklama', 'Borç', 'Alacak', 'Miktar', 'Döviz', 'Kur',
    ];

    private const OPEN_STATUSES = ['issued', 'partially_paid', 'paid'];

    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Yapılandırılmış hesap eşlemesini döndürür.
     *
     * UYARI: Bu eşleme KULLANILMADAN ÖNCE KİRACI TARAFINDAN ayarlanmalıdır.
     * Kodda hiçbir muhasebe hesabı sabitlenmez (hardcode edilmez); boş eşleme
     * boş "Hesap Kodu" hücresi üretir ve sayı olarak raporlanır.
     *
     * Beklenen şekil (fiili kodlar tenant tarafından girilir):
     *   ['invoice' => ['debit' => '...', 'credit' => '...', 'tax' => '...']]
     * veya kök seviyede ['debit' => '...', 'credit' => '...', 'tax' => '...'].
     */
    public function accountMap(): array
    {
        $map = config('integrations.channels.luca.account_map', []);

        return is_array($map) ? $map : [];
    }

    /**
     * Verilen aralıktaki kesilmiş satış belgelerinden resmi fiş dosyası üretir.
     *
     * @return array{path:string,rows:int,documents:int,unmapped_rows:int}
     */
    public function export(int $companyId, string $from, string $to): array
    {
        $accountMap = $this->accountMapFor($companyId);

        $documents = Document::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereDate('issue_date', '>=', $from)
            ->whereDate('issue_date', '<=', $to)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get(['id', 'serial', 'external_no', 'issue_date', 'currency_code',
                'subtotal', 'discount_total', 'tax_total', 'grand_total']);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Luca fiş CSV akışı açılamadı.');
        }

        fputcsv($handle, self::COLUMNS, ';', '"');

        $rows = 0;
        $unmapped = 0;
        foreach ($documents as $document) {
            $date = $this->date($document->issue_date);
            $voucherNo = trim((string) $document->serial);
            $evidenceNo = trim((string) ($document->external_no ?: $document->serial));
            $description = 'Fatura '.($voucherNo !== '' ? $voucherNo : (string) $document->id);
            $currency = strtoupper(trim((string) $document->currency_code));

            // Açıklama/opak referans dışında hiçbir cari alanı kullanılmaz.
            $debitAccount = $this->accountCode($accountMap, 'invoice', 'debit');
            $creditAccount = $this->accountCode($accountMap, 'invoice', 'credit');
            $taxAccount = $this->accountCode($accountMap, 'invoice', 'tax');

            $tax = Money::dec($document->tax_total);
            $gross = Money::dec($document->grand_total);
            $net = Money::sub($gross, $tax);

            $hasTaxRow = $taxAccount !== '' && bccomp($tax, '0', 4) !== 0;
            $creditAmount = $hasTaxRow ? $net : $gross;

            $lines = [
                [$debitAccount, $gross, 'debit'],
                [$creditAccount, $creditAmount, 'credit'],
            ];
            if ($hasTaxRow) {
                $lines[] = [$taxAccount, $tax, 'credit'];
            }

            foreach ($lines as [$account, $amount, $side]) {
                $code = trim((string) $account);
                if ($code === '') {
                    $unmapped++;
                }

                fputcsv($handle, [
                    $voucherNo,
                    $date,
                    $code,
                    $evidenceNo,
                    $date,
                    $description,
                    $side === 'debit' ? $this->turkishAmount($amount) : '',
                    $side === 'credit' ? $this->turkishAmount($amount) : '',
                    '', // Miktar: tek birimli muhasebe fişinde boş bırakılır
                    $currency,
                    '',
                ], ';', '"');
                $rows++;
            }
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        // Resmi fiş içe aktarımı UTF-8 + BOM bekler. Kanal config'inde yer alan
        // csv_encoding (Windows-1254) fatura/iade aktarımında kullanılır; bu
        // özel fiş dosyası için BOM'lu UTF-8 sabittir.
        $content = self::UTF8_BOM.$content;

        $safeFrom = preg_replace('/[^0-9-]/', '', $from) ?: 'from';
        $safeTo = preg_replace('/[^0-9-]/', '', $to) ?: 'to';
        $path = 'integrations/luca/out/luca-fis-'.$safeFrom.'-'.$safeTo.'.csv';

        Storage::disk('local')->put($path, $content);

        return [
            'path' => $path,
            'rows' => $rows,
            'documents' => count($documents),
            'unmapped_rows' => $unmapped,
        ];
    }

    /**
     * Tenant eşlemesi "settings" tablosunda (luca_account_map) varsa config'i
     * override eder; yoksa yalnızca config kullanılır. Ayrıca tenant_integrations
     * kaydındaki JSON settings['account_map'] desteklenir.
     */
    private function accountMapFor(int $companyId): array
    {
        $map = $this->accountMap();

        try {
            if (class_exists(Company::class)) {
                $company = Company::find($companyId);
                $override = $company?->setting('luca_account_map');
                if (is_string($override)) {
                    $decoded = json_decode($override, true);
                    $override = is_array($decoded) ? $decoded : null;
                }
                if (is_array($override) && $override !== []) {
                    $map = array_replace_recursive($map, $override);
                }
            }
        } catch (Throwable) {
            // Ayar tablosu yoksa config-only çalışır.
        }

        try {
            if (class_exists(TenantIntegration::class)) {
                $integration = TenantIntegration::query()
                    ->where('company_id', $companyId)
                    ->where('channel', 'luca')
                    ->first();
                $settings = $integration?->settings;
                $override = is_array($settings) ? ($settings['account_map'] ?? null) : null;
                if (is_array($override) && $override !== []) {
                    $map = array_replace_recursive($map, $override);
                }
            }
        } catch (Throwable) {
            // Entegrasyon kaydı yoksa config-only çalışır.
        }

        return $map;
    }

    /** @param array<string,mixed> $map */
    private function accountCode(array $map, string $documentType, string $role): string
    {
        foreach ([$documentType, 'default'] as $group) {
            $entry = $map[$group] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $code = trim((string) ($entry[$role] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }

        $flat = $map[$role] ?? null;

        return is_scalar($flat) ? trim((string) $flat) : '';
    }

    private function date(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) === 1
            ? date('d.m.Y', strtotime(substr($raw, 0, 10)))
            : date('d.m.Y');
    }

    /** Float'a çevirmeden 2 ondalıklı Türkçe tutar üretir. */
    private function turkishAmount(string $value): string
    {
        $value = Money::dec($value);
        if (function_exists('bcadd')) {
            $value = bcadd($value, '0', 2);
        }

        $parts = array_pad(explode('.', $value, 2), 2, '');
        $normalized = $parts[0].'.'.str_pad(substr($parts[1], 0, 2), 2, '0');

        return number_format((float) $normalized, 2, ',', '.');
    }
}
