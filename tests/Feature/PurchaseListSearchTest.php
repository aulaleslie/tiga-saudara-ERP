<?php

namespace Tests\Feature;

use App\Livewire\Purchase\PurchaseTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductCategory;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseListSearchTest extends TestCase
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

    public function test_search_purchase_by_supplier_purchase_number(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => 'SUPPLIER-INV-12345',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $purchase2 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => 'SUPPLIER-INV-67890',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'SUPPLIER-INV-12345')
            ->call('searchSubmit')
            ->assertSeeHtml($purchase1->reference)
            ->assertDontSeeHtml($purchase2->reference);
    }

    public function test_search_purchase_by_tax_ref_no(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'tax_ref_no' => 'TAX-REF-ABC123',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $purchase2 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'tax_ref_no' => 'TAX-REF-XYZ789',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'TAX-REF-ABC123')
            ->call('searchSubmit')
            ->assertSeeHtml($purchase1->reference)
            ->assertDontSeeHtml($purchase2->reference);
    }

    public function test_search_purchase_by_supplier_reference_no(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_reference_no' => 'SUP-REF-555',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $purchase2 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_reference_no' => 'SUP-REF-666',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'SUP-REF-555')
            ->call('searchSubmit')
            ->assertSeeHtml($purchase1->reference)
            ->assertDontSeeHtml($purchase2->reference);
    }

    public function test_search_purchase_by_detail_product_name(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $purchase2 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase1->id,
            'product_name' => 'Laptop Dell',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase2->id,
            'product_name' => 'Mouse Logitech',
            'product_code' => 'LOG-001',
            'quantity' => 5,
            'unit_price' => 400,
            'price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'Laptop Dell')
            ->call('searchSubmit')
            ->assertSeeHtml($purchase1->reference)
            ->assertDontSeeHtml($purchase2->reference);
    }

    public function test_search_purchase_by_detail_product_code(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $purchase2 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase1->id,
            'product_name' => 'Laptop',
            'product_code' => 'DELL-PRECISION-5560',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase2->id,
            'product_name' => 'Mouse',
            'product_code' => 'LOG-MX-MASTER',
            'quantity' => 5,
            'unit_price' => 400,
            'price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'DELL-PRECISION-5560')
            ->call('searchSubmit')
            ->assertSeeHtml($purchase1->reference)
            ->assertDontSeeHtml($purchase2->reference);
    }

    public function test_multiple_detail_rows_return_one_document_row(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_name' => 'Laptop Dell',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_name' => 'Laptop Lenovo',
            'product_code' => 'LEN-001',
            'quantity' => 2,
            'unit_price' => 1000,
            'price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'Laptop')
            ->call('searchSubmit')
            ->assertSeeHtml($purchase->reference);
    }

    public function test_search_composes_with_filters(): void
    {
        $supplier = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'Supplier A',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase1 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => 'SUPPLIER-INV-001',
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $purchase2 = Purchase::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_purchase_number' => 'SUPPLIER-INV-001',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        Livewire::test(PurchaseTable::class, ['settingId' => 1])
            ->set('searchText', 'SUPPLIER-INV-001')
            ->call('searchSubmit')
            ->set('statusFilter', [Purchase::STATUS_DRAFTED])
            ->assertSeeHtml($purchase1->reference)
            ->assertDontSeeHtml($purchase2->reference);
    }
}
