<?php

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferMovementService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferMovementServiceConditionDriftTest extends TestCase
{
    use RefreshDatabase;

    private TransferMovementService $service;

    private User $user;

    private Setting $setting;

    private Location $origin;

    private Location $destination;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TransferMovementService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $this->setting->id]);

        $category = Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Serial Transfer Product',
            'product_code' => 'STP-001',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);
    }

    private function makeStock(): ProductStock
    {
        return ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);
    }

    private function makeSerial(array $overrides = []): ProductSerialNumber
    {
        return ProductSerialNumber::create(array_merge([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-DRIFT-' . uniqid(),
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
            'tax_id' => null,
        ], $overrides));
    }

    private function makeDraftTransfer(ProductSerialNumber $serial, bool $savedAsBroken): Transfer
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'created_by' => $this->user->id,
            'status' => Transfer::STATUS_APPROVED,
            'revision' => 1,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'quantity_tax' => $savedAsBroken ? 0 : 0,
            'quantity_non_tax' => $savedAsBroken ? 0 : 1,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => $savedAsBroken ? 1 : 0,
            'serial_numbers' => [
                [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'is_broken' => $savedAsBroken,
                ],
            ],
        ]);

        return $transfer->fresh('products');
    }

    /** @test */
    public function good_non_tax_serial_becoming_broken_before_dispatch_is_rejected_and_fully_rolled_back(): void
    {
        $stock = $this->makeStock();
        $serial = $this->makeSerial(['is_broken' => false]);
        $transfer = $this->makeDraftTransfer($serial, savedAsBroken: false);

        // Drift: serial becomes broken after the draft snapshot was saved.
        $serial->update(['is_broken' => true]);

        $stockBefore = $stock->fresh()->toArray();
        $serialBefore = $serial->fresh()->toArray();
        $transferBefore = $transfer->fresh()->toArray();
        $historyCountBefore = SerialNumberHistory::count();
        $transactionCountBefore = Transaction::count();

        try {
            $this->service->dispatch($transfer);
            $this->fail('Expected dispatch to throw due to condition drift.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Kondisi nomor seri', $e->getMessage());
        }

        $this->assertEquals($stockBefore, $stock->fresh()->toArray());
        $this->assertEquals($serialBefore, $serial->fresh()->toArray());
        $this->assertEquals($this->origin->id, $serial->fresh()->location_id);
        $this->assertEquals($transferBefore['status'], $transfer->fresh()->status);
        $this->assertEquals($historyCountBefore, SerialNumberHistory::count());
        $this->assertEquals($transactionCountBefore, Transaction::count());
    }

    /** @test */
    public function broken_non_tax_serial_becoming_good_before_dispatch_is_rejected_and_fully_rolled_back(): void
    {
        $stock = $this->makeStock();
        $stock->update([
            'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 1,
            'quantity' => 1,
            'broken_quantity' => 1,
        ]);

        $serial = $this->makeSerial(['is_broken' => true]);
        $transfer = $this->makeDraftTransfer($serial, savedAsBroken: true);

        // Drift: serial is repaired/marked good after the draft snapshot was saved.
        $serial->update(['is_broken' => false]);

        $stockBefore = $stock->fresh()->toArray();
        $serialBefore = $serial->fresh()->toArray();
        $historyCountBefore = SerialNumberHistory::count();
        $transactionCountBefore = Transaction::count();

        try {
            $this->service->dispatch($transfer);
            $this->fail('Expected dispatch to throw due to condition drift.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Kondisi nomor seri', $e->getMessage());
        }

        $this->assertEquals($stockBefore, $stock->fresh()->toArray());
        $this->assertEquals($serialBefore, $serial->fresh()->toArray());
        $this->assertEquals($this->origin->id, $serial->fresh()->location_id);
        $this->assertEquals($historyCountBefore, SerialNumberHistory::count());
        $this->assertEquals($transactionCountBefore, Transaction::count());
    }

    /** @test */
    public function legacy_status_broken_with_is_broken_false_is_treated_as_broken_and_drift_against_normal_draft_is_rejected(): void
    {
        $stock = $this->makeStock();

        // Legacy row: status literal BROKEN but is_broken flag never migrated to true.
        $serial = $this->makeSerial([
            'status' => ProductSerialNumber::STATUS_BROKEN,
            'is_broken' => false,
        ]);

        $transfer = $this->makeDraftTransfer($serial, savedAsBroken: false);

        $stockBefore = $stock->fresh()->toArray();
        $serialBefore = $serial->fresh()->toArray();
        $historyCountBefore = SerialNumberHistory::count();
        $transactionCountBefore = Transaction::count();

        try {
            $this->service->dispatch($transfer);
            $this->fail('Expected dispatch to throw: legacy BROKEN status must be treated as broken.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Kondisi nomor seri', $e->getMessage());
        }

        $this->assertEquals($stockBefore, $stock->fresh()->toArray());
        $this->assertEquals($serialBefore, $serial->fresh()->toArray());
        $this->assertEquals($this->origin->id, $serial->fresh()->location_id);
        $this->assertEquals($historyCountBefore, SerialNumberHistory::count());
        $this->assertEquals($transactionCountBefore, Transaction::count());
    }

    /** @test */
    public function rollback_leaves_locations_stock_status_transactions_and_histories_completely_unchanged(): void
    {
        $stock = $this->makeStock();
        $serial = $this->makeSerial(['is_broken' => false]);
        $transfer = $this->makeDraftTransfer($serial, savedAsBroken: false);

        $serial->update(['is_broken' => true]);

        $snapshot = [
            'origin_location' => $this->origin->fresh()->toArray(),
            'destination_location' => $this->destination->fresh()->toArray(),
            'stock' => $stock->fresh()->toArray(),
            'serial_location_id' => $serial->fresh()->location_id,
            'transfer_status' => $transfer->fresh()->status,
            'transfer_product' => $transfer->fresh()->products->first()->toArray(),
            'transaction_count' => Transaction::count(),
            'history_count' => SerialNumberHistory::count(),
        ];

        try {
            $this->service->dispatch($transfer);
            $this->fail('Expected dispatch to throw due to condition drift.');
        } catch (\Exception $e) {
            // expected
        }

        $this->assertEquals($snapshot['origin_location'], $this->origin->fresh()->toArray());
        $this->assertEquals($snapshot['destination_location'], $this->destination->fresh()->toArray());
        $this->assertEquals($snapshot['stock'], $stock->fresh()->toArray());
        $this->assertEquals($snapshot['serial_location_id'], $serial->fresh()->location_id);
        $this->assertEquals($snapshot['transfer_status'], $transfer->fresh()->status);
        $this->assertEquals($snapshot['transfer_product'], $transfer->fresh()->products->first()->toArray());
        $this->assertEquals($snapshot['transaction_count'], Transaction::count());
        $this->assertEquals($snapshot['history_count'], SerialNumberHistory::count());
    }

    /** @test */
    public function dispatch_locks_product_stock_before_product_serial_number_matching_transfer_hierarchy(): void
    {
        $this->makeStock();
        $serial = $this->makeSerial(['is_broken' => false]);
        $transfer = $this->makeDraftTransfer($serial, savedAsBroken: false);

        DB::enableQueryLog();
        $this->service->dispatch($transfer);
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();

        // SQLite's grammar drops the "for update" clause entirely, so lock order here is
        // inferred from the order the two tables are first selected (both selects are made
        // under lockForUpdate() in TransferMovementService), matching the intended hierarchy:
        // ProductStock is locked in dispatch() before allocateSerialized() locks the serials.
        $stockSelectIndex = $queries->search(fn ($sql) => str_contains($sql, 'select * from "product_stocks"'));
        $serialSelectIndex = $queries->search(fn ($sql) => str_contains($sql, 'select * from "product_serial_numbers"'));

        $this->assertNotFalse($stockSelectIndex, 'Expected a locking SELECT against product_stocks.');
        $this->assertNotFalse($serialSelectIndex, 'Expected a locking SELECT against product_serial_numbers.');
        $this->assertLessThan(
            $serialSelectIndex,
            $stockSelectIndex,
            'ProductStock must be locked before ProductSerialNumber to match the established lock hierarchy and avoid deadlocking with Sale dispatch approval.'
        );
    }
}
