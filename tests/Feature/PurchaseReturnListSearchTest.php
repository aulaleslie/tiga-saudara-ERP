<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\DataTables\PurchaseReturnsDataTable;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

class PurchaseReturnListSearchTest extends TestCase
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

        Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);

        session(['setting_id' => 1]);
    }

    private function searchDataTable(string $searchValue): array
    {
        $dataTable = new PurchaseReturnsDataTable();
        $model = new PurchaseReturn();
        $query = $model->newQuery()
            ->with(['supplier', 'location', 'purchaseReturnDetails', 'settlementItems']);

        \Illuminate\Support\Facades\Request::merge([
            'search' => ['value' => $searchValue],
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]);

        $result = $dataTable->dataTable($query)->toArray();
        return $result['data'];
    }

    public function test_search_purchase_return_by_reference(): void
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

        $return1 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'setting_id' => 1,
        ]);

        $return2 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'setting_id' => 1,
        ]);

        $results = $this->searchDataTable($return1->reference);
        $this->assertCount(1, $results);
        $this->assertStringContainsString($return1->reference, $results[0]['reference']);
    }

    public function test_search_purchase_return_by_supplier_name(): void
    {
        $supplier1 = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'PT Supplier ABC',
            'supplier_email' => 'supplier1@example.com',
            'supplier_phone' => '08123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $supplier2 = Supplier::create([
            'setting_id' => 1,
            'supplier_name' => 'PT Supplier XYZ',
            'supplier_email' => 'supplier2@example.com',
            'supplier_phone' => '08234567',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $return1 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier1->id,
            'supplier_name' => $supplier1->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-001',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'setting_id' => 1,
        ]);

        $return2 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier2->id,
            'supplier_name' => $supplier2->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-002',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'setting_id' => 1,
        ]);

        $results = $this->searchDataTable('PT Supplier ABC');
        $this->assertCount(1, $results);
    }

    public function test_search_purchase_return_by_detail_product_name(): void
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

        $return1 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-001',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'setting_id' => 1,
        ]);

        $return2 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-002',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'setting_id' => 1,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return1->id,
            'product_name' => 'Laptop Dell',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 500,
            'price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return2->id,
            'product_name' => 'Mouse Logitech',
            'product_code' => 'LOG-001',
            'quantity' => 1,
            'unit_price' => 300,
            'price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $results = $this->searchDataTable('Laptop Dell');
        $this->assertCount(1, $results);
    }

    public function test_search_purchase_return_by_detail_product_code(): void
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

        $return1 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-001',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'setting_id' => 1,
        ]);

        $return2 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-002',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'setting_id' => 1,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return1->id,
            'product_name' => 'Laptop',
            'product_code' => 'DELL-PRECISION-5560',
            'quantity' => 1,
            'unit_price' => 500,
            'price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return2->id,
            'product_name' => 'Mouse',
            'product_code' => 'LOG-MX-MASTER',
            'quantity' => 1,
            'unit_price' => 300,
            'price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $results = $this->searchDataTable('DELL-PRECISION-5560');
        $this->assertCount(1, $results);
    }

    public function test_search_purchase_return_by_source_purchase_reference(): void
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
            'supplier_purchase_number' => 'SUPPLIER-001',
            'status' => Purchase::STATUS_APPROVED,
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
            'supplier_purchase_number' => 'SUPPLIER-002',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'due_date' => now()->addDays(7),
            'setting_id' => 1,
        ]);

        $return1 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'setting_id' => 1,
        ]);

        $return2 = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'setting_id' => 1,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return1->id,
            'po_id' => $purchase1->id,
            'product_name' => 'Laptop',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 500,
            'price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return2->id,
            'po_id' => $purchase2->id,
            'product_name' => 'Mouse',
            'product_code' => 'LOG-001',
            'quantity' => 1,
            'unit_price' => 300,
            'price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $results = $this->searchDataTable($purchase1->reference);
        $this->assertCount(1, $results);
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

        $return = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'location_id' => 1,
            'reference' => 'RET-001',
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'approval_status' => 'draft',
            'payment_method' => 'CASH',
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'setting_id' => 1,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_name' => 'Laptop Dell',
            'product_code' => 'DEL-001',
            'quantity' => 1,
            'unit_price' => 300,
            'price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_name' => 'Laptop Lenovo',
            'product_code' => 'LEN-001',
            'quantity' => 1,
            'unit_price' => 200,
            'price' => 200,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $results = $this->searchDataTable('Laptop');
        $this->assertCount(1, $results);
    }
}
