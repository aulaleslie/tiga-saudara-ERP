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
use Tests\TestCase;

class TransferFormOriginGatingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Location $origin;
    private Location $destination;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $this->setting->id]);

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
    public function search_product_is_disabled_without_a_valid_origin()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\SearchProduct::class, ['locationId' => null])
            ->set('query', 'Product')
            ->assertSet('search_results', collect());
    }

    /** @test */
    public function search_product_rejects_selection_without_a_valid_origin()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\SearchProduct::class, ['locationId' => null])
            ->call('selectProduct', $this->product->toArray())
            ->assertDispatched('scanFailed');
    }

    /** @test */
    public function search_product_rejects_scan_without_a_valid_origin()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\SearchProduct::class, ['locationId' => null])
            ->call('scanBarcode', 'anything')
            ->assertDispatched('scanFailed');
    }

    /** @test */
    public function origin_change_clears_destination_and_rows()
    {
        $otherOrigin = Location::factory()->create(['setting_id' => $this->setting->id]);

        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$this->sampleRow()]);

        $livewire->assertSet('destinationLocation', $this->destination->id);
        $this->assertCount(1, $livewire->get('rows'));

        $livewire->call('onOriginLocationSelected', ['id' => $otherOrigin->id]);

        $livewire->assertSet('originLocation', $otherOrigin->id);
        $livewire->assertSet('destinationLocation', null);
        $this->assertCount(0, $livewire->get('rows'));
    }

    /** @test */
    public function reselecting_the_same_origin_does_not_clear_rows()
    {
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$this->sampleRow()]);

        $livewire->call('onOriginLocationSelected', ['id' => $this->origin->id]);

        $this->assertCount(1, $livewire->get('rows'));
    }

    /** @test */
    public function destination_change_preserves_rows()
    {
        $otherDestination = Location::factory()->create(['setting_id' => $this->setting->id]);

        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$this->sampleRow()]);

        $livewire->call('onDestinationLocationSelected', ['id' => $otherDestination->id]);

        $livewire->assertSet('destinationLocation', $otherDestination->id);
        $this->assertCount(1, $livewire->get('rows'));
    }

    /** @test */
    public function mode_change_clears_rows_but_preserves_origin_and_destination()
    {
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$this->sampleRow()]);

        $livewire->set('stockCondition', Transfer::CONDITION_BREAKAGE);

        $livewire->assertSet('originLocation', $this->origin->id);
        $livewire->assertSet('destinationLocation', $this->destination->id);
        $this->assertCount(0, $livewire->get('rows'));
    }

    /** @test */
    public function editable_draft_hydrates_destination_mode_and_rows()
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
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

        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer]);

        $livewire->assertSet('originLocation', $this->origin->id)
            ->assertSet('destinationLocation', $this->destination->id)
            ->assertSet('stockCondition', Transfer::CONDITION_GOOD)
            ->assertSet('isMixedConditionHistory', false);

        $this->assertCount(1, $livewire->get('rows'));
    }
}
