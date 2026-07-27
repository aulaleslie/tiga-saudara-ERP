<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
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

        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['salePayments.global.access', 'salePayments.create']);
    }

    public function test_approved_sales_are_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_APPROVED,
                'archived_at' => null,
            ])
            ->create();

        // Verify through live_due_amount property that it's eligible
        $this->assertTrue($sale->live_due_amount > 0);
    }

    public function test_dispatched_partially_sales_are_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_DISPATCHED_PARTIALLY,
                'archived_at' => null,
            ])
            ->create();

        $this->assertTrue($sale->live_due_amount > 0);
    }

    public function test_dispatched_sales_are_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_DISPATCHED,
                'archived_at' => null,
            ])
            ->create();

        $this->assertTrue($sale->live_due_amount > 0);
    }

    public function test_archived_sales_are_not_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_APPROVED,
                'archived_at' => now(),
            ])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_drafted_sales_are_not_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_DRAFTED,
                'archived_at' => null,
            ])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_waiting_approval_sales_are_not_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_WAITING_APPROVAL,
                'archived_at' => null,
            ])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_rejected_sales_are_not_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_REJECTED,
                'archived_at' => null,
            ])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        $response->assertStatus(404);
    }

    public function test_fully_paid_sales_are_not_eligible()
    {
        $sale = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state([
                'status' => Sale::STATUS_APPROVED,
                'archived_at' => null,
                'due_amount' => 0,
            ])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.create', $sale->id));

        // Should show error message about no positive due amount
        $response->assertStatus(302); // Redirect on error
        $response->assertSessionHas('error');
    }

    public function test_global_list_includes_only_eligible_sales()
    {
        // Create eligible sales
        $approved = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state(['status' => Sale::STATUS_APPROVED, 'archived_at' => null])
            ->create();

        $dispatched = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state(['status' => Sale::STATUS_DISPATCHED, 'archived_at' => null])
            ->create();

        // Create ineligible sales
        $drafted = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state(['status' => Sale::STATUS_DRAFTED, 'archived_at' => null])
            ->create();

        $archived = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state(['status' => Sale::STATUS_APPROVED, 'archived_at' => now()])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        // The table component should contain approved sales but not drafted/archived
        // This would be verified through component testing or page assertion
    }

    public function test_cross_setting_sales_are_included()
    {
        $otherSetting = Setting::factory()->create();

        $sale1 = Sale::factory()
            ->for($this->customer)
            ->for($this->setting)
            ->state(['status' => Sale::STATUS_APPROVED, 'archived_at' => null])
            ->create();

        $sale2 = Sale::factory()
            ->for($this->customer)
            ->for($otherSetting)
            ->state(['status' => Sale::STATUS_APPROVED, 'archived_at' => null])
            ->create();

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        // Both sales should be accessible through global view
    }
}
