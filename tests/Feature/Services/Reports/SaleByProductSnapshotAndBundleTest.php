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
use Maatwebsite\Excel\Facades\Excel;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
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
    public function it_merges_direct_and_pos_bundle_component_into_one_product_unit_row_with_weighted_average(): void
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

        // Also sell the same Mouse directly (2 units / 60,000) -> must merge into 1 row: qty 3, sales 85,000, avg 85000/3
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

        // 2 rows total: Laptop + merged Mouse (no role splitting)
        $this->assertCount(2, $dataset['rows']);

        $laptopRow = $dataset['rows']->firstWhere('product_id', $laptop->id);
        $this->assertEquals(1.0, $laptopRow->sold_quantity);
        $this->assertEquals(100000.0, $laptopRow->sold_value);
        $this->assertEquals(100000.0, $laptopRow->average_sales_value);
        $this->assertObjectNotHasProperty('sale_role', $laptopRow);
        $this->assertObjectNotHasProperty('total_cost', $laptopRow);

        $mouseRow = $dataset['rows']->firstWhere('product_id', $mouse->id);
        $this->assertEquals(3.0, $mouseRow->sold_quantity);
        $this->assertEquals(85000.0, $mouseRow->sold_value);
        $this->assertEqualsWithDelta(85000.0 / 3.0, $mouseRow->average_sales_value, 0.01);

        // Grand totals conserve exact commercial value (100k + 25k + 60k = 185k)
        $this->assertEquals(185000.0, $dataset['totals']['sold_value']);
        $this->assertArrayNotHasKey('total_cost', $dataset['totals']);

        // Filtering to ATK root includes atkChild (Mouse) with combined 3 units / 85,000
        $atkDataset = $service->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
            categoryIds: [$this->atkRoot->id],
        ));
        $this->assertCount(1, $atkDataset['rows']);
        $this->assertEquals(3.0, $atkDataset['rows']->first()->sold_quantity);
        $this->assertEquals(85000.0, $atkDataset['totals']['sold_value']);
    }

    /** @test */
    public function it_handles_taxed_pos_bundle_and_split_owner_placeholder_without_phantom_rows(): void
    {
        $laptop = $this->makeProduct(['category_id' => $this->laptopRoot->id, 'product_name' => 'Laptop Taxed']);
        $mouse = $this->makeProduct(['category_id' => $this->atkChild->id, 'product_name' => 'Mouse Taxed']);

        // Tax-included POS sale: parent 110,000 (tax 10,000 -> excl 100,000); component 22,000 (tax 2,000 -> excl 20,000)
        // Parent residual excl = 80,000; Component excl = 20,000; Total = 100,000
        $sale1 = $this->makeSale(['setting_id' => $this->setting1->id, 'is_tax_included' => true, 'total_amount' => 110000]);
        $this->linkPosSale($sale1);

        $parent1 = SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $laptop->id,
            'product_name' => $laptop->product_name,
            'product_code' => $laptop->product_code,
            'quantity' => 1,
            'price' => 110000,
            'unit_price' => 110000,
            'sub_total' => 110000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 10000,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale1->id,
            'sale_detail_id' => $parent1->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $mouse->id,
            'name' => $mouse->product_name,
            'quantity' => 1,
            'price' => 22000,
            'sub_total' => 22000,
            'tax_amount' => 2000,
        ]);

        // Split-owner placeholder parent in setting2 with missing cost_snapshot_source:
        // qty 0, sub_total 25,000 matching component 25,000 -> residual 0 (must still be skipped without HPP metadata)
        $sale2 = $this->makeSale(['setting_id' => $this->setting2->id, 'is_tax_included' => false, 'total_amount' => 25000]);
        $this->linkPosSale($sale2);

        $placeholderParent = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $laptop->id,
            'product_name' => $laptop->product_name,
            'product_code' => $laptop->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 25000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_snapshot_source' => null,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $placeholderParent->id,
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'product_id' => $mouse->id,
            'name' => $mouse->product_name,
            'quantity' => 1,
            'price' => 25000,
            'sub_total' => 25000,
            'tax_amount' => 0,
        ]);

        // Ordinary non-bundle zero-quantity detail in setting2 must still be preserved (not treated as bundle placeholder)
        $zeroQtyOrdinary = $this->makeProduct(['category_id' => $this->atkRoot->id, 'product_name' => 'Ordinary Zero Qty']);
        SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $zeroQtyOrdinary->id,
            'product_name' => $zeroQtyOrdinary->product_name,
            'product_code' => $zeroQtyOrdinary->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_snapshot_source' => null,
        ]);

        $service = new SaleByProductReportQueryService();

        // Setting2 only: placeholder parent is skipped even with null cost_snapshot_source; Mouse + ordinary zero-qty remain
        $s2Dataset = $service->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting2->id],
        ));
        $this->assertCount(2, $s2Dataset['rows']);
        $this->assertNull($s2Dataset['rows']->firstWhere('product_id', $laptop->id));
        $s2Mouse = $s2Dataset['rows']->firstWhere('product_id', $mouse->id);
        $this->assertEquals(1.0, $s2Mouse->sold_quantity);
        $this->assertEquals(25000.0, $s2Mouse->sold_value);
        $s2Ordinary = $s2Dataset['rows']->firstWhere('product_id', $zeroQtyOrdinary->id);
        $this->assertNotNull($s2Ordinary);
        $this->assertEquals(0.0, $s2Ordinary->sold_quantity);
        $this->assertEquals(0.0, $s2Ordinary->sold_value);
        $this->assertEquals(0.0, $s2Ordinary->average_sales_value);

        // Both settings: Laptop has 1 qty / 80,000; Mouse has 2 qty / 45,000 (20,000 + 25,000); Ordinary Zero Qty has 0 / 0
        $bothDataset = $service->getDataset(new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id, $this->setting2->id],
        ));
        $this->assertCount(3, $bothDataset['rows']);
        $this->assertEquals(80000.0, $bothDataset['rows']->firstWhere('product_id', $laptop->id)->sold_value);
        $this->assertEquals(2.0, $bothDataset['rows']->firstWhere('product_id', $mouse->id)->sold_quantity);
        $this->assertEquals(45000.0, $bothDataset['rows']->firstWhere('product_id', $mouse->id)->sold_value);
        $this->assertEquals(125000.0, $bothDataset['totals']['sold_value']);
    }

    /** @test */
    public function it_preserves_numeric_zero_for_non_pos_components_and_ignores_missing_or_corrupted_cost_snapshots(): void
    {
        $parent = $this->makeProduct(['product_name' => 'Paket Non POS']);
        $includedComp = $this->makeProduct(['product_name' => 'Komponen Included']);
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
            'cost_unit_snapshot' => null,
            'cost_total_snapshot' => null,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE,
            'cost_snapshot_at' => null,
        ]);

        // Non-POS included component with informational_item_price (not billable -> numeric 0.0)
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
            'cost_unit_snapshot' => -999,
            'cost_snapshot_source' => 'BACKFILL_ZERO_FALLBACK',
        ]);

        // Standalone commercial bundle item
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => null,
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'product_id' => $standaloneComp->id,
            'name' => $standaloneComp->product_name,
            'quantity' => 1,
            'price' => 40000,
            'sub_total' => 40000,
            'tax_amount' => 0,
        ]);

        $filter = new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
        );

        $dataset = (new SaleByProductReportQueryService())->getDataset($filter);

        $incRow = $dataset['rows']->firstWhere('product_id', $includedComp->id);
        $this->assertSame(0.0, $incRow->sold_value);
        $this->assertSame(0.0, $incRow->average_sales_value);

        $stdRow = $dataset['rows']->firstWhere('product_id', $standaloneComp->id);
        $this->assertSame(40000.0, $stdRow->sold_value);

        $parentRow = $dataset['rows']->firstWhere('product_id', $parent->id);
        $this->assertSame(100000.0, $parentRow->sold_value);
        $this->assertSame(140000.0, $dataset['totals']['sold_value']);

        // Verify stored cost snapshot was not mutated
        $this->assertEquals(
            SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE,
            SaleDetails::find($detail->id)->cost_snapshot_source
        );

        // Verify serialized CSV preserves numeric 0.00 and has exactly 6 columns
        $csvRaw = ltrim(
            Excel::raw(new SaleByProductReportExport($dataset, $filter, true), \Maatwebsite\Excel\Excel::CSV),
            "\xEF\xBB\xBF"
        );
        $csvLines = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csvRaw)))));
        $csvHeader = $csvLines[0];
        $this->assertSame([
            'Kode Produk',
            'Nama Produk',
            'Kuantitas Terjual',
            'Satuan',
            'Total Nilai terjual',
            'Harga Penjualan Rata-rata',
        ], $csvHeader);

        $csvZeroRow = collect($csvLines)->first(fn ($r) => ($r[1] ?? '') === $includedComp->product_name);
        $this->assertCount(6, $csvZeroRow);
        $this->assertSame('0.00', $csvZeroRow[4]);
        $this->assertSame('0.00', $csvZeroRow[5]);

        // Verify serialized XLSX has exactly 6 columns (A:F), metadata merges A1:F4, and single sheet
        $xlsxRaw = Excel::raw(new SaleByProductReportExport($dataset, $filter, false), \Maatwebsite\Excel\Excel::XLSX);
        $tmpFile = tempnam(sys_get_temp_dir(), 'sbp_xlsx_');
        file_put_contents($tmpFile, $xlsxRaw);
        try {
            $spreadsheet = IOFactory::load($tmpFile);
            $this->assertSame(1, $spreadsheet->getSheetCount());
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame('F', $sheet->getHighestColumn());
            $merges = $sheet->getMergeCells();
            $this->assertArrayHasKey('A1:F1', $merges);
            $this->assertArrayHasKey('A2:F2', $merges);
            $this->assertArrayHasKey('A3:F3', $merges);
            $this->assertArrayHasKey('A4:F4', $merges);
            $this->assertSame('Kode Produk', $sheet->getCell('A6')->getValue());
            $this->assertSame('Harga Penjualan Rata-rata', $sheet->getCell('F6')->getValue());
        } finally {
            @unlink($tmpFile);
        }
    }

    /** @test */
    public function it_renders_exact_six_columns_on_screen_without_role_modal_or_status_labels(): void
    {
        $product = $this->makeProduct(['product_name' => 'Produk Six Col', 'product_code' => 'SC-01']);
        $sale = $this->makeSale();
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(SaleByProductReport::class)
            ->set('startDate', '2026-11-01')
            ->set('endDate', '2026-11-30')
            ->call('applyFilters')
            ->assertSeeInOrder([
                'Kode Produk',
                'Nama Produk',
                'Kuantitas Terjual',
                'Satuan',
                'Total Nilai terjual',
                'Harga Penjualan Rata-rata',
            ])
            ->assertSee('Produk Six Col')
            ->assertDontSee('Dijual sebagai')
            ->assertDontSee('Total Modal')
            ->assertDontSee('Modal Rata-rata/unit')
            ->assertDontSee('Included in bundle')
            ->assertDontSee('Modal belum lengkap');
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
    public function it_handles_over_500_source_rows_across_detail_and_component_chunk_boundaries(): void
    {
        $repeatedProduct = $this->makeProduct(['product_name' => 'Repeated Chunk Product', 'product_code' => 'RCP-01']);
        $sale = $this->makeSale(['total_amount' => 1000000]);
        $this->linkPosSale($sale);

        $detailRows = [];
        $now = now()->toDateTimeString();
        for ($i = 0; $i < 520; $i++) {
            $detailRows[] = [
                'sale_id' => $sale->id,
                'product_id' => $repeatedProduct->id,
                'product_name' => $repeatedProduct->product_name,
                'product_code' => $repeatedProduct->product_code,
                'quantity' => 1,
                'price' => 1000,
                'unit_price' => 1000,
                'sub_total' => 1000,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($detailRows, 200) as $chunk) {
            DB::table('sale_details')->insert($chunk);
        }

        $bundleRows = [];
        for ($i = 0; $i < 510; $i++) {
            $bundleRows[] = [
                'sale_id' => $sale->id,
                'sale_detail_id' => null,
                'bundle_id' => 1,
                'bundle_item_id' => 1,
                'product_id' => $repeatedProduct->id,
                'name' => $repeatedProduct->product_name,
                'quantity' => 1,
                'price' => 500,
                'sub_total' => 500,
                'tax_amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($bundleRows, 200) as $chunk) {
            DB::table('sale_bundle_items')->insert($chunk);
        }

        $filter = new SaleByProductReportFilterData(
            startDate: '2026-11-01',
            endDate: '2026-11-30',
            scopeSettingIds: [$this->setting1->id],
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $dataset = (new SaleByProductReportQueryService())->getDataset($filter);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // All 520 details + 510 bundle items merge into 1 row: qty 1030, value 520*1000 + 510*500 = 775,000
        $this->assertCount(1, $dataset['rows']);
        $row = $dataset['rows']->first();
        $this->assertEquals(1030.0, $row->sold_quantity);
        $this->assertEquals(775000.0, $row->sold_value);
        $this->assertEqualsWithDelta(775000.0 / 1030.0, $row->average_sales_value, 0.01);
        $this->assertEquals(775000.0, $dataset['totals']['sold_value']);

        // Bounded SQL queries (1 aggregated query + 2 one-time schema checks)
        $this->assertLessThanOrEqual(5, $queryCount);
    }

    /** @test */
    public function it_does_not_rerun_report_queries_during_draft_filter_searches(): void
    {
        $product = $this->makeProduct(['product_name' => 'Draft Search Product', 'product_code' => 'DSP-01']);
        $sale = $this->makeSale();
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $component = Livewire::test(SaleByProductReport::class)
            ->set('startDate', '2026-11-01')
            ->set('endDate', '2026-11-30')
            ->call('applyFilters')
            ->assertSee('Draft Search Product');

        // Typing in customerSearch and productSearch must NOT query sale_details or sale_bundle_items
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component
            ->set('customerSearch', 'Customer')
            ->set('productSearch', 'Draft')
            ->assertSee('Draft Search Product');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $reportQueries = $queries->filter(
            fn (string $sql) => str_contains($sql, 'sale_details') || str_contains($sql, 'sale_bundle_items')
        );
        $this->assertCount(0, $reportQueries, 'Draft filter searches should not rerun report aggregation queries.');
    }

    /** @test */
    public function it_calls_paginate_exactly_once_per_apply_including_from_another_page(): void
    {
        $sale = $this->makeSale();
        for ($i = 1; $i <= 20; $i++) {
            $p = $this->makeProduct(['product_name' => sprintf('Paginated Product %02d', $i)]);
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
            ]);
        }

        $paginateCalls = 0;
        $countingService = new class($paginateCalls) extends SaleByProductReportQueryService {
            public int $calls;
            public function __construct(int &$calls)
            {
                parent::__construct();
                $this->calls = &$calls;
            }
            public function paginate(
                SaleByProductReportFilterData $filter,
                int $perPage = 15,
                ?int $page = null,
                ?array $precomputedDataset = null
            ): array {
                $this->calls++;
                return parent::paginate($filter, $perPage, $page, $precomputedDataset);
            }
        };
        $this->app->instance(SaleByProductReportQueryService::class, $countingService);

        $component = Livewire::test(SaleByProductReport::class)
            ->set('startDate', '2026-11-01')
            ->set('endDate', '2026-11-30')
            ->call('applyFilters');

        $this->assertSame(1, $paginateCalls, 'Initial Apply should call paginate() exactly once.');
        $this->assertSame(1, $component->get('loadedPage'));

        // Move to page 2 -> exactly 1 more paginate() call
        $paginateCalls = 0;
        $component->call('setPage', 2);
        $this->assertSame(1, $paginateCalls, 'Switching to page 2 should call paginate() exactly once.');
        $this->assertSame(2, $component->get('loadedPage'));

        // Re-apply from page 2 -> must reset to page 1 and call paginate() exactly ONCE (not twice!)
        $paginateCalls = 0;
        $component->call('applyFilters');
        $this->assertSame(1, $paginateCalls, 'Re-applying from page 2 must call paginate() exactly once.');
        $this->assertSame(1, $component->get('loadedPage'));
    }
}
