<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Services\TransferMovementDocumentService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferMovementFoundationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        foreach ([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            'stockTransfers.show',
            'stockTransfers.approval',
            'stockTransfers.dispatch',
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            'stockTransfers.receive',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
            'stockTransfers.archive',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.show',
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
        ]);

        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin Warehouse',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination Warehouse',
        ]);

        $this->product = Product::create([
            'product_name'           => 'Product 1',
            'product_code'           => 'P1',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 10,
            'product_cost'           => 100,
            'product_price'          => 200,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);
    }

    /** @test */
    public function transfer_show_page_does_not_expose_dormant_movement_data_or_break(): void
    {
        $transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 1,
        ]);

        // Create a dormant movement attempt for this transfer
        $service = app(TransferMovementDocumentService::class);
        $movement = $service->createDraft(
            $transfer,
            TransferMovement::TYPE_FORWARD_DISPATCH,
            $this->user->id,
            [['product_id' => $this->product->id, 'quantity' => 5]]
        );

        $response = $this->actingAs($this->user)->get(route('transfers.show', $transfer->id));

        $response->assertStatus(200);
        $response->assertDontSee('transfer_movements');
        $response->assertDontSee('FORWARD_DISPATCH');
        $response->assertDontSee('DRAFT movement');
    }
}
