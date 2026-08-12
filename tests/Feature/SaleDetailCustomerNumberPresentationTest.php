<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SaleDetailCustomerNumberPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Customer $customer;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'customer@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        Permission::firstOrCreate(['name' => 'sales.show', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales.reporting-date.override', 'guard_name' => 'web']);
        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('sales.show');
        $this->user->givePermissionTo('sales.reporting-date.override');
    }

    public function test_sale_detail_view_displays_populated_customer_invoice_number(): void
    {
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SAL-001',
            'imported_sales_reference_number' => 'CUST-INV-12345',
            'setting_id' => $this->setting->id,
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('sales.show', $sale->id));

        $response->assertStatus(200);
        $response->assertSee('Nomor Penjualan Pelanggan');
        $response->assertSee('CUST-INV-12345');
    }

    public function test_sale_detail_view_omits_label_for_null_customer_invoice_number(): void
    {
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SAL-002',
            'imported_sales_reference_number' => null,
            'setting_id' => $this->setting->id,
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('sales.show', $sale->id));

        $response->assertStatus(200);
        $response->assertDontSee('Nomor Penjualan Pelanggan');
    }

    public function test_sale_detail_view_omits_label_for_empty_customer_invoice_number(): void
    {
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SAL-003',
            'imported_sales_reference_number' => '',
            'setting_id' => $this->setting->id,
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('sales.show', $sale->id));

        $response->assertStatus(200);
        $response->assertDontSee('Nomor Penjualan Pelanggan');
    }

    public function test_sale_detail_view_displays_internal_sale_reference(): void
    {
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SAL-004',
            'imported_sales_reference_number' => 'CUST-INV-999',
            'setting_id' => $this->setting->id,
            'customer_name' => $this->customer->customer_name,
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('sales.show', $sale->id));

        $response->assertStatus(200);
        $response->assertSee('INV/SAL-004');
    }
}
