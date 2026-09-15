<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class GlobalSalePaymentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected Customer $customer;
    protected Customer $customer2;
    protected Unit $baseUnit;
    protected Unit $boxUnit;
    protected Category $category;
    protected Location $location;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create(['company_name' => 'Setting 1']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Setting 2']);
        session(['setting_id' => $this->setting1->id]);

        $this->customer = Customer::factory()->create([
            'customer_name' => 'Mega Enterprise Ltd',
            'contact_name' => 'Budi Santoso',
            'setting_id' => $this->setting1->id,
        ]);

        $this->customer2 = Customer::factory()->create([
            'customer_name' => 'Second Enterprise Ltd',
            'contact_name' => 'Siti Rahma',
            'setting_id' => $this->setting2->id,
        ]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        \Spatie\Permission\Models\Permission::findOrCreate('sales.reporting-date.override', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('sales.due-date.override', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('sales.edit', 'web');

        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', fn() => true);

        $this->baseUnit = Unit::create([
            'name' => 'PIECE',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting1->id,
        ]);

        $this->boxUnit = Unit::create([
            'name' => 'BOX',
            'short_name' => 'BOX',
            'operator' => '*',
            'operation_value' => 10,
            'setting_id' => $this->setting1->id,
        ]);

        $this->category = Category::create([
            'category_name' => 'Sale Category',
            'category_code' => 'SALECAT',
            'setting_id' => $this->setting1->id,
            'created_by' => $this->user->id,
        ]);

        $this->location = Location::create([
            'name' => 'Sales Central Warehouse',
            'setting_id' => $this->setting1->id,
        ]);
    }

    private function createSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => $this->setting1->id,
        ], $overrides));
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Gamma Ultrabook Pro',
            'product_code' => 'GUP-001',
            'barcode' => 'BAR-GUP-998877',
            'setting_id' => $this->setting1->id,
            'product_quantity' => 50,
            'product_cost' => 50000,
            'product_price' => 75000,
            'category_id' => $this->category->id,
            'product_unit' => $this->baseUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function createSaleDetail(Sale $sale, ?Product $product, array $overrides = []): SaleDetails
    {
        return SaleDetails::create(array_merge([
            'sale_id' => $sale->id,
            'product_id' => $product?->id,
            'product_name' => $product?->product_name ?? 'Product Name',
            'product_code' => $product?->product_code ?? 'Product Code',
            'quantity' => 1,
            'unit_price' => 100000,
            'price' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'sub_total' => 100000,
        ], $overrides));
    }

    public function test_cross_field_and_tokens_match_and_missing_token_excludes()
    {
        $product = $this->createProduct(['product_name' => 'HighEnd Graphics Workstation']);

        $matchingSale = $this->createSale([
            'reference' => 'SO-TOKEN-MATCH-01',
            'note' => 'Urgent Priority Order',
        ]);
        $this->createSaleDetail($matchingSale, $product, [
            'product_name' => 'Snapshot Name',
        ]);

        $nonMatchingSale = $this->createSale([
            'reference' => 'SO-TOKEN-FAIL-02',
            'note' => 'Standard Priority Order',
        ]);
        $this->createSaleDetail($nonMatchingSale, $product, [
            'product_name' => 'Snapshot Name',
        ]);

        // "Budi Graphics Urgent" -> token1 matches customer contact_name (Budi), token2 matches current product name (Graphics), token3 matches note (Urgent)
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Budi Graphics Urgent')
            ->assertSee('SO-TOKEN-MATCH-01')
            ->assertDontSee('SO-TOKEN-FAIL-02');

        // Missing token "AbsentToken" should exclude all
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Budi Graphics AbsentToken')
            ->assertDontSee('SO-TOKEN-MATCH-01')
            ->assertDontSee('SO-TOKEN-FAIL-02');
    }

    public function test_current_product_authority_and_snapshot_only_exclusion()
    {
        $product = $this->createProduct([
            'product_name' => 'Current Catalog Laptop 15',
            'product_code' => 'LAP-15-CURR',
        ]);

        $sale = $this->createSale(['reference' => 'SO-SNAP-TEST']);
        $this->createSaleDetail($sale, $product, [
            'product_name' => 'Old Discarded Snapshot Name',
            'product_code' => 'OLD-SALE-SNAP-CODE',
        ]);

        // Current catalog name matches
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Laptop 15')
            ->assertSee('SO-SNAP-TEST');

        // Current catalog code matches
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'LAP-15-CURR')
            ->assertSee('SO-SNAP-TEST');

        // Old detail snapshot name only fails
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Old Discarded Snapshot')
            ->assertDontSee('SO-SNAP-TEST');

        // Old detail snapshot code only fails
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'OLD-SALE-SNAP-CODE')
            ->assertDontSee('SO-SNAP-TEST');
    }

    public function test_inactive_linked_product_remains_discoverable()
    {
        $inactiveProduct = $this->createProduct([
            'product_name' => 'Legacy Discontinued Router',
            'is_active' => false,
        ]);

        $sale = $this->createSale(['reference' => 'SO-INACTIVE-PROD']);
        $this->createSaleDetail($sale, $inactiveProduct, [
            'product_name' => 'Snapshot Name',
        ]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Discontinued Router')
            ->assertSee('SO-INACTIVE-PROD');
    }

    public function test_exact_case_insensitive_primary_and_conversion_barcode_lookup()
    {
        $product = $this->createProduct([
            'barcode' => 'SaleBarcodePrimary777',
        ]);

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->baseUnit->id,
            'conversion_factor' => 10,
            'barcode' => 'SaleBarcodeBox888',
        ]);

        $sale = $this->createSale(['reference' => 'SO-BARCODE-01']);
        $this->createSaleDetail($sale, $product, [
            'product_name' => 'Product Name',
            'product_code' => 'CODE',
        ]);

        // Primary barcode exact match with lower / upper casing
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'salebarcodeprimary777')
            ->assertSee('SO-BARCODE-01');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SALEBARCODEPRIMARY777')
            ->assertSee('SO-BARCODE-01');

        // Conversion barcode exact match with lower / upper casing
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'salebarcodebox888')
            ->assertSee('SO-BARCODE-01');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SALEBARCODEBOX888')
            ->assertSee('SO-BARCODE-01');

        // Substring / partial barcode search must NOT match
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SaleBarcodePrimary')
            ->assertDontSee('SO-BARCODE-01');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SaleBarcodeBox')
            ->assertDontSee('SO-BARCODE-01');
    }

    public function test_exact_dispatched_serial_lineage_and_isolation_across_sales()
    {
        $product = $this->createProduct();

        $saleWithSerial = $this->createSale(['reference' => 'SO-HAS-SERIAL']);
        $detail = $this->createSaleDetail($saleWithSerial, $product);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-EXACT-SALE-001',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $saleWithSerial->id,
            'product_serial_number_id' => $serial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
        ]);

        // Another sale with same product but without that serial provenance
        $saleWithoutSerial = $this->createSale(['reference' => 'SO-NO-SERIAL']);
        $this->createSaleDetail($saleWithoutSerial, $product);

        // Exact match with different casing succeeds for SO-HAS-SERIAL only
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'sn-exact-sale-001')
            ->assertSee('SO-HAS-SERIAL')
            ->assertDontSee('SO-NO-SERIAL');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SN-EXACT-SALE-001')
            ->assertSee('SO-HAS-SERIAL')
            ->assertDontSee('SO-NO-SERIAL');

        // Partial serial search must NOT match
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SN-EXACT-SALE')
            ->assertDontSee('SO-HAS-SERIAL')
            ->assertDontSee('SO-NO-SERIAL');
    }

    public function test_isolated_dispatch_detail_json_array_serial_provenance_and_special_chars()
    {
        $product = $this->createProduct();

        $saleWithDispatchOnly = $this->createSale(['reference' => 'SO-DISPATCH-ONLY']);
        $this->createSaleDetail($saleWithDispatchOnly, $product);

        $dispatch = Dispatch::create([
            'sale_id' => $saleWithDispatchOnly->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        // Serial containing wildcard-like characters '%' and '_'
        $specialSerial = 'SN_100%_SPECIAL';
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $saleWithDispatchOnly->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$specialSerial, 'ANOTHER-SN']),
        ]);

        $otherSale = $this->createSale(['reference' => 'SO-OTHER-DISPATCH']);
        $this->createSaleDetail($otherSale, $product);

        // Exact match with lowercase/uppercase input succeeds
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'sn_100%_special')
            ->assertSee('SO-DISPATCH-ONLY')
            ->assertDontSee('SO-OTHER-DISPATCH');

        // Partial match with wildcard character must NOT match
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'SN_100')
            ->assertDontSee('SO-DISPATCH-ONLY');
    }

    public function test_isolated_sale_details_serial_number_ids_fallback_and_null_product_id()
    {
        $product = $this->createProduct();

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-DETAIL-FALLBACK-777',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $saleWithSerialIds = $this->createSale(['reference' => 'SO-SERIAL-IDS-ONLY']);
        // Simulate historical record where product was deleted/nullified
        $this->createSaleDetail($saleWithSerialIds, null, [
            'product_id' => null,
            'product_name' => 'Historical Unlinked Item',
            'product_code' => 'HIST-001',
            'serial_number_ids' => [$serial->id],
        ]);

        $otherSale = $this->createSale(['reference' => 'SO-OTHER-DETAIL']);
        $this->createSaleDetail($otherSale, $product);

        // Exact search matches even when product_id is null on sale_details
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'sn-detail-fallback-777')
            ->assertSee('SO-SERIAL-IDS-ONLY')
            ->assertDontSee('SO-OTHER-DETAIL');
    }

    public function test_pos_and_bundle_fields_participate_in_tokenized_text_search()
    {
        $product = $this->createProduct(['product_name' => 'Bundle Base System']);

        $sale = $this->createSale(['reference' => 'SO-POS-BUNDLE-01']);
        $detail = $this->createSaleDetail($sale, $product);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => 'Special Cooling Fan Addon',
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // Search token matching bundle item name
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'Cooling Fan')
            ->assertSee('SO-POS-BUNDLE-01');
    }

    public function test_search_or_predicates_cannot_bypass_party_business_or_eligibility_constraints()
    {
        $product = $this->createProduct(['product_name' => 'Secure Laptop 99']);

        // Sale in Setting 1 (Customer 1)
        $saleSetting1 = $this->createSale([
            'reference' => 'SO-SETTING-1',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_APPROVED,
        ]);
        $this->createSaleDetail($saleSetting1, $product);

        // Sale in Setting 2 (Customer 2)
        $saleSetting2 = $this->createSale([
            'reference' => 'SO-SETTING-2',
            'setting_id' => $this->setting2->id,
            'customer_id' => $this->customer2->id,
            'status' => Sale::STATUS_APPROVED,
        ]);
        $this->createSaleDetail($saleSetting2, $product);

        // Ineligible Draft sale in Setting 1
        $ineligibleDraftSale = $this->createSale([
            'reference' => 'SO-INELIGIBLE-DRAFT',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'status' => Sale::STATUS_DRAFTED,
        ]);
        $this->createSaleDetail($ineligibleDraftSale, $product);

        // 1. Search while filtering by Setting 1 only -> Setting 2 and Draft must NOT appear despite matching product
        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'globalBusinessFilters' => [$this->setting1->id],
        ])
            ->set('search', 'Secure Laptop')
            ->assertSee('SO-SETTING-1')
            ->assertDontSee('SO-SETTING-2')
            ->assertDontSee('SO-INELIGIBLE-DRAFT');

        // 2. Search while embedded under Customer 2 -> Setting 1 must NOT appear
        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'customerId' => $this->customer2->id,
        ])
            ->set('search', 'Secure Laptop')
            ->assertSee('SO-SETTING-2')
            ->assertDontSee('SO-SETTING-1');
    }

    public function test_ordinary_non_global_sales_list_search_remains_snapshot_based()
    {
        $product = $this->createProduct([
            'product_name' => 'Current Catalog Sale Product',
        ]);

        $sale = $this->createSale([
            'reference' => 'SO-ORDINARY-LIST',
            'setting_id' => $this->setting1->id,
        ]);
        $this->createSaleDetail($sale, $product, [
            'product_name' => 'Persisted Sales Snapshot Name',
            'product_code' => 'SALES-SNAP-001',
        ]);

        // In non-global mode ($globalMode = false), ordinary search matches detail snapshot
        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => false,
            'settingId' => $this->setting1->id,
        ])
            ->set('search', 'Persisted Sales Snapshot')
            ->assertSee('SO-ORDINARY-LIST');
    }
}
