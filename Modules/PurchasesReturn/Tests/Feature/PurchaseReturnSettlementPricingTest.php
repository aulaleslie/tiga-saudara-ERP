<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReturnSettlementPricingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected Location $location;
    protected Product $product;
    protected Product $serialProduct;

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
            'product_name' => 'Non-Serial Product',
            'product_code' => 'NSP001',
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

        $this->serialProduct = Product::create([
            'product_name' => 'Serial Product',
            'product_code' => 'SP001',
            'product_unit' => 'pcs',
            'product_price' => 2000,
            'product_cost' => 1500,
            'product_stock_alert' => 5,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'serial_number_required' => true,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
        ]);
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

    /**
     * 4.1 Test targeted line is valued at target purchase unit_price * quantity
     * when the target price is higher than the return line's stored value (uncapped).
     */
    public function test_targeted_line_is_valued_at_target_unit_price_when_target_price_is_higher(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 2000,
            'due_amount' => 2000,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 200,
            'unit_price' => 200, // Higher than return detail unit_price 100
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 1000, 'due_amount' => 1000]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 2000.0); // 10 * 200 = 2000, not capped at 1000
    }

    /**
     * 4.2 Test targeted line resolves to non-zero when max_nominal is 0.
     */
    public function test_targeted_line_resolves_to_non_zero_when_max_nominal_is_zero(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 500,
            'due_amount' => 500,
        ]);
        PurchaseDetail::create([
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

        $purchaseReturn = $this->createReturn(['total_amount' => 0, 'due_amount' => 0]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0, // max_nominal is 0
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 500.0); // 5 * 100 = 500, uncapped
    }

    /**
     * 4.3 Test line whose target purchase has no matching product detail keeps existing value and does not become zero.
     */
    public function test_line_keeps_existing_value_when_target_purchase_has_no_matching_product_detail(): void
    {
        $otherProduct = Product::create([
            'product_name' => 'Other Product',
            'product_code' => 'OTH001',
            'product_unit' => 'pcs',
            'product_price' => 500,
            'product_cost' => 400,
            'product_stock_alert' => 10,
            'category_id' => $this->product->category_id,
            'setting_id' => $this->setting->id,
            'serial_number_required' => false,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
        ]);

        $purchase = $this->createPurchase(['total_amount' => 300, 'due_amount' => 300]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $otherProduct->id,
            'product_name' => $otherProduct->product_name,
            'product_code' => $otherProduct->product_code,
            'quantity' => 3,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 750, 'due_amount' => 750]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 150,
            'unit_price' => 150,
            'sub_total' => 750,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.nominal', 750)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 750.0);
    }

    /**
     * 5.1 Test serialized line auto-selected via origin_purchase_id is repriced from that purchase.
     */
    public function test_serialized_line_auto_selected_via_origin_purchase_is_repriced(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 750,
            'due_amount' => 750,
            'paid_amount' => 0,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 1,
            'price' => 750,
            'unit_price' => 750, // Origin unit price is 750
            'sub_total' => 750,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-PRICING-01',
            'location_id' => $this->location->id,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 500, 'due_amount' => 500]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500, // Catalogue price at return creation was 500
            'sub_total' => 500,
            'serial_number_ids' => [$sn->id],
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->assertSet('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 750.0);
    }

    /**
     * 5.2 Test non-serialized line auto-selected via origin_purchase_id is repriced from that purchase.
     */
    public function test_non_serialized_line_auto_selected_via_origin_purchase_is_repriced(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 800,
            'due_amount' => 800,
            'paid_amount' => 0,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 4,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 800,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 400, 'due_amount' => 400]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 4,
            'price' => 100,
            'unit_price' => 100, // Catalogue price was 100 (total 400)
            'sub_total' => 400,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // When method becomes MODIFY_PURCHASE, it auto-selects origin purchase and reprices to 4 * 200 = 800
        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->assertSet('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 800.0);
    }

    /**
     * 5.3 Test settlement form opened with a previously saved draft target purchase presents recomputed value on load.
     */
    public function test_form_opened_with_saved_draft_target_purchase_recomputes_value_on_mount(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 1200,
            'due_amount' => 1200,
            'paid_amount' => 0,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 3,
            'price' => 400,
            'unit_price' => 400, // Purchase price is 400/unit
            'sub_total' => 1200,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 300, 'due_amount' => 300]);
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 3,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 300,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Previously saved settlement item has nominal 300 and target_purchase_id pointing to $purchase
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => null,
            'method' => PurchaseReturnDetail::METHOD_MODIFY_PURCHASE,
            'nominal' => 300,
            'target_purchase_id' => $purchase->id,
            'status' => PurchaseReturnItemSettlement::STATUS_DRAFT,
        ]);

        // Form should recompute to 3 * 400 = 1200 on mount
        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.nominal', 1200.0);
    }

    /**
     * 5.4 Test serialized line belonging to a multi-quantity return detail is valued at one unit's price.
     */
    public function test_serialized_line_on_multi_quantity_detail_is_valued_at_one_unit(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 900,
            'due_amount' => 900,
            'paid_amount' => 0,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 3,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 900,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $sn1 = ProductSerialNumber::create(['product_id' => $this->serialProduct->id, 'serial_number' => 'SN-M-01', 'location_id' => $this->location->id]);
        $sn2 = ProductSerialNumber::create(['product_id' => $this->serialProduct->id, 'serial_number' => 'SN-M-02', 'location_id' => $this->location->id]);

        $purchaseReturn = $this->createReturn(['total_amount' => 400, 'due_amount' => 400]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 2, // Multi-quantity detail (2 units)
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 400,
            'serial_number_ids' => [$sn1->id, $sn2->id],
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Each line is valued at 1 unit * 300 = 300, not 2 * 300 = 600
        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->assertSet('settlementLines.0.nominal', 300.0)
            ->set('settlementLines.1.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->assertSet('settlementLines.1.nominal', 300.0);
    }

    /**
     * 6.1 Test SUBMITTED line retains its stored nominal when form is opened.
     */
    public function test_submitted_line_retains_stored_nominal_on_mount(): void
    {
        $purchase = $this->createPurchase(['total_amount' => 1000, 'due_amount' => 1000]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 500, 'due_amount' => 500]);
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => null,
            'method' => PurchaseReturnDetail::METHOD_MODIFY_PURCHASE,
            'nominal' => 350, // Custom submitted amount
            'target_purchase_id' => $purchase->id,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.nominal', 350.0); // Preserved, not recomputed to 1000
    }

    /**
     * 6.2 Test APPROVED line retains its stored nominal when form is opened.
     */
    public function test_approved_line_retains_stored_nominal_on_mount(): void
    {
        $purchase = $this->createPurchase(['total_amount' => 1000, 'due_amount' => 1000]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 500, 'due_amount' => 500]);
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => null,
            'method' => PurchaseReturnDetail::METHOD_MODIFY_PURCHASE,
            'nominal' => 450,
            'target_purchase_id' => $purchase->id,
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.nominal', 450.0);
    }

    /**
     * 6.3 Test MODIFY_PURCHASE line with no target purchase keeps stored value and existing ceiling.
     */
    public function test_modify_purchase_without_target_purchase_keeps_stored_value_and_ceiling(): void
    {
        $purchaseReturn = $this->createReturn(['total_amount' => 500, 'due_amount' => 500]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', null)
            ->assertSet('settlementLines.0.nominal', 500.0)
            ->assertSet('settlementLines.0.max_nominal', 500.0);
    }

    /**
     * 6.4 Test targeted line whose derived value exceeds max_nominal passes submission validation.
     */
    public function test_targeted_line_exceeding_stored_max_nominal_passes_submission(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 1500,
            'due_amount' => 1500,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 300,
            'unit_price' => 300, // 5 * 300 = 1500
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 500, 'due_amount' => 500]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500, // Stored max_nominal is 500
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'nominal' => 1500,
            'status' => 'SUBMITTED',
        ]);
    }

    /**
     * 6.5 Test untargeted line exceeding max_nominal is rejected on submission.
     */
    public function test_untargeted_line_exceeding_max_nominal_is_rejected_on_submission(): void
    {
        $purchaseReturn = $this->createReturn(['total_amount' => 500, 'due_amount' => 500]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 500,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', null)
            ->set('settlementLines.0.nominal', 600) // Exceeds max_nominal 500
            ->call('submitLine', 0)
            ->assertHasErrors(['settlementLines.0.nominal']);
    }

    /**
     * 7.1 & 7.2 & 7.3 End-to-end full quantity return against unpaid source purchase clears due_amount on approval.
     */
    public function test_full_quantity_return_targeting_source_purchase_clears_outstanding_balance_on_approval(): void
    {
        // Source purchase: 10 units at 250 = 2500 total, due_amount = 2500
        $sourcePurchase = $this->createPurchase([
            'reference' => 'PO-SOURCE-001',
            'supplier_purchase_number' => 'SPN-SOURCE-001',
            'total_amount' => 2500,
            'paid_amount' => 0,
            'due_amount' => 2500,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
        ]);
        PurchaseDetail::create([
            'purchase_id' => $sourcePurchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 250,
            'unit_price' => 250,
            'sub_total' => 2500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Purchase return created with catalogue price 100/unit (subtotal 1000)
        $purchaseReturn = $this->createReturn([
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $sourcePurchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 1.1 Create Received Note so ensurePurchaseDetailsHaveQuantity passes on approval
        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $sourcePurchase->id,
            'external_delivery_number' => 'DEL-SOURCE-001',
            'date' => now(),
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_APPROVED,
            'location_id' => $this->location->id,
        ]);
        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'product_id' => $this->product->id,
            'po_detail_id' => $sourcePurchase->purchaseDetails->first()->id,
            'quantity_received' => 10,
        ]);

        // 1. Settlement form derives 10 * 250 = 2500 when targeting source purchase
        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $sourcePurchase->id)
            ->assertSet('settlementLines.0.nominal', 2500.0)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $settlementItem = PurchaseReturnItemSettlement::where('purchase_return_id', $purchaseReturn->id)->first();
        $this->assertNotNull($settlementItem);
        $this->assertEquals(2500, $settlementItem->nominal);
        // 2. Approve the settlement line via the controller
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHasNoErrors();

        // 3. Confirm source purchase total_amount and due_amount are reduced to 0
        $sourcePurchase->refresh();
        $this->assertEquals(0, (float) $sourcePurchase->total_amount);
        $this->assertEquals(0, (float) $sourcePurchase->due_amount);
        $this->assertEquals(0, (float) $sourcePurchase->paid_amount);
        $this->assertEquals('UNPAID', strtoupper($sourcePurchase->payment_status));
    }

    /**
     * The per-unit price is derived from sub_total / quantity, not from the unit_price
     * column. On real data those disagree: unit_price can carry a list price or a
     * different unit basis after UOM conversion, while sub_total reconciles with the
     * document total. Mirrors purchase 18587: unit_price 730000, price 693000,
     * sub_total 2772000 over qty 4.
     */
    public function test_price_is_derived_from_sub_total_not_unit_price_column(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 2772000,
            'due_amount' => 2772000,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 4,
            'price' => 693000,
            'unit_price' => 730000, // list price: must NOT be used
            'sub_total' => 2772000, // authoritative: 693000 * 4
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 100, 'due_amount' => 100]);
        $this->createReturnDetailFor($purchaseReturn, null, 4, 25);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            // 693000 * 4 = 2772000, not 730000 * 4 = 2920000
            ->assertSet('settlementLines.0.nominal', 2772000.0)
            ->assertSet('settlementLines.0.max_nominal', 2772000.0);
    }

    /**
     * A UOM-converted line: unit_price is per piece while price is per box. sub_total
     * remains the reliable basis. Mirrors purchase detail 34991.
     */
    public function test_price_derivation_handles_uom_converted_line(): void
    {
        $purchase = $this->createPurchase([
            'total_amount' => 35000,
            'due_amount' => 35000,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1000,
            'price' => 35, // per piece
            'unit_price' => 17500, // per box: must NOT be used as the per-unit basis
            'sub_total' => 35000, // 35 * 1000 pieces
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 100, 'due_amount' => 100]);
        $this->createReturnDetailFor($purchaseReturn, null, 10, 10);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            // 35 per piece * 10 returned = 350
            ->assertSet('settlementLines.0.nominal', 350.0);
    }

    /**
     * The dropdown payload is built in two places: loadUnpaidPurchases() and
     * ensurePurchaseInList(), the latter used when a purchase is injected on
     * auto-select. Both must derive the price identically or the auto-select path
     * reintroduces the unit_price basis.
     */
    public function test_auto_selected_purchase_injected_into_list_uses_sub_total_basis(): void
    {
        // ensurePurchaseInList() is reached only from the serialized auto-select branch,
        // and only actually injects when the purchase is absent from the list built by
        // loadUnpaidPurchases(). A RETURNED status falls outside that query's status
        // filter, so the injected payload is the one the resolver reads.
        $purchase = $this->createPurchase([
            'total_amount' => 693000,
            'due_amount' => 693000,
            'paid_amount' => 0,
            'status' => Purchase::STATUS_RETURNED,
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 1,
            'price' => 693000,
            'unit_price' => 730000, // list price: must NOT be used
            'sub_total' => 693000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-ENSURE-LIST-01',
            'location_id' => $this->location->id,
        ]);

        $purchaseReturn = $this->createReturn(['total_amount' => 730000, 'due_amount' => 730000]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 1,
            'price' => 730000,
            'unit_price' => 730000,
            'sub_total' => 730000,
            'serial_number_ids' => [$sn->id],
            'location_id' => $this->location->id,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->assertSet('settlementLines.0.target_purchase_id', $purchase->id)
            // sub_total basis 693000, not the unit_price column's 730000
            ->assertSet('settlementLines.0.nominal', 693000.0)
            ->assertSet('settlementLines.0.max_nominal', 693000.0);
    }

    /**
     * A return line with no recorded originating purchase (po_id NULL) gets no
     * auto-selected target, so both the value and the ceiling must follow the target
     * the user picks by hand. Mirrors purchase return 6.
     */
    public function test_manually_selected_target_reprices_line_without_origin_purchase(): void
    {
        $purchase = $this->createReceivedPurchaseWithDetail(4, 693000, 'MANUAL-PICK');
        $purchase->purchaseDetails()->update(['unit_price' => 730000]);

        // po_id NULL: no origin, so nothing is auto-assigned on mount.
        $purchaseReturn = $this->createReturn(['total_amount' => 2920000, 'due_amount' => 2920000]);
        $this->createReturnDetailFor($purchaseReturn, null, 4, 730000);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.target_purchase_id', null)
            ->assertSet('settlementLines.0.max_nominal', 2920000.0)
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            // The nota dropdown calls selectTargetPurchase() rather than assigning the
            // entangled property, since that entangle is deferred.
            ->call('selectTargetPurchase', 0, $purchase->id)
            ->assertSet('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 2772000.0)
            ->assertSet('settlementLines.0.max_nominal', 2772000.0)
            // The input's entangle is deferred, so the browser needs telling explicitly
            // to adopt the repriced value instead of re-sending its stale copy.
            ->assertDispatched('settlement-line-repriced', function (string $event, array $params) {
                return ($params['index'] ?? null) === 0
                    && abs(((float) ($params['nominal'] ?? 0)) - 2772000.0) < 0.01;
            });
    }

    /**
     * The Maks ceiling shown beside the value is recomputed too, so the label and the
     * value never disagree after a reprice.
     */
    public function test_max_nominal_is_recomputed_alongside_nominal(): void
    {
        $purchase = $this->createReceivedPurchaseWithDetail(4, 693000, 'MAXNOM');
        // Diverge unit_price from the sub_total basis so the assertion discriminates.
        $purchase->purchaseDetails()->update(['unit_price' => 730000]);

        // Return line stored at a lower catalogue-derived value.
        $purchaseReturn = $this->createReturn(['total_amount' => 100000, 'due_amount' => 100000]);
        $this->createReturnDetailFor($purchaseReturn, null, 4, 25000);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.max_nominal', 100000.0)
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_MODIFY_PURCHASE)
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            ->assertSet('settlementLines.0.nominal', 2772000.0)
            ->assertSet('settlementLines.0.max_nominal', 2772000.0);
    }

    /**
     * Build a submitted settlement item directly, bypassing the form, so approval-time
     * validation can be exercised against values the form would not itself produce.
     */
    protected function createSubmittedSettlement(
        PurchaseReturn $purchaseReturn,
        PurchaseReturnDetail $detail,
        float $nominal,
        ?int $targetPurchaseId,
        string $method = PurchaseReturnDetail::METHOD_MODIFY_PURCHASE
    ): PurchaseReturnItemSettlement {
        return PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => null,
            'method' => $method,
            'nominal' => $nominal,
            'target_purchase_id' => $targetPurchaseId,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => $this->user->id,
        ]);
    }

    /**
     * Create a received purchase with a single line, plus the approved received note
     * that approval-time quantity checks require.
     */
    protected function createReceivedPurchaseWithDetail(int $quantity, float $unitPrice, string $suffix): Purchase
    {
        $total = $quantity * $unitPrice;

        $purchase = $this->createPurchase([
            'reference' => 'PO-' . $suffix,
            'supplier_purchase_number' => 'SPN-' . $suffix,
            'total_amount' => $total,
            'paid_amount' => 0,
            'due_amount' => $total,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'unit_price' => $unitPrice,
            'sub_total' => $total,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DEL-' . $suffix,
            'date' => now(),
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_APPROVED,
            'location_id' => $this->location->id,
        ]);
        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $this->product->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => $quantity,
        ]);

        return $purchase->refresh();
    }

    protected function createReturnDetailFor(PurchaseReturn $purchaseReturn, ?int $poId, int $quantity, float $unitPrice): PurchaseReturnDetail
    {
        return PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $poId,
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
     * 9.4 A targeted settlement valued above the return line's subtotal, but within the
     * target purchase's total, is accepted at approval.
     */
    public function test_targeted_settlement_above_return_subtotal_is_approved(): void
    {
        // Target purchase: 10 units at 250 = 2500.
        $targetPurchase = $this->createReceivedPurchaseWithDetail(10, 250, 'TARGET-APPROVE');

        // Return line stored at catalogue price 100/unit -> subtotal 1000.
        $purchaseReturn = $this->createReturn(['total_amount' => 1000, 'due_amount' => 1000]);
        $detail = $this->createReturnDetailFor($purchaseReturn, $targetPurchase->id, 10, 100);

        // 2500 exceeds the return line subtotal (1000) but equals the target total.
        $settlement = $this->createSubmittedSettlement($purchaseReturn, $detail, 2500, $targetPurchase->id);

        $response = $this->post(route('purchase-return-settlements.item.approve', $settlement->id));

        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
        $this->assertEquals(
            PurchaseReturnItemSettlement::STATUS_APPROVED,
            $settlement->fresh()->status
        );
    }

    /**
     * 9.5 A targeted settlement exceeding the target purchase's total is rejected.
     */
    public function test_targeted_settlement_above_target_purchase_total_is_rejected_on_approval(): void
    {
        // Target purchase total 2500.
        $targetPurchase = $this->createReceivedPurchaseWithDetail(10, 250, 'TARGET-OVER');

        $purchaseReturn = $this->createReturn(['total_amount' => 1000, 'due_amount' => 1000]);
        $detail = $this->createReturnDetailFor($purchaseReturn, $targetPurchase->id, 10, 100);

        // 3000 exceeds the target purchase total of 2500.
        $settlement = $this->createSubmittedSettlement($purchaseReturn, $detail, 3000, $targetPurchase->id);

        $response = $this->post(route('purchase-return-settlements.item.approve', $settlement->id));

        $response->assertSessionHas('error');
        $this->assertEquals(
            PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            $settlement->fresh()->status,
            'Settlement exceeding the target purchase total must not be approved.'
        );
    }

    /**
     * 9.6 The subtotal ceiling still applies to an UNTARGETED settlement even when the
     * return detail records an originating purchase. This is the boundary that the
     * detail->po_id disjunct previously widened past the rule.
     */
    public function test_untargeted_settlement_above_subtotal_is_rejected_even_when_detail_has_po_id(): void
    {
        // The detail records an originating purchase, but no target purchase is selected.
        $originPurchase = $this->createReceivedPurchaseWithDetail(10, 250, 'ORIGIN-UNTARGETED');

        $purchaseReturn = $this->createReturn(['total_amount' => 1000, 'due_amount' => 1000]);
        $detail = $this->createReturnDetailFor($purchaseReturn, $originPurchase->id, 10, 100);

        // target_purchase_id is null: this is the cash-refund flow, which stays capped
        // at the return line subtotal (1000) despite po_id being present.
        $settlement = $this->createSubmittedSettlement($purchaseReturn, $detail, 2500, null);

        $response = $this->post(route('purchase-return-settlements.item.approve', $settlement->id));

        $response->assertSessionHas('error');
        $this->assertEquals(
            PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            $settlement->fresh()->status,
            'An untargeted settlement must remain bounded by the return line subtotal.'
        );

        $originPurchase->refresh();
        $this->assertEquals(2500, (float) $originPurchase->total_amount);
        $this->assertEquals(2500, (float) $originPurchase->due_amount);
    }

    /**
     * 9.7 A non-MODIFY_PURCHASE settlement above the return line subtotal is still rejected.
     */
    public function test_non_modify_settlement_above_subtotal_is_rejected_on_approval(): void
    {
        $originPurchase = $this->createReceivedPurchaseWithDetail(10, 250, 'ORIGIN-BROKEN');

        $purchaseReturn = $this->createReturn(['total_amount' => 1000, 'due_amount' => 1000]);
        $detail = $this->createReturnDetailFor($purchaseReturn, $originPurchase->id, 10, 100);

        $settlement = $this->createSubmittedSettlement(
            $purchaseReturn,
            $detail,
            2500,
            null,
            PurchaseReturnDetail::METHOD_BROKEN_STOCK
        );

        $response = $this->post(route('purchase-return-settlements.item.approve', $settlement->id));

        $response->assertSessionHas('error');
        $this->assertEquals(
            PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            $settlement->fresh()->status,
            'Methods other than MODIFY_PURCHASE must remain bounded by the return line subtotal.'
        );
    }
}
