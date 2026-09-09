<?php

namespace Tests\Feature\Adjustment;

use App\Livewire\Adjustment\AdjustmentProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\AdjustmentProductResolver;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockOpnameRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $location;
    protected Unit $baseUnit;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@company.com',
            'footer_text' => 'Footer',
            'company_address' => 'Jakarta',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'is_pkp' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
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

        // Create adjustment permissions
        Permission::findOrCreate('adjustments.access', 'web');
        Permission::findOrCreate('adjustments.create', 'web');
        Permission::findOrCreate('adjustments.edit', 'web');
        Permission::findOrCreate('adjustments.approval', 'web');
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->givePermissionTo(['adjustments.access', 'adjustments.create', 'adjustments.edit', 'adjustments.approval', 'adjustments.view-system-stock']);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_resolver_searches_stock_managed_products_without_is_sold_filter()
    {
        $product = Product::create([
            'product_name' => 'Baut Baja 10mm',
            'product_code' => 'BB10',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $nonStockManaged = Product::create([
            'product_name' => 'Jasa Servis Baja',
            'product_code' => 'JSB',
            'product_cost' => 0,
            'product_price' => 50000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => false,
            'is_active' => true,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $results = $resolver->searchProducts('Baja');

        $ids = collect($results)->pluck('id')->all();
        $this->assertContains($product->id, $ids);
        $this->assertNotContains($nonStockManaged->id, $ids);
    }

    public function test_resolver_resolves_primary_barcode_conversion_and_serials_with_ambiguity()
    {
        $resolver = app(AdjustmentProductResolver::class);

        $product = Product::create([
            'product_name' => 'Kabel LAN Cat6',
            'product_code' => 'LAN6',
            'barcode' => 'BAR-LAN-01',
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $dusUnit = Unit::create([
            'name' => 'Dus',
            'short_name' => 'dus',
            'operator' => '*',
            'operation_value' => 10,
            'is_active' => true,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $dusUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 10,
            'barcode' => 'CONV-LAN-10',
        ]);

        // 1. Primary barcode
        $res1 = $resolver->resolveScan('BAR-LAN-01');
        $this->assertEquals('resolved', $res1['status']);
        $this->assertEquals('product', $res1['match_type']);
        $this->assertEquals($product->id, $res1['candidate']['product']['id']);

        // 2. Conversion barcode
        $res2 = $resolver->resolveScan('CONV-LAN-10');
        $this->assertEquals('resolved', $res2['status']);
        $this->assertEquals('conversion', $res2['match_type']);
        $this->assertEquals(10, $res2['candidate']['conversion']['conversion_factor']);

        // 3. Unknown barcode
        $res3 = $resolver->resolveScan('UNKNOWN-CODE-999');
        $this->assertEquals('not_found', $res3['status']);

        // 4. Ambiguous barcode (serial on another product shares text with barcode)
        $product2 = Product::create([
            'product_name' => 'Router Wifi',
            'product_code' => 'RTW',
            'product_cost' => 100000,
            'product_price' => 200000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product2->id,
            'location_id' => $this->location->id,
            'serial_number' => 'BAR-LAN-01', // Shares text with product 1's primary barcode!
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $resAmb = $resolver->resolveScan('BAR-LAN-01');
        $this->assertEquals('ambiguous', $resAmb['status']);
        $this->assertCount(2, $resAmb['candidates']);
    }

    public function test_counting_increments_and_serial_handling_in_component()
    {
        $productOrdinary = Product::create([
            'product_name' => 'Mouse USB',
            'product_code' => 'MUSB',
            'barcode' => 'BC-MOUSE',
            'product_cost' => 20000,
            'product_price' => 40000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        $productSerialized = Product::create([
            'product_name' => 'Monitor LED 24',
            'product_code' => 'MLED24',
            'barcode' => 'BC-MONITOR',
            'product_cost' => 1000000,
            'product_price' => 1500000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, ['locationId' => $this->location->id]);

        // 1. Ordinary barcode scan adds 1 to good condition
        $component->set('scanInput', 'BC-MOUSE')
            ->call('processScan')
            ->assertCount('products', 1);

        $this->assertEquals(1, $component->get('products')[0]['good_count']);
        $this->assertEquals(0, $component->get('products')[0]['bad_count']);

        // 2. Switch condition to bad and scan again -> bad count becomes 1, good count stays 1
        $component->call('setActiveCondition', 'bad')
            ->set('scanInput', 'BC-MOUSE')
            ->call('processScan');

        $this->assertEquals(1, $component->get('products')[0]['good_count']);
        $this->assertEquals(1, $component->get('products')[0]['bad_count']);

        // 3. Serialized barcode scan -> creates row at 0 without incrementing
        $component->set('scanInput', 'BC-MONITOR')
            ->call('processScan')
            ->assertCount('products', 2);

        $this->assertEquals(0, $component->get('products')[1]['good_count']);
        $this->assertEquals(0, $component->get('products')[1]['bad_count']);

        // 4. Row serial dialog: add unregistered/raw serials
        $component->call('openSerialModal', 1)
            ->set('rowSerialCondition', 'good')
            ->set('rowSerialInput', 'sn-test-001')
            ->call('addRowSerial');

        // Verify serial was normalized to uppercase and good count incremented to 1
        $this->assertEquals(1, $component->get('products')[1]['good_count']);
        $this->assertEquals('SN-TEST-001', $component->get('products')[1]['serial_numbers'][0]['serial_number']);

        // 5. Deduplication across conditions: attempting to add SN-TEST-001 as bad should be rejected
        $component->set('rowSerialCondition', 'bad')
            ->set('rowSerialInput', 'sn-test-001')
            ->call('addRowSerial');

        $this->assertNotNull($component->get('rowSerialError'));
        $this->assertEquals(1, $component->get('products')[1]['good_count']);
        $this->assertEquals(0, $component->get('products')[1]['bad_count']);

        // 6. Explicit reclassification: change serial 0 to bad
        $component->call('reclassifySerial', 0, 'bad');
        $this->assertEquals(0, $component->get('products')[1]['good_count']);
        $this->assertEquals(1, $component->get('products')[1]['bad_count']);
    }

    public function test_location_change_confirmation_and_clearing()
    {
        $location2 = Location::create([
            'name' => 'Gudang Cabang',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $product = Product::create([
            'product_name' => 'Barang A',
            'product_code' => 'BA',
            'barcode' => 'BAR-A',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, ['locationId' => $this->location->id]);

        $component->set('scanInput', 'BAR-A')
            ->call('processScan')
            ->assertCount('products', 1);

        // Change location event fired
        $component->call('locationDropdownSelected', 'location_id', $location2->id);

        // Modal should be open, products not yet cleared
        $this->assertTrue($component->get('showLocationConfirmModal'));
        $this->assertCount(1, $component->get('products'));

        // Confirm
        $component->call('confirmLocationChange');
        $this->assertFalse($component->get('showLocationConfirmModal'));
        $this->assertEquals($location2->id, $component->get('locationId'));
        $this->assertCount(0, $component->get('products'));
    }

    public function test_persistence_of_versioned_count_draft_and_approval_guard()
    {
        $product = Product::create([
            'product_name' => 'Barang B',
            'product_code' => 'BB',
            'barcode' => 'BAR-B',
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Create baseline stock
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $draftPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'base_unit' => 'Pcs',
                    'is_serialized' => false,
                    'good_count' => 12,
                    'bad_count' => 1,
                    'baseline' => [
                        'existing_good_total' => 10,
                        'existing_good_tax' => 10,
                        'existing_good_non_tax' => 0,
                        'existing_bad_total' => 0,
                        'existing_bad_tax' => 0,
                        'existing_bad_non_tax' => 0,
                    ],
                ]
            ]
        ];

        // 1. Post to store
        $response = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ-TEST-001',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'note' => 'Catatan opname fisik',
            'count_draft' => json_encode($draftPayload),
        ]);

        $response->assertRedirect(route('adjustments.index'));
        $this->assertDatabaseHas('adjustments', [
            'reference' => 'ADJ-TEST-001',
        ]);

        $adjustment = Adjustment::where('reference', 'ADJ-TEST-001')->first();
        $this->assertNotNull($adjustment);
        $this->assertEquals(\Modules\Adjustment\Entities\AdjustmentStatus::Draft, $adjustment->status);
        $this->assertEquals('NORMAL', strtoupper($adjustment->type));
        $this->assertTrue($adjustment->isVersionedCountDraft());

        // Verify stock was NOT mutated
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first();
        $this->assertEquals(10, $stock->quantity);

        // 2. Attempt legacy approval -> must be rejected with message and no stock mutation
        $approveResponse = $this->patch(route('adjustments.approve', $adjustment));
        $approveResponse->assertSessionHasErrors('message');

        $adjustment->refresh();
        $this->assertEquals(\Modules\Adjustment\Entities\AdjustmentStatus::Draft, $adjustment->status);

        $stock->refresh();
        $this->assertEquals(10, $stock->quantity);
    }

    public function test_failed_validation_restores_old_input_over_saved_model(): void
    {
        // Create an existing adjustment
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SAVED-1',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'type' => 'normal',
            'status' => 'pending',
            'count_draft' => [
                'schema_version' => 1,
                'location_id' => $this->location->id,
                'rows' => [
                    [
                        'product_id' => 999,
                        'product_name' => 'Saved Product',
                        'product_code' => 'SAVED-1',
                        'base_unit' => 'Pcs',
                        'is_serialized' => false,
                        'good_count' => 5,
                        'bad_count' => 0,
                    ]
                ]
            ]
        ]);

        // Simulate failed validation where old input has attempted counts
        $attemptedDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => 999,
                    'product_name' => 'Saved Product',
                    'product_code' => 'SAVED-1',
                    'base_unit' => 'Pcs',
                    'is_serialized' => false,
                    'good_count' => 20, // user attempted 20
                    'bad_count' => 3,  // user attempted 3
                ]
            ]
        ];

        request()->setLaravelSession(session());
        session()->flashInput([
            'count_draft' => json_encode($attemptedDraft),
            'location_id' => $this->location->id,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'adjustment' => $adjustment,
            'locationId' => $this->location->id,
        ]);

        // Products should have the attempted counts (20, 3) rather than the saved counts (5, 0)
        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals(20, $products[0]['good_count']);
        $this->assertEquals(3, $products[0]['bad_count']);
    }

    public function test_authoritative_persistence_and_deduplication(): void
    {
        $product = Product::create([
            'product_name' => 'Produk Non Serial',
            'product_code' => 'PNS-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => false, // Authoritative: NOT serialized
            'is_active' => true,
        ]);

        $serializedProduct = Product::create([
            'product_name' => 'Produk Serial',
            'product_code' => 'PS-1',
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true, // Authoritative: IS serialized
            'is_active' => true,
        ]);

        $service = app(CountDraftService::class);

        // 1. Client attempts to pass fake is_serialized=true and fake is_pkp=false on PNS-1
        $manipulatedDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'is_pkp' => false, // client tries to claim non-PKP
            'rows' => [
                [
                    'product_id' => $product->id,
                    'is_serialized' => true, // client lies that it's serialized
                    'good_count' => 10,
                    'bad_count' => 2,
                    'serials' => [],
                ]
            ]
        ];

        $structured = $service->validateAndStructureDraft($manipulatedDraft, $this->location->id);
        // Assert setting/PKP and serial flag are authoritatively reloaded from DB
        $this->assertTrue($structured['is_pkp']);
        $this->assertFalse($structured['rows'][0]['is_serialized']);
        $this->assertEquals(10, $structured['rows'][0]['tax_allocation']['good_tax']);
        $this->assertEquals(0, $structured['rows'][0]['tax_allocation']['good_non_tax']);

        // 2. Client attempts to pass serialized product with arbitrary good_count but no serials
        $manipulatedSerializedDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $serializedProduct->id,
                    'is_serialized' => false, // client lies that it's not serialized
                    'good_count' => 50,
                    'bad_count' => 10,
                    'serials' => [
                        ['serial_number' => 'SN001', 'condition' => 'good'],
                    ],
                ]
            ]
        ];
        $structuredSerialized = $service->validateAndStructureDraft($manipulatedSerializedDraft, $this->location->id);
        $this->assertTrue($structuredSerialized['rows'][0]['is_serialized']);
        // Serialized product count MUST be recomputed strictly from serials: 1 good, 0 bad
        $this->assertEquals(1, $structuredSerialized['rows'][0]['good_count']);
        $this->assertEquals(0, $structuredSerialized['rows'][0]['bad_count']);

        // 3. Client attempts to pass duplicate product row
        $duplicateRowDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                ['product_id' => $product->id, 'good_count' => 1, 'bad_count' => 0],
                ['product_id' => $product->id, 'good_count' => 2, 'bad_count' => 0],
            ]
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Produk ganda ditemukan dalam daftar opname");
        $service->validateAndStructureDraft($duplicateRowDraft, $this->location->id);
    }

    public function test_baseline_and_source_serials_are_resolved_authoritatively(): void
    {
        $product = Product::create([
            'product_name' => 'Serialized Phone',
            'product_code' => 'SP-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        // Create authoritative product stock in DB
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 7,
            'quantity_tax' => 7,
            'quantity_non_tax' => 0,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create an existing registered serial in DB
        $registeredSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'REG-12345',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $service = app(CountDraftService::class);

        // Client attempts to spoof baseline stock and supply fake source references
        $tamperedDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'baseline' => [
                        'existing_good_total' => 999, // Tampered
                        'existing_good_tax' => 999,
                        'existing_good_non_tax' => 0,
                        'existing_bad_total' => 888,  // Tampered
                        'existing_bad_tax' => 888,
                        'existing_bad_non_tax' => 0,
                    ],
                    'serials' => [
                        // Known serial with spoofed source IDs
                        [
                            'serial_number' => 'REG-12345',
                            'condition' => 'good',
                            'source_serial_id' => 99999, // Spoofed
                            'source_location_id' => 88888, // Spoofed
                            'source_location_name' => 'Fake Location',
                            'source_status' => 'FAKE_STATUS',
                        ],
                        // Unknown serial with client-invented source references
                        [
                            'serial_number' => 'UNREGISTERED-999',
                            'condition' => 'bad',
                            'source_serial_id' => 77777, // Should be null
                            'source_location_id' => 66666, // Should be null
                            'source_status' => 'INVENTED',
                        ],
                    ],
                ]
            ]
        ];

        // 1. New save: baseline captured from actual DB stock, source metadata resolved by product + serial text
        $structured = $service->validateAndStructureDraft($tamperedDraft, $this->location->id);
        $row = $structured['rows'][0];

        // Baseline must match actual DB stock (7 good, 2 bad), ignoring client 999/888
        $this->assertEquals(7, $row['baseline']['existing_good_total']);
        $this->assertEquals(2, $row['baseline']['existing_bad_total']);

        // Known serial must have authoritative DB metadata
        $knownSerialEntry = collect($row['serials'])->firstWhere('serial_number', 'REG-12345');
        $this->assertNotNull($knownSerialEntry);
        $this->assertEquals($registeredSerial->id, $knownSerialEntry['source_serial_id']);
        $this->assertEquals($this->location->id, $knownSerialEntry['source_location_id']);
        $this->assertEquals('ACTIVE', $knownSerialEntry['source_status']);

        // Unknown serial must retain null source references
        $unknownSerialEntry = collect($row['serials'])->firstWhere('serial_number', 'UNREGISTERED-999');
        $this->assertNotNull($unknownSerialEntry);
        $this->assertNull($unknownSerialEntry['source_serial_id']);
        $this->assertNull($unknownSerialEntry['source_location_id']);
        $this->assertNull($unknownSerialEntry['source_status']);

        // 2. Existing draft save: server-held baseline is preserved across edits
        $existingAdjustment = Adjustment::create([
            'reference' => 'ADJ-EXISTING',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => 'pending',
            'type' => 'normal',
            'count_draft' => [
                'schema_version' => 1,
                'location_id' => $this->location->id,
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'baseline' => [
                            'existing_good_total' => 15, // Historical captured baseline
                            'existing_good_tax' => 15,
                            'existing_good_non_tax' => 0,
                            'existing_bad_total' => 0,
                            'existing_bad_tax' => 0,
                            'existing_bad_non_tax' => 0,
                            'captured_at' => '2026-09-01T00:00:00Z',
                        ],
                    ]
                ]
            ]
        ]);

        $structuredUpdate = $service->validateAndStructureDraft($tamperedDraft, $this->location->id, $existingAdjustment);
        // Preserves server-held baseline 15 from existing adjustment
        $this->assertEquals(15, $structuredUpdate['rows'][0]['baseline']['existing_good_total']);
        $this->assertEquals('2026-09-01T00:00:00Z', $structuredUpdate['rows'][0]['baseline']['captured_at']);
    }

    public function test_empty_attempted_draft_restores_empty_state_without_falling_back_to_saved_adjustment(): void
    {
        $adjustment = Adjustment::create([
            'reference' => 'ADJ-SAVED-EMPTY-TEST',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'type' => 'normal',
            'status' => 'pending',
            'count_draft' => [
                'schema_version' => 1,
                'location_id' => $this->location->id,
                'rows' => [
                    [
                        'product_id' => 123,
                        'product_name' => 'Previously Saved Item',
                        'product_code' => 'PSI-1',
                        'base_unit' => 'Pcs',
                        'is_serialized' => false,
                        'good_count' => 10,
                        'bad_count' => 0,
                    ]
                ]
            ]
        ]);

        // Simulate user removing all rows and failing validation with empty attempted draft
        $emptyAttemptedDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [],
        ];

        request()->setLaravelSession(session());
        session()->flashInput([
            'count_draft' => json_encode($emptyAttemptedDraft),
            'location_id' => $this->location->id,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'adjustment' => $adjustment,
            'locationId' => $this->location->id,
        ]);

        // Should restore explicitly empty rows, NOT fall back to the saved row
        $products = $component->get('products');
        $this->assertCount(0, $products);
    }

    public function test_closing_dialogs_dispatches_restore_scanner_focus(): void
    {
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        // 1. Search modal
        $component->call('openSearchModal')
            ->assertSet('showSearchModal', true)
            ->call('closeSearchModal')
            ->assertSet('showSearchModal', false)
            ->assertDispatched('restore-scanner-focus');

        // 2. Serial modal
        $product = Product::create([
            'product_name' => 'Item Serial Test',
            'product_code' => 'IST-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_required' => true,
        ]);

        $component->call('openSerialModal', 0)
            ->assertSet('showSerialModal', true)
            ->call('closeSerialModal')
            ->assertSet('showSerialModal', false)
            ->assertDispatched('restore-scanner-focus');

        // 3. Ambiguity modal
        $component->set('showAmbiguityModal', true)
            ->call('closeAmbiguityModal')
            ->assertSet('showAmbiguityModal', false)
            ->assertDispatched('restore-scanner-focus');
    }

    public function test_cancelling_location_change_dispatches_set_selected_location(): void
    {
        $otherLocation = Location::create([
            'name' => 'Gudang Kedua',
            'setting_id' => $this->setting->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $product = Product::create([
            'product_name' => 'Item X',
            'product_code' => 'IX-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        // Add a product to populated state
        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_required' => false,
        ]);

        $this->assertCount(1, $component->get('products'));

        // Change location -> triggers confirm modal
        $component->call('locationSelected', $otherLocation->id);
        $this->assertTrue($component->get('showLocationConfirmModal'));
        $this->assertEquals($otherLocation->id, $component->get('pendingLocationId'));

        // Cancel change -> dispatches setSelectedLocation with original location id
        $component->call('cancelLocationChange')
            ->assertDispatched('setSelectedLocation', locationId: $this->location->id);

        $this->assertFalse($component->get('showLocationConfirmModal'));
        $this->assertEquals($this->location->id, $component->get('locationId'));
        $this->assertCount(1, $component->get('products'));
    }

    public function test_signed_baseline_preserved_when_stock_changes_between_addition_and_submission(): void
    {
        $this->actingAs($this->user);

        // Setup product with 10 units in stock
        $product = Product::create([
            'product_name' => 'Stock Shift Item',
            'product_code' => 'SSI-10',
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // 1. Operator opens form and adds the product row: baseline captured at 10
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'serial_number_required' => false,
        ]);

        // Operator counts 8 units
        $component->set('products.0.good_count', 8);

        // Verify the component row captures baseline 10 with a valid signature
        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals(10, $products[0]['baseline']['existing_good_total']);
        $this->assertNotEmpty($products[0]['baseline']['signature'] ?? null);

        // Operator observes displayed difference of 8 - 10 = -2
        $payloadJson = $component->get('countDraftPayload');
        $this->assertNotEmpty($payloadJson);

        // 2. While the operator is working / before saving, background stock changes from 10 -> 8
        $stock->update([
            'quantity' => 8,
            'quantity_tax' => 8,
        ]);
        $this->assertEquals(8, $stock->fresh()->quantity_tax);

        // 3. Form is submitted with the count_draft payload containing the signed baseline
        $service = app(CountDraftService::class);
        $savedAdjustment = $service->saveDraft([
            'reference' => 'ADJ-PRESERVE-BASELINE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $payloadJson,
        ]);

        // 4. Verification: The saved baseline must preserve the initial snapshot of 10,
        // resulting in a saved baseline difference of 8 - 10 = -2 (NOT 8 - 8 = 0).
        $savedDraft = $savedAdjustment->fresh()->count_draft;
        $this->assertIsArray($savedDraft);
        $this->assertCount(1, $savedDraft['rows']);

        $savedRow = $savedDraft['rows'][0];
        $this->assertEquals(10, $savedRow['baseline']['existing_good_total']);
        $this->assertEquals(8, $savedRow['good_count']);
        $this->assertEquals(
            -2,
            $savedRow['good_count'] - $savedRow['baseline']['existing_good_total'],
            'The agreed preservation of the reviewed baseline must hold: counted 8 vs baseline 10 gives difference of -2, not 0.'
        );
    }

    public function test_empty_draft_submission_stays_in_draft_workflow_with_indonesian_error(): void
    {
        // When submitting an empty draft (no products)
        $emptyPayload = json_encode([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [],
        ]);

        $response = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ-EMPTY',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $emptyPayload,
        ]);

        $response->assertSessionHasErrors(['count_draft']);
        $errors = session('errors')->get('count_draft');
        $this->assertStringContainsString('Daftar produk tidak boleh kosong', $errors[0]);

        // When count_draft has no product_ids and empty rows
        $response2 = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ-EMPTY-2',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
        ]);

        $response2->assertSessionHasErrors(['count_draft']);
    }

    public function test_consolidated_validation_returns_indonesian_errors_for_invalid_inputs(): void
    {
        $service = app(CountDraftService::class);

        // Missing location
        try {
            $service->validateDraftInput([
                'reference' => 'ADJ-1',
                'date' => now()->toDateString(),
                'location_id' => null,
                'count_draft' => json_encode(['schema_version' => 1, 'rows' => []]),
            ]);
            $this->fail('Expected ValidationException for missing location');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('location_id', $e->errors());
            $this->assertStringContainsString('Lokasi stok opname wajib dipilih', $e->errors()['location_id'][0]);
        }

        // Duplicate serial
        $product = Product::create([
            'product_name' => 'Serialized Tablet',
            'product_code' => 'ST-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        try {
            $service->validateDraftInput([
                'reference' => 'ADJ-1',
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'count_draft' => json_encode([
                    'schema_version' => 1,
                    'location_id' => $this->location->id,
                    'rows' => [
                        [
                            'product_id' => $product->id,
                            'serials' => [
                                ['serial_number' => 'SN-DUP', 'condition' => 'good'],
                                ['serial_number' => 'sn-dup', 'condition' => 'bad'],
                            ]
                        ]
                    ]
                ]),
            ]);
            $this->fail('Expected ValidationException for duplicate serial');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('count_draft', $e->errors());
            $this->assertStringContainsString("Nomor seri ganda 'SN-DUP'", $e->errors()['count_draft'][0]);
        }
    }

    public function test_scanner_retains_text_and_dispatches_select_event_on_failed_scan(): void
    {
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $component->set('scanInput', 'NON-EXISTENT-BARCODE-999');
        $component->call('processScan');

        // Unsuccessful scan: retain text, set Indonesian danger feedback, dispatch select-scan-input
        $this->assertEquals('NON-EXISTENT-BARCODE-999', $component->get('scanInput'));
        $this->assertStringContainsString('tidak ditemukan', $component->get('feedbackMessage'));
        $this->assertEquals('danger', $component->get('feedbackType'));
        $component->assertDispatched('select-scan-input');
    }

    public function test_quantity_rejects_scientific_notation_and_non_integers(): void
    {
        $product = Product::create([
            'product_name' => 'Standard Item',
            'product_code' => 'SI-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        $service = app(CountDraftService::class);

        // Client attempts to send scientific notation "1e2"
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('harus berupa bilangan bulat positif atau nol');

        $service->validateAndStructureDraft([
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => '1e2',
                    'bad_count' => 0,
                ]
            ]
        ], $this->location->id);
    }

    public function test_scanner_retains_text_when_scan_resolves_but_application_is_rejected(): void
    {
        $product = Product::create([
            'product_name' => 'Phone Item',
            'product_code' => 'PI-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SER-DUP-999',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        // First scan adds the serial: input should be cleared
        $component->set('scanInput', 'SER-DUP-999');
        $component->call('processScan');
        $this->assertEquals('', $component->get('scanInput'));
        $this->assertEquals('success', $component->get('feedbackType'));

        // Second scan of the same serial is rejected as duplicate:
        // scan input MUST NOT be cleared; it should retain text and dispatch select-scan-input
        $component->set('scanInput', 'SER-DUP-999');
        $component->call('processScan');
        $this->assertEquals('SER-DUP-999', $component->get('scanInput'));
        $this->assertEquals('warning', $component->get('feedbackType'));
        $this->assertStringContainsString('sudah ada pada produk ini', $component->get('feedbackMessage'));
        $component->assertDispatched('select-scan-input');
    }

    public function test_validation_error_preserves_raw_attempted_quantities_through_restoration_and_resubmission(): void
    {
        $product = Product::create([
            'product_name' => 'Scientific Notation Item',
            'product_code' => 'SNI-1',
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        // 1. Initial submission with scientific notation quantity e.g. "1e2"
        $attemptedDraft = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'base_unit' => 'Pcs',
                    'is_serialized' => false,
                    'good_count' => '1e2',
                    'bad_count' => 0,
                    'baseline' => [
                        'existing_good_total' => 10,
                        'existing_good_tax' => 10,
                        'existing_good_non_tax' => 0,
                        'existing_bad_total' => 0,
                        'existing_bad_tax' => 0,
                        'existing_bad_non_tax' => 0,
                    ],
                ]
            ]
        ];

        $postResponse = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ-RAW-QTY',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => json_encode($attemptedDraft),
        ]);

        // Rejection: must fail with count_draft validation error
        $postResponse->assertSessionHasErrors(['count_draft']);

        // 2. Form restoration from old input
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $products = $component->get('products');
        $this->assertCount(1, $products);
        // The raw attempted quantity must be preserved as "1e2", NOT converted to 100 or 1
        $this->assertSame('1e2', $products[0]['good_count']);

        // 3. Resubmission without user correction:
        // getCountDraftPayloadProperty must preserve "1e2"
        $restoredPayload = $component->get('countDraftPayload');
        $this->assertNotEmpty($restoredPayload);
        $decoded = json_decode($restoredPayload, true);
        $this->assertSame('1e2', $decoded['rows'][0]['good_count']);

        // Submitting this restored payload again must STILL fail validation (not silently save 100 or 1)
        $resubmitResponse = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ-RAW-QTY',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $restoredPayload,
        ]);

        $resubmitResponse->assertSessionHasErrors(['count_draft']);
        $this->assertDatabaseMissing('adjustments', [
            'reference' => 'ADJ-RAW-QTY',
        ]);
    }

    /**
     * Test unauthorized users cannot view system stock or differences in create, edit, or show views.
     */
    public function test_unauthorized_user_cannot_view_system_stock_or_differences()
    {
        // Ensure user does NOT have adjustments.view-system-stock permission
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        $product = Product::create([
            'product_name' => 'Kabel Tembaga 2.5mm',
            'product_code' => 'KT25',
            'product_cost' => 5000,
            'product_price' => 7500,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 142,
            'quantity_tax' => 142,
            'quantity_non_tax' => 0,
            'broken_quantity' => 7,
            'broken_quantity_tax' => 7,
            'broken_quantity_non_tax' => 0,
        ]);

        // 1. Create View via Livewire
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $normalized = $resolver->normalizeProductData($product);
        $component->call('productSelected', $normalized);

        // Assert rendered HTML does not contain system stock or difference headers/values.
        // Assert against labeled/structured text rather than a bare numeric
        // substring: a short number like "142" can also appear inside an
        // opaque, randomly generated baseline token and would make this
        // assertion flaky/false-failing without actually disclosing stock.
        $component->assertDontSee('Stok Sistem (Bagus / Rusak)');
        $component->assertDontSee('Selisih (Bagus / Rusak)');
        $component->assertDontSee('Bagus: 142'); // labeled system good stock value
        $component->assertDontSee('Rusak: 7'); // labeled system bad stock value
        $component->assertDontSee('bi-shield-x text-danger'); // system bad stock icon/badge

        // Assert public component state does NOT disclose system stock quantities
        $productsState = $component->get('products');
        $this->assertArrayNotHasKey('existing_good_total', $productsState[0]['baseline']);
        $this->assertArrayNotHasKey('existing_bad_total', $productsState[0]['baseline']);
        $this->assertNotEmpty($productsState[0]['baseline']['token']);

        // Assert count draft hidden input payload does NOT disclose system stock quantities
        $payload = $component->get('countDraftPayload');
        $decoded = json_decode($payload, true);
        $this->assertArrayNotHasKey('existing_good_total', $decoded['rows'][0]['baseline']);
        $this->assertArrayNotHasKey('existing_bad_total', $decoded['rows'][0]['baseline']);
        $this->assertNotEmpty($decoded['rows'][0]['baseline']['token']);

        // 2. Save adjustment and check Show View
        Permission::findOrCreate('adjustments.show', 'web');
        $this->user->givePermissionTo('adjustments.show');

        $service = app(CountDraftService::class);
        $adj = $service->saveDraft([
            'reference' => 'ADJ-PERM-TEST',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $payload,
        ]);

        $showResponse = $this->get(route('adjustments.show', $adj));
        $showResponse->assertOk();
        $showResponse->assertDontSee('Saat Ini');
        $showResponse->assertDontSee('Selisih Bagus');
        $showResponse->assertDontSee('Selisih Rusak');
        $showResponse->assertDontSee('142');
        $showResponse->assertDontSee('Ringkasan Peninjauan');

        // 3. Edit View
        $editResponse = $this->get(route('adjustments.edit', $adj));
        $editResponse->assertOk();
        $editResponse->assertDontSee('Stok Sistem (Bagus / Rusak)');
        $editResponse->assertDontSee('Selisih (Bagus / Rusak)');
    }

    /**
     * Test authorized user with permission can view system stock and differences.
     */
    public function test_authorized_user_with_permission_can_view_system_stock_and_differences()
    {
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        Permission::findOrCreate('adjustments.show', 'web');
        $this->user->givePermissionTo(['adjustments.view-system-stock', 'adjustments.show']);

        $product = Product::create([
            'product_name' => 'Pipa PVC 1/2 Inch',
            'product_code' => 'PVC12',
            'product_cost' => 15000,
            'product_price' => 22000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 0,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $normalized = $resolver->normalizeProductData($product);
        $component->call('productSelected', $normalized);

        // Assert comparisons are visible
        $component->assertSee('Stok Sistem (Bagus / Rusak)');
        $component->assertSee('Selisih (Bagus / Rusak)');
        $component->assertSee('50');
        $component->assertSee('5');

        // Public state contains quantities for authorized user
        $productsState = $component->get('products');
        $this->assertEquals(50, $productsState[0]['baseline']['existing_good_total']);
        $this->assertEquals(5, $productsState[0]['baseline']['existing_bad_total']);

        // Check show view
        $service = app(CountDraftService::class);
        $adj = $service->saveDraft([
            'reference' => 'ADJ-AUTH-TEST',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $component->get('countDraftPayload'),
        ]);

        $showResponse = $this->get(route('adjustments.show', $adj));
        $showResponse->assertOk();
        $showResponse->assertSee('Saat Ini');
        $showResponse->assertSee('Selisih Bagus');
        $showResponse->assertSee('Selisih Rusak');
    }

    /**
     * Test Super Admin bypasses permission check and can view system stock.
     */
    public function test_super_admin_bypasses_permission_check()
    {
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        // Assign Super Admin role
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $this->user->assignRole('Super Admin');

        $product = Product::create([
            'product_name' => 'Semen Padang 50kg',
            'product_code' => 'SP50',
            'product_cost' => 60000,
            'product_price' => 70000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 200,
            'quantity_tax' => 200,
            'quantity_non_tax' => 0,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 0,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $normalized = $resolver->normalizeProductData($product);
        $component->call('productSelected', $normalized);

        $component->assertSee('Stok Sistem (Bagus / Rusak)');
        $component->assertSee('Selisih (Bagus / Rusak)');
        $component->assertSee('200');
    }

    /**
     * Test unauthorized user can complete full counting workflow without system stock disclosures.
     */
    public function test_unauthorized_user_completes_counting_workflow_with_server_held_baseline()
    {
        Permission::findOrCreate('adjustments.view-system-stock', 'web');
        $this->user->revokePermissionTo('adjustments.view-system-stock');

        $product = Product::create([
            'product_name' => 'Cat Tembok Putih 5kg',
            'product_code' => 'CTP5',
            'barcode' => '8991234567890',
            'product_cost' => 80000,
            'product_price' => 100000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 25,
            'quantity_tax' => 25,
            'quantity_non_tax' => 0,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 3,
            'broken_quantity_non_tax' => 0,
        ]);

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        // 1. Scan barcode
        $component->set('scanInput', '8991234567890');
        $component->call('processScan');

        // Physical count incremented to 1
        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals(1, $products[0]['good_count']);

        // Switch to bad condition and increment
        $component->call('setActiveCondition', 'bad');
        $component->set('scanInput', '8991234567890');
        $component->call('processScan');

        $products = $component->get('products');
        $this->assertEquals(1, $products[0]['bad_count']);

        // Directly update good count to 20
        $component->set('products.0.good_count', 20);

        // Baseline must be opaque token only
        $payload = $component->get('countDraftPayload');
        $decoded = json_decode($payload, true);
        $this->assertArrayNotHasKey('existing_good_total', $decoded['rows'][0]['baseline']);
        $this->assertArrayNotHasKey('existing_bad_total', $decoded['rows'][0]['baseline']);
        $this->assertNotEmpty($decoded['rows'][0]['baseline']['token']);

        // Submit via HTTP POST
        $response = $this->post(route('adjustments.store'), [
            'reference' => 'ADJ-UNAUTH-FLOW',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => $payload,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('adjustments', ['reference' => 'ADJ-UNAUTH-FLOW']);

        $savedAdj = Adjustment::where('reference', 'ADJ-UNAUTH-FLOW')->first();
        // Server restored the authoritative baseline from token
        $savedBaseline = $savedAdj->count_draft['rows'][0]['baseline'];
        $this->assertEquals(25, $savedBaseline['existing_good_total']);
        $this->assertEquals(3, $savedBaseline['existing_bad_total']);
    }

    /**
     * Test server-held baseline preserves original count snapshot even when ProductStock changes before save,
     * and rejects invalid/cross-document tokens.
     */
    public function test_server_held_baseline_preservation_and_token_validation()
    {
        $product = Product::create([
            'product_name' => 'Kawat Las 2.6mm',
            'product_code' => 'KL26',
            'product_cost' => 30000,
            'product_price' => 45000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 15,
            'quantity_tax' => 15,
            'quantity_non_tax' => 0,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 1,
            'broken_quantity_non_tax' => 0,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $captured = $resolver->captureBaseline($product->id, $this->location->id);
        $token = $captured['token'];
        $this->assertNotEmpty($token);

        // Simulate stock changed in DB while operator is counting
        $stock->update(['quantity' => 8, 'quantity_tax' => 8]);

        // Submit draft with the opaque token
        $draftPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 15,
                    'bad_count' => 1,
                    'baseline' => [
                        'token' => $token,
                    ],
                ]
            ]
        ];

        $service = app(CountDraftService::class);
        $adj = $service->saveDraft([
            'reference' => 'ADJ-SNAPSHOT-PRESERVE',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => json_encode($draftPayload),
        ]);

        // The authoritative saved baseline must be the original 15 (not the silently updated 8)
        $savedRow = $adj->count_draft['rows'][0];
        $this->assertEquals(15, $savedRow['baseline']['existing_good_total']);
        $this->assertEquals(1, $savedRow['baseline']['existing_bad_total']);

        // Test 1: Expired/invalid token must reject with clear InvalidArgumentException (not silently capture current stock)
        $invalidTokenPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 15,
                    'bad_count' => 1,
                    'baseline' => [
                        'token' => 'non-existent-or-expired-token',
                    ],
                ]
            ]
        ];

        try {
            $service->validateAndStructureDraft($invalidTokenPayload, $this->location->id);
            $this->fail('Expected InvalidArgumentException for invalid or expired baseline token');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak valid atau telah kedaluwarsa', $e->getMessage());
        }

        // Test 2: Cross-document / cross-session reuse for the SAME product and location must be rejected
        $sessionA = (string) \Illuminate\Support\Str::uuid();
        $sessionB = (string) \Illuminate\Support\Str::uuid();

        // Capture snapshot specifically bound to session A
        $snapshotSessionA = $resolver->captureBaseline($product->id, $this->location->id, auth()->id(), $sessionA);
        $tokenSessionA = $snapshotSessionA['token'];

        // Valid submission under session A succeeds
        $validSessionAPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionA,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 8,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $tokenSessionA,
                    ],
                ]
            ]
        ];
        $structuredSessionA = $service->validateAndStructureDraft($validSessionAPayload, $this->location->id);
        $this->assertEquals($sessionA, $structuredSessionA['draft_session_id']);
        $this->assertEquals(8, $structuredSessionA['rows'][0]['baseline']['existing_good_total']);

        // Attempt to reuse session A's token in session B for the exact same product and location
        $reusedSessionBPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionB,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 8,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $tokenSessionA, // Reused from session A!
                    ],
                ]
            ]
        ];

        try {
            $service->validateAndStructureDraft($reusedSessionBPayload, $this->location->id);
            $this->fail('Expected InvalidArgumentException when reusing token across sessions/documents');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak valid atau telah kedaluwarsa', $e->getMessage());
        }

        // Test 3: Replay attack prevention - saving one document, then attempting another document using the SAME token AND SAME session ID
        $sessionC = (string) \Illuminate\Support\Str::uuid();
        $snapshotSessionC = $resolver->captureBaseline($product->id, $this->location->id, auth()->id(), $sessionC);
        $tokenSessionC = $snapshotSessionC['token'];

        $firstDocPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionC,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 8,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $tokenSessionC,
                    ],
                ]
            ]
        ];

        // Save first document
        $firstAdjustment = $service->saveDraft([
            'reference' => 'ADJ-DOC-1',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'count_draft' => json_encode($firstDocPayload),
        ]);
        $this->assertNotNull($firstAdjustment->id);

        // Attempt to create a SECOND document replaying the exact same token and exact same session ID
        $replayedSecondDocPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionC, // Replayed session C
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 8,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $tokenSessionC, // Replayed token C
                    ],
                ]
            ]
        ];

        // Creating a new document (adjustment = null) must reject the replayed token and session
        try {
            $service->validateAndStructureDraft($replayedSecondDocPayload, $this->location->id, null);
            $this->fail('Expected InvalidArgumentException when replaying token and session on a new document');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'sudah digunakan untuk dokumen penyesuaian lain') ||
                str_contains($e->getMessage(), 'tidak valid atau telah kedaluwarsa')
            );
        }

        // Updating the SAME document ($firstAdjustment) with the same token/session succeeds
        $validUpdateDraft = $service->validateAndStructureDraft($replayedSecondDocPayload, $this->location->id, $firstAdjustment);
        $this->assertEquals(8, $validUpdateDraft['rows'][0]['baseline']['existing_good_total']);

        // Test 4: Legacy-adapted snapshots are bound to the legacy adjustment and session
        $legacyAdj = Adjustment::create([
            'reference' => 'ADJ-LEGACY-BIND',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => 'pending',
            'type' => 'normal',
        ]);
        \Modules\Adjustment\Entities\AdjustedProduct::create([
            'adjustment_id' => $legacyAdj->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'is_taxable' => 1,
            'type' => 'normal',
        ]);

        $adapted = $service->adaptLegacyAdjustmentToDraft($legacyAdj);
        $legacyToken = $adapted['rows'][0]['baseline']['token'];
        $this->assertNotEmpty($legacyToken);
        $this->assertEquals("adj-{$legacyAdj->id}", $adapted['draft_session_id']);

        // Attempting to reuse legacy snapshot on another new document must fail
        $tamperedLegacyReuse = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => "adj-{$legacyAdj->id}",
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 5,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $legacyToken,
                    ],
                ]
            ]
        ];
        try {
            $service->validateAndStructureDraft($tamperedLegacyReuse, $this->location->id, null);
            $this->fail('Expected InvalidArgumentException when reusing legacy adapted snapshot on another document');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak valid atau telah kedaluwarsa', $e->getMessage());
        }
    }

    public function test_permission_revocation_scrubs_existing_livewire_state_and_payload(): void
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => 'adjustments.view-system-stock', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

        $product = Product::create([
            'product_name' => 'Cat Tembok Putih 5kg',
            'product_code' => 'CTP-5',
            'product_cost' => 50000,
            'product_price' => 75000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 45,
            'quantity_tax' => 45,
            'quantity_non_tax' => 0,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 3,
            'broken_quantity_non_tax' => 0,
        ]);

        $this->actingAs($user);

        // Mount component with permission - system stock baseline is initially visible in state
        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        // Add product to editor while user has permission
        $component->call('productSelected', ['id' => $product->id]);

        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals(45, $products[0]['baseline']['existing_good_total'] ?? null);

        $payload = json_decode($component->get('countDraftPayload'), true);
        $this->assertEquals(45, $payload['rows'][0]['baseline']['existing_good_total'] ?? null);

        // Revoke permission while editor remains open
        $user->revokePermissionTo('adjustments.view-system-stock');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Trigger subsequent action/response/render (e.g. changing condition)
        $component->call('setActiveCondition', 'bad');

        // Verify public products state has baseline scrubbed
        $scrubbedProducts = $component->get('products');
        $baseline = $scrubbedProducts[0]['baseline'];
        $this->assertArrayNotHasKey('existing_good_total', $baseline);
        $this->assertArrayNotHasKey('existing_good_tax', $baseline);
        $this->assertArrayNotHasKey('existing_bad_total', $baseline);
        $this->assertNotEmpty($baseline['token']);

        // Verify hidden JSON payload also has baseline scrubbed
        $scrubbedPayload = json_decode($component->get('countDraftPayload'), true);
        $payloadBaseline = $scrubbedPayload['rows'][0]['baseline'];
        $this->assertArrayNotHasKey('existing_good_total', $payloadBaseline);
        $this->assertArrayNotHasKey('existing_bad_total', $payloadBaseline);
        $this->assertNotEmpty($payloadBaseline['token']);
    }

    public function test_concurrent_prevalidated_submission_rejects_second_claim(): void
    {
        $product = Product::create([
            'product_name' => 'Concurrent Item',
            'product_code' => 'CI-1',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $service = app(CountDraftService::class);
        $sessionId = (string) \Illuminate\Support\Str::uuid();

        $snapshot = $resolver->captureBaseline($product->id, $this->location->id, auth()->id(), $sessionId);
        $token = $snapshot['token'];

        $payload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionId,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 20,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $token,
                    ],
                ]
            ]
        ];

        // Both requests validate BEFORE either transaction commits
        $structured1 = $service->validateAndStructureDraft($payload, $this->location->id);
        $structured2 = $service->validateAndStructureDraft($payload, $this->location->id);

        // First request saves successfully
        $adj1 = $service->saveDraft([
            'reference' => 'ADJ-RACE-1',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'structured_draft' => $structured1,
        ]);
        $this->assertNotNull($adj1->id);

        // Second request attempts save with pre-validated payload
        // Must fail with clear rejection because snapshot/session is already claimed by adj1
        try {
            $service->saveDraft([
                'reference' => 'ADJ-RACE-2',
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'structured_draft' => $structured2,
            ]);
            $this->fail('Expected rejection when concurrently claiming an already-bound snapshot');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'sudah digunakan oleh dokumen lain') ||
                str_contains($e->getMessage(), 'sudah terikat dengan dokumen lain')
            );
        }
    }

    public function test_database_rollback_releases_snapshot_binding_for_retry(): void
    {
        $product = Product::create([
            'product_name' => 'Rollback Item',
            'product_code' => 'RI-1',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 12,
            'quantity_tax' => 12,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $service = app(CountDraftService::class);
        $sessionId = (string) \Illuminate\Support\Str::uuid();

        $snapshot = $resolver->captureBaseline($product->id, $this->location->id, auth()->id(), $sessionId);
        $token = $snapshot['token'];

        $payload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionId,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 12,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $token,
                    ],
                ]
            ]
        ];

        $structured = $service->validateAndStructureDraft($payload, $this->location->id);

        // Mock the resolver to throw after the header row is created but while
        // still inside saveDraft's transaction, forcing a post-write rollback.
        // (saveDraft no longer notifies on ordinary draft saves, so the
        // notification service can't be used to trigger this anymore.)
        $mockResolver = \Mockery::mock(AdjustmentProductResolver::class);
        $mockResolver->shouldReceive('bindSnapshotToAdjustment')
            ->once()
            ->andThrow(new \RuntimeException('Simulated failure during document post-processing'));
        $this->app->instance(AdjustmentProductResolver::class, $mockResolver);

        // Attempt save: must fail and throw RuntimeException
        try {
            $service->saveDraft([
                'reference' => 'ADJ-ROLLBACK-TEST',
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'structured_draft' => $structured,
            ]);
            $this->fail('Expected exception from mocked resolver');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated failure during document post-processing', $e->getMessage());
        }

        // Verify document was NOT created (rolled back)
        $this->assertDatabaseMissing('adjustments', ['reference' => 'ADJ-ROLLBACK-TEST']);

        // Verify token binding was released: snapshot is NOT bound to any document
        $cachedSnapshot = \Illuminate\Support\Facades\Cache::get("opname_baseline_{$token}");
        $this->assertNull($cachedSnapshot['adjustment_id'] ?? null);

        // Verify session binding was released
        $this->assertNull(\Illuminate\Support\Facades\Cache::get("opname_session_adj_{$sessionId}"));

        // Now restore normal resolver and retry the same draft with the same token and session
        \Mockery::close();
        $this->app->forgetInstance(AdjustmentProductResolver::class);

        $savedAdjustment = $service->saveDraft([
            'reference' => 'ADJ-RETRY-SUCCESS',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'structured_draft' => $structured,
        ]);

        $this->assertNotNull($savedAdjustment->id);
        $this->assertDatabaseHas('adjustments', ['reference' => 'ADJ-RETRY-SUCCESS']);

        // Token is now bound to the newly created document
        $recheckSnapshot = \Illuminate\Support\Facades\Cache::get("opname_baseline_{$token}");
        $this->assertEquals($savedAdjustment->id, $recheckSnapshot['adjustment_id']);
    }

    public function test_failed_edit_retains_original_ownership_and_rejects_replay(): void
    {
        $product = Product::create([
            'product_name' => 'Failed Edit Item',
            'product_code' => 'FEI-1',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $service = app(CountDraftService::class);
        $sessionId = (string) \Illuminate\Support\Str::uuid();

        $snapshot = $resolver->captureBaseline($product->id, $this->location->id, auth()->id(), $sessionId);
        $token = $snapshot['token'];

        $payload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionId,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 20,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $token,
                    ],
                ]
            ]
        ];

        // 1. Initial save of adjustment succeeds
        $structured = $service->validateAndStructureDraft($payload, $this->location->id);
        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-ORIGINAL-OWNER',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'structured_draft' => $structured,
        ]);

        $this->assertNotNull($adjustment->id);
        // Snapshot is now owned by $adjustment->id
        $cachedSnapshot = \Illuminate\Support\Facades\Cache::get("opname_baseline_{$token}");
        $this->assertEquals($adjustment->id, $cachedSnapshot['adjustment_id']);

        // 2. Simulate an edit attempt on this adjustment that fails during notification
        $editPayload = $payload;
        $editPayload['rows'][0]['good_count'] = 22; // User modified count in edit
        $structuredEdit = $service->validateAndStructureDraft($editPayload, $this->location->id, $adjustment);

        $mockResolver = \Mockery::mock(AdjustmentProductResolver::class);
        $mockResolver->shouldReceive('bindSnapshotToAdjustment')
            ->once()
            ->andThrow(new \RuntimeException('Simulated failure during edit notification'));
        $this->app->instance(AdjustmentProductResolver::class, $mockResolver);

        try {
            $service->saveDraft([
                'reference' => 'ADJ-ORIGINAL-OWNER',
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'structured_draft' => $structuredEdit,
            ], $adjustment);
            $this->fail('Expected exception from mocked resolver');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated failure during edit notification', $e->getMessage());
        }

        \Mockery::close();
        $this->app->forgetInstance(AdjustmentProductResolver::class);

        // 3. Verify original adjustment still exists in database
        $this->assertDatabaseHas('adjustments', ['id' => $adjustment->id]);

        // 4. Verify token and session ownership were NOT released (still bound to $adjustment->id)
        $cachedAfterFailedEdit = \Illuminate\Support\Facades\Cache::get("opname_baseline_{$token}");
        $this->assertEquals($adjustment->id, $cachedAfterFailedEdit['adjustment_id']);
        $this->assertEquals($adjustment->id, \Illuminate\Support\Facades\Cache::get("opname_session_adj_{$sessionId}"));

        // 5. Attempt replay: try to create a NEW adjustment using the same token and session
        try {
            $replayStructured = $service->validateAndStructureDraft($payload, $this->location->id);
            $service->saveDraft([
                'reference' => 'ADJ-REPLAY-ATTEMPT',
                'date' => now()->toDateString(),
                'location_id' => $this->location->id,
                'structured_draft' => $replayStructured,
            ]);
            $this->fail('Expected exception for replaying token/session bound to another document');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'sudah digunakan untuk dokumen penyesuaian lain') ||
                str_contains($e->getMessage(), 'sudah digunakan oleh dokumen lain') ||
                str_contains($e->getMessage(), 'sudah terikat dengan dokumen lain')
            );
        }
    }

    public function test_saved_draft_editable_after_snapshot_cache_cleared(): void
    {
        $product = Product::create([
            'product_name' => 'Cache Expired Item',
            'product_code' => 'CEI-1',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 30,
            'quantity_tax' => 30,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $service = app(CountDraftService::class);
        $sessionId = (string) \Illuminate\Support\Str::uuid();

        $snapshot = $resolver->captureBaseline($product->id, $this->location->id, auth()->id(), $sessionId);
        $token = $snapshot['token'];

        $payload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionId,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 30,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $token,
                    ],
                ]
            ]
        ];

        // 1. Initial save of adjustment
        $structured = $service->validateAndStructureDraft($payload, $this->location->id);
        $adjustment = $service->saveDraft([
            'reference' => 'ADJ-CACHE-EXPIRED',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'note' => 'Original note',
            'structured_draft' => $structured,
        ]);

        $this->assertNotNull($adjustment->id);
        $originalBaseline = $adjustment->count_draft['rows'][0]['baseline'];
        $this->assertEquals(30, $originalBaseline['existing_good_total']);

        // 2. Simulate cache expiry / cache clear (e.g. 7 days later or php artisan cache:clear)
        \Illuminate\Support\Facades\Cache::flush();

        // Verify cache entries are completely gone
        $this->assertNull(\Illuminate\Support\Facades\Cache::get("opname_baseline_{$token}"));
        $this->assertNull(\Illuminate\Support\Facades\Cache::get("opname_session_adj_{$sessionId}"));

        // Simulate stock changed in DB between the 7 days (e.g. sales happened, stock dropped to 25)
        ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->update([
                'quantity' => 25,
                'quantity_tax' => 25,
            ]);

        // 3. User edits the saved draft (updating note and physical count)
        $editPayload = [
            'schema_version' => 1,
            'location_id' => $this->location->id,
            'draft_session_id' => $sessionId,
            'rows' => [
                [
                    'product_id' => $product->id,
                    'good_count' => 28,
                    'bad_count' => 0,
                    'baseline' => [
                        'token' => $token, // Token sent from client/table state
                    ],
                ]
            ]
        ];

        // Validation against existing adjustment must preserve the persisted database-held baseline (30, NOT 25)
        $structuredEdit = $service->validateAndStructureDraft($editPayload, $this->location->id, $adjustment);
        $this->assertEquals(30, $structuredEdit['rows'][0]['baseline']['existing_good_total']);

        // Saving the edit must succeed despite snapshot cache expiry
        $updatedAdjustment = $service->saveDraft([
            'reference' => 'ADJ-CACHE-EXPIRED',
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'note' => 'Updated note after cache clear',
            'structured_draft' => $structuredEdit,
        ], $adjustment);

        // BaseModel automatically uppercases string attributes
        $this->assertEquals(strtoupper('Updated note after cache clear'), $updatedAdjustment->note);
        $savedRows = $updatedAdjustment->count_draft['rows'];
        $this->assertEquals(28, $savedRows[0]['good_count']);
        // Crucial: original baseline of 30 was preserved, NOT overwritten with current stock 25
        $this->assertEquals(30, $savedRows[0]['baseline']['existing_good_total']);
    }

    // --- Correction: productSelected() must add exactly one row per genuine
    // selection and never surface both the generic session flash alert and
    // the component's own feedbackMessage for a duplicate selection. ---

    private function makeSelectableProduct(string $name, string $code): Product
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => $code,
            'product_cost' => 1000,
            'product_price' => 2000,
            'setting_id' => $this->setting->id,
            'unit_id' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'is_active' => true,
        ]);
    }

    public function test_first_product_selection_adds_exactly_one_row_with_only_success_feedback()
    {
        $product = $this->makeSelectableProduct('Produk Pertama', 'FIRST-1');

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $normalized = $resolver->normalizeProductData($product);
        $component->call('productSelected', $normalized);

        $this->assertCount(1, $component->get('products'));
        $this->assertEquals(
            "Produk 'Produk Pertama' berhasil ditambahkan ke daftar.",
            $component->get('feedbackMessage')
        );
        $this->assertEquals('success', $component->get('feedbackType'));

        // No generic session-flash alert coexisting with the component's own message.
        $this->assertFalse(session()->has('message'));
    }

    public function test_second_selection_of_same_product_keeps_one_row_and_shows_only_duplicate_feedback()
    {
        $product = $this->makeSelectableProduct('Produk Kedua', 'SECOND-1');

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $normalized = $resolver->normalizeProductData($product);

        $component->call('productSelected', $normalized);
        $this->assertCount(1, $component->get('products'));

        $component->call('productSelected', $normalized);

        // Still exactly one row: the duplicate selection must not add a second row.
        $this->assertCount(1, $component->get('products'));
        $this->assertEquals(
            "Produk 'Produk Kedua' sudah ada di daftar (baris 1).",
            $component->get('feedbackMessage')
        );
        $this->assertEquals('info', $component->get('feedbackType'));

        // The generic "Produk sudah dipilih." session alert must never appear
        // alongside (or instead of) the component's own duplicate message.
        $this->assertFalse(session()->has('message'));
        $this->assertNotEquals('Produk sudah dipilih.', session('message'));
    }

    public function test_selecting_two_distinct_products_produces_two_rows()
    {
        $productA = $this->makeSelectableProduct('Produk A', 'DIST-A');
        $productB = $this->makeSelectableProduct('Produk B', 'DIST-B');

        $component = Livewire::test(AdjustmentProductTable::class, [
            'locationId' => $this->location->id,
        ]);

        $resolver = app(AdjustmentProductResolver::class);
        $component->call('productSelected', $resolver->normalizeProductData($productA));
        $component->call('productSelected', $resolver->normalizeProductData($productB));

        $products = $component->get('products');
        $this->assertCount(2, $products);
        $this->assertEquals('Produk A', $products[0]['product_name']);
        $this->assertEquals('Produk B', $products[1]['product_name']);
        $this->assertEquals(
            "Produk 'Produk B' berhasil ditambahkan ke daftar.",
            $component->get('feedbackMessage')
        );
        $this->assertFalse(session()->has('message'));
    }
}


