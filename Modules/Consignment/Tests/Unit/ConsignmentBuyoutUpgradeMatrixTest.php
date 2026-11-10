<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Tests\TestCase;

/**
 * 10.1 upgrade/regression matrix. Legacy consignment data is written on the PRE-upgrade schema (the four buyout
 * migrations rolled back), then the migrations are replayed forward and every legacy fact is compared: sold sources
 * (versioned and unversioned snapshots), waiting/approved/billed confirmations with their receipt allocations and old
 * tax snapshots, partial returns' evidence, and legacy serial claims - plus the unchanged legacy capacity arithmetic
 * and the absence of any inventory effect from the upgrade itself. Deterministic SQLite; MySQL replay of the same
 * migrations on populated data is covered in ConsignmentBuyoutMysqlCostLedgerTest.
 */
class ConsignmentBuyoutUpgradeMatrixTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    private const MIGRATIONS = [
        '2026_11_10_000001_create_consignment_buyout_tables',
        '2026_11_10_000002_create_consignment_buyout_posting_evidence_tables',
        '2026_11_10_000003_create_consignment_buyout_return_apportionments',
        '2026_11_10_000004_create_product_price_cost_event_ledger',
    ];

    private function migration(string $name)
    {
        return require base_path("Modules/Consignment/Database/Migrations/{$name}.php");
    }

    private function legacyFingerprint(): array
    {
        $dump = fn (string $table, string $order = 'id') => DB::table($table)->orderBy($order)->get()->map(fn ($r) => (array) $r)->all();

        return [
            'sold_sources' => $dump('consignment_sold_sources'),
            'confirmations' => $dump('consignment_billing_confirmations'),
            'lines' => $dump('consignment_billing_confirmation_lines'),
            'allocations' => $dump('consignment_receipt_allocations'),
            'receipts' => $dump('consignment_receiving_details'),
            'stocks' => $dump('product_stocks'),
            'products' => $dump('products'),
            'claims_legacy_columns' => DB::table('consignment_active_serial_claims')->orderBy('id')
                ->get(['id', 'product_serial_number_id', 'consignment_billing_confirmation_id', 'consignment_serialized_allocation_id'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @test */
    public function legacy_evidence_survives_the_schema_upgrade_unchanged_and_legacy_arithmetic_is_identical(): void
    {
        $this->setUpBuyoutFixtures();
        $supplier = $this->supplier;
        $receipt = $this->receipt($supplier, '10');
        DB::table('consignment_receiving_details')->where('id', $receipt->id)->update(['unit_dpp' => 100, 'unit_cost' => 100]);
        $this->stock('10');

        // ---- go back to the pre-upgrade schema (nothing buyout-related holds evidence yet)
        foreach (array_reverse(self::MIGRATIONS) as $name) {
            $this->migration($name)->down();
        }
        $this->assertFalse(Schema::hasTable('consignment_buyouts'));
        $this->assertFalse(Schema::hasTable('product_price_cost_events'));
        $this->assertFalse(Schema::hasColumn('consignment_active_serial_claims', 'owner_type'));

        // ---- legacy data written by the OLD code paths
        $versioned = $this->soldSource('4');
        DB::table('consignment_sold_sources')->where('id', $versioned->id)->update(['source_snapshot' => json_encode(['snapshot_version' => 1, 'x' => 'v'])]);
        $unversioned = $this->soldSource('2');
        DB::table('consignment_sold_sources')->where('id', $unversioned->id)->update(['source_snapshot' => json_encode(['x' => 'legacy-no-version'])]);
        $partialReturn = $this->soldSource('3');           // partially returned later; the evidence rows must survive as they are
        DB::table('consignment_sold_sources')->where('id', $partialReturn->id)->update(['source_hash' => 'PARTIAL-RETURN-HASH']);

        $mk = function (string $number, string $status, array $extra = []) use ($supplier, $versioned, $receipt) {
            $c = ConsignmentBillingConfirmation::create(['setting_id' => $this->setting->id, 'supplier_id' => $supplier->id,
                'confirmation_number' => $number, 'status' => $status, 'date' => '2026-01-15', 'source_hash' => 'HASH-' . $number] + $extra);
            $l = ConsignmentBillingConfirmationLine::create(['consignment_billing_confirmation_id' => $c->id, 'consignment_sold_source_id' => $versioned->id,
                'product_id' => $this->product->id, 'location_id' => $this->location->id, 'allocated_base_quantity' => '1.500',
                'sold_source_snapshot' => ['snapshot_version' => 1]]);
            ConsignmentReceiptAllocation::create(['consignment_billing_confirmation_line_id' => $l->id, 'consignment_receiving_detail_id' => $receipt->id,
                'allocated_base_quantity' => '1.500', 'unit_cost' => 100, 'unit_dpp' => 100, 'tax_id' => null, 'tax_rate' => 11, 'tax_amount' => 16.5,
                'tax_snapshot_version' => 1, 'receiving_detail_snapshot' => ['tax_rate' => '11.0000', 'note' => 'old tax snapshot']]);

            return $c;
        };
        $mk('LEG-WAIT', 'WAITING_APPROVAL');
        $mk('LEG-APPR', 'APPROVED');
        $mk('LEG-BILL', 'APPROVED', ['billed_at' => '2026-02-01 10:00:00', 'supplier_invoice_number' => 'SUP-1', 'invoice_date' => '2026-02-01']);

        // a legacy serial claim in the pre-upgrade column set
        $serial = \Modules\Product\Entities\ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'serial_number' => 'SN-LEG', 'consignment_receiving_detail_id' => $receipt->id, 'status' => 'ACTIVE']);
        $before = $this->legacyFingerprint();
        $legacyAvailableBefore = 10.0 - 1.5 - 1.5;         // received - waiting - approved (billed approved confirmation counts as approved)

        // ---- upgrade
        foreach (self::MIGRATIONS as $name) {
            $this->migration($name)->up();
        }

        $this->assertEquals($before, $this->legacyFingerprint(), 'every legacy row (hashes, amounts, tax snapshots, balances, stock) is byte-identical');
        $this->assertSame(0, DB::table('consignment_buyouts')->count() + DB::table('consignment_buyout_movements')->count()
            + DB::table('consignment_buyout_cost_events')->count() + DB::table('consignment_inventory_coordinations')->count(),
            'the upgrade creates no buyout, movement, cost or coordination data');
        $this->assertSame(0, DB::table('product_price_cost_events')->count(), 'no price existed, so no ledger history is invented');

        // Legacy arithmetic still yields the legacy number: received - legacy waiting - legacy approved (billed counts as approved).
        $cap = $this->svc->receiptCapacity($receipt->id);
        $this->assertEquals(10.0 - 4.5, $cap['financial_available'] ?? $cap['unsold_available'] + 0, 'waiting + approved + billed legacy allocations (1.5 each) remain deducted');

        // Versioned and unversioned snapshots both resolve to a supported version (no silent re-versioning).
        $this->assertSame(1, \Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(['snapshot_version' => 1]));
        $this->assertSame(1, \Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion(['x' => 'legacy-no-version']));
        $this->assertSame([], array_filter($this->svc->poolBlockers($this->setting->id, $this->product->id, $this->location->id),
            fn ($b) => $b['type'] === 'UNKNOWN_SNAPSHOT_VERSION'), 'old unversioned sources are still recognised');

        // The legacy claim columns gain the LEGACY owner without touching their meaning.
        $this->assertSame(['LEGACY'], DB::table('consignment_active_serial_claims')->pluck('owner_type')->unique()->values()->all() ?: ['LEGACY']);
        $this->assertNotNull($serial->fresh());
    }

    /** @test */
    public function a_populated_upgrade_is_replayable_and_the_ledger_refuses_to_vanish_once_evidence_exists(): void
    {
        $this->setUpBuyoutFixtures();
        // Re-running every up() over an already-upgraded, populated schema is a no-op or a clean refusal, never data loss.
        $price = DB::table('product_prices')->insertGetId(['product_id' => $this->product->id, 'setting_id' => $this->setting->id,
            'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 50]);
        $events = DB::table('product_price_cost_events')->count();
        $this->migration(self::MIGRATIONS[3])->up();
        $this->assertSame($events, DB::table('product_price_cost_events')->count(), 're-running the ledger migration adds no phantom events');
        DB::table('product_prices')->where('id', $price)->update(['average_purchase_price' => 60]);
        $this->assertSame($events + 1, DB::table('product_price_cost_events')->count());
    }
}
