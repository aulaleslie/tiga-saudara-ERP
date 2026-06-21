<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\People\Entities\Customer;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SalePayment;
use Livewire\Livewire;
use App\Livewire\Reports\SalesOrderCompletionReport;
use App\Services\Reports\SalesOrderCompletionReportFilterData;
use App\Services\Reports\SalesOrderCompletionReportQueryService;
use Spatie\Tags\Tag;

class SalesOrderCompletionReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'saleReports.access']);
        Role::firstOrCreate(['name' => 'Staff']);

        $this->currency = Currency::create([
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
            'default_currency_id' => $this->currency->id,
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
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ], $overrides));
    }

    /** @test */
    public function it_can_render_the_sales_order_completion_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sales-order-completion.index'));
        $response->assertStatus(200);
        $response->assertSee('Penyelesaian Pemesanan Penjualan');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sales-order-completion.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_filters_by_source_stage_correctly()
    {
        $customer = $this->makeCustomer();
        
        $draftSale = $this->makeSale($customer, ['status' => Sale::STATUS_DRAFTED]);
        $waitingSale = $this->makeSale($customer, ['status' => 'WAITING_APPROVAL']);
        $approvedSale = $this->makeSale($customer, ['status' => Sale::STATUS_APPROVED]);
        $dispatchedSale = $this->makeSale($customer, ['status' => 'DISPATCHED']);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Penawaran'
        );

        $queryService = new SalesOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($draftSale->id, $result[0]->id);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );
        $result = $queryService->build($filter)->get();

        $this->assertCount(3, $result);
        $this->assertFalse($result->contains('id', $draftSale->id));
    }

    /** @test */
    public function it_falls_back_to_header_if_only_invalidated_payments_exist()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'total_amount' => 1000, 
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => Sale::STATUS_APPROVED
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500,
            'payment_method' => 'Cash',
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-1',
            'setting_id' => $this->setting->id,
            'status' => SalePayment::STATUS_INVALIDATED
        ]);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new SalesOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->first();

        // No active payments, so active_payment_count is 0, falls back to sales.paid_amount (1000)
        $this->assertEquals(1000, $result->derived_active_paid);
    }

    /** @test */
    public function it_calculates_amounts_correctly_including_deliveries_and_payments()
    {
        $customer = $this->makeCustomer();
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => "Product 1",
            'product_code' => "PRD-1",
            'product_cost' => 500,
            'product_price' => 1000,
        ]);

        $sale = $this->makeSale($customer, ['total_amount' => 1500, 'status' => Sale::STATUS_APPROVED]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 750,
            'sub_total' => 1500,
            'product_name' => 'Product 1',
            'product_code' => 'PRD-1',
            'price' => 750,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
            'sale_id' => $sale->id,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'tax_id' => null,
            'bundle_id' => 0,
            'dispatched_quantity' => 1,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500,
            'payment_method' => 'Cash',
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-1',
            'setting_id' => $this->setting->id,
            'is_invalid' => false
        ]);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new SalesOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals(1500, $result[0]->total_amount);
        $this->assertEquals(750, $result[0]->derived_delivery_amount); // 1 dispatched * 750 unit amount
        $this->assertEquals(1500, $result[0]->derived_invoice_amount);
        $this->assertEquals(500, $result[0]->derived_active_paid);
    }

    /** @test */
    public function it_maps_order_status_correctly()
    {
        $queryService = new SalesOrderCompletionReportQueryService();

        $row1 = new Sale();
        $row1->status = Sale::STATUS_APPROVED;
        $row1->derived_active_paid = 0;
        $row1->derived_invoice_amount = 0;
        $mapped1 = $queryService->mapRow($row1);
        $this->assertEquals('Belum Dibayar', $mapped1['Status Pesanan']);

        $row2 = new Sale();
        $row2->status = Sale::STATUS_DISPATCHED_PARTIALLY;
        $row2->derived_active_paid = 500;
        $row2->derived_invoice_amount = 1000;
        $mapped2 = $queryService->mapRow($row2);
        $this->assertEquals('Terbayar Sebagian', $mapped2['Status Pesanan']);

        $row3 = new Sale();
        $row3->status = Sale::STATUS_DISPATCHED;
        $row3->derived_active_paid = 1000;
        $row3->derived_invoice_amount = 1000;
        $mapped3 = $queryService->mapRow($row3);
        $this->assertEquals('Selesai', $mapped3['Status Pesanan']);
    }

    /** @test */
    public function it_filters_by_customer_and_tags_correctly()
    {
        $customer1 = $this->makeCustomer('Cust 1');
        $customer2 = $this->makeCustomer('Cust 2');

        $tag = Tag::findOrCreate('TestTag', 'en');
        $sale1 = $this->makeSale($customer1);
        $sale1->attachTag($tag);

        $sale2 = $this->makeSale($customer2);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan',
            customerIds: [$customer1->id],
            tagIds: [$tag->id]
        );

        $queryService = new SalesOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($sale1->id, $result[0]->id);
    }

    /** @test */
    public function it_enforces_tenant_scoping()
    {
        $setting2 = Setting::create([
            'company_name' => 'Setting 2',
            'company_email' => 't2@test.com',
            'company_phone' => '123',
            'notification_email' => 'n2@test.com',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $customer1 = $this->makeCustomer('C1', $this->setting->id);
        $customer2 = $this->makeCustomer('C2', $setting2->id);

        $this->makeSale($customer1, ['setting_id' => $this->setting->id]);
        $this->makeSale($customer2, ['setting_id' => $setting2->id]);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new SalesOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($this->setting->id, $result[0]->setting_id);
    }

    /** @test */
    public function it_enforces_snapshot_protection_for_export()
    {
        session(['setting_id' => $this->setting->id]);
        $component = Livewire::test(SalesOrderCompletionReport::class)
            ->set('startDate', '2023-01-01')
            ->set('endDate', '2023-01-31');

        $component->call('exportExcel');
        $component->assertDispatched('alert');

        $component->call('applyFilters');
        $component->call('exportExcel')
            ->assertNotDispatched('alert');
    }

    /** @test */
    public function it_exports_data_with_correct_mapping_and_totals()
    {
        $customer = $this->makeCustomer();
        $this->makeSale($customer, [
            'total_amount' => 1500,
            'paid_amount' => 500,
            'due_amount' => 1000,
            'status' => Sale::STATUS_APPROVED
        ]);

        $filter = new SalesOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new SalesOrderCompletionReportQueryService();
        $query = $queryService->build($filter);
        
        $export = new \App\Exports\SalesOrderCompletionReportExport($query, $filter, false);
        $rows = $export->collection();

        // 1 data row + 1 total row
        $this->assertCount(2, $rows);

        $dataRow = $rows[0];
        $this->assertFalse($dataRow->is_total_row);
        $this->assertArrayHasKey('Tanggal Pemesanan', $dataRow->data);
        $this->assertArrayHasKey('No. Pemesanan', $dataRow->data);
        $this->assertArrayHasKey('Jumlah Faktur', $dataRow->data);
        
        $totalRow = $rows[1];
        $this->assertTrue($totalRow->is_total_row);
        $this->assertEquals('Total', $totalRow->data['Tanggal Pemesanan']);
        $this->assertEquals(1500, $totalRow->data['Jumlah Pesanan']);
        $this->assertEquals(1500, $totalRow->data['Jumlah Faktur']);
        $this->assertEquals(500, $totalRow->data['Jumlah Pembayaran']);
    }
}
