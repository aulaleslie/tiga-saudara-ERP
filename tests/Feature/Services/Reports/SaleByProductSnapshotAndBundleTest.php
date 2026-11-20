<?php

namespace Tests\Feature\Services\Reports;

use App\Exports\SaleByProductReportExport;
use App\Livewire\Reports\SaleByProductReport;
use App\Models\User;
use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SalesCostSnapshotService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleByProductSnapshotAndBundleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting1;
    private Setting $setting2;
    private Customer $customer;
    private Category $atkRoot;
    private Category $atkChild;
    private Category $laptopRoot;
    private Brand $brandA;
    private Brand $brandB;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Bisnis Utama',
            'company_email' => 'b1@example.com',
            'company_phone' => '0811111111',
            'notification_email' => 'b1@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'F1',
            'company_address' => 'Addr 1',
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Bisnis Kedua',
            'company_email' => 'b2@example.com',
            'company_phone' => '0822222222',
            'notification_email' => 'b2@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'F2',
            'company_address' => 'Addr 2',
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
        $perm = Permission::firstOrCreate(['name' => 'saleReports.access']);
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo($perm);
        $this->user->assignRole($role);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting1->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer Umum',
            'customer_email' => 'cust@example.com',
            'customer_phone' => '0812345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl. Test',
            'setting_id' => $this->setting1->id,
        ]);

        $this->atkRoot = Category::create([
            'category_code' => 'ATK',
            'category_name' => 'ATK',
            'created_by' => $this->user->id,
        ]);
        $this->atkChild = Category::create([
            'category_code' => 'ATK-KERTAS',
            'category_name' => 'Kertas',
            'parent_id' => $this->atkRoot->id,
            'created_by' => $this->user->id,
        ]);
        $this->laptopRoot = Category::create([
            'category_code' => 'LAPTOP',
            'category_name' => 'LAPTOP',
            'created_by' => $this->user->id,
        ]);

        $this->brandA = Brand::create(['name' => 'Brand Alpha', 'created_by' => $this->user->id]);
        $this->brandB = Brand::create(['name' => 'Brand Beta', 'created_by' => $this->user->id]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        static $seq = 1;
        $n = $seq++;

        return Product::create(array_merge([
            'setting_id' => $this->setting1->id,
            'category_id' => $this->atkRoot->id,
            'brand_id' => $this->brandA->id,
            'product_name' => "Produk {$n}",
            'product_code' => "PRD-{$n}",
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'stock_managed' => true,
        ], $overrides));
    }

    private function makeSale(array $overrides = []): Sale
    {
        static $seq = 1;
        $n = $seq++;

        return Sale::create(array_merge([
            'date' => '2026-11-15',
            'reporting_date' => '2026-11-15',
            'reference' => "SL-{$n}",
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
            'is_tax_included' => false,
        ], $overrides));
    }

    private function linkPosSale(Sale $sale): void
    {
        if (!DB::getSchemaBuilder()->hasTable('pos_checkout_sales')) {
            return;
        }

        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('pos_checkout_sales')->insert([
            'pos_checkout_id' => 1,
            'split_key' => 'split-' . $sale->id,
            'source_setting_id' => $sale->setting_id,
            'source_location_id' => 1,
            'tax_bucket' => 'non_tax',
            'sale_id' => $sale->id,
            'subtotal' => $sale->total_amount,
            'tax_total' => 0,
            'grand_total' => $sale->total_amount,
            'paid_total' => $sale->paid_amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function it_decomposes_pos_bundle_revenue_once_and_keeps_direct_and_component_roles_separate(): void
    {
        $laptop = $this->makeProduct(['category_id' => $this->laptopRoot->id, 'product_name' => 'Laptop Paket', 'product_code' => 'LP-01']);
        $mouse = $this->makeProduct(['category_id' => $this->atkChild->id, 'product_name' => 'Mouse ATK', 'product_code' => 'MS-01']);

        $sale = $this->makeSale(['total_amount' => 125000]);
        $this->linkPosSale($sale);

        // POS parent subtotal 125,000 includes component subtotal 25,000 -> residual 100,000
        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $laptop->id,
            'product_name' => $laptop->product_name,
            'product_code' => $laptop->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 125000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 80000,
            'cost_total_snapshot' => 80000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $mouse->id,
            'name' => $mouse->product_name,
            'quantity' => 1,
            'price' => 25000,
            'sub_total' => 25000,
            'tax_amount' => 0,
            'cost_unit_snapshot' => 15000,
            'cost_total_snapshot' => 15000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        // Also sell the same Mouse directly to verify direct vs component separation
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $mouse->id,
            'product_name' => $mouse->product_name,
            'product_code' => $mouse->product_code,
            'quantity' => 2,
            'price' => 30000,
            'unit_price' => 30000,
            'sub_total' => 60000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 15000,
            'cost_total_snapshot' => 30000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        $service = new SaleByProductReportQueryService();
        $dataset = $service->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
        ));

        $this->assertCount(3, $dataset['rows']);

        $laptopRow = $dataset['rows']->firstWhere('product_id', $laptop->id);
        $this->assertEquals(100000.0, $laptopRow->sold_value);
        $this->assertEquals(80000.0, $laptopRow->total_cost);
        $this->assertEquals('direct', $laptopRow->sale_role);

        $mouseDirect = $dataset['rows']->first(fn ($r) => $r->product_id === $mouse->id && $r->sale_role === 'direct');
        $mouseComp = $dataset['rows']->first(fn ($r) => $r->product_id === $mouse->id && $r->sale_role === 'component');

        $this->assertEquals(2.0, $mouseDirect->sold_quantity);
        $this->assertEquals(60000.0, $mouseDirect->sold_value);
        $this->assertEquals(30000.0, $mouseDirect->average_sales_value);

        $this->assertEquals(1.0, $mouseComp->sold_quantity);
        $this->assertEquals(25000.0, $mouseComp->sold_value);
        $this->assertEquals(15000.0, $mouseComp->total_cost);

        // Grand totals conserve exact commercial value (100k + 25k + 60k = 185k) and own HPP (80k + 15k + 30k = 125k)
        $this->assertEquals(185000.0, $dataset['totals']['sold_value']);
        $this->assertEquals(125000.0, $dataset['totals']['total_cost']);

        // Filtering to ATK root includes atkChild (Mouse) without changing its assigned 25,000 share
        $atkDataset = $service->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
            categoryIds: [$this->atkRoot->id],
        ));
        $this->assertCount(2, $atkDataset['rows']);
        $this->assertEquals(85000.0, $atkDataset['totals']['sold_value']);
    }

    /** @test */
    public function it_classifies_non_pos_included_standalone_and_ambiguous_components_correctly(): void
    {
        $parent = $this->makeProduct(['product_name' => 'Paket Non POS']);
        $includedComp = $this->makeProduct(['product_name' => 'Komponen Included']);
        $ambiguousComp = $this->makeProduct(['product_name' => 'Komponen Ambigu']);
        $standaloneComp = $this->makeProduct(['product_name' => 'Komponen Standalone']);

        $sale = $this->makeSale(['total_amount' => 100000]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 50000,
            'cost_total_snapshot' => 50000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        // Standard non-POS included component with informational_item_price (not billable)
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $includedComp->id,
            'name' => $includedComp->product_name,
            'quantity' => 2,
            'price' => 0,
            'informational_item_price' => 12000,
            'sub_total' => 0,
            'tax_amount' => 0,
            'cost_unit_snapshot' => 5000,
            'cost_total_snapshot' => 10000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        // Ambiguous legacy non-POS component with nonzero price
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'product_id' => $ambiguousComp->id,
            'name' => $ambiguousComp->product_name,
            'quantity' => 1,
            'price' => 15000,
            'sub_total' => 15000,
            'tax_amount' => 0,
            'cost_unit_snapshot' => 7000,
            'cost_total_snapshot' => 7000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        // Standalone commercial bundle item (sale_detail_id = null)
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => null,
            'bundle_id' => 1,
            'bundle_item_id' => 3,
            'product_id' => $standaloneComp->id,
            'name' => $standaloneComp->product_name,
            'quantity' => 1,
            'price' => 40000,
            'sub_total' => 40000,
            'tax_amount' => 0,
            'cost_unit_snapshot' => 20000,
            'cost_total_snapshot' => 20000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        $dataset = (new SaleByProductReportQueryService())->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
        ));

        $incRow = $dataset['rows']->firstWhere('product_id', $includedComp->id);
        $this->assertEquals('included', $incRow->sold_value_status);
        $this->assertNull($incRow->sold_value);
        $this->assertNull($incRow->average_sales_value);
        $this->assertEquals(10000.0, $incRow->total_cost);

        $ambRow = $dataset['rows']->firstWhere('product_id', $ambiguousComp->id);
        $this->assertEquals('unavailable', $ambRow->sold_value_status);
        $this->assertNull($ambRow->sold_value);
        $this->assertEquals(7000.0, $ambRow->total_cost);

        $stdRow = $dataset['rows']->firstWhere('product_id', $standaloneComp->id);
        $this->assertEquals('complete', $stdRow->sold_value_status);
        $this->assertEquals(40000.0, $stdRow->sold_value);
        $this->assertEquals('component', $stdRow->sale_role);

        // Parent keeps full 100,000 (not reduced by included or ambiguous components)
        $parentRow = $dataset['rows']->firstWhere('product_id', $parent->id);
        $this->assertEquals(100000.0, $parentRow->sold_value);

        // Grand total marks sold_value_partial = true because of ambiguous row
        $this->assertEquals(140000.0, $dataset['totals']['sold_value']);
        $this->assertTrue($dataset['totals']['sold_value_partial']);
    }

    /** @test */
    public function it_calculates_weighted_multi_business_hpp_and_distinguishes_valid_zero_from_missing(): void
    {
        $prodWeighted = $this->makeProduct(['product_name' => 'Produk Weighted']);
        $prodZeroValid = $this->makeProduct(['product_name' => 'Jasa Non Stok', 'stock_managed' => false]);
        $prodMissing = $this->makeProduct(['product_name' => 'Produk Missing HPP']);

        $sale1 = $this->makeSale(['setting_id' => $this->setting1->id]);
        $sale2 = $this->makeSale(['setting_id' => $this->setting2->id]);

        // 2 * 100 + 3 * 200 = 800; weighted avg = 160
        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $prodWeighted->id,
            'product_name' => $prodWeighted->product_name,
            'product_code' => $prodWeighted->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 100,
            'cost_total_snapshot' => 200,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);
        SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $prodWeighted->id,
            'product_name' => $prodWeighted->product_name,
            'product_code' => $prodWeighted->product_code,
            'quantity' => 3,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 200,
            'cost_total_snapshot' => 600,
            'cost_snapshot_source' => 'BACKFILL_HISTORICAL_AVG',
            'cost_snapshot_at' => now(),
        ]);

        // Valid zero (NON_STOCK_MANAGED)
        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $prodZeroValid->id,
            'product_name' => $prodZeroValid->product_name,
            'product_code' => $prodZeroValid->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED,
            'cost_snapshot_at' => now(),
        ]);

        // Missing average price recorded as 0 -> must stay incomplete!
        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $prodMissing->id,
            'product_name' => $prodMissing->product_name,
            'product_code' => $prodMissing->product_code,
            'quantity' => 1,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE,
            'cost_snapshot_at' => now(),
        ]);

        $filter = new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id, $this->setting2->id],
        );

        $service = new SaleByProductReportQueryService();
        $dataset = $service->getDataset($filter);

        $wRow = $dataset['rows']->firstWhere('product_id', $prodWeighted->id);
        $this->assertEquals(5.0, $wRow->sold_quantity);
        $this->assertEquals(800.0, $wRow->total_cost);
        $this->assertEquals(160.0, $wRow->average_cost);
        $this->assertEquals('complete', $wRow->cost_status);

        $zRow = $dataset['rows']->firstWhere('product_id', $prodZeroValid->id);
        $this->assertEquals(0.0, $zRow->total_cost);
        $this->assertEquals(0.0, $zRow->average_cost);
        $this->assertEquals('complete', $zRow->cost_status);

        $mRow = $dataset['rows']->firstWhere('product_id', $prodMissing->id);
        $this->assertNull($mRow->total_cost);
        $this->assertNull($mRow->average_cost);
        $this->assertEquals('missing', $mRow->cost_status);

        $this->assertTrue($dataset['totals']['total_cost_partial']);
        $this->assertEquals(800.0, $dataset['totals']['total_cost']);

        // Verify export parity: valid zero stays numeric 0.0, missing average exports blank '' with reason
        $export = new SaleByProductReportExport($dataset, $filter, false);
        $exported = $export->array();
        $exportedZero = collect($exported)->first(fn ($r) => $r[1] === $prodZeroValid->product_name);
        $exportedMissing = collect($exported)->first(fn ($r) => $r[1] === $prodMissing->product_name);

        $this->assertSame(0.0, $exportedZero[8]);
        $this->assertSame(0.0, $exportedZero[9]);
        $this->assertSame('Lengkap', $exportedZero[10]);

        $this->assertSame('', $exportedMissing[8]);
        $this->assertSame('', $exportedMissing[9]);
        $this->assertSame('Modal belum lengkap', $exportedMissing[10]);

        // Verify serialized CSV preserves numeric 0 for valid zero row (WithStrictNullComparison)
        $csvRaw = \Maatwebsite\Excel\Facades\Excel::raw(
            new SaleByProductReportExport($dataset, $filter, true),
            \Maatwebsite\Excel\Excel::CSV
        );
        $csvLines = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csvRaw)))));
        $csvZeroRow = collect($csvLines)->first(fn ($r) => ($r[1] ?? '') === $prodZeroValid->product_name);
        $csvMissingRow = collect($csvLines)->first(fn ($r) => ($r[1] ?? '') === $prodMissing->product_name);

        $this->assertNotNull($csvZeroRow);
        $this->assertSame('0.00', $csvZeroRow[8]);
        $this->assertSame('0.00', $csvZeroRow[9]);
        $this->assertSame('', $csvMissingRow[8]);
        $this->assertSame('', $csvMissingRow[9]);
    }

    /** @test */
    public function it_marks_backfill_zero_fallback_as_incomplete_while_keeping_backfill_running_average_complete(): void
    {
        $prodFallback = $this->makeProduct(['product_name' => 'Produk Backfill Zero Fallback']);
        $prodValidBackfill = $this->makeProduct(['product_name' => 'Produk Backfill Running Avg']);

        $sale = $this->makeSale();
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $prodFallback->id,
            'product_name' => $prodFallback->product_name,
            'product_code' => $prodFallback->product_code,
            'quantity' => 2,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
            'cost_snapshot_source' => 'BACKFILL_ZERO_FALLBACK',
            'cost_snapshot_at' => now(),
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $prodValidBackfill->id,
            'product_name' => $prodValidBackfill->product_name,
            'product_code' => $prodValidBackfill->product_code,
            'quantity' => 2,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 2500,
            'cost_total_snapshot' => 5000,
            'cost_snapshot_source' => 'BACKFILL_RUNNING_AVERAGE',
            'cost_snapshot_at' => now(),
        ]);

        $dataset = (new SaleByProductReportQueryService())->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
        ));

        $fallbackRow = $dataset['rows']->firstWhere('product_id', $prodFallback->id);
        $this->assertEquals('missing', $fallbackRow->cost_status);
        $this->assertNull($fallbackRow->total_cost);
        $this->assertNull($fallbackRow->average_cost);

        $validRow = $dataset['rows']->firstWhere('product_id', $prodValidBackfill->id);
        $this->assertEquals('complete', $validRow->cost_status);
        $this->assertEquals(5000.0, $validRow->total_cost);
        $this->assertEquals(2500.0, $validRow->average_cost);
    }

    /** @test */
    public function it_filters_by_brand_and_blocks_export_when_brand_filter_changes(): void
    {
        $pA = $this->makeProduct(['brand_id' => $this->brandA->id, 'product_name' => 'Alpha Pen']);
        $pB = $this->makeProduct(['brand_id' => $this->brandB->id, 'product_name' => 'Beta Pen']);

        $sale = $this->makeSale();
        foreach ([$pA, $pB] as $p) {
            SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $p->id,
                'product_name' => $p->product_name,
                'product_code' => $p->product_code,
                'quantity' => 1,
                'price' => 10000,
                'unit_price' => 10000,
                'sub_total' => 10000,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
                'cost_unit_snapshot' => 5000,
                'cost_total_snapshot' => 5000,
                'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
                'cost_snapshot_at' => now(),
            ]);
        }

        Livewire::test(SaleByProductReport::class)
            ->set('startDate', '2026-11-01')
            ->set('endDate', '2026-11-30')
            ->call('toggleBrand', $this->brandA->id, $this->brandA->name)
            ->call('applyFilters')
            ->assertSee($pA->product_name)
            ->assertDontSee($pB->product_name)
            ->call('toggleBrand', $this->brandB->id, $this->brandB->name)
            ->assertSet('hasPendingFilterChanges', true)
            ->call('exportExcel')
            ->assertDispatched('alert', [
                'type' => 'error',
                'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.',
            ]);
    }

    /** @test */
    public function it_maintains_bounded_query_count_regardless_of_result_size(): void
    {
        $sale = $this->makeSale();
        for ($i = 1; $i <= 20; $i++) {
            $p = $this->makeProduct(['product_name' => "Bounded Item {$i}"]);
            SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $p->id,
                'product_name' => $p->product_name,
                'product_code' => $p->product_code,
                'quantity' => 1,
                'price' => 1000,
                'unit_price' => 1000,
                'sub_total' => 1000,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
                'cost_unit_snapshot' => 500,
                'cost_total_snapshot' => 500,
                'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
                'cost_snapshot_at' => now(),
            ]);
        }

        $filter = new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
            categoryIds: [$this->atkRoot->id],
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new SaleByProductReportQueryService())->getDataset($filter);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Constant O(1) queries (category tree + schema checks + details + siblings + bundle items), never per-row.
        $this->assertLessThanOrEqual(10, $queryCount);
    }
}
