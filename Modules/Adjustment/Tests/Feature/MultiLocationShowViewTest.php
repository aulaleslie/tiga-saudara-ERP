<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MultiLocationShowViewTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $pkpSetting;
    protected Setting $nonPkpSetting;
    protected Location $pkpLocation;
    protected Location $nonPkpLocation;
    protected Unit $baseUnit;

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

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Store',
            'company_email' => 'pkp@store.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@pkp.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->nonPkpSetting = Setting::create([
            'company_name' => 'Non-PKP Store',
            'company_email' => 'nonpkp@store.com',
            'company_phone' => '987654321',
            'notification_email' => 'notify@nonpkp.com',
            'footer_text' => 'Footer',
            'company_address' => 'Surabaya',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => false,
        ]);

        $this->pkpLocation = Location::create([
            'name' => 'Gudang Pusat PKP',
            'setting_id' => $this->pkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->nonPkpLocation = Location::create([
            'name' => 'Gudang Cabang Retail',
            'setting_id' => $this->nonPkpSetting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->baseUnit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);

        Permission::findOrCreate('adjustments.show', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.delete', 'web');
        Permission::findOrCreate('adjustments.view-system-stock', 'web');

        $this->user->givePermissionTo([
            'adjustments.show', 'adjustments.edit', 'adjustments.approval',
            'adjustments.delete', 'adjustments.view-system-stock',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->pkpSetting->id]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Monitor LED 24',
            'product_code' => 'MON-24',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->pkpSetting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
            'serial_number_required' => false,
        ], $overrides));
    }

    private function makeStock(Product $product, Location $location, array $values): ProductStock
    {
        return ProductStock::create(array_merge([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ], $values));
    }

    public function test_reviewer_sees_multi_location_badges_and_allocation_plan_preview()
    {
        $product = $this->makeProduct();

        $this->makeStock($product, $this->nonPkpLocation, [
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
        ]);
        $this->makeStock($product, $this->pkpLocation, [
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SHOW-ML-1',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => null,
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
            'count_draft' => [
                'schema_version' => 2,
                'location_ids' => [$this->nonPkpLocation->id, $this->pkpLocation->id],
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'good_count' => 25, // Shortage of 5 (pool total 30)
                        'bad_count' => 0,
                        'location_baselines' => [
                            $this->nonPkpLocation->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                            $this->pkpLocation->id => ['good' => 20, 'bad' => 0, 'total' => 20],
                        ],
                    ],
                ],
            ],
        ]);

        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->nonPkpLocation->id, 'position' => 0]);
        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->pkpLocation->id, 'position' => 1]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        // Location header displays both locations
        $response->assertSee('GUDANG CABANG RETAIL');
        $response->assertSee('GUDANG PUSAT PKP');

        // Reviewer sees allocation plan
        $response->assertSee('Rencana Alokasi');
        $response->assertSee('Bagus:');
        $response->assertSee('-5');
    }

    public function test_counter_without_system_stock_permission_cannot_see_allocation_plan_or_system_stock()
    {
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        $product = $this->makeProduct();

        $this->makeStock($product, $this->nonPkpLocation, [
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SHOW-ML-COUNTER',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::WaitingApproval,
            'location_id' => null,
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
            'count_draft' => [
                'schema_version' => 2,
                'location_ids' => [$this->nonPkpLocation->id, $this->pkpLocation->id],
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'good_count' => 25,
                        'bad_count' => 0,
                        'location_baselines' => [
                            $this->nonPkpLocation->id => ['good' => 10, 'bad' => 0, 'total' => 10],
                        ],
                    ],
                ],
            ],
        ]);

        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->nonPkpLocation->id, 'position' => 0]);
        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->pkpLocation->id, 'position' => 1]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        // Reviewer elements are hidden
        $response->assertDontSee('Rencana Alokasi');
        $response->assertDontSee('Saat Ini');
        $response->assertDontSee('Ringkasan Peninjauan');

        // Counter sees entered numbers
        $response->assertSee('25');
    }

    public function test_reviewer_sees_approved_multi_location_detail_page_with_normalized_locations_and_allocation_evidence()
    {
        $product = $this->makeProduct();

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SHOW-ML-APPROVED-REV',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Approved,
            'location_id' => null,
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subHour(),
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'count_draft' => [
                'schema_version' => 2,
                'location_ids' => [$this->nonPkpLocation->id, $this->pkpLocation->id],
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'good_count' => 25,
                        'bad_count' => 0,
                    ],
                ],
            ],
            'approval_result' => [
                'adjustment_id' => 999,
                'selected_locations' => [
                    [
                        'location_id' => $this->nonPkpLocation->id,
                        'location_name' => $this->nonPkpLocation->name,
                        'setting_id' => $this->nonPkpSetting->id,
                        'is_pkp' => false,
                    ],
                    [
                        'location_id' => $this->pkpLocation->id,
                        'location_name' => $this->pkpLocation->name,
                        'setting_id' => $this->pkpSetting->id,
                        'is_pkp' => true,
                    ],
                ],
                'location_id' => $this->nonPkpLocation->id,
                'location_name' => $this->nonPkpLocation->name,
                'setting_id' => $this->nonPkpSetting->id,
                'is_pkp' => false,
                'products' => [
                    [
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'base_unit' => 'pcs',
                        'is_serialized' => false,
                        'entered' => ['good' => 25, 'bad' => 0, 'serial_count' => null],
                        'applied' => ['good' => 25, 'bad' => 0],
                        'current' => ['good' => 30, 'bad' => 0],
                        'difference' => ['good' => -5, 'bad' => 0],
                        'good_allocation_plan' => [
                            'type' => 'shortage',
                            'difference' => -5,
                            'steps' => [
                                [
                                    'location_id' => $this->nonPkpLocation->id,
                                    'location_name' => $this->nonPkpLocation->name,
                                    'before_stock' => 10,
                                    'delta' => -5,
                                    'after_stock' => 5,
                                ],
                            ],
                        ],
                        'serials' => [],
                    ],
                ],
                'warnings' => [],
                'conflicts' => [],
            ],
        ]);

        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->nonPkpLocation->id, 'position' => 0]);
        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->pkpLocation->id, 'position' => 1]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        // Renders normalized location names
        $response->assertSee('GUDANG CABANG RETAIL');
        $response->assertSee('GUDANG PUSAT PKP');

        // Reviewer sees immutable applied allocation evidence
        $response->assertSee('Bagus: 25');
        $response->assertSee('Rusak: 0');
    }

    public function test_counter_without_permission_sees_approved_location_names_without_quantities_or_allocation_details()
    {
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        $product = $this->makeProduct();

        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SHOW-ML-APPROVED-CTR',
            'date' => now()->toDateString(),
            'type' => 'normal',
            'status' => AdjustmentStatus::Approved,
            'location_id' => null,
            'submitted_by' => $this->user->id,
            'submitted_at' => now()->subHour(),
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'count_draft' => [
                'schema_version' => 2,
                'location_ids' => [$this->nonPkpLocation->id, $this->pkpLocation->id],
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'good_count' => 25,
                        'bad_count' => 0,
                    ],
                ],
            ],
            'approval_result' => [
                'adjustment_id' => 1000,
                'selected_locations' => [
                    [
                        'location_id' => $this->nonPkpLocation->id,
                        'location_name' => $this->nonPkpLocation->name,
                        'setting_id' => $this->nonPkpSetting->id,
                        'is_pkp' => false,
                    ],
                    [
                        'location_id' => $this->pkpLocation->id,
                        'location_name' => $this->pkpLocation->name,
                        'setting_id' => $this->pkpSetting->id,
                        'is_pkp' => true,
                    ],
                ],
                'location_id' => $this->nonPkpLocation->id,
                'location_name' => $this->nonPkpLocation->name,
                'setting_id' => $this->nonPkpSetting->id,
                'is_pkp' => false,
                'products' => [
                    [
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'base_unit' => 'pcs',
                        'is_serialized' => false,
                        'entered' => ['good' => 25, 'bad' => 0, 'serial_count' => null],
                        'applied' => ['good' => 25, 'bad' => 0],
                        'current' => ['good' => 30, 'bad' => 0],
                        'difference' => ['good' => -5, 'bad' => 0],
                        'good_allocation_plan' => [
                            'type' => 'shortage',
                            'difference' => -5,
                            'steps' => [
                                [
                                    'location_id' => $this->nonPkpLocation->id,
                                    'location_name' => $this->nonPkpLocation->name,
                                    'before_stock' => 10,
                                    'delta' => -5,
                                    'after_stock' => 5,
                                ],
                            ],
                        ],
                        'serials' => [],
                    ],
                ],
                'warnings' => [],
                'conflicts' => [],
            ],
        ]);

        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->nonPkpLocation->id, 'position' => 0]);
        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $this->pkpLocation->id, 'position' => 1]);

        $response = $this->get(route('adjustments.show', $adjustment));
        $response->assertOk();

        // Counter still sees both location names
        $response->assertSee('GUDANG CABANG RETAIL');
        $response->assertSee('GUDANG PUSAT PKP');

        // Reviewer/system elements and allocation details remain hidden
        $response->assertDontSee('Rencana Alokasi');
        $response->assertDontSee('Saat Ini');
        $response->assertDontSee('Ringkasan Peninjauan');

        // Counter sees entered numbers
        $response->assertSee('25');
    }
}
