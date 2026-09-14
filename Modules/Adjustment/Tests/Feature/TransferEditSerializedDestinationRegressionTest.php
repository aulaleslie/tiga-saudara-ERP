<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferStockForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Livewire\LocationSearchDropdown;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferEditSerializedDestinationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $blindUser;
    private User $privilegedUser;
    private Location $origin;
    private Location $destination;
    private Location $otherDestination;
    private Location $inactiveDestination;
    private Product $serializedProduct;
    private ProductSerialNumber $serial1;
    private ProductSerialNumber $serial2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.show',
            'stockTransfers.edit',
            TransferStockVisibility::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        $this->origin = Location::factory()->create([
            'setting_id' => $this->setting->id,
            'name' => 'Origin Warehouse',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->destination = Location::factory()->create([
            'setting_id' => $this->setting->id,
            'name' => 'Destination Branch',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->otherDestination = Location::factory()->create([
            'setting_id' => $this->setting->id,
            'name' => 'Other Destination Branch',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->inactiveDestination = Location::factory()->create([
            'setting_id' => $this->setting->id,
            'name' => 'Inactive Destination Branch',
            'is_active' => false,
            'is_consignment' => false,
        ]);

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo(['stockTransfers.access', 'stockTransfers.create', 'stockTransfers.edit']);

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            TransferStockVisibility::PERMISSION,
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->privilegedUser->id,
        ]);

        $this->serializedProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Serialized Camera',
            'product_code' => 'CAM-' . uniqid(),
            'product_cost' => 1000000,
            'product_price' => 1500000,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->serializedProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $tax = \Illuminate\Support\Facades\DB::table('taxes')->insertGetId([
            'name' => 'PPN',
            'value' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->serial1 = ProductSerialNumber::create([
            'product_id' => $this->serializedProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'CAM-SN-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'tax_id' => $tax,
            'is_broken' => 0,
        ]);

        $this->serial2 = ProductSerialNumber::create([
            'product_id' => $this->serializedProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'CAM-SN-002',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'tax_id' => $tax,
            'is_broken' => 0,
        ]);
    }

    private function createDestinationlessDraft(): Transfer
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => Transfer::STATUS_DRAFT,
            'created_by' => $this->blindUser->id,
            'revision' => 1,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->serializedProduct->id,
            'quantity' => 2,
            'quantity_tax' => 2,
            'quantity_non_tax' => 0,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
            'serial_numbers' => [
                [
                    'id' => $this->serial1->id,
                    'serial_number' => $this->serial1->serial_number,
                    'tax_id' => $this->serial1->tax_id,
                    'is_broken' => false,
                ],
                [
                    'id' => $this->serial2->id,
                    'serial_number' => $this->serial2->serial_number,
                    'tax_id' => $this->serial2->tax_id,
                    'is_broken' => false,
                ],
            ],
        ]);

        return $transfer;
    }

    /** @test */
    public function task_3_1_and_3_2_blind_edit_destinationless_draft_selects_destination_via_dropdown_and_saves_preserving_tax_allocation()
    {
        $transfer = $this->createDestinationlessDraft();

        // 1. Mount parent form for blind user
        $form = Livewire::actingAs($this->blindUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        // Assert blind snapshot projection omits protected fields
        $rows = $form->get('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals(2, $rows[0]['requested_quantity']);
        $this->assertArrayNotHasKey('quantity_tax', $rows[0]);
        $this->assertArrayNotHasKey('quantity_non_tax', $rows[0]);
        $this->assertArrayNotHasKey('broken_quantity_tax', $rows[0]);
        $this->assertArrayNotHasKey('broken_quantity_non_tax', $rows[0]);
        $this->assertArrayNotHasKey('stock', $rows[0]);

        // Serial numbers are projected down to identity only
        $serials = $rows[0]['serial_numbers'];
        $this->assertCount(2, $serials);
        $this->assertArrayNotHasKey('tax_id', $serials[0]);
        $this->assertArrayNotHasKey('taxable', $serials[0]);
        $this->assertArrayNotHasKey('is_broken', $serials[0]);

        // 2. Verify rendered Blade template compiles nested destination dropdown with :selected, without wire:model, and without dispatchTo
        $compiledBlade = \Illuminate\Support\Facades\Blade::compileString(
            file_get_contents(resource_path('views/livewire/transfer/transfer-stock-form.blade.php'))
        );
        $destinationSection = substr($compiledBlade, (int) strpos($compiledBlade, 'Lokasi Tujuan'));
        $this->assertStringContainsString("'name' => 'destination_location'", $destinationSection);
        $this->assertStringContainsString("'selected' => \$destinationLocation", $destinationSection);
        
        $destinationParamsBlock = substr($destinationSection, 0, (int) strpos($destinationSection, 'destination-location-dropdown'));
        $this->assertStringNotContainsString('wire:model', $destinationParamsBlock);
        $this->assertStringNotContainsString('dispatchTo', $destinationParamsBlock);

        // 3. Verify Save / Submit buttons have Alpine :disabled, form submit guard, local startDestinationPending capture, and wire:loading.attr="disabled"
        $this->assertStringContainsString('isDestinationPending', $compiledBlade);
        $this->assertStringContainsString('startDestinationPending()', $compiledBlade);
        $this->assertStringContainsString('x-on:submit.prevent="if (isDestinationPending || Boolean(destinationSyncError))', $compiledBlade);
        $this->assertStringContainsString(':disabled="isDestinationPending || Boolean(destinationSyncError)"', $compiledBlade);
        $this->assertStringContainsString('destination-selection-confirmed', $compiledBlade);
        $this->assertStringContainsString('location-dropdown-selection-rejected', $compiledBlade);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $compiledBlade);
        $this->assertStringContainsString('locationDropdownSelected', $compiledBlade);

        // 4. Select destination in LocationSearchDropdown and verify bubbling event contract (no dispatchTo)
        $dropdown = Livewire::actingAs($this->blindUser)
            ->test(LocationSearchDropdown::class, [
                'name' => 'destination_location',
                'placeholder' => 'Pilih Lokasi Tujuan...',
                'excludedLocationIds' => [$this->origin->id],
                'crossBusiness' => true,
            ])
            ->call('select', (string) $this->destination->id);

        $dropdown->assertDispatched('locationDropdownSelected', function ($event, $params) {
            return ($params['name'] ?? null) === 'destination_location'
                && (int) ($params['value'] ?? null) === (int) $this->destination->id;
        });

        // 5. Deliver the bubbled event to the mounted parent form (WITHOUT manual $form->set)
        $form->dispatch('locationDropdownSelected', 'destination_location', (string) $this->destination->id);
        $this->assertEquals($this->destination->id, $form->get('destinationLocation'));
        $this->assertCount(1, $form->get('rows'), 'Product rows must not be cleared on destination update');
        $form->assertDispatched('destination-selection-confirmed', function ($event, $params) {
            $data = $params[0] ?? $params;
            return (int) ($data['destinationLocationId'] ?? 0) === (int) $this->destination->id;
        });
        $form->assertDispatched('locationsConfirmed', function ($event, $params) {
            $data = $params[0] ?? $params;
            return (int) ($data['destinationLocationId'] ?? 0) === (int) $this->destination->id;
        });

        // 6. Save draft without manually setting any parent destination property
        $form->call('saveDraft');
        $form->assertHasNoErrors();
        $form->assertRedirect(route('transfers.index'));

        // 7. Assert database state: destination updated, requested quantity preserved, serial identities preserved, authoritative taxed allocation derived
        $transfer->refresh();
        $this->assertEquals($this->destination->id, $transfer->destination_location_id);
        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->status);

        $this->assertCount(1, $transfer->products);
        $tp = $transfer->products->first();
        $this->assertEquals(2, $tp->quantity);
        $this->assertEquals(2, $tp->quantity_tax);
        $this->assertEquals(0, $tp->quantity_non_tax);

        $persistedSerials = collect($tp->serial_numbers)->pluck('id')->all();
        $this->assertContains($this->serial1->id, $persistedSerials);
        $this->assertContains($this->serial2->id, $persistedSerials);
    }

    /** @test */
    public function task_3_3_blind_serialized_row_with_mismatched_serial_count_fails_validation_without_mutating()
    {
        $transfer = $this->createDestinationlessDraft();
        $initialRevision = $transfer->revision;

        $form = Livewire::actingAs($this->blindUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        // Row has requested_quantity 2, but serial_numbers only has 1 serial ID
        $form->set('rows', [
            [
                'id' => $this->serializedProduct->id,
                'product_name' => $this->serializedProduct->product_name,
                'requested_quantity' => 2,
                'serial_number_required' => true,
                'serial_numbers' => [
                    ['id' => $this->serial1->id, 'serial_number' => 'CAM-SN-001'],
                ],
            ],
        ]);

        $form->call('saveDraft');

        // Assert no redirect and validation error dispatched
        $form->assertNoRedirect();
        $dispatches = $form->effects['dispatches'] ?? [];
        $hasTableError = collect($dispatches)->contains(function ($dispatch) {
            if (($dispatch['name'] ?? '') !== 'tableValidationErrors') {
                return false;
            }
            $params = $dispatch['params'][0] ?? [];
            return isset($params['products.0.requested_quantity']);
        });
        $this->assertTrue($hasTableError, 'Expected tableValidationErrors dispatch containing products.0.requested_quantity error.');

        // Assert transfer, lines, and revision remain unmutated in database
        $transfer->refresh();
        $this->assertNull($transfer->destination_location_id);
        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->status);
        $this->assertEquals($initialRevision, $transfer->revision);
        $this->assertCount(1, $transfer->products);
        $this->assertCount(2, $transfer->products->first()->serial_numbers);
    }

    /** @test */
    public function task_3_3_blind_serialized_row_with_duplicate_serial_ids_fails_validation_without_mutating()
    {
        $transfer = $this->createDestinationlessDraft();
        $initialRevision = $transfer->revision;

        $form = Livewire::actingAs($this->blindUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        // Row with duplicate serial ID (same serial passed twice, deduplicated count = 1 != requested_quantity 2)
        $form->set('rows', [
            [
                'id' => $this->serializedProduct->id,
                'product_name' => $this->serializedProduct->product_name,
                'requested_quantity' => 2,
                'serial_number_required' => true,
                'serial_numbers' => [
                    ['id' => $this->serial1->id, 'serial_number' => 'CAM-SN-001'],
                    ['id' => $this->serial1->id, 'serial_number' => 'CAM-SN-001'],
                ],
            ],
        ]);

        $form->call('saveDraft');

        // Assert no redirect and validation error dispatched
        $form->assertNoRedirect();
        $dispatches = $form->effects['dispatches'] ?? [];
        $hasTableError = collect($dispatches)->contains(function ($dispatch) {
            if (($dispatch['name'] ?? '') !== 'tableValidationErrors') {
                return false;
            }
            $params = $dispatch['params'][0] ?? [];
            return isset($params['products.0.requested_quantity']);
        });
        $this->assertTrue($hasTableError, 'Expected tableValidationErrors dispatch containing products.0.requested_quantity error.');

        // Assert transfer, lines, and revision remain unmutated in database
        $transfer->refresh();
        $this->assertNull($transfer->destination_location_id);
        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->status);
        $this->assertEquals($initialRevision, $transfer->revision);
        $this->assertCount(1, $transfer->products);
        $this->assertCount(2, $transfer->products->first()->serial_numbers);
    }

    /** @test */
    public function task_3_3_authoritatively_ineligible_serial_state_rejects_save_without_partial_mutation()
    {
        $transfer = $this->createDestinationlessDraft();
        $initialRevision = $transfer->revision;

        // Serial becomes dispatched before editor saves
        $this->serial1->update(['dispatch_detail_id' => 999]);

        $form = Livewire::actingAs($this->blindUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->set('destinationLocation', $this->destination->id)
            ->call('saveDraft');

        $form->assertNoRedirect();

        // Destination, lines, and revision were not mutated on the transfer
        $transfer->refresh();
        $this->assertNull($transfer->destination_location_id);
        $this->assertEquals($initialRevision, $transfer->revision);
    }

    /** @test */
    public function task_3_3_invalid_destination_selection_is_rejected_without_partial_draft_mutation()
    {
        $transfer = $this->createDestinationlessDraft();
        $initialRevision = $transfer->revision;

        // Same origin and destination
        $form = Livewire::actingAs($this->blindUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->set('destinationLocation', $this->origin->id)
            ->call('saveDraft');

        $form->assertNoRedirect();
        $this->assertArrayHasKey('destination_location', $form->get('selfManagedValidationErrors'));

        $transfer->refresh();
        $this->assertNull($transfer->destination_location_id);
        $this->assertEquals($initialRevision, $transfer->revision);
    }

    /** @test */
    public function task_3_4_privileged_edit_preserves_bucket_aware_validation_and_saves_successfully()
    {
        $transfer = $this->createDestinationlessDraft();

        $form = Livewire::actingAs($this->privilegedUser)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        $rows = $form->get('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals(2, $rows[0]['quantity_tax']);
        $this->assertArrayHasKey('stock', $rows[0]);

        $form->set('destinationLocation', $this->destination->id)
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertRedirect(route('transfers.index'));

        $transfer->refresh();
        $this->assertEquals($this->destination->id, $transfer->destination_location_id);
        $this->assertEquals(2, $transfer->products->first()->quantity_tax);
    }

    /** @test */
    public function task_3_3_rejected_dropdown_selection_dispatches_rejection_acknowledgement_event()
    {
        // Attempting to select excluded origin location in destination dropdown dispatches rejection event
        $dropdown = Livewire::actingAs($this->blindUser)
            ->test(LocationSearchDropdown::class, [
                'name' => 'destination_location',
                'placeholder' => 'Pilih Lokasi Tujuan...',
                'excludedLocationIds' => [$this->origin->id],
                'crossBusiness' => true,
            ])
            ->call('select', (string) $this->origin->id);

        $dropdown->assertDispatched('location-dropdown-selection-rejected', function ($event, $params) {
            return ($params['name'] ?? null) === 'destination_location'
                && (int) ($params['value'] ?? null) === (int) $this->origin->id;
        });
        $this->assertNull($dropdown->get('selected'));
    }
}

