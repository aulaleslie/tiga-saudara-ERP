<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
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

        // Only disable CheckUserRoleForSetting to preserve permission middleware
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

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

        // Archived sales should not appear in global list
        $archived = Sale::approvedUp()->whereNull('archived_at')->where('id', $sale->id)->first();
        $this->assertNull($archived, 'Archived sale should not be retrieved by approvedUp scope');
    }

    public function test_drafted_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DRAFTED,
        ]);

        // Drafted sales should not be approved-up
        $draftApproved = Sale::approvedUp()->where('id', $sale->id)->first();
        $this->assertNull($draftApproved, 'Drafted sale should not be approved-up');
    }

    public function test_waiting_approval_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_WAITING_APPROVAL,
        ]);

        // Waiting approval sales should not be approved-up
        $waitingApproved = Sale::approvedUp()->where('id', $sale->id)->first();
        $this->assertNull($waitingApproved, 'Waiting approval sale should not be approved-up');
    }

    public function test_rejected_sales_are_not_eligible()
    {
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_REJECTED,
        ]);

        // Rejected sales should not be approved-up
        $rejected = Sale::approvedUp()->where('id', $sale->id)->first();
        $this->assertNull($rejected, 'Rejected sale should not be approved-up');
    }

    public function test_fully_paid_sales_are_not_eligible()
    {
        // Create an unpaid sale
        $sale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // Verify unpaid sale has positive live due
        $this->assertTrue($sale->live_due_amount > 0);

        // Create a fully paid sale by allocating full SalePayment
        $sale2 = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
            'total_amount' => 500000,
        ]);

        // Get or create payment method for the payment
        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::firstOrCreate(
            ['name' => 'Cash Payment'],
            [
                'coa_id' => $this->getOrCreateCOA(),
                'is_cash' => true,
            ]
        );

        // Add a payment equal to total (making it fully paid)
        SalePayment::create([
            'sale_id' => $sale2->id,
            'amount' => 500000,
            'date' => now(),
            'reference' => 'TEST-FULL-PAYMENT',
            'payment_method' => 'CASH',  // Text field for payment method name
            'payment_method_id' => $paymentMethod->id,
            'status' => 'active',
        ]);

        // Refresh and verify
        $sale2->refresh();
        $this->assertFalse($sale2->live_due_amount > 0, 'Fully paid sale should have zero live due');
    }

    protected function getOrCreateCOA()
    {
        $coa = \Illuminate\Support\Facades\DB::table('chart_of_accounts')
            ->where('account_number', '1000')
            ->first();

        if ($coa) {
            return $coa->id;
        }

        return \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        // Query for approved-up non-archived with positive live due
        $eligible = Sale::approvedUp()
            ->whereNull('archived_at')
            ->whereLiveDueAmountGreaterThan(0)
            ->get();

        $this->assertTrue($eligible->contains('id', $approved->id), 'Approved sale should be eligible');
        $this->assertTrue($eligible->contains('id', $dispatched->id), 'Dispatched sale should be eligible');
        $this->assertFalse($eligible->contains('id', $drafted->id), 'Drafted sale should not be eligible');
        $this->assertFalse($eligible->contains('id', $archived->id), 'Archived sale should not be eligible');
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

        // Global query should find both sales across settings
        $eligible = Sale::where('customer_id', $this->customer->id)
            ->approvedUp()
            ->whereNull('archived_at')
            ->whereLiveDueAmountGreaterThan(0)
            ->get();

        $this->assertTrue($eligible->contains('id', $sale1->id), 'Sale from setting 1 should be found');
        $this->assertTrue($eligible->contains('id', $sale2->id), 'Sale from setting 2 should be found');
    }
}
