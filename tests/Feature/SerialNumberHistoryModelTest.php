<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SerialNumberHistoryModelTest extends TestCase
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
            'serial_number' => 'SERIAL-TEST-001',
            'status' => 'active'
        ]);
    }

    /**
     * Test history record creation and relationships.
     */
    public function test_can_create_history_record_and_verify_relationships()
    {
        $history = SerialNumberHistory::create([
            'product_serial_number_id' => $this->serialNumber->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'note' => 'Initial receipt',
            'reference_type' => Product::class,
            'reference_id' => 1,
        ]);

        // Verify relationships
        $this->assertInstanceOf(ProductSerialNumber::class, $history->serialNumber);
        $this->assertEquals($this->serialNumber->id, $history->serialNumber->id);

        $this->assertInstanceOf(Location::class, $history->location);
        $this->assertEquals($this->location->id, $history->location->id);

        $this->assertInstanceOf(User::class, $history->user);
        $this->assertEquals($this->user->id, $history->user->id);

        $this->assertEquals('Initial receipt', $history->note);
        $this->assertEquals(SerialNumberHistory::EVENT_RECEIVED, $history->event_type);
    }

    /**
     * Test inverse relationship on ProductSerialNumber.
     */
    public function test_serial_number_has_histories_relationship()
    {
        SerialNumberHistory::create([
            'product_serial_number_id' => $this->serialNumber->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $this->serialNumber->id,
            'event_type' => SerialNumberHistory::EVENT_SOLD,
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertCount(2, $this->serialNumber->histories);
        $this->assertInstanceOf(SerialNumberHistory::class, $this->serialNumber->histories->first());
    }

    /**
     * Test polymorphic reference relationship.
     */
    public function test_history_belongs_to_polymorphic_reference()
    {
        $history = SerialNumberHistory::create([
            'product_serial_number_id' => $this->serialNumber->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'user_id' => $this->user->id,
            'reference_type' => Location::class,
            'reference_id' => $this->location->id,
        ]);

        $this->assertInstanceOf(Location::class, $history->reference);
        $this->assertEquals($this->location->id, $history->reference->id);
    }
}
