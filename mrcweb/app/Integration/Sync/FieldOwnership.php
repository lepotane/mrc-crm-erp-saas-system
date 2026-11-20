<?php

namespace App\Integration\Sync;

use App\Integration\Enums\FieldSource;
use App\Models\IntegrationFieldOwner;
use InvalidArgumentException;

/**
 * Sunum alanlarının sahibini (mrc | tsoft) firma bazında çözer.
 * Kimlik alanları (sku/barkod) bu bayrağa bakılmaksızın MRC kaydından gelir.
 */
final class FieldOwnership
{
    public function get(int $companyId, string $entity, string $field, string $default = 'mrc'): string
    {
        $source = IntegrationFieldOwner::query()
            ->where('company_id', $companyId)
            ->where('entity', $entity)
            ->where('field', $field)
            ->value('source');

        return $source !== null && $source !== '' ? (string) $source : $default;
    }

    public function set(int $companyId, string $entity, string $field, string $source): void
    {
        $normalized = FieldSource::tryFrom(strtolower(trim($source)));

        if ($normalized === null) {
            throw new InvalidArgumentException('Geçersiz alan kaynağı: '.$source);
        }

        IntegrationFieldOwner::updateOrCreate(
            ['company_id' => $companyId, 'entity' => $entity, 'field' => $field],
            ['source' => $normalized->value],
        );
    }

    public function allowsWriteFrom(int $companyId, string $entity, string $field, string $source): bool
    {
        return $this->get($companyId, $entity, $field) === strtolower(trim($source));
    }

    /**
     * sku/barkod her zaman MRC kimlik kaydından alınır; name/description/image
     * ise alan sahipliğine göre MRC ya da Tsoft tarafından seçilir.
     *
     * @param  array<string,mixed>  $mrcData
     * @param  array<string,mixed>  $externalData
     * @return array<string,mixed>
     */
    public function resolveProduct(int $companyId, array $mrcData, array $externalData): array
    {
        $nameSource = $this->get($companyId, 'product', 'name', 'mrc');
        $imageSource = $this->get($companyId, 'product', 'image', 'mrc');
        $nameIsExternal = $nameSource === FieldSource::Tsoft->value;
        $imageIsExternal = $imageSource === FieldSource::Tsoft->value;

        return [
            'sku' => $mrcData['sku'] ?? $externalData['sku'] ?? null,
            'barcode' => $mrcData['barcode'] ?? $externalData['barcode'] ?? null,
            'name' => $nameIsExternal
                ? ($externalData['name'] ?? $mrcData['name'] ?? null)
                : ($mrcData['name'] ?? $externalData['name'] ?? null),
            // Açıklama, ad ile aynı sahip bayrağını kullanır (karar K3).
            'description' => $nameIsExternal
                ? ($externalData['description'] ?? $mrcData['description'] ?? null)
                : ($mrcData['description'] ?? $externalData['description'] ?? null),
            'image_url' => $imageIsExternal
                ? ($externalData['image_url'] ?? $externalData['image'] ?? $mrcData['image_url'] ?? $mrcData['image'] ?? null)
                : ($mrcData['image_url'] ?? $mrcData['image'] ?? $externalData['image_url'] ?? $externalData['image'] ?? null),
            'name_source' => $nameSource,
            'image_source' => $imageSource,
        ];
    }
}
