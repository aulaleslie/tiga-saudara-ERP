<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PurchaseReportExport;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Product\Entities\Product;

class PurchaseReportExportParityTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \Spatie\Permission\Models\Role::create(['name' => 'super_admin']);
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'access_reports']);
        $role->givePermissionTo($permission);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = \Modules\Setting\Entities\Setting::create([
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
    }

    /** @test */
    public function it_blocks_export_before_running_report()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
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
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.xlsx', function(PurchaseReportExport $export) {
            // Check that it's an excel file (default) and has the correct query
            $query = $export->query();
            return $query->count() === 0; // we have no records, just testing the download logic
        });
    }

    /** @test */
    public function it_downloads_csv_with_correct_filename_and_does_not_block()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportCsv');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.csv', function(PurchaseReportExport $export) {
            $events = $export->registerEvents();
            // CSV should not have events
            return count($events) === 0;
        });
    }

    /** @test */
    public function it_blocks_pdf_export_with_disabled_message()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
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
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('startDate', '2026-05-15') // pending filter, not applied
            ->call('exportExcel');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.xlsx', function(PurchaseReportExport $export) {
            $query = $export->query();
            // Should still use the applied start date 2026-05-01, not 2026-05-15
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
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('reportMode', 'header')
            ->call('exportExcel');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.xlsx', function (PurchaseReportExport $export) {
            return in_array('Nama Produk', $export->headings(), true)
                && !in_array('Mode Laporan', $export->headings(), true);
        });
    }

    /** @test */
    public function header_mode_exports_only_concise_header_columns()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Header Supplier',
            'supplier_email' => 'header@example.com',
            'supplier_phone' => '08123',
            'address' => 'Header Address',
            'city' => 'City',
            'country' => 'Country',
        ]);

        $purchase = Purchase::create([
            'date' => '2026-05-10',
            'due_date' => '2026-05-15',
            'total_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'supplier_id' => $supplier->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => null,
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
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('reportMode', 'header')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.xlsx', function (PurchaseReportExport $export) use ($purchase) {
            $expectedHeadings = [
                'Tanggal',
                'Nomor Transaksi',
                'Nomor Pembelian Supplier',
                'Nama Panggilan',
                'Status Dokumen',
                'Status Pembayaran',
                'Memo',
                'Total',
                'Sisa Tagihan',
                'Tanggal Jatuh Tempo',
                'Jumlah Kena Pajak',
                'Total Pajak',
                'Pembayaran',
                'No Ref',
                'Tag',
            ];

            $row = $export->query()->first();
            $mapped = array_combine($export->headings(), $export->map($row));

            return $export->headings() === $expectedHeadings
                && $export->query()->count() === 1
                && !array_key_exists('Nama Produk', $mapped)
                && $mapped['Nomor Transaksi'] === $purchase->reference;
        });
    }

    /** @test */
    public function it_exports_raw_numeric_values_and_dashes_for_empty()
    {
        // Add a mock purchase to verify mapRow logic
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST01',
            'product_cost' => 0,
            'product_price' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $purchase = Purchase::create([
            'reference' => 'PR-0001',
            'date' => '2026-05-10',
            'due_date' => '2026-05-15',
            'total_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'supplier_id' => null, // empty optional
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
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
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.xlsx', function(PurchaseReportExport $export) {
            $query = $export->query();
            $row = $query->first();
            $mapped = $export->map($row);

            $headings = $export->headings();
            $mappedAssoc = array_combine($headings, $mapped);

            return $mappedAssoc['Total'] == 100000
                && $mappedAssoc['Kuantitas'] == 10
                && $mappedAssoc['Nama Panggilan'] === '-';
        });
    }

    /** @test */
    public function exports_follow_the_current_table_sort()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('sortBy', 'total_amount') // Sort ascending (first click)
            ->call('exportExcel');

        Excel::assertDownloaded('purchases_list_01-05-2026_31-05-2026.xlsx', function(PurchaseReportExport $export) {
            $query = $export->query()->getQuery();
            $orders = $query->orders;
            if (!$orders) return false;
            return $orders[0]['column'] === 'purchases.total_amount' && $orders[0]['direction'] === 'asc';
        });
    }
}
