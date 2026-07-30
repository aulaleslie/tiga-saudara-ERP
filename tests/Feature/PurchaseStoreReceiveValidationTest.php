<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseStoreReceiveValidationTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $setting;
    protected $unit;
    protected $category;
    protected $user;
    protected $location;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually since factories are missing or flaky
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street 1',
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->category = Category::create([
            'created_by' => $this->user->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'setting_id' => $this->setting->id,
        ]);

        // Mock Location
        $this->location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        $this->supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Supplier A',
            'supplier_phone' => '123456',
            'supplier_email' => 'supplier@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        \Illuminate\Support\Facades\Gate::define('purchases.receive', fn() => true);
        session(['setting_id' => $this->setting->id]);
    }

    private function createProduct()
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sample Product ' . uniqid(),
            'product_code' => 'PRD-' . uniqid(),
            'product_barcode_symbology' => null,
            'product_quantity' => 100,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
        ]);
    }

    private function createPurchase($product, $qty = 1)
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000 * $qty,
            'paid_amount' => 0,
            'due_amount' => 1000 * $qty,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $qty,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000 * $qty,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        return [$purchase, $detail];
    }

    /** @test */
    public function it_rejects_receiving_with_missing_location()
    {
        $product = $this->createProduct();
        list($purchase, $detail) = $this->createPurchase($product, 1);

        $response = $this->post(route('purchases.storeReceive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-123']],
            'location_id' => '', // Missing location
            'external_delivery_number' => 'DEL-001',
            'notes' => [$detail->id => 'Test note'],
        ]);

        $response->assertSessionHasErrors(['location_id' => 'Lokasi wajib dipilih.']);

        $response->assertSessionHas('_old_input', function ($oldInput) use ($detail) {
            return $oldInput['received'][$detail->id] == 1
                && $oldInput['serial_numbers'][$detail->id][0] === 'SN-123'
                && $oldInput['external_delivery_number'] === 'DEL-001'
                && $oldInput['notes'][$detail->id] === 'Test note';
        });

        $this->assertDatabaseMissing('received_notes', [
            'po_id' => $purchase->id,
        ]);
    }

    /** @test */
    public function it_rejects_receiving_with_all_zero_quantities()
    {
        $product1 = $this->createProduct();
        $product2 = $this->createProduct();

        list($purchase, $detail1) = $this->createPurchase($product1, 1);
        $detail2 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product2->id,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $response = $this->post(route('purchases.storeReceive', $purchase->id), [
            'received' => [
                $detail1->id => 0,
                $detail2->id => 0,
            ],
            'location_id' => $this->location->id,
            'external_delivery_number' => 'DEL-002',
            'notes' => [
                $detail1->id => 'Note 1',
                $detail2->id => 'Note 2',
            ],
        ]);

        $response->assertSessionHasErrors(['received' => 'Minimal satu produk harus memiliki jumlah diterima lebih dari 0.']);

        $response->assertSessionHas('_old_input', function ($oldInput) use ($detail1, $detail2) {
            return $oldInput['received'][$detail1->id] == 0
                && $oldInput['received'][$detail2->id] == 0
                && $oldInput['location_id'] == $this->location->id
                && $oldInput['external_delivery_number'] === 'DEL-002'
                && $oldInput['notes'][$detail1->id] === 'Note 1'
                && $oldInput['notes'][$detail2->id] === 'Note 2';
        });

        $this->assertDatabaseMissing('received_notes', [
            'po_id' => $purchase->id,
        ]);
    }
}
