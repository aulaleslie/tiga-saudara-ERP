<?php

namespace Modules\Adjustment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\Product;
use RuntimeException;
use Tests\TestCase;

class TransferLifecycleMaterialEditTest extends TestCase
{
    use RefreshDatabase;

    private TransferLifecycleService $service;
    private User $user;
    private Location $origin;
    private Location $destination;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransferLifecycleService::class);
        $this->user = User::factory()->create();

        $setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $setting->id]);

        $categoryId = \Illuminate\Support\Facades\DB::table('categories')->insertGetId([
            'category_code' => 'CAT-01',
            'category_name' => 'Test Category',
            'created_by' => $this->user->id,
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'product_price' => 100,
            'product_cost' => 50,
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::find($productId);
    }

    private function productData(int $quantityNonTax): array
    {
        return [
            [
                'product_id' => $this->product->id,
                'quantity' => $quantityNonTax,
                'quantities' => [
                    'quantity_tax' => 0,
                    'quantity_non_tax' => $quantityNonTax,
                    'quantity_broken_tax' => 0,
                    'quantity_broken_non_tax' => 0,
                ],
                'serial_numbers' => [],
            ],
        ];
    }

    /** @test */
    public function a_material_draft_edit_remains_draft_and_advances_revision()
    {
        $transfer = $this->service->createDraft(
            $this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );

        $updated = $this->service->updateTransfer(
            $transfer, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(20), $this->user->id
        );

        $this->assertEquals(Transfer::STATUS_DRAFT, $updated->status);
        $this->assertEquals(2, $updated->revision);

        $this->assertDatabaseHas('transfer_action_histories', [
            'transfer_id' => $transfer->id,
            'action' => TransferActionHistory::ACTION_EDITED,
            'from_status' => Transfer::STATUS_DRAFT,
            'to_status' => Transfer::STATUS_DRAFT,
        ]);
    }

    /** @test */
    public function a_material_pending_edit_returns_to_draft_and_advances_revision()
    {
        $transfer = $this->service->createDraft(
            $this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );
        $transfer = $this->service->submitDraft($transfer, $this->user->id);
        $this->assertEquals(Transfer::STATUS_PENDING, $transfer->status);

        $updated = $this->service->updateTransfer(
            $transfer, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(30), $this->user->id
        );

        $this->assertEquals(Transfer::STATUS_DRAFT, $updated->status);
        $this->assertEquals(3, $updated->revision);

        $this->assertDatabaseHas('transfer_action_histories', [
            'transfer_id' => $transfer->id,
            'action' => TransferActionHistory::ACTION_EDITED,
            'from_status' => Transfer::STATUS_PENDING,
            'to_status' => Transfer::STATUS_DRAFT,
        ]);
    }

    /** @test */
    public function a_no_op_draft_save_leaves_status_revision_lines_and_history_unchanged()
    {
        $transfer = $this->service->createDraft(
            $this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );

        $historyCountBefore = TransferActionHistory::where('transfer_id', $transfer->id)->count();

        $updated = $this->service->updateTransfer(
            $transfer, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );

        $this->assertEquals(Transfer::STATUS_DRAFT, $updated->status);
        $this->assertEquals(1, $updated->revision);
        $this->assertEquals(
            $historyCountBefore,
            TransferActionHistory::where('transfer_id', $transfer->id)->count()
        );
        $this->assertDatabaseHas('transfer_products', [
            'transfer_id' => $transfer->id,
            'quantity' => 10,
        ]);
    }

    /** @test */
    public function a_no_op_pending_save_leaves_status_revision_lines_and_history_unchanged()
    {
        $transfer = $this->service->createDraft(
            $this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );
        $transfer = $this->service->submitDraft($transfer, $this->user->id);

        $historyCountBefore = TransferActionHistory::where('transfer_id', $transfer->id)->count();

        $updated = $this->service->updateTransfer(
            $transfer, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );

        $this->assertEquals(Transfer::STATUS_PENDING, $updated->status);
        $this->assertEquals(2, $updated->revision);
        $this->assertEquals(
            $historyCountBefore,
            TransferActionHistory::where('transfer_id', $transfer->id)->count()
        );
    }

    /** @test */
    public function a_stale_concurrent_edit_is_rejected_without_partial_persistence()
    {
        $transfer = $this->service->createDraft(
            $this->origin->id, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(10), $this->user->id
        );

        Transfer::where('id', $transfer->id)->update(['revision' => 99]);

        $this->expectException(RuntimeException::class);

        $this->service->updateTransfer(
            $transfer, $this->destination->id, Transfer::CONDITION_GOOD, $this->productData(50), $this->user->id
        );
    }
}
