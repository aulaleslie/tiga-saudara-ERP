<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Product\Entities\Product;
use App\Models\User;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Livewire\Livewire;
use App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm;

class PurchaseReturnSettlementPhase4Test extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $product;
    protected $location;
    protected $purchaseReturn;
    protected $detail;

    protected function setUp(): void
    {
        parent::setUp();
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

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
            'supplier_name' => 'Supplier Test',
            'supplier_phone' => '123',
            'supplier_email' => 'test@supplier.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT',
            'category_name' => 'Test Category',
            'created_by' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 0,
            'serial_number_required' => false,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        $this->purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST-4',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by' => 1,
        ]);

        $this->detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
    }

    public function test_user_with_view_price_can_see_settlement_values()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturns.viewPrice');
        $this->user->givePermissionTo(['purchaseReturnSettlements.submit', 'purchaseReturns.viewPrice']);

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->set('settlementLines.0.method', 'CREDIT')
            ->assertSee('Nilai Penyelesaian')
            ->assertSee('type="text"', false);
    }

    public function test_user_without_view_price_cannot_see_settlement_values()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        $this->user->givePermissionTo(['purchaseReturnSettlements.submit']);

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertDontSee('Nilai Penyelesaian')
            ->assertDontSee('type="text"');
    }

    public function test_settlement_nominal_cannot_exceed_item_value()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        $this->user->givePermissionTo(['purchaseReturnSettlements.submit']);

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->set('settlementLines.0.method', 'CREDIT')
            ->set('settlementLines.0.target_purchase_id', 999) // Non-existent but triggers max check before exists? Actually exists might trigger first.
            ->set('settlementLines.0.nominal', 1200) // Exceeds 1000
            ->call('submit')
            ->assertHasErrors(['settlementLines.0.nominal' => 'max']);
    }

    public function test_nominal_value_is_saved_correctly()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        $this->user->givePermissionTo(['purchaseReturnSettlements.submit']);

        // Create a purchase to satisfy exists rule
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now(),
            'reference' => 'PO-1',
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'due_date' => now(),
            'due_amount' => 1000,
            'paid_amount' => 0,
            'total_amount' => 1000,
        ]);

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->set('settlementLines.0.method', 'CREDIT')
            ->set('settlementLines.0.target_purchase_id', $purchase->id)
            ->set('settlementLines.0.nominal', 800)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchase-returns.show', $this->purchaseReturn->id));

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'purchase_return_id' => $this->purchaseReturn->id,
            'method' => 'CREDIT',
            'nominal' => 800,
        ]);
    }

    public function test_settlement_creation_respects_submit_permission()
    {
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.submit');
        // Do NOT give permission

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertForbidden();
    }

    public function test_roll_up_status_shows_awaiting_when_no_items()
    {
        // No settlement items created yet
        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $this->purchaseReturn->fresh()->settlement_status);
    }

    public function test_roll_up_status_shows_partial_when_some_approved()
    {
        // Create two settlement items, one approved, one draft
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->detail->id,
            'method' => 'CASH',
            'nominal' => 500,
            'status' => 'APPROVED',
        ]);
        
        // Create another detail for a second settlement item
        $detail2 = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $detail2->id,
            'method' => null,
            'nominal' => 0,
            'status' => 'DRAFT',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_PARTIAL_SETTLEMENT, $this->purchaseReturn->fresh()->settlement_status);
    }

    public function test_roll_up_status_shows_settled_when_all_approved()
    {
        // Create one settlement item as approved
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->detail->id,
            'method' => 'CREDIT',
            'nominal' => 1000,
            'status' => 'APPROVED',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $this->purchaseReturn->fresh()->settlement_status);
    }

    public function test_roll_up_status_shows_awaiting_when_submitted_but_not_approved()
    {
        // Create one settlement item as submitted
        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        // Awaiting Settlement until there are approved items
        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $this->purchaseReturn->fresh()->settlement_status);
    }
}

