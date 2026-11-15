<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Tenant çözümü: kimlikli her controller aktif firmayla çalışır.
 *
 * Ayar okuma burada toplanır : `Ayarlar → Genel Ayarlar`
 * değerleri belge/cari/ürün üretiminde kullanılan TEK kaynak olsun diye. Aksi
 * hâlde aynı ayar hem SPA'da hem sunucuda ayrı ayrı "varsayılan" olarak
 * yazılıp bir süre sonra birbirinden sapıyor (bulunan gerçek kusur buydu:
 * SPA boş satırı sabit %20 KDV ile açıyordu, ayar ise hiç okunmuyordu).
 * Sunucu authoritative; SPA yalnız öneri olarak aynı değeri gösterir.
 */
trait ResolvesTenant
{
    protected ?Company $tenantCompanyCache = null;

    protected function tenantId(Request $request): int
    {
        return (int) ($request->user()?->current_company_id ?? 0);
    }

    /**
     * Aktif firma modeli. `CompanyContext` üyelik doğrulamasını yaptığı için
     * burada kayıt varsayılır; bulunamazsa 404 (kiracı bağlamı kopmuş).
     */
    protected function tenantCompany(Request $request): Company
    {
        $id = $this->tenantId($request);

        if ($this->tenantCompanyCache?->id !== $id) {
            $this->tenantCompanyCache = $request->user()?->currentCompany
                ?? Company::find($id)
                ?? new Company(['id' => $id]);
        }

        return $this->tenantCompanyCache;
    }

    /** Firmanın ana para birimi (`companies.currency_code` tek doğruluk kaynağı). */
    protected function companyCurrency(Request $request): string
    {
        return (string) ($this->tenantCompany($request)->currency_code ?: 'TRY');
    }

    /** Varsayılan KDV oranı (%). Ayar yoksa 20 — mevzuatın en sık oranı. */
    protected function defaultTaxRate(Request $request): float
    {
        $value = $this->tenantCompany($request)->setting('default_tax_rate');

        return $value === null ? 20.0 : (float) $value;
    }

    /**
     * Varsayılan vade (gün). Ayar satırı yoksa `SettingsController`'ın sunduğu
     * gibi 7 gün; kullanıcı 0 seçerse "vade önerme" demektir (alan boş kalır).
     */
    protected function defaultPaymentDays(Request $request): int
    {
        $value = $this->tenantCompany($request)->setting('default_payment_days');

        return max(0, (int) ($value ?? 7));
    }

    /**
     * Kesim tarihine firma vadesini ekler; para hareketi doğuran belgelerde
     * (fatura / alım faturası) Vade boşsa kullanılır.
     */
    protected function dueDateFromTerm(string $issueDate, int $days): ?string
    {
        if ($days === 0) {
            return null;
        }

        return date('Y-m-d', strtotime($issueDate.' +'.$days.' days'));
    }
}
