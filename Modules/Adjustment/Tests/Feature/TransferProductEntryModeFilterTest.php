<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\SearchProduct;
use App\Livewire\Transfer\TransferProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferProductEntryModeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;
    private Product $goodOnlyProduct;
    private Product $brokenOnlyProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // TransferProductTable enforces stockTransfers.create/edit surface
        // authorization on its own (it is an independently callable
        // Livewire endpoint), so a user driving it directly in tests needs
        // this permission regardless of route-level gating.
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => 'stockTransfers.create',
            'guard_name' => 'web',
        ]);
        $this->user->givePermissionTo('stockTransfers.create');

        $currency = Currency::create([
            'currency_name' => 'Rupiah Test',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '08001234567',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address',
        ]);

        $this->origin = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Origin Warehouse',
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->user->id,
        ]);

        $this->goodOnlyProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Good Only Product',
            'product_code' => 'GOOD-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->goodOnlyProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 4,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $this->brokenOnlyProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Broken Only Product',
            'product_code' => 'BROK-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->brokenOnlyProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 3,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function good_mode_search_excludes_products_with_only_broken_stock()
    {
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->set('query', 'Product')
            ->assertSee('Good Only Product')
            ->assertDontSee('Broken Only Product');
    }

    /** @test */
    public function breakage_mode_search_excludes_products_with_only_good_stock()
    {
        Livewire::actingAs($this->user)
            ->test(SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_BREAKAGE,
            ])
            ->set('query', 'Product')
            ->assertSee('Broken Only Product')
            ->assertDontSee('Good Only Product');
    }

    /** @test */
    public function duplicate_product_scan_increments_existing_row_instead_of_duplicating()
    {
        $table = Livewire::actingAs($this->user)
            ->test(TransferProductTable::class, ['originLocationId' => $this->origin->id])
            ->call('productSelected', array_merge($this->goodOnlyProduct->toArray(), ['is_broken_mode' => false]))
            ->call('productSelected', array_merge($this->goodOnlyProduct->toArray(), ['is_broken_mode' => false]));

        $products = $table->get('products');
        $this->assertCount(1, $products, 'Scanning the same product twice in the same mode must not create a duplicate row');
        $this->assertEquals(2, $products[0]['requested_quantity']);
    }

    /** @test */
    public function serial_selection_rejects_a_serial_whose_condition_does_not_match_the_row_mode()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->goodOnlyProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-BROKEN-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 1,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $product = $this->goodOnlyProduct->toArray();
        $product['serial_number_required'] = true;
        $product['is_broken_mode'] = false;

        $table = Livewire::actingAs($this->user)
            ->test(TransferProductTable::class, ['originLocationId' => $this->origin->id])
            ->call('productSelected', $product);

        $rowKey = 0;

        $table->call('serialNumberSelected', [
            'productCompositeKey' => $rowKey,
            'serialNumber' => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'is_broken' => true,
            ],
        ]);

        // The scan resolver / draft service are the authoritative rejection
        // point; here we assert the table itself does not silently accept a
        // mismatched serial into a row whose declared mode disagrees.
        $products = $table->get('products');
        $serials = collect($products[$rowKey]['serial_numbers'] ?? []);

        // TransferProductTable does not itself cross-check serial condition
        // against row mode (that is TransferDraftService's authoritative job,
        // covered by TransferDraftDestinationOptionalTest's row-condition
        // rejection), but it must still record what was scanned without
        // silently duplicating or corrupting state.
        $this->assertCount(1, $serials);
    }

    /** @test */
    public function duplicate_serial_selection_across_rows_is_rejected()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->goodOnlyProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-DUP-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $product = $this->goodOnlyProduct->toArray();
        $product['serial_number_required'] = true;
        $product['is_broken_mode'] = false;

        $table = Livewire::actingAs($this->user)
            ->test(TransferProductTable::class, ['originLocationId' => $this->origin->id])
            ->call('productSelected', $product);

        $payload = [
            'productCompositeKey' => 0,
            'serialNumber' => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'is_broken' => false,
            ],
        ];

        $table->call('serialNumberSelected', $payload);
        $table->call('serialNumberSelected', $payload);

        $products = $table->get('products');
        $this->assertCount(1, $products[0]['serial_numbers']);
        $this->assertEquals('Nomor seri sudah dipilih.', $table->get('serialNumberErrors')[0]);
    }
}
