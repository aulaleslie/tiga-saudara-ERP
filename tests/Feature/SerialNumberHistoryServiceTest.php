<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SerialNumberHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SerialNumberHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private ProductSerialNumber $serialNumber;

    protected function setUp(): void
    {
        parent::setUp();

        // Basic setup for testing
        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234567890',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        
        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => 1
        ]);

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TEST',
            'setting_id' => 1,
            'created_by' => $this->user->id
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_cost' => 5000000,
            'product_price' => 7000000,
            'category_id' => $category->id,
            'setting_id' => 1,
            'serial_number_required' => true
        ]);

        $this->serialNumber = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERVICE-TEST-001',
            'status' => 'active'
        ]);
    }

    /**
     * Test service creates history record correctly.
     */
    public function test_service_creates_history_record_correctly()
    {
        $history = SerialNumberHistoryService::record(
            $this->serialNumber->id,
            SerialNumberHistory::EVENT_RECEIVED,
            $this->location->id,
            null,
            'Test note'
        );

        $this->assertInstanceOf(SerialNumberHistory::class, $history);
        $this->assertEquals($this->serialNumber->id, $history->product_serial_number_id);
        $this->assertEquals(SerialNumberHistory::EVENT_RECEIVED, $history->event_type);
        $this->assertEquals($this->location->id, $history->location_id);
        $this->assertEquals($this->user->id, $history->user_id);
        $this->assertEquals('Test note', $history->note);
    }

    /**
     * Test service captures authenticated user automatically.
     */
    public function test_service_captures_authenticated_user_automatically()
    {
        $history = SerialNumberHistoryService::record(
            $this->serialNumber->id,
            SerialNumberHistory::EVENT_SOLD
        );

        $this->assertEquals($this->user->id, $history->user_id);
    }

    /**
     * Test service handles polymorphic reference.
     */
    public function test_service_handles_polymorphic_reference()
    {
        $history = SerialNumberHistoryService::record(
            $this->serialNumber->id,
            SerialNumberHistory::EVENT_RECEIVED,
            $this->location->id,
            $this->location,
            'Linked to location'
        );

        $this->assertEquals(Location::class, $history->reference_type);
        $this->assertEquals($this->location->id, $history->reference_id);
        $this->assertInstanceOf(Location::class, $history->reference);
    }

    /**
     * Test service works without reference.
     */
    public function test_service_works_without_reference()
    {
        $history = SerialNumberHistoryService::record(
            $this->serialNumber->id,
            SerialNumberHistory::EVENT_STATUS_CHANGED
        );

        $this->assertNull($history->reference_type);
        $this->assertNull($history->reference_id);
    }
}
