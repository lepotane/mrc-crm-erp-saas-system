<?php

namespace Tests\Unit\Integration\Sync;

use App\Models\SyncDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDiffModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_filter_by_pending_type_and_status(): void
    {
        $first = $this->makeDiff('SKU-A', SyncDiff::TYPE_PRODUCT);
        $second = $this->makeDiff('SKU-B', SyncDiff::TYPE_PRICE);
        $third = $this->makeDiff('SKU-C', SyncDiff::TYPE_ORDER);
        $third->markIgnored('Kullanıcı yok saydı.');

        $this->assertSame(2, SyncDiff::pending()->count());
        $this->assertSame(1, SyncDiff::ofType(SyncDiff::TYPE_PRICE)->count());
        $this->assertSame(1, SyncDiff::status(SyncDiff::STATUS_IGNORED)->count());
        $this->assertSame(1, SyncDiff::ofType(SyncDiff::TYPE_ORDER)->status(SyncDiff::STATUS_IGNORED)->count());
        $this->assertSame(0, SyncDiff::ofType(SyncDiff::TYPE_STOCK)->count());

        $this->assertTrue($first->exists && $second->exists && $third->exists);
    }

    public function test_mark_imported_and_ignored_set_status_resolved_at_and_summary(): void
    {
        $diff = $this->makeDiff('SKU-D', SyncDiff::TYPE_PRODUCT);

        $diff->markImported('Ürün alındı.');
        $fresh = $diff->fresh();

        $this->assertSame(SyncDiff::STATUS_IMPORTED, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame('Ürün alındı.', $fresh->summary);
        $this->assertSame(0, SyncDiff::pending()->count());

        $other = $this->makeDiff('SKU-E', SyncDiff::TYPE_PRICE);
        $other->markIgnored('Sahiplik MRC.');
        $this->assertSame(SyncDiff::STATUS_IGNORED, $other->fresh()->status);
        $this->assertSame('Sahiplik MRC.', $other->fresh()->summary);
        $this->assertNotNull($other->fresh()->resolved_at);
    }

    public function test_json_snapshots_are_cast_to_arrays(): void
    {
        $diff = $this->makeDiff('SKU-F', SyncDiff::TYPE_PRICE, [
            'currency' => 'TRY',
            'sale_price' => '12.3400',
        ]);

        $this->assertIsArray($diff->fresh()->external_snapshot);
        $this->assertSame('12.3400', $diff->fresh()->external_snapshot['sale_price']);
    }

    private function makeDiff(string $key, string $type, ?array $external = null): SyncDiff
    {
        return SyncDiff::create([
            'company_id' => 1,
            'entity_type' => $type,
            'external_key' => $key,
            'diff_type' => $type === SyncDiff::TYPE_PRODUCT
                ? SyncDiff::DIFF_NEW_REMOTE
                : SyncDiff::DIFF_CHANGED_REMOTE,
            'mrc_snapshot' => null,
            'external_snapshot' => $external,
            'summary' => 'Test farkı.',
            'status' => SyncDiff::STATUS_PENDING,
            'detected_at' => now(),
        ]);
    }
}
