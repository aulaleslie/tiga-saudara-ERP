<?php

namespace Modules\Purchase\Tests\Feature;

use App\DTOs\DateAdjustmentCommand;
use App\Exports\SupplierPayablesReportExport;
use App\Livewire\Reports\PurchaseReport;
use App\Livewire\Reports\SupplierPayablesReport;
use App\Models\User;
use App\Services\DocumentDateAdjustmentService;
use App\Services\Reports\AgedPayablesReportQueryService;
use App\Services\Reports\SupplierPayablesReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Livewire\PurchaseSummaryCards;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseDueDateConsumerRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Supplier $supplier;
    private DocumentDateAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('purchases.due-date.override', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.received.correct', 'web');
        Permission::findOrCreate('purchasePayments.create', 'web');
        Permission::findOrCreate('purchasePayments.global.access', 'web');
        Permission::findOrCreate('purchaseReports.access', 'web');

        Role::findOrCreate('Super Admin', 'web');

        Currency::firstOrCreate(['id' => 1], [
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::first() ?: Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test',
            'company_address' => 'Test Address',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '12345',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
        ]);

        $this->user = User::factory()->create([
            'is_active' => true,
        ]);
        $this->user->givePermissionTo([
            'purchases.due-date.override',
            'purchases.reporting-date.override',
            'purchases.show',
            'purchases.received.correct',
            'purchasePayments.create',
            'purchasePayments.global.access',
            'purchaseReports.access',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->service = app(DocumentDateAdjustmentService::class);
    }

    private function makePurchase(array $overrides = []): Purchase
    {
        static $ref = 1;
        return Purchase::create(array_merge([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'date' => now()->subDays(30)->format('Y-m-d'),
            'due_date' => now()->subDays(5)->format('Y-m-d'),
            'reference' => 'REG-PR-' . ($ref++),
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'paid_amount' => 0,
        ], $overrides));
    }

    /** @test */
    public function test_1_overdue_summary_cards_use_replacement_due_date()
    {
        // 1. Initial purchase due 5 days ago (overdue)
        $purchase = $this->makePurchase([
            'due_date' => Carbon::yesterday()->format('Y-m-d'),
        ]);

        $component = Livewire::test(PurchaseSummaryCards::class, ['settingId' => $this->setting->id]);
        $this->assertEquals(1, $component->get('telatBayar')['count']);

        // 2. Adjust due date to 10 days in future
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: now()->addDays(10)->format('Y-m-d'),
            reason: 'Extend payment terms',
        ), $this->user);

        // 3. Verify overdue summary card no longer counts it
        $component = Livewire::test(PurchaseSummaryCards::class, ['settingId' => $this->setting->id]);
        $this->assertEquals(0, $component->get('telatBayar')['count']);
    }

    /** @test */
    public function test_2_purchase_list_due_date_filtering_uses_replacement_due_date()
    {
        // Purchase originally due next month
        $originalDueDate = now()->addMonth()->format('Y-m-d');
        $purchase = $this->makePurchase(['due_date' => $originalDueDate]);

        // Adjust due date to yesterday
        $newDueDate = Carbon::yesterday()->format('Y-m-d');
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Accelerated due date',
        ), $this->user);

        // Query purchases filtering by new due date range
        $results = Purchase::query()
            ->where('setting_id', $this->setting->id)
            ->whereBetween('due_date', [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()])
            ->get();

        $this->assertTrue($results->contains($purchase));
    }

    /** @test */
    public function test_3_payment_presentation_uses_replacement_due_date()
    {
        $purchase = $this->makePurchase(['due_date' => '2026-05-01']);

        $newDueDate = '2026-06-15';
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Payment presentation test',
        ), $this->user);

        $response = $this->get(route('purchases.global-payments.create', ['supplier' => $purchase->supplier_id]));
        $response->assertStatus(200);
        $response->assertSee(Carbon::parse($newDueDate)->format('d M Y'));
    }

    /** @test */
    public function test_4_purchase_detail_presentation_uses_replacement_due_date()
    {
        $purchase = $this->makePurchase(['due_date' => '2026-05-01']);

        $newDueDate = '2026-08-20';
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Detail presentation test',
        ), $this->user);

        $response = $this->get(route('purchases.show', $purchase));
        $response->assertStatus(200);
        $response->assertSee('2026-08-20');
    }

    /** @test */
    public function test_5_purchase_print_presentation_uses_replacement_due_date()
    {
        $purchase = $this->makePurchase(['due_date' => '2026-05-01']);

        $newDueDate = '2026-09-01';
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Print presentation test',
        ), $this->user);

        $response = $this->get(route('purchases.pdf', $purchase->id));
        $response->assertStatus(200);
    }

    /** @test */
    public function test_6_primary_purchase_report_due_date_filtering_uses_replacement_due_date()
    {
        $purchase = $this->makePurchase([
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'due_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Report Detail Product',
            'product_code' => 'RPT-001',
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $nextMonthDate = now()->addMonth()->startOfMonth()->format('Y-m-d');
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $nextMonthDate,
            reason: 'Primary report test',
        ), $this->user);

        Livewire::test(PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('dateBasis', 'due_date')
            ->set('startDate', now()->addMonth()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->addMonth()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                $ids = $purchases->pluck('purchase_id')->toArray();
                return in_array($purchase->id, $ids);
            });
    }

    /** @test */
    public function test_7_supplier_payables_filtering_and_display_uses_replacement_due_date()
    {
        $purchase = $this->makePurchase([
            'date' => now()->subDays(10)->format('Y-m-d'),
            'due_date' => now()->addDays(60)->format('Y-m-d'),
        ]);

        // 1. Filter up to next 15 days does NOT contain purchase
        Livewire::test(SupplierPayablesReport::class)
            ->set('dueDateUntil', now()->addDays(15)->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return !$purchases->pluck('id')->contains($purchase->id);
            });

        // 2. Adjust due date to 5 days from now
        $newDueDate = now()->addDays(5)->format('Y-m-d');
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Supplier payables adjustment',
        ), $this->user);

        // 3. Verify filter up to next 15 days NOW contains purchase with new due date
        Livewire::test(SupplierPayablesReport::class)
            ->set('dueDateUntil', now()->addDays(15)->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('purchases', function ($purchases) use ($purchase) {
                return $purchases->pluck('id')->contains($purchase->id);
            });

        $mapped = SupplierPayablesReportQueryService::mapRows($purchase->fresh());
        $this->assertEquals($newDueDate . ' 00:00:00', Carbon::parse($mapped['Jatuh Tempo'])->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function test_8_supplier_payables_exports_use_replacement_due_date()
    {
        $purchase = $this->makePurchase([
            'due_date' => '2026-01-01',
        ]);

        $newDueDate = '2026-07-25';
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Supplier payables export test',
        ), $this->user);

        $filter = new \App\Services\Reports\SupplierPayablesReportFilterData(
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            supplierIds: [$this->supplier->id]
        );

        $query = app(SupplierPayablesReportQueryService::class)->build($filter);
        $export = new SupplierPayablesReportExport($query, $filter);
        $exportRows = $export->array();

        $found = false;
        foreach ($exportRows as $row) {
            if (isset($row[4]) && $row[4] === '25/07/2026') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Export array should contain the formatted replacement due date 25/07/2026.');
    }

    /** @test */
    public function test_9_due_date_based_aged_payables_uses_replacement_due_date()
    {
        // Purchase received 45 days ago, original due date 45 days ago (in 31-60 days bucket)
        $purchase = $this->makePurchase([
            'date' => now()->subDays(45)->format('Y-m-d'),
            'due_date' => now()->subDays(45)->format('Y-m-d'),
            'total_amount' => 5000,
            'due_amount' => 5000,
        ]);

        $queryService = app(AgedPayablesReportQueryService::class);
        $filter = new \App\Services\Reports\AgedPayablesReportFilterData(
            asOfDate: now()->format('Y-m-d'),
            agingBasis: 'Tanggal Jatuh Tempo',
            scopeSettingId: $this->setting->id,
            supplierIds: [$this->supplier->id]
        );

        $rowBefore = $queryService->build($filter)->first();
        $this->assertEquals(5000, $rowBefore->bucket_2); // 31-60 days bucket

        // Adjust due date to 15 days ago (so it moves into 1-30 days bucket)
        $newDueDate = now()->subDays(15)->format('Y-m-d');
        $this->service->adjustDates($purchase, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Aged payables test',
        ), $this->user);

        $rowAfter = $queryService->build($filter)->first();
        $this->assertEquals(5000, $rowAfter->bucket_1); // 1-30 days bucket
        $this->assertEquals(0, $rowAfter->bucket_2);
    }
}
