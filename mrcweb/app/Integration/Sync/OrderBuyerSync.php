<?php

namespace App\Integration\Sync;

use App\Models\Party;
use Throwable;

/**
 * rest1 sipariş gövdesinden toplanan misafir alıcılarını MRC cari kaydına çevirir.
 *
 * MrcResyncService::importOrderBuyers() ile aynı güvenli upsert sözleşmesi:
 *  - party yalnızca (company_id, code) ile bulunur; code en fazla 12 karakterdir.
 *  - Mevcut cari GERÇEK bir ada sahipse kişisel veri üzerine yazılmaz; yalnızca
 *    ad boşsa veya placeholder ise ('Tsoft Müşteri' / 'TSOFT-ANON') güncellenir.
 *  - Hem opak code hem sayısal id IntegrationIdMap'e kaydedilir; '0' atlanır.
 *  - Satır bazlı hatalar genel mesajlarla errors() dizisine döner; kişisel veri
 *    asla hata mesajına, istisnaya veya günlüğe kopyalanmaz.
 */
final class OrderBuyerSync
{
    /** @var array<int,string> */
    private array $errors = [];

    /**
     * @param  array<int,array<string,mixed>>  $buyers
     */
    public function sync(int $companyId, array $buyers): int
    {
        $currency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        $imported = 0;
        $seen = [];

        foreach (array_values($buyers) as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }

            try {
                $name = trim((string) ($raw['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $code = trim((string) ($raw['code'] ?? ''));
                $externalId = trim((string) ($raw['id'] ?? ''));
                $dedupeKey = $code.'|'.$externalId;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                // parties.code varchar(12): uzun misafir kodları kısaltılır.
                $partyCode = $code !== '' && mb_strlen($code) <= 12
                    ? $code
                    : 'T'.mb_substr(md5($dedupeKey), 0, 11);

                $name = mb_substr($name, 0, 190);
                $taxNumber = mb_substr(trim((string) ($raw['tax'] ?? '')), 0, 20);
                $email = mb_substr(trim((string) ($raw['email'] ?? '')), 0, 255);
                $phone = mb_substr(trim((string) ($raw['phone'] ?? '')), 0, 30);
                $city = mb_substr(trim((string) ($raw['city'] ?? '')), 0, 60);
                $address = mb_substr(trim((string) ($raw['address'] ?? '')), 0, 255);

                $contactAttributes = [
                    'name' => $name,
                    'tax_number' => $taxNumber !== '' ? $taxNumber : null,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'city' => $city !== '' ? $city : null,
                    'address' => $address !== '' ? $address : null,
                ];

                $party = Party::query()
                    ->where('company_id', $companyId)
                    ->where('code', $partyCode)
                    ->first();

                if ($party instanceof Party) {
                    // Gerçek adı olan cari korunur; placeholder/boşsa kişisel veri yazılır.
                    $currentName = trim((string) $party->name);
                    if ($currentName === '' || in_array($currentName, ['Tsoft Müşteri', 'TSOFT-ANON'], true)) {
                        $party->fill($contactAttributes)->save();
                    } else {
                        // Üye cari listesinde adres/telefon boş olabilir; sipariş
                        // adresinden yalnızca eksik alanları tamamla, mevcut veriyi ezme.
                        $eksik = [];
                        foreach (['tax_number', 'email', 'phone', 'city', 'address'] as $field) {
                            if (trim((string) ($party->{$field} ?? '')) === '' && ($contactAttributes[$field] ?? null) !== null) {
                                $eksik[$field] = $contactAttributes[$field];
                            }
                        }
                        if ($eksik !== []) {
                            $party->fill($eksik)->save();
                        }
                    }
                } else {
                    $attributes = $contactAttributes + [
                        'type' => 'customer',
                        'code' => $partyCode,
                        'currency_code' => $currency,
                        'notes' => 'Tsoft sipariş müşterisi',
                        'is_active' => true,
                    ];
                    $party = Party::updateOrCreate(
                        ['company_id' => $companyId, 'code' => $partyCode],
                        $attributes,
                    );
                }

                // Hem opak kodu hem sayısal müşteri kimliği eşlenir (importCustomers ile aynı).
                $keys = array_filter(
                    [$code, $externalId],
                    static fn (string $key): bool => $key !== '' && $key !== '0',
                );
                foreach (array_unique($keys) as $externalKey) {
                    IntegrationIdMap::updateOrCreate(
                        [
                            'company_id' => $companyId,
                            'mrc_type' => 'party',
                            'mrc_id' => (int) $party->id,
                            'external_type' => 'customer',
                            'external_key' => mb_substr($externalKey, 0, 190),
                        ],
                        [],
                    );
                }

                $imported++;
            } catch (Throwable) {
                // Ham veri/kişisel bilgi hata mesajına veya günlüğe yazılmaz.
                $this->errors[] = 'order_buyers['.$index.']: cari yazilamadi.';
            }
        }

        return $imported;
    }

    /** @return array<int,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
