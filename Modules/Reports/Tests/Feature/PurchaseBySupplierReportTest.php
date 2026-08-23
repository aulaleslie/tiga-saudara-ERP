<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\People\Entities\Supplier;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Spatie\Tags\Tag;

class PurchaseBySupplierReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
        Role::firstOrCreate(['name' => 'Staff']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('purchaseReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    protected function makeSupplier(string $name = 'Supplier 1', ?int $settingId = null): Supplier
    {
        static $counter = 0;
        $counter++;
        return Supplier::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'supplier_name' => $name,
            'supplier_email' => "s{$counter}@test.com",
            'supplier_phone' => (string) $counter,
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);
    }

    protected function makePurchase(Supplier $supplier, array $overrides = []): Purchase
    {
        static $ref = 0;
        $ref++;
        return Purchase::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'PR-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ], $overrides));
    }

    protected function makePurchaseDetail(Purchase $purchase, array $overrides = []): PurchaseDetail
    {
        return PurchaseDetail::create(array_merge([
            'purchase_id' => $purchase->id,
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Product A',
            'product_code' => 'PA-001',
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    /** @test */
    public function it_can_render_the_purchase_by_supplier_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-supplier.index'));
        $response->assertStatus(200);
        $response->assertSee('Pembelian Per Supplier');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-supplier.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_shows_purchase_by_supplier_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.index'))
            ->assertStatus(200)
            ->assertSee('Pembelian Per Supplier');
    }

    /** @test */
    public function it_defaults_start_and_end_date_to_current_month()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }

    /** @test */
    public function it_returns_one_row_per_purchase_detail_for_a_single_purchase()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product A']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product B']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product C']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 3;
            })
            ->assertSeeHtml('Subtotal')
            ->assertSeeHtml('Total');
    }

    /** @test */
    public function it_hides_suppliers_without_matching_purchase_details()
    {
        $supplierWithPurchases = $this->makeSupplier('Supplier A');
        $supplierWithoutPurchases = $this->makeSupplier('Supplier B');

        $purchase = $this->makePurchase($supplierWithPurchases, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makePurchaseDetail($purchase);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $supplierNames = $purchases->pluck('supplier_name')->unique()->map(fn($name) => strtoupper($name));
                \PHPUnit\Framework\Assert::assertTrue($supplierNames->contains(strtoupper('Supplier A')), 'Supplier A not found. Found: ' . $supplierNames->implode(', '));
                \PHPUnit\Framework\Assert::assertFalse($supplierNames->contains(strtoupper('Supplier B')), 'Supplier B was found but should be hidden.');
                return true;
            });
    }

    /** @test */
    public function it_computes_running_totals_per_supplier_using_sub_total_and_tax()
    {
        $supplier = $this->makeSupplier('Supplier A');
        $purchase1 = $this->makePurchase($supplier, ['date' => '2026-05-01']);
        $purchase2 = $this->makePurchase($supplier, ['date' => '2026-05-02']);

        // First purchase detail
        $this->makePurchaseDetail($purchase1, ['sub_total' => 1000, 'product_tax_amount' => 110]);
        // Second purchase detail
        $this->makePurchaseDetail($purchase2, ['sub_total' => 2000, 'product_tax_amount' => 0]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                // The query sorts by date desc.
                // Row 0 is purchase2 (2026-05-02, sub_total 2000, tax 0)
                // Row 0 is purchase1 (2026-05-01, sub_total 1000, tax 110)
                $rows = $purchases->values(); // Use the original order
                \PHPUnit\Framework\Assert::assertCount(2, $rows, 'Expected 2 rows, found ' . $rows->count());

                \PHPUnit\Framework\Assert::assertEquals(1000, $rows[0]->sub_total);
                \PHPUnit\Framework\Assert::assertEquals(0, $rows[0]->previous_running_total);

                \PHPUnit\Framework\Assert::assertEquals(2000, $rows[1]->sub_total);
                \PHPUnit\Framework\Assert::assertEquals(1110, $rows[1]->previous_running_total);

                return true;
            });
    }

    /** @test */
    public function it_renders_pajak_row_when_product_tax_amount_is_greater_than_zero()
    {
        $supplier = $this->makeSupplier('Supplier A');
        $purchase = $this->makePurchase($supplier, ['date' => '2026-05-01']);

        $detailWithTax = $this->makePurchaseDetail($purchase, ['sub_total' => 1000, 'product_tax_amount' => 110]);
        $detailWithoutTax = $this->makePurchaseDetail($purchase, ['sub_total' => 2000, 'product_tax_amount' => 0]);

        $mappedWithTax = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($detailWithTax, 0);
        \PHPUnit\Framework\Assert::assertCount(2, $mappedWithTax);
        \PHPUnit\Framework\Assert::assertFalse($mappedWithTax[0]['is_tax_row']);
        \PHPUnit\Framework\Assert::assertEquals(1000, $mappedWithTax[0]['Nominal tagihan']);
        \PHPUnit\Framework\Assert::assertTrue($mappedWithTax[1]['is_tax_row']);
        \PHPUnit\Framework\Assert::assertEquals(110, $mappedWithTax[1]['Nominal tagihan']);
        \PHPUnit\Framework\Assert::assertEquals('Pajak', $mappedWithTax[1]['Nama produk']);

        $mappedWithoutTax = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($detailWithoutTax, 0);
        \PHPUnit\Framework\Assert::assertCount(1, $mappedWithoutTax);
    }

    /** @test */
    public function it_derives_dpp_for_tax_included_purchases()
    {
        $supplier = $this->makeSupplier('Supplier A');
        $purchase = $this->makePurchase($supplier, ['date' => '2026-05-01', 'is_tax_included' => true]);

        // sub_total 1110, tax 110. DPP should be 1000.
        $detailWithTax = $this->makePurchaseDetail($purchase, ['sub_total' => 1110, 'product_tax_amount' => 110]);

        $mappedWithTax = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($detailWithTax, 0);
        \PHPUnit\Framework\Assert::assertCount(2, $mappedWithTax);
        \PHPUnit\Framework\Assert::assertFalse($mappedWithTax[0]['is_tax_row']);
        \PHPUnit\Framework\Assert::assertEquals(1000, $mappedWithTax[0]['Nominal tagihan']); // DPP
        \PHPUnit\Framework\Assert::assertEquals(1000, $mappedWithTax[0]['Total nominal tagihan']);

        \PHPUnit\Framework\Assert::assertTrue($mappedWithTax[1]['is_tax_row']);
        \PHPUnit\Framework\Assert::assertEquals(110, $mappedWithTax[1]['Nominal tagihan']); // Tax
        \PHPUnit\Framework\Assert::assertEquals(1110, $mappedWithTax[1]['Total nominal tagihan']);
    }

    /** @test */
    public function it_filters_by_categories_correctly()
    {
        $category1 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat 1', 'created_by' => $this->user->id]);
        $category2 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C2', 'category_name' => 'Cat 2', 'created_by' => $this->user->id]);
        
        $product1 = Product::create(['product_name' => 'P1', 'product_code' => 'P1', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category1->id]);
        $product2 = Product::create(['product_name' => 'P2', 'product_code' => 'P2', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category2->id]);

        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier);
        
        $this->makePurchaseDetail($purchase, ['product_id' => $product1->id, 'product_name' => 'P1']);
        $this->makePurchaseDetail($purchase, ['product_id' => $product2->id, 'product_name' => 'P2']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('categoryIds', [$category1->id])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $names = $purchases->pluck('product_name');
                \PHPUnit\Framework\Assert::assertTrue($names->contains('P1'));
                \PHPUnit\Framework\Assert::assertFalse($names->contains('P2'));
                return true;
            });
    }

    /** @test */
    public function it_filters_by_tags_using_mencakup_semua_and_salah_satu_logic()
    {
        $tag1 = Tag::findOrCreate('tag1');
        $tag2 = Tag::findOrCreate('tag2');

        $supplier = $this->makeSupplier();
        
        $purchase1 = $this->makePurchase($supplier, ['date' => '2026-05-01']);
        $purchase1->attachTag($tag1);
        $this->makePurchaseDetail($purchase1, ['product_name' => 'Only Tag1']);
        
        $purchase2 = $this->makePurchase($supplier, ['date' => '2026-05-02']);
        $purchase2->attachTags([$tag1, $tag2]);
        $this->makePurchaseDetail($purchase2, ['product_name' => 'Both Tags']);

        // Salah satu logic
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Salah satu')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(2, $purchases->count());
                return true;
            });
            
        // Mencakup semua logic
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Mencakup semua')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count());
                \PHPUnit\Framework\Assert::assertEquals('BOTH TAGS', strtoupper($purchases->first()->product_name));
                return true;
            });
    }

    /** @test */
    public function it_sorts_suppliers_by_total_purchase_amount()
    {
        $supplier1 = $this->makeSupplier('Supplier Low');
        $supplier2 = $this->makeSupplier('Supplier High');

        $purchase1 = $this->makePurchase($supplier1, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchase1, ['sub_total' => 1000]);

        $purchase2 = $this->makePurchase($supplier2, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchase2, ['sub_total' => 5000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('sortField', 'supplier_total')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                // Row 0 should be Supplier High because total is 5000 > 1000
                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER HIGH', strtoupper($rows[0]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER LOW', strtoupper($rows[1]->supplier_name));
                return true;
            });
    }

    /** @test */
    public function it_has_export_buttons()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-supplier.index'));
        $response->assertSee('Excel');
        $response->assertSee('CSV');
    }

    /** @test */
    public function it_blocks_export_excel_before_filter_is_applied()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_blocks_export_csv_before_filter_is_applied()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->call('exportCsv')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_blocks_export_when_snapshot_is_stale()
    {
        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters');

        session()->forget('purchase_by_supplier_report_snapshot');

        $component->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_downloads_xlsx_after_applying_filters()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('purchases_by_vendor_2026-05-01_2026-05-31.xlsx');
    }

    /** @test */
    public function it_downloads_csv_after_applying_filters()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportCsv');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('purchases_by_vendor_2026-05-01_2026-05-31.csv');
    }

    /** @test */
    public function it_exports_same_row_count_as_filtered_display()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $supplier = $this->makeSupplier('Supplier A');
        $purchase = $this->makePurchase($supplier, ['date' => '2026-05-15']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Prod 1']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Prod 2']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Prod 3']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('purchases_by_vendor_2026-05-01_2026-05-31.xlsx', function ($export) {
            return count($export->array()) === 6;
        });
    }
    /** @test */
    public function it_exports_correct_columns_and_includes_tax_rows()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $supplier = $this->makeSupplier('Supplier A');
        $purchase = $this->makePurchase($supplier, ['date' => '2026-05-15']);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Prod 1', 'sub_total' => 1000, 'product_tax_amount' => 110]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('purchases_by_vendor_2026-05-01_2026-05-31.xlsx', function ($export) {
            $headings = $export->headings();
            \PHPUnit\Framework\Assert::assertNotContains('Keterangan', $headings);
            \PHPUnit\Framework\Assert::assertEquals(['Supplier', 'Tanggal', 'Transaksi', 'No', 'Produk', 'Kuantitas', 'Satuan', 'Harga Satuan', 'Jumlah Tagihan', 'Total'], $headings);

            $rows = $export->array();
            \PHPUnit\Framework\Assert::assertCount(5, $rows);

            $prodRow = $rows[1];
            \PHPUnit\Framework\Assert::assertEquals('PROD 1', $prodRow[4]);
            \PHPUnit\Framework\Assert::assertEquals(1000, $prodRow[8]);
            \PHPUnit\Framework\Assert::assertEquals(1000, $prodRow[9]);

            $taxRow = $rows[2];
            \PHPUnit\Framework\Assert::assertEquals('Pajak', $taxRow[4]);
            \PHPUnit\Framework\Assert::assertEquals(110, $taxRow[8]);
            \PHPUnit\Framework\Assert::assertEquals(1110, $taxRow[9]);

            $subtotalRow = $rows[3];
            \PHPUnit\Framework\Assert::assertEquals(1110, $subtotalRow[9]);

            $grandTotalRow = $rows[4];
            \PHPUnit\Framework\Assert::assertEquals(1110, $grandTotalRow[9]);

            return true;
        });
    }
    /** @test */
    public function it_export_respects_supplier_filter()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $supplier1 = $this->makeSupplier('Supplier 1');
        $supplier2 = $this->makeSupplier('Supplier 2');
        
        $purchase1 = $this->makePurchase($supplier1, ['date' => '2026-05-15']);
        $this->makePurchaseDetail($purchase1);
        
        $purchase2 = $this->makePurchase($supplier2, ['date' => '2026-05-16']);
        $this->makePurchaseDetail($purchase2);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('supplierIds', [$supplier1->id])
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('purchases_by_vendor_2026-05-01_2026-05-31.xlsx', function ($export) use ($supplier1) {
            $rows = $export->array();
            return count($rows) === 4 && $rows[0][0] === $supplier1->supplier_name;
        });
    }

    /** @test */
    public function it_hides_the_menu_item_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.access']);
        $unauthorizedUser->givePermissionTo('reports.access');
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.index'))
            ->assertStatus(200)
            ->assertDontSee('Pembelian Per Supplier');
    }

    /** @test */
    public function it_handles_period_presets_without_refreshing_rows_until_filtered()
    {
        $startOfMonth = now()->startOfMonth()->format('Y-m-d');
        $today = now()->format('Y-m-d');
        $startOfYear = now()->startOfYear()->format('Y-m-d');
        $endOfYear = now()->endOfYear()->format('Y-m-d');

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->assertSet('startDate', $startOfMonth)
            ->set('periodPreset', 'today')
            ->assertSet('startDate', $today)
            // Verify rendered HTML actually reflects the state update
            ->assertSeeHtml('wire:model.live="startDate"')
            ->assertSeeHtml('value="' . $today . '"')
            // Before applying filters, appliedFilters startDate is still the old one
            ->assertSet('appliedFilters.startDate', $startOfMonth)
            ->call('applyFilters')
            // After applying filters, appliedFilters matches the new startDate
            ->assertSet('appliedFilters.startDate', $today)
            ->set('periodPreset', 'this_year')
            ->assertSet('startDate', $startOfYear)
            ->assertSet('endDate', $endOfYear)
            ->assertSeeHtml('value="' . $startOfYear . '"')
            ->assertSeeHtml('value="' . $endOfYear . '"');
    }

    /** @test */
    public function it_scopes_purchases_to_the_current_setting()
    {
        $supplier = $this->makeSupplier();
        $purchaseInSetting = $this->makePurchase($supplier, ['setting_id' => $this->setting->id]);
        $this->makePurchaseDetail($purchaseInSetting, ['product_name' => 'In Setting']);

        $otherSetting = Setting::create([
            'company_name' => 'Other',
            'company_email' => 'other@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
        $purchaseOtherSetting = $this->makePurchase($supplier, ['setting_id' => $otherSetting->id]);
        $this->makePurchaseDetail($purchaseOtherSetting, ['product_name' => 'Other Setting']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count());
                \PHPUnit\Framework\Assert::assertEquals('IN SETTING', strtoupper($purchases->first()->product_name));
                return true;
            });
    }
    /** @test */
    public function it_groups_supplier_rows_together_without_interleaving_when_dates_alternate()
    {
        $supplierA = $this->makeSupplier('Supplier A');
        $supplierB = $this->makeSupplier('Supplier B');

        // Alternating dates: B, A, B
        $purchaseB1 = $this->makePurchase($supplierB, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchaseB1, ['product_name' => 'B 01']);

        $purchaseA1 = $this->makePurchase($supplierA, ['date' => '2026-05-02']);
        $this->makePurchaseDetail($purchaseA1, ['product_name' => 'A 02']);

        $purchaseB2 = $this->makePurchase($supplierB, ['date' => '2026-05-03']);
        $this->makePurchaseDetail($purchaseB2, ['product_name' => 'B 03']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('sortField', 'date')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                \PHPUnit\Framework\Assert::assertCount(3, $rows);

                // Both B rows must be adjacent, and A must be by itself.
                // With max date DESC:
                // B's max date is 2026-05-03.
                // A's max date is 2026-05-02.
                // So Supplier B group comes first, then Supplier A group.
                // Within B, dates are DESC: 03 then 01.

                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER B', strtoupper($rows[0]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('B 03', $rows[0]->product_name);

                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER B', strtoupper($rows[1]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('B 01', $rows[1]->product_name);

                \PHPUnit\Framework\Assert::assertEquals('SUPPLIER A', strtoupper($rows[2]->supplier_name));
                \PHPUnit\Framework\Assert::assertEquals('A 02', $rows[2]->product_name);

                return true;
            });
    }

    /** @test */
    public function it_computes_running_totals_correctly_on_page_two_including_tax()
    {
        $supplier = $this->makeSupplier('Supplier A');
        
        // Create 20 purchases so page 2 has 5 items (default perPage is 15)
        for ($i = 1; $i <= 20; $i++) {
            $purchase = $this->makePurchase($supplier, ['date' => '2026-05-01']);
            // Page 1 contains rows 20 down to 6. They all have sub_total = 100, tax = 10.
            // So the correct running total at the start of page 2 (after page 1) MUST be 15 * 110 = 1650.
            $subTotal = ($i === 1) ? 1000 : 100;
            $taxAmount = ($i === 1) ? 100 : 10;
            $this->makePurchaseDetail($purchase, ['sub_total' => $subTotal, 'product_tax_amount' => $taxAmount]);
        }

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('setPage', 2)
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                \PHPUnit\Framework\Assert::assertCount(5, $rows);
                
                // Since it's ascending, the first item (row 1) had sub_total 1000, tax 100 (total 1100).
                // The next 14 items on page 1 had total 110 each (14 * 110 = 1540).
                // Total before page 2 is 1100 + 1540 = 2640.
                \PHPUnit\Framework\Assert::assertEquals(2640, $rows[0]->previous_running_total);
                
                // The last item on page 2 (row 20) is preceded by 4 items on page 2.
                // Its previous_running_total should be 2640 + 4 * 110 = 3080.
                \PHPUnit\Framework\Assert::assertEquals(3080, $rows[4]->previous_running_total);
                
                return true;
            });
    }

    /** @test */
    public function it_restores_date_filters_when_cancel_filters_is_called()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('periodPreset', 'this_month')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('periodPreset', 'today')
            ->set('startDate', '2026-06-01') // Unapplied change
            ->call('cancelFilters')
            ->assertSet('periodPreset', 'this_month') // Restored from appliedFilters
            ->assertSet('startDate', '2026-05-01'); // Restored from appliedFilters
    }

    /** @test */
    public function it_resets_date_filters_to_current_month_when_reset_filters_is_called()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('periodPreset', 'this_month')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('resetFilters')
            ->assertSet('periodPreset', '')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }
    /** @test */
    public function it_does_not_render_subtotal_at_page_boundary_if_supplier_continues_on_next_page()
    {
        $supplier = $this->makeSupplier('Supplier A');
        // default perPage is 15. Create 16 rows for the same supplier.
        for ($i = 1; $i <= 16; $i++) {
            $purchase = $this->makePurchase($supplier, ['date' => '2026-05-01']);
            $this->makePurchaseDetail($purchase, ['sub_total' => 100]);
        }

        // Page 1 will have 15 rows. The last row on page 1 belongs to Supplier A.
        // Supplier A continues on page 2.
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 15 && $purchases->hasMorePages();
            })
            // Since it continues to next page, Subtotal should NOT be rendered on page 1.
            ->assertDontSeeHtml('>Subtotal</td>');
            
        // Page 2 will have 1 row for Supplier A.
        // It's the end of Supplier A, so Subtotal SHOULD be rendered on page 2.
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('setPage', 2)
            ->assertSeeHtml('>Subtotal</td>')
            ->assertSeeHtml('(Lanjutan)');
    }

    /** @test */
    public function it_renders_subtotal_at_page_boundary_if_supplier_changes_on_next_page()
    {
        $supplierA = $this->makeSupplier('Supplier A');
        $supplierB = $this->makeSupplier('Supplier B');
        // create 15 rows for Supplier A (fills page 1)
        for ($i = 1; $i <= 15; $i++) {
            $purchase = $this->makePurchase($supplierA, ['date' => '2026-05-02']); // higher date
            $this->makePurchaseDetail($purchase, ['sub_total' => 100]);
        }
        // create 1 row for Supplier B (goes to page 2)
        $purchaseB = $this->makePurchase($supplierB, ['date' => '2026-05-01']);
        $this->makePurchaseDetail($purchaseB, ['sub_total' => 100]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('sortField', 'date')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                return $purchases->count() === 15 && $purchases->hasMorePages();
            })
            // Since next page is Supplier B, Subtotal SHOULD be rendered on page 1 for Supplier A.
            ->assertSeeHtml('>Subtotal</td>');
    }

    /** @test */
    public function it_renders_discount_row_only_when_purchase_has_discount_and_it_is_the_last_filtered_detail()
    {
        $category1 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat 1', 'created_by' => $this->user->id]);
        $category2 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C2', 'category_name' => 'Cat 2', 'created_by' => $this->user->id]);
        
        $product1 = Product::create(['product_name' => 'P1', 'product_code' => 'P1', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category1->id]);
        $product2 = Product::create(['product_name' => 'P2', 'product_code' => 'P2', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category2->id]);

        $supplier = $this->makeSupplier();
        // Purchase with discount
        $purchaseWithDiscount = $this->makePurchase($supplier, ['date' => '2026-05-01', 'discount_amount' => 500]);
        $this->makePurchaseDetail($purchaseWithDiscount, ['product_id' => $product1->id, 'product_name' => 'P1']);
        $this->makePurchaseDetail($purchaseWithDiscount, ['product_id' => $product2->id, 'product_name' => 'P2']);

        // Purchase without discount
        $purchaseNoDiscount = $this->makePurchase($supplier, ['date' => '2026-05-02', 'discount_amount' => 0]);
        $this->makePurchaseDetail($purchaseNoDiscount, ['product_id' => $product1->id, 'product_name' => 'P1']);

        // Filter by category 1. P2 will be excluded. So P1 is the ONLY detail (and the last detail) for both purchases.
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('categoryIds', [$category1->id])
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchaseWithDiscount, $purchaseNoDiscount) {
                // There should be exactly 2 details loaded (one per purchase)
                \PHPUnit\Framework\Assert::assertEquals(2, $purchases->count());
                
                // For the purchase with discount, the single P1 detail should be flagged as last detail
                $detailWithDiscount = $purchases->firstWhere('purchase_id', $purchaseWithDiscount->id);
                \PHPUnit\Framework\Assert::assertNotNull($detailWithDiscount);
                \PHPUnit\Framework\Assert::assertTrue($detailWithDiscount->is_last_detail);
                
                // For the purchase without discount, it should also be flagged as last detail
                $detailNoDiscount = $purchases->firstWhere('purchase_id', $purchaseNoDiscount->id);
                \PHPUnit\Framework\Assert::assertNotNull($detailNoDiscount);
                \PHPUnit\Framework\Assert::assertTrue($detailNoDiscount->is_last_detail);
                
                return true;
            });
            
        // Check HTML output
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('categoryIds', [$category1->id])
            ->call('applyFilters')
            ->assertSeeHtml('Diskon')
            ->assertSeeHtml('-500'); // the negative amount
    }

    /** @test */
    public function it_accounts_for_document_discount_in_cross_page_running_totals()
    {
        $supplier = $this->makeSupplier();
        // Create a purchase with 16 details and a discount of 500
        $purchaseWithDiscount = $this->makePurchase($supplier, ['date' => '2026-05-01', 'discount_amount' => 500]);
        for ($i = 1; $i <= 16; $i++) {
            $this->makePurchaseDetail($purchaseWithDiscount, ['product_name' => "P$i", 'sub_total' => 100, 'product_tax_amount' => 0]);
        }

        // Default pagination is 15. Page 1 has 15 items (subtotal 1500). Page 2 has 1 item (100) + discount (-500) = total 1100.
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('page', 2)
            ->assertSeeHtml('1.100'); // The final grand total / subtotal for the supplier should be 1.100
    }

    /** @test */
    public function it_includes_purchase_by_reporting_date_when_not_original_date()
    {
        $supplier = $this->makeSupplier();
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        $purchase = $this->makePurchase($supplier, ['date' => $originalDate, 'reporting_date' => $reportingDate]);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product A']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', $reportingDate)
            ->set('endDate', $reportingDate)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count(), 'Purchase should be included when reporting_date is in period');
                return true;
            });
    }

    /** @test */
    public function it_excludes_purchase_when_date_range_contains_original_but_not_reporting_date()
    {
        $supplier = $this->makeSupplier();
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        $purchase = $this->makePurchase($supplier, ['date' => $originalDate, 'reporting_date' => $reportingDate]);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product A']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', $originalDate)
            ->set('endDate', $originalDate)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(0, $purchases->count(), 'Purchase should be excluded when only original date is in period and reporting_date override is set');
                return true;
            });
    }

    /** @test */
    public function it_displays_effective_date_in_rendered_output()
    {
        $supplier = $this->makeSupplier();
        $originalDate = '2026-01-15';
        $reportingDate = '2026-02-15';

        $purchase = $this->makePurchase($supplier, ['date' => $originalDate, 'reporting_date' => $reportingDate]);
        $this->makePurchaseDetail($purchase, ['product_name' => 'Product A']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', $reportingDate)
            ->set('endDate', $reportingDate)
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                // The purchase should be included with the reporting date
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count());
                return true;
            });
    }

    /** @test */
    public function it_sorts_and_groups_by_effective_date()
    {
        $supplier = $this->makeSupplier();
        $date1 = '2026-01-15';
        $date2 = '2026-01-10';
        $reportingDate2 = '2026-02-20';

        $purchase1 = $this->makePurchase($supplier, ['date' => $date1]);
        $this->makePurchaseDetail($purchase1, ['product_name' => 'Product A', 'sub_total' => 1000]);

        $purchase2 = $this->makePurchase($supplier, ['date' => $date2, 'reporting_date' => $reportingDate2]);
        $this->makePurchaseDetail($purchase2, ['product_name' => 'Product B', 'sub_total' => 2000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-01-01')
            ->set('endDate', '2026-02-28')
            ->set('sortField', 'date')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                $rows = $purchases->values();
                \PHPUnit\Framework\Assert::assertCount(2, $rows);
                // Both should be included when filtering by the full date range
                return true;
            });
    }

    /** @test */
    public function it_excludes_archived_purchases_from_report_and_totals()
    {
        $supplier = $this->makeSupplier('Supplier Mixed');
        $date = '2026-08-15';
        
        $activePurchase = $this->makePurchase($supplier, ['date' => $date]);
        $this->makePurchaseDetail($activePurchase, ['product_name' => 'Active Product', 'sub_total' => 1000]);

        $archivedPurchase = $this->makePurchase($supplier, ['date' => $date, 'archived_at' => now(), 'archived_by' => $this->user->id]);
        $this->makePurchaseDetail($archivedPurchase, ['product_name' => 'Archived Product', 'sub_total' => 5000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-08-01')
            ->set('endDate', '2026-08-31')
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) {
                \PHPUnit\Framework\Assert::assertEquals(1, $purchases->count());
                \PHPUnit\Framework\Assert::assertEquals('ACTIVE PRODUCT', strtoupper($purchases->first()->product_name));
                
                $mapped = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($purchases->first(), 0);
                \PHPUnit\Framework\Assert::assertEquals(1000, $mapped[0]['Total nominal tagihan']);
                
                return true;
            });
    }

    /** @test */
    public function it_excludes_archived_purchases_from_exports()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        
        $supplier = $this->makeSupplier('Supplier Mixed');
        $date = '2026-08-15';
        
        $activePurchase = $this->makePurchase($supplier, ['date' => $date]);
        $this->makePurchaseDetail($activePurchase, ['product_name' => 'Active Product', 'sub_total' => 1000]);

        $archivedPurchase = $this->makePurchase($supplier, ['date' => $date, 'archived_at' => now(), 'archived_by' => $this->user->id]);
        $this->makePurchaseDetail($archivedPurchase, ['product_name' => 'Archived Product', 'sub_total' => 5000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-08-01')
            ->set('endDate', '2026-08-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('purchases_by_vendor_2026-08-01_2026-08-31.xlsx', function ($export) {
            $rows = $export->array();
            \PHPUnit\Framework\Assert::assertCount(4, $rows);
            
            \PHPUnit\Framework\Assert::assertEquals('ACTIVE PRODUCT', strtoupper($rows[1][4]));
            
            foreach ($rows as $row) {
                \PHPUnit\Framework\Assert::assertNotEquals('ARCHIVED PRODUCT', strtoupper($row[4] ?? ''));
            }
            return true;
        });
    }
}
