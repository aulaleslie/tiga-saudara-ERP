<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class MultiLocationDraftPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;
    private Location $locA;
    private Location $locB;
    private Product $product;
    private CountDraftService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CountDraftService::class);
        $this->user = User::factory()->create();

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'adjustments.create', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'adjustments.edit', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'adjustments.access', 'guard_name' => 'web']);

        $this->user->givePermissionTo(['adjustments.create', 'adjustments.edit', 'adjustments.access']);
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
    public function saveDraft_persists_v2_and_syncs_relation_rows(): void
    {
        $input = [
            'reference' => 'ADJ/2026/001',
            'date' => '2026-09-19',
            'location_ids' => [$this->locB->id, $this->locA->id], // Reverse to verify canonical ordering
            'note' => 'Multi-location count draft',
            'count_draft' => json_encode([
                'rows' => [
                    [
                        'product_id' => $this->product->id,
                        'good_count' => 12,
                        'bad_count' => 2,
                        'serials' => [],
                    ],
                ],
            ]),
        ];

        $validated = $this->service->validateDraftInput($input);
        $adjustment = $this->service->saveDraft($validated);

        $this->assertInstanceOf(Adjustment::class, $adjustment);
        $this->assertTrue($adjustment->isSchemaVersion2());
        $this->assertEquals(AdjustmentStatus::Draft, $adjustment->status);

        // Check adjustment_locations rows
        $adjLocations = AdjustmentLocation::where('adjustment_id', $adjustment->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $adjLocations);
        $this->assertEquals($this->locA->id, $adjLocations[0]->location_id);
        $this->assertEquals(1, $adjLocations[0]->position);
        $this->assertEquals($this->locB->id, $adjLocations[1]->location_id);
        $this->assertEquals(2, $adjLocations[1]->position);

        // Check count_draft json
        $draft = $adjustment->count_draft;
        $this->assertEquals(2, $draft['schema_version']);
        $this->assertCount(2, $draft['locations']);
        $this->assertCount(2, $draft['rows'][0]['location_baselines']);
    }

    /** @test */
    public function updateDraft_synchronizes_relation_rows_atomically(): void
    {
        // 1. Create with locA + locB
        $input1 = [
            'reference' => 'ADJ/2026/001',
            'date' => '2026-09-19',
            'location_ids' => [$this->locA->id, $this->locB->id],
            'count_draft' => json_encode([
                'rows' => [
                    [
                        'product_id' => $this->product->id,
                        'good_count' => 12,
                        'bad_count' => 2,
                        'serials' => [],
                    ],
                ],
            ]),
        ];
        $validated1 = $this->service->validateDraftInput($input1);
        $adj = $this->service->saveDraft($validated1);

        $this->assertCount(2, AdjustmentLocation::where('adjustment_id', $adj->id)->get());

        // 2. Update to only locA
        $input2 = [
            'reference' => 'ADJ/2026/001',
            'date' => '2026-09-19',
            'location_ids' => [$this->locA->id],
            'count_draft' => json_encode([
                'rows' => [
                    [
                        'product_id' => $this->product->id,
                        'good_count' => 8,
                        'bad_count' => 1,
                        'serials' => [],
                    ],
                ],
            ]),
        ];
        $validated2 = $this->service->validateDraftInput($input2, $adj);
        $updatedAdj = $this->service->saveDraft($validated2, $adj);

        $adjLocations = AdjustmentLocation::where('adjustment_id', $updatedAdj->id)->get();
        $this->assertCount(1, $adjLocations);
        $this->assertEquals($this->locA->id, $adjLocations->first()->location_id);
    }

    /** @test */
    public function controller_store_and_update_support_multi_location(): void
    {
        // Store via POST
        $response = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ/2026/999',
            'date' => '2026-09-19',
            'location_ids' => [$this->locA->id, $this->locB->id],
            'note' => 'Controller test',
            'count_draft' => json_encode([
                'rows' => [
                    [
                        'product_id' => $this->product->id,
                        'good_count' => 10,
                        'bad_count' => 0,
                        'serials' => [],
                    ],
                ],
            ]),
        ]);

        $response->assertRedirect(route('adjustments.index'));
        $this->assertDatabaseHas('adjustments', [
            'reference' => 'ADJ/2026/999',
            'status' => AdjustmentStatus::Draft->value,
        ]);

        $adj = Adjustment::where('reference', 'ADJ/2026/999')->first();
        $this->assertCount(2, $adj->selectedLocations);

        // Update via PATCH
        $patchResponse = $this->patch(route('adjustments.update', $adj), [
            'reference' => 'ADJ/2026/999',
            'date' => '2026-09-20',
            'location_ids' => [$this->locA->id],
            'note' => 'Updated note',
            'count_draft' => json_encode([
                'rows' => [
                    [
                        'product_id' => $this->product->id,
                        'good_count' => 9,
                        'bad_count' => 1,
                        'serials' => [],
                    ],
                ],
            ]),
        ]);

        $patchResponse->assertRedirect(route('adjustments.index'));
        $this->assertCount(1, $adj->fresh()->selectedLocations);
    }
}
