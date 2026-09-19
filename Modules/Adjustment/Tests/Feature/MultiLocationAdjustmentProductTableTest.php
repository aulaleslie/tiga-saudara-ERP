<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Adjustment\AdjustmentProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class MultiLocationAdjustmentProductTableTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;
    private Location $locA;
    private Location $locB;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::firstOrCreate(
            ['code' => 'IDR'],
            [
                'currency_name' => 'Rupiah',
                'symbol' => 'RP',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]
        );

        $this->settingA = Setting::create([
            'company_name' => 'Alpha Business',
            'company_email' => 'alpha@test.test',
            'company_phone' => '0800000001',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'alpha@test.test',
            'footer_text' => 'Footer A',
            'company_address' => 'Bandung',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Beta Business',
            'company_email' => 'beta@test.test',
            'company_phone' => '0800000002',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'beta@test.test',
            'footer_text' => 'Footer B',
            'company_address' => 'Jakarta',
            'is_pkp' => false,
        ]);

        session(['setting_id' => $this->settingA->id]);

        $this->locA = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Alpha',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->locB = Location::create([
            'setting_id' => $this->settingB->id,
            'name' => 'Gudang Beta',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Test Item',
            'product_code' => 'TI01',
            'barcode' => '888000111',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 20,
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locA->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locB->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 1,
        ]);
    }

    /** @test */
    public function scan_is_blocked_when_no_location_selected(): void
    {
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationIds' => [],
        ]);

        $component->call('processScan', '888000111');

        $this->assertEquals('warning', $component->get('feedbackType'));
        $this->assertStringContainsStringIgnoringCase('Pilih lokasi terlebih dahulu', (string) $component->get('feedbackMessage'));
        $this->assertEmpty($component->get('products'));
    }

    /** @test */
    public function scan_captures_per_location_baselines_across_selected_pool(): void
    {
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationIds' => [$this->locA->id, $this->locB->id],
        ]);

        $component->call('processScan', '888000111');

        $products = $component->get('products');
        $this->assertCount(1, $products);

        $row = $products[0];
        $this->assertEquals(1, $row['good_count']);
        $this->assertArrayHasKey('location_baselines', $row);
        $this->assertCount(2, $row['location_baselines']);

        // Check combined baseline in draft payload
        $payload = json_decode($component->get('countDraftPayload'), true);
        $this->assertEquals(2, $payload['schema_version']);
        $this->assertEquals([$this->locA->id, $this->locB->id], $payload['location_ids']);
        $this->assertArrayHasKey('location_set_fingerprint', $payload);
    }

    /** @test */
    public function changing_location_set_with_existing_products_prompts_confirmation(): void
    {
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationIds' => [$this->locA->id],
        ]);

        // Add a product
        $component->call('processScan', '888000111');
        $this->assertCount(1, $component->get('products'));

        // Attempt to change location pool
        $component->call('handleLocationsChange', [$this->locA->id, $this->locB->id]);

        // Confirmation modal should be shown, state not yet modified
        $this->assertTrue($component->get('showLocationConfirmModal'));
        $this->assertEquals([$this->locA->id], $component->get('locationIds'));
        $this->assertEquals([$this->locA->id, $this->locB->id], $component->get('pendingLocationIds'));
        $this->assertCount(1, $component->get('products'));

        // Cancelling should dismiss modal and keep products
        $component->call('cancelLocationChange');
        $this->assertFalse($component->get('showLocationConfirmModal'));
        $this->assertEquals([$this->locA->id], $component->get('locationIds'));
        $this->assertCount(1, $component->get('products'));

        // Trigger change again and confirm
        $component->call('handleLocationsChange', [$this->locB->id]);
        $this->assertTrue($component->get('showLocationConfirmModal'));

        $component->call('confirmLocationChange');
        $this->assertFalse($component->get('showLocationConfirmModal'));
        $this->assertEquals([$this->locB->id], $component->get('locationIds'));
        $this->assertEmpty($component->get('products'), 'Confirming location change must clear existing count state');
    }

    /** @test */
    public function tampered_location_identifiers_are_rejected(): void
    {
        $inactive = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Inactive Loc',
            'is_active' => false,
            'is_consignment' => false,
        ]);
        $consignment = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Consignment Loc',
            'is_active' => true,
            'is_consignment' => true,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationIds' => [$this->locA->id],
        ]);

        // Attempt to pass inactive location ID
        $component->call('handleLocationsChange', [$this->locA->id, $inactive->id]);
        $this->assertEquals('danger', $component->get('feedbackType'));
        $this->assertEquals([$this->locA->id], $component->get('locationIds'));

        // Attempt to pass consignment location ID
        $component->call('handleLocationsChange', [$consignment->id]);
        $this->assertEquals('danger', $component->get('feedbackType'));
        $this->assertEquals([$this->locA->id], $component->get('locationIds'));
    }

    /** @test */
    public function receives_locations_selected_event_with_named_parameters(): void
    {
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationIds' => [],
        ]);

        $component->dispatch('locationsSelected', name: 'location_ids', values: [$this->locA->id, $this->locB->id]);

        $this->assertEquals([$this->locA->id, $this->locB->id], $component->get('locationIds'));
    }
}
