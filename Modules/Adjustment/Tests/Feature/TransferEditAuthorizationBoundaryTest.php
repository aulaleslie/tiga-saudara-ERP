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
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferEditAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Setting $otherSetting;
    private Location $origin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->otherSetting = Setting::factory()->create();
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

        Permission::firstOrCreate(['name' => 'stockTransfers.edit', 'guard_name' => 'web']);
        $this->user->givePermissionTo('stockTransfers.edit');

        $this->actingAs($this->user);
    }

    private function makeTransfer(string $status): Transfer
    {
        $transfer = Transfer::create([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => null,
            'stock_condition' => Transfer::CONDITION_GOOD,
            'status' => $status,
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
    public function controller_edit_rejects_a_transfer_from_another_tenant()
    {
        session(['setting_id' => $this->otherSetting->id]);

        $transfer = $this->makeTransfer(Transfer::STATUS_DRAFT);

        $response = $this->get(route('transfers.edit', $transfer->id));

        $response->assertForbidden();
    }

    /** @test */
    public function controller_edit_rejects_an_approved_transfer()
    {
        session(['setting_id' => $this->setting->id]);

        $transfer = $this->makeTransfer(Transfer::STATUS_APPROVED);

        $response = $this->get(route('transfers.edit', $transfer->id));

        $response->assertForbidden();
    }

    /** @test */
    public function controller_edit_allows_draft_and_pending_from_the_origin_tenant()
    {
        session(['setting_id' => $this->setting->id]);

        foreach ([Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING] as $status) {
            $transfer = $this->makeTransfer($status);

            $response = $this->get(route('transfers.edit', $transfer->id));

            $response->assertOk();
        }
    }

    /** @test */
    public function livewire_save_draft_is_forbidden_for_an_approved_transfer()
    {
        session(['setting_id' => $this->setting->id]);

        $transfer = $this->makeTransfer(Transfer::STATUS_APPROVED);

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class, ['transfer' => $transfer])
            ->set('rows', [[
                'id' => $this->product->id,
                'quantity_tax' => 1,
                'quantity_non_tax' => 1,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'stock' => [
                    'quantity_tax' => 10,
                    'quantity_non_tax' => 10,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                ],
            ]])
            ->call('saveDraft');

        $this->assertEquals(Transfer::STATUS_APPROVED, $transfer->fresh()->status);
    }
}
