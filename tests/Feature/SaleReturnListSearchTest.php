<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\DataTables\SaleReturnsDataTable;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

class SaleReturnListSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->actingAs($user);

        Currency::create(['id' => 1, 'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp', 'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1]);
        Setting::create(['id' => 1, 'company_name' => 'Test', 'company_email' => 'test@test.com', 'company_phone' => '123', 'notification_email' => 'test@test.com', 'default_currency_id' => 1, 'default_currency_position' => 'prefix', 'footer_text' => 'Test', 'company_address' => 'Test']);
        Location::create(['id' => 1, 'name' => 'Main', 'setting_id' => 1]);
        session(['setting_id' => 1]);
    }

    private function searchDataTable(string $searchValue): array
    {
        $dataTable = new SaleReturnsDataTable();
        $model = new SaleReturn();
        $query = $model->newQuery()
            ->with(['sale', 'customer', 'saleReturnDetails']);

        \Illuminate\Support\Facades\Request::merge([
            'search' => ['value' => $searchValue],
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]);

        $result = $dataTable->dataTable($query)->toArray();
        return $result['data'];
    }

    public function test_search_sale_return_by_reference(): void
    {
        $customer = Customer::create(['setting_id' => 1, 'customer_name' => 'A', 'customer_email' => 'a@test.com', 'customer_phone' => '123', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $r1 = SaleReturn::create(['date' => now(), 'reference' => 'RET-001', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-001', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'setting_id' => 1]);
        $r2 = SaleReturn::create(['date' => now(), 'reference' => 'RET-002', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-002', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 300, 'paid_amount' => 0, 'due_amount' => 300, 'setting_id' => 1]);
        $results = $this->searchDataTable($r1->reference);
        $this->assertCount(1, $results);
    }

    public function test_search_sale_return_by_customer_name(): void
    {
        $c1 = Customer::create(['setting_id' => 1, 'customer_name' => 'PT ABC', 'customer_email' => 'a@test.com', 'customer_phone' => '0811111111', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $c2 = Customer::create(['setting_id' => 1, 'customer_name' => 'PT XYZ', 'customer_email' => 'b@test.com', 'customer_phone' => '0822222222', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        SaleReturn::create(['date' => now(), 'reference' => 'RET-001', 'customer_id' => $c1->id, 'location_id' => 1, 'sale_reference' => 'SAL-001', 'customer_name' => 'PT ABC', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'setting_id' => 1]);
        SaleReturn::create(['date' => now(), 'reference' => 'RET-002', 'customer_id' => $c2->id, 'location_id' => 1, 'sale_reference' => 'SAL-002', 'customer_name' => 'PT XYZ', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 300, 'paid_amount' => 0, 'due_amount' => 300, 'setting_id' => 1]);
        $results = $this->searchDataTable('PT ABC');
        $this->assertCount(1, $results);
    }

    public function test_search_sale_return_by_detail_product_name(): void
    {
        $customer = Customer::create(['setting_id' => 1, 'customer_name' => 'A', 'customer_email' => 'a@test.com', 'customer_phone' => '123', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $r1 = SaleReturn::create(['date' => now(), 'reference' => 'RET-001', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-001', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'setting_id' => 1]);
        $r2 = SaleReturn::create(['date' => now(), 'reference' => 'RET-002', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-002', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 300, 'paid_amount' => 0, 'due_amount' => 300, 'setting_id' => 1]);
        SaleReturnDetail::create(['sale_return_id' => $r1->id, 'product_name' => 'Laptop Dell', 'product_code' => 'DEL-001', 'quantity' => 1, 'unit_price' => 500, 'price' => 500, 'sub_total' => 500, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        SaleReturnDetail::create(['sale_return_id' => $r2->id, 'product_name' => 'Mouse', 'product_code' => 'LOG-001', 'quantity' => 1, 'unit_price' => 300, 'price' => 300, 'sub_total' => 300, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $results = $this->searchDataTable('Laptop Dell');
        $this->assertCount(1, $results);
    }

    public function test_search_sale_return_by_detail_product_code(): void
    {
        $customer = Customer::create(['setting_id' => 1, 'customer_name' => 'A', 'customer_email' => 'a@test.com', 'customer_phone' => '123', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $r1 = SaleReturn::create(['date' => now(), 'reference' => 'RET-001', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-001', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'setting_id' => 1]);
        $r2 = SaleReturn::create(['date' => now(), 'reference' => 'RET-002', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-002', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 300, 'paid_amount' => 0, 'due_amount' => 300, 'setting_id' => 1]);
        SaleReturnDetail::create(['sale_return_id' => $r1->id, 'product_name' => 'Laptop', 'product_code' => 'DELL-PRECISION', 'quantity' => 1, 'unit_price' => 500, 'price' => 500, 'sub_total' => 500, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        SaleReturnDetail::create(['sale_return_id' => $r2->id, 'product_name' => 'Mouse', 'product_code' => 'LOG-MX', 'quantity' => 1, 'unit_price' => 300, 'price' => 300, 'sub_total' => 300, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $results = $this->searchDataTable('DELL-PRECISION');
        $this->assertCount(1, $results);
    }

    public function test_search_sale_return_by_source_sale_reference(): void
    {
        $customer = Customer::create(['setting_id' => 1, 'customer_name' => 'A', 'customer_email' => 'a@test.com', 'customer_phone' => '123', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $s1 = Sale::create(['date' => now(), 'customer_id' => $customer->id, 'customer_name' => 'A', 'status' => Sale::STATUS_APPROVED, 'payment_status' => 'UNPAID', 'payment_method' => 'CASH', 'total_amount' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'due_date' => now()->addDays(7), 'setting_id' => 1]);
        $s2 = Sale::create(['date' => now(), 'customer_id' => $customer->id, 'customer_name' => 'A', 'status' => Sale::STATUS_APPROVED, 'payment_status' => 'UNPAID', 'payment_method' => 'CASH', 'total_amount' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'due_date' => now()->addDays(7), 'setting_id' => 1]);
        $r1 = SaleReturn::create(['date' => now(), 'reference' => 'RET-001', 'sale_id' => $s1->id, 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => $s1->reference, 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'setting_id' => 1]);
        $r2 = SaleReturn::create(['date' => now(), 'reference' => 'RET-002', 'sale_id' => $s2->id, 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => $s2->reference, 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 300, 'paid_amount' => 0, 'due_amount' => 300, 'setting_id' => 1]);
        $results = $this->searchDataTable($s1->reference);
        $this->assertCount(1, $results);
    }

    public function test_multiple_detail_rows_return_one_document_row(): void
    {
        $customer = Customer::create(['setting_id' => 1, 'customer_name' => 'A', 'customer_email' => 'a@test.com', 'customer_phone' => '123', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $return = SaleReturn::create(['date' => now(), 'reference' => 'RET-001', 'customer_id' => $customer->id, 'location_id' => 1, 'sale_reference' => 'SAL-001', 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 500, 'paid_amount' => 0, 'due_amount' => 500, 'setting_id' => 1]);
        SaleReturnDetail::create(['sale_return_id' => $return->id, 'product_name' => 'Laptop Dell', 'product_code' => 'DEL-001', 'quantity' => 1, 'unit_price' => 300, 'price' => 300, 'sub_total' => 300, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        SaleReturnDetail::create(['sale_return_id' => $return->id, 'product_name' => 'Laptop Lenovo', 'product_code' => 'LEN-001', 'quantity' => 1, 'unit_price' => 200, 'price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $results = $this->searchDataTable('Laptop');
        $this->assertCount(1, $results);
    }
}
