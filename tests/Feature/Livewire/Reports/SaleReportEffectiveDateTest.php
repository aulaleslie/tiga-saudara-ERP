<?php

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\SaleReport;
use App\Services\Reports\SaleReportFilterData;
use App\Services\Reports\SaleReportQueryService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleReportEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'sales.access']);
        Permission::firstOrCreate(['name' => 'sales.show']);
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
        $this->user->givePermissionTo('sales.access');
        $this->user->givePermissionTo('sales.show');

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
            'reference' => 'TEST-' . uniqid(),
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
        ]);
    }

    public function test_sale_report_uses_effective_date_for_filtering()
    {
        // Sale in January, no override -> belongs in Jan
        $this->createSale('2026-01-15', null);

        // Sale in January, overridden to Feb -> belongs in Feb
        $this->createSale('2026-01-20', '2026-02-15');

        // Sale in March, overridden to Feb -> belongs in Feb
        $this->createSale('2026-03-10', '2026-02-20');

        $filter = new SaleReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            reportMode: 'header',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleReportQueryService();
        $query = $service->build($filter);

        // Should see the two sales shifted to Feb, but not the Jan one.
        $this->assertCount(2, $query->get());
    }

    public function test_sale_report_sorting_uses_effective_date()
    {
        // 2nd
        $sale1 = $this->createSale('2026-01-20', '2026-02-15');
        // 3rd
        $sale2 = $this->createSale('2026-01-10', '2026-02-20');
        // 1st
        $sale3 = $this->createSale('2026-01-25', '2026-02-05');

        $filter = new SaleReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            reportMode: 'header',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleReportQueryService();
        $query = $service->build($filter);
        $service->applySort($query, 'date', 'asc', 'header');

        $data = $query->get();
        
        // Ensure they are sorted by effective date: 05, 15, 20
        $this->assertEquals($sale3->id, $data[0]->id);
        $this->assertEquals($sale1->id, $data[1]->id);
        $this->assertEquals($sale2->id, $data[2]->id);
    }

    public function test_sale_report_rendered_date_and_exports_use_effective_date()
    {
        $sale = $this->createSale('2026-01-20', '2026-02-15');

        $filter = new SaleReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            reportMode: 'header',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleReportQueryService();
        $query = $service->build($filter);

        $data = $query->get();
        $this->assertCount(1, $data);

        // Verify mapped row has correct rendered date
        $headings = \App\Services\Reports\SaleReportQueryService::headingsFor('header');
        $mappedRow = \App\Services\Reports\SaleReportQueryService::mapRow($data[0], 'header');
        
        $this->assertEquals('15/02/2026', $mappedRow['Tanggal']);
    }

    public function test_sale_report_cleared_override_falls_back_to_original_date()
    {
        $sale = $this->createSale('2026-01-20', '2026-02-15');

        $filter = new SaleReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            reportMode: 'header',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleReportQueryService();
        $this->assertCount(1, $service->build($filter)->get());

        // Clear override
        $sale->update(['reporting_date' => null]);

        // Should no longer appear in Feb
        $this->assertCount(0, $service->build($filter)->get());

        // Should appear in Jan
        $filterJan = new SaleReportFilterData(
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            reportMode: 'header',
            scopeSettingId: $this->setting->id
        );
        $this->assertCount(1, $service->build($filterJan)->get());
    }
}
