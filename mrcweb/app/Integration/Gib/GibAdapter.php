<?php

namespace App\Integration\Gib;

use App\Integration\Canonical\CanonicalGibFile;
use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\SyncResult;
use App\Integration\Contracts\ChannelAdapterInterface;
use App\Integration\Contracts\InboundFileSourceInterface;
use App\Integration\Contracts\VoucherSinkInterface;
use App\Integration\Enums\Channel;
use App\Integration\Support\CredentialVault;
use App\Models\Company;
use App\Models\GibDocument;
use App\Models\GibInboundFile;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GIB channel adapter.
 *
 * Outbound: generates a standards-compliant UBL-TR XML file and hands it to a
 * licensed integrator. MRC never signs or submits to GIB itself.
 * Inbound: reads integrator feedback files dropped into local storage.
 */
final class GibAdapter implements ChannelAdapterInterface, InboundFileSourceInterface, VoucherSinkInterface
{
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function __construct(
        private readonly Company|int $company,
        private readonly CredentialVault $vault,
    ) {}

    public function channel(): Channel
    {
        return Channel::Gib;
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['push_voucher', 'fetch_inbound_files'], true);
    }

    public function pushVoucher(CanonicalVoucher $voucher): SyncResult
    {
        if (! $voucher->isBalanced()) {
            return SyncResult::failure('Voucher is not balanced.');
        }

        $ettn = trim((string) ($voucher->ettn ?? ''));
        if ($ettn === '') {
            return SyncResult::failure('ETTN is required for a GIB voucher.');
        }

        try {
            $xml = (new UblBuilder)->build($voucher);
            $safeEttn = preg_replace('/[^A-Za-z0-9_-]/', '', $ettn) ?: 'ettn';
            $directory = 'integrations/gib/out/'.$this->companyId();
            $path = $directory.'/'.$safeEttn.'.xml';

            Storage::disk('local')->put($path, $xml);

            GibDocument::updateOrCreate(
                ['company_id' => $this->companyId(), 'ettN' => $ettn],
                [
                    'document_type' => $this->documentType($voucher),
                    'direction' => 'out',
                    'status' => 'official_pending',
                    'file_path' => $path,
                    'xml' => $xml,
                    'official_at' => null,
                    'response' => ['generated_at' => now()->toIso8601String()],
                ],
            );

            return SyncResult::success($ettn);
        } catch (\Throwable $e) {
            Log::error('GIB voucher file generation failed', [
                'company_id' => $this->companyId(),
                'ettn' => $ettn,
                'error' => $e->getMessage(),
            ]);

            return SyncResult::failure('GIB voucher file generation failed: '.$e->getMessage());
        }
    }

    /** @return array<int, CanonicalGibFile> */
    public function fetchInboundFiles(?CarbonInterface $since = null): array
    {
        $directory = 'integrations/gib/in/'.$this->companyId();
        $disk = Storage::disk('local');
        $files = $disk->exists($directory) ? $disk->files($directory) : [];
        $result = [];

        foreach ($files as $path) {
            try {
                $modified = $disk->lastModified($path);
                if ($since !== null && $modified < $since->getTimestamp()) {
                    continue;
                }

                $raw = $disk->get($path);
                if (! is_string($raw) || trim($raw) === '') {
                    Log::warning('Skipping empty GIB inbound file', ['path' => $path]);

                    continue;
                }

                [$ettn, $type] = $this->parseXml($raw, $path);
                if ($ettn === null) {
                    Log::warning('Skipping GIB inbound file without ETTN', ['path' => $path]);

                    continue;
                }

                $receivedAt = Carbon::createFromTimestamp($modified);
                $outbound = GibDocument::where('company_id', $this->companyId())
                    ->where('ettN', $ettn)
                    ->first();
                $status = $outbound?->status ?? 'pending';

                GibInboundFile::updateOrCreate(
                    ['company_id' => $this->companyId(), 'file_path' => $path],
                    [
                        'ettN' => $ettn,
                        'document_type' => $type,
                        'received_at' => $receivedAt,
                        'matched_document_id' => $outbound?->document_id,
                        'status' => $status,
                    ],
                );

                $result[] = new CanonicalGibFile(
                    $ettn,
                    $type,
                    'in',
                    $path,
                    $raw,
                    $receivedAt,
                    $status,
                    $outbound?->integrator_ref,
                );
            } catch (\Throwable $e) {
                // A single malformed file must never break the whole fetch.
                Log::warning('Skipping malformed GIB inbound file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Defensive ETTN/document-type extraction. SimpleXML is used when present;
     * a regex fallback and the file name are used only when the XML gives us
     * nothing usable.
     *
     * @return array{0: ?string, 1: string}
     */
    private function parseXml(string $raw, string $path): array
    {
        $ettn = null;
        $type = 'invoice';

        if (function_exists('simplexml_load_string')) {
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = @simplexml_load_string($raw);
                if ($xml !== false) {
                    $xml->registerXPathNamespace('cbc', self::CBC_NS);
                    $uuids = $xml->xpath('//cbc:UUID');
                    if (is_array($uuids) && isset($uuids[0])) {
                        $candidate = trim((string) $uuids[0]);
                        $ettn = $candidate !== '' ? $candidate : null;
                    }

                    $root = strtolower($xml->getName());
                    if (str_contains($root, 'creditnote')) {
                        $type = 'creditnote';
                    }

                    $codes = $xml->xpath('//cbc:InvoiceTypeCode');
                    if (is_array($codes) && isset($codes[0]) && trim((string) $codes[0]) !== '') {
                        $type = trim((string) $codes[0]);
                    }
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        if ($ettn === null && preg_match('/<(?:[\w-]+:)?UUID>\s*([^<]+)\s*<\//i', $raw, $match) === 1) {
            $ettn = trim($match[1]);
        }

        if ($ettn === null) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1) {
                $ettn = $name;
            }
        }

        return [$ettn, $type];
    }

    private function documentType(CanonicalVoucher $voucher): string
    {
        foreach ($voucher->lines as $line) {
            $type = trim((string) ($line->documentType ?? ''));
            if ($type !== '') {
                return mb_substr($type, 0, 40);
            }
        }

        return 'invoice';
    }

    private function companyId(): int
    {
        return $this->company instanceof Company ? (int) $this->company->id : (int) $this->company;
    }
}
