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

class SaleReturnListOrderingTest extends TestCase
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

    private function ordered(string $column, string $dir): array
    {
        $cols = [];
        foreach (['reference', 'date'] as $name) {
            $cols[] = ['data' => $name, 'name' => $name, 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']];
        }
        \Illuminate\Support\Facades\Request::merge([
            'draw' => 1, 'start' => 0, 'length' => 50, 'search' => ['value' => ''],
            'columns' => $cols,
            'order' => [['column' => array_search($column, ['reference', 'date']), 'dir' => $dir]],
        ]);

        $rows = (new SaleReturnsDataTable())->dataTable((new SaleReturn())->newQuery())->toArray()['data'];

        return array_map(fn ($r) => strip_tags($r['reference']), $rows);
    }

    private function make(string $ref, string $date): SaleReturn
    {
        $c = Customer::firstOrCreate(['customer_name' => 'A'], ['setting_id' => 1, 'customer_email' => 'a@test.com', 'customer_phone' => '1', 'city' => 'C', 'country' => 'C', 'address' => 'A']);

        return SaleReturn::create(['date' => $date, 'reference' => $ref, 'customer_id' => $c->id, 'location_id' => 1, 'sale_reference' => 'S-' . $ref, 'customer_name' => 'A', 'status' => 'APPROVED', 'payment_status' => 'UNPAID', 'approval_status' => 'draft', 'payment_method' => 'CASH', 'total_amount' => 1, 'paid_amount' => 0, 'due_amount' => 1, 'setting_id' => 1]);
    }

    public function test_default_order_is_document_date_then_id_descending_including_backdated_and_ties(): void
    {
        $this->make('R-OLD-FIRST', '2026-01-05');     // id 1, oldest date, created first
        $this->make('R-NEW', '2026-03-10');           // id 2, newest date
        $this->make('R-BACKDATED', '2026-02-01');     // id 3, created last but backdated
        $this->make('R-TIE-A', '2026-03-10');         // id 4, ties with R-NEW
        $this->make('R-TIE-B', '2026-03-10');         // id 5

        $this->assertSame(['R-TIE-B', 'R-TIE-A', 'R-NEW', 'R-BACKDATED', 'R-OLD-FIRST'], $this->ordered('date', 'desc'));
    }

    public function test_explicit_user_sorting_is_honoured(): void
    {
        $this->make('R-B', '2026-01-05');
        $this->make('R-A', '2026-03-10');
        $this->make('R-C', '2026-02-01');

        $this->assertSame(['R-B', 'R-C', 'R-A'], $this->ordered('date', 'asc'));
        $this->assertSame(['R-A', 'R-B', 'R-C'], $this->ordered('reference', 'asc'));
        $this->assertSame(['R-C', 'R-B', 'R-A'], $this->ordered('reference', 'desc'));
    }

    public function test_the_table_is_configured_to_open_on_the_date_column_descending(): void
    {
        $dt = new SaleReturnsDataTable();
        $columns = (new \ReflectionMethod($dt, 'getColumns'));
        $columns->setAccessible(true);
        $names = array_map(fn ($c) => $c->data, $columns->invoke($dt));

        $this->assertSame(1, array_search('date', $names));
        $this->assertSame([[1, 'desc']], $dt->html()->getAttributes()['order']);
    }
}
