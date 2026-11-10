<?php

namespace Tests\Feature\Adjustment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\StockOpnameApprovalService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\People\Entities\Supplier;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real stock-opname approval versus serials claimed by an approved buyout. Deterministic SQLite integration.
 */
class StockOpnameBuyoutClaimTest extends TestCase
{
    use RefreshDatabase;

    protected User $approver;
    protected Setting $setting;
    protected Location $location;
    protected Location $otherLocation;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company', 'company_email' => 'test@company.com',
            'company_phone' => '123456789', 'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer', 'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);
        $this->otherLocation = Location::create([
            'name' => 'Gudang Cabang', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);

        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        $this->approver = User::factory()->create(['is_active' => 1]);

        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        $this->approver->givePermissionTo(['adjustments.approval', 'adjustments.edit']);

        $this->actingAs($this->approver);
        session(['setting_id' => $this->setting->id]);
    }

    private function makeProduct(bool $serialized = false): Product
    {
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*',
            'operation_value' => 1, 'is_active' => true,
        ]);

        return Product::create([
            'product_name' => $serialized ? 'Produk Serial' : 'Produk Uji',
            'product_code' => ($serialized ? 'PS-' : 'PU-') . uniqid(),
            'product_cost' => 1000, 'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'stock_managed' => true, 'is_active' => true,
            'serial_number_required' => $serialized,
        ]);
    }

    /**
     * Serialized products still keep exact per-location good/bad x tax bucket
     * counters on ProductStock alongside the individual serial rows (this is
     * the authoritative bucket-accounting convention used across the wider
     * codebase, e.g. ProductController::handleStockInitialization), so tests
     * that exercise serialized bucket accounting must create both.
     */
    private function makeStockRow(Product $product, Location $location, int $goodTax = 0, int $goodNonTax = 0, int $badTax = 0, int $badNonTax = 0): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $goodTax + $goodNonTax + $badTax + $badNonTax,
            'quantity_tax' => $goodTax,
            'quantity_non_tax' => $goodNonTax,
            'broken_quantity' => $badTax + $badNonTax,
            'broken_quantity_tax' => $badTax,
            'broken_quantity_non_tax' => $badNonTax,
        ]);
    }

    private function makeSerial(Product $product, Location $location, string $serialNumber, bool $isBroken = false, ?int $taxId = null): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => $isBroken,
            'tax_id' => $taxId,
        ]);
    }

    private function makeWaitingApprovalAdjustment(array $rows, ?Location $location = null): Adjustment
    {
        $location ??= $this->location;

        return Adjustment::create([
            'reference' => 'ADJ-APR-' . uniqid(),
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => $location->id,
            'submitted_by' => $this->approver->id,
            'submitted_at' => now(),
            'count_draft' => ['schema_version' => 1, 'rows' => $rows],
        ]);
    }

    private function service(): StockOpnameApprovalService
    {
        return app(StockOpnameApprovalService::class);
    }

    private function claimedSerial(Product $product, Location $at, string $sn): array
    {
        $supplier = Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Sup', 'supplier_email' => 's@e.com',
            'supplier_phone' => '0811', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $receival = ConsignmentReceival::create(['setting_id' => $this->setting->id, 'supplier_id' => $supplier->id, 'reference' => 'CR-O',
            'receival_number' => 'CR-O', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => $this->setting->id, 'location_id' => $at->id,
            'consignment_receival_id' => $receival->id, 'receiving_number' => 'CR-O', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $product->id,
            'product_name' => 'P', 'product_code' => 'P', 'quantity' => 1, 'unit_cost' => 1, 'unit_dpp' => 1,
            'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $detail = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $product->id, 'quantity_received' => 1, 'received_base_quantity' => 1, 'unit_cost' => 1, 'unit_dpp' => 1]);
        $serial = $this->makeSerial($product, $at, $sn, false, $this->tax->id);
        $serial->update(['consignment_receiving_detail_id' => $detail->id]);

        $user = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::findOrCreate($p, 'web');
            $user->givePermissionTo($p);
        }
        $user->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $svc = new ConsignmentBuyoutLifecycleService();
        $b = $svc->createDraft($this->setting->id, $supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $detail->id,
            'destination_location_id' => $this->location->id, 'base_quantity' => '1', 'serial_ids' => [$serial->id],
        ]], $user);

        return [$svc->approve($svc->submit($b, $user), $user), $serial];
    }

    public function test_opname_cannot_move_a_serial_claimed_by_a_buyout_and_changes_nothing()
    {
        $product = $this->makeProduct(true);
        $this->makeStockRow($product, $this->otherLocation, goodTax: 2);
        $this->makeStockRow($product, $this->location);
        [$buyout, $claimed] = $this->claimedSerial($product, $this->otherLocation, 'SN-CLAIMED-1');
        $free = $this->makeSerial($product, $this->otherLocation, 'SN-FREE-1', false, $this->tax->id);

        $blocked = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [['serial_number' => 'SN-CLAIMED-1', 'condition' => 'good']]],
        ]);
        try {
            $this->service()->approve($blocked, $this->approver);
            $this->fail('claimed serial was moved by opname');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(AdjustmentStatus::WaitingApproval, $blocked->fresh()->status);
        $this->assertEquals($this->otherLocation->id, $claimed->fresh()->location_id);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(1, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->count());
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // Negative control: the same operation for an unclaimed serial is approved and moves it.
        $ok = $this->makeWaitingApprovalAdjustment([
            ['product_id' => $product->id, 'good_count' => 0, 'bad_count' => 0, 'serials' => [['serial_number' => 'SN-FREE-1', 'condition' => 'good']]],
        ]);
        $this->service()->approve($ok, $this->approver);
        $this->assertEquals($this->location->id, $free->fresh()->location_id);
        $this->assertEquals($this->otherLocation->id, $claimed->fresh()->location_id);
    }

    public function test_opname_coordination_covers_the_products_it_consumes()
    {
        $product = $this->makeProduct(false);
        $this->makeStockRow($product, $this->location, goodTax: 5);
        $adj = $this->makeWaitingApprovalAdjustment([['product_id' => $product->id, 'good_count' => 3, 'bad_count' => 0, 'serials' => []]]);
        $this->service()->approve($adj, $this->approver);
        $this->assertDatabaseHas('consignment_inventory_coordinations', ['setting_id' => $this->setting->id, 'product_id' => $product->id]);
    }
}
