<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseReceivingPerSettingQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected $settingA;
    protected $settingB;
    protected $user;
    protected $locationA1;
    protected $locationA2;
    protected $locationB1;

    protected function setUp(): void
    {
        parent::setUp();
        
        Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'id' => 1,
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '1',
            'notification_email' => 'a@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->settingB = Setting::create([
            'id' => 2,
            'company_name' => 'Setting B',
            'company_email' => 'b@test.com',
            'company_phone' => '2',
            'notification_email' => 'b@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        
        $this->user = User::factory()->create();
        Permission::findOrCreate('purchases.receive', 'web');
        Permission::findOrCreate('purchases.receive.approval', 'web');
        $this->user->givePermissionTo(['purchases.receive', 'purchases.receive.approval']);
        
        $this->actingAs($this->user);

        // Required for some models
        Category::create([
            'id' => 1,
            'category_code' => 'CAT01',
            'category_name' => 'Test Category',
            'setting_id' => $this->settingA->id,
            'created_by' => $this->user->id,
        ]);

        Unit::create([
            'id' => 1,
            'operator' => '*',
            'operation_value' => 1,
            'short_name' => 'pc',
            'name' => 'Piece',
            'setting_id' => $this->settingA->id,
        ]);

        \Modules\People\Entities\Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->settingA->id,
        ]);

        $this->locationA1 = Location::create(['id' => 1, 'name' => 'Loc A1', 'setting_id' => $this->settingA->id]);
        $this->locationA2 = Location::create(['id' => 2, 'name' => 'Loc A2', 'setting_id' => $this->settingA->id]);
        $this->locationB1 = Location::create(['id' => 3, 'name' => 'Loc B1', 'setting_id' => $this->settingB->id]);
    }

    public function test_purchase_receiving_records_per_setting_quantity_in_transaction_log()
    {
        // Scenario: Multi-location setting receives stock
        // Setting A has L1 (30 units) and L2 (20 units). Global has Setting B (40 units).
        // Total global = 90. Total Setting A = 50.
        
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 90, // Global quantity
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => 1,
            'stock_managed' => 1,
        ]);

        ProductStock::create(['product_id' => $product->id, 'location_id' => $this->locationA1->id, 'quantity' => 30, 'quantity_tax' => 0, 'quantity_non_tax' => 30, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);
        ProductStock::create(['product_id' => $product->id, 'location_id' => $this->locationA2->id, 'quantity' => 20, 'quantity_tax' => 0, 'quantity_non_tax' => 20, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);
        ProductStock::create(['product_id' => $product->id, 'location_id' => $this->locationB1->id, 'quantity' => 40, 'quantity_tax' => 0, 'quantity_non_tax' => 40, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);

        // Create Purchase for Setting A
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-A-001',
            'supplier_id' => 1,
            'supplier_name' => 'Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->settingA->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Receiving into Location A1
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->locationA1->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 10,
            'po_detail_id' => $detail->id,
        ]);

        // Set session setting_id to A (to match purchase)
        session(['setting_id' => $this->settingA->id]);

        // Approve Receiving
        $response = $this->post(route('receivings.approve', $receivedNote->id));
        $response->assertSessionHasNoErrors();

        // Assertions
        $product->refresh();
        $this->assertEquals(100, $product->product_quantity); // 90 + 10 global

        $transaction = Transaction::where('product_id', $product->id)
            ->where('reason', 'like', '%Diterima dari Pembelian%')
            ->first();

        $this->assertNotNull($transaction);
        // Setting A had 30 + 20 = 50.
        $this->assertEquals(50, $transaction->previous_quantity);
        $this->assertEquals(60, $transaction->after_quantity);
        $this->assertEquals(60, $transaction->current_quantity);
        
        // Per-location fields should still use location stock (30)
        $this->assertEquals(30, $transaction->previous_quantity_at_location);
        $this->assertEquals(40, $transaction->after_quantity_at_location);
    }

    public function test_purchase_receiving_with_zero_prior_stock_in_setting()
    {
        // Scenario: Product has 0 stock in Setting A, but 40 in Setting B.
        // Total global = 40. Total Setting A = 0.
        
        $product = Product::create([
            'product_name' => 'Test Product 2',
            'product_code' => 'TP002',
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 40,
            'setting_id' => $this->settingA->id,
            'category_id' => 1,
            'unit_id' => 1,
            'stock_managed' => 1,
        ]);

        // No stock in A1, A2
        ProductStock::create(['product_id' => $product->id, 'location_id' => $this->locationB1->id, 'quantity' => 40, 'quantity_tax' => 0, 'quantity_non_tax' => 40, 'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-A-002',
            'supplier_id' => 1,
            'supplier_name' => 'Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->settingA->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 15,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->locationA1->id,
            'status' => ReceivedNote::STATUS_PENDING,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 15,
            'po_detail_id' => $detail->id,
        ]);

        session(['setting_id' => $this->settingA->id]);

        $response = $this->post(route('receivings.approve', $receivedNote->id));
        $response->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertEquals(55, $product->product_quantity); // 40 + 15 global

        $transaction = Transaction::where('product_id', $product->id)
            ->where('reason', 'like', '%Diterima dari Pembelian%')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertEquals(0, $transaction->previous_quantity);
        $this->assertEquals(15, $transaction->after_quantity);
        $this->assertEquals(15, $transaction->current_quantity);
    }
}
