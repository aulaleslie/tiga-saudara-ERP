<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Services\BreakageSerialPolicy;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

/**
 * Task 5.3: Focused serial tests for breakage eligibility -- valid
 * selected-location good serials are accepted; unknown, duplicate,
 * wrong-product, wrong-location, inactive, dispatched, returning, broken,
 * and PKP-inconsistent serials are all rejected (design.md "Resolve scans
 * with a strict breakage policy" / BreakageSerialPolicy).
 */
class BreakageSerialPolicyEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(bool $isPkp): Setting
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        return Setting::create([
            'company_name' => 'CV Tiga Computer ' . uniqid(),
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

    private function makeProduct(Setting $setting, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $setting->id,
            'product_name' => 'Laptop',
            'product_code' => 'SKU-' . uniqid(),
            'product_quantity' => 5,
            'serial_number_required' => true,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ], $overrides));
    }

    /** @test */
    public function it_accepts_an_existing_good_serial_at_the_selected_product_and_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-OK', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertCount(1, $classification['eligible']);
        $this->assertEmpty($classification['serial_conflicts']);
        $this->assertEmpty($classification['conflicts']);
    }

    /** @test */
    public function it_rejects_an_unknown_serial_id(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [999999]);

        $this->assertEmpty($classification['eligible']);
        $this->assertCount(1, $classification['serial_conflicts']);
        $this->assertSame('Nomor seri tidak ditemukan.', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_a_duplicate_serial_id_in_the_same_request(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-DUP', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id, $serial->id]);

        $this->assertCount(1, $classification['eligible']);
        $this->assertNotEmpty($classification['conflicts']);
    }

    /** @test */
    public function it_rejects_a_serial_belonging_to_a_different_product(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);
        $otherProduct = $this->makeProduct($setting, ['product_code' => 'SKU-OTHER']);

        $serial = ProductSerialNumber::create([
            'product_id' => $otherProduct->id, 'location_id' => $location->id,
            'serial_number' => 'SN-WRONG-PRODUCT', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('bukan milik produk', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_a_serial_at_a_different_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang A']);
        $otherLocation = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang B']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $otherLocation->id,
            'serial_number' => 'SN-WRONG-LOC', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('lokasi lain', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_an_inactive_status_serial(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-SOLD', 'status' => 'SOLD', 'tax_id' => $tax->id,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('tidak tersedia', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_a_dispatched_serial(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-DISPATCHED', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
            'dispatch_detail_id' => 999,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('tidak tersedia', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_a_serial_currently_in_return_process(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-RETURNING', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
            'is_in_return_process' => true,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('tidak tersedia', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_an_already_broken_serial(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $product = $this->makeProduct($setting);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-BROKEN', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
            'is_broken' => true,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('tidak tersedia', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_rejects_a_serial_whose_tax_classification_disagrees_with_location_pkp(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang PKP']);
        $product = $this->makeProduct($setting);

        // Non-tax serial (tax_id null) at a PKP location.
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-NONTAX', 'status' => 'ACTIVE', 'tax_id' => null,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, [$serial->id]);

        $this->assertEmpty($classification['eligible']);
        $this->assertStringContainsString('klasifikasi pajak', $classification['serial_conflicts'][0]['reason']);
    }

    /** @test */
    public function it_requires_at_least_one_serial_for_a_serialized_product(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        $classification = app(BreakageSerialPolicy::class)->classify($product, $location, true, []);

        $this->assertEmpty($classification['eligible']);
        $this->assertNotEmpty($classification['conflicts']);
    }

    /** @test */
    public function scan_eligibility_check_mirrors_classify_for_a_single_serial(): void
    {
        $setting = $this->makeSetting(false);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang Non-PKP']);
        $product = $this->makeProduct($setting);

        $goodSerial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-SCAN-OK', 'status' => 'ACTIVE', 'tax_id' => null,
        ]);

        $brokenSerial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-SCAN-BROKEN', 'status' => 'ACTIVE', 'tax_id' => null,
            'is_broken' => true,
        ]);

        $policy = app(BreakageSerialPolicy::class);

        $this->assertTrue($policy->isEligibleForScan($goodSerial, $product, $location, false));
        $this->assertFalse($policy->isEligibleForScan($brokenSerial, $product, $location, false));
    }
}
