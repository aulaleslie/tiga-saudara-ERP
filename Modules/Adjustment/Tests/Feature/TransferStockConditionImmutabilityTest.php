<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferStockForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Services\TransferDraftService;
use Modules\Adjustment\DTOs\TransferFormState;
use Modules\Adjustment\DTOs\TransferFormLineState;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferStockConditionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;
    private Product $product;

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
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => 'stockTransfers.edit', 'guard_name' => 'web']);
        $this->user->givePermissionTo('stockTransfers.edit');
    }

    private function makeDraft(string $condition = Transfer::CONDITION_GOOD): Transfer
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => null,
            'stock_condition' => $condition,
            'status' => Transfer::STATUS_DRAFT,
            'created_by' => $this->user->id,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'quantity_tax' => 1,
            'quantity_non_tax' => 1,
        ]);

        return $transfer;
    }

    /** @test */
    public function service_boundary_rejects_a_crafted_condition_change_on_a_draft()
    {
        $transfer = $this->makeDraft(Transfer::CONDITION_GOOD);

        $state = new TransferFormState($this->origin->id, null, Transfer::CONDITION_BREAKAGE);
        $line = new TransferFormLineState($this->product->id, $this->product->product_name, $this->product->product_code, null, false, true, 1);
        $state->addLine($line);

        try {
            app(TransferDraftService::class)->saveDraft($state, $this->user, $this->setting->id, $transfer);
            $this->fail('Expected a condition change on an existing transfer to be rejected.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertEquals(Transfer::CONDITION_GOOD, $transfer->fresh()->stock_condition);
    }

    /** @test */
    public function livewire_form_rejects_a_crafted_condition_change_on_an_existing_transfer()
    {
        $transfer = $this->makeDraft(Transfer::CONDITION_GOOD);

        $component = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [stockCondition]');
        $component->set('stockCondition', Transfer::CONDITION_BREAKAGE);
    }
}
