<?php

namespace Modules\Adjustment\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use RuntimeException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;

class TransferLifecycleServiceTest extends TestCase
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

    /** @test */
    public function it_creates_draft_transfer()
    {
        $products = [
            ['product_id' => $this->product->id, 'quantity' => 10],
        ];

        $transfer = $this->service->createDraft(
            $this->origin->id,
            $this->destination->id,
            $products,
            $this->user->id
        );

        $this->assertNotNull($transfer);
        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->status);
        $this->assertEquals(1, $transfer->revision);
        
        $this->assertDatabaseHas('transfer_products', [
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]);

        $this->assertDatabaseHas('transfer_action_histories', [
            'transfer_id' => $transfer->id,
            'action' => TransferActionHistory::ACTION_CREATED,
            'to_status' => Transfer::STATUS_DRAFT,
        ]);
    }

    /** @test */
    public function it_handles_idempotent_creation()
    {
        $key = (string) Str::uuid();
        
        $products = [['product_id' => $this->product->id, 'quantity' => 5]];

        $first = $this->service->createDraft($this->origin->id, $this->destination->id, $products, $this->user->id, $key);
        $second = $this->service->createDraft($this->origin->id, $this->destination->id, $products, $this->user->id, $key);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, Transfer::count());
    }

    /** @test */
    public function it_submits_draft()
    {
        $transfer = $this->service->createDraft($this->origin->id, $this->destination->id, [], $this->user->id);
        
        $submitted = $this->service->submitDraft($transfer, $this->user->id);

        $this->assertEquals(Transfer::STATUS_PENDING, $submitted->status);
        $this->assertEquals(2, $submitted->revision);

        $this->assertDatabaseHas('transfer_action_histories', [
            'transfer_id' => $transfer->id,
            'action' => TransferActionHistory::ACTION_SUBMITTED,
            'to_status' => Transfer::STATUS_PENDING,
        ]);
    }

    /** @test */
    public function it_updates_products_and_revision()
    {
        $transfer = $this->service->createDraft($this->origin->id, $this->destination->id, [], $this->user->id);
        
        $updated = $this->service->updateTransfer($transfer, [
            ['product_id' => $this->product->id, 'quantity' => 20]
        ], $this->user->id);

        $this->assertEquals(2, $updated->revision);
        $this->assertDatabaseHas('transfer_products', [
            'transfer_id' => $transfer->id,
            'quantity' => 20,
        ]);
    }

    /** @test */
    public function it_prevents_concurrent_modification()
    {
        $transfer = $this->service->createDraft($this->origin->id, $this->destination->id, [], $this->user->id);
        
        // Simulate another process incrementing the revision
        Transfer::where('id', $transfer->id)->update(['revision' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transfer has been modified by another process.');

        $this->service->updateTransfer($transfer, [], $this->user->id);
    }
}
