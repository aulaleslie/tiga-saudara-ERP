<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Adjustment\DTOs\TransferFormLineState;
use Modules\Adjustment\DTOs\TransferFormState;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferDraftService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferMixedConditionHistoryEditBlockedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;
    private Product $product;
    private Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => 20,
            'quantity_tax' => 2,
            'quantity_non_tax' => 3,
            'broken_quantity' => 10,
            'broken_quantity_tax' => 4,
            'broken_quantity_non_tax' => 6,
        ]);

        // A historical transfer with no explicit stock_condition and a line
        // that mixes good and broken quantities: this predates the
        // single-condition constraint and must remain view-only.
        $this->transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => null,
            'stock_condition' => null,
            'status' => Transfer::STATUS_DRAFT,
            'created_by' => $this->user->id,
        ]);

        TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 15,
            'quantity_tax' => 2,
            'quantity_non_tax' => 3,
            'quantity_broken_tax' => 4,
            'quantity_broken_non_tax' => 6,
        ]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => 'stockTransfers.edit', 'guard_name' => 'web']);
        $this->user->givePermissionTo('stockTransfers.edit');
        $this->actingAs($this->user);
    }

    /** @test */
    public function controller_edit_rejects_a_historical_mixed_condition_transfer()
    {
        $response = $this->get(route('transfers.edit', $this->transfer->id));

        $response->assertForbidden();
    }

    /** @test */
    public function controller_update_rejects_a_historical_mixed_condition_transfer()
    {
        $response = $this->put(route('transfers.update', $this->transfer->id), [
            'product_ids' => [$this->product->id],
            'quantities' => [5],
        ]);

        $response->assertRedirect(route('transfers.show', $this->transfer->id));

        $tp = $this->transfer->fresh()->products->first();
        $this->assertEquals(15, $tp->quantity);
        $this->assertEquals(4, $tp->quantity_broken_tax);
        $this->assertEquals(6, $tp->quantity_broken_non_tax);
    }

    /** @test */
    public function service_boundary_rejects_saving_a_historical_mixed_condition_transfer()
    {
        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_GOOD);
        $state->addLine(new TransferFormLineState($this->product->id, $this->product->product_name, $this->product->product_code, null, false, false, 3));

        try {
            app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id, $this->transfer);
            $this->fail('Expected saving a historical mixed-condition transfer to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('historical mix', $e->getMessage());
        }

        $tp = $this->transfer->fresh()->products->first();
        $this->assertEquals(15, $tp->quantity);
        $this->assertEquals(4, $tp->quantity_broken_tax);
        $this->assertEquals(6, $tp->quantity_broken_non_tax);
    }
}
