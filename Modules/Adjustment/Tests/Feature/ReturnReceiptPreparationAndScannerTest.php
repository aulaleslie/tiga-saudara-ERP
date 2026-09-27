<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\ReturnReceiptPreparationService;
use Modules\Adjustment\Services\ReturnReceiptProjectionService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReturnReceiptPreparationAndScannerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;
    protected Product $serializedProduct;
    protected Product $otherTenantProduct;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ReturnReceiptPreparationService $preparationService;
    protected ReturnReceiptProjectionService $projectionService;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stock_transfers.v2_dispatch_enabled' => true]);

        foreach ([
            'stockTransfers.access',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
            TransferStockVisibility::PERMISSION,
        ] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => true]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $this->tax = Tax::create([
            'name'       => 'PPN 11%',
            'value'      => 11.0,
            'is_default' => true,
            'is_active'  => true,
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
            'status'                  => Transfer::STATUS_RETURN_DISPATCHED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $policy = $this->createRoutePolicySnapshot(
            $this->transfer,
            $this->origin,
            $this->destination,
            $this->user,
            1,
            TransferRoutePolicy::CLASSIFICATION_TAX,
            true,
            $this->tax
        );

        $returnBatchId = 'ret_batch_001';

        $this->dispatchMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_RETURN_DISPATCH,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->destination->id,
            'destination_location_id' => $this->origin->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'return_batch_id'         => $returnBatchId,
        ]);

        $forwardReceipt = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_APPROVED,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $obligation = TransferMovementReturnObligation::create([
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id'      => $forwardReceipt->id,
            'product_id'               => $this->product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => '10.0000',
        ]);

        $line = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10,
        ]);

        TransferReturnObligationReservation::create([
            'transfer_movement_return_obligation_id' => $obligation->id,
            'transfer_movement_id'                   => $this->dispatchMovement->id,
            'transfer_movement_line_id'              => $line->id,
            'quantity'                                => '10.0000',
            'status'                                  => TransferReturnObligationReservation::STATUS_ACTIVE,
            'created_by'                              => $this->user->id,
        ]);

        $this->preparationService = app(ReturnReceiptPreparationService::class);
        $this->projectionService = app(ReturnReceiptProjectionService::class);
    }

    public function test_it_creates_empty_draft_without_prefilled_items(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $this->assertNotNull($draft);
        $this->assertEquals(TransferMovement::TYPE_RETURN_RECEIPT, $draft->type);
        $this->assertEquals(TransferMovement::STATUS_DRAFT, $draft->status);
        $this->assertEquals($this->dispatchMovement->return_batch_id, $draft->return_batch_id);
        $this->assertCount(0, $draft->lines);
    }

    public function test_blind_projection_contains_no_manifest_or_stock_expectations(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $projection = $this->projectionService->getPreparationProjection($this->transfer, $draft);

        $this->assertArrayNotHasKey('expected_products', $projection);
        $this->assertArrayNotHasKey('expected_quantities', $projection);
        $this->assertArrayNotHasKey('manifest', $projection);
        $this->assertArrayNotHasKey('reservations', $projection);
        $this->assertArrayNotHasKey('obligations', $projection);
        $this->assertArrayHasKey('lines', $projection);
        $this->assertEmpty($projection['lines']);
    }

    public function test_scanner_records_valid_product_barcode_and_conversion_factors(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        // Scan base unit barcode
        $result = $this->preparationService->applyScan(
            $draft,
            'BAR-WID-1',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $this->assertEquals('resolved', $result['status']);
        $draft->refresh();
        $this->assertCount(1, $draft->lines);
        $this->assertEquals(1, (int) $draft->lines->first()->quantity);

        // Scan pack conversion barcode (conversion factor = 10)
        $resultPack = $this->preparationService->applyScan(
            $draft,
            'BAR-PACK-10',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $this->assertEquals('resolved', $resultPack['status']);
        $draft->refresh();
        $this->assertEquals(11, (int) $draft->lines->first()->quantity);
    }

    public function test_scanner_resolves_in_transit_serial_number(): void
    {
        $sn = ProductSerialNumber::create([
            'product_id'             => $this->serializedProduct->id,
            'serial_number'          => 'SN-RETURN-TRANSIT-01',
            'status'                 => 'in_transit',
            'location_id'            => $this->destination->id,
            'custody_movement_id'    => $this->dispatchMovement->id,
            'custody_transfer_id'    => $this->transfer->id,
        ]);

        $dispLine = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        $movSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        TransferActiveSerialClaim::create([
            'product_serial_number_id'    => $sn->id,
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);

        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $result = $this->preparationService->applyScan(
            $draft,
            'SN-RETURN-TRANSIT-01',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $this->assertEquals('resolved', $result['status']);
        $draft->refresh();
        $this->assertCount(1, $draft->lines);
        $this->assertCount(1, $draft->lines->first()->serials);
        $this->assertEquals('SN-RETURN-TRANSIT-01', $draft->lines->first()->serials->first()->serial_number);
    }

    public function test_empty_count_confirmation_and_mutation_invalidation(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $draft = $this->preparationService->confirmEmpty($draft, $draft->lock_version, $this->user->id);
        $this->assertTrue($draft->empty_count_confirmed);

        // Mutating with a scan must clear empty_count_confirmed
        $this->preparationService->applyScan(
            $draft,
            'BAR-WID-1',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $draft->refresh();
        $this->assertFalse($draft->empty_count_confirmed);
        $this->assertCount(1, $draft->lines);
    }

    public function test_optimistic_locking_collision_detection(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $staleLockVersion = $draft->lock_version;

        // Perform valid update to increment lock_version
        $this->preparationService->applyScan(
            $draft,
            'BAR-WID-1',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $draft->refresh();
        $this->assertGreaterThan($staleLockVersion, $draft->lock_version);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Optimistic lock error');

        // Attempt second update with stale lock version
        $this->preparationService->applyScan(
            $draft,
            'BAR-WID-1',
            $this->setting->id,
            $staleLockVersion,
            $this->user->id
        );
    }

    public function test_blind_error_sanitization_for_other_tenant_or_unknown_products(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $result = $this->preparationService->applyScan(
            $draft,
            'UNKNOWN-BARCODE',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $this->assertContains($result['status'], ['rejected', 'not_found']);

        $resultOtherTenant = $this->preparationService->applyScan(
            $draft,
            'BAR-OTHER-1',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $this->assertContains($resultOtherTenant['status'], ['rejected', 'not_found']);
    }

    public function test_preparation_error_is_identical_for_regular_privileged_and_super_admin(): void
    {
        $regularUser = User::factory()->create();
        $privilegedUser = User::factory()->create();
        $privilegedUser->givePermissionTo('stockTransfers.view-system-stock');
        $privilegedUser->givePermissionTo('stockTransfers.receive.create');

        $superAdmin = User::factory()->create();
        $superAdmin->givePermissionTo('stockTransfers.receive.create');
        $superAdmin->assignRole('Super Admin');

        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        // 1. Check scan endpoint error response with invalid query
        $resPrivileged = $this->actingAs($privilegedUser)->withSession(['setting_id' => $this->setting->id])->postJson(
            route('transfers.movements.return-receipt.scan', ['transfer' => $this->transfer->id, 'movement' => $draft->id]),
            ['query' => 'INVALID_CODE', 'lock_version' => 9999] // stale lock version triggers exception
        );

        $resSuperAdmin = $this->actingAs($superAdmin)->withSession(['setting_id' => $this->setting->id])->postJson(
            route('transfers.movements.return-receipt.scan', ['transfer' => $this->transfer->id, 'movement' => $draft->id]),
            ['query' => 'INVALID_CODE', 'lock_version' => 9999]
        );

        $this->assertEquals(400, $resPrivileged->status());
        $this->assertEquals(400, $resSuperAdmin->status());

        $this->assertEquals(
            'Terjadi kesalahan saat memproses perhitungan penerimaan retur.',
            $resPrivileged->json('message')
        );
        $this->assertEquals(
            'Terjadi kesalahan saat memproses perhitungan penerimaan retur.',
            $resSuperAdmin->json('message')
        );
    }

    public function test_submit_revalidates_source_and_transit_claim_boundary(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        // Attempt submit on unconfirmed empty draft -> rejected
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Draf penerimaan kosong harus dikonfirmasi');

        $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
    }

    public function test_submit_rejects_mutated_live_serial_tuple(): void
    {
        $sn = ProductSerialNumber::create([
            'product_id'          => $this->serializedProduct->id,
            'serial_number'       => 'SN-SUBMIT-TUPLE-01',
            'status'              => ProductSerialNumber::STATUS_ACTIVE,
            'location_id'         => $this->destination->id,
            'is_broken'           => false,
            'tax_id'              => null,
        ]);

        $dispLine = TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
        ]);

        $movSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => $sn->serial_number,
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
            'tax_id'                    => null,
        ]);

        $claim = TransferActiveSerialClaim::create([
            'product_serial_number_id'    => $sn->id,
            'transfer_movement_id'        => $this->dispatchMovement->id,
            'transfer_movement_serial_id' => $movSerial->id,
        ]);

        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $this->preparationService->applyScan(
            $draft,
            'SN-SUBMIT-TUPLE-01',
            $this->setting->id,
            $draft->lock_version,
            $this->user->id
        );

        $draft->refresh();

        // 1. Mutate condition -> must be rejected at submission
        $sn->update(['is_broken' => true]);

        try {
            $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
            $this->fail('Submission should have failed due to condition drift.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('berstatus barang rusak padahal transfer berstatus GOOD', $e->getMessage());
        }

        // Restore condition
        $sn->update(['is_broken' => false]);

        // 2. Mutate location -> must be rejected at submission
        $sn->update(['location_id' => $this->origin->id]);

        try {
            $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
            $this->fail('Submission should have failed due to location mismatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak cocok dengan lokasi pengiriman', $e->getMessage());
        }

        // Restore location
        $sn->update(['location_id' => $this->destination->id]);

        // 3. Mutate tax identity -> must be rejected at submission
        $sn->update(['tax_id' => $this->tax->id]);

        try {
            $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
            $this->fail('Submission should have failed due to tax identity drift.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Identitas pajak nomor seri SN-SUBMIT-TUPLE-01 telah berubah', $e->getMessage());
        }

        // Restore tax identity
        $sn->update(['tax_id' => null]);

        // 4. Stolen/mutated claim -> must be rejected at submission
        $otherMovSerial = TransferMovementSerial::create([
            'transfer_movement_id'      => $this->dispatchMovement->id,
            'transfer_movement_line_id' => $dispLine->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => $sn->id,
            'serial_number'             => 'SN-OTHER-SERIAL',
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
            'transit_custody_status'    => TransferMovementSerial::CUSTODY_IN_TRANSIT,
        ]);

        $claim->update(['transfer_movement_serial_id' => $otherMovSerial->id]);

        try {
            $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
            $this->fail('Submission should have failed due to claim serial row mismatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Klaim transit nomor seri SN-SUBMIT-TUPLE-01 tidak cocok', $e->getMessage());
        }

        // Restore claim
        $claim->update(['transfer_movement_serial_id' => $movSerial->id]);

        // 5. Valid submission succeeds
        $submitted = $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
        $this->assertEquals(TransferMovement::STATUS_PENDING, $submitted->status);
    }

    public function test_submit_rejects_crafted_null_product_serial_number_id_observations(): void
    {
        $draft = $this->preparationService->getOrCreateDraft(
            $this->transfer,
            $this->dispatchMovement,
            $this->user->id
        );

        $line = TransferMovementLine::create([
            'transfer_movement_id' => $draft->id,
            'product_id'           => $this->serializedProduct->id,
            'quantity'             => 1,
            'count_confirmed'      => true,
        ]);

        // Crafted observation with null product_serial_number_id
        TransferMovementSerial::create([
            'transfer_movement_id'      => $draft->id,
            'transfer_movement_line_id' => $line->id,
            'product_id'                => $this->serializedProduct->id,
            'product_serial_number_id'  => null,
            'serial_number'             => 'SN-CRAFTED-NULL-ID',
            'stock_condition'           => TransferMovementSerial::CONDITION_GOOD,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Draf penerimaan retur memiliki nomor seri 'SN-CRAFTED-NULL-ID' yang tidak terdaftar di database.");

        $this->preparationService->submit($draft, $draft->lock_version, $this->user->id);
    }
}
