<?php

namespace Tests\Feature\Reports;

use App\Exports\CrossBusinessStockInventoryExport;
use App\Livewire\Reports\CrossBusinessStockInventory;
use App\Models\User;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrossBusinessStockPriceExportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingMultiLoc;
    private Setting $settingNoLoc;
    private Location $loc1;
    private Location $loc2;
    private User $priceUser;
    private User $stockOnlyUser;
    private Unit $pcsUnit;
    private Category $category;
    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'inventory.view_remaining_stock', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'products.view_prices', 'guard_name' => 'web']);
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->settingMultiLoc = Setting::create([
            'company_name' => 'Alpha MultiLoc',
            'company_email' => 'alpha@example.com',
            'company_phone' => '08111',
            'notification_email' => 'alpha@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'company_address' => 'Alpha Address',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);

        $this->settingNoLoc = Setting::create([
            'company_name' => 'Beta NoLoc',
            'company_email' => 'beta@example.com',
            'company_phone' => '08222',
            'notification_email' => 'beta@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'company_address' => 'Beta Address',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);

        $this->loc1 = Location::create(['setting_id' => $this->settingMultiLoc->id, 'name' => 'Gudang 1', 'is_active' => true]);
        $this->loc2 = Location::create(['setting_id' => $this->settingMultiLoc->id, 'name' => 'Gudang 2', 'is_active' => true]);

        $this->priceUser = User::factory()->create(['is_active' => 1]);
        $this->priceUser->assignRole($superAdminRole);
        $this->priceUser->givePermissionTo(['inventory.view_remaining_stock', 'products.view_prices']);
        $this->actingAs($this->priceUser);

        $this->pcsUnit = Unit::create(['name' => 'Pieces', 'short_name' => 'Pcs', 'is_active' => true, 'setting_id' => $this->settingMultiLoc->id]);
        $this->category = Category::create(['category_code' => 'CAT1', 'category_name' => 'Elektronik', 'created_by' => $this->priceUser->id]);
        $this->brand = Brand::create(['name' => 'Acme', 'is_active' => true, 'created_by' => $this->priceUser->id]);

        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $this->stockOnlyUser = User::factory()->create(['is_active' => 1]);
        $this->stockOnlyUser->givePermissionTo('inventory.view_remaining_stock');
        $this->stockOnlyUser->settings()->attach($this->settingMultiLoc->id, ['role_id' => $staffRole->id]);
        $this->stockOnlyUser->settings()->attach($this->settingNoLoc->id, ['role_id' => $staffRole->id]);
    }

    public function test_export_permissions_validation_and_hidden_screen_prices_independence(): void
    {
        Excel::fake();
        \Carbon\Carbon::setTestNow(now());

        try {
            $this->actingAs($this->stockOnlyUser);
            $stockComp = Livewire::test(CrossBusinessStockInventory::class);

            // Stock-only export is permitted without products.view_prices
            $stockComp->call('exportExcel', 'stock');
            Excel::assertDownloaded('stok-persediaan-lintas-bisnis_' . now()->format('Y-m-d_His') . '.xlsx');

            // Price-bearing exports rejected for stock-only user
            Livewire::test(CrossBusinessStockInventory::class)
                ->call('exportExcel', 'prices')
                ->assertForbidden();

            Livewire::test(CrossBusinessStockInventory::class)
                ->call('exportExcel', 'stock_and_prices')
                ->assertForbidden();

            // Authorized user with showPrices=false can still export Harga saja
            $this->actingAs($this->priceUser);
            $priceComp = Livewire::test(CrossBusinessStockInventory::class)
                ->assertSet('showPrices', false);

            $priceComp->call('exportExcel', 'prices');
            Excel::assertDownloaded('harga-produk-lintas-bisnis_' . now()->format('Y-m-d_His') . '.xlsx');

            // Invalid export mode fails validation
            $priceComp->call('exportExcel', 'invalid_mode')
                ->assertHasErrors('exportContent');
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_serialized_xlsx_structure_and_strict_null_vs_zero_for_all_three_modes(): void
    {
        $p1 = Product::create([
            'setting_id' => $this->settingMultiLoc->id,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_name' => 'Server Rack',
            'product_code' => 'SRV-01',
            'product_unit' => 'Pcs',
            'product_quantity' => 5,
            'product_price' => 999,
            'product_cost' => 888,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $p1->id,
            'location_id' => $this->loc1->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 1,
            'broken_quantity_tax' => 1,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $p1->id,
            'setting_id' => $this->settingMultiLoc->id,
            'average_purchase_price' => 0.00,
            'sale_price' => 2500000.00,
            'tier_1_price' => 2400000.50,
            'tier_2_price' => null,
        ]);
        // settingNoLoc intentionally has NO ProductPrice row

        $service = new CrossBusinessStockInventoryQueryService();
        $filter = new CrossBusinessStockInventoryFilterData(
            businessIds: [$this->settingMultiLoc->id, $this->settingNoLoc->id]
        );
        $businesses = $service->getBusinessHierarchy($filter->businessIds);

        // 1. Harga saja (prices)
        $pricesRaw = Excel::raw(
            new CrossBusinessStockInventoryExport(
                $service->getRowGeneratorForExport($filter, 10, CrossBusinessStockInventoryExport::CONTENT_PRICES),
                $businesses,
                $filter,
                'decimal',
                CrossBusinessStockInventoryExport::CONTENT_PRICES
            ),
            \Maatwebsite\Excel\Excel::XLSX
        );

        $tmpPrices = tempnam(sys_get_temp_dir(), 'cbsi_prices_') . '.xlsx';
        file_put_contents($tmpPrices, $pricesRaw);
        $pricesSheet = IOFactory::load($tmpPrices)->getActiveSheet();
        @unlink($tmpPrices);

        // 4 fixed cols (Produk, Kategori, Merek, Satuan) + 4 prices * 2 businesses = 12 cols (A..L)
        $this->assertSame('L', $pricesSheet->getHighestColumn());
        $this->assertSame('Satuan', $pricesSheet->getCell('D2')->getValue());
        $this->assertSame('PCS', $pricesSheet->getCell('D5')->getValue());
        $this->assertSame('ALPHA MULTILOC', $pricesSheet->getCell('E2')->getValue());
        $this->assertSame('BETA NOLOC', $pricesSheet->getCell('I2')->getValue());
        $this->assertSame('Harga Modal', $pricesSheet->getCell('E4')->getValue());
        $this->assertSame('Harga Jual', $pricesSheet->getCell('F4')->getValue());
        $this->assertSame('Tier 1', $pricesSheet->getCell('G4')->getValue());
        $this->assertSame('Tier 2', $pricesSheet->getCell('H4')->getValue());

        // Strict zero vs null vs 2500000.00
        $this->assertSame(0, (int) $pricesSheet->getCell('E5')->getValue());
        $this->assertNotNull($pricesSheet->getCell('E5')->getValue());
        $this->assertEquals(2500000.0, $pricesSheet->getCell('F5')->getValue());
        $this->assertEquals(2400000.5, $pricesSheet->getCell('G5')->getValue());
        $this->assertNull($pricesSheet->getCell('H5')->getValue(), 'Null tier_2 must export as blank cell.');
        $this->assertNull($pricesSheet->getCell('I5')->getValue(), 'Missing business price row must export as blank cell.');

        // 2. Stok + Harga (stock_and_prices)
        $combinedRaw = Excel::raw(
            new CrossBusinessStockInventoryExport(
                $service->getRowGeneratorForExport($filter, 10, CrossBusinessStockInventoryExport::CONTENT_STOCK_AND_PRICES),
                $businesses,
                $filter,
                'decimal',
                CrossBusinessStockInventoryExport::CONTENT_STOCK_AND_PRICES
            ),
            \Maatwebsite\Excel\Excel::XLSX
        );

        $tmpCombined = tempnam(sys_get_temp_dir(), 'cbsi_comb_') . '.xlsx';
        file_put_contents($tmpCombined, $combinedRaw);
        $combSheet = IOFactory::load($tmpCombined)->getActiveSheet();
        @unlink($tmpCombined);

        // 7 fixed cols (Produk, Kategori, Merek, Satuan, Total Bagus, Total Rusak, Total Dalam Pengiriman)
        // + Alpha MultiLoc: 2 locs * 2 + 1 in-delivery + 4 prices = 9 cols (H..P)
        // + Beta NoLoc: 1 placeholder * 2 + 1 in-delivery + 4 prices = 7 cols (Q..W)
        // Total = 23 cols (W)
        $this->assertSame('W', $combSheet->getHighestColumn());
        $this->assertSame('Satuan', $combSheet->getCell('D2')->getValue());
        $this->assertSame('ALPHA MULTILOC', $combSheet->getCell('H2')->getValue());
        $this->assertSame('GUDANG 1', $combSheet->getCell('H3')->getValue());
        $this->assertSame('GUDANG 2', $combSheet->getCell('J3')->getValue());
        $this->assertSame('Dalam Pengiriman', $combSheet->getCell('L3')->getValue());
        $this->assertSame('Harga Modal', $combSheet->getCell('M4')->getValue());
        $this->assertEquals(2500000.0, $combSheet->getCell('N5')->getValue());
        $this->assertSame('BETA NOLOC', $combSheet->getCell('Q2')->getValue());
        $this->assertSame('—', $combSheet->getCell('Q3')->getValue());
    }

    public function test_price_only_export_respects_availability_filter_and_skips_stock_materialization_across_batches(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = Product::create([
                'setting_id' => $this->settingMultiLoc->id,
                'category_id' => $this->category->id,
                'brand_id' => $this->brand->id,
                'unit_id' => $this->pcsUnit->id,
                'base_unit_id' => $this->pcsUnit->id,
                'product_name' => "Batch Item {$i}",
                'product_code' => "BCH-{$i}",
                'product_unit' => 'Pcs',
                'product_quantity' => $i <= 3 ? 5 : 0,
                'product_price' => 1000,
                'product_cost' => 500,
                'stock_managed' => true,
                'is_active' => true,
            ]);

            if ($i <= 3) {
                ProductStock::create([
                    'product_id' => $p->id,
                    'location_id' => $this->loc1->id,
                    'quantity' => 5,
                    'quantity_tax' => 5,
                    'quantity_non_tax' => 0,
                    'broken_quantity' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                ]);
            }

            ProductPrice::create([
                'product_id' => $p->id,
                'setting_id' => $this->settingMultiLoc->id,
                'average_purchase_price' => 1000 * $i,
                'sale_price' => 2000 * $i,
                'tier_1_price' => null,
                'tier_2_price' => null,
            ]);
        }

        $service = new CrossBusinessStockInventoryQueryService();
        $filter = new CrossBusinessStockInventoryFilterData(
            businessIds: [$this->settingMultiLoc->id],
            availability: 'available'
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        // chunkSize = 2 forces 2 batches for 3 available products
        $rows = iterator_to_array($service->getRowGeneratorForExport($filter, 2, CrossBusinessStockInventoryExport::CONTENT_PRICES));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $rows, 'Only the 3 available products should be exported.');

        // Must NOT run purchase_details or grouped product_stocks matrix queries
        $purchaseQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'purchase_details'))->count();
        $stockGroupByQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'product_stocks') && str_contains($q['query'], 'group by'))->count();
        $this->assertSame(0, $purchaseQueries);
        $this->assertSame(0, $stockGroupByQueries);
    }
}
