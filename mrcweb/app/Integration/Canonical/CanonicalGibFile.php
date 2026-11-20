<?php

namespace App\Integration\Canonical;

use Carbon\CarbonInterface;

/**
 * GİB dosyası — giden (resmileştir) ve gelen (fetch) akışların ortak modeli.
 */
final class CanonicalGibFile
{
    public function __construct(
        public readonly string $ettn,
        public readonly string $documentType,
        public readonly string $direction,
        public readonly ?string $filePath,
        public readonly ?string $xml,
        public readonly ?CarbonInterface $receivedAt,
        public readonly ?string $status = null,
        public readonly ?string $externalRef = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ettn' => $this->ettn,
            'document_type' => $this->documentType,
            'direction' => $this->direction,
            'file_path' => $this->filePath,
            'received_at' => $this->receivedAt?->toIso8601String(),
            'status' => $this->status,
            'external_ref' => $this->externalRef,
        ];
    }
}
