<?php

namespace Tests\Feature;

use App\Livewire\Sale\SaleTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleListSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => 1]);
    }

    public function test_search_sale_by_imported_sales_reference_number(): void
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale1 = Sale::create([
            'date' => now(),
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'imported_sales_reference_number' => 'IMPORT-REF-12345',
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $sale2 = Sale::create([
            'date' => now(),
            'reference' => 'SL-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'imported_sales_reference_number' => 'IMPORT-REF-67890',
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'IMPORT-REF-12345')
            ->call('searchSubmit')
            ->assertSee('SL-001')
            ->assertDontSee('SL-002');
    }

    public function test_search_sale_by_tax_ref_no(): void
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale1 = Sale::create([
            'date' => now(),
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_ref_no' => 'TAX-REF-ABC123',
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $sale2 = Sale::create([
            'date' => now(),
            'reference' => 'SL-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_ref_no' => 'TAX-REF-XYZ789',
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'TAX-REF-ABC123')
            ->call('searchSubmit')
            ->assertSee('SL-001')
            ->assertDontSee('SL-002');
    }

    public function test_search_sale_by_detail_product_name(): void
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale1 = Sale::create([
            'date' => now(),
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $sale2 = Sale::create([
            'date' => now(),
            'reference' => 'SL-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_name' => 'Laptop Dell',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_name' => 'Mouse Logitech',
            'product_code' => 'LOG-001',
            'quantity' => 5,
            'unit_price' => 400,
            'price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'Laptop Dell')
            ->call('searchSubmit')
            ->assertSee('SL-001')
            ->assertDontSee('SL-002');
    }

    public function test_search_sale_by_detail_product_code(): void
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale1 = Sale::create([
            'date' => now(),
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $sale2 = Sale::create([
            'date' => now(),
            'reference' => 'SL-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_name' => 'Laptop',
            'product_code' => 'DELL-PRECISION-5560',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_name' => 'Mouse',
            'product_code' => 'LOG-MX-MASTER',
            'quantity' => 5,
            'unit_price' => 400,
            'price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'DELL-PRECISION-5560')
            ->call('searchSubmit')
            ->assertSee('SL-001')
            ->assertDontSee('SL-002');
    }

    public function test_multiple_detail_rows_return_one_document_row(): void
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_name' => 'Laptop Dell',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_name' => 'Laptop Lenovo',
            'product_code' => 'LEN-001',
            'quantity' => 2,
            'unit_price' => 1000,
            'price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(SaleTable::class, ['settingId' => 1])
            ->set('searchText', 'Laptop')
            ->call('searchSubmit')
            ->assertSee('SL-001', $textOnly = true, $exact = false);
    }
}
