<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\PurchaseDeliveryReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PurchaseDeliveryReportExport;
use App\Services\Reports\PurchaseDeliveryReportFilterData;
use App\Services\Reports\PurchaseDeliveryReportQueryService;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;

class PurchaseDeliveryReportTest extends TestCase
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
        
        session(['setting_id' => $this->setting->id]);
    }

    public function test_can_access_report_page_with_permission()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.purchase-delivery.index'));

        $response->assertStatus(200);
        $response->assertSeeLivewire('reports.purchase-delivery-report');
    }

    public function test_cannot_access_report_page_without_permission()
    {
        $this->user->revokePermissionTo('purchaseReports.access');

        $response = $this->actingAs($this->user)
            ->get(route('reports.purchase-delivery.index'));

        $response->assertStatus(403);
    }

    public function test_mount_initializes_default_values_without_triggering_query()
    {
        $component = Livewire::actingAs($this->user)
            ->test(PurchaseDeliveryReport::class);

        $component->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->assertSet('filterTriggered', false);
    }

    public function test_supplier_search()
    {
        Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
        ]);

        Livewire::actingAs($this->user)
            ->test(PurchaseDeliveryReport::class)
            ->set('supplierSearch', 'Test')
            ->assertSet('supplierSearch', 'Test')
            ->assertCount('supplierOptions', 1);
    }

    public function test_applying_valid_filters_triggers_query_and_creates_snapshot()
    {
        $component = Livewire::actingAs($this->user)
            ->test(PurchaseDeliveryReport::class);

        $component->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true);

        // Snapshot is created in session
        $this->assertNotNull(session('purchase_delivery_report_snapshot'));
    }

    public function test_excel_export_success_with_valid_snapshot()
    {
        Excel::fake();

        $component = Livewire::actingAs($this->user)
            ->test(PurchaseDeliveryReport::class)
            ->call('applyFilters');

        $component->call('exportExcel');

        Excel::assertDownloaded('purchase_delivery_' . now()->startOfMonth()->format('Y-m-d') . '_' . now()->endOfMonth()->format('Y-m-d') . '.xlsx', function(PurchaseDeliveryReportExport $export) {
            return true;
        });
    }

    public function test_csv_export_success_with_valid_snapshot()
    {
        Excel::fake();

        $component = Livewire::actingAs($this->user)
            ->test(PurchaseDeliveryReport::class)
            ->call('applyFilters');

        $component->call('exportCsv');

        Excel::assertDownloaded('purchase_delivery_' . now()->startOfMonth()->format('Y-m-d') . '_' . now()->endOfMonth()->format('Y-m-d') . '.csv', function(PurchaseDeliveryReportExport $export) {
            return true;
        });
    }

    public function test_export_prevention_when_snapshot_is_stale()
    {
        Excel::fake();

        $component = Livewire::actingAs($this->user)
            ->test(PurchaseDeliveryReport::class)
            ->call('applyFilters');

        // Modify filter but don't apply
        $component->set('startDate', now()->subMonth()->startOfMonth()->format('Y-m-d'));

        $component->call('exportExcel')
            ->assertDispatched('alert');
    }

    public function test_query_service_groups_multiple_receivings_and_excludes_unapproved()
    {
        $supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier A',
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $supplier->id,
            'date' => now()->format('Y-m-d'),
            'reference' => 'PO-001',
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'due_date' => now()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Product A',
            'product_code' => 'PRD-A',
            'product_price' => 1000,
            'product_cost' => 1000,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'Product A',
            'product_code' => 'PRD-A',
            'price' => 100,
            'unit_price' => 100,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'quantity' => 10,
            'sub_total' => 1000
        ]);

        // Approved receiving 1: 3 items
        $receivedNote1 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
            'location_id' => 1,
            'approved_at' => now(),
            'approved_by' => $this->user->id
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote1->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 3
        ]);

        // Approved receiving 2: 2 items
        $receivedNote2 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
            'location_id' => 1,
            'approved_at' => now(),
            'approved_by' => $this->user->id
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote2->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 2
        ]);

        // Pending receiving: should be excluded
        $receivedNote3 = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->format('Y-m-d'),
            'status' => 'PENDING',
            'location_id' => 1,
        ]);
        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote3->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 5
        ]);

        $filter = new PurchaseDeliveryReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $service = new PurchaseDeliveryReportQueryService();
        $query = $service->build($filter);
        $results = $query->get();

        // Should group into exactly 1 row with 5 delivered items (3 + 2, excluding 5 pending)
        $this->assertCount(1, $results);
        $row = $results->first();
        $this->assertEquals(5, $row->delivered_quantity);
        $this->assertEquals(500, $row->delivered_amount);
    }

    public function test_export_row_contents_are_correct()
    {
        $supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier A',
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $supplier->id,
            'date' => now()->format('Y-m-d'),
            'reference' => 'PO-001',
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'due_date' => now()->format('Y-m-d'),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Product A',
            'product_code' => 'PRD-A',
            'product_price' => 1000,
            'product_cost' => 1000,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'Product A',
            'product_code' => 'PRD-A',
            'price' => 100,
            'unit_price' => 100,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'quantity' => 10,
            'sub_total' => 1000
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
            'location_id' => 1,
            'approved_at' => now(),
            'approved_by' => $this->user->id
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 5
        ]);

        $filter = new PurchaseDeliveryReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $service = new PurchaseDeliveryReportQueryService();
        $query = $service->build($filter);

        $export = new PurchaseDeliveryReportExport($query, $filter, false);
        $collection = $export->collection();

        // 1 row + 1 subtotal + 1 grand total
        $this->assertCount(3, $collection);
        
        $firstRow = $export->map($collection->first());
        $this->assertEquals('SUPPLIER A - PRD-A', $firstRow['Supplier & Kode produk / SKU']);
        $this->assertEquals('PRODUCT A', $firstRow['Nama produk']);
        $this->assertEquals(5, $firstRow['Qty']);
        $this->assertEquals(500, $firstRow['Jumlah']);

        $subtotalRow = $export->map($collection->get(1));
        $this->assertEquals('Subtotal SUPPLIER A', $subtotalRow['Supplier & Kode produk / SKU']);
        $this->assertEquals(500, $subtotalRow['Jumlah']);

        $grandTotalRow = $export->map($collection->get(2));
        $this->assertEquals('Total Keseluruhan', $grandTotalRow['Supplier & Kode produk / SKU']);
        $this->assertEquals(500, $grandTotalRow['Jumlah']);
    }
}
