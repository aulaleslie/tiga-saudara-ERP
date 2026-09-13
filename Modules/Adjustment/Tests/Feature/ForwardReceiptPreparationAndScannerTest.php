<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Services\ForwardReceiptPreparationService;
use Modules\Adjustment\Services\ForwardReceiptProjectionService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;

class ForwardReceiptPreparationAndScannerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Product $serializedProduct;
    protected Product $otherTenantProduct;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ForwardReceiptPreparationService $preparationService;
    protected ForwardReceiptProjectionService $projectionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $packUnit = Unit::create(['name' => 'Pack of 10', 'short_name' => 'pack', 'operator' => '*', 'operation_value' => 10]);

        $this->product = Product::create([
            'product_name'           => 'Standard Widget',
            'product_code'           => 'WID-001',
            'barcode'                => 'BAR-WID-1',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 0,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductUnitConversion::create([
            'product_id'        => $this->product->id,
            'base_unit_id'      => $unit->id,
            'unit_id'           => $packUnit->id,
            'conversion_factor' => 10,
            'barcode'           => 'BAR-PACK-10',
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Serialized Device',
            'product_code'           => 'DEV-001',
            'barcode'                => 'BAR-DEV-1',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 0,
            'product_cost'           => 5000,
            'product_price'          => 7000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        $otherSetting = Setting::factory()->create();
        $this->otherTenantProduct = Product::create([
            'product_name'           => 'Other Tenant Product',
            'product_code'           => 'OTHER-001',
            'barcode'                => 'BAR-OTHER-1',
            'setting_id'             => $otherSetting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 0,
            'product_cost'           => 100,
            'product_price'          => 200,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->dispatchMovement = TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_DISPATCH,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $this->origin->id,
            'destination_location_id'  => $this->destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->user->id,
            'reviewed_by'              => $this->user->id,
            'reviewed_at'              => now(),
        ]);

        $this->preparationService = app(ForwardReceiptPreparationService::class);
        $this->projectionService = app(ForwardReceiptProjectionService::class);
    }

    public function test_get_or_create_draft_starts_empty_without_seeded_lines(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $this->assertEquals(TransferMovement::TYPE_FORWARD_RECEIPT, $movement->type);
        $this->assertEquals(TransferMovement::STATUS_DRAFT, $movement->status);
        $this->assertEquals($this->destination->id, $movement->destination_location_id);
        $this->assertCount(0, $movement->lines);
        $this->assertFalse($movement->empty_count_confirmed);
    }

    public function test_scan_barcode_with_unit_conversion_applies_base_quantity(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $result = $this->preparationService->applyScan($movement, 'BAR-PACK-10', $this->setting->id, $movement->lock_version, $this->user->id);

        $this->assertEquals('resolved', $result['status']);

        $movement->refresh();
        $this->assertCount(1, $movement->lines);
        $this->assertEquals(10, (int) $movement->lines->first()->quantity);
        $this->assertFalse($movement->empty_count_confirmed);
    }

    public function test_scan_allows_zero_stock_destination_product(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $result = $this->preparationService->applyScan($movement, 'BAR-WID-1', $this->setting->id, $movement->lock_version, $this->user->id);

        $this->assertEquals('resolved', $result['status']);

        $movement->refresh();
        $this->assertCount(1, $movement->lines);
        $this->assertEquals(1, (int) $movement->lines->first()->quantity);
    }

    public function test_scan_cross_tenant_product_fails(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $result = $this->preparationService->applyScan($movement, 'BAR-OTHER-1', $this->setting->id, $movement->lock_version, $this->user->id);

        $this->assertContains($result['status'], ['rejected', 'not_found']);
    }

    public function test_scan_serial_number_records_observation_even_if_substitute(): void
    {
        $sn = ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'serial_number' => 'SN-SUB-999',
            'status'        => 'active',
            'location_id'   => $this->destination->id,
        ]);

        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $result = $this->preparationService->applyScan($movement, 'SN-SUB-999', $this->setting->id, $movement->lock_version, $this->user->id);

        $this->assertEquals('resolved', $result['status']);

        $movement->refresh();
        $this->assertCount(1, $movement->lines);
        $this->assertCount(1, $movement->lines->first()->serials);
        $this->assertEquals('SN-SUB-999', $movement->lines->first()->serials->first()->serial_number);
    }

    public function test_duplicate_serial_scan_in_same_receipt_is_rejected(): void
    {
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'serial_number' => 'SN-DUP-001',
            'status'        => 'active',
            'location_id'   => $this->destination->id,
        ]);

        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);
        $res1 = $this->preparationService->applyScan($movement, 'SN-DUP-001', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $res1['status']);

        $movement->refresh();
        $res2 = $this->preparationService->applyScan($movement, 'SN-DUP-001', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('rejected', $res2['status']);
    }

    public function test_serialized_product_cannot_mutate_quantity_manually(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nomor seri tidak dapat diubah secara manual');

        $this->preparationService->setLineQuantity($movement, $this->serializedProduct->id, 5, true, $movement->lock_version, $this->user->id);
    }

    public function test_explicit_empty_count_confirmation_and_invalidation(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $movement = $this->preparationService->confirmEmpty($movement, $movement->lock_version, $this->user->id);
        $this->assertTrue($movement->empty_count_confirmed);
        $this->assertEquals($this->user->id, $movement->empty_count_confirmed_by);
        $this->assertNotNull($movement->empty_count_confirmed_at);

        // Adding an observation resets empty confirmation
        $this->preparationService->applyScan($movement, 'BAR-WID-1', $this->setting->id, $movement->lock_version, $this->user->id);
        $movement->refresh();
        $this->assertFalse($movement->empty_count_confirmed);
        $this->assertNull($movement->empty_count_confirmed_by);
        $this->assertNull($movement->empty_count_confirmed_at);
    }

    public function test_submitting_empty_receipt_without_confirmation_throws(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dikonfirmasi');

        $this->preparationService->submit($movement, $movement->lock_version, $this->user->id);
    }

    public function test_submitting_confirmed_empty_receipt_becomes_pending(): void
    {
        $movement = $this->preparationService->getOrCreateDraft($this->transfer, $this->user->id);
        $movement = $this->preparationService->confirmEmpty($movement, $movement->lock_version, $this->user->id);

        $submitted = $this->preparationService->submit($movement, $movement->lock_version, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_PENDING, $submitted->status);
        $this->assertTrue($submitted->empty_count_confirmed);
    }
}
