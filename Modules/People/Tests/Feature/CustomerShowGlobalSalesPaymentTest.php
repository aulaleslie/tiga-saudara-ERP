<?php

namespace Modules\People\Tests\Feature;

use Tests\TestCase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

class CustomerShowGlobalSalesPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();
        session(['setting_id' => $this->setting1->id]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_customer_detail_shows_embedded_workspace_when_user_has_global_access()
    {
        Gate::define('customers.show', fn() => true);
        Gate::define('salePayments.global.access', fn() => true);
        Gate::define('salePayments.create', fn() => true);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Alice Customer',
        ]);

        $sale = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(15),
            'reference' => 'SO-CUST-EMBEDDED-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'setting_id' => $this->setting2->id,
        ]);

        $response = $this->get(route('customers.show', $customer->id));

        $response->assertSuccessful();
        $response->assertSee('ALICE CUSTOMER');
        $response->assertSee('Pembayaran Penjualan Global');
        $response->assertSee('SO-CUST-EMBEDDED-01');
        $response->assertSee(route('sales.global-payments.create', $sale->id));
    }

    public function test_customer_detail_hides_embedded_workspace_when_user_lacks_global_access()
    {
        Gate::define('customers.show', fn() => true);
        Gate::define('salePayments.global.access', fn() => false);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Bob Customer',
        ]);

        $response = $this->get(route('customers.show', $customer->id));

        $response->assertSuccessful();
        $response->assertSee('BOB CUSTOMER');
        $response->assertDontSee('Pembayaran Penjualan Global');
    }

    public function test_customer_detail_shows_read_only_workspace_when_user_lacks_create_permission()
    {
        Gate::define('customers.show', fn() => true);
        Gate::define('salePayments.global.access', fn() => true);
        Gate::define('salePayments.create', fn() => false);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'customer_name' => 'Charlie Customer',
        ]);

        $sale = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(15),
            'reference' => 'SO-READONLY-01',
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'setting_id' => $this->setting1->id,
        ]);

        $response = $this->get(route('customers.show', $customer->id));

        $response->assertSuccessful();
        $response->assertSee('CHARLIE CUSTOMER');
        $response->assertSee('SO-READONLY-01');
        $response->assertDontSee(route('sales.global-payments.create', $sale->id));
        $response->assertSee(route('sales.global-payments.show', $sale->id));
        $response->assertSee(route('sales.global-payments.history', $sale->id));
    }
}
