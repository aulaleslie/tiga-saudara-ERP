<?php

namespace Tests\Feature;

use App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * A target purchase can carry more than one PurchaseDetail row for the same
 * product at different prices (e.g. the product was added twice, or repriced
 * mid-document). Approval always reduces those rows sequentially, exhausting
 * one before touching the next. The settlement nominal shown/validated in the
 * Livewire form must be computed via that exact same row-by-row allocation —
 * not a flat weighted average — so the nominal the user sees/approves always
 * equals exactly what approval removes from the purchase total.
 */
class PurchaseReturnMultiLineSameProductValuationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Location $location;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'purchaseReturnSettlements.submit',
            'purchaseReturnSettlements.approve',
            'purchaseReturns.viewPrice',
        ];
        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission);
        }

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo($permissions);
        $this->actingAs($this->user);

        Gate::before(fn () => true);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $category = Category::create([
            'category_code' => 'TEST_CAT',
            'category_name' => 'Test Category',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Multi Price Product',
            'product_code' => 'MPP001',
            'product_unit' => 'pcs',
            'product_price' => 1000,
            'product_cost' => 800,
            'product_stock_alert' => 10,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'serial_number_required' => false,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    protected function createPurchase(array $attributes = []): Purchase
    {
        return Purchase::create(array_merge([
            'supplier_id' => $this->supplier->id,
            'reference' => 'PO-' . uniqid(),
            'supplier_purchase_number' => 'SPN-' . uniqid(),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
            'payment_method' => 'Cash',
        ], $attributes));
    }

    protected function createReturn(array $attributes = []): PurchaseReturn
    {
        return PurchaseReturn::create(array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 1000,
            'reference' => 'PR-' . uniqid(),
            'status' => PurchaseReturn::STATUS_IN_RETURN,
            'approval_status' => 'APPROVED',
            'return_dispatch_status' => 'DISPATCHED',
            'return_dispatched_at' => now(),
            'date' => now(),
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
        ], $attributes));
    }

    protected function markFullyReceived(Purchase $purchase, PurchaseDetail $detail): void
    {
        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'external_delivery_number' => 'GRN-' . uniqid(),
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => $detail->quantity,
        ]);
    }

    protected function createReturnDetail(PurchaseReturn $purchaseReturn, float $quantity, float $unitPrice): PurchaseReturnDetail
    {
        return PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'unit_price' => $unitPrice,
            'sub_total' => $quantity * $unitPrice,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
    }

    /**
     * Two same-product lines on one purchase, priced 100/unit (qty 5 = 500) and
     * 300/unit (qty 5 = 1500). Returning 3 units must be valued and approved at
     * exactly 3 x 100 = 300 — the first row's price — because approval exhausts
     * the first (lower id) row before touching the second. A flat weighted
     * average across both rows (100+300)/2 = 200/unit would instead price it at
     * 600, which is not what approval actually removes.
     */
    public function test_return_within_first_line_is_valued_and_approved_at_first_lines_price(): void
    {
        $purchase = $this->createPurchase(['total_amount' => 2000, 'due_amount' => 2000]);

        $lowPriceDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $highPriceDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->markFullyReceived($purchase, $lowPriceDetail);
        $this->markFullyReceived($purchase, $highPriceDetail);

        $purchaseReturn = $this->createReturn(['total_amount' => 300, 'due_amount' => 300]);
        $returnDetail = $this->createReturnDetail($purchaseReturn, 3, 100);

        $component = Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            // 3 units consumed entirely from the first (100/unit) row: 3 x 100 = 300,
            // not the flat-average (100+300)/2 x 3 = 600.
            ->assertSet('settlementLines.0.nominal', 300.0)
            ->assertSet('settlementLines.0.max_nominal', 300.0);

        $component->call('submitLine', 0);

        $settlementItem = PurchaseReturnItemSettlement::where('purchase_return_detail_id', $returnDetail->id)->first();
        $this->assertNotNull($settlementItem);
        $this->assertEquals(300.0, (float) $settlementItem->nominal);

        $totalBefore = (float) $purchase->total_amount;

        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        $purchase->refresh();
        $totalAfter = (float) $purchase->total_amount;

        // The settlement nominal must exactly equal the reduction in the purchase total.
        $this->assertEqualsWithDelta(300.0, $totalBefore - $totalAfter, 0.001);

        $lowPriceDetail->refresh();
        $this->assertSame('2.000', (string) $lowPriceDetail->quantity);
        $this->assertEqualsWithDelta(200.0, (float) $lowPriceDetail->sub_total, 0.001);
    }

    /**
     * Same two-line setup, but the return (7 units) spans both lines: it fully
     * consumes the first row (5 units @ 100 = 500) then takes 2 units from the
     * second row (2 x 300 = 600), totalling 1100. The flat weighted average
     * ((100+300)/2 x 7 = 1400) would overvalue this settlement relative to what
     * approval actually removes.
     */
    public function test_return_spanning_both_lines_is_valued_and_approved_using_deterministic_allocation(): void
    {
        $purchase = $this->createPurchase(['total_amount' => 2000, 'due_amount' => 2000]);

        $firstDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $secondDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->markFullyReceived($purchase, $firstDetail);
        $this->markFullyReceived($purchase, $secondDetail);

        $purchaseReturn = $this->createReturn(['total_amount' => 1100, 'due_amount' => 1100]);
        $returnDetail = $this->createReturnDetail($purchaseReturn, 7, 100);

        $component = Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            // 5 units @ 100 (=500) + 2 units @ 300 (=600) = 1100, not the flat-average
            // (100+300)/2 x 7 = 1400.
            ->assertSet('settlementLines.0.nominal', 1100.0)
            ->assertSet('settlementLines.0.max_nominal', 1100.0);

        $component->call('submitLine', 0);

        $settlementItem = PurchaseReturnItemSettlement::where('purchase_return_detail_id', $returnDetail->id)->first();
        $this->assertNotNull($settlementItem);
        $this->assertEquals(1100.0, (float) $settlementItem->nominal);

        $totalBefore = (float) $purchase->total_amount;

        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        $purchase->refresh();
        $totalAfter = (float) $purchase->total_amount;

        // The settlement nominal must exactly equal the reduction in the purchase total.
        $this->assertEqualsWithDelta(1100.0, $totalBefore - $totalAfter, 0.001);

        $firstDetail->refresh();
        $secondDetail->refresh();
        $this->assertSame('0.000', (string) $firstDetail->quantity);
        $this->assertEqualsWithDelta(0.0, (float) $firstDetail->sub_total, 0.001);
        $this->assertSame('3.000', (string) $secondDetail->quantity);
        $this->assertEqualsWithDelta(900.0, (float) $secondDetail->sub_total, 0.001);
    }
}
