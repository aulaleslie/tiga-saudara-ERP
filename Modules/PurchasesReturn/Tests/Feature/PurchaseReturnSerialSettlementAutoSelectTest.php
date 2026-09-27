<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm;

class PurchaseReturnSerialSettlementAutoSelectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Tenant A',
            'company_email'             => 'tenant_a@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'tenant_a@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        
        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Test',
            'supplier_phone' => '123',
            'supplier_email' => 'test@supplier.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);
        
        $this->location = Location::create([
            'name' => 'Location 1',
            'setting_id' => $this->setting->id
        ]);

        Gate::define('purchaseReturnSettlements.submit', fn() => true);
    }

    public function test_auto_selects_purchase_for_serial_numbered_item_on_modify_purchase_method(): void
    {
        // 1. Setup Product
        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SP01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => true,
        ]);

        // 2. Setup Purchase and Receiving
        $purchase = Purchase::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'supplier_name' =>'Supplier Test',
            'due_date' => now(),
            'total_amount' => 10000,
            'paid_amount' => 5000,
            'due_amount' => 5000,
            'reference' => 'PO-ABC-123',
            'supplier_purchase_number' => 'SUPP-ABC-123',
        ]);

        $pod = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
        ]);

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => 'APPROVED',
        ]);

        $rnd = ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $pod->id,
            'quantity_received' => 1,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-AUTOSELECT-123',
            'status' => 'ACTIVE',
            'received_note_detail_id' => $rnd->id,
        ]);

        // 3. Setup Purchase Return
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => PurchaseReturn::STATUS_PENDING_APPROVAL,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'reference' => 'PR-123',
            'return_dispatch_status' => 'dispatched',
        ]);

        $prd = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $purchase->id,
            'serial_number_ids' => [$serial->id],
        ]);

        // 4. Test Livewire
        $component = Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id]);
        
        $component->assertSet('settlementLines.0.serial_number', 'SN-AUTOSELECT-123')
            ->set('settlementLines.0.method', 'MODIFY_PURCHASE')
            ->assertSet('settlementLines.0.target_purchase_id', $purchase->id);
            
        // Check if unpaidPurchases for this product contains the purchase reference (priority: supplier_purchase_number)
        $unpaid = $component->get('unpaidPurchases');
        $this->assertArrayHasKey($product->id, $unpaid);
        $this->assertEquals('SUPP-ABC-123', $unpaid[$product->id]['MODIFY_PURCHASE'][0]['text']);
    }

    public function test_non_serial_product_filters_purchases_by_product_id(): void
    {
        // 1. Setup two products
        $product1 = Product::create([
            'product_name' => 'Prod 1',
            'product_code' => 'P1',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => false,
        ]);
        $product2 = Product::create([
            'product_name' => 'Prod 2',
            'product_code' => 'P2',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => false,
        ]);

        // 2. Setup Purchase for Product 1 only
        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'supplier_name' =>'Supplier Test',
            'due_date' => now(),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'reference' => 'PO-1',
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchase1->id,
            'product_id' => $product1->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => 'Prod 1',
            'product_code' => 'P1',
        ]);

        // 3. Setup Purchase Return for both products
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => PurchaseReturn::STATUS_PENDING_APPROVAL,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'reference' => 'PR-456',
            'return_dispatch_status' => 'dispatched',
        ]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product1->id,
            'product_name' => 'Prod 1',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $purchase1->id,
        ]);
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product2->id,
            'product_name' => 'Prod 2',
            'product_code' => 'P2',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $component = Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id]);
        $unpaid = $component->get('unpaidPurchases');

        // Product 1 should have purchase1
        $this->assertCount(1, $unpaid[$product1->id]['MODIFY_PURCHASE']);
        $this->assertEquals($purchase1->id, $unpaid[$product1->id]['MODIFY_PURCHASE'][0]['id']);

        // Product 2 should have no purchases
        $this->assertEmpty($unpaid[$product2->id]['MODIFY_PURCHASE']);
    }

    public function test_serial_modify_purchase_does_not_auto_select_when_detail_purchase_is_missing(): void
    {
        $category = Category::create([
            'category_name' => 'Test Category 2',
            'category_code' => 'TC02',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serial Product B',
            'product_code' => 'SP02',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => true,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'supplier_name' => 'Supplier Test',
            'due_date' => now(),
            'total_amount' => 10000,
            'paid_amount' => 5000,
            'due_amount' => 5000,
            'reference' => 'PO-NO-DETAIL-CONTEXT',
            'supplier_purchase_number' => 'SUPP-NO-DETAIL-CONTEXT',
        ]);

        $pod = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
        ]);

        $rn = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => 'APPROVED',
        ]);

        $rnd = ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $pod->id,
            'quantity_received' => 1,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-NO-DETAIL-CONTEXT',
            'status' => 'ACTIVE',
            'received_note_detail_id' => $rnd->id,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => PurchaseReturn::STATUS_PENDING_APPROVAL,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'reference' => 'PR-NO-DETAIL-CONTEXT',
            'return_dispatch_status' => 'dispatched',
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => null,
            'serial_number_ids' => [$serial->id],
        ]);

        Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('settlementLines.0.serial_number', 'SN-NO-DETAIL-CONTEXT')
            ->set('settlementLines.0.method', 'MODIFY_PURCHASE')
            ->assertSet('settlementLines.0.target_purchase_id', null);
    }
}
