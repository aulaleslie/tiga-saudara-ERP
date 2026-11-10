<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real purchase-return dispatch approval (stock leaves the building) versus goods reserved by an approved buyout.
 * Deterministic SQLite integration; not a concurrency proof.
 */
class ConsignmentBuyoutPurchaseReturnDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Location $location;
    private Supplier $supplier;
    private ConsignmentReceivingDetail $receipt;
    private User $buyoutUser;
    private ConsignmentBuyoutLifecycleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::create(['id' => 1, 'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp', 'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1]);
        Setting::create(['id' => 1, 'company_name' => 'Test Company', 'company_email' => 'test@company.com', 'company_phone' => '1234567890',
            'company_address' => 'Test Address', 'default_currency_id' => 1, 'default_currency_position' => 'prefix', 'notification_email' => 'n@t.com', 'footer_text' => 'F']);
        $this->supplier = Supplier::create(['supplier_name' => 'Test Supplier', 'supplier_email' => 'test@supplier.com', 'supplier_phone' => '1234567890',
            'city' => 'C', 'country' => 'C', 'address' => 'A', 'setting_id' => 1]);
        $this->product = Product::create(['product_name' => 'Test Product', 'product_code' => 'PRD-001', 'product_quantity' => 10, 'product_cost' => 10000,
            'product_price' => 12000, 'product_unit' => 'pcs', 'product_stock_alert' => 0, 'setting_id' => 1]);
        $this->location = Location::create(['name' => 'Konsinyasi', 'setting_id' => 1, 'is_consignment' => true]);
        session(['setting_id' => 1]);
        Gate::define('purchaseReturns.dispatchApproval', fn () => true);

        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10, 'quantity_tax' => 0,
            'quantity_non_tax' => 10, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);

        $receival = ConsignmentReceival::create(['setting_id' => 1, 'supplier_id' => $this->supplier->id, 'reference' => 'CR-PRD', 'receival_number' => 'CR-PRD', 'status' => 'APPROVED', 'date' => now()]);
        $receiving = ConsignmentReceiving::create(['setting_id' => 1, 'location_id' => $this->location->id, 'consignment_receival_id' => $receival->id,
            'receiving_number' => 'CR-PRD', 'status' => 'APPROVED', 'date' => now()]);
        $line = ConsignmentReceivalLine::create(['consignment_receival_id' => $receival->id, 'product_id' => $this->product->id, 'product_name' => 'P', 'product_code' => 'P',
            'quantity' => 10, 'unit_cost' => 1, 'unit_dpp' => 1, 'subtotal_cost' => 1, 'subtotal_dpp' => 1, 'total_cost' => 1, 'total_dpp' => 1]);
        $this->receipt = ConsignmentReceivingDetail::create(['consignment_receiving_id' => $receiving->id, 'consignment_receival_line_id' => $line->id,
            'product_id' => $this->product->id, 'quantity_received' => 10, 'received_base_quantity' => 10, 'unit_cost' => 1, 'unit_dpp' => 1]);

        $this->buyoutUser = User::factory()->create();
        foreach (['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->buyoutUser->givePermissionTo($p);
        }
        $this->buyoutUser->settings()->attach(1, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        $this->svc = new ConsignmentBuyoutLifecycleService();
    }

    private function reserve(int $qty)
    {
        $dest = Location::firstOrCreate(['name' => 'Std dest', 'setting_id' => 1], ['is_consignment' => false]);
        $b = $this->svc->createDraft(1, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'destination_location_id' => $dest->id,
            'base_quantity' => (string) $qty, 'buckets' => ['NON_TAXED' => (string) $qty]]], $this->buyoutUser);

        return $this->svc->approve($this->svc->submit($b, $this->buyoutUser), $this->buyoutUser);
    }

    private function purchaseReturn(int $qty): PurchaseReturn
    {
        $return = PurchaseReturn::create(['date' => now(), 'reference' => 'PR-' . uniqid(), 'setting_id' => 1, 'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->supplier_name, 'payment_method' => 'Cash', 'paid_amount' => 0,
            'total_amount' => 1000, 'due_amount' => 1000, 'status' => 'Pending', 'approval_status' => 'approved', 'payment_status' => 'Unpaid',
            'return_dispatch_status' => 'pending_approval']);
        PurchaseReturnDetail::create(['purchase_return_id' => $return->id, 'product_id' => $this->product->id, 'product_name' => 'Test Product',
            'product_code' => 'PRD-001', 'quantity' => $qty, 'location_id' => $this->location->id, 'price' => 1000, 'unit_price' => 1000,
            'sub_total' => 1000 * $qty, 'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);

        return $return;
    }

    private function approve(PurchaseReturn $r)
    {
        return $this->actingAs(User::factory()->create())->withSession(['setting_id' => 1])->post(route('purchase-returns.dispatch-approve', $r->id));
    }

    private function stock(): float
    {
        return (float) ProductStock::where('product_id', $this->product->id)->value('quantity_non_tax');
    }

    /** @test */
    public function dispatch_cannot_consume_reserved_goods_rolls_back_completely_and_works_for_unreserved_and_after_release(): void
    {
        $buyout = $this->reserve(8);                    // 10 on hand, 8 reserved => 2 returnable
        $txBefore = Transaction::count();
        $productQty = (float) $this->product->fresh()->product_quantity;

        // 5 > 2 unreserved: refused, nothing changes (ordinary error handling, no 500).
        $big = $this->purchaseReturn(5);
        $this->approve($big)->assertRedirect();
        $this->assertSame('pending_approval', strtolower($big->fresh()->return_dispatch_status));
        $this->assertSame(10.0, $this->stock());
        $this->assertSame($productQty, (float) $this->product->fresh()->product_quantity);
        $this->assertSame($txBefore, Transaction::count());
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());

        // Negative control: 2 units are unreserved and dispatch normally, leaving the reservation intact.
        $small = $this->purchaseReturn(2);
        $this->approve($small)->assertRedirect();
        $this->assertSame('dispatched', strtolower($small->fresh()->return_dispatch_status));
        $this->assertSame(8.0, $this->stock());
        $this->assertSame(2, $buyout->fresh()->reservations()->where('status', 'ACTIVE')->count());
        $this->assertSame(8.0, \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::reservedQuantity($this->product->id, $this->location->id));

        // The 5-unit return is still refused (only 0 unreserved left)...
        $this->approve($big)->assertRedirect();
        $this->assertSame('pending_approval', strtolower($big->fresh()->return_dispatch_status));
        $this->assertSame(8.0, $this->stock());

        // ...until the buyout is cancelled and the goods are released: then the same approval succeeds (partial-state free).
        $this->svc->cancel($buyout, 'release', $this->buyoutUser);
        $this->approve($big)->assertRedirect();
        $this->assertSame('dispatched', strtolower($big->fresh()->return_dispatch_status));
        $this->assertSame(3.0, $this->stock());
    }

    /** @test */
    public function a_multi_line_return_with_one_reserved_line_changes_nothing_at_all(): void
    {
        $other = Product::create(['product_name' => 'Other', 'product_code' => 'PRD-002', 'product_quantity' => 5, 'product_cost' => 1, 'product_price' => 2,
            'product_unit' => 'pcs', 'product_stock_alert' => 0, 'setting_id' => 1]);
        $otherStock = ProductStock::create(['product_id' => $other->id, 'location_id' => $this->location->id, 'quantity' => 5, 'quantity_tax' => 0,
            'quantity_non_tax' => 5, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);
        $this->reserve(9);

        // Line 1 (unreserved product) would succeed on its own; line 2 (reserved product) must abort the WHOLE approval.
        $return = $this->purchaseReturn(3);
        PurchaseReturnDetail::create(['purchase_return_id' => $return->id, 'product_id' => $other->id, 'product_name' => 'Other', 'product_code' => 'PRD-002',
            'quantity' => 2, 'location_id' => $this->location->id, 'price' => 1, 'unit_price' => 1, 'sub_total' => 2, 'product_discount_amount' => 0,
            'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);
        // order matters: put the unreserved line first
        PurchaseReturnDetail::where('purchase_return_id', $return->id)->where('product_id', $this->product->id)->update(['id' => 9999]);

        $writes = 0;
        \DB::listen(function ($q) use (&$writes) {
            if (str_starts_with(strtolower($q->sql), 'update "product_stocks"')) {
                $writes++;
            }
        });
        $this->approve($return)->assertRedirect();

        $this->assertGreaterThan(0, $writes, 'the unreserved line really was posted before the reserved line aborted the approval');
        $this->assertSame('pending_approval', strtolower($return->fresh()->return_dispatch_status));
        $this->assertEquals(5, (float) $otherStock->fresh()->quantity_non_tax, 'the unreserved line was rolled back too');
        $this->assertSame(10.0, $this->stock());
        $this->assertSame(0, Transaction::count());
    }
}
