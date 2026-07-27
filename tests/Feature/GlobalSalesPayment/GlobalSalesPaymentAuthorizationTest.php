<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalSalesPaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected Sale $sale;
    protected User $authorizedUser;
    protected User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Only disable CheckUserRoleForSetting to preserve permission middleware
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Seed permissions needed for tests
        $this->seedPermissions(['salePayments.global.access', 'salePayments.create']);

        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'note' => null,
            'payment_term_id' => null,
            'tax_id' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Create user with global access
        $this->authorizedUser = User::factory()->create();
        $this->authorizedUser->givePermissionTo('salePayments.global.access');

        // Create user without global access
        $this->unauthorizedUser = User::factory()->create();
    }

    /**
     * Create permissions in test database
     */
    protected function seedPermissions(array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_anonymous_user_cannot_access_index()
    {
        $response = $this->get(route('sales.global-payments.index'));
        // Anonymous requests redirect to login, not 403
        $response->assertStatus(302);
    }

    public function test_user_without_permission_cannot_access_index()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.index'));
        $response->assertStatus(403);
    }

    public function test_user_with_global_access_can_view_index()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.index'));
        $response->assertStatus(200);
    }

    public function test_anonymous_user_cannot_view_sale_detail()
    {
        $response = $this->get(route('sales.global-payments.show', $this->sale->id));
        // Anonymous requests redirect to login, not 403
        $response->assertStatus(302);
    }

    public function test_user_without_permission_cannot_view_sale_detail()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id));
        $response->assertStatus(403);
    }

    public function test_user_with_permission_can_view_sale_detail()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id));
        $response->assertStatus(200);
    }

    public function test_create_requires_both_permissions()
    {
        // User with only global.access cannot access create
        $user1 = User::factory()->create();
        $user1->givePermissionTo('salePayments.global.access');

        $response = $this->actingAs($user1)
            ->get(route('sales.global-payments.create', $this->sale->id));
        $response->assertStatus(403);

        // User with both permissions can access create
        $user2 = User::factory()->create();
        $user2->givePermissionTo(['salePayments.global.access', 'salePayments.create']);

        $response = $this->actingAs($user2)
            ->get(route('sales.global-payments.create', $this->sale->id));
        // Should not be forbidden (403) - can be 200 (form displays) or 302 (redirected if no live due)
        $this->assertNotEquals(403, $response->status());
    }

    public function test_store_requires_both_permissions()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('salePayments.global.access');

        $response = $this->actingAs($user)
            ->post(route('sales.global-payments.store', $this->sale->id), [
                'reference' => 'TEST001',
                'date' => now()->toDateString(),
                'payment_method_id' => 1,
                'allocations' => [$this->sale->id => 100000],
            ]);
        $response->assertStatus(403);
    }

    public function test_history_requires_global_access_permission()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.history', $this->sale->id));
        $response->assertStatus(403);
    }

    public function test_user_with_global_access_can_view_history()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.history', $this->sale->id));
        $response->assertStatus(200);
    }
}
