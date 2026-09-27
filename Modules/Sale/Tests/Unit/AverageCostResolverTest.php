<?php

namespace Modules\Sale\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Services\AverageCostResolver;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Tests\TestCase;

class AverageCostResolverTest extends TestCase
{
    use RefreshDatabase;

    protected AverageCostResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->resolver = new AverageCostResolver();
    }

    protected function makeSetting(bool $isPkp = false): Setting
    {
        return Setting::create([
            'company_name' => 'Setting ' . uniqid(),
            'company_email' => uniqid() . '@test.com',
            'company_phone' => '1',
            'notification_email' => uniqid() . '@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => $isPkp,
        ]);
    }

    protected function makeProduct(Setting $setting): Product
    {
        return Product::create([
            'product_name' => 'Product ' . uniqid(),
            'product_code' => 'P-' . uniqid(),
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'setting_id' => $setting->id,
            'stock_managed' => true,
        ]);
    }

    protected function setAveragePrice(Product $product, Setting $setting, $value): void
    {
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 0,
            'average_purchase_price' => $value,
        ]);
    }

    protected function makeLocation(Setting $owner): Location
    {
        return Location::create([
            'setting_id' => $owner->id,
            'name' => 'Location ' . uniqid(),
        ]);
    }

    /**
     * Wires $from -> can sell through $to's location, at the given position.
     */
    protected function linkSaleLocation(Setting $from, Location $toLocation, int $position): void
    {
        SettingSaleLocation::create([
            'setting_id' => $from->id,
            'location_id' => $toLocation->id,
            'is_enabled' => true,
            'position' => $position,
        ]);
    }

    public function test_owner_positive_average_wins_over_any_fallback(): void
    {
        $owner = $this->makeSetting();
        $product = $this->makeProduct($owner);
        $this->setAveragePrice($product, $owner, 500);

        $other = $this->makeSetting();
        $otherLocation = $this->makeLocation($other);
        $this->linkSaleLocation($owner, $otherLocation, 1);
        $this->setAveragePrice($product, $other, 999);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(500.0, $result['unit_cost']);
        $this->assertSame(AverageCostResolver::SOURCE_OWNER_AVERAGE_PRICE, $result['source']);
        $this->assertSame($owner->id, $result['setting_id']);
        $this->assertFalse($result['is_missing']);
    }

    public function test_falls_back_to_nearby_same_pkp_setting_when_owner_price_missing(): void
    {
        $owner = $this->makeSetting(false);
        $product = $this->makeProduct($owner);
        // Owner has no positive average price.

        $samePkp = $this->makeSetting(false);
        $samePkpLocation = $this->makeLocation($samePkp);
        $this->linkSaleLocation($owner, $samePkpLocation, 1);
        $this->setAveragePrice($product, $samePkp, 700);

        $oppositePkp = $this->makeSetting(true);
        $oppositeLocation = $this->makeLocation($oppositePkp);
        $this->linkSaleLocation($owner, $oppositeLocation, 2);
        $this->setAveragePrice($product, $oppositePkp, 800);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(700.0, $result['unit_cost']);
        $this->assertSame(AverageCostResolver::SOURCE_NEARBY_SAME_PKP_AVERAGE_PRICE, $result['source']);
        $this->assertSame($samePkp->id, $result['setting_id']);
        $this->assertFalse($result['setting_is_pkp']);
    }

    public function test_falls_back_to_nearby_opposite_pkp_setting_when_no_same_pkp_candidate_has_price(): void
    {
        $owner = $this->makeSetting(true);
        $product = $this->makeProduct($owner);

        $samePkpNoPrice = $this->makeSetting(true);
        $samePkpLocation = $this->makeLocation($samePkpNoPrice);
        $this->linkSaleLocation($owner, $samePkpLocation, 1);
        // No average price set for samePkpNoPrice.

        $oppositePkp = $this->makeSetting(false);
        $oppositeLocation = $this->makeLocation($oppositePkp);
        $this->linkSaleLocation($owner, $oppositeLocation, 2);
        $this->setAveragePrice($product, $oppositePkp, 650);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(650.0, $result['unit_cost']);
        $this->assertSame(AverageCostResolver::SOURCE_NEARBY_OPPOSITE_PKP_AVERAGE_PRICE, $result['source']);
        $this->assertSame($oppositePkp->id, $result['setting_id']);
        $this->assertFalse($result['setting_is_pkp']);
    }

    public function test_same_pkp_to_same_pkp_fallback_prefers_earliest_enabled_position(): void
    {
        $owner = $this->makeSetting(false);
        $product = $this->makeProduct($owner);

        $farther = $this->makeSetting(false);
        $farLocation = $this->makeLocation($farther);
        $this->linkSaleLocation($owner, $farLocation, 2);
        $this->setAveragePrice($product, $farther, 900);

        $closer = $this->makeSetting(false);
        $closeLocation = $this->makeLocation($closer);
        $this->linkSaleLocation($owner, $closeLocation, 1);
        $this->setAveragePrice($product, $closer, 400);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(400.0, $result['unit_cost']);
        $this->assertSame($closer->id, $result['setting_id']);
    }

    public function test_multiple_locations_owned_by_same_setting_collapse_to_earliest_position(): void
    {
        $owner = $this->makeSetting(false);
        $product = $this->makeProduct($owner);

        $nearby = $this->makeSetting(false);
        $locationA = $this->makeLocation($nearby);
        $locationB = $this->makeLocation($nearby);
        // Later position row for the same setting should not change its rank.
        $this->linkSaleLocation($owner, $locationB, 5);
        $this->linkSaleLocation($owner, $locationA, 1);
        $this->setAveragePrice($product, $nearby, 300);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(300.0, $result['unit_cost']);
        $this->assertSame($nearby->id, $result['setting_id']);
    }

    public function test_equal_positions_tie_break_by_setting_id(): void
    {
        $owner = $this->makeSetting(false);
        $product = $this->makeProduct($owner);

        $settingLow = $this->makeSetting(false);
        $settingHigh = $this->makeSetting(false);
        // Ensure settingLow has the lower id for a deterministic assertion.
        if ($settingLow->id > $settingHigh->id) {
            [$settingLow, $settingHigh] = [$settingHigh, $settingLow];
        }

        $this->linkSaleLocation($owner, $this->makeLocation($settingHigh), 1);
        $this->linkSaleLocation($owner, $this->makeLocation($settingLow), 1);

        $this->setAveragePrice($product, $settingLow, 111);
        $this->setAveragePrice($product, $settingHigh, 222);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(111.0, $result['unit_cost']);
        $this->assertSame($settingLow->id, $result['setting_id']);
    }

    public function test_falls_back_to_deterministic_remaining_settings_when_no_sale_location_configured(): void
    {
        $owner = $this->makeSetting(false);
        $product = $this->makeProduct($owner);

        $settingA = $this->makeSetting(false);
        $settingB = $this->makeSetting(false);
        if ($settingA->id > $settingB->id) {
            [$settingA, $settingB] = [$settingB, $settingA];
        }

        // No setting_sale_locations configured at all; both are "remaining" settings.
        $this->setAveragePrice($product, $settingB, 50);
        $this->setAveragePrice($product, $settingA, 75);

        $result = $this->resolver->resolve($product, $owner->id);

        // Remaining settings ordered by setting ID: settingA (lower id) wins.
        $this->assertSame(75.0, $result['unit_cost']);
        $this->assertSame($settingA->id, $result['setting_id']);
    }

    public function test_returns_missing_when_no_positive_candidate_exists_anywhere(): void
    {
        $owner = $this->makeSetting();
        $product = $this->makeProduct($owner);

        $other = $this->makeSetting();
        $this->setAveragePrice($product, $other, 0);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(0.0, $result['unit_cost']);
        $this->assertSame(AverageCostResolver::SOURCE_MISSING_AVERAGE_PRICE, $result['source']);
        $this->assertNull($result['setting_id']);
        $this->assertTrue($result['is_missing']);
    }

    public function test_zero_null_negative_and_blank_prices_are_excluded_as_candidates(): void
    {
        $owner = $this->makeSetting(false);
        $product = $this->makeProduct($owner);
        $this->setAveragePrice($product, $owner, 0);

        $zeroSetting = $this->makeSetting(false);
        $this->linkSaleLocation($owner, $this->makeLocation($zeroSetting), 1);
        $this->setAveragePrice($product, $zeroSetting, 0);

        $negativeSetting = $this->makeSetting(false);
        $this->linkSaleLocation($owner, $this->makeLocation($negativeSetting), 2);
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $negativeSetting->id,
            'sale_price' => 0,
            'average_purchase_price' => -10,
        ]);

        $validSetting = $this->makeSetting(false);
        $this->linkSaleLocation($owner, $this->makeLocation($validSetting), 3);
        $this->setAveragePrice($product, $validSetting, 42);

        $result = $this->resolver->resolve($product, $owner->id);

        $this->assertSame(42.0, $result['unit_cost']);
        $this->assertSame($validSetting->id, $result['setting_id']);
    }
}
