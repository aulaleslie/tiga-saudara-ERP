<?php

namespace Modules\Pos\Tests\Unit;

use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\PosCheckoutSplitPlannerService;
use Modules\Pos\Services\PosNonStockSourceResolverService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

/**
 * Non-stock POS content takes its financial and audit ownership from the first enabled
 * entry of the configured POS sales-location order, never from the terminal setting and
 * never filtered by PKP status.
 */
class PosNonStockSourceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_non_stock_parent_uses_first_configured_location_owner(): void
    {
        [$terminalSetting, $otherSetting, $terminalLocation, $otherLocation] = $this->createTwoBusinessSources();

        // Put the OTHER business's location first in the configured order.
        $this->orderSources($terminalSetting, [$otherLocation->id => 1, $terminalLocation->id => 2]);

        $groups = $this->planNonStockLine($terminalSetting->id);

        $this->assertCount(1, $groups);
        $this->assertSame($otherSetting->id, $groups[0]['source_setting_id'], 'Non-stock ownership must follow the configured first source, not the terminal business.');
        $this->assertSame($otherLocation->id, $groups[0]['source_location_id']);
        $this->assertNotSame($terminalSetting->id, $groups[0]['source_setting_id']);
    }

    public function test_reordering_configured_locations_changes_future_non_stock_ownership(): void
    {
        [$terminalSetting, $otherSetting, $terminalLocation, $otherLocation] = $this->createTwoBusinessSources();

        $this->orderSources($terminalSetting, [$otherLocation->id => 1, $terminalLocation->id => 2]);
        $before = $this->planNonStockLine($terminalSetting->id);
        $this->assertSame($otherLocation->id, $before[0]['source_location_id']);

        // Reorder so the terminal-owned location is first.
        $this->orderSources($terminalSetting, [$terminalLocation->id => 1, $otherLocation->id => 2]);
        $after = $this->planNonStockLine($terminalSetting->id);

        $this->assertSame($terminalLocation->id, $after[0]['source_location_id']);
        $this->assertSame($terminalSetting->id, $after[0]['source_setting_id']);
    }

    public function test_first_configured_source_is_used_even_when_pkp_and_tax_follows_that_source(): void
    {
        $defaultTax = Tax::query()->create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        [$terminalSetting, $otherSetting, $terminalLocation, $otherLocation] = $this->createTwoBusinessSources();

        // The first configured source is PKP; the old rule would have skipped it.
        $otherSetting->update(['is_pkp' => true]);
        $this->orderSources($terminalSetting, [$otherLocation->id => 1, $terminalLocation->id => 2]);

        $groups = $this->planNonStockLine($terminalSetting->id);

        $this->assertCount(1, $groups);
        $this->assertSame($otherSetting->id, $groups[0]['source_setting_id']);
        $this->assertSame('TAX:' . $defaultTax->id, $groups[0]['tax_bucket'], 'Tax bucket must follow the resolved source owner PKP policy.');
    }

    public function test_non_pkp_first_configured_source_yields_non_tax_bucket(): void
    {
        [$terminalSetting, $otherSetting, $terminalLocation, $otherLocation] = $this->createTwoBusinessSources();

        $otherSetting->update(['is_pkp' => false]);
        $this->orderSources($terminalSetting, [$otherLocation->id => 1, $terminalLocation->id => 2]);

        $groups = $this->planNonStockLine($terminalSetting->id);

        $this->assertSame('NON_TAX', $groups[0]['tax_bucket']);
        $this->assertNull($groups[0]['lines'][0]['tax_id']);
    }

    public function test_missing_configured_source_fails_with_actionable_validation_error(): void
    {
        $setting = $this->createSetting('NO SOURCE BIZ');
        $location = Location::create(['name' => 'Gudang Utama', 'setting_id' => $setting->id]);

        // Disable every configured sales location for this setting.
        SettingSaleLocation::query()->where('setting_id', $setting->id)->update(['is_enabled' => false]);
        SalesLocationResolver::forget($setting->id);

        $this->assertNull((new PosNonStockSourceResolverService())->resolve($setting->id));

        $this->expectException(PosCheckoutValidationException::class);
        $this->expectExceptionMessageMatches('/lokasi penjualan POS aktif/i');

        $this->planNonStockLine($setting->id);
    }

    public function test_configured_first_location_that_no_longer_resolves_returns_null_not_terminal_setting(): void
    {
        $terminalSetting = $this->createSetting('DANGLING SOURCE BIZ');
        $location = Location::create(['name' => 'Dangling Loc', 'setting_id' => $terminalSetting->id]);

        $this->orderSources($terminalSetting, [$location->id => 1]);

        // The configured entry now points at a location row that no longer exists.
        \Illuminate\Support\Facades\DB::table('locations')->where('id', $location->id)->delete();
        SalesLocationResolver::forget($terminalSetting->id);

        $resolved = (new PosNonStockSourceResolverService())->resolve($terminalSetting->id);

        $this->assertNull($resolved, 'An unusable configured source must not silently fall back to the terminal setting.');
    }

    public function test_dangling_configured_source_surfaces_actionable_validation_error(): void
    {
        $terminalSetting = $this->createSetting('DANGLING PLANNER BIZ');
        $location = Location::create(['name' => 'Dangling Planner Loc', 'setting_id' => $terminalSetting->id]);

        $this->orderSources($terminalSetting, [$location->id => 1]);

        \Illuminate\Support\Facades\DB::table('locations')->where('id', $location->id)->delete();
        SalesLocationResolver::forget($terminalSetting->id);

        // The planner turns an unusable source into the actionable configuration error
        // rather than posting under the terminal business.
        $this->expectException(PosCheckoutValidationException::class);
        $this->expectExceptionMessageMatches('/lokasi penjualan POS aktif/i');
        $this->planNonStockLine($terminalSetting->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planNonStockLine(int $terminalSettingId): array
    {
        $planner = new PosCheckoutSplitPlannerService(new PosNonStockSourceResolverService());

        $plan = $planner->plan([
            'setting_id' => $terminalSettingId,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 501,
                        'product_name' => 'Jasa Instalasi',
                        'product_code' => 'SRV-501',
                        'qty' => 2,
                        'unit_price' => 100,
                        'tax_id' => null,
                        'tax_rate' => 0,
                        'stock_managed' => false,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 200,
                    ],
                ],
            ],
            'allocations' => [],
        ]);

        return $plan['groups'];
    }

    /**
     * @return array{0:Setting,1:Setting,2:Location,3:Location}
     */
    private function createTwoBusinessSources(): array
    {
        $terminalSetting = $this->createSetting('TERMINAL BIZ');
        $otherSetting = $this->createSetting('OTHER BIZ');

        $terminalLocation = Location::create(['name' => 'Terminal Loc', 'setting_id' => $terminalSetting->id]);
        $otherLocation = Location::create(['name' => 'Other Loc', 'setting_id' => $otherSetting->id]);

        // The other business's location is borrowable by the terminal setting.
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $terminalSetting->id, 'location_id' => $otherLocation->id],
            ['is_enabled' => true, 'position' => 99]
        );

        return [$terminalSetting, $otherSetting, $terminalLocation, $otherLocation];
    }

    /**
     * @param  array<int, int>  $positionsByLocationId
     */
    private function orderSources(Setting $setting, array $positionsByLocationId): void
    {
        foreach ($positionsByLocationId as $locationId => $position) {
            SettingSaleLocation::updateOrCreate(
                ['setting_id' => $setting->id, 'location_id' => $locationId],
                ['is_enabled' => true, 'position' => $position]
            );
        }

        // Any location not named above must not compete for first place.
        SettingSaleLocation::query()
            ->where('setting_id', $setting->id)
            ->whereNotIn('location_id', array_keys($positionsByLocationId))
            ->update(['is_enabled' => false]);

        SalesLocationResolver::forget($setting->id);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id') ?? Currency::factory()->create()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }
}
