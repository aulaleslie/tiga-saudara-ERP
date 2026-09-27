<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\ForwardDispatchPreparationService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Tests\TestCase;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;

class ForwardDispatchPreparationAndScannerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $nonSerializedProduct;
    protected Product $serializedProduct;
    protected ProductUnitConversion $boxConversion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Warehouse',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Warehouse',
        ]);

        $baseUnit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $boxUnit  = Unit::create(['name' => 'Box', 'short_name' => 'box']);

        $this->nonSerializedProduct = Product::create([
            'product_name'           => 'Widget Non-Serialized',
            'product_code'           => 'WNS-001',
            'barcode'                => 'BARCODE-WNS-1',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $baseUnit->id,
            'product_quantity'       => 100,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $this->boxConversion = ProductUnitConversion::create([
            'product_id'        => $this->nonSerializedProduct->id,
            'base_unit_id'      => $baseUnit->id,
            'unit_id'           => $boxUnit->id,
            'conversion_factor' => 12,
            'barcode'           => 'BOX-BARCODE-12',
        ]);

        ProductStock::create([
            'product_id'              => $this->nonSerializedProduct->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 100,
            'quantity_non_tax'        => 100,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        $this->serializedProduct = Product::create([
            'product_name'           => 'Laptop Serialized',
            'product_code'           => 'LAP-001',
            'barcode'                => 'BARCODE-LAP-1',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $baseUnit->id,
            'product_quantity'       => 5,
            'product_cost'           => 5000000,
            'product_price'          => 7000000,
            'serial_number_required' => true,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->serializedProduct->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 5,
            'quantity_non_tax'        => 5,
            'quantity_tax'            => 0,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);

        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'LAP-SN-001',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'LAP-SN-002',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
        ProductSerialNumber::create([
            'product_id'    => $this->serializedProduct->id,
            'location_id'   => $this->origin->id,
            'serial_number' => 'LAP-SN-003',
            'status'        => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken'     => false,
        ]);
    }

    /** @test */
    public function get_or_create_draft_seeds_approved_products_as_unconfirmed_zero(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->nonSerializedProduct->id,
            'quantity'    => 10,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        $this->assertEquals(TransferMovement::STATUS_DRAFT, $movement->status);
        $this->assertEquals(1, $movement->lines->count());

        $line = $movement->lines->first();
        $this->assertEquals((int) $this->nonSerializedProduct->id, (int) $line->product_id);
        $this->assertEquals('0.0000', (string) $line->quantity);
        $this->assertFalse($line->count_confirmed);
    }

    /** @test */
    public function scan_accumulates_base_and_conversion_barcodes_and_confirms_line(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->nonSerializedProduct->id,
            'quantity'    => 20,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        // Scan base unit barcode (1 unit)
        $res1 = $prepService->applyScan($movement, 'BARCODE-WNS-1', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $res1['status']);
        $movement = $movement->fresh(['lines.serials']);

        // Scan conversion box barcode (12 units)
        $res2 = $prepService->applyScan($movement, 'BOX-BARCODE-12', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $res2['status']);
        $movement = $movement->fresh(['lines.serials']);

        $line = $movement->lines->first();
        $this->assertEquals('13.0000', (string) $line->quantity);
        $this->assertTrue($line->count_confirmed);
    }

    /** @test */
    public function scan_serial_prevents_duplicate_selection_and_supports_alternate_serials(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id'    => $transfer->id,
            'product_id'     => $this->serializedProduct->id,
            'quantity'       => 1,
            'serial_numbers' => [['serial_number' => 'LAP-SN-001']],
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        // Scan alternate serial LAP-SN-002
        $res1 = $prepService->applyScan($movement, 'LAP-SN-002', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $res1['status']);
        $movement = $movement->fresh(['lines.serials']);

        // Attempt duplicate scan of LAP-SN-002
        $res2 = $prepService->applyScan($movement, 'lap-sn-002', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('rejected', $res2['status']);

        $line = $movement->lines->first();
        $this->assertEquals('1.0000', (string) $line->quantity);
        $this->assertCount(1, $line->serials);
        $this->assertEquals('LAP-SN-002', $line->serials->first()->serial_number);
    }

    /** @test */
    public function unconfirmed_approved_product_blocks_submission_while_confirmed_zero_passes(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->nonSerializedProduct->id,
            'quantity'    => 5,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        // Attempt submit while line is still unconfirmed zero
        try {
            $prepService->submit($movement, $movement->lock_version, $this->user->id);
            $this->fail('Submission should have failed due to unconfirmed line.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Every approved product must have its count explicitly confirmed', $e->getMessage());
        }

        // Explicitly confirm zero
        $updated = $prepService->setLineQuantity($movement, $this->nonSerializedProduct->id, 0, true, $movement->lock_version, $this->user->id);

        // Submit now succeeds and movement becomes PENDING
        $submitted = $prepService->submit($updated, $updated->lock_version, $this->user->id);
        $this->assertEquals(TransferMovement::STATUS_PENDING, $submitted->status);
    }

    /** @test */
    public function scan_allows_observation_of_non_serialized_product_with_zero_system_stock(): void
    {
        // Zero out stock of non-serialized product at origin location
        ProductStock::where('product_id', $this->nonSerializedProduct->id)
            ->where('location_id', $this->origin->id)
            ->update([
                'quantity'         => 0,
                'quantity_non_tax' => 0,
                'quantity_tax'     => 0,
            ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->nonSerializedProduct->id,
            'quantity'    => 5,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        // Scan barcode of product with 0 stock
        $res = $prepService->applyScan($movement, 'BARCODE-WNS-1', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('resolved', $res['status']);

        $movement = $movement->fresh(['lines']);
        $line = $movement->lines->first();
        $this->assertEquals('1.0000', (string) $line->quantity);
        $this->assertTrue($line->count_confirmed);
    }

    /** @test */
    public function scan_serialized_product_barcode_rejects_and_prompts_for_serial(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->serializedProduct->id,
            'quantity'    => 1,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        // Scan barcode of the serialized product (BARCODE-LAP-1)
        $res = $prepService->applyScan($movement, 'BARCODE-LAP-1', $this->setting->id, $movement->lock_version, $this->user->id);
        $this->assertEquals('rejected', $res['status']);
        $this->assertStringContainsString('memerlukan nomor seri', $res['message']);

        // Line quantity remains 0
        $movement = $movement->fresh(['lines.serials']);
        $line = $movement->lines->first();
        $this->assertEquals('0.0000', (string) $line->quantity);
        $this->assertCount(0, $line->serials);
    }

    /** @test */
    public function set_line_quantity_forbids_manual_positive_quantity_for_serialized_product(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->serializedProduct->id,
            'quantity'    => 1,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kuantitas produk dengan nomor seri tidak dapat diubah secara manual');

        $prepService->setLineQuantity($movement, $this->serializedProduct->id, 2, true, $movement->lock_version, $this->user->id);
    }

    /** @test */
    public function set_line_quantity_forbids_cross_tenant_products(): void
    {
        $otherSetting = Setting::create([
            'company_name'   => 'Other Company',
            'company_email'  => 'other@example.com',
            'company_phone'  => '081234567891',
            'notification_email' => 'other@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
        ]);

        $otherProduct = Product::create([
            'setting_id'    => $otherSetting->id,
            'product_name'  => 'Cross Tenant Non-Serialized',
            'product_code'  => 'CTNS-001',
            'barcode'       => 'CTNS-BARCODE-1',
            'stock_managed' => true,
            'product_cost'  => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
        ]);

        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->user);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->nonSerializedProduct->id,
            'quantity'    => 5,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $movement = $prepService->getOrCreateDraft($transfer, $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Produk tidak valid atau tidak terdaftar pada unit bisnis asal.');

        $prepService->setLineQuantity($movement, $otherProduct->id, 5, true, $movement->lock_version, $this->user->id);
    }
}
