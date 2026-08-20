<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\People\Entities\Customer;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Livewire\Livewire;
use App\Livewire\Reports\SaleDeliveryReport;
use App\Services\Reports\SaleDeliveryReportQueryService;
use App\Services\Reports\SaleDeliveryReportFilterData;

class SaleDeliveryReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'saleReports.access']);
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
        $this->user->givePermissionTo('saleReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    protected function makeCustomer(string $name = 'Customer 1', ?int $settingId = null): Customer
    {
        static $counter = 0;
        $counter++;
        return Customer::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'customer_name' => $name,
            'customer_email' => "c{$counter}@test.com",
            'customer_phone' => (string) $counter,
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        static $counter = 0;
        $counter++;
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_name' => "Product {$counter}",
            'product_code' => "PRD-{$counter}",
            'product_cost' => 500,
            'product_price' => 1000,
        ], $overrides));
    }

    protected function makeSale(Customer $customer, array $overrides = []): Sale
    {
        static $ref = 0;
        $ref++;
        return Sale::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => 'Completed',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ], $overrides));
    }

    protected function makeSaleDetail(Sale $sale, array $overrides = []): SaleDetails
    {
        return SaleDetails::create(array_merge([
            'sale_id' => $sale->id,
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

    protected function makeSaleBundleItem(Sale $sale, SaleDetails $saleDetail, array $overrides = []): SaleBundleItem
    {
        return SaleBundleItem::create(array_merge([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_id' => $saleDetail->product_id ?? 0,
            'bundle_item_id' => 1,
            'product_id' => null,
            'quantity' => 1,
            'price' => 500,
            'sub_total' => 500,
            'name' => 'Component A',
            'tax_amount' => 0,
        ], $overrides));
    }

    protected function makeDispatch(Customer $customer, array $overrides = []): Dispatch
    {
        return Dispatch::create(array_merge([
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
        ], $overrides));
    }

    protected function makeDispatchDetail(Dispatch $dispatch, array $overrides = []): DispatchDetail
    {
        return DispatchDetail::create(array_merge([
            'dispatch_id' => $dispatch->id,
            'sale_id' => null,
            'product_id' => null,
            'tax_id' => null,
            'bundle_id' => 0,
            'dispatched_quantity' => 1,
        ], $overrides));
    }

    /** @test */
    public function it_can_render_the_sale_delivery_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-delivery.index'));
        $response->assertStatus(200);
        $response->assertSee('Pengiriman Penjualan');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-delivery.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_keeps_dispatch_details_sale_detail_id_nullable_for_legacy_composite_key_matching()
    {
        $this->assertTrue(
            Schema::hasColumn('dispatch_details', 'sale_detail_id'),
            'dispatch_details.sale_detail_id is used for exact lineage matching and must exist.'
        );

        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $dispatch = $this->makeDispatch($customer, ['sale_id' => $sale->id]);
        $detail = $this->makeDispatchDetail($dispatch, ['sale_id' => $sale->id, 'product_id' => $this->makeProduct()->id]);

        $this->assertNull(
            $detail->fresh()->sale_detail_id,
            'sale_detail_id must remain nullable so legacy rows without exact lineage stay readable.'
        );
    }

    /** @test */
    public function it_matches_delivery_to_the_exact_sale_detail_lineage_when_two_details_share_a_composite_key()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $product = $this->makeProduct();

        // Two sale details for the same product/tax/bundle composite key (e.g. two identical
        // lines added separately), distinguishable only by their sale_detail_id lineage.
        $saleDetailOne = $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'quantity' => 1,
            'sub_total' => 1000,
        ]);
        $saleDetailTwo = $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'quantity' => 1,
            'sub_total' => 4000,
        ]);

        $dispatch = $this->makeDispatch($customer, ['sale_id' => $sale->id]);

        // Only the second sale detail was actually dispatched; exact lineage must attribute
        // the delivered amount to its own commercial value (4000), not the composite-key
        // aggregate across both details (1000 + 4000 = 5000).
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailTwo->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->delivered_quantity);
        $this->assertEquals(4000, $result[0]->unit_amount);
        $this->assertEquals(4000, $result[0]->delivered_amount);
    }

    /** @test */
    public function it_falls_back_to_composite_key_matching_when_dispatch_detail_has_no_sale_detail_id()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $product = $this->makeProduct();

        $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'quantity' => 1,
            'sub_total' => 1000,
        ]);

        $dispatch = $this->makeDispatch($customer, ['sale_id' => $sale->id]);

        // Legacy/import-style dispatch detail without sale_detail_id lineage must still be
        // matched by the composite key (sale_id, product_id, tax_id, bundle_id).
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'sale_detail_id' => null,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->delivered_quantity);
        $this->assertEquals(1000, $result[0]->unit_amount);
        $this->assertEquals(1000, $result[0]->delivered_amount);
    }

    /** @test */
    public function it_filters_approved_dispatches_by_date_and_matches_commercial_aggregate()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $product = $this->makeProduct();

        $saleDetail = $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'quantity' => 2,
            'sub_total' => 2000,
        ]);

        $dispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->delivered_quantity);
        $this->assertEquals(1000, $result[0]->unit_amount);
        $this->assertEquals(1000, $result[0]->delivered_amount);
    }

    /** @test */
    public function it_handles_import_style_dispatch_rows_without_sale_detail_id_and_separate_tax()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $product = $this->makeProduct();

        // Same product, different tax context
        $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'tax_id' => 1,
            'quantity' => 1,
            'sub_total' => 1000,
        ]);
        $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'tax_id' => 2,
            'quantity' => 1,
            'sub_total' => 1500,
        ]);

        $dispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
        ]);
        // Dispatch detail does not have sale_detail_id, matches by composite key
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'tax_id' => 2,
            'dispatched_quantity' => 1,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]->tax_id);
        $this->assertEquals(1500, $result[0]->delivered_amount);
    }

    /** @test */
    public function it_separates_standalone_and_bundle_items_with_composite_key()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $product = $this->makeProduct();

        // Standalone item
        $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'quantity' => 1,
            'sub_total' => 1000,
        ]);

        // Bundle item
        $bundleProduct = $this->makeProduct();
        $saleDetailBundle = $this->makeSaleDetail($sale, [
            'product_id' => $bundleProduct->id, // Bundle parent
            'quantity' => 1,
            'sub_total' => 5000,
        ]);
        $this->makeSaleBundleItem($sale, $saleDetailBundle, [
            'product_id' => $product->id,
            'quantity' => 2,
            'sub_total' => 500, // Bundle component commercial value
        ]);

        $dispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
        ]);
        // Delivery of bundle item
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'bundle_id' => $saleDetailBundle->id,
            'dispatched_quantity' => 1,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($saleDetailBundle->id, $result[0]->bundle_id);
        // Unit amount = 500 / 2 = 250. Delivered quantity = 1. Delivered amount = 250.
        $this->assertEquals(250, $result[0]->unit_amount);
        $this->assertEquals(250, $result[0]->delivered_amount);
    }

    /** @test */
    public function it_includes_approved_non_stock_acknowledgement_quantity_as_completed_work(): void
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $service = $this->makeProduct(['stock_managed' => false, 'product_quantity' => 0]);

        $this->makeSaleDetail($sale, [
            'product_id' => $service->id,
            'quantity' => 2,
            'sub_total' => 2000,
        ]);

        $dispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $service->id,
            'dispatched_quantity' => 2,
            'is_inventory_managed' => false,
            'location_id' => null,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result, 'Approved non-stock acknowledgement quantity must be included as completed work');
        $this->assertEquals(2, $result[0]->delivered_quantity);
        $this->assertEquals(2000, $result[0]->delivered_amount);
    }

    /** @test */
    public function it_excludes_pending_and_rejected_acknowledgement_quantity_regardless_of_inventory_routing(): void
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $service = $this->makeProduct(['stock_managed' => false, 'product_quantity' => 0]);
        $stockProduct = $this->makeProduct(['stock_managed' => true]);

        $this->makeSaleDetail($sale, [
            'product_id' => $service->id,
            'quantity' => 2,
            'sub_total' => 2000,
        ]);
        $this->makeSaleDetail($sale, [
            'product_id' => $stockProduct->id,
            'quantity' => 3,
            'sub_total' => 3000,
        ]);

        $pendingDispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'PENDING',
        ]);
        $this->makeDispatchDetail($pendingDispatch, [
            'sale_id' => $sale->id,
            'product_id' => $service->id,
            'dispatched_quantity' => 2,
            'is_inventory_managed' => false,
            'location_id' => null,
        ]);

        $rejectedDispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'REJECTED',
        ]);
        $this->makeDispatchDetail($rejectedDispatch, [
            'sale_id' => $sale->id,
            'product_id' => $stockProduct->id,
            'dispatched_quantity' => 3,
            'is_inventory_managed' => true,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(0, $result, 'Pending and rejected acknowledgement quantity must not appear in the report');
    }

    /** @test */
    public function it_keeps_standalone_bundle_and_tax_contexts_separated_when_mixing_stock_and_non_stock_work(): void
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);

        // Standalone non-stock service.
        $service = $this->makeProduct(['stock_managed' => false, 'product_quantity' => 0]);
        $this->makeSaleDetail($sale, [
            'product_id' => $service->id,
            'quantity' => 1,
            'sub_total' => 1000,
        ]);

        // Bundle parent (stock-managed) with a non-stock component, taxed context.
        $component = $this->makeProduct(['stock_managed' => false, 'product_quantity' => 0]);
        $bundleParent = $this->makeProduct(['stock_managed' => true]);
        $bundleDetail = $this->makeSaleDetail($sale, [
            'product_id' => $bundleParent->id,
            'tax_id' => 7,
            'quantity' => 1,
            'sub_total' => 5000,
        ]);
        $this->makeSaleBundleItem($sale, $bundleDetail, [
            'product_id' => $component->id,
            'bundle_id' => $bundleDetail->id,
            'quantity' => 1,
            'sub_total' => 1500,
        ]);

        $dispatch = $this->makeDispatch($customer, ['sale_id' => $sale->id]);

        // Standalone service acknowledgement.
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $service->id,
            'dispatched_quantity' => 1,
            'is_inventory_managed' => false,
            'location_id' => null,
        ]);

        // Bundle component acknowledgement, inheriting parent's tax and bundle_id.
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $component->id,
            'tax_id' => 7,
            'bundle_id' => $bundleDetail->id,
            'dispatched_quantity' => 1,
            'is_inventory_managed' => false,
            'location_id' => null,
        ]);

        $filter = new SaleDeliveryReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleDeliveryReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(2, $result, 'Standalone and bundled acknowledgement rows must remain separated by the composite key');

        $standaloneRow = $result->firstWhere('product_id', $service->id);
        $this->assertNotNull($standaloneRow);
        $this->assertEquals(0, $standaloneRow->bundle_id);
        $this->assertEquals(1, $standaloneRow->delivered_quantity);

        $bundleRow = $result->firstWhere('product_id', $component->id);
        $this->assertNotNull($bundleRow);
        $this->assertEquals($bundleDetail->id, $bundleRow->bundle_id);
        $this->assertEquals(7, $bundleRow->tax_id);
        $this->assertEquals(1, $bundleRow->delivered_quantity);
        $this->assertEquals(1500, $bundleRow->delivered_amount);
    }

    /** @test */
    public function it_renders_livewire_component_and_displays_empty_state()
    {
        Livewire::test(SaleDeliveryReport::class)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('Tidak ada data pengiriman penjualan yang sesuai dengan filter ini.');
    }

    /** @test */
    public function it_displays_grouped_customer_rows_with_subtotals_and_grand_total()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        $product = $this->makeProduct();

        $saleDetail = $this->makeSaleDetail($sale, [
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'quantity' => 1,
            'sub_total' => 2000,
        ]);

        $dispatch = $this->makeDispatch($customer, [
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
        ]);
        $this->makeDispatchDetail($dispatch, [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
        ]);

        Livewire::test(SaleDeliveryReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee($customer->customer_name)
            ->assertSee($saleDetail->product_name)
            ->assertSee('2.000') // Unit amount
            ->assertSee('Subtotal')
            ->assertSee('Total Keseluruhan');
    }

    /** @test */
    public function it_can_cancel_and_reset_filters()
    {
        $component = Livewire::test(SaleDeliveryReport::class)
            ->set('customerSearch', 'Test')
            ->call('resetFilters')
            ->assertSet('customerSearch', '');

        $component->set('startDate', '2020-01-01')
            ->call('cancelFilters')
            ->assertNotSet('startDate', '2020-01-01'); // Should revert to applied filters or default
    }

    /** @test */
    public function it_enforces_snapshot_protection_for_export()
    {
        $component = Livewire::test(SaleDeliveryReport::class)
            ->set('startDate', '2023-01-01')
            ->set('endDate', '2023-01-31');

        $component->call('exportExcel');
        // Because filter wasn't applied, snapshot is missing or invalid
        $component->assertDispatched('alert');

        $component->call('applyFilters');
        
        // Now export should work without alert
        // (Assuming export triggers file download, we just assert no alert)
        // However, actually Excel::download might be intercepted in tests.
        // We will just verify it doesn't dispatch an alert.
        $component->call('exportExcel')
            ->assertNotDispatched('alert');
    }
}
