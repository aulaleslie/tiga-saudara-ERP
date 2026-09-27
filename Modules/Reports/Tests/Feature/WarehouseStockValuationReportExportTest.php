<?php

namespace Modules\Reports\Tests\Feature;

use App\Livewire\Reports\WarehouseStockValuationReport;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseStockValuationReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $currency;
    protected $location1;
    protected $location2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address'
        ]);

        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'inventoryValuationReports.access', 'guard_name' => 'web']);
        $this->user->givePermissionTo('inventoryValuationReports.access');

        $this->location1 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'WAREHOUSE A'
        ]);

        $this->location2 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'WAREHOUSE B'
        ]);
    }

    private function makeCategory(string $name = 'General', ?int $settingId = null): Category
    {
        return Category::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'category_code' => 'CAT-' . strtoupper(uniqid()),
            'category_name' => $name, 'created_by' => $this->user->id,
        ]);
    }

    private function makeProduct(Category $category, string $code, string $name, bool $stockManaged = true, float $averagePrice = 0, float $minQty = 0, ?int $settingId = null): Product
    {
        $settingId = $settingId ?? $this->setting->id;

        $product = Product::create([
            'setting_id' => $settingId,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'stock_managed' => $stockManaged,
            'average_purchase_price' => $averagePrice,
            'product_stock_alert' => $minQty, 'product_cost' => 0, 'product_price' => 0, 'product_cost' => 0, 'product_price' => 0
        ]);


        if ($averagePrice > 0) {
            ProductPrice::create([
                'setting_id' => $settingId,
                'product_id' => $product->id,
                'average_purchase_price' => $averagePrice,
                'last_purchase_price' => $averagePrice,
                'sale_price' => $averagePrice * 1.5,
            ]);
        }

        return $product;
    }

                private function makeTransaction(Product $product, Location $location, string $type, float $qty, string $date, string $reason = ''): Transaction
    {
        $trx = Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $product->setting_id,
            'location_id' => $location->id,
            'user_id' => $this->user->id ?? 1,
            'type' => $type,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'previous_quantity' => 0,
            'after_quantity' => $qty,
            'current_quantity' => $qty,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty,
            'current_quantity_at_location' => $qty,
            'reason' => $reason,
        ]);
        $trx->created_at = \Carbon\Carbon::parse($date);
        $trx->updated_at = \Carbon\Carbon::parse($date);
        $trx->save(['timestamps' => false]);
        return $trx;
    }

    /** @test */
    public function it_can_export_report_to_csv_with_flat_rows()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'P1', 'PRODUCT A', true, 15000);
        $this->makeTransaction($product, $this->location1, 'init', 10, now()->format('Y-m-d H:i:s'));

        \Maatwebsite\Excel\Facades\Excel::fake();

        Livewire::test(WarehouseStockValuationReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportCsv');

        $dateFormatted = now()->format('d-m-Y');
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded("nilai-stok-gudang_{$dateFormatted}.csv", function (\App\Exports\WarehouseStockValuationReportExport $export) {
            $headings = $export->headings();
            $this->assertEquals([
                'Gudang',
                'Kode Produk',
                'Nama Produk',
                'Qty',
                'Min. Qty',
                'Satuan Produk',
                'Harga Rata-rata',
                'Nilai Persediaan',
            ], $headings);

            $data = $export->collection()->toArray();
            $this->assertCount(2, $data); // Warehouse A and Warehouse B rows
            
            $firstMapped = $export->map($data[0]);
            $this->assertEquals(['WAREHOUSE A', 'P1', 'PRODUCT A', 10.0, 0.0, 'PCS', 15000.0, 150000.0], $firstMapped);
            
            $secondMapped = $export->map($data[1]);
            $this->assertEquals(['WAREHOUSE B', 'P1', 'PRODUCT A', 0.0, 0.0, 'PCS', 15000.0, 0.0], $secondMapped);

            return true;
        });
    }

    /** @test */
    public function it_can_export_report_to_xlsx_with_grouping_and_total_rows()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'P1', 'PRODUCT A', true, 15000);
        $this->makeTransaction($product, $this->location1, 'init', 10, now()->format('Y-m-d H:i:s'));

        \Maatwebsite\Excel\Facades\Excel::fake();

        Livewire::test(WarehouseStockValuationReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel');

        $dateFormatted = now()->format('d-m-Y');
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded("nilai-stok-gudang_{$dateFormatted}.xlsx", function (\App\Exports\WarehouseStockValuationReportExport $export) {
            $headings = $export->headings();
            $this->assertEquals([
                'Gudang / Kode Produk',
                'Nama Produk',
                'Qty',
                'Min. Qty',
                'Satuan Produk',
                'Harga Rata-rata',
                'Nilai Persediaan',
            ], $headings);

            $data = $export->collection()->toArray();
            $this->assertCount(5, $data); // 2 warehouses * (1 group row + 1 product row) + 1 total row
            
            $this->assertEquals('group', $data[0]['type']);
            $this->assertEquals('WAREHOUSE A', $data[0]['kode_produk']);

            $this->assertEquals('product', $data[1]['type']);
            $this->assertEquals('P1', $data[1]['kode_produk']);
            $this->assertEquals(150000.0, $data[1]['nilai_persediaan']);

            $this->assertEquals('group', $data[2]['type']);
            $this->assertEquals('WAREHOUSE B', $data[2]['kode_produk']);

            $this->assertEquals('product', $data[3]['type']);
            $this->assertEquals('P1', $data[3]['kode_produk']);
            $this->assertEquals(0.0, $data[3]['nilai_persediaan']);

            $this->assertEquals('total', $data[4]['type']);
            $this->assertEquals('Total Nilai Persediaan Seluruh Produk', $data[4]['kode_produk']);
            $this->assertEquals(150000.0, $data[4]['nilai_persediaan']);

            return true;
        });
    }
}
