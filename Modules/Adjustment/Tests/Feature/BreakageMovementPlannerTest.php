<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\BreakageMovementPlanner;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class BreakageMovementPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(bool $isPkp): Setting
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'RP',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        return Setting::create([
            'company_name' => 'CV Tiga Computer',
            'company_email' => 'ops@tiga.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'ops@tiga.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
            'is_pkp' => $isPkp,
        ]);
    }

    /** @test */
    public function it_plans_non_serialized_breakage_at_a_pkp_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Kabel',
            'product_code' => 'SKU-CBL',
            'product_quantity' => 10,
            'serial_number_required' => false,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 2,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(),
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'quantity_tax' => 3,
            'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]),
            'type' => 'sub',
        ]);

        $plan = app(BreakageMovementPlanner::class)->plan($adjustment->fresh());

        $this->assertTrue($plan['approvable']);
        $this->assertSame(10, $plan['products'][0]['current']['good']);
        $this->assertSame(2, $plan['products'][0]['current']['bad']);
        $this->assertSame(3, $plan['products'][0]['movement']);
        $this->assertSame(7, $plan['products'][0]['projected']['good']);
        $this->assertSame(5, $plan['products'][0]['projected']['bad']);
    }

    /** @test */
    public function it_blocks_when_requested_quantity_exceeds_good_stock(): void
    {
        $setting = $this->makeSetting(false);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Kabel',
            'product_code' => 'SKU-CBL2',
            'product_quantity' => 2,
            'serial_number_required' => false,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'quantity_tax' => 0,
            'quantity_non_tax' => 2,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(),
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'serial_numbers' => json_encode([]),
            'type' => 'sub',
        ]);

        $plan = app(BreakageMovementPlanner::class)->plan($adjustment->fresh());

        $this->assertFalse($plan['approvable']);
        $this->assertNotEmpty($plan['products'][0]['conflicts']);
    }

    /** @test */
    public function it_rejects_serial_from_another_location_and_wrong_pkp_bucket(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang A']);
        $otherLocation = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang B']);

        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Gadget',
            'product_code' => 'SKU-GDG',
            'product_quantity' => 10,
            'serial_number_required' => true,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $wrongLocationSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $otherLocation->id,
            'serial_number' => 'SN-WRONG-LOC',
            'tax_id' => $tax->id,
        ]);

        $wrongPkpSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'SN-WRONG-PKP',
            'tax_id' => null,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(),
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'quantity_tax' => 0,
            'quantity_non_tax' => 2,
            'serial_numbers' => json_encode([$wrongLocationSerial->id, $wrongPkpSerial->id]),
            'type' => 'sub',
        ]);

        $plan = app(BreakageMovementPlanner::class)->plan($adjustment->fresh());

        $this->assertFalse($plan['approvable']);
        $this->assertCount(2, $plan['products'][0]['serial_conflicts']);
        $this->assertEmpty($plan['products'][0]['serials']);
    }

    /** @test */
    public function it_accepts_eligible_good_serial_at_selected_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Gadget',
            'product_code' => 'SKU-GDG2',
            'product_quantity' => 5,
            'serial_number_required' => true,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'SN-OK',
            'tax_id' => $tax->id,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(),
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([$serial->id]),
            'type' => 'sub',
        ]);

        $plan = app(BreakageMovementPlanner::class)->plan($adjustment->fresh());

        $this->assertTrue($plan['approvable']);
        $this->assertSame(1, $plan['products'][0]['movement']);
        $this->assertSame(0, $plan['products'][0]['projected']['good']);
        $this->assertSame(1, $plan['products'][0]['projected']['bad']);
    }

    /** @test */
    public function it_reports_a_blocking_conflict_when_a_row_references_a_missing_product(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);

        $adjustment = Adjustment::create([
            'date' => now(),
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => 999999,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]),
            'type' => 'sub',
        ]);

        $plan = app(BreakageMovementPlanner::class)->plan($adjustment->fresh());

        $this->assertFalse($plan['approvable']);
        $this->assertNotEmpty($plan['conflicts']);
    }
}
