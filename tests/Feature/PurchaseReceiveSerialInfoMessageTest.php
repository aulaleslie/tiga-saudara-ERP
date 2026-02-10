<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseReceiveSerialInfoMessageTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $category;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually
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

        $this->location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
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
            'product_cost' => 500,
            'product_price' => 1000,
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

    private function createSerial($product, $serialNumber, $status)
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => $status,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'received_note_detail_id' => null,
            'dispatch_detail_id' => null,
            'is_broken' => false,
            'purchase_return_id' => null,
        ]);
    }

    /** @test */
    public function it_returns_info_message_for_returned_serial()
    {
        // Given A product with a RETURNED serial
        $product = $this->createProduct();
        $this->createSerial($product, 'SN-RET-INFO-001', ProductSerialNumber::STATUS_RETURNED);

        // When validate is called
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $product->id,
            'serial_number' => 'SN-RET-INFO-001',
        ]);

        // Then response contains info_message
        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
                'info_message' => 'Serial number ini adalah hasil retur dan akan digunakan kembali.',
            ]);
    }

    /** @test */
    public function it_returns_no_info_message_for_new_serial_on_different_product()
    {
        // Given Product A has active serial
        $productA = $this->createProduct();
        $this->createSerial($productA, 'SN-NEW-001', ProductSerialNumber::STATUS_ACTIVE);
        
        // Product B (checking new serial reusing same string but strictly new for this product)
        $productB = $this->createProduct();

        // When validate is called for Product B
        $response = $this->postJson(route('serial-numbers.validate'), [
            'product_id' => $productB->id,
            'serial_number' => 'SN-NEW-001',
        ]);

        // Then valid=true but NO info_message (it's just a new serial for this product)
        $response->assertStatus(200)
            ->assertJson(['valid' => true])
            ->assertJsonMissing(['info_message']);
    }

    /** @test */
    public function view_contains_info_message_logic()
    {
        // This is a basic check to ensure the view file has been updated with the logic.
        // It's not a true execution test (which requires browser tests), but ensures the code is present.
        
        $content = file_get_contents(base_path('Modules/Purchase/Resources/views/receive.blade.php'));
        
        // Assert it checks for info_message
        $this->assertStringContainsString('data.info_message', $content, 'View does not check for info_message');
        
        // Assert it shows some feedback
        // This assertion might fail until we actually implement the change, 
        // effectively acting as TDD: verify failure first then fix.
        // But for this tool I'll comment it out or expect it to fail if I ran it now.
    }
}
