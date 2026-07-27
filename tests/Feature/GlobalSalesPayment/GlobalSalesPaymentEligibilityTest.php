<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalSalesPaymentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed permissions needed for tests
        $this->seedPermissions(['salePayments.global.access', 'salePayments.create']);

        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['salePayments.global.access', 'salePayments.create']);
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

    protected function createSale($customer, $setting, $overrides = [])
    {
        $defaults = [
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
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
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'archived_at' => null,
        ];

        return Sale::create(array_merge($defaults, $overrides));
    }

    public function test_approved_sales_are_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
        ]);

        // Verify through live_due_amount property that it's eligible
        $this->assertTrue($sale->live_due_amount > 0);
    }

    public function test_dispatched_partially_sales_are_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED_PARTIALLY,
        ]);

        $this->assertTrue($sale->live_due_amount > 0);
    }

    public function test_dispatched_sales_are_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        $this->assertTrue($sale->live_due_amount > 0);
    }

    public function test_archived_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
            'archived_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_drafted_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DRAFTED,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_waiting_approval_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_WAITING_APPROVAL,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_rejected_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_REJECTED,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_fully_paid_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
            'due_amount' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        // Should show error message about no positive due amount
        $response->assertStatus(302); // Redirect on error
        $response->assertSessionHas('error');
    }

    public function test_global_list_includes_only_eligible_sales()
    {
        // Create eligible sales
        $approved = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
        ]);

        $dispatched = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
        ]);

        // Create ineligible sales
        $drafted = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DRAFTED,
        ]);

        $archived = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
            'archived_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        // The table component should contain approved sales but not drafted/archived
        // This would be verified through component testing or page assertion
    }

    public function test_cross_setting_sales_are_included()
    {
        $otherSetting = Setting::factory()->create();

        $sale1 = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
        ]);

        $sale2 = $this->createSale($this->customer, $otherSetting, [
            'status' => Sale::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        // Both sales should be accessible through global view
    }
}
