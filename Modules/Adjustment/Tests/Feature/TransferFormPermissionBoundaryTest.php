<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferStockForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferFormPermissionBoundaryTest extends TestCase
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

        Permission::firstOrCreate(['name' => 'stockTransfers.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'stockTransfers.edit', 'guard_name' => 'web']);
    }

    private function sampleRow(): array
    {
        return [
            'id' => $this->product->id,
            'quantity_tax' => 1,
            'quantity_non_tax' => 1,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'stock' => [
                'quantity_tax' => 4,
                'quantity_non_tax' => 6,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ],
        ];
    }

    /** @test */
    public function save_draft_of_a_new_transfer_is_forbidden_without_create_permission()
    {
        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$this->sampleRow()])
            ->call('saveDraft')
            ->assertForbidden();

        $this->assertDatabaseMissing('transfers', ['origin_location_id' => $this->origin->id]);
    }

    /** @test */
    public function save_draft_of_a_new_transfer_succeeds_with_create_permission()
    {
        $this->user->givePermissionTo('stockTransfers.create');

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$this->sampleRow()])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transfers', ['origin_location_id' => $this->origin->id]);
    }

    /** @test */
    public function save_draft_of_an_existing_transfer_is_forbidden_without_edit_permission()
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
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

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->set('rows', [$this->sampleRow()])
            ->call('saveDraft')
            ->assertForbidden();
    }

    /** @test */
    public function submit_for_approval_is_forbidden_without_edit_permission()
    {
        $destination = Location::factory()->create(['setting_id' => $this->setting->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $destination->id,
            'stock_condition' => Transfer::CONDITION_GOOD,
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

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->set('rows', [$this->sampleRow()])
            ->call('submitForApproval')
            ->assertForbidden();

        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->fresh()->status);
    }
}
