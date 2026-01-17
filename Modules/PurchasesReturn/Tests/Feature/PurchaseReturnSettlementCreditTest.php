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

class PurchaseReturnSettlementCreditTest extends TestCase
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

    public function test_credit_purchases_contains_only_unpaid_purchases(): void
    {
        // 1. Create a Paid Purchase
        Purchase::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'supplier_name' =>'Supplier Test',
            'due_date' => now(),
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'reference' => 'PO-PAID',
        ]);

        // 2. Create an Unpaid Purchase
        $unpaidPurchase = Purchase::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting->id,
            'status' => 'RECEIVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'supplier_name' =>'Supplier Test',
            'due_date' => now(),
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'reference' => 'PO-UNPAID',
            'supplier_purchase_number' => 'SUPP-UNPAID-123',
        ]);

        // 3. Setup Purchase Return
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending Approval',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'approval_status' => 'approved',
            'reference' => 'PR-123',
        ]);

        $component = Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id]);
        
        $creditPurchases = $component->get('creditPurchases');
        
        $this->assertCount(1, $creditPurchases);
        $this->assertEquals($unpaidPurchase->id, $creditPurchases[0]['id']);
        $this->assertEquals('SUPP-UNPAID-123', $creditPurchases[0]['text']);
    }

    public function test_serial_item_identifies_origin_purchase_id(): void
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
            'serial_number' => 'SN-123',
            'status' => 'active',
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
            'status' => 'Pending Approval',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'approval_status' => 'approved',
            'reference' => 'PR-123',
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
            'serial_number_ids' => [$serial->id],
        ]);

        // 4. Test Livewire
        $component = Livewire::test(PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id]);
        
        $this->assertEquals($purchase->id, $component->get('settlementLines.0.origin_purchase_id'));
    }
}
