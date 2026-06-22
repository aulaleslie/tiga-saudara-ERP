<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Services\Reports\PurchaseOrderCompletionReportFilterData;
use App\Services\Reports\PurchaseOrderCompletionReportValidator;
use App\Services\Reports\PurchaseOrderCompletionReportSnapshotService;
use App\Services\Reports\PurchaseOrderCompletionReportQueryService;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\Product;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;
use App\Livewire\Reports\PurchaseOrderCompletionReport;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PurchaseOrderCompletionReportExport;

class PurchaseOrderCompletionReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
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
        $this->user->givePermissionTo('purchaseReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    /** @test */
    public function it_can_render_the_purchase_order_completion_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-order-completion.index'));
        $response->assertStatus(200);
        $response->assertSee('Penyelesaian Pemesanan Pembelian');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.purchase-order-completion.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_displays_the_actionable_landing_card_on_the_reports_page()
    {
        Permission::firstOrCreate(['name' => 'reports.access']);
        $this->user->givePermissionTo('reports.access');

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.index', ['tab' => 'pembelian']));
        $response->assertStatus(200);
        $response->assertSee('Penyelesaian pesanan pembelian');
        $response->assertSee(route('reports.purchase-order-completion.index'));
    }

    /** @test */
    public function it_normalizes_filter_data_and_computes_hash()
    {
        $data = [
            'startDate' => '2023-01-01',
            'endDate' => '2023-01-31',
            'sourceStage' => 'Penawaran',
            'supplierIds' => ['1', '2'],
            'tagLogic' => 'invalid_logic',
            'isGlobal' => '1',
            'scopeSettingId' => '5',
        ];

        $filter = PurchaseOrderCompletionReportFilterData::fromArray($data);

        $this->assertEquals('2023-01-01', $filter->startDate);
        $this->assertEquals('2023-01-31', $filter->endDate);
        $this->assertEquals('Penawaran', $filter->sourceStage);
        $this->assertSame([1, 2], $filter->supplierIds);
        $this->assertEquals('any', $filter->tagLogic); // Fallback to any
        $this->assertTrue($filter->isGlobal);
        $this->assertSame(5, $filter->scopeSettingId);

        $hash = $filter->hash();
        $this->assertNotEmpty($hash);
        
        $filter2 = PurchaseOrderCompletionReportFilterData::fromArray($data);
        $this->assertEquals($hash, $filter2->hash());
    }

    /** @test */
    public function it_validates_filter_data()
    {
        $validator = new PurchaseOrderCompletionReportValidator();

        $validData = [
            'startDate' => '2023-01-01',
            'endDate' => '2023-01-31',
            'sourceStage' => 'Pemesanan',
        ];
        
        $validated = $validator->validate($validData);
        $this->assertEquals('2023-01-01', $validated['startDate']);

        $invalidData = [
            'startDate' => '2023-01-31',
            'endDate' => '2023-01-01', // End before start
        ];

        $this->expectException(ValidationException::class);
        $validator->validate($invalidData);
    }

    /** @test */
    public function it_creates_and_validates_snapshots()
    {
        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: '2023-01-01',
            endDate: '2023-01-31',
            sourceStage: 'Pemesanan'
        );

        $service = new PurchaseOrderCompletionReportSnapshotService();
        $snapshot = $service->createSnapshot($filter, 10);

        $this->assertEquals(10, $snapshot->resultCount);
        $this->assertEquals($filter->hash(), $snapshot->validatedFilterHash);
        
        $this->assertTrue($service->isValidForExport($filter));

        $changedFilter = new PurchaseOrderCompletionReportFilterData(
            startDate: '2023-01-01',
            endDate: '2023-02-28', // Changed
            sourceStage: 'Pemesanan'
        );

        $this->assertFalse($service->isValidForExport($changedFilter));
        
        $service->invalidate();
        $this->assertFalse($service->isValidForExport($filter));
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
            'due_date' => now()->addDays(30)->format('Y-m-d'),
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

    /** @test */
    public function it_filters_by_source_stage_correctly()
    {
        $supplier = $this->makeSupplier();
        
        $draftPurchase = $this->makePurchase($supplier, ['status' => Purchase::STATUS_DRAFTED]);
        $waitingPurchase = $this->makePurchase($supplier, ['status' => Purchase::STATUS_WAITING_APPROVAL]);
        $approvedPurchase = $this->makePurchase($supplier, ['status' => Purchase::STATUS_APPROVED]);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Penawaran'
        );

        $queryService = new PurchaseOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($draftPurchase->id, $result[0]->id);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );
        $result = $queryService->build($filter)->get();

        $this->assertCount(2, $result);
        $this->assertFalse($result->contains('id', $draftPurchase->id));
    }

    /** @test */
    public function it_does_not_fall_back_to_header_if_invalidated_payments_exist()
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->makePurchase($supplier, [
            'total_amount' => 1000, 
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => Purchase::STATUS_APPROVED
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 500, // This sets it to 50000 cents in DB
            'payment_method_id' => 1,
            'payment_method' => 'Cash',
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-1',
            'status' => PurchasePayment::STATUS_INVALIDATED
        ]);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new PurchaseOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->first();

        // Payment rows exist (even if invalidated), so payment_count > 0, does not fall back to purchases.paid_amount (1000)
        $this->assertEquals(0, $result->derived_active_paid);
    }

    /** @test */
    public function it_calculates_amounts_correctly_including_deliveries_and_payments()
    {
        $supplier = $this->makeSupplier();
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => "Product 1",
            'product_code' => "PRD-1",
            'product_cost' => 500,
            'product_price' => 1000,
        ]);

        $purchase = $this->makePurchase($supplier, ['total_amount' => 1500, 'status' => Purchase::STATUS_APPROVED]);
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
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

        $rn = ReceivedNote::create([
            'date' => now()->format('Y-m-d'),
            'status' => 'APPROVED',
            'po_id' => $purchase->id,
            'reference' => 'RN-1',
            'supplier_id' => $supplier->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $detail->id,
            'quantity_ordered' => 2,
            'quantity_received' => 1,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 500,
            'payment_method_id' => 1,
            'payment_method' => 'Cash',
            'date' => now()->format('Y-m-d'),
            'reference' => 'PAY-1',
            'status' => PurchasePayment::STATUS_ACTIVE
        ]);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new PurchaseOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals(1500, $result[0]->total_amount);
        $this->assertEquals(750, $result[0]->derived_delivery_amount); // 1 received * 750 unit amount
        $this->assertEquals(1500, $result[0]->derived_invoice_amount);
        $this->assertEquals(500, $result[0]->derived_active_paid);
    }

    /** @test */
    public function it_maps_order_status_correctly()
    {
        $queryService = new PurchaseOrderCompletionReportQueryService();

        $row1 = new Purchase();
        $row1->status = Purchase::STATUS_APPROVED;
        $row1->derived_active_paid = 0;
        $row1->derived_invoice_amount = 0;
        $mapped1 = $queryService->mapRow($row1);
        $this->assertEquals('Belum Dibayar', $mapped1['Status Pemesanan']);

        $row2 = new Purchase();
        $row2->status = Purchase::STATUS_RECEIVED_PARTIALLY;
        $row2->derived_active_paid = 500;
        $row2->derived_invoice_amount = 1000;
        $mapped2 = $queryService->mapRow($row2);
        $this->assertEquals('Terbayar Sebagian', $mapped2['Status Pemesanan']);

        $row3 = new Purchase();
        $row3->status = Purchase::STATUS_RECEIVED;
        $row3->derived_active_paid = 1000;
        $row3->derived_invoice_amount = 1000;
        $mapped3 = $queryService->mapRow($row3);
        $this->assertEquals('Selesai', $mapped3['Status Pemesanan']);
    }

    /** @test */
    public function it_filters_by_supplier_and_tags_correctly()
    {
        $supplier1 = $this->makeSupplier('Sup 1');
        $supplier2 = $this->makeSupplier('Sup 2');

        $tag = Tag::findOrCreate('TestTag', 'en');
        $purchase1 = $this->makePurchase($supplier1);
        $purchase1->attachTag($tag);

        $purchase2 = $this->makePurchase($supplier2);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan',
            supplierIds: [$supplier1->id],
            tagIds: [$tag->id]
        );

        $queryService = new PurchaseOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($purchase1->id, $result[0]->id);
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

        $supplier1 = $this->makeSupplier('S1', $this->setting->id);
        $supplier2 = $this->makeSupplier('S2', $setting2->id);

        $this->makePurchase($supplier1, ['setting_id' => $this->setting->id]);
        $this->makePurchase($supplier2, ['setting_id' => $setting2->id]);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new PurchaseOrderCompletionReportQueryService();
        $result = $queryService->build($filter)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($this->setting->id, $result[0]->setting_id);
    }

    /** @test */
    public function livewire_component_validates_and_applies_filters()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Livewire::test(PurchaseOrderCompletionReport::class)
            ->set('startDate', '2023-01-31')
            ->set('endDate', '2023-01-01') // Invalid date range
            ->call('applyFilters')
            ->assertHasErrors(['endDate']);

        Livewire::test(PurchaseOrderCompletionReport::class)
            ->set('startDate', '2023-01-01')
            ->set('endDate', '2023-01-31')
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertSet('filterTriggered', true);
    }

    /** @test */
    public function livewire_component_resets_filters()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Livewire::test(PurchaseOrderCompletionReport::class)
            ->set('startDate', '2022-01-01')
            ->set('supplierIds', [1, 2])
            ->call('resetFilters')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('supplierIds', []);
    }

    /** @test */
    public function it_exports_data_successfully()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $filter = new PurchaseOrderCompletionReportFilterData(
            startDate: now()->startOfMonth()->format('Y-m-d'),
            endDate: now()->endOfMonth()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan'
        );

        $queryService = new PurchaseOrderCompletionReportQueryService();
        $snapshotService = new PurchaseOrderCompletionReportSnapshotService();
        $snapshotService->createSnapshot($filter, 0);

        Livewire::test(PurchaseOrderCompletionReport::class)
            ->set('startDate', $filter->startDate)
            ->set('endDate', $filter->endDate)
            ->set('sourceStage', $filter->sourceStage)
            ->set('filterTriggered', true)
            ->set('appliedFilters', [
                'startDate' => $filter->startDate,
                'endDate' => $filter->endDate,
                'sourceStage' => $filter->sourceStage,
                'tagLogic' => 'any',
            ])
            ->call('exportExcel');

        Excel::assertDownloaded("purchase_order_completion_{$filter->startDate}_{$filter->endDate}.xlsx", function (PurchaseOrderCompletionReportExport $export) {
            return true;
        });
    }

    /** @test */
    public function it_blocks_export_when_snapshot_is_invalid()
    {
        Excel::fake();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        Livewire::test(PurchaseOrderCompletionReport::class)
            ->set('startDate', '2023-01-01')
            ->set('endDate', '2023-01-31')
            ->set('filterTriggered', true)
            ->set('appliedFilters', [
                'startDate' => '2023-01-01',
                'endDate' => '2023-01-31',
                'tagLogic' => 'any',
            ])
            ->call('exportExcel')
            ->assertDispatched('alert');
    }
}
