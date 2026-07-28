<?php

namespace Modules\People\Tests\Feature;

use Tests\TestCase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

class SupplierShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup setting_id session as expected by controller
        session(['setting_id' => 1]);
        
        \Illuminate\Support\Facades\DB::table('settings')->insertOrIgnore([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer Text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_authorized_supplier_show_request_displays_dash_when_payment_term_id_is_null()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Gate::define('suppliers.show', fn() => true);
        Gate::define('suppliers.access', fn() => true);

        $supplier = Supplier::factory()->create([
            'payment_term_id' => null,
            'setting_id' => 1,
        ]);

        $response = $this->get(route('suppliers.show', $supplier->id));

        $response->assertSuccessful();
        $response->assertSee('-');
    }

    public function test_authorized_supplier_show_request_displays_assigned_payment_term_name()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Gate::define('suppliers.show', fn() => true);
        Gate::define('suppliers.access', fn() => true);

        $paymentTermId = \Illuminate\Support\Facades\DB::table('payment_terms')->insertGetId([
            'name' => 'Net 30',
            'longevity' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplier = Supplier::factory()->create([
            'payment_term_id' => $paymentTermId,
            'setting_id' => 1,
        ]);

        $response = $this->get(route('suppliers.show', $supplier->id));

        $response->assertSuccessful();
        $response->assertSee('Net 30');
    }
}
