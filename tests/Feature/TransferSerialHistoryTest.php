<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferSerialHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Currency $currency;
    private array $origin;
    private array $destination;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass permission checks
        Gate::define('stockTransfers.dispatch', fn() => true);
        Gate::define('stockTransfers.receive', fn() => true);
        Gate::define('stockTransfers.dispatchReturn', fn() => true);
        Gate::define('stockTransfers.receiveReturn', fn() => true);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->user = User::factory()->create();
        
        $this->origin = $this->createSettingWithLocation('Origin', 'origin@example.com');
        $this->destination = $this->createSettingWithLocation('Destination', 'destination@example.com');

        $category = Category::create([
            'category_name' => 'Electronics',
            'category_code' => 'ELEC',
            'setting_id' => $this->origin['setting']->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serialized Tablet',
            'product_code' => 'TAB01',
            'product_quantity' => 10,
            'product_cost' => 2000000,
            'product_price' => 3000000,
            'setting_id' => $this->origin['setting']->id,
            'serial_number_required' => true,
        ]);

        // Setup stock at origin
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin['location']->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    public function test_dispatch_shipment_creates_location_transfer_history(): void
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin['location']->id,
            'serial_number' => 'SN-TRANSFER-001',
            'status' => 'active',
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin['location']->id,
            'destination_location_id' => $this->destination['location']->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'created_by'              => $this->user->id,
        ]);

        $transferProduct = TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
            'serial_numbers' => [
                ['id' => $serial->id, 'serial_number' => $serial->serial_number]
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->origin['setting']->id])
            ->post(route('transfers.dispatch', $transfer));

        $response->assertSessionHasNoErrors();
        $this->assertEquals(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);

        // Verify history
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_LOCATION_TRANSFER,
            'location_id' => $this->destination['location']->id,
            'reference_type' => get_class($transfer),
            'reference_id' => $transfer->id,
        ]);

        $history = SerialNumberHistory::where('product_serial_number_id', $serial->id)
            ->where('event_type', SerialNumberHistory::EVENT_LOCATION_TRANSFER)
            ->first();

        $this->assertStringContainsStringIgnoringCase('Transfer dari Origin Location ke Destination Location', $history->note);
    }

    public function test_dispatch_return_creates_location_transfer_history(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not support return transfer statuses in enum constraints.');
        }

        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->destination['location']->id, // Already at destination
            'serial_number' => 'SN-RETURN-001',
            'status' => 'active',
        ]);

        // Setup stock at destination for return
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->destination['location']->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin['location']->id,
            'destination_location_id' => $this->destination['location']->id,
            'status'                  => Transfer::STATUS_RECEIVED,
            'created_by'              => $this->user->id,
        ]);

        $transferProduct = TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
            'serial_numbers' => [
                ['id' => $serial->id, 'serial_number' => $serial->serial_number]
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->destination['setting']->id])
            ->post(route('transfers.return-dispatch', $transfer));

        $response->assertSessionHasNoErrors();
        $this->assertEquals(Transfer::STATUS_RETURN_DISPATCHED, $transfer->fresh()->status);

        // Verify history
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_LOCATION_TRANSFER,
            'location_id' => $this->origin['location']->id,
            'reference_type' => get_class($transfer),
            'reference_id' => $transfer->id,
        ]);

        $history = SerialNumberHistory::where('product_serial_number_id', $serial->id)
            ->where('event_type', SerialNumberHistory::EVENT_LOCATION_TRANSFER)
            ->first();

        $this->assertStringContainsStringIgnoringCase('Return dari Destination Location ke Origin Location', $history->note);
    }

    private function createSettingWithLocation(string $name, string $email): array
    {
        $setting = Setting::create([
            'company_name'             => $name . ' Company',
            'company_email'            => $email,
            'company_phone'            => '1234567890',
            'default_currency_id'      => $this->currency->id,
            'default_currency_position'=> 'prefix',
            'notification_email'       => $email,
            'footer_text'              => 'Footer text',
            'company_address'          => '123 Street',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name'       => $name . ' Location',
        ]);

        return [
            'setting'  => $setting,
            'location' => $location,
        ];
    }
}
