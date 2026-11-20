<?php

namespace App\Integration\Canonical;

use App\Integration\Enums\FieldSource;

/**
 * Ürün. Sunum alanları alan-sahipliğine göre ayrışabilir (karar K3).
 */
final class CanonicalProduct
{
    public function __construct(
        public readonly string $sku,
        public readonly ?string $barcode,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        public readonly FieldSource $nameSource,
        public readonly FieldSource $imageSource,
        public readonly ?string $categoryRef,
        public readonly ?string $externalRef = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'name_source' => $this->nameSource->value,
            'image_source' => $this->imageSource->value,
            'category_ref' => $this->categoryRef,
            'external_ref' => $this->externalRef,
        ];
    }
}
