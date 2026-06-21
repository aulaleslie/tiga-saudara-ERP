<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\CustomerReceivablesReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\People\Entities\Customer;

class CustomerReceivablesReportTest extends TestCase
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

    public function test_as_of_balance_replay_excludes_later_payments()
    {
        $sale = Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
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

        // Report as of 2023-01-15: only the first payment counts. Remaining 60k
        $component = Livewire::actingAs($this->user)
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters');

        $sales = $component->viewData('sales');
        $this->assertCount(1, $sales);
        $this->assertEquals(60000, $sales->first()->sisa_piutang);
        
        $grandTotal = $component->viewData('grandTotal');
        $this->assertEquals(60000, $grandTotal);

        // Report as of 2023-01-25: fully paid, should not appear
        $component2 = Livewire::actingAs($this->user)
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-25')
            ->call('applyFilters');

        $this->assertCount(0, $component2->viewData('sales'));
    }

    public function test_access_control()
    {
        $this->user->revokePermissionTo('saleReports.access');

        $response = $this->actingAs($this->user)->get(route('reports.customer-receivables.index'));
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
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $otherSetting->id
        ]);

        Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-MINE',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $this->setting->id
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters');

        $sales = $component->viewData('sales');
        $this->assertCount(1, $sales);
        $this->assertEquals('INV-MINE', $sales->first()->reference);
    }

    public function test_filters_and_sort()
    {
        $tag = \Spatie\Tags\Tag::findOrCreate('VVIP', 'en');
        
        $sale1 = Sale::create([
            'date' => '2023-01-01',
            'due_date' => '2023-01-10',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $this->setting->id
        ]);
        $sale1->attachTag($tag);

        $sale2 = Sale::create([
            'date' => '2023-01-05',
            'due_date' => '2023-01-15',
            'reference' => 'INV-002',
            'customer_id' => $this->customer2->id,
            'customer_name' => 'Customer B',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
            'setting_id' => $this->setting->id
        ]);

        // Filter by due date
        $component = Livewire::actingAs($this->user)
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-20')
            ->set('dueDateUntil', '2023-01-12')
            ->call('applyFilters');
        
        $this->assertCount(1, $component->viewData('sales'));
        $this->assertEquals('INV-001', $component->viewData('sales')->first()->reference);

        // Filter by tag
        $component2 = Livewire::actingAs($this->user)
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-20')
            ->set('tagIds', [$tag->id])
            ->call('applyFilters');
            
        $this->assertCount(1, $component2->viewData('sales'));
        $this->assertEquals('INV-001', $component2->viewData('sales')->first()->reference);

        // Sort by total balance desc
        $component3 = Livewire::actingAs($this->user)
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-20')
            ->set('sortField', 'total_balance')
            ->set('sortDirection', 'desc')
            ->call('applyFilters');
            
        $salesDesc = $component3->viewData('sales');
        $this->assertEquals('INV-002', $salesDesc->first()->reference);
    }

    public function test_export_parity()
    {
        Sale::create([
            'date' => '2023-01-01',
            'reference' => 'INV-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer A',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
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
            ->test(CustomerReceivablesReport::class)
            ->set('endDate', '2023-01-15')
            ->call('applyFilters')
            ->call('exportExcel');

        $component->assertFileDownloaded('piutang_pelanggan_2023-01-15.xlsx');
    }
}
