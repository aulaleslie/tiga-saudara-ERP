<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\ForwardDispatchPreparationService;
use Modules\Adjustment\Services\ForwardDispatchProjectionService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Modules\Adjustment\Tests\Support\CreatesRoutePolicySnapshot;

class ForwardDispatchAuthorizationAndProjectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRoutePolicySnapshot;

    private const GUARD = 'web';

    protected User $blindDispatcher;
    protected User $privilegedDispatcher;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'stockTransfers.access',
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            TransferStockVisibility::PERMISSION,
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => self::GUARD]);
        }

        $this->setting = Setting::factory()->create();

        $this->blindDispatcher = User::factory()->create();
        $this->blindDispatcher->givePermissionTo(['stockTransfers.access', 'stockTransfers.dispatch.create', 'stockTransfers.dispatch.approval']);

        $this->privilegedDispatcher = User::factory()->create();
        $this->privilegedDispatcher->givePermissionTo(['stockTransfers.access', 'stockTransfers.dispatch.create', 'stockTransfers.dispatch.approval', TransferStockVisibility::PERMISSION]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Location',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Location',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->product = Product::create([
            'product_name'           => 'Secret Inventory Item',
            'product_code'           => 'SII-001',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 100,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        ProductStock::create([
            'product_id'              => $this->product->id,
            'location_id'             => $this->origin->id,
            'quantity'                => 100,
            'quantity_non_tax'        => 80,
            'quantity_tax'            => 20,
            'broken_quantity'         => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax'     => 0,
        ]);
    }

    /** @test */
    public function blind_preparation_projection_omits_requested_quantities_and_system_stock(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->blindDispatcher->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->blindDispatcher);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 25,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $projectionService = app(ForwardDispatchProjectionService::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->blindDispatcher->id);

        // Blind projection
        $blindProj = $projectionService->getPreparationProjection($transfer, $movement, false);
        $this->assertArrayNotHasKey('requested_quantity', $blindProj['lines'][0]);

        // Privileged projection
        $privProj = $projectionService->getPreparationProjection($transfer, $movement, true);
        $this->assertArrayHasKey('requested_quantity', $privProj['lines'][0]);
        $this->assertEquals('25', (string) $privProj['lines'][0]['requested_quantity']);
    }

    /** @test */
    public function blind_approval_projection_returns_neutral_non_quantitative_guidance(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->blindDispatcher->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
        ]);

        $this->createRoutePolicySnapshot($transfer, $this->origin, $this->destination, $this->blindDispatcher);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id'  => $this->product->id,
            'quantity'    => 25,
        ]);

        $prepService = app(ForwardDispatchPreparationService::class);
        $projectionService = app(ForwardDispatchProjectionService::class);

        $movement = $prepService->getOrCreateDraft($transfer, $this->blindDispatcher->id);
        // Operator enters 10 instead of 25 (mismatch)
        $movement = $prepService->setLineQuantity($movement, $this->product->id, 10, true, $movement->lock_version, $this->blindDispatcher->id);
        $pendingMovement = $prepService->submit($movement, $movement->lock_version, $this->blindDispatcher->id);

        // Blind approval projection
        $blindApprovalProj = $projectionService->getApprovalProjection($transfer, $pendingMovement, false);
        $this->assertFalse($blindApprovalProj['matches']);
        $this->assertArrayNotHasKey('summary', $blindApprovalProj);
        $this->assertArrayNotHasKey('details', $blindApprovalProj);
        $this->assertStringContainsString('Terdapat ketidaksesuaian', $blindApprovalProj['message']);

        // Privileged approval projection
        $privApprovalProj = $projectionService->getApprovalProjection($transfer, $pendingMovement, true);
        $this->assertFalse($privApprovalProj['matches']);
        $this->assertArrayHasKey('summary', $privApprovalProj);
        $this->assertArrayHasKey('details', $privApprovalProj);
        $this->assertEquals('SHORTAGE', $privApprovalProj['details'][$this->product->id]['status']);
    }
}
