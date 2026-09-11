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

class TransferStockConditionSegmentedControlTest extends TestCase
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
        $this->user->givePermissionTo(['stockTransfers.create', 'stockTransfers.edit']);
    }

    private function sampleRow(): array
    {
        return [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
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
    public function a_new_transfer_defaults_to_good_condition()
    {
        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->assertSet('stockCondition', Transfer::CONDITION_GOOD);
    }

    /** @test */
    public function the_create_form_renders_button_controls_not_native_radio_inputs()
    {
        $html = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->html();

        $this->assertStringContainsString('id="stock-condition-good"', $html);
        $this->assertStringContainsString('id="stock-condition-breakage"', $html);
        $this->assertStringNotContainsString('type="radio"', $html);
        $this->assertStringNotContainsString('btn-check', $html);
    }

    /** @test */
    public function the_selected_condition_button_is_highlighted_with_correct_aria_pressed()
    {
        $html = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->html();

        // GOOD is selected by default: its button is filled/active with
        // aria-pressed="true"; the unselected button is outlined with
        // aria-pressed="false".
        $this->assertMatchesRegularExpression(
            '/id="stock-condition-good"[^>]*class="btn btn-success active"[^>]*aria-pressed="true"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="stock-condition-breakage"[^>]*class="btn btn-outline-warning"[^>]*aria-pressed="false"/s',
            $html
        );
    }

    /** @test */
    public function selecting_breakage_updates_the_highlighted_state()
    {
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('selectStockCondition', Transfer::CONDITION_BREAKAGE);

        $livewire->assertSet('stockCondition', Transfer::CONDITION_BREAKAGE);

        $html = $livewire->html();

        $this->assertMatchesRegularExpression(
            '/id="stock-condition-breakage"[^>]*class="btn btn-warning active"[^>]*aria-pressed="true"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="stock-condition-good"[^>]*class="btn btn-outline-success"[^>]*aria-pressed="false"/s',
            $html
        );
    }

    /** @test */
    public function selecting_a_different_condition_with_entered_rows_requires_confirmation()
    {
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('rows', [$this->sampleRow()]);

        $livewire->call('selectStockCondition', Transfer::CONDITION_BREAKAGE);

        $livewire->assertSet('showConditionConfirmModal', true);
        $livewire->assertSet('stockCondition', Transfer::CONDITION_GOOD);
        $this->assertCount(1, $livewire->get('rows'));

        $livewire->call('confirmConditionChange');

        $livewire->assertSet('stockCondition', Transfer::CONDITION_BREAKAGE);
        $livewire->assertSet('showConditionConfirmModal', false);
        $this->assertCount(0, $livewire->get('rows'));
    }

    /** @test */
    public function cancelling_a_condition_change_preserves_the_prior_selection_and_rows()
    {
        $livewire = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('rows', [$this->sampleRow()]);

        $livewire->call('selectStockCondition', Transfer::CONDITION_BREAKAGE);
        $livewire->call('cancelConditionChange');

        $livewire->assertSet('stockCondition', Transfer::CONDITION_GOOD);
        $livewire->assertSet('showConditionConfirmModal', false);
        $this->assertCount(1, $livewire->get('rows'));
    }

    /** @test */
    public function an_existing_transfer_renders_a_read_only_badge_with_no_buttons()
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

        $html = Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->html();

        $this->assertStringContainsString('Barang Baik', $html);
        $this->assertStringNotContainsString('id="stock-condition-good"', $html);
        $this->assertStringNotContainsString('id="stock-condition-breakage"', $html);
        $this->assertStringNotContainsString('selectStockCondition', $html);
    }
}
