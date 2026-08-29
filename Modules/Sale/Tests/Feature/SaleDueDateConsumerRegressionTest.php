<?php

namespace Modules\Sale\Tests\Feature;

use App\DTOs\DateAdjustmentCommand;
use App\Exports\CustomerReceivablesReportExport;
use App\Livewire\Reports\CustomerReceivablesReport;
use App\Models\User;
use App\Services\DocumentDateAdjustmentService;
use App\Services\Reports\AgedReceivablesReportQueryService;
use App\Services\Reports\CustomerReceivablesReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Livewire\SaleSummaryCards;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleDueDateConsumerRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Customer $customer;
    private DocumentDateAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('sales.due-date.override', 'web');
        Permission::findOrCreate('sales.reporting-date.override', 'web');
        Permission::findOrCreate('sales.show', 'web');
        Permission::findOrCreate('salePayments.create', 'web');
        Permission::findOrCreate('salePayments.global.access', 'web');
        Permission::findOrCreate('saleReports.access', 'web');

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

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Test Customer',
        ]);

        $this->user = User::factory()->create([
            'is_active' => true,
        ]);
        $this->user->givePermissionTo([
            'sales.due-date.override',
            'sales.reporting-date.override',
            'sales.show',
            'salePayments.create',
            'salePayments.global.access',
            'saleReports.access',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->service = app(DocumentDateAdjustmentService::class);
    }

    private function makeSale(array $overrides = []): Sale
    {
        static $ref = 1;
        return Sale::create(array_merge([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_APPROVED,
            'date' => now()->subDays(30)->format('Y-m-d'),
            'due_date' => now()->subDays(5)->format('Y-m-d'),
            'reference' => 'REG-SL-' . ($ref++),
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'paid_amount' => 0,
        ], $overrides));
    }

    /** @test */
    public function test_1_sales_overdue_summary_cards_use_replacement_due_date()
    {
        // 1. Initial sale due yesterday (overdue)
        $sale = $this->makeSale([
            'due_date' => Carbon::yesterday()->format('Y-m-d'),
        ]);

        $component = Livewire::test(SaleSummaryCards::class, ['settingId' => $this->setting->id]);
        $this->assertEquals(1, $component->get('piutangTelat')['count']);

        // 2. Adjust due date to 10 days in future
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: now()->addDays(10)->format('Y-m-d'),
            reason: 'Extended customer due date',
        ), $this->user);

        // 3. Verify overdue summary card no longer counts it
        $component = Livewire::test(SaleSummaryCards::class, ['settingId' => $this->setting->id]);
        $this->assertEquals(0, $component->get('piutangTelat')['count']);
    }

    /** @test */
    public function test_2_sales_list_due_date_filtering_uses_replacement_due_date()
    {
        // Sale originally due next month
        $originalDueDate = now()->addMonth()->format('Y-m-d');
        $sale = $this->makeSale(['due_date' => $originalDueDate]);

        // Adjust due date to yesterday
        $newDueDate = Carbon::yesterday()->format('Y-m-d');
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Accelerated sale due date',
        ), $this->user);

        // Query sales filtering by new due date range
        $results = Sale::query()
            ->where('setting_id', $this->setting->id)
            ->whereBetween('due_date', [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()])
            ->get();

        $this->assertTrue($results->contains($sale));
    }

    /** @test */
    public function test_3_sales_payment_presentation_uses_replacement_due_date()
    {
        $sale = $this->makeSale(['due_date' => '2026-05-01']);

        $newDueDate = '2026-06-20';
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Sale payment presentation test',
        ), $this->user);

        $response = $this->get(route('sales.global-payments.create', $sale->id));
        $response->assertStatus(200);
        $response->assertSee(Carbon::parse($newDueDate)->format('d M Y'));
    }

    /** @test */
    public function test_4_customer_receivables_filtering_uses_replacement_due_date()
    {
        $sale = $this->makeSale([
            'date' => now()->subDays(10)->format('Y-m-d'),
            'due_date' => now()->addDays(60)->format('Y-m-d'),
        ]);

        // 1. Filter up to next 15 days does NOT contain sale
        Livewire::test(CustomerReceivablesReport::class)
            ->set('dueDateUntil', now()->addDays(15)->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return !$sales->pluck('id')->contains($sale->id);
            });

        // 2. Adjust due date to 5 days from now
        $newDueDate = now()->addDays(5)->format('Y-m-d');
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Customer receivables adjustment',
        ), $this->user);

        // 3. Verify filter up to next 15 days NOW contains sale
        Livewire::test(CustomerReceivablesReport::class)
            ->set('dueDateUntil', now()->addDays(15)->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) use ($sale) {
                return $sales->pluck('id')->contains($sale->id);
            });
    }

    /** @test */
    public function test_5_customer_receivables_display_uses_replacement_due_date()
    {
        $sale = $this->makeSale([
            'due_date' => '2026-02-01',
        ]);

        $newDueDate = '2026-09-10';
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Customer receivables display test',
        ), $this->user);

        $mapped = CustomerReceivablesReportQueryService::mapRows($sale->fresh());
        $this->assertEquals($newDueDate . ' 00:00:00', Carbon::parse($mapped['Jatuh Tempo'])->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function test_6_customer_receivables_exports_use_replacement_due_date()
    {
        $sale = $this->makeSale([
            'due_date' => '2026-01-01',
        ]);

        $newDueDate = '2026-10-15';
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Customer receivables export test',
        ), $this->user);

        $filter = new \App\Services\Reports\CustomerReceivablesReportFilterData(
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            customerIds: [$this->customer->id]
        );

        $query = app(CustomerReceivablesReportQueryService::class)->build($filter);
        $export = new CustomerReceivablesReportExport($query, $filter);
        $exportRows = $export->array();

        // Check that one of the export data rows has formatted due date 15/10/2026
        $found = false;
        foreach ($exportRows as $row) {
            if (isset($row[4]) && $row[4] === '15/10/2026') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Export array should contain the formatted replacement due date 15/10/2026.');
    }

    /** @test */
    public function test_7_transaction_date_based_aged_receivables_remains_unchanged_after_due_date_adjustment()
    {
        // Sale date is 45 days ago (falling in 31-60 days bucket based on transaction date)
        $sale = $this->makeSale([
            'date' => now()->subDays(45)->format('Y-m-d'),
            'due_date' => now()->subDays(10)->format('Y-m-d'),
            'total_amount' => 3000,
            'due_amount' => 3000,
        ]);

        $queryService = app(AgedReceivablesReportQueryService::class);
        $filter = new \App\Services\Reports\AgedReceivablesReportFilterData(
            asOfDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            customerIds: [$this->customer->id]
        );

        // Get initial aged receivables snapshot
        $rowBefore = $queryService->build($filter)->first();
        $this->assertEquals(3000, $rowBefore->bucket_2); // 31-60 days bucket based on sales.date

        // Adjust due_date into the far future (+90 days)
        $newDueDate = now()->addDays(90)->format('Y-m-d');
        $this->service->adjustDates($sale, new DateAdjustmentCommand(
            reportingAction: 'keep',
            reportingDate: null,
            dueDateAction: 'set',
            dueDate: $newDueDate,
            reason: 'Aged receivables immutability check',
        ), $this->user);

        // Verify that transaction-date-based Aged Receivables buckets remain COMPLETELY UNCHANGED
        $rowAfter = $queryService->build($filter)->first();
        $this->assertEquals(3000, $rowAfter->bucket_2); // Still 31-60 days bucket
        $this->assertEquals(0, $rowAfter->bucket_1);
    }
}
