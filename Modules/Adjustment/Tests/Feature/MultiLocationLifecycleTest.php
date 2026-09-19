<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Adjustment\Services\StockOpnameLifecycleService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MultiLocationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $settingA;
    protected Setting $settingB;
    protected Location $locA;
    protected Location $locB;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Company A',
            'company_email' => 'a@company.com',
            'company_phone' => '111',
            'notification_email' => 'a@company.com',
            'company_address' => 'Jakarta',
            'footer_text' => 'Footer A',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Company B',
            'company_email' => 'b@company.com',
            'company_phone' => '222',
            'notification_email' => 'b@company.com',
            'company_address' => 'Surabaya',
            'footer_text' => 'Footer B',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->locA = Location::create(['name' => 'Gudang A', 'setting_id' => $this->settingA->id, 'is_active' => true, 'is_consignment' => false]);
        $this->locB = Location::create(['name' => 'Gudang B', 'setting_id' => $this->settingB->id, 'is_active' => true, 'is_consignment' => false]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1, 'is_active' => true]);

        $this->product = Product::create([
            'product_name' => 'Produk Multi',
            'product_code' => 'PM-' . uniqid(),
            'product_cost' => 10000,
            'product_price' => 20000,
            'setting_id' => $this->settingA->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => false,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
        Permission::findOrCreate('adjustments.create', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.delete', 'web');

        $this->user->givePermissionTo(['adjustments.create', 'adjustments.edit', 'adjustments.approval', 'adjustments.delete']);
        $this->actingAs($this->user);
        session(['setting_id' => $this->settingA->id]);
    }

    private function createMultiLocationDraft(array $locations): Adjustment
    {
        $primary = $locations[0];
        $adj = Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'SO-LC-' . uniqid(),
            'location_id' => $primary->id,
            'status' => AdjustmentStatus::Draft,
            'count_draft' => [
                'schema_version' => 2,
                'locations' => array_map(fn ($l) => (int) $l->id, $locations),
                'rows' => [
                    [
                        'product_id' => $this->product->id,
                        'good_count' => 10,
                        'bad_count' => 0,
                        'location_baselines' => [
                            $locations[0]->id => ['good' => 5, 'bad' => 0],
                            $locations[1]->id => ['good' => 5, 'bad' => 0],
                        ],
                    ],
                ],
            ],
        ]);

        foreach ($locations as $idx => $loc) {
            AdjustmentLocation::create([
                'adjustment_id' => $adj->id,
                'location_id' => $loc->id,
                'position' => $idx + 1,
            ]);
        }

        return $adj;
    }

    public function test_cross_setting_multi_location_draft_submission(): void
    {
        $adj = $this->createMultiLocationDraft([$this->locA, $this->locB]);

        // Active setting is settingA, but draft contains locA (settingA) and locB (settingB)
        $service = app(StockOpnameLifecycleService::class);
        $submitted = $service->submit($adj, $this->user);

        $this->assertEquals(AdjustmentStatus::WaitingApproval, $submitted->status);
        $this->assertEquals($this->user->id, $submitted->submitted_by);
        $this->assertNotNull($submitted->submitted_at);
    }

    public function test_cross_setting_multi_location_draft_rejection(): void
    {
        $adj = $this->createMultiLocationDraft([$this->locA, $this->locB]);
        $service = app(StockOpnameLifecycleService::class);
        $service->submit($adj, $this->user);

        // Switch active session to settingB (or any setting)
        session(['setting_id' => $this->settingB->id]);

        $rejected = $service->reject($adj, $this->user, 'Hitungan fisik tidak sesuai dengan catatan manual.');

        $this->assertEquals(AdjustmentStatus::Rejected, $rejected->status);
        $this->assertEqualsIgnoringCase('Hitungan fisik tidak sesuai dengan catatan manual.', $rejected->rejection_reason);
        $this->assertEquals($this->user->id, $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);
    }

    public function test_cross_setting_multi_location_draft_deletion(): void
    {
        $adj = $this->createMultiLocationDraft([$this->locA, $this->locB]);
        $adjId = $adj->id;

        $service = app(StockOpnameLifecycleService::class);
        $service->deleteDraft($adj, $this->user);

        $this->assertNull(Adjustment::find($adjId));
        $this->assertEquals(0, AdjustmentLocation::where('adjustment_id', $adjId)->count());
    }

    public function test_lifecycle_action_rejected_if_any_location_in_pool_becomes_inactive(): void
    {
        $adj = $this->createMultiLocationDraft([$this->locA, $this->locB]);

        // Deactivate locB
        $this->locB->update(['is_active' => false]);

        $service = app(StockOpnameLifecycleService::class);

        $this->expectException(ValidationException::class);
        $service->submit($adj, $this->user);
    }

    public function test_lifecycle_action_rejected_if_any_location_in_pool_becomes_consignment(): void
    {
        $adj = $this->createMultiLocationDraft([$this->locA, $this->locB]);

        // Convert locB to consignment
        $this->locB->update(['is_consignment' => true]);

        $service = app(StockOpnameLifecycleService::class);

        $this->expectException(ValidationException::class);
        $service->submit($adj, $this->user);
    }
}
