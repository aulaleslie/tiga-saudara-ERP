<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Services\ForwardReceiptComparatorService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ForwardReceiptComparatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Location $origin;
    protected Location $destination;
    protected Product $productA;
    protected Product $productB;
    protected Transfer $transfer;
    protected TransferMovement $dispatchMovement;
    protected ForwardReceiptComparatorService $comparator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Origin',
        ]);

        $this->destination = Location::create([
            'setting_id' => $this->setting->id,
            'name'       => 'Destination',
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        $this->productA = Product::create([
            'product_name'           => 'Product A',
            'product_code'           => 'PROD-A',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 10,
            'product_cost'           => 100,
            'product_price'          => 150,
            'serial_number_required' => false,
            'stock_managed'          => true,
        ]);

        $this->productB = Product::create([
            'product_name'           => 'Product B',
            'product_code'           => 'PROD-B',
            'setting_id'             => $this->setting->id,
            'unit_id'                => $unit->id,
            'product_quantity'       => 5,
            'product_cost'           => 200,
            'product_price'          => 300,
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
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $this->dispatchMovement->id,
            'product_id'           => $this->productA->id,
            'stock_condition'      => 'good',
            'quantity'             => 10,
        ]);

        $this->comparator = app(ForwardReceiptComparatorService::class);
    }

    public function test_compare_exact_match(): void
    {
        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productA->id,
            'stock_condition'      => 'good',
            'quantity'             => 10,
        ]);

        $diff = $this->comparator->compare($receiptMovement, $this->dispatchMovement);

        $this->assertTrue($diff['matches']);
        $this->assertEquals(0, $diff['summary']['mismatched_products_count']);
        $this->assertEquals(0, $diff['summary']['unexpected_products_count']);
        $this->assertEquals(0, $diff['summary']['missing_products_count']);
        $this->assertEquals('MATCH', $diff['details'][$this->productA->id]['status']);
    }

    public function test_compare_partial_and_missing(): void
    {
        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productA->id,
            'stock_condition'      => 'good',
            'quantity'             => 7, // Shortage of 3
        ]);

        $diff = $this->comparator->compare($receiptMovement, $this->dispatchMovement);

        $this->assertFalse($diff['matches']);
        $this->assertEquals(1, $diff['summary']['mismatched_products_count']);
        $this->assertEquals('SHORTAGE', $diff['details'][$this->productA->id]['status']);
        $this->assertEquals('10.0000', $diff['details'][$this->productA->id]['expected_quantity']);
        $this->assertEquals('7.0000', $diff['details'][$this->productA->id]['counted_quantity']);
        $this->assertEquals('-3.0000', $diff['details'][$this->productA->id]['difference_quantity']);
    }

    public function test_compare_unexpected_product(): void
    {
        $receiptMovement = TransferMovement::create([
            'transfer_id'             => $this->transfer->id,
            'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
            'revision'                => 1,
            'lock_version'            => 1,
            'transfer_revision'       => 1,
            'status'                  => TransferMovement::STATUS_PENDING,
            'origin_location_id'      => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'source_movement_id'      => $this->dispatchMovement->id,
            'stock_condition'         => TransferMovement::CONDITION_GOOD,
            'created_by'              => $this->user->id,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productA->id,
            'stock_condition'      => 'good',
            'quantity'             => 10,
        ]);

        TransferMovementLine::create([
            'transfer_movement_id' => $receiptMovement->id,
            'product_id'           => $this->productB->id,
            'stock_condition'      => 'good',
            'quantity'             => 2,
        ]);

        $diff = $this->comparator->compare($receiptMovement, $this->dispatchMovement);

        $this->assertFalse($diff['matches']);
        $this->assertEquals(1, $diff['summary']['unexpected_products_count']);
        $this->assertEquals('UNEXPECTED', $diff['details'][$this->productB->id]['status']);
        $this->assertEquals('2.0000', $diff['details'][$this->productB->id]['counted_quantity']);
    }
}
