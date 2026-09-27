<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use RuntimeException;
use Tests\TestCase;

class TransferRoutePolicyMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create(['is_pkp' => false]);

        $this->origin = Location::create(['setting_id' => $this->setting->id, 'name' => 'Origin']);
        $this->destination = Location::create(['setting_id' => $this->setting->id, 'name' => 'Destination']);

        $this->transfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_APPROVED,
            'revision'                => 1,
            'workflow_version'        => 2,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);
    }

    public function test_route_policy_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('transfer_route_policies'));

        foreach ([
            'transfer_id', 'transfer_revision', 'origin_location_id', 'destination_location_id',
            'origin_setting_id', 'destination_setting_id', 'origin_is_pkp', 'destination_is_pkp',
            'same_business', 'stock_condition', 'destination_classification', 'mandatory_return',
            'resolved_tax_id', 'resolved_tax_name', 'resolved_tax_rate', 'tax_resolver_provenance',
            'approved_by', 'approved_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('transfer_route_policies', $column), "Missing column {$column}");
        }
    }

    public function test_return_obligation_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('transfer_movement_return_obligations'));

        foreach ([
            'transfer_id', 'transfer_route_policy_id', 'receipt_movement_id', 'product_id',
            'stock_condition', 'required_quantity', 'returned_quantity', 'status',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('transfer_movement_return_obligations', $column), "Missing column {$column}");
        }
    }

    public function test_route_policy_enforces_unique_transfer_revision(): void
    {
        TransferRoutePolicy::create($this->policyAttributes());

        $this->expectException(QueryException::class);
        TransferRoutePolicy::create($this->policyAttributes());
    }

    public function test_obligation_enforces_unique_identity_for_idempotent_creation(): void
    {
        $policy = TransferRoutePolicy::create($this->policyAttributes());
        $movement = $this->createReceiptMovement();
        $product = Product::create([
            'product_name'           => 'Obligated Product',
            'product_code'           => 'OBL-001',
            'setting_id'             => $this->setting->id,
            'product_quantity'       => 0,
            'product_cost'           => 1000,
            'product_price'          => 1500,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $attributes = [
            'transfer_id'              => $this->transfer->id,
            'transfer_route_policy_id' => $policy->id,
            'receipt_movement_id'      => $movement->id,
            'product_id'               => $product->id,
            'stock_condition'          => TransferMovementReturnObligation::CONDITION_GOOD,
            'required_quantity'        => 10,
        ];

        TransferMovementReturnObligation::create($attributes);

        $this->expectException(QueryException::class);
        TransferMovementReturnObligation::create($attributes);
    }

    public function test_policy_cannot_be_updated_after_creation(): void
    {
        $policy = TransferRoutePolicy::create($this->policyAttributes());

        $this->expectException(RuntimeException::class);
        $policy->update(['mandatory_return' => true]);
    }

    public function test_policy_cannot_be_deleted(): void
    {
        $policy = TransferRoutePolicy::create($this->policyAttributes());

        $this->expectException(RuntimeException::class);
        $policy->delete();
    }

    public function test_relationships_and_casts_load_correctly(): void
    {
        $policy = TransferRoutePolicy::create($this->policyAttributes());

        $this->assertTrue($policy->refresh()->mandatory_return === false);
        $this->assertInstanceOf(Transfer::class, $policy->transfer);
        $this->assertInstanceOf(Location::class, $policy->originLocation);
        $this->assertInstanceOf(Location::class, $policy->destinationLocation);
        $this->assertIsBool($policy->same_business);
    }

    public function test_no_historical_transfer_receives_policy_backfill(): void
    {
        $legacyTransfer = Transfer::create([
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => Transfer::STATUS_COMPLETED,
            'revision'                => 1,
            'workflow_version'        => 1,
            'stock_condition'         => Transfer::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        $this->assertFalse(TransferRoutePolicy::where('transfer_id', $legacyTransfer->id)->exists());
    }

    private function policyAttributes(): array
    {
        return [
            'transfer_id'                => $this->transfer->id,
            'transfer_revision'          => 1,
            'origin_location_id'         => $this->origin->id,
            'destination_location_id'    => $this->destination->id,
            'origin_setting_id'          => $this->setting->id,
            'destination_setting_id'     => $this->setting->id,
            'origin_is_pkp'              => false,
            'destination_is_pkp'         => false,
            'same_business'              => true,
            'stock_condition'            => Transfer::CONDITION_GOOD,
            'destination_classification' => TransferRoutePolicy::CLASSIFICATION_PRESERVE,
            'mandatory_return'           => false,
            'approved_by'                => $this->user->id,
            'approved_at'                => now(),
        ];
    }

    private function createReceiptMovement(): TransferMovement
    {
        return TransferMovement::create([
            'transfer_id'              => $this->transfer->id,
            'type'                     => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                 => 1,
            'lock_version'             => 1,
            'transfer_revision'        => 1,
            'status'                   => TransferMovement::STATUS_APPROVED,
            'origin_location_id'       => $this->origin->id,
            'destination_location_id'  => $this->destination->id,
            'stock_condition'          => TransferMovement::CONDITION_GOOD,
            'created_by'               => $this->user->id,
        ]);
    }
}
