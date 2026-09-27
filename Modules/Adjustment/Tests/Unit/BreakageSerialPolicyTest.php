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

class BreakageSerialPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(bool $isPkp): Setting
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
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
    public function it_reports_a_conflict_when_the_same_serial_id_is_submitted_twice(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Gadget', 'product_code' => 'SKU-DUP',
            'product_quantity' => 5, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-DUP', 'tax_id' => $tax->id,
        ]);

        $classification = app(BreakageSerialPolicy::class)->classify(
            $product, $location, true, [$serial->id, $serial->id]
        );

        $this->assertNotEmpty($classification['conflicts']);
        $this->assertCount(1, $classification['eligible']);
    }
}
