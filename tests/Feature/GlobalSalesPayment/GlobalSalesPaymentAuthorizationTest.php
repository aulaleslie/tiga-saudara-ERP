<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
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

        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_APPROVED,
                'archived_at' => null,
            ])
            ->create();

        // Create user with global access
        $this->authorizedUser = User::factory()->create();
        $this->authorizedUser->givePermissionTo('salePayments.global.access');

        // Create user without global access
        $this->unauthorizedUser = User::factory()->create();
    }

    public function test_menu_is_visible_to_authorized_users()
    {
        // Test that the menu item appears for users with salePayments.global.access
        $this->actingAs($this->authorizedUser)
            ->get('/') // Assuming home renders the menu
            ->assertSee('Pembayaran Penjualan Global');
    }

    public function test_menu_is_hidden_from_unauthorized_users()
    {
        // Test that the menu item is hidden for users without permission
        $this->actingAs($this->unauthorizedUser)
            ->get('/')
            ->assertDontSee('Pembayaran Penjualan Global');
    }

    public function test_index_route_forbidden_without_permission()
    {
        $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.index'))
            ->assertStatus(403);
    }

    public function test_index_route_accessible_with_permission()
    {
        $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.index'))
            ->assertStatus(200);
    }

    public function test_show_route_forbidden_without_global_access()
    {
        $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id))
            ->assertStatus(403);
    }

    public function test_show_route_accessible_with_global_access()
    {
        $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id))
            ->assertStatus(200);
    }

    public function test_create_route_forbidden_without_create_permission()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('salePayments.global.access');
        // Note: user does not have salePayments.create

        $this->actingAs($user)
            ->get(route('sales.global-payments.create', $this->sale->id))
            ->assertStatus(403);
    }

    public function test_create_route_accessible_with_both_permissions()
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['salePayments.global.access', 'salePayments.create']);

        $this->actingAs($user)
            ->get(route('sales.global-payments.create', $this->sale->id))
            ->assertStatus(200);
    }

    public function test_store_route_forbidden_without_create_permission()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('salePayments.global.access');

        $this->actingAs($user)
            ->post(route('sales.global-payments.store', $this->sale->id), [
                'reference' => 'TEST001',
                'date' => now()->toDateString(),
                'payment_method_id' => 1,
                'allocations' => [$this->sale->id => 100000],
            ])
            ->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_view_details()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id));

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_view_details()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->get(route('sales.global-payments.show', $this->sale->id));

        $response->assertStatus(200);
        $response->assertViewHas('sale', $this->sale);
        $response->assertViewHas('setting', $this->setting);
    }
}
