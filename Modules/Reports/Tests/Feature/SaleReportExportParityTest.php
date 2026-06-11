<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SaleReportExport;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Product\Entities\Product;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Customer;

class SaleReportExportParityTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'super_admin']);
        $permission = Permission::create(['name' => 'saleReports.access']);
        $role->givePermissionTo($permission);

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
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
    }

    /** @test */
    public function it_blocks_export_before_running_report()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_downloads_excel_with_correct_filename_and_does_not_block()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.xlsx', function(SaleReportExport $export) {
            $query = $export->query();
            return $query->count() === 0;
        });
    }

    /** @test */
    public function it_downloads_csv_with_correct_filename_and_does_not_block()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportCsv');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.csv', function(SaleReportExport $export) {
            $events = $export->registerEvents();
            return count($events) === 0;
        });
    }

    /** @test */
    public function it_blocks_pdf_export_with_disabled_message()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->call('exportPdf')
            ->assertDispatched('alert', function ($eventName, $eventData) {
                return $eventName === 'alert'
                    && isset($eventData[0]['message'])
                    && str_contains($eventData[0]['message'], 'belum tersedia');
            });
    }

    /** @test */
    public function unapplied_pending_filters_do_not_affect_exported_rows()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('startDate', '2026-05-15')
            ->call('exportExcel');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.xlsx', function(SaleReportExport $export) {
            $query = $export->query();
            $bindings = $query->getBindings();
            return !in_array('2026-05-15', $bindings);
        });
    }

    /** @test */
    public function unapplied_pending_mode_changes_do_not_affect_exported_shape()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('reportMode', 'header')
            ->call('exportExcel');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.xlsx', function (SaleReportExport $export) {
            return in_array('Nama Produk', $export->headings(), true);
        });
    }

    /** @test */
    public function header_mode_exports_only_concise_header_columns()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Header Customer',
            'customer_email' => 'header@example.com',
            'customer_phone' => '08123',
            'address' => 'Header Address',
            'city' => 'City',
            'country' => 'Country',
        ]);

        $sale = Sale::create([
            'reference' => 'SO-HDR',
            'date' => '2026-05-10',
            'total_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
        ]);

        $product = Product::create([
            'product_name' => 'Header Product',
            'product_code' => 'HDR-01',
            'product_cost' => 0,
            'product_price' => 50000,
            'setting_id' => $this->setting->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Header Product',
            'product_code' => 'HDR-01',
            'quantity' => 2,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('reportMode', 'header')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.xlsx', function (SaleReportExport $export) use ($sale) {
            $expectedHeadings = [
                'Tanggal',
                'Nomor Transaksi',
                'Nama Pelanggan',
                'Status Dokumen',
                'Status Pembayaran',
                'Memo',
                'Total',
                'Sisa Tagihan',
                'Jumlah Kena Pajak',
                'Total Pajak',
                'Pembayaran',
                'Tag',
            ];

            $row = $export->query()->first();
            $mapped = array_combine($export->headings(), $export->map($row));

            return $export->headings() === $expectedHeadings
                && $export->query()->count() === 1
                && !array_key_exists('Nama Produk', $mapped)
                && $mapped['Nomor Transaksi'] === $sale->reference;
        });
    }

    /** @test */
    public function it_exports_raw_numeric_values_and_dashes_for_empty()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST01',
            'product_cost' => 0,
            'product_price' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $sale = Sale::create([
            'reference' => 'SO-0001',
            'date' => '2026-05-10',
            'total_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => '-',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST01',
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_type' => 'percentage',
            'product_discount_amount' => 5,
            'product_tax_amount' => 0,
        ]);

        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.xlsx', function(SaleReportExport $export) {
            $query = $export->query();
            $row = $query->first();
            $mapped = $export->map($row);

            $headings = $export->headings();
            $mappedAssoc = array_combine($headings, $mapped);

            return $mappedAssoc['Total'] == 100000
                && $mappedAssoc['Kuantitas'] == 10
                && $mappedAssoc['Nama Pelanggan'] === '-';
        });
    }

    /** @test */
    public function exports_follow_the_current_table_sort()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('sortBy', 'total_amount') // Sort ascending
            ->call('exportExcel');

        Excel::assertDownloaded('sales_list_01-05-2026_31-05-2026.xlsx', function(SaleReportExport $export) {
            $query = $export->query()->getQuery();
            $orders = $query->orders;
            if (!$orders) return false;
            return $orders[0]['column'] === 'sales.total_amount' && $orders[0]['direction'] === 'asc';
        });
    }
}
