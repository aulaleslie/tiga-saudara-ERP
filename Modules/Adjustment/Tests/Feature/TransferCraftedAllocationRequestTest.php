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

/**
 * Task 5.3: crafted-request coverage proving that a client-supplied
 * allocation/stock breakdown -- whether omitted (blind submission) or
 * forged with a low-stock-bypassing value -- can never control persistence.
 * Authoritative allocation is always recomputed server-side from real
 * ProductStock at save time (TransferDraftService::buildProductsData).
 */
class TransferCraftedAllocationRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;
    private Product $product;

    // Real available stock is intentionally small.
    private const REAL_TAX = 2;
    private const REAL_NON_TAX = 3;

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
            'product_name' => 'Crafted Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => self::REAL_TAX + self::REAL_NON_TAX,
            'quantity_tax' => self::REAL_TAX,
            'quantity_non_tax' => self::REAL_NON_TAX,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => 'stockTransfers.create', 'guard_name' => 'web']);
        $this->user->givePermissionTo('stockTransfers.create');
    }

    /** @test */
    public function forged_high_bucket_values_cannot_bypass_real_stock_limits()
    {
        // Crafted row: requested_quantity honestly says 5, but the bucket
        // fields and "stock" snapshot are forged to claim far more stock is
        // available than actually exists at the origin.
        $forgedRow = [
            'id' => $this->product->id,
            'requested_quantity' => 999999,
            'quantity_tax' => 500000,
            'quantity_non_tax' => 499999,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'stock' => [
                'quantity_tax' => 500000,
                'quantity_non_tax' => 499999,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ],
        ];

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$forgedRow])
            ->call('saveDraft');

        // No transfer was persisted with the forged quantity; the real
        // stock ceiling (5) was never exceeded anywhere in the system.
        $this->assertDatabaseMissing('transfer_products', [
            'product_id' => $this->product->id,
            'quantity' => 999999,
        ]);

        $persisted = TransferProduct::where('product_id', $this->product->id)->first();
        if ($persisted) {
            $this->assertLessThanOrEqual(self::REAL_TAX + self::REAL_NON_TAX, $persisted->quantity);
        }

        // The real stock is untouched.
        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->origin->id)
            ->first();
        $this->assertEquals(self::REAL_TAX, $stock->quantity_tax);
        $this->assertEquals(self::REAL_NON_TAX, $stock->quantity_non_tax);
    }

    /** @test */
    public function blind_submission_with_no_bucket_fields_is_authoritatively_allocated()
    {
        // A blind row: only operator intent (requested_quantity), no bucket
        // breakdown and no "stock" key at all -- exactly what
        // TransferProductTable now sends for a user without
        // stockTransfers.view-system-stock.
        $blindRow = [
            'id' => $this->product->id,
            'requested_quantity' => self::REAL_TAX + self::REAL_NON_TAX,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'is_broken_mode' => false,
        ];

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$blindRow])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $persisted = TransferProduct::where('product_id', $this->product->id)->first();
        $this->assertNotNull($persisted);

        // Authoritative allocation was derived server-side from real stock
        // (non-tax first), never from a client-carried bucket split that
        // was never present in the blind row.
        $this->assertEquals(self::REAL_NON_TAX, $persisted->quantity_non_tax);
        $this->assertEquals(self::REAL_TAX, $persisted->quantity_tax);
        $this->assertEquals(self::REAL_TAX + self::REAL_NON_TAX, $persisted->quantity);
    }

    /** @test */
    public function blind_submission_exceeding_real_stock_is_rejected_without_partial_persistence()
    {
        $blindRow = [
            'id' => $this->product->id,
            'requested_quantity' => self::REAL_TAX + self::REAL_NON_TAX + 100,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'is_broken_mode' => false,
        ];

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$blindRow])
            ->call('saveDraft');

        $this->assertDatabaseMissing('transfers', [
            'origin_location_id' => $this->origin->id,
        ]);
    }
}
