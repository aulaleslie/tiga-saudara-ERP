<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\People\Entities\Customer;
use App\Models\User;
use Spatie\Tags\Tag;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SaleReportHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'saleReports.access']);
        Role::create(['name' => 'Staff']);

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

    protected function makeSale(Customer $customer, array $overrides = []): Sale
    {
        static $ref = 0;
        $ref++;
        return Sale::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'SO-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ], $overrides));
    }

    protected function makePayment(Sale $sale, float $amount, string $status = null): SalePayment
    {
        static $pref = 0;
        $pref++;
        return SalePayment::create([
            'sale_id' => $sale->id,
            'reference' => 'SP-' . str_pad($pref, 4, '0', STR_PAD_LEFT),
            'amount' => $amount,
            'status' => $status ?? SalePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => now()->format('Y-m-d'),
        ]);
    }

    protected function makeProduct(string $name = 'Product A'): \Modules\Product\Entities\Product
    {
        return \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => $name,
            'product_code' => 'P-' . uniqid(),
            'product_price' => 1000,
            'product_cost' => 500,
            'product_quantity' => 10,
        ]);
    }

    protected function makeSaleDetail(Sale $sale, array $overrides = []): SaleDetails
    {
        $product = $this->makeProduct($overrides['product_name'] ?? 'Product A');
        return SaleDetails::create(array_merge([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    // ─── Page render ───────────────────────────────────────────────────────────

    /** @test */
    public function it_can_render_the_sale_report_page()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-report.index'));
        $response->assertStatus(200);
    }

    /** @test */
    public function it_shows_sale_report_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertSee('href="' . route('reports.index') . '"', false);
    }

    /** @test */
    public function it_hides_sale_report_menu_for_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('home'))
            ->assertStatus(200)
            ->assertDontSee('href="' . route('reports.index') . '"', false);
    }

    /** @test */
    public function it_renders_page_title_and_breadcrumb_as_daftar_penjualan()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.sale-report.index'))
            ->assertStatus(200)
            ->assertSee('Laporan Penjualan');
    }

    // ─── Task 1.2: Default date range is current month ─────────────────────────

    /** @test */
    public function it_defaults_start_and_end_date_to_current_month()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->assertSet('reportMode', 'detail')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }

    /** @test */
    public function it_falls_back_to_detail_mode_when_query_string_mode_is_invalid()
    {
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::withQueryParams(['reportMode' => 'invalid'])
            ->actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->assertSet('reportMode', 'detail');
    }

    // ─── Task 1.3: One sale with multiple details → multiple report rows ───

    /** @test */
    public function it_returns_one_row_per_sale_detail_for_a_single_sale()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makeSaleDetail($sale, ['product_name' => 'Product A']);
        $this->makeSaleDetail($sale, ['product_name' => 'Product B']);
        $this->makeSaleDetail($sale, ['product_name' => 'Product C']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                return $sales->count() === 3;
            });

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('reportMode', 'header')
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('appliedFilters.reportMode', 'header')
            ->assertDontSee('Nama Produk')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->count() === 1
                    && $sales->pluck('id')->contains($sale->id);
            });
    }

    /** @test */
    public function it_produces_separate_rows_for_each_detail_of_different_sales()
    {
        $customer = $this->makeCustomer();
        $s1 = $this->makeSale($customer, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $s2 = $this->makeSale($customer, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $this->makeSaleDetail($s1, ['product_name' => 'P1-A']);
        $this->makeSaleDetail($s1, ['product_name' => 'P1-B']);
        $this->makeSaleDetail($s2, ['product_name' => 'P2-A']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                return $sales->count() === 3;
            });
    }

    // ─── Task 1.4: Bahasa Indonesia labels, no Tipe transaksi/product/Gudang filters

    /** @test */
    public function it_does_not_render_a_tipe_transaksi_filter()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.sale-report.index'))
            ->assertStatus(200)
            ->assertDontSee('Tipe transaksi');
    }

    /** @test */
    public function it_does_not_render_a_product_filter()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-report.index'));
        $response->assertStatus(200);
        // Ensure no product-filter input is present
        $response->assertDontSee('wire:model="productId"');
        $response->assertDontSee('wire:model="productSearch"');
    }

    /** @test */
    public function it_does_not_render_a_gudang_filter()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-report.index'));
        $response->assertStatus(200);
        $response->assertDontSee('wire:model="locationId"');
        $response->assertDontSee('wire:model="locationSearch"');
    }

    /** @test */
    public function it_renders_bahasa_indonesia_filter_labels()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-report.index'));
        $response->assertStatus(200);
        $response->assertSee('Tanggal awal');
        $response->assertSee('Tanggal akhir');
        $response->assertSee('Pelanggan');
        $response->assertSee('Status Dokumen');
        $response->assertSee('Status Pembayaran');
        $response->assertSee('Grup dengan tag');
    }

    // ─── Task 1.5: Customer/tag min 2-char lookup ──────────────────────────────

    /** @test */
    public function it_only_triggers_customer_lookup_after_min_chars()
    {
        $this->makeCustomer('Test Customer');
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('customerSearch', 'T')
            ->assertSet('customerOptions', [])
            ->set('customerSearch', 'Te')
            ->assertCount('customerOptions', 1);
    }

    /** @test */
    public function it_only_triggers_tag_lookup_after_min_chars_and_respects_locale()
    {
        $tag = Tag::create(['name' => ['en' => 'Test Tag', 'id' => 'Tag Tes']]);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('tagSearch', 'T')
            ->assertSet('tagOptions', [])
            ->set('tagSearch', 'Te')
            ->assertCount('tagOptions', 1)
            ->assertSet('tagOptions.0.id', $tag->id);
    }

    // ─── Task 1.6: Multi-select OR behavior ───────────────────────────────────

    /** @test */
    public function it_filters_by_multiple_customers_with_or_semantics()
    {
        $c1 = $this->makeCustomer('Alpha Customer');
        $c2 = $this->makeCustomer('Beta Customer');
        $c3 = $this->makeCustomer('Gamma Customer');
        $s1 = $this->makeSale($c1, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $s2 = $this->makeSale($c2, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $s3 = $this->makeSale($c3, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $this->makeSaleDetail($s1);
        $this->makeSaleDetail($s2);
        $this->makeSaleDetail($s3);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('customerIds', [$c1->id, $c2->id])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($s1, $s2, $s3) {
                $ids = $sales->pluck('sale_id')->toArray();
                return in_array($s1->id, $ids)
                    && in_array($s2->id, $ids)
                    && !in_array($s3->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_multiple_tags_with_or_semantics()
    {
        $tagA = Tag::create(['name' => ['en' => 'TagA']]);
        $tagB = Tag::create(['name' => ['en' => 'TagB']]);
        $tagC = Tag::create(['name' => ['en' => 'TagC']]);

        $customer = $this->makeCustomer();
        $sA = $this->makeSale($customer, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $sB = $this->makeSale($customer, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $sC = $this->makeSale($customer, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $sA->attachTag($tagA);
        $sB->attachTag($tagB);
        $sC->attachTag($tagC);
        $this->makeSaleDetail($sA);
        $this->makeSaleDetail($sB);
        $this->makeSaleDetail($sC);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('tagIds', [$tagA->id, $tagB->id])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sA, $sB, $sC) {
                $ids = $sales->pluck('sale_id')->toArray();
                return in_array($sA->id, $ids)
                    && in_array($sB->id, $ids)
                    && !in_array($sC->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_multiple_document_statuses_with_or_semantics()
    {
        $customer = $this->makeCustomer();
        $sApproved = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Sale::STATUS_APPROVED,
        ]);
        $sDispatched = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
        ]);
        $sDrafted = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'status' => Sale::STATUS_DRAFTED,
        ]);
        $this->makeSaleDetail($sApproved);
        $this->makeSaleDetail($sDispatched);
        $this->makeSaleDetail($sDrafted);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('documentStatuses', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sApproved, $sDispatched, $sDrafted) {
                $ids = $sales->pluck('sale_id')->toArray();
                return in_array($sApproved->id, $ids)
                    && in_array($sDispatched->id, $ids)
                    && !in_array($sDrafted->id, $ids);
            });
    }

    /** @test */
    public function it_filters_by_multiple_payment_statuses_with_or_semantics()
    {
        $customer = $this->makeCustomer();
        $sUnpaid = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        $sPartial = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 500,
            'due_amount' => 500,
        ]);
        $sPaid = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makePayment($sPartial, 500);
        $this->makePayment($sPaid, 1000);
        $this->makeSaleDetail($sUnpaid);
        $this->makeSaleDetail($sPartial);
        $this->makeSaleDetail($sPaid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID', 'PARTIAL'])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sUnpaid, $sPartial, $sPaid) {
                $ids = $sales->pluck('sale_id')->toArray();
                return in_array($sUnpaid->id, $ids)
                    && in_array($sPartial->id, $ids)
                    && !in_array($sPaid->id, $ids);
            });
    }

    // ─── Task 1.7: Derived payment status ignores invalidated payments ─────────

    /** @test */
    public function it_derives_payment_status_as_unpaid_when_all_payments_are_invalidated()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        $this->makePayment($sale, 1000, SalePayment::STATUS_INVALIDATED);
        $this->makeSaleDetail($sale);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID'])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->pluck('sale_id')->contains($sale->id);
            });
    }

    /** @test */
    public function it_derives_payment_status_as_partial_using_active_payments_only()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        // One active payment of 300 and one invalidated of 700 → derived = PARTIAL
        $this->makePayment($sale, 300);
        $this->makePayment($sale, 700, SalePayment::STATUS_INVALIDATED);
        $this->makeSaleDetail($sale);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['PARTIAL'])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->pluck('sale_id')->contains($sale->id);
            });
    }

    /** @test */
    public function it_derives_payment_status_from_header_amounts_when_no_payment_rows_exist()
    {
        $customer = $this->makeCustomer();
        $partial = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 500,
            'due_amount' => 500,
        ]);
        $paid = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makeSaleDetail($partial);
        $this->makeSaleDetail($paid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($partial, $paid) {
                $rows = $sales->keyBy('sale_id');

                return \App\Services\Reports\SaleReportQueryService::mapRow($rows[$partial->id])['Status Pembayaran'] === 'Terbayar Sebagian'
                    && \App\Services\Reports\SaleReportQueryService::mapRow($rows[$paid->id])['Status Pembayaran'] === 'Lunas';
            });
    }

    /** @test */
    public function it_filters_payment_status_from_header_amounts_when_no_payment_rows_exist()
    {
        $customer = $this->makeCustomer();
        $unpaid = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);
        $paid = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makeSaleDetail($unpaid);
        $this->makeSaleDetail($paid);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['PAID'])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($unpaid, $paid) {
                $ids = $sales->pluck('sale_id')->toArray();
                return in_array($paid->id, $ids) && !in_array($unpaid->id, $ids);
            });
    }

    /** @test */
    public function it_ignores_stale_header_amounts_when_payment_rows_exist()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);
        $this->makePayment($sale, 1000, SalePayment::STATUS_INVALIDATED);
        $this->makeSaleDetail($sale);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->set('paymentStatuses', ['UNPAID'])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->pluck('sale_id')->contains($sale->id);
            });
    }

    // ─── Task 1.8: Gudang column from approved dispatch locations ─────────

    /** @test */
    public function it_populates_gudang_column_from_approved_dispatch_location()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $detail = $this->makeSaleDetail($sale);

        $location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Utama',
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $detail->product_id ?? 1, // need product_id
            'location_id' => $location->id,
            'dispatched_quantity' => 1,
        ]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                $row = $sales->first();
                return $row && stripos($row->gudang ?? '', 'Gudang Utama') !== false;
            });
    }

    /** @test */
    public function it_joins_multiple_distinct_approved_dispatch_locations_in_gudang_without_duplicating_rows()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $detail = $this->makeSaleDetail($sale);

        $loc1 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Gudang A']);
        $loc2 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Gudang B']);

        $rn1 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $rn2 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        DispatchDetail::create(['dispatch_id' => $rn1->id, 'sale_id' => $sale->id, 'product_id' => $detail->product_id ?? 1, 'location_id' => $loc1->id, 'dispatched_quantity' => 1]);
        DispatchDetail::create(['dispatch_id' => $rn2->id, 'sale_id' => $sale->id, 'product_id' => $detail->product_id ?? 1, 'location_id' => $loc2->id, 'dispatched_quantity' => 1]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                // Only 1 detail → 1 row, Gudang contains both location names
                if ($sales->count() !== 1) {
                    return false;
                }
                $gudang = $sales->first()->gudang ?? '';
                return stripos($gudang, 'Gudang A') !== false && stripos($gudang, 'Gudang B') !== false;
            });
    }

    // ─── Existing behaviour: date filters & scope ──────────────────────────────

    /** @test */
    public function it_filters_sales_by_date_range()
    {
        $customer = $this->makeCustomer();
        $s1 = $this->makeSale($customer, ['date' => '2026-01-15']);
        $s2 = $this->makeSale($customer, ['date' => '2026-02-01']);
        $this->makeSaleDetail($s1);
        $this->makeSaleDetail($s2);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class, ['isGlobal' => false])
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-01-01')
            ->set('endDate', '2026-01-31')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                return $sales->count() === 1;
            });
    }

    /** @test */
    public function it_enforces_setting_id_scope_in_non_global_mode()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Company',
            'company_email' => 'other@example.com',
            'company_phone' => '987654321',
            'notification_email' => 'other-notify@example.com',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $c1 = $this->makeCustomer('C1', $this->setting->id);
        $c2 = $this->makeCustomer('C2', $otherSetting->id);
        $s1 = $this->makeSale($c1, ['date' => now()->startOfMonth()->format('Y-m-d')]);
        $s2 = $this->makeSale($c2, ['date' => now()->startOfMonth()->format('Y-m-d'), 'setting_id' => $otherSetting->id]);
        $this->makeSaleDetail($s1);
        $this->makeSaleDetail($s2);

        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                return $sales->every(fn($row) => $row->sale->setting_id === $this->setting->id);
            });
    }

    /** @test */
    public function it_shows_empty_state_when_no_records_match()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2020-01-01')
            ->set('endDate', '2020-01-01')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                return $sales->count() === 0;
            });
    }

    /** @test */
    public function it_rejects_end_date_before_start_date()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-02-01')
            ->set('endDate', '2026-01-01')
            ->call('applyFilters')
            ->assertHasErrors(['endDate']);
    }

    /** @test */
    public function it_rejects_invalid_document_status_value()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('documentStatuses', ['INVALID_STATUS'])
            ->call('applyFilters')
            ->assertHasErrors(['documentStatuses.*']);
    }
}
