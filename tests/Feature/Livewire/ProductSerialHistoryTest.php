<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Product\ProductSerialHistoryTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductSerialHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $product;
    protected $setting;
    protected $location;
    protected $otherSetting;
    protected $otherLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create minimal setting
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'TestCo',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
        ]);

        $this->location = \Modules\Setting\Entities\Location::create([
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        $this->otherSetting = Setting::create([
            'company_name' => 'OtherCo',
            'company_email' => 'other@example.com',
            'company_phone' => '67890',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify-other@example.com',
            'footer_text' => 'Footer Other',
            'company_address' => 'Addr Other',
        ]);

        $this->otherLocation = \Modules\Setting\Entities\Location::create([
            'name' => 'Other Location',
            'setting_id' => $this->otherSetting->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST001',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 0,
            'product_price' => 1000.00,
            'serial_number_required' => true,
        ]);

        // Put setting_id in session for the component's getActiveSettingId
        session(['setting_id' => $this->setting->id]);
    }

    public function test_component_renders_and_shows_serials()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-TEST-001',
            'status' => 'active',
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->assertSee('SN-TEST-001')
            ->assertSee('ACTIVE');
    }

    public function test_can_search_serials()
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'UNIQUE-111',
            'status' => 'active',
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'OTHER-222',
            'status' => 'active',
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->set('searchQuery', 'UNIQUE')
            ->assertSee('UNIQUE-111')
            ->assertDontSee('OTHER-222');
    }

    public function test_can_expand_history()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-HIST-001',
            'status' => 'active',
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => 'RECEIVED',
            'user_id' => $this->user->id,
            'created_at' => now(),
            'location_id' => $this->location->id,
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->assertDontSee('Diterima dari Pembelian')
            ->call('toggleExpand', $serial->id)
            ->assertSee('Diterima dari Pembelian');
    }

    public function test_it_hides_serials_from_other_settings()
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-ACTIVE-SETTING',
            'status' => 'active',
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->otherLocation->id,
            'serial_number' => 'SN-OTHER-SETTING',
            'status' => 'active',
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->assertSee('SN-ACTIVE-SETTING')
            ->assertDontSee('SN-OTHER-SETTING');
    }

    public function test_it_filters_expanded_histories_to_active_setting()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-FILTER-HIST-001',
            'status' => 'active',
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'user_id' => $this->user->id,
            'created_at' => now()->subMinute(),
            'location_id' => $this->location->id,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_SOLD,
            'user_id' => $this->user->id,
            'created_at' => now(),
            'location_id' => $this->otherLocation->id,
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->call('toggleExpand', $serial->id)
            ->assertSee('Diterima dari Pembelian')
            ->assertDontSee('Terjual');
    }

    public function test_it_keeps_locationless_history_events_for_scoped_serial()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-NULL-LOC-HIST-001',
            'status' => 'active',
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_STATUS_CHANGED,
            'user_id' => $this->user->id,
            'created_at' => now(),
            'location_id' => null,
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->call('toggleExpand', $serial->id)
            ->assertSee('Perubahan Status');
    }

    public function test_it_keeps_search_working_after_setting_scope()
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'UNIQUE-A-111',
            'status' => 'active',
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->otherLocation->id,
            'serial_number' => 'UNIQUE-B-222',
            'status' => 'active',
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->set('searchQuery', 'UNIQUE')
            ->assertSee('UNIQUE-A-111')
            ->assertDontSee('UNIQUE-B-222');
    }

    public function test_purchase_return_dispatch_event_label_is_rendered()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-DISPATCH-RET-001',
            'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
            'is_in_return_process' => true,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURN_DISPATCHED,
            'user_id' => $this->user->id,
            'created_at' => now(),
            'location_id' => $this->location->id,
        ]);

        Livewire::test(ProductSerialHistoryTable::class, ['productId' => $this->product->id])
            ->call('toggleExpand', $serial->id)
            ->assertSee('Dikirim Retur ke Supplier');
    }
}
