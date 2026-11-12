<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Tests\TestCase;

/**
 * track-transferred-consignment-provenance 1.5: the provenance upgrade is additive. A finalized local buyout (Purchase,
 * lineage, cost event), sold sources with hashes, serials, stock and payments are byte-identical across a
 * rollback/upgrade cycle; populated provenance refuses to roll back.
 */
class ConsignmentProvenanceUpgradeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    private const MIGRATIONS = [
        '2026_11_11_000001_create_consignment_transfer_provenance_tables',
        '2026_11_11_000002_add_inventory_owner_to_consignment_buyout_evidence',
    ];

    private function migration(string $name)
    {
        return require base_path("Modules/Consignment/Database/Migrations/{$name}.php");
    }

    private function fingerprint(): array
    {
        $out = [];
        foreach (['consignment_receiving_details', 'consignment_sold_sources', 'consignment_billing_confirmations', 'consignment_receipt_allocations',
            'purchases', 'purchase_details', 'purchase_payments', 'product_serial_numbers', 'product_stocks', 'products', 'product_prices',
            'consignment_buyouts', 'consignment_buyout_purchase_lineages', 'consignment_buyout_movements', 'consignment_buyout_ownership_events'] as $t) {
            if (Schema::hasTable($t)) {
                $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            }
        }
        // cost events: compare the pre-existing columns only (the unique key changes, the data must not).
        $out['cost_events'] = DB::table('consignment_buyout_cost_events')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();

        return $out;
    }

    private function stripOwner(array $fp): array
    {
        foreach ($fp as $t => $rows) {
            $fp[$t] = array_map(function ($r) { unset($r['inventory_owner_setting_id']); return $r; }, $rows);
        }

        return $fp;
    }

    /** @test */
    public function upgrade_leaves_legacy_and_buyout_evidence_byte_identical(): void
    {
        $this->setUpFinalizationFixtures();
        [$b] = $this->mixed();
        $this->fin->finalize($b, $this->user);
        $this->assertSame(1, DB::table('consignment_buyout_cost_events')->count());

        foreach (array_reverse(self::MIGRATIONS) as $m) {
            $this->migration($m)->down();
        }
        $this->assertFalse(Schema::hasTable('consignment_provenance_events'));
        $this->assertFalse(Schema::hasColumn('consignment_buyout_lines', 'inventory_owner_setting_id'));
        $before = $this->fingerprint();

        foreach (self::MIGRATIONS as $m) {
            $this->migration($m)->up();
        }

        $this->assertEquals($before, $this->stripOwner($this->fingerprint()));
        $this->assertSame(0, DB::table('consignment_buyout_lines')->whereNotNull('inventory_owner_setting_id')->count(), 'no owner backfill');
        foreach (['consignment_transfer_lot_selections', 'consignment_provenance_events', 'consignment_obligation_bindings', 'consignment_historical_mappings'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), "{$t} is not seeded by the migration");
        }
    }

    /** @test */
    public function populated_provenance_refuses_rollback(): void
    {
        $this->setUpBuyoutFixtures();
        $r = $this->receipt($this->supplier, '5');
        ConsignmentProvenanceEvent::create([
            'consignment_receiving_detail_id' => $r->id, 'original_setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id,
            'product_id' => $this->product->id, 'kind' => 'RECEIPT', 'source_type' => 'test', 'source_id' => 1, 'source_event_key' => 'k',
            'from_state' => 'EXTERNAL', 'to_state' => 'SELLABLE', 'to_location_id' => $this->location->id, 'to_setting_id' => $this->setting->id,
            'base_quantity' => 5, 'effective_at' => now(),
        ]);

        $this->migration(self::MIGRATIONS[1])->down(); // no remote-owner evidence: allowed
        try {
            $this->migration(self::MIGRATIONS[0])->down();
            $this->fail('rollback should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
        }
        $this->assertSame(1, DB::table('consignment_provenance_events')->count());
    }
}
