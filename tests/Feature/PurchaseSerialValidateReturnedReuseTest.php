<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseSerialValidateReturnedReuseTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $setting;
    protected $unit;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually since factories are missing
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

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
            'created_by' => $user->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'setting_id' => $this->setting->id,
        ]);
        $this->actingAs($user);
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
            'product_cost' => 500, // 5.00 * 100
            'product_price' => 1000, // 10.00 * 100
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'sale_price' => 1000,
            'tier_1_price' => 1000,
            'tier_2_price' => 1000,
        ]);
    }

    private function createSerial($product, $serialNumber, $status, $isInReturnProcess = false)
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => $status,
            'is_in_return_process' => $isInReturnProcess,
            // Add other required fields with defaults to avoid sql errors
            'location_id' => null, // Assuming nullable
            'tax_id' => null,
            'received_note_detail_id' => null,
            'dispatch_detail_id' => null,
            'is_broken' => false,
            'purchase_return_id' => null,
        ]);
    }

    /** @test */
    public function it_allows_returned_serial_reuse_with_info_message()
    {
        // Given A product with a RETURNED serial
        $product = $this->createProduct();
        $this->createSerial($product, 'SN-RET-001', ProductSerialNumber::STATUS_RETURNED, false);

        // When validate is called
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $product->id,
            'serial_number' => 'SN-RET-001',
        ]);

        // Then response is valid=true and contains info_message
        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
                // We expect some info message like "Serial number ini adalah hasil retur..."
            ]);
        
        $this->assertArrayHasKey('info_message', $response->json());
    }

    /** @test */
    public function it_blocks_return_in_process_serial()
    {
        // Given A product with a RETURN_IN_PROCESS serial
        $product = $this->createProduct();
        $this->createSerial($product, 'SN-PROC-001', ProductSerialNumber::STATUS_RETURN_IN_PROCESS, true);

        // When validate is called
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $product->id,
            'serial_number' => 'SN-PROC-001',
        ]);

        // Then response is valid=false with explicit return-process message
        $response->assertStatus(200)
            ->assertJson([
                'valid' => false,
                'message' => 'Serial number sedang dalam proses retur.',
            ]);
    }

    /** @test */
    public function it_blocks_active_serial_as_duplicate()
    {
        // Given A product with an ACTIVE serial
        $product = $this->createProduct();
        $this->createSerial($product, 'SN-ACT-001', ProductSerialNumber::STATUS_ACTIVE, false);

        // When validate is called
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $product->id,
            'serial_number' => 'SN-ACT-001',
        ]);

        // Then response is valid=false with duplicate message
        $response->assertStatus(200)
            ->assertJson([
                'valid' => false,
                'message' => 'Serial number sudah ada untuk produk ini.',
            ]);
    }

    /** @test */
    public function it_allows_active_serial_on_different_product()
    {
        // Given Product A has active serial 'SN-123'
        $productA = $this->createProduct();
        $this->createSerial($productA, 'SN-123', ProductSerialNumber::STATUS_ACTIVE);

        // And we are validating for Product B
        $productB = $this->createProduct();

        // When validate is called for Product B with 'SN-123'
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $productB->id,
            'serial_number' => 'SN-123',
        ]);

        // Then response is valid=true
        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
            ]);
    }

    /** @test */
    public function it_ignores_pending_serials_in_other_receivings()
    {
        // Given 'SN-PENDING-001' is in a pending receiving for the same product
        $product = $this->createProduct();
        
        // We need to create Note and Detail manually too since they likely don't have factories
        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'reference' => 'RN-001',
            'status' => ReceivedNote::STATUS_PENDING,
            'user_id' => 1,
            'setting_id' => $this->setting->id,
            'document_path' => null,
            'purchase_id' => 1, // dummy
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => 1, // dummy
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'purchase_detail_id' => $purchaseDetail->id,
            'item_type' => 'product', // Assuming standard setup
            'product_id' => $product->id,
            'quantity' => 1,
            'pending_serial_numbers' => ['SN-PENDING-001'],
        ]);

        // When validate is called for 'SN-PENDING-001'
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $product->id,
            'serial_number' => 'SN-PENDING-001',
        ]);

        // Then response is valid=true (Cross-pending check removed)
        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
            ]);
    }
}
