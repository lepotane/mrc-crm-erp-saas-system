<?php

namespace Tests\Unit\Integration\Sync;

use App\Integration\Sync\PriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceSyncDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_direction_returns_failure_summary(): void
    {
        $summary = (new PriceSyncService)->sync(1, 'sideways', ['SKU-1']);

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['pushed']);
        $this->assertGreaterThan(0, $summary['failed']);
        $this->assertNotEmpty($summary['details']);
    }

    public function test_empty_selection_without_all_returns_failure_summary(): void
    {
        $summary = (new PriceSyncService)->sync(1, 'out', []);

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['pushed']);
        $this->assertGreaterThan(0, $summary['failed']);
        $this->assertNotEmpty($summary['details']);
    }
}
