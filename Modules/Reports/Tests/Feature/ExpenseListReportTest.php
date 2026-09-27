<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Tags\Tag;
use Livewire\Livewire;
use App\Livewire\Reports\ExpenseListReport;
use App\Services\Reports\ExpenseListReportFilterData;
use App\Services\Reports\ExpenseListReportQueryService;

class ExpenseListReportTest extends TestCase
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

        $this->user = \App\Models\User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('purchaseReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    public function test_expense_list_report_renders()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.expense-list.index'));
        $response->assertStatus(200);
    }

    public function test_query_service_filters_correctly()
    {
        session(['setting_id' => $this->setting->id]);
        
        $category = ExpenseCategory::create(['category_name' => 'Test Category']);
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'a@b.com',
            'supplier_phone' => '123',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country'
        ]);
        
        $expense1 = Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 1000,
            'status' => 'APPROVED',
            'is_tax_included' => false,
        ]);
        
        $tag = Tag::findOrCreate('TestTag', 'en');
        $expense1->attachTag($tag);

        $expense2 = Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 2000,
            'status' => 'DRAFT', // Should not be included
            'is_tax_included' => false,
        ]);

        $filter = new ExpenseListReportFilterData(
            startDate: now()->subDays(7)->format('Y-m-d'),
            endDate: now()->addDays(7)->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            supplierIds: [$supplier->id],
            tagIds: [$tag->id]
        );

        $service = new ExpenseListReportQueryService();
        $query = $service->build($filter);

        $results = $query->get();

        $this->assertCount(1, $results);
        $this->assertEquals($expense1->id, $results->first()->id);
    }

    public function test_export_snapshot_guard_allows_detail_mode_toggle()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $component = Livewire::test(ExpenseListReport::class)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters');

        // Toggle detail mode
        $component->call('toggleDetailMode')
            ->assertSet('detailMode', true);

        // Export should still work without re-applying because detailMode is ignored in hash
        $component->call('exportCsv')
            ->assertNotDispatched('alert');
    }

    public function test_report_computes_totals_correctly()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = ExpenseCategory::create(['category_name' => 'Test Category']);

        $expense = Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'amount' => 1500, // Total
            'status' => 'APPROVED',
            'is_tax_included' => false,
        ]);

        $expense->detailRows()->create([
            'name' => 'Item 1',
            'amount' => 1000
        ]);

        $expense->detailRows()->create([
            'name' => 'Item 2',
            'amount' => 500
        ]);

        Livewire::test(ExpenseListReport::class)
            ->set('startDate', now()->subDay()->format('Y-m-d'))
            ->set('endDate', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('grandTotals', function ($totals) {
                return isset($totals['Jumlah']) && $totals['Jumlah'] == 1500;
            });
    }
}
