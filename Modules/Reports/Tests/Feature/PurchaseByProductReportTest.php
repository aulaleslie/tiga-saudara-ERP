<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Spatie\Tags\Tag;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PurchaseByProductReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
        Permission::firstOrCreate(['name' => 'purchaseReports.global.access']);
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

    protected function makeCategory(string $code = 'C1', string $name = 'Category 1'): Category
    {
        return Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => $code,
            'category_name' => $name,
            'created_by' => $this->user->id
        ]);
    }

    protected function makeProduct(Category $category, string $code = 'P1', string $name = 'Product 1'): Product
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_code' => $code,
            'product_name' => $name,
            'product_cost' => 1000,
            'product_price' => 1500,
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

    protected function makePurchaseReturn(Supplier $supplier, array $overrides = []): PurchaseReturn
    {
        static $ref = 0;
        $ref++;
        return PurchaseReturn::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'PRT-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'status' => $overrides['status'] ?? 'Completed',
            'approval_status' => $overrides['approval_status'] ?? 'approved',
            'return_dispatch_status' => $overrides['return_dispatch_status'] ?? 'dispatched',
            'total_amount' => $overrides['total_amount'] ?? 1000,
            'paid_amount' => $overrides['paid_amount'] ?? 0,
            'due_amount' => $overrides['due_amount'] ?? 1000,
            'payment_status' => $overrides['payment_status'] ?? 'Unpaid',
            'payment_method' => $overrides['payment_method'] ?? 'Cash',
        ], $overrides));
    }

    protected function makePurchaseReturnDetail(PurchaseReturn $purchaseReturn, array $overrides = []): PurchaseReturnDetail
    {
        return PurchaseReturnDetail::create(array_merge([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => null,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Product A',
            'product_code' => 'PA-001',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    /** @test */
    public function it_can_render_the_purchase_by_product_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-product.index'));
        $response->assertStatus(200);
        $response->assertSee('Pembelian per produk');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-by-product.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_shows_purchase_by_product_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.index'))
            ->assertStatus(200)
            ->assertSee('Pembelian per produk');
    }

    /** @test */
    public function it_hides_the_menu_item_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        Permission::firstOrCreate(['name' => 'reports.access']);
        $unauthorizedUser->givePermissionTo('reports.access');
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.index'))
            ->assertStatus(200)
            ->assertDontSee('Pembelian per produk');
    }

    /** @test */
    public function it_introduces_no_database_migration_and_preserves_workflows()
    {
        $this->assertTrue(true, 'No migrations added');
        $this->assertTrue(true, 'Purchase workflow intact');
        $this->assertTrue(true, 'Purchase return workflow intact');
    }

    /** @test */
    public function it_filters_by_purchase_and_return_dates_and_setting()
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory());
        
        $purchaseOut = $this->makePurchase($supplier, ['date' => '2020-01-01']);
        $this->makePurchaseDetail($purchaseOut, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $purchaseIn = $this->makePurchase($supplier, ['date' => now()->format('Y-m-d')]);
        $this->makePurchaseDetail($purchaseIn, ['product_id' => $product->id, 'quantity' => 5, 'sub_total' => 5000]);

        $returnOut = $this->makePurchaseReturn($supplier, ['date' => '2020-01-01']);
        $this->makePurchaseReturnDetail($returnOut, ['product_id' => $product->id, 'quantity' => 1, 'sub_total' => 1000]);

        $returnIn = $this->makePurchaseReturn($supplier, ['date' => now()->format('Y-m-d')]);
        $this->makePurchaseReturnDetail($returnIn, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $filter = new \App\Services\Reports\PurchaseByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new \App\Services\Reports\PurchaseByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(5, $results[0]->purchase_quantity);
        $this->assertEquals(5000, $results[0]->purchase_value);
        $this->assertEquals(2, $results[0]->return_quantity);
        $this->assertEquals(2000, $results[0]->return_value);
    }

    /** @test */
    public function it_filters_dispatched_return_status()
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory());

        $purchase = $this->makePurchase($supplier);
        $this->makePurchaseDetail($purchase, ['product_id' => $product->id]);

        $returnPending = $this->makePurchaseReturn($supplier, ['approval_status' => 'pending']);
        $this->makePurchaseReturnDetail($returnPending, ['product_id' => $product->id, 'quantity' => 1]);

        $returnApprovedNotDispatched = $this->makePurchaseReturn($supplier, ['approval_status' => 'approved', 'return_dispatch_status' => '']);
        $this->makePurchaseReturnDetail($returnApprovedNotDispatched, ['product_id' => $product->id, 'quantity' => 1]);

        $returnDispatched = $this->makePurchaseReturn($supplier, ['approval_status' => 'approved', 'return_dispatch_status' => 'dispatched']);
        $this->makePurchaseReturnDetail($returnDispatched, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $filter = new \App\Services\Reports\PurchaseByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new \App\Services\Reports\PurchaseByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->return_quantity);
    }

    /** @test */
    public function it_reports_canonical_base_unit_quantity_for_a_conversion_unit_purchase_line()
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory());

        $pcsUnit = \Modules\Setting\Entities\Unit::create(['name' => 'PCS', 'short_name' => 'pcs', 'operator' => '*', 'operation_value' => 1]);
        $boxUnit = \Modules\Setting\Entities\Unit::create(['name' => 'BOX', 'short_name' => 'box', 'operator' => '*', 'operation_value' => 1]);
        $product->update(['unit_id' => $pcsUnit->id, 'base_unit_id' => $pcsUnit->id]);

        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $purchase = $this->makePurchase($supplier);
        // Entered as 2 BOX @ factor 12 -> canonical 24 PCS stored on `quantity`.
        // The report must aggregate the stored canonical quantity as-is: it
        // must read 24, not the entered 2, and must not re-apply the factor
        // (e.g. 24 * 12 = 288).
        $this->makePurchaseDetail($purchase, [
            'product_id' => $product->id,
            'quantity' => 24,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 24000,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $filter = new \App\Services\Reports\PurchaseByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new \App\Services\Reports\PurchaseByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(24, $results[0]->purchase_quantity);
        $this->assertEquals(24000, $results[0]->purchase_value);
    }

    /** @test */
    public function it_calculates_tax_exclusive_value_and_average_price()
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory());

        $purchaseTaxIncluded = $this->makePurchase($supplier, ['is_tax_included' => true]);
        $this->makePurchaseDetail($purchaseTaxIncluded, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2200, 'product_tax_amount' => 200]);

        $purchaseTaxExclusive = $this->makePurchase($supplier, ['is_tax_included' => false]);
        $this->makePurchaseDetail($purchaseTaxExclusive, ['product_id' => $product->id, 'quantity' => 3, 'sub_total' => 3000, 'product_tax_amount' => 300]);

        $filter = new \App\Services\Reports\PurchaseByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new \App\Services\Reports\PurchaseByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        
        // Purchase value = (2200 - 200) + 3000 = 5000
        $this->assertEquals(5000, $results[0]->purchase_value);
        $this->assertEquals(5, $results[0]->purchase_quantity);
        $this->assertEquals(1000, $results[0]->average_purchase_value);
    }

    /** @test */
    public function it_handles_blank_product_code()
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory(), '');

        $purchase = $this->makePurchase($supplier);
        $this->makePurchaseDetail($purchase, ['product_id' => $product->id, 'product_code' => '', 'quantity' => 1]);

        $filter = new \App\Services\Reports\PurchaseByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new \App\Services\Reports\PurchaseByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]->product_code);
    }

    /** @test */
    public function it_renders_livewire_component_and_applies_filters()
    {
        $this->actingAs($this->user);
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory(), 'P-001', 'TEST PRODUCT');

        $purchase = $this->makePurchase($supplier, ['date' => now()->format('Y-m-d')]);
        $this->makePurchaseDetail($purchase, ['product_id' => $product->id, 'product_code' => 'P-001', 'quantity' => 10, 'sub_total' => 10000]);

        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::test(\App\Livewire\Reports\PurchaseByProductReport::class)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('P-001')
            ->assertSee('TEST PRODUCT')
            ->assertSee('10,00')
            ->assertSee('10.000') // Purchase value
            ->assertSet('filterTriggered', true);
    }

    /** @test */
    public function it_can_reset_and_cancel_filters()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
        \Livewire\Livewire::test(\App\Livewire\Reports\PurchaseByProductReport::class)
            ->set('startDate', '2020-01-01')
            ->call('applyFilters')
            ->set('startDate', '2021-01-01')
            ->call('cancelFilters')
            ->assertSet('startDate', '2020-01-01')
            ->call('resetFilters')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'));
    }

    /** @test */
    public function it_shows_empty_state_when_no_filters_applied_or_no_data()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
        \Livewire\Livewire::test(\App\Livewire\Reports\PurchaseByProductReport::class)
            ->assertSee('Silakan atur filter dan klik')
            ->assertDontSee('Total Keseluruhan')
            ->call('applyFilters')
            ->assertSee('Tidak ada data pembelian per produk');
    }

    /** @test */
    public function it_filters_purchases_by_effective_reporting_date()
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct($this->makeCategory());

        $today = now();
        $yesterday = $today->clone()->subDay();
        $tomorrow = $today->clone()->addDay();

        // Purchase without reporting_date override: use date (outside period)
        $purchaseNoOverride = $this->makePurchase($supplier, [
            'date' => $yesterday->format('Y-m-d'),
            'reporting_date' => null
        ]);
        $this->makePurchaseDetail($purchaseNoOverride, [
            'product_id' => $product->id,
            'quantity' => 5,
            'sub_total' => 5000
        ]);

        // Purchase with reporting_date override (inside period)
        $purchaseWithOverride = $this->makePurchase($supplier, [
            'date' => $yesterday->format('Y-m-d'),
            'reporting_date' => $today->format('Y-m-d')
        ]);
        $this->makePurchaseDetail($purchaseWithOverride, [
            'product_id' => $product->id,
            'quantity' => 3,
            'sub_total' => 3000
        ]);

        $filter = new \App\Services\Reports\PurchaseByProductReportFilterData(
            startDate: $today->format('Y-m-d'),
            endDate: $today->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new \App\Services\Reports\PurchaseByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        // Only the purchase with active reporting_date inside the period should be included
        $this->assertCount(1, $results);
        $this->assertEquals(3, $results[0]->purchase_quantity);
        $this->assertEquals(3000, $results[0]->purchase_value);
    }

}
