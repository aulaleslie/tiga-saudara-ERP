<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseApprovalPriceSyncTest extends TestCase
{
    use RefreshDatabase;

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
            'company_name' => 'Setting B',
            'company_email' => 'b@test.com',
            'company_phone' => '2',
            'notification_email' => 'b@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang A'
        ]);

        $this->user = User::factory()->create();
        Permission::findOrCreate('purchases.receive', 'web');
        Permission::findOrCreate('purchases.receive.approval', 'web');
        $this->user->givePermissionTo(['purchases.receive', 'purchases.receive.approval']);
        $this->actingAs($this->user);

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
            'supplier_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->settingA->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'unit_id' => 1,
            'setting_id' => $this->settingA->id,
        ]);
        
        // Seed price for setting A
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 0,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);
        
        session(['setting_id' => $this->settingA->id]);
    }

    public function test_purchase_approval_syncs_average_price_to_all_settings()
    {
        // Create a Purchase
        $purchase = Purchase::create([
            'reference' => 'PR-0001',
            'supplier_id' => 1,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'user_id' => $this->user->id,
        ]);

        // Detail: 10 qty at 10,000 DPP unit cost (subtotal = 100,000)
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 10,
            'price' => 10000, // Price per unit
            'unit_price' => 10000, // Unit price
            'sub_total' => 100000, // Total DPP
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'purchase_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 10,
            'po_detail_id' => $purchaseDetail->id,
            'purchase_detail_id' => $purchaseDetail->id,
        ]);

        // Receive is pending approval. Let's approve it.
        $approveResponse = $this->post(route('receivings.approve', $receivedNote->id));

        $approveResponse->assertSessionHasNoErrors();
        
        // Assert sync happened across both settings
        $prices = ProductPrice::where('product_id', $this->product->id)->get()->keyBy('setting_id');
        
        // Should have created prices for setting B because of sync
        $this->assertCount(2, $prices);
        
        // The DPP unit cost was 100,000 / 10 = 10,000. So average should be 10,000
        $this->assertEquals(10000.0, (float) $prices[$this->settingA->id]->average_purchase_price);
        $this->assertEquals(10000.0, (float) $prices[$this->settingB->id]->average_purchase_price);

        // Also last purchase price should be synced to 10000 across all settings
        $this->assertEquals(10000.0, (float) $prices[$this->settingA->id]->last_purchase_price);
        $this->assertEquals(10000.0, (float) $prices[$this->settingB->id]->last_purchase_price);
    }

    public function test_purchase_approval_syncs_last_purchase_price_and_preserves_unrelated_fields()
    {
        // Set specific non-zero selling price, tier prices, and tax IDs for setting A
        $tax1 = \Modules\Setting\Entities\Tax::firstOrCreate(['name' => 'Tax 1'], ['value' => 10]);
        $tax2 = \Modules\Setting\Entities\Tax::firstOrCreate(['name' => 'Tax 2'], ['value' => 11]);

        $pricesA = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->settingA->id)
            ->first();
        $pricesA->update([
            'sale_price' => 55000,
            'tier_1_price' => 50000,
            'tier_2_price' => 45000,
            'last_purchase_price' => 20000,
            'sale_tax_id' => $tax1->id,
            'purchase_tax_id' => $tax2->id,
        ]);

        // Create a Purchase with detail price = 35000
        $purchase = Purchase::create([
            'reference' => 'PR-0002',
            'supplier_id' => 1,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 175000,
            'paid_amount' => 0,
            'due_amount' => 175000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'user_id' => $this->user->id,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 5,
            'price' => 35000,
            'unit_price' => 35000,
            'sub_total' => 175000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'purchase_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 5,
            'po_detail_id' => $purchaseDetail->id,
            'purchase_detail_id' => $purchaseDetail->id,
        ]);

        $approveResponse = $this->post(route('receivings.approve', $receivedNote->id));
        $approveResponse->assertSessionHasNoErrors();

        $prices = ProductPrice::where('product_id', $this->product->id)->get()->keyBy('setting_id');
        $this->assertCount(2, $prices);

        // Setting A last purchase price updated to 35000
        $this->assertEquals(35000.0, (float) $prices[$this->settingA->id]->last_purchase_price);
        // Setting A selling, tier, and tax fields preserved
        $this->assertEquals(55000.0, (float) $prices[$this->settingA->id]->sale_price);
        $this->assertEquals(50000.0, (float) $prices[$this->settingA->id]->tier_1_price);
        $this->assertEquals(45000.0, (float) $prices[$this->settingA->id]->tier_2_price);
        $this->assertEquals($tax1->id, $prices[$this->settingA->id]->sale_tax_id);
        $this->assertEquals($tax2->id, $prices[$this->settingA->id]->purchase_tax_id);

        // Setting B missing row created with last_purchase_price = 35000
        $this->assertEquals(35000.0, (float) $prices[$this->settingB->id]->last_purchase_price);
    }

    public function test_multiple_received_products_synchronize_independently()
    {
        $product2 = Product::create([
            'product_name' => 'Second Product',
            'product_code' => 'TEST-02',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'unit_id' => 1,
            'setting_id' => $this->settingA->id,
        ]);

        $purchase = Purchase::create([
            'reference' => 'PR-0003',
            'supplier_id' => 1,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600000,
            'paid_amount' => 0,
            'due_amount' => 600000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'user_id' => $this->user->id,
        ]);

        $detail1 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 10,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $detail2 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product2->id,
            'product_name' => 'Second Product',
            'product_code' => 'TEST-02',
            'quantity' => 10,
            'price' => 45000,
            'unit_price' => 45000,
            'sub_total' => 450000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'purchase_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 10,
            'po_detail_id' => $detail1->id,
            'purchase_detail_id' => $detail1->id,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 10,
            'po_detail_id' => $detail2->id,
            'purchase_detail_id' => $detail2->id,
        ]);

        $approveResponse = $this->post(route('receivings.approve', $receivedNote->id));
        $approveResponse->assertSessionHasNoErrors();

        // Product 1 prices synced to 15000 across settings
        $prices1 = ProductPrice::where('product_id', $this->product->id)->get()->keyBy('setting_id');
        $this->assertEquals(15000.0, (float) $prices1[$this->settingA->id]->last_purchase_price);
        $this->assertEquals(15000.0, (float) $prices1[$this->settingB->id]->last_purchase_price);

        // Product 2 prices synced to 45000 across settings
        $prices2 = ProductPrice::where('product_id', $product2->id)->get()->keyBy('setting_id');
        $this->assertEquals(45000.0, (float) $prices2[$this->settingA->id]->last_purchase_price);
        $this->assertEquals(45000.0, (float) $prices2[$this->settingB->id]->last_purchase_price);
    }

    public function test_pending_receiving_or_zero_quantity_does_not_synchronize_last_purchase_price()
    {
        $purchase = Purchase::create([
            'reference' => 'PR-0004',
            'supplier_id' => 1,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 99000,
            'paid_amount' => 0,
            'due_amount' => 99000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'user_id' => $this->user->id,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'quantity' => 10,
            'price' => 9900,
            'unit_price' => 9900,
            'sub_total' => 99000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'purchase_id' => $purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 0, // Zero received
            'po_detail_id' => $purchaseDetail->id,
            'purchase_detail_id' => $purchaseDetail->id,
        ]);

        // While receiving is pending, last purchase price should remain 0
        $priceA = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->settingA->id)
            ->first();
        $this->assertEquals(0, (float) $priceA->last_purchase_price);

        // Approving note with 0 quantity received
        $approveResponse = $this->post(route('receivings.approve', $receivedNote->id));
        $approveResponse->assertSessionHasNoErrors();

        // Price remains unchanged because received quantity was 0
        $priceA = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->settingA->id)
            ->first();
        $this->assertEquals(0, (float) $priceA->last_purchase_price);
    }
}
