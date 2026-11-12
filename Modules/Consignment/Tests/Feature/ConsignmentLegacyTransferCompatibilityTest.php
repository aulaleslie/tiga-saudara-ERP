<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferMovementService;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent;
use Modules\Consignment\Services\ConsignmentTransferProvenanceService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use RuntimeException;
use Tests\TestCase;

/**
 * Documented compatibility deviation (design §3, stock-transfer-inventory-movement delta): V1/V2 executors cannot post
 * receipt provenance, so NEW dispatches from consignment origins are refused with an actionable V3 path. Ordinary
 * non-consignment V1/V2 dispatches are unchanged. Exercises the real legacy TransferMovementService.
 */
class ConsignmentLegacyTransferCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->setting = Setting::factory()->create();
        $this->product = Product::create(['product_name' => 'Legacy Bulk', 'product_code' => 'LB-' . uniqid(), 'setting_id' => $this->setting->id,
            'product_quantity' => 10, 'product_cost' => 1000, 'product_price' => 1500, 'serial_number_required' => false, 'stock_managed' => true]);
    }

    private function legacyTransfer(Location $from, Location $to): Transfer
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $from->id, 'quantity' => 10, 'quantity_non_tax' => 10, 'quantity_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0]);
        $t = Transfer::create(['origin_location_id' => $from->id, 'destination_location_id' => $to->id, 'created_by' => auth()->id(),
            'status' => Transfer::STATUS_APPROVED, 'revision' => 1]);
        TransferProduct::create(['transfer_id' => $t->id, 'product_id' => $this->product->id, 'quantity' => 4, 'quantity_tax' => 0, 'quantity_non_tax' => 4,
            'quantity_broken_tax' => 0, 'quantity_broken_non_tax' => 0]);

        return $t->fresh('products');
    }

    private function stockAt(Location $l): int
    {
        return (int) ProductStock::where('product_id', $this->product->id)->where('location_id', $l->id)->value('quantity');
    }

    /** @test */
    public function ordinary_legacy_dispatch_is_unchanged_and_records_no_provenance(): void
    {
        $a = Location::factory()->create(['setting_id' => $this->setting->id, 'is_consignment' => false]);
        $b = Location::factory()->create(['setting_id' => $this->setting->id, 'is_consignment' => false]);
        $t = $this->legacyTransfer($a, $b);

        app(TransferMovementService::class)->dispatch($t);

        $this->assertSame(6, $this->stockAt($a), 'the legacy executor still deducts exactly as before');
        $this->assertSame(0, ConsignmentProvenanceEvent::count());
    }

    /** @test */
    public function new_legacy_dispatch_from_a_consignment_origin_is_refused_with_an_actionable_v3_path_and_changes_nothing(): void
    {
        $a = Location::factory()->create(['setting_id' => $this->setting->id, 'is_consignment' => true]);
        $b = Location::factory()->create(['setting_id' => $this->setting->id, 'is_consignment' => true]);
        $t = $this->legacyTransfer($a, $b);
        $txBefore = \DB::table('transactions')->count();

        try {
            app(TransferMovementService::class)->dispatch($t);
            $this->fail('legacy consignment dispatch accepted');
        } catch (RuntimeException $e) {
            $this->assertSame(ConsignmentTransferProvenanceService::LEGACY_REFUSAL, $e->getMessage());
            $this->assertStringContainsString('versi 3', $e->getMessage());
            $this->assertStringContainsString('STOCK_TRANSFERS_V3_CREATION_ENABLED', $e->getMessage());
        }
        $this->assertSame(10, $this->stockAt($a), 'refused before any stock effect');
        $this->assertSame($txBefore, \DB::table('transactions')->count());
        $this->assertSame(Transfer::STATUS_APPROVED, $t->fresh()->status);
    }

    /** @test */
    public function the_guard_is_wired_into_every_legacy_dispatch_entry_point(): void
    {
        foreach ([
            'Modules/Adjustment/Services/TransferMovementService.php' => 2,          // V1 dispatch + dispatchReturn
            'Modules/Adjustment/Services/ForwardDispatchApprovalExecutor.php' => 1,  // V2 forward
            'Modules/Adjustment/Services/ReturnDispatchApprovalExecutor.php' => 1,   // V2 return
        ] as $file => $n) {
            $this->assertSame($n, substr_count(file_get_contents(base_path($file)), 'assertLegacyDispatchAllowed'), $file);
        }
        // Receiving already-dispatched legacy documents is not guarded (nothing is stranded).
        foreach (['ForwardReceiptApprovalExecutor.php', 'ReturnReceiptApprovalExecutor.php'] as $f) {
            $this->assertStringNotContainsString('assertLegacyDispatchAllowed', file_get_contents(base_path("Modules/Adjustment/Services/{$f}")));
        }
    }
}
