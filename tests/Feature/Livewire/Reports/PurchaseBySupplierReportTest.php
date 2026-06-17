<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\PurchaseBySupplierReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Product\Entities\Product;
use Modules\People\Entities\Supplier;

class PurchaseBySupplierReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'purchases.access']);
        Permission::firstOrCreate(['name' => 'purchases.show']);
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
        $this->user->givePermissionTo('purchases.access');
        $this->user->givePermissionTo('purchases.show');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id
        ]);
    }

    public function test_discounted_single_line_invoice_expands_to_product_diskon_pajak()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST1',
            'product_price' => 100000,
            'product_cost' => 50000,
            'product_quantity' => 10,
            'setting_id' => $this->setting->id
        ]);

        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'INV-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 11,
            'tax_amount' => 11000,
            'discount_percentage' => 0,
            'discount_amount' => 5000,
            'shipping_amount' => 0,
            'total_amount' => 106000, // 100000 - 5000 + 11000
            'paid_amount' => 106000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST1',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 11000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(PurchaseBySupplierReport::class)
            ->set('startDate', now()->subDay()->format('Y-m-d'))
            ->set('endDate', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(1, $purchases);
        $detail = $purchases->first();

        // Map rows manually like blade does
        $mappedRows = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($detail, 0, true);
        
        // Assert there are 3 rows: Product, Diskon, Pajak
        $this->assertCount(3, $mappedRows);
        
        $this->assertEquals('TEST PRODUCT', $mappedRows[0]['Nama produk']);
        $this->assertEquals(100000, $mappedRows[0]['Nominal tagihan']);
        
        $this->assertEquals('Diskon', $mappedRows[1]['Nama produk']);
        $this->assertEquals(-5000, $mappedRows[1]['Nominal tagihan']);
        
        $this->assertEquals('Pajak', $mappedRows[2]['Nama produk']);
        $this->assertEquals(11000, $mappedRows[2]['Nominal tagihan']);
        
        $this->assertEquals(106000, $mappedRows[2]['Total nominal tagihan']);
    }

    public function test_multi_line_discounted_invoice_emits_exactly_one_diskon_row()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST1',
            'product_price' => 100000,
            'product_cost' => 50000,
            'product_quantity' => 10,
            'setting_id' => $this->setting->id
        ]);

        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reference' => 'INV-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 11,
            'tax_amount' => 22000,
            'discount_percentage' => 0,
            'discount_amount' => 5000,
            'shipping_amount' => 0,
            'total_amount' => 217000, // 200000 - 5000 + 22000
            'paid_amount' => 217000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => 'Test',
            'setting_id' => $this->setting->id
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'Test Product 1',
            'product_code' => 'TEST1',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 11000,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'Test Product 2',
            'product_code' => 'TEST2',
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 11000,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(PurchaseBySupplierReport::class)
            ->set('startDate', now()->subDay()->format('Y-m-d'))
            ->set('endDate', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters');

        $purchases = $component->viewData('purchases');
        $this->assertCount(2, $purchases);

        // First detail (not last)
        $mappedRows1 = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($purchases[0], 0, false);
        $this->assertCount(2, $mappedRows1); // Product + Pajak
        $this->assertEquals('TEST PRODUCT 1', $mappedRows1[0]['Nama produk']);
        $this->assertEquals('Pajak', $mappedRows1[1]['Nama produk']);

        // Second detail (last)
        $mappedRows2 = \App\Services\Reports\PurchaseBySupplierReportQueryService::mapRows($purchases[1], 111000, true);
        $this->assertCount(3, $mappedRows2); // Product + Diskon + Pajak
        $this->assertEquals('TEST PRODUCT 2', $mappedRows2[0]['Nama produk']);
        $this->assertEquals('Diskon', $mappedRows2[1]['Nama produk']);
        $this->assertEquals(-5000, $mappedRows2[1]['Nominal tagihan']);
        $this->assertEquals('Pajak', $mappedRows2[2]['Nama produk']);
        $this->assertEquals(217000, $mappedRows2[2]['Total nominal tagihan']);
    }
}
