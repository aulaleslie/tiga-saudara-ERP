<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\SupplierPayablesReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\People\Entities\Supplier;

class SupplierPayablesReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;
    protected $supplier2;

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
        
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'a@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id
        ]);

        $this->supplier2 = Supplier::create([
            'supplier_name' => 'Supplier B',
            'supplier_email' => 'b@test.com',
            'supplier_phone' => '654321',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id
        ]);
    }

    private function createPurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'date' => '2023-01-01',
            'due_date' => '2023-02-01',
            'supplier_id' => $this->supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ], $overrides));
    }

    // ==========================================
    // 5.1 Feature/Livewire tests
    // ==========================================

    public function test_route_authorization()
    {
        $this->user->revokePermissionTo('purchaseReports.access');

        $response = $this->actingAs($this->user)->get(route('reports.supplier-payables.index'));
        $response->assertStatus(403);
    }

    public function test_authorized_user_can_access_report()
    {
        $response = $this->actingAs($this->user)->get(route('reports.supplier-payables.index'));
        $response->assertStatus(200);
        $response->assertSeeText('Laporan Hutang Supplier');
    }

    public function test_default_as_of_date()
    {
        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class);

        $this->assertEquals(now()->format('Y-m-d'), $component->get('endDate'));
    }

    public function test_supplier_grouping_subtotals_grand_totals()
    {
        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 50000,
            'due_amount' => 50000,
        ]);

        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 30000,
            'due_amount' => 30000,
        ]);

        $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
            'due_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(3, $purchases);
        
        $grandTotal = $component->viewData('grandTotal');
        $this->assertEquals(180000, $grandTotal);
        
        $grandTotalJumlah = $component->viewData('grandTotalJumlah');
        $this->assertEquals(180000, $grandTotalJumlah);
    }

    public function test_empty_supplier_omission()
    {
        $purchase = $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
            'due_amount' => 0,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 100000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(0, $purchases);
    }

    // ==========================================
    // 5.2 Query service tests
    // ==========================================

    public function test_as_of_balance_replay_excludes_later_payments()
    {
        $purchase = $this->createPurchase([
            'total_amount' => 100000,
            'due_amount' => 100000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 40000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 60000,
            'date' => '2023-01-20',
            'reference' => 'PAY-002',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        // Report as of 2023-01-15: only the first payment counts. Remaining 60k
        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals(60000, $purchases->first()->saldo);
        
        $grandTotal = $component->viewData('grandTotal');
        $this->assertEquals(60000, $grandTotal);

        // Report as of 2023-01-25: fully paid, should not appear
        $component2 = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-25')
            ->call('applyFilters');

        $this->assertCount(0, $component2->viewData('purchases'));
    }

    public function test_invalidated_payments_not_counted()
    {
        $purchase = $this->createPurchase([
            'total_amount' => 100000,
            'due_amount' => 100000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 100000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'INVALIDATED'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals(100000, $purchases->first()->saldo);
    }

    public function test_payment_amount_scaling()
    {
        $purchase = $this->createPurchase([
            'total_amount' => 100000,
            'due_amount' => 100000,
        ]);

        // PurchasePayment setter multiplies by 100, so DB stores 50000*100=5000000
        // Query divides by 100.0, so: 5000000/100 = 50000
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 50000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals(50000, $purchases->first()->saldo);
    }

    public function test_positive_balance_filtering()
    {
        // Fully paid purchase
        $purchase1 = $this->createPurchase([
            'total_amount' => 100000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase1->id,
            'amount' => 100000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        // Partially paid purchase (different supplier so we can identify)
        $purchase2 = $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 50000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier2->id, $purchases->first()->supplier_id);
    }

    public function test_due_date_filtering()
    {
        $p1 = $this->createPurchase([
            'due_date' => '2023-01-10',
            'total_amount' => 50000,
        ]);

        $p2 = $this->createPurchase([
            'due_date' => '2023-01-20',
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-31')
            ->set('dueDateUntil', '2023-01-12')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier->id, $purchases->first()->supplier_id);
    }

    public function test_supplier_filtering()
    {
        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 50000,
        ]);

        $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->set('supplierIds', [$this->supplier2->id])
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier2->id, $purchases->first()->supplier_id);
    }

    public function test_tag_any_filtering()
    {
        $tag = \Spatie\Tags\Tag::findOrCreate('URGENT', 'en');

        $purchase1 = $this->createPurchase([
            'total_amount' => 50000,
        ]);
        $purchase1->attachTag($tag);

        $purchase2 = $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->set('tagIds', [$tag->id])
            ->set('tagLogic', 'Salah satu')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier->id, $purchases->first()->supplier_id);
    }

    public function test_tag_all_filtering()
    {
        $tag1 = \Spatie\Tags\Tag::findOrCreate('URGENT', 'en');
        $tag2 = \Spatie\Tags\Tag::findOrCreate('IMPORTANT', 'en');

        $purchase1 = $this->createPurchase([
            'total_amount' => 50000,
        ]);
        $purchase1->attachTag($tag1);
        $purchase1->attachTag($tag2);

        $purchase2 = $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
        ]);
        $purchase2->attachTag($tag1);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Mencakup semua')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier->id, $purchases->first()->supplier_id);
    }

    // ==========================================
    // 5.3 Sorting tests
    // ==========================================

    public function test_sort_by_supplier_name()
    {
        $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 50000,
        ]);

        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->set('sortField', 'supplier_name')
            ->set('sortDirection', 'asc')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        // Supplier A before Supplier B
        $this->assertEquals($this->supplier->id, $purchases->first()->supplier_id);
    }

    public function test_sort_by_total_balance_desc()
    {
        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 50000,
        ]);

        $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-12-31')
            ->set('sortField', 'total_balance')
            ->set('sortDirection', 'desc')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        // Supplier B (100k) before Supplier A (50k)
        $this->assertEquals($this->supplier2->id, $purchases->first()->supplier_id);
    }

    // ==========================================
    // 5.4 Export tests
    // ==========================================

    public function test_export_xlsx()
    {
        $purchase = $this->createPurchase([
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters')
            ->call('exportExcel');

        $component->assertFileDownloaded('hutang_supplier_2023-01-15.xlsx');
    }

    public function test_export_csv()
    {
        $this->createPurchase([
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters')
            ->call('exportCsv');

        $component->assertFileDownloaded('hutang_supplier_2023-01-15.csv');
    }

    public function test_export_pdf()
    {
        $this->createPurchase([
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters')
            ->call('exportPdf');

        $component->assertFileDownloaded('hutang_supplier_2023-01-15.pdf');
    }

    public function test_export_includes_subtotal_rows_with_both_amounts()
    {
        // Create two purchases from same supplier
        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 50000,
        ]);
        $this->createPurchase([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 30000,
        ]);

        // Create purchase from different supplier
        $this->createPurchase([
            'supplier_id' => $this->supplier2->id,
            'total_amount' => 20000,
        ]);

        $filterData = new \App\Services\Reports\SupplierPayablesReportFilterData(
            endDate: '2023-01-15',
            scopeSettingId: $this->setting->id
        );

        $export = new \App\Exports\SupplierPayablesReportExport(
            $this->buildReportQuery('2023-01-15'),
            $filterData,
            false
        );
        $rows = $export->array();

        // Find subtotal row for Supplier A (should contain both Jumlah and Saldo)
        $subtotalARow = null;
        foreach ($rows as $row) {
            // Subtotal rows have label in column 6 (Keterangan)
            if (is_string($row[5] ?? null) && str_contains($row[5] ?? '', 'Total Hutang')) {
                $subtotalARow = $row;
                break;
            }
        }

        $this->assertNotNull($subtotalARow, 'Subtotal row for Supplier A not found');
        $this->assertEquals(80000.0, $subtotalARow[6] ?? null, 'Supplier A Jumlah should be 80000');
        $this->assertEquals(80000.0, $subtotalARow[7] ?? null, 'Supplier A Saldo should be 80000');

        // Find grand total row (should contain both Jumlah and Saldo)
        $grandTotalRow = null;
        foreach ($rows as $row) {
            if ($row[5] === 'Grand Total') {
                $grandTotalRow = $row;
                break;
            }
        }

        $this->assertNotNull($grandTotalRow, 'Grand Total row not found');
        $this->assertEquals(100000.0, $grandTotalRow[6] ?? null, 'Grand Total Jumlah should be 100000');
        $this->assertEquals(100000.0, $grandTotalRow[7] ?? null, 'Grand Total Saldo should be 100000');
    }

    private function buildReportQuery(string $asOfDate)
    {
        $filterData = new \App\Services\Reports\SupplierPayablesReportFilterData(
            endDate: $asOfDate,
            scopeSettingId: $this->setting->id
        );

        $service = new \App\Services\Reports\SupplierPayablesReportQueryService();
        $query = $service->build($filterData);
        $service->applySort($query, 'supplier_name', 'asc');
        return $query;
    }

    public function test_export_blocked_without_apply()
    {
        $this->createPurchase([
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('exportExcel');

        // Should dispatch alert instead of downloading
        $component->assertDispatched('alert');
    }

    public function test_export_blocked_after_filter_change_without_reapply()
    {
        $this->createPurchase([
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters');

        // Change the filter but don't reapply
        $component->set('endDate', '2023-02-15')
            ->call('exportExcel');

        $component->assertDispatched('alert');
    }

    public function test_tenant_scoping()
    {
        $otherSetting = Setting::create([
            'company_name' => 'Other Company',
            'company_email' => 'other@example.com',
            'company_phone' => '987654321',
            'notification_email' => 'other_notify@example.com',
            'company_address' => 'Other Address',
            'footer_text' => 'Other Footer',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
        ]);

        $otherSupplier = Supplier::create([
            'supplier_name' => 'Other Supplier',
            'supplier_email' => 'other@test.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $otherSetting->id
        ]);

        Purchase::create([
            'date' => '2023-01-01',
            'due_date' => '2023-02-01',
            'supplier_id' => $otherSupplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'APPROVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $otherSetting->id
        ]);

        $myPurchase = $this->createPurchase([
            'total_amount' => 100000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SupplierPayablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->supplier->id, $purchases->first()->supplier_id);
    }
}
