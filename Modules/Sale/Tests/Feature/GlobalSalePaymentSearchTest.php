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

    public function test_empty_whitespace_repeated_tokens_and_broad_matches_without_caps()
    {
        $sales = [];
        for ($i = 1; $i <= 7; $i++) {
            $sales[] = $this->createSale([
                'reference' => "SO-BROAD-CAP-{$i}",
                'note' => 'CommonBroadToken shared note',
                'setting_id' => $this->setting1->id,
            ]);
        }
        $unrelated = $this->createSale([
            'reference' => 'SO-UNRELATED-EMPTY',
            'note' => 'SomethingElse',
            'setting_id' => $this->setting1->id,
        ]);

        // Empty and whitespace-only inputs are no-ops
        $emptyIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query()->orderBy('id'),
            ''
        )->pluck('id')->all();
        $whitespaceIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query()->orderBy('id'),
            "   \t\n  "
        )->pluck('id')->all();
        $allIds = \Modules\Sale\Entities\Sale::query()->orderBy('id')->pluck('id')->all();

        $this->assertSame($allIds, $emptyIds);
        $this->assertSame($allIds, $whitespaceIds);

        // Broad multi-row match across > 5 records without truncation
        $broadIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query()->orderBy('id'),
            'CommonBroadToken'
        )->pluck('id')->all();
        $this->assertCount(7, $broadIds);
        $this->assertNotContains($unrelated->id, $broadIds);

        // Repeated identical tokens match the exact same distinct set without duplicates
        $repeatedIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query()->orderBy('id'),
            'CommonBroadToken CommonBroadToken'
        )->pluck('id')->all();
        $this->assertSame($broadIds, $repeatedIds);

        // Non-matching query returns empty without error
        $noneIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query(),
            'DefinitelyMissingSearchToken999'
        )->pluck('id')->all();
        $this->assertSame([], $noneIds);
    }

    public function test_all_header_customer_tag_pos_split_bundle_and_cross_field_token_routes()
    {
        $contactCustomer = Customer::factory()->create([
            'customer_name' => 'PT Harapan Nusantara',
            'contact_name' => 'Pak Hendrawan',
            'setting_id' => $this->setting1->id,
        ]);

        $saleHeader = $this->createSale([
            'reference' => 'SO-HDR-ROUTE',
            'imported_sales_reference_number' => 'IMP-LEGACY-551',
            'tax_ref_no' => 'FP-010-2026-88',
            'note' => 'UrgentCrossNote dispatch',
            'customer_id' => $contactCustomer->id,
            'customer_name' => $contactCustomer->canonical_name,
            'setting_id' => $this->setting1->id,
        ]);
        $saleHeader->attachTag('PriorityVIP');

        $product = $this->createProduct([
            'product_name' => 'Alpha Industrial Compressor',
            'product_code' => 'AIC-PRO-900',
        ]);
        $detail = $this->createSaleDetail($saleHeader, $product);

        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $saleHeader->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => 'Silent Muffler Addon',
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Search Term 1',
            'code' => 'T-SRCH-1',
            'is_active' => true,
        ]);
        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);
        $trxSplit = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'customer_id' => $contactCustomer->id,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $session->id,
            'code' => 'TRX-SPLIT-ROUTE-77',
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checkoutSplit = \Modules\Pos\Entities\PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trxSplit->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $contactCustomer->id,
            'receipt_number' => 'RCP-SPLIT-8899',
            'status' => \Modules\Pos\Entities\PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid('', true),
            'payload_hash' => 'hash_split_' . uniqid(),
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 0,
        ]);

        $salePosSplit = $this->createSale([
            'reference' => 'SO-POS-SPLIT-99',
            'customer_id' => $contactCustomer->id,
            'customer_name' => $contactCustomer->canonical_name,
            'setting_id' => $this->setting1->id,
        ]);
        \Modules\Pos\Entities\PosCheckoutSale::create([
            'pos_checkout_id' => $checkoutSplit->id,
            'sale_id' => $salePosSplit->id,
            'split_key' => 'main',
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location->id,
            'tax_bucket' => 'NON_PKP',
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 0,
        ]);

        // Single sources + cross-field intersections
        foreach ([
            'IMP-LEGACY-551 Pak Hendrawan' => [$saleHeader->id],
            'FP-010-2026-88 UrgentCrossNote' => [$saleHeader->id],
            'PriorityVIP AIC-PRO-900' => [$saleHeader->id],
            'Silent SO-HDR-ROUTE' => [$saleHeader->id],
            'TRX-SPLIT-ROUTE-77 RCP-SPLIT-8899' => [$salePosSplit->id],
            'PriorityVIP SO-POS-SPLIT-99' => [],
        ] as $term => $expectedIds) {
            $actualIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
                \Modules\Sale\Entities\Sale::query()->orderBy('id'),
                $term
            )->pluck('id')->all();
            $this->assertSame($expectedIds, $actualIds, "Failed search term: {$term}");
        }
    }

    public function test_full_input_identity_matches_alongside_text_matches_numeric_and_string_json_serials_and_deduplication()
    {
        // 1. Multi-word serial + full-input barcode do not require all tokens to match text,
        //    yet also preserve pure text matches (exact match does NOT terminate text search).
        $barcodeProduct = $this->createProduct([
            'product_name' => 'Barcode Device',
            'product_code' => 'BD-100',
            'barcode' => 'BAR MULTI 001',
        ]);
        $saleBarcode = $this->createSale([
            'reference' => 'SO-BARCODE-FULL',
            'setting_id' => $this->setting1->id,
        ]);
        $this->createSaleDetail($saleBarcode, $barcodeProduct);

        $saleTextSamePhrase = $this->createSale([
            'reference' => 'SO-TEXT-SAME-PHRASE',
            'note' => 'Contains BAR and MULTI and 001 in note',
            'setting_id' => $this->setting1->id,
        ]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('search', 'bar multi 001')
            ->assertSee('SO-BARCODE-FULL')
            ->assertSee('SO-TEXT-SAME-PHRASE');

        // 2. Absent catalogue product (product_id = null): BOTH numeric JSON [id] and stringified JSON ["id"] match once.
        $serialNum = ProductSerialNumber::create([
            'product_id' => $barcodeProduct->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-MULTI-ROUTE 77%',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);
        $serialStr = ProductSerialNumber::create([
            'product_id' => $barcodeProduct->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-STRING-JSON-88',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // Duplicate routes on the same Sale (tracking + dispatch JSON + two sale_details with numeric serial_number_ids)
        $saleMultiRoute = $this->createSale([
            'reference' => 'SO-MULTI-ROUTE-ONCE',
            'setting_id' => $this->setting1->id,
        ]);
        $this->createSaleDetail($saleMultiRoute, null, [
            'product_id' => null,
            'serial_number_ids' => [$serialNum->id],
        ]);
        $this->createSaleDetail($saleMultiRoute, null, [
            'product_id' => null,
            'serial_number_ids' => [$serialNum->id],
        ]);
        SalesOrderSerialTracking::create([
            'sale_id' => $saleMultiRoute->id,
            'product_serial_number_id' => $serialNum->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
        ]);
        $dispatch = Dispatch::create([
            'sale_id' => $saleMultiRoute->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $saleMultiRoute->id,
            'product_id' => $barcodeProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['SN-MULTI-ROUTE 77%']),
        ]);

        // Stringified JSON ["123"] on sale_details with product_id = null.
        // Note: SQLite json_each matches this due to INTEGER column/value affinity; MySQL numeric-only JSON_CONTAINS parity is verified in GlobalPaymentSettlementReadsMysqlTest.
        $saleStringJson = $this->createSale([
            'reference' => 'SO-STRING-JSON-ONCE',
            'setting_id' => $this->setting1->id,
        ]);
        $strDetail = $this->createSaleDetail($saleStringJson, null, [
            'product_id' => null,
        ]);
        \Illuminate\Support\Facades\DB::table('sale_details')
            ->where('id', $strDetail->id)
            ->update(['serial_number_ids' => json_encode([(string) $serialStr->id])]);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $multiRouteQuery = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query(),
            'sn-multi-route 77%'
        );
        $buildQueries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(0, $buildQueries, 'applySaleSearch must defer serial lookup into SQL without eager queries outside snapshots');

        $matchedMultiRouteIds = $multiRouteQuery->pluck('id')->all();
        $this->assertSame([$saleMultiRoute->id], $matchedMultiRouteIds);

        $matchedStringJsonIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            \Modules\Sale\Entities\Sale::query(),
            'sn-string-json-88'
        )->pluck('id')->all();
        $this->assertSame([$saleStringJson->id], $matchedStringJsonIds);
    }

    public function test_long_input_exceeding_61_distinct_tokens_intersects_without_join_limit()
    {
        $tokens = [];
        for ($i = 1; $i <= 65; $i++) {
            $tokens[] = sprintf('tok%02d', $i);
        }

        $noteTokens = implode(' ', array_slice($tokens, 0, 25));
        $customerTokens = implode(' ', array_slice($tokens, 25, 25));
        $productAllTokens = implode(' ', array_slice($tokens, 50, 15));
        $productMissingOneTokens = implode(' ', array_slice($tokens, 50, 14));

        $productMatch = $this->createProduct([
            'product_name' => $productAllTokens,
            'product_code' => 'SKU-LONG-65',
            'barcode' => 'BAR-LONG-65',
        ]);
        $productPartial = $this->createProduct([
            'product_name' => $productMissingOneTokens,
            'product_code' => 'SKU-LONG-64',
            'barcode' => 'BAR-LONG-64',
        ]);

        $longCustomer = Customer::factory()->create([
            'customer_name' => $customerTokens,
            'contact_name' => 'Long Contact',
            'setting_id' => $this->setting1->id,
        ]);

        $saleAll65 = $this->createSale([
            'reference' => 'SO-LONG-65',
            'customer_id' => $longCustomer->id,
            'customer_name' => $longCustomer->canonical_name,
            'note' => $noteTokens,
            'setting_id' => $this->setting1->id,
        ]);
        $this->createSaleDetail($saleAll65, $productMatch);

        $saleOnly64 = $this->createSale([
            'reference' => 'SO-LONG-64',
            'customer_id' => $longCustomer->id,
            'customer_name' => $longCustomer->canonical_name,
            'note' => $noteTokens,
            'setting_id' => $this->setting1->id,
        ]);
        $this->createSaleDetail($saleOnly64, $productPartial);

        $matchedIds = \App\Support\GlobalPaymentSearchQuery::applySaleSearch(
            Sale::query(),
            implode(' ', $tokens)
        )->pluck('id')->all();

        $this->assertSame([$saleAll65->id], $matchedIds);
    }

    public function test_search_respects_outer_card_business_customer_and_archived_scopes()
    {
        $unpaidDueSale = $this->createSale([
            'reference' => 'SO-SCOPE-DUE',
            'note' => 'ScopeTargetWidget',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'payment_status' => 'UNPAID',
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);

        $paidSale = $this->createSale([
            'reference' => 'SO-SCOPE-PAID',
            'note' => 'ScopeTargetWidget',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'payment_status' => 'PAID',
            'total_amount' => 150000,
            'paid_amount' => 150000,
            'due_amount' => 0,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        \Modules\Sale\Entities\SalePayment::create([
            'sale_id' => $paidSale->id,
            'amount' => 150000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-SCOPE-PAID',
            'status' => \Modules\Sale\Entities\SalePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
        ]);

        $returnedArchivedSale = $this->createSale([
            'reference' => 'SO-SCOPE-RETURNED',
            'note' => 'ScopeTargetWidget',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'status' => \Modules\Sale\Entities\Sale::STATUS_RETURNED,
            'payment_status' => 'UNPAID',
            'total_amount' => 150000,
            'due_amount' => 150000,
        ]);

        $otherBusinessSale = $this->createSale([
            'reference' => 'SO-SCOPE-SET2',
            'note' => 'ScopeTargetWidget',
            'setting_id' => $this->setting2->id,
            'customer_id' => $this->customer2->id,
            'payment_status' => 'UNPAID',
            'total_amount' => 150000,
            'due_amount' => 150000,
        ]);

        // Card filter + search never leaks PAID, RETURNED/archived, or other customer/business rows
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->set('dueAmountOnly', true)
            ->set('search', 'ScopeTargetWidget')
            ->assertSee('SO-SCOPE-DUE')
            ->assertDontSee('SO-SCOPE-PAID')
            ->assertDontSee('SO-SCOPE-RETURNED');

        Livewire::test(\App\Livewire\Sale\SaleTable::class, [
            'globalMode' => true,
            'customerId' => $this->customer->id,
        ])
            ->set('globalBusinessFilters', [$this->setting1->id])
            ->set('search', 'ScopeTargetWidget')
            ->assertSee('SO-SCOPE-DUE')
            ->assertSee('SO-SCOPE-PAID')
            ->assertDontSee('SO-SCOPE-RETURNED')
            ->assertDontSee('SO-SCOPE-SET2');
    }
}
