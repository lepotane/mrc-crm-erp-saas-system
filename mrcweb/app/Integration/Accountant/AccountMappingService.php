<?php

namespace App\Integration\Accountant;

use App\Models\AccountMapping;
use App\Models\LucaAccountPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Hesap planı içe aktarma ve belge tipi -> hesap kodu eşlemeleri.
 *
 * Tasarım kararı: hiçbir hesap kodu koda gömülmez. Eşleme eksikse
 * {@see resolve()} açık hata verir; `suggestFromPlan()` yalnızca öneri üretir
 * ve hiçbir zaman otomatik uygulanmaz.
 */
final class AccountMappingService
{
    private const CODE_LENGTH = 40;

    private const NAME_LENGTH = 190;

    private const PARENT_CODE_LENGTH = 40;

    private const SOURCE_LENGTH = 20;

    private const DOCUMENT_TYPE_LENGTH = 50;

    private const ROLE_LENGTH = 30;

    private const EXTERNAL_REF_LENGTH = 190;

    /** @return list<string> */
    public static function roles(): array
    {
        return [
            AccountMapping::ROLE_DEBIT,
            AccountMapping::ROLE_CREDIT,
            AccountMapping::ROLE_TAX,
            AccountMapping::ROLE_COUNTER,
        ];
    }

    /**
     * Hesap planı satırlarını (company_id, code) anahtarıyla upsert eder.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{total:int,imported:int,skipped:int,errors:array<int,array<string,mixed>>}
     */
    public function importPlan(int $companyId, array $rows, string $source = 'import'): array
    {
        $summary = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'errors' => []];
        $source = $this->truncate($source, self::SOURCE_LENGTH);
        if ($source === '') {
            $source = LucaAccountPlan::SOURCE_IMPORT;
        }

        foreach ($rows as $index => $row) {
            try {
                if (! is_array($row)) {
                    $summary['skipped']++;
                    $summary['errors'][] = ['row' => $index, 'message' => 'Satır dizi değil.'];

                    continue;
                }

                $code = $this->truncate((string) ($row['code'] ?? ''), self::CODE_LENGTH);
                if ($code === '') {
                    $summary['skipped']++;

                    continue;
                }

                $name = $this->truncate((string) ($row['name'] ?? ''), self::NAME_LENGTH);
                $parentCode = $row['parent_code'] ?? null;
                $parentCode = $parentCode === null || $parentCode === ''
                    ? null
                    : $this->truncate((string) $parentCode, self::PARENT_CODE_LENGTH);
                $externalRef = $row['external_ref'] ?? null;
                $externalRef = $externalRef === null || $externalRef === ''
                    ? null
                    : $this->truncate((string) $externalRef, self::EXTERNAL_REF_LENGTH);

                LucaAccountPlan::query()->updateOrCreate(
                    ['company_id' => $companyId, 'code' => $code],
                    [
                        'name' => $name,
                        'parent_code' => $parentCode,
                        'is_active' => $this->toBool($row['is_active'] ?? null),
                        'source' => $source,
                        'external_ref' => $externalRef,
                        'synced_at' => Carbon::now(),
                    ]
                );

                $summary['imported']++;
            } catch (\Throwable $e) {
                $summary['errors'][] = [
                    'row' => $index,
                    'message' => get_class($e).': '.$e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * Belge tipi için zorunlu rolleri döndürür. Eksik rol varsa sessiz varsayılan
     * kullanılmaz; açık istisna fırlatılır.
     *
     * @return array{debit:string,credit:string,tax:string}
     */
    public function resolve(int $companyId, string $documentType): array
    {
        $documentType = $this->truncate(trim($documentType), self::DOCUMENT_TYPE_LENGTH);

        $rows = AccountMapping::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->get(['role', 'account_code'])
            ->keyBy('role');

        $resolved = [];
        foreach ([AccountMapping::ROLE_DEBIT, AccountMapping::ROLE_CREDIT, AccountMapping::ROLE_TAX] as $role) {
            $code = $rows->get($role)?->account_code;
            if ($code === null || trim((string) $code) === '') {
                throw new RuntimeException(
                    'Account mapping is missing the "'.$role.'" account for document type "'
                    .$documentType.'" (company '.$companyId.').'
                );
            }

            $resolved[$role] = trim((string) $code);
        }

        return $resolved;
    }

    /**
     * Rol doğrulanır, kod hesap planında aranır ve eşleme upsert edilir.
     *
     * Plan içe aktarılmadıysa manuel kod girişine izin verilir (log uyarısı ile);
     * plan mevcutsa plan dışı kod reddedilir.
     */
    public function setMapping(
        int $companyId,
        string $documentType,
        string $role,
        string $accountCode,
        ?string $notes = null
    ): AccountMapping {
        if (! in_array($role, self::roles(), true)) {
            throw new InvalidArgumentException(
                'Invalid account mapping role "'.$role.'". Allowed roles: '.implode(', ', self::roles()).'.'
            );
        }

        $documentType = $this->truncate(trim($documentType), self::DOCUMENT_TYPE_LENGTH);
        $accountCode = $this->truncate(trim($accountCode), self::CODE_LENGTH);

        if ($documentType === '') {
            throw new InvalidArgumentException('Document type cannot be empty.');
        }
        if ($accountCode === '') {
            throw new InvalidArgumentException('Account code cannot be empty.');
        }

        $planExists = LucaAccountPlan::query()
            ->where('company_id', $companyId)
            ->where('code', $accountCode)
            ->exists();
        $planImported = LucaAccountPlan::upToDate($companyId)
            || LucaAccountPlan::query()->where('company_id', $companyId)->exists();

        if (! $planExists) {
            if ($planImported) {
                throw new InvalidArgumentException(
                    'Account code "'.$accountCode.'" does not exist in the imported plan for company '.$companyId.'.'
                );
            }

            Log::warning('account_mapping.manual_without_plan', [
                'company_id' => $companyId,
                'document_type' => $documentType,
                'role' => $role,
                'account_code' => $accountCode,
            ]);
        }

        return AccountMapping::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'document_type' => $documentType,
                'role' => $role,
            ],
            [
                'account_code' => $accountCode,
                'source' => AccountMapping::SOURCE_MANUAL,
                'notes' => $notes,
            ]
        );
    }

    /**
     * Plan satırı adlarındaki Türkçe anahtar kelimelerden rol bazlı öneriler üretir.
     * Eşleşme yoksa boş dizi döner; asla sabit kod üretilmez veya uygulanmaz.
     *
     * @return array<string,string>
     */
    public function suggestFromPlan(int $companyId, string $documentType): array
    {
        $documentType = $this->truncate(trim($documentType), self::DOCUMENT_TYPE_LENGTH);
        $isSale = ! in_array($documentType, ['purchase', 'purchase_order', 'bill'], true);

        // Anahtar kelimeler yalnızca öneri içindir; hiçbir kod sabitlenmez.
        // Türkçe karakterler normalize edilir (müşteri/musteri, satış/satis ...).
        $keywords = [
            AccountMapping::ROLE_DEBIT => $isSale ? ['musteri'] : ['mal', 'satici'],
            AccountMapping::ROLE_CREDIT => $isSale ? ['satis'] : ['satici', 'satis'],
            AccountMapping::ROLE_TAX => ['kdv'],
        ];

        $plan = LucaAccountPlan::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name']);

        $suggestions = [];
        foreach ($keywords as $role => $needles) {
            foreach ($plan as $row) {
                $name = $this->normalize((string) ($row->name ?? ''));
                if ($name === '') {
                    continue;
                }

                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $suggestions[$role] = (string) $row->code;

                        continue 3;
                    }
                }
            }
        }

        return $suggestions;
    }

    /**
     * Mevcut eşlemelerden DocumentVoucherBuilder'ın okuduğu account_map
     * şeklini üretir. Böylece builder değiştirilmeden senkron veri tüketilebilir.
     *
     * @return array<string,array<string,string|null>>
     */
    public function toTenantSettings(int $companyId): array
    {
        $rows = AccountMapping::query()
            ->where('company_id', $companyId)
            ->orderBy('document_type')
            ->get(['document_type', 'role', 'account_code']);

        $settings = [];
        foreach ($rows as $row) {
            $documentType = trim((string) ($row->document_type ?? ''));
            if ($documentType === '' || ! in_array($row->role, self::roles(), true)) {
                continue;
            }

            $settings[$documentType][$row->role] = (string) $row->account_code;
        }

        // Builder'ın beklediği üç anahtar her zaman ve tutarlı sırada bulunsun;
        // tax null ise builder vergi satırını atlar (uydurma kod üretilmez).
        foreach ($settings as $documentType => $roles) {
            $ordered = [];
            foreach (self::roles() as $role) {
                if (array_key_exists($role, $roles)) {
                    $ordered[$role] = $roles[$role];
                } elseif (in_array($role, [
                    AccountMapping::ROLE_DEBIT, AccountMapping::ROLE_CREDIT, AccountMapping::ROLE_TAX,
                ], true)) {
                    $ordered[$role] = null;
                }
            }
            $settings[$documentType] = $ordered;
        }

        return $settings;
    }

    private function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    private function normalize(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'ğ' => 'g',
            'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        return in_array($this->normalize(trim((string) $value)), ['1', 'true', 'evet', 'yes', 'y', 'aktif'], true);
    }
}
