<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class CustomerNameNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['customers.access', 'pos.access', 'pos.sell', 'sales.access', 'sales.show', 'reports.access', 'saleReports.access', 'saleReports.global.access'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        
        \Modules\Setting\Entities\Setting::create([
            'id' => 1,
            'company_name' => 'Test',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test',
            'company_address' => 'Test',
            'pos_enabled' => true,
        ]);
    }

    public function test_import_shaped_customer_appears_correctly_in_data_table()
    {
        // An import-shaped customer: customer_name is populated, contact_name is null/empty
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Imported Customer Inc.',
            'contact_name' => null,
            'customer_email' => 'import@test.com',
            'customer_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['customers.access', 'pos.access', 'pos.sell']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        $request = \Illuminate\Http\Request::create(route('customers.index'), 'GET', ['draw' => 1]);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $dataTable = app(\Modules\People\DataTables\CustomersDataTable::class);
        
        $response = $dataTable->ajax();
        $this->assertStringContainsString('IMPORTED CUSTOMER INC.', $response->content());
    }

    public function test_import_shaped_customer_appears_correctly_in_pos_search()
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'POS Search Company',
            'contact_name' => null,
            'customer_email' => 'pos@test.com',
            'customer_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['customers.access', 'pos.access', 'pos.sell']);
        
        $this->mock(\Modules\Pos\Services\PosSessionLifecycleService::class, function ($mock) {
            $mock->shouldReceive('getActiveSessionForCashier')->andReturn(new \Modules\Pos\Entities\PosSession(['id' => 1, 'setting_id' => 1]));
        });

        $this->actingAs($user)->withSession(['setting_id' => 1]);

        $response = $this->getJson(route('pos.sell.customers.search', ['q' => 'POS Search']));

        $response->assertStatus(200);
        // It should return the canonical name
        $response->assertJsonFragment([
            'customer_name' => 'POS SEARCH COMPANY',
        ]);
        // The display name might be just the customer_name
        $response->assertSee('POS SEARCH COMPANY');
    }

    public function test_import_shaped_customer_appears_in_legacy_loader()
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Legacy Loader Co',
            'contact_name' => null,
            'customer_email' => 'legacy@test.com',
            'customer_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        \Livewire\Livewire::test(\App\Livewire\AutoComplete\CustomerLoader::class)
            ->set('isFocused', true)
            ->set('query', 'Legacy Loader')
            ->call('searchCustomers')
            ->assertSee('LEGACY LOADER CO');
    }

    public function test_import_shaped_customer_appears_in_shared_sales_dropdown()
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Sales Dropdown Co',
            'contact_name' => null,
            'customer_email' => 'sales@test.com',
            'customer_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        \Livewire\Livewire::test(\Modules\People\Livewire\CustomerSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Sales Dropdown')
            ->assertSee('SALES DROPDOWN CO');
    }

    public function test_customer_model_display_name_resolution()
    {
        // 1. Whitespace-safe fallback (canonical is blank, falls back to contact_name if available, else '-')
        $c1 = Customer::create(['setting_id' => 1, 'customer_name' => '   ', 'contact_name' => 'Whitespace Fallback', 'customer_email' => 'a@a.com', 'customer_phone' => '1', 'city' => 'A', 'country' => 'B', 'address' => 'C']);
        $this->assertEquals('WHITESPACE FALLBACK', $c1->display_name);

        // 2. Equal deduplication
        $c2 = Customer::create(['setting_id' => 1, 'customer_name' => 'Same Name', 'contact_name' => 'Same Name', 'customer_email' => 'b@b.com', 'customer_phone' => '2', 'city' => 'A', 'country' => 'B', 'address' => 'C']);
        $this->assertEquals('SAME NAME', $c2->display_name);

        // 3. Distinct supplemental contact context
        $c3 = Customer::create(['setting_id' => 1, 'customer_name' => 'Distinct Company', 'contact_name' => 'Distinct Contact', 'customer_email' => 'c@c.com', 'customer_phone' => '3', 'city' => 'A', 'country' => 'B', 'address' => 'C']);
        $this->assertEquals('DISTINCT CONTACT - DISTINCT COMPANY', $c3->display_name);
        
        // 4. Blank contact, nonblank customer
        $c4 = Customer::create(['setting_id' => 1, 'customer_name' => 'Only Canonical', 'contact_name' => '  ', 'customer_email' => 'd@d.com', 'customer_phone' => '4', 'city' => 'A', 'country' => 'B', 'address' => 'C']);
        $this->assertEquals('ONLY CANONICAL', $c4->display_name);
    }

    public function test_customer_quick_add_modal_keeps_contact_name_null_and_customer_name_canonical()
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        \Livewire\Livewire::test(\App\Livewire\Modules\People\Modals\CustomerQuickAddModal::class)
            ->set('customer_name', 'Quick Add Co')
            ->set('contact_name', '')
            ->call('save')
            ->assertDispatched('customerCreated');

        $customer = Customer::query()->where('customer_name', 'QUICK ADD CO')->first();
        $this->assertNotNull($customer);
        $this->assertNull($customer->contact_name);
    }

    public function test_import_shaped_customer_appears_in_sale_table_livewire()
    {
        $customerId = \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => 1,
            'customer_name' => 'Imported Sale Co',
            'contact_name' => null,
            'customer_email' => 'sales@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'setting_id' => 1,
            'customer_id' => $customerId,
            'customer_name' => 'Imported Sale Co',
            'date' => now(),
            'reference' => 'SL-001',
            'status' => 'Approved',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['sales.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        // Test App\Livewire\Sale\SaleTable component
        \Livewire\Livewire::test(\App\Livewire\Sale\SaleTable::class, ['settingId' => 1])
            ->assertSee('Imported Sale Co');
    }

    public function test_import_shaped_customer_appears_in_dispatch_sale_header_livewire()
    {
        $customerId = \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => 1,
            'customer_name' => 'Imported Sale Co',
            'contact_name' => null,
            'customer_email' => 'sales@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'setting_id' => 1,
            'customer_id' => $customerId,
            'customer_name' => 'Imported Sale Co',
            'date' => now(),
            'reference' => 'SL-001',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sale = \Modules\Sale\Entities\Sale::find($saleId);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['sales.access', 'sales.show']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        // Test App\Livewire\Sale\DispatchSaleHeader component
        \Livewire\Livewire::test(\App\Livewire\Sale\DispatchSaleHeader::class, ['sale' => $sale])
            ->assertSee('Imported Sale Co');
    }

    public function test_pos_checkout_resolver_canonical_behavior()
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'POS Canonical Co',
            'contact_name' => null,
            'customer_email' => 'pos_canon@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $resolver = app(\Modules\Pos\Services\PosCheckoutCustomerResolverService::class);
        $result = $resolver->resolve(1, $customer->id);

        $this->assertEquals('POS CANONICAL CO', $result['selected_customer']['display_name']);
        $this->assertEquals('POS CANONICAL CO', $result['selected_customer']['customer_name']);
        $this->assertNull($result['selected_customer']['contact_name']);
    }

    public function test_distinct_contact_dropdown_labels()
    {
        $customer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Dropdown Corp',
            'contact_name' => 'Distinct Contact',
            'customer_email' => 'dd@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        \Livewire\Livewire::test(\Modules\People\Livewire\CustomerSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Dropdown')
            ->assertSee('DROPDOWN CORP')
            ->assertDontSee('DISTINCT CONTACT - DROPDOWN CORP');
    }

    public function test_whitespace_only_customer_names()
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        // Test CustomerQuickAddModal
        \Livewire\Livewire::test(\App\Livewire\Modules\People\Modals\CustomerQuickAddModal::class)
            ->set('customer_name', '   ')
            ->set('contact_name', '   ')
            ->call('save')
            ->assertHasErrors(['customer_name']);

        // Test Legacy CreateModal
        \Livewire\Livewire::test(\App\Livewire\Customer\CreateModal::class)
            ->set('customer_name', '   ')
            ->set('contact_name', '   ')
            ->call('save')
            ->assertHasErrors(['customer_name']);

        $this->assertDatabaseMissing('customers', ['customer_name' => '   ']);
        $this->assertDatabaseMissing('customers', ['customer_name' => '-']);
    }

    public function test_settingless_missing_settings_customers_appear()
    {
        // Missing setting ID
        $customer = Customer::create([
            'setting_id' => null,
            'customer_name' => 'Global Customer',
            'contact_name' => null,
            'customer_email' => 'glob@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        // Dropdown test
        \Livewire\Livewire::test(\Modules\People\Livewire\CustomerSearchDropdown::class)
            ->set('open', true)
            ->set('search', 'Global Customer')
            ->assertSee('GLOBAL CUSTOMER');
            
        // Loader test
        \Livewire\Livewire::test(\App\Livewire\AutoComplete\CustomerLoader::class)
            ->set('isFocused', true)
            ->set('query', 'Global Customer')
            ->call('searchCustomers')
            ->assertSee('GLOBAL CUSTOMER');
    }

    public function test_aged_receivables_report_loader_resolves_canonical_name()
    {
        Customer::create([
            'setting_id' => null,
            'customer_name' => 'Settingless Report Customer',
            'contact_name' => 'Contact 1',
            'customer_email' => 'c1@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        Customer::create([
            'setting_id' => 2,
            'customer_name' => 'Other Setting Report Customer',
            'contact_name' => 'Contact 2',
            'customer_email' => 'c2@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => 1,
            'customer_name' => '',
            'contact_name' => 'Malformed Historical Data',
            'customer_email' => 'malformed@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['saleReports.global.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        \Livewire\Livewire::test(\App\Livewire\Reports\AgedReceivablesReport::class)
            ->set('customerSearch', 'Settingless Report')
            ->assertSee('SETTINGLESS REPORT CUSTOMER');

        \Livewire\Livewire::test(\App\Livewire\Reports\AgedReceivablesReport::class)
            ->set('customerSearch', 'Other Setting Report')
            ->assertSee('OTHER SETTING REPORT CUSTOMER');

        \Livewire\Livewire::test(\App\Livewire\Reports\AgedReceivablesReport::class)
            ->set('customerSearch', 'Malformed')
            ->assertSee('Malformed Historical Data');
    }

    public function test_sale_by_product_report_loader_resolves_canonical_name()
    {
        Customer::create([
            'setting_id' => null,
            'customer_name' => 'Cross Setting Product Report Customer',
            'contact_name' => 'Contact',
            'customer_email' => 'product@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => null,
            'customer_name' => '',
            'contact_name' => 'Fallback Contact Product',
            'customer_email' => 'fallback@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['saleReports.global.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        \Livewire\Livewire::test(\App\Livewire\Reports\SaleByProductReport::class)
            ->set('customerSearch', 'Cross Setting Product')
            ->assertSee('CROSS SETTING PRODUCT REPORT CUSTOMER');

        \Livewire\Livewire::test(\App\Livewire\Reports\SaleByProductReport::class)
            ->set('customerSearch', 'Fallback Contact')
            ->assertSee('Fallback Contact Product');
    }

    public function test_sale_delivery_report_loader_resolves_canonical_name()
    {
        Customer::create([
            'setting_id' => null,
            'customer_name' => 'Settingless Delivery Report Customer',
            'contact_name' => 'Contact',
            'customer_email' => 'delivery@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => 2,
            'customer_name' => '',
            'contact_name' => 'Fallback Contact Delivery',
            'customer_email' => 'fallback@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['saleReports.global.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        \Livewire\Livewire::test(\App\Livewire\Reports\SaleDeliveryReport::class)
            ->set('customerSearch', 'Settingless Delivery')
            ->assertSee('SETTINGLESS DELIVERY REPORT CUSTOMER');

        \Livewire\Livewire::test(\App\Livewire\Reports\SaleDeliveryReport::class)
            ->set('customerSearch', 'Fallback Contact')
            ->assertSee('Fallback Contact Delivery');
    }

    public function test_sales_order_completion_report_loader_resolves_canonical_name()
    {
        Customer::create([
            'setting_id' => null,
            'customer_name' => 'Settingless Completion Report Customer',
            'contact_name' => 'Contact',
            'customer_email' => 'completion@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => null,
            'customer_name' => '   ',
            'contact_name' => 'Fallback Contact Completion',
            'customer_email' => 'fallback@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['saleReports.global.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        \Livewire\Livewire::test(\App\Livewire\Reports\SalesOrderCompletionReport::class)
            ->set('customerSearch', 'Settingless Completion')
            ->assertSee('SETTINGLESS COMPLETION REPORT CUSTOMER');

        \Livewire\Livewire::test(\App\Livewire\Reports\SalesOrderCompletionReport::class)
            ->set('customerSearch', 'Fallback Contact')
            ->assertSee('Fallback Contact Completion');
    }

    public function test_global_sales_filters_loads_with_canonical_name()
    {
        // Create malformed historical customer with blank name and contact fallback, with different setting_id
        $customerId = \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'setting_id' => null,
            'customer_name' => '',
            'contact_name' => 'Fallback Filter Contact',
            'customer_email' => 'fallback@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['sales.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        $component = \Livewire\Livewire::test(\Modules\Sale\Http\Livewire\GlobalSalesFilters::class);

        // Assert rendered component contains the fallback contact name
        $component->assertSee('Fallback Filter Contact');

        // Verify the customers are loaded with canonical names as array data
        $customers = $component->viewData('customers');
        $this->assertNotNull($customers);
        $this->assertTrue(
            $customers->contains('id', $customerId),
            'Customer should be loaded in GlobalSalesFilters'
        );
        $customer = $customers->firstWhere('id', $customerId);
        $this->assertEquals('Fallback Filter Contact', $customer['canonical_name']);
    }

    public function test_customer_datatable_default_order_column_is_created_at_descending()
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['customers.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        $dataTable = app(\Modules\People\DataTables\CustomersDataTable::class);

        // Assert the html() builder configuration contains default order column 5 (created_at), descending
        $htmlBuilder = $dataTable->html();
        $attributes = $htmlBuilder->getAttributes();
        $this->assertArrayHasKey('order', $attributes);
        $this->assertEquals([[5, 'desc']], $attributes['order']);
    }

    public function test_customer_datatable_ajax_server_side_ordering()
    {
        Customer::query()->delete();
        $oldCustomer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'Old Customer',
            'contact_name' => null,
            'customer_email' => 'old@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now()->subDays(2),
        ]);

        $newCustomer = Customer::create([
            'setting_id' => 1,
            'customer_name' => 'New Customer',
            'contact_name' => null,
            'customer_email' => 'new@test.com',
            'customer_phone' => '124',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'created_at' => now(),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['customers.access']);
        $this->actingAs($user)->withSession(['setting_id' => 1]);

        $request = \Illuminate\Http\Request::create(route('customers.index'), 'GET', [
            'draw' => 1,
            'order' => [
                ['column' => 5, 'dir' => 'desc']
            ],
            'columns' => [
                ['data' => 'customer_name', 'name' => 'customer_name', 'orderable' => 'true'],
                ['data' => 'contact_name', 'name' => 'contact_name', 'orderable' => 'true'],
                ['data' => 'customer_email', 'name' => 'customer_email', 'orderable' => 'true'],
                ['data' => 'customer_phone', 'name' => 'customer_phone', 'orderable' => 'true'],
                ['data' => 'action', 'name' => 'action', 'orderable' => 'false'],
                ['data' => 'created_at', 'name' => 'created_at', 'orderable' => 'true'],
            ]
        ]);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->app->instance('request', $request);
        $dataTable = app(\Modules\People\DataTables\CustomersDataTable::class);

        $response = $dataTable->ajax();
        $data = json_decode($response->content(), true)['data'];

        // Assert exact descending created_at row order
        $this->assertEquals('NEW CUSTOMER', strip_tags($data[0]['customer_name']));
        $this->assertEquals('OLD CUSTOMER', strip_tags($data[1]['customer_name']));
    }
}
