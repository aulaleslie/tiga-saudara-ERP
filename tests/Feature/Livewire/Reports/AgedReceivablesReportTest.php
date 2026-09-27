<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\AgedReceivablesReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\People\Entities\Customer;

class AgedReceivablesReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $customer;
    protected $customer2;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'saleReports.access']);
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
        $this->user->givePermissionTo('saleReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer A',
            'customer_email' => 'a@test.com',
            'customer_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id
        ]);

        $this->customer2 = Customer::create([
            'customer_name' => 'Customer B',
            'customer_email' => 'b@test.com',
            'customer_phone' => '654321',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id
        ]);
    }

    public function test_aged_receivables_bucket_boundaries()
    {
        $createSale = function ($date, $ref, $amount) {
            Sale::create([
                'date' => $date,
                'reference' => $ref,
                'customer_id' => $this->customer->id,
                'customer_name' => 'Customer A',
                'tax_amount' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_percentage' => 0, 'discount_percentage' => 0,
                'total_amount' => $amount,
                'paid_amount' => 0,
                'due_amount' => $amount,
                'status' => 'Completed',
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'setting_id' => $this->setting->id
            ]);
        };

        // Bucket 1 (1 - 30 Hari)
        $createSale('2023-01-15', 'INV-0', 10000);  // 0 days
        $createSale('2022-12-16', 'INV-30', 20000); // 30 days

        // Bucket 2 (31 - 60 Hari)
        $createSale('2022-12-15', 'INV-31', 30000); // 31 days
        $createSale('2022-11-16', 'INV-60', 40000); // 60 days

        // Bucket 3 (61 - 90 Hari)
        $createSale('2022-11-15', 'INV-61', 50000); // 61 days
        $createSale('2022-10-17', 'INV-90', 60000); // 90 days

        // Bucket 4 (> 90 Hari)
        $createSale('2022-10-16', 'INV-91', 70000); // 91 days

        // Report as of 2023-01-15
        $component = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $sales = $component->viewData('sales');
        $this->assertCount(1, $sales);
        
        $grandTotals = $component->viewData('grandTotals');
        $this->assertEquals(30000, $grandTotals['1 - 30 Hari']);
        $this->assertEquals(70000, $grandTotals['31 - 60 Hari']);
        $this->assertEquals(110000, $grandTotals['61 - 90 Hari']);
        $this->assertEquals(70000, $grandTotals['> 90 Hari']);
        $this->assertEquals(280000, $grandTotals['Total']);
    }

    public function test_as_of_balance_replay_excludes_later_payments()
    {
        $sale = Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 40000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 60000,
            'date' => '2023-01-20',
            'reference' => 'PAY-002',
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $grandTotals = $component->viewData('grandTotals');
        $this->assertEquals(60000, $grandTotals['Total']);

        $component2 = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-25')
            ->call('applyFilters');

        $this->assertCount(0, $component2->viewData('sales'));
    }

    public function test_access_control()
    {
        $this->user->revokePermissionTo('saleReports.access');

        $response = $this->actingAs($this->user)->get(route('reports.aged-receivables.index'));
        $response->assertStatus(403);
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

        $otherCustomer = Customer::create([
            'customer_name' => 'Other Customer',
            'customer_email' => 'other@test.com',
            'customer_phone' => '123456',
            'setting_id' => $otherSetting->id
        ]);

        Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-OTHER',
            'customer_id' => $otherCustomer->id,
            'customer_name' => 'Other Customer',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $otherSetting->id
        ]);

        Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-MINE',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $sales = $component->viewData('sales');
        $this->assertCount(1, $sales);
        $this->assertEquals($this->customer->id, $sales->first()->customer_id);
    }

    public function test_filters_and_sort()
    {
        $tag = \Spatie\Tags\Tag::findOrCreate('VVIP', 'en');
        
        $sale1 = Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);
        $sale1->attachTag($tag);

        $sale2 = Sale::create([
            'date' => '2023-01-05',
            'reference' => 'INV-002',
            'customer_id' => $this->customer2->id,
            'customer_name' => 'Customer B',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id
        ]);

        // Filter by tag
        $component2 = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-20')
            ->set('tagIds', [$tag->id])
            ->call('applyFilters');
            
        $this->assertCount(1, $component2->viewData('sales'));
        $this->assertEquals($this->customer->id, $component2->viewData('sales')->first()->customer_id);

        // Sort by total balance desc
        $component3 = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-20')
            ->set('sortField', 'total_balance')
            ->set('sortDirection', 'desc')
            ->call('applyFilters');
            
        $salesDesc = $component3->viewData('sales');
        $this->assertEquals($this->customer2->id, $salesDesc->first()->customer_id);
    }

    public function test_export_parity()
    {
        Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $component->call('exportExcel');
        $component->assertFileDownloaded('usia_piutang_2023-01-15.xlsx');

        $component->call('exportCsv');
        $component->assertFileDownloaded('usia_piutang_2023-01-15.csv');

        $component->call('exportPdf');
        $component->assertFileDownloaded('usia_piutang_2023-01-15.pdf');
    }

    public function test_stale_export_is_blocked()
    {
        $component = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-15');
        
        $component->call('exportExcel');
        $component->assertDispatched('alert');
        $component->assertHasNoErrors();
    }

    public function test_invalidated_payment_is_excluded()
    {
        $sale = Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 40000,
            'date' => '2023-01-10',
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => 'DELETED'
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(AgedReceivablesReport::class)
            ->set('asOfDate', '2023-01-15')
            ->call('applyFilters');

        $grandTotals = $component->viewData('grandTotals');
        // Payment is DELETED, so it shouldn't be counted. Full balance remains.
        $this->assertEquals(100000, $grandTotals['Total']);
    }
}
