<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Livewire\ProductSearchDropdown;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductSearchDropdownTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Category $category;
    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Dropdown Test Co',
            'company_email' => 'dropdown@example.com',
            'company_phone' => '123456',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'dropdown@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
        ]);

        $user = User::factory()->create();

        $this->category = Category::create([
            'category_name' => 'Snacks & Drinks',
            'category_code' => 'SND',
            'setting_id' => $this->setting->id,
            'created_by' => $user->id,
        ]);

        $this->brand = Brand::create([
            'name' => 'BrandNest',
            'setting_id' => $this->setting->id,
            'created_by' => $user->id,
        ]);
    }

    private int $seq = 1;

    private function createProduct(array $attributes): Product
    {
        $seq = $this->seq++;
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_name' => 'Sample Product ' . $seq,
            'product_code' => 'SMP-' . $seq,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'stock_managed' => true,
        ], $attributes));
    }

    /**
     * 1. Empty search retains zero suggestions.
     */
    public function test_empty_search_retains_zero_suggestions(): void
    {
        $this->createProduct(['product_name' => 'Indomie Goreng']);

        Livewire::test(ProductSearchDropdown::class)
            ->set('search', '')
            ->assertSet('search_results', [])
            ->assertSet('query_count', 0);
    }

    /**
     * 2. Product-name search.
     */
    public function test_product_name_search(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Chitato Sapi Panggang', 'product_code' => 'CHT-01']);
        $p2 = $this->createProduct(['product_name' => 'Kusuka Keripik Singkong', 'product_code' => 'KSK-01']);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Chitato')
            ->assertCount('search_results', 1)
            ->assertSee('Chitato Sapi Panggang')
            ->assertDontSee('Kusuka Keripik Singkong');
    }

    /**
     * 3. Product-code search.
     */
    public function test_product_code_search(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Aqua 600ml', 'product_code' => 'AQUA-600']);
        $p2 = $this->createProduct(['product_name' => 'Le Minerale 600ml', 'product_code' => 'LEM-600']);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'AQUA-600')
            ->assertCount('search_results', 1)
            ->assertSee('Aqua 600ml')
            ->assertDontSee('Le Minerale 600ml');
    }

    /**
     * 4. Barcode search.
     */
    public function test_barcode_search(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Teh Botol Sosro', 'barcode' => '8991234567890']);
        $p2 = $this->createProduct(['product_name' => 'Teh Pucuk Harum', 'barcode' => '8999876543210']);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', '8991234567890')
            ->assertCount('search_results', 1)
            ->assertSee('Teh Botol Sosro')
            ->assertDontSee('Teh Pucuk Harum');
    }

    /**
     * 5. Category-name search.
     */
    public function test_category_name_search(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Taro Net', 'category_id' => $this->category->id]);
        $p2 = $this->createProduct(['product_name' => 'Detergen Bubuk', 'category_id' => null]);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Snacks')
            ->assertCount('search_results', 1)
            ->assertSee('Taro Net')
            ->assertDontSee('Detergen Bubuk');
    }

    /**
     * 6. Brand-name search.
     */
    public function test_brand_name_search(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Wafer Coklat', 'brand_id' => $this->brand->id]);
        $p2 = $this->createProduct(['product_name' => 'Biskuit Susu', 'brand_id' => null]);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'BrandNest')
            ->assertCount('search_results', 1)
            ->assertSee('Wafer Coklat')
            ->assertDontSee('Biskuit Susu');
    }

    /**
     * 7. Multi-token matching using AND-per-token behavior.
     * Product matching only one of two tokens is excluded.
     */
    public function test_multi_token_search_and_matching(): void
    {
        $pBoth = $this->createProduct(['product_name' => 'Ultra Milk Coklat 250ml']);
        $pOne = $this->createProduct(['product_name' => 'Ultra Milk Stroberi 250ml']);
        $pOther = $this->createProduct(['product_name' => 'Indomilk Coklat 250ml']);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Ultra Coklat')
            ->assertCount('search_results', 1)
            ->assertSee('Ultra Milk Coklat 250ml')
            ->assertDontSee('Ultra Milk Stroberi 250ml')
            ->assertDontSee('Indomilk Coklat 250ml');
    }

    /**
     * 8. Retired products with merged_into_id are excluded from search.
     */
    public function test_retired_products_are_excluded_from_search(): void
    {
        $survivor = $this->createProduct(['product_name' => 'Active Survivor Biscuit']);
        $retired = $this->createProduct([
            'product_name' => 'Retired Old Biscuit',
            'merged_into_id' => $survivor->id,
        ]);

        Livewire::test(ProductSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Biscuit')
            ->assertCount('search_results', 1)
            ->assertSee('Active Survivor Biscuit')
            ->assertDontSee('Retired Old Biscuit');
    }

    /**
     * 9. Self/parent product remains searchable when no excludeProductId is supplied.
     */
    public function test_self_product_remains_searchable_when_no_exclude_supplied(): void
    {
        $parent = $this->createProduct(['product_name' => 'Parent Bundle Product']);

        Livewire::test(ProductSearchDropdown::class, ['excludeProductId' => null])
            ->set('open', true)
            ->set('search', 'Parent')
            ->assertCount('search_results', 1)
            ->assertSee('Parent Bundle Product');
    }

    /**
     * 10. A product is excluded when excludeProductId is explicitly supplied.
     */
    public function test_product_is_excluded_when_exclude_product_id_supplied(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Excluded Product']);
        $p2 = $this->createProduct(['product_name' => 'Included Product']);

        Livewire::test(ProductSearchDropdown::class, ['excludeProductId' => $p1->id])
            ->set('open', true)
            ->set('search', 'Product')
            ->assertCount('search_results', 1)
            ->assertDontSee('Excluded Product')
            ->assertSee('Included Product');
    }

    /**
     * 11. Direct select($id) cannot select a retired or explicitly excluded product.
     */
    public function test_direct_select_cannot_select_retired_or_excluded_product(): void
    {
        $survivor = $this->createProduct(['product_name' => 'Survivor Active']);
        $retired = $this->createProduct(['product_name' => 'Retired Old', 'merged_into_id' => $survivor->id]);
        $excluded = $this->createProduct(['product_name' => 'Excluded One']);

        // Attempt select on retired product
        Livewire::test(ProductSearchDropdown::class)
            ->call('select', $retired->id)
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null)
            ->assertNotDispatched('productSelected');

        // Attempt select on excluded product
        Livewire::test(ProductSearchDropdown::class, ['excludeProductId' => $excluded->id])
            ->call('select', $excluded->id)
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null)
            ->assertNotDispatched('productSelected');

        // Valid product select succeeds
        Livewire::test(ProductSearchDropdown::class, ['index' => 'row_1'])
            ->call('select', $survivor->id)
            ->assertSet('selected', $survivor->id)
            ->assertSet('selectedLabel', 'Survivor Active')
            ->assertDispatched('productSelected', function ($event, $params) use ($survivor) {
                $productId = is_array($params[1]) ? $params[1]['id'] : $params[1]->id;
                return $params[0] === 'row_1' && $productId === $survivor->id;
            });
    }

    /**
     * 12. query_count reflects the filtered total before display limit and loadMore() increases visible results with stable ordering.
     */
    public function test_query_count_and_load_more_pagination_with_stable_ordering(): void
    {
        // Create 15 products with names ordered deterministically
        for ($i = 1; $i <= 15; $i++) {
            $num = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->createProduct(['product_name' => "Batch Item {$num}"]);
        }

        $component = Livewire::test(ProductSearchDropdown::class)
            ->set('search', 'Batch Item')
            ->assertSet('query_count', 15)
            ->assertCount('search_results', 10);

        // Verify initial 10 results are Item 01 through Item 10
        $initialResults = collect($component->get('search_results'))->pluck('product_name')->all();
        $this->assertEquals('Batch Item 01', $initialResults[0]);
        $this->assertEquals('Batch Item 10', $initialResults[9]);

        // Load more
        $component->call('loadMore')
            ->assertSet('how_many', 20)
            ->assertCount('search_results', 15);

        $expandedResults = collect($component->get('search_results'))->pluck('product_name')->all();
        $this->assertEquals('Batch Item 01', $expandedResults[0]);
        $this->assertEquals('Batch Item 15', $expandedResults[14]);
        // Verify stable ordering (no duplicates)
        $this->assertCount(15, array_unique($expandedResults));
    }

    /**
     * 13. Renders hidden input containing selected product ID when name property is provided.
     */
    public function test_renders_hidden_input_when_name_property_provided(): void
    {
        $product = $this->createProduct(['product_name' => 'Target Form Product']);

        Livewire::test(ProductSearchDropdown::class, [
            'name' => 'product_id',
            'selected' => $product->id,
        ])
        ->assertSeeHtml('<input type="hidden" name="product_id" value="' . $product->id . '">');
    }

    /**
     * 14. Conversion mode excludes inactive, unmanaged, serialized, and zero-stock products.
     */
    public function test_conversion_mode_filters_out_invalid_candidates(): void
    {
        $loc = \Modules\Setting\Entities\Location::create([
            'name' => 'Main Loc',
            'setting_id' => $this->setting->id,
        ]);

        // Eligible candidate
        $eligible = $this->createProduct([
            'product_name' => 'Eligible Gadget',
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $eligible->id,
            'location_id' => $loc->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Inactive
        $inactive = $this->createProduct([
            'product_name' => 'Inactive Gadget',
            'is_active' => false,
        ]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $inactive->id,
            'location_id' => $loc->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Not stock managed
        $unmanaged = $this->createProduct([
            'product_name' => 'Unmanaged Service',
            'stock_managed' => false,
        ]);

        // Already serialized
        $serialized = $this->createProduct([
            'product_name' => 'Serialized iPhone',
            'serial_number_required' => true,
        ]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $serialized->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Zero stock
        $zeroStock = $this->createProduct([
            'product_name' => 'Zero Stock Product',
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $zeroStock->id,
            'location_id' => $loc->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        Livewire::test(ProductSearchDropdown::class, [
            'conversionCandidatesOnly' => true,
        ])
        ->set('open', true)
        ->set('search', 'Gadget')
        ->assertCount('search_results', 1)
        ->assertSee('Eligible Gadget')
        ->assertDontSee('Inactive Gadget');

        Livewire::test(ProductSearchDropdown::class, [
            'conversionCandidatesOnly' => true,
        ])
        ->set('open', true)
        ->set('search', 'Service')
        ->assertCount('search_results', 0);

        Livewire::test(ProductSearchDropdown::class, [
            'conversionCandidatesOnly' => true,
        ])
        ->set('open', true)
        ->set('search', 'iPhone')
        ->assertCount('search_results', 0);

        Livewire::test(ProductSearchDropdown::class, [
            'conversionCandidatesOnly' => true,
        ])
        ->set('open', true)
        ->set('search', 'Zero Stock')
        ->assertCount('search_results', 0);
    }

    /**
     * 15. Existing dropdown consumers retain default search behavior when conversionCandidatesOnly is false.
     */
    public function test_existing_consumers_retain_default_search_behavior(): void
    {
        $serialized = $this->createProduct([
            'product_name' => 'Standard Search Product',
            'serial_number_required' => true,
        ]);

        Livewire::test(ProductSearchDropdown::class, [
            'conversionCandidatesOnly' => false,
        ])
        ->set('open', true)
        ->set('search', 'Standard Search')
        ->assertCount('search_results', 1)
        ->assertSee('Standard Search Product');
    }
}
