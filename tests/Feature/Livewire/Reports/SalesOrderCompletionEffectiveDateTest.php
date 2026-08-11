<?php

namespace Tests\Feature\Livewire\Reports;

use App\Services\Reports\SalesOrderCompletionReportFilterData;
use App\Services\Reports\SalesOrderCompletionReportQueryService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesOrderCompletionEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();

        $this->user = User::factory()->create();
        Role::firstOrCreate(['name' => 'Staff']);
        $this->user->assignRole('Staff');
        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
    }

    private function createSale(string $originalDate, ?string $reportingDate = null, int $amount = 1000): Sale
    {
        return Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-ORD-' . uniqid(),
            'customer_name' => 'Test Customer',
            'date' => Carbon::parse($originalDate),
            'reporting_date' => $reportingDate ? Carbon::parse($reportingDate) : null,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
            'is_tax_included' => false,
            'tax_amount' => 0,
        ]);
    }

    public function test_sales_order_completion_report_uses_effective_date()
    {
        // Originally in Jan, but overridden to Feb
        $sale1 = $this->createSale('2026-01-15', '2026-02-15', 1000);
        
        // Originally in March, overridden to Feb
        $sale2 = $this->createSale('2026-03-10', '2026-02-20', 2000);

        // In Feb, without override
        $sale3 = $this->createSale('2026-02-10', null, 3000);

        // In Jan, without override
        $sale4 = $this->createSale('2026-01-20', null, 4000);

        // Fetch for Feb
        $filter = new SalesOrderCompletionReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingId: $this->setting->id,
            sourceStage: 'Pemesanan',
            isGlobal: false
        );
        $service = new SalesOrderCompletionReportQueryService();
        $query = $service->build($filter);
        
        $service->applySort($query, 'date', 'asc');
        $results = $query->get();

        // Should include sale1, sale2, sale3
        $this->assertCount(3, $results);

        // Sorted by effective date asc
        $this->assertEquals($sale3->id, $results[0]->id); // Feb 10
        $this->assertEquals($sale1->id, $results[1]->id); // Feb 15
        $this->assertEquals($sale2->id, $results[2]->id); // Feb 20

        // Test Mapping
        $row = SalesOrderCompletionReportQueryService::mapRow($results[1]); // sale1
        $this->assertEquals('15/02/2026', $row['Tanggal Pemesanan']);
    }
}
