<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Authorization for the lifecycle-specific edit permissions.
 *
 * Covers each new permission in its granted and withheld state for an ordinary
 * user, plus the Super Admin path, which reaches the same states through the
 * Gate::before rule without any explicit assignment.
 */
class MonetaryEditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create(['is_pkp' => false]);
        session(['setting_id' => $this->setting->id]);

        foreach ([
            'purchases.update',
            'purchases.approved.edit',
            'purchases.received.monetary.edit',
            'purchases.received.correct',
            'sales.edit',
            'sales.approved.edit',
            'sales.dispatched.monetary.edit',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'cust@test.com',
            'customer_phone' => '1234',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    private function userWith(array $abilities): User
    {
        $user = User::factory()->create();

        if ($abilities) {
            $user->givePermissionTo($abilities);
        }

        // Spatie caches the permission map process-wide; a stale map leaks in
        // from earlier suites during a full run and denies the route gate.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function superAdmin(): User
    {
        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makePurchase(string $status): Purchase
    {
        return Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . fake()->unique()->numerify('####'),
            'status' => $status,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 10000,
            'due_amount' => 10000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
    }

    private function makeSale(string $status): Sale
    {
        return Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SL-' . fake()->unique()->numerify('####'),
            'status' => $status,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 10000,
            'due_amount' => 10000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
    }

    // --- Purchase: post-receipt monetary permission -------------------------

    public function test_received_purchase_denied_without_monetary_permission(): void
    {
        $this->actingAs($this->userWith(['purchases.update']));
        $purchase = $this->makePurchase(Purchase::STATUS_RECEIVED);

        $this->assertSame(Purchase::EDIT_MODE_NONE, $purchase->resolveEditMode());
        $this->get(route('purchases.edit', $purchase->id))->assertStatus(403);
    }

    public function test_received_purchase_denied_without_ordinary_update_permission(): void
    {
        // The lifecycle permission alone is not enough; ordinary edit authority
        // remains a prerequisite.
        $this->actingAs($this->userWith(['purchases.received.monetary.edit']));
        $purchase = $this->makePurchase(Purchase::STATUS_RECEIVED);

        $this->assertSame(Purchase::EDIT_MODE_NONE, $purchase->resolveEditMode());
        $this->get(route('purchases.edit', $purchase->id))->assertStatus(403);
    }

    public function test_received_purchase_allowed_with_both_permissions(): void
    {
        $this->actingAs($this->userWith(['purchases.update', 'purchases.received.monetary.edit']));
        $purchase = $this->makePurchase(Purchase::STATUS_RECEIVED);

        $this->assertSame(Purchase::EDIT_MODE_MONETARY_ONLY, $purchase->resolveEditMode());
    }

    public function test_partially_received_purchase_follows_the_same_rule(): void
    {
        $this->actingAs($this->userWith(['purchases.update', 'purchases.received.monetary.edit']));

        $this->assertSame(
            Purchase::EDIT_MODE_MONETARY_ONLY,
            $this->makePurchase(Purchase::STATUS_RECEIVED_PARTIALLY)->resolveEditMode()
        );
    }

    // --- Purchase: approved-document permission -----------------------------

    public function test_approved_purchase_denied_without_approved_edit_permission(): void
    {
        $this->actingAs($this->userWith(['purchases.update']));
        $purchase = $this->makePurchase(Purchase::STATUS_APPROVED);

        $this->assertSame(Purchase::EDIT_MODE_NONE, $purchase->resolveEditMode());
        $this->get(route('purchases.edit', $purchase->id))->assertStatus(403);
    }

    public function test_approved_unreceived_purchase_keeps_full_edit(): void
    {
        $this->actingAs($this->userWith(['purchases.update', 'purchases.approved.edit']));

        // Full mode: quantity editing stays available before receiving.
        $this->assertSame(
            Purchase::EDIT_MODE_FULL,
            $this->makePurchase(Purchase::STATUS_APPROVED)->resolveEditMode()
        );
    }

    public function test_drafted_purchase_keeps_full_edit(): void
    {
        // Pre-approval states keep their historical behaviour; the route and
        // controller `purchases.update` gates guard entry to the form.
        $this->actingAs($this->userWith(['purchases.update']));

        $this->assertSame(
            Purchase::EDIT_MODE_FULL,
            $this->makePurchase(Purchase::STATUS_DRAFTED)->resolveEditMode()
        );

        $this->assertSame(
            Purchase::EDIT_MODE_FULL,
            $this->makePurchase(Purchase::STATUS_REJECTED)->resolveEditMode()
        );
    }

    // --- Sale: post-dispatch monetary permission ----------------------------

    public function test_dispatched_sale_denied_without_monetary_permission(): void
    {
        $this->actingAs($this->userWith(['sales.edit']));
        $sale = $this->makeSale(Sale::STATUS_DISPATCHED);

        $this->assertSame(Sale::EDIT_MODE_NONE, $sale->resolveEditMode());
        $this->get(route('sales.edit', $sale->id))->assertStatus(403);
    }

    public function test_dispatched_sale_denied_without_ordinary_edit_permission(): void
    {
        $this->actingAs($this->userWith(['sales.dispatched.monetary.edit']));
        $sale = $this->makeSale(Sale::STATUS_DISPATCHED);

        $this->assertSame(Sale::EDIT_MODE_NONE, $sale->resolveEditMode());
        $this->get(route('sales.edit', $sale->id))->assertStatus(403);
    }

    public function test_dispatched_sale_allowed_with_both_permissions(): void
    {
        $this->actingAs($this->userWith(['sales.edit', 'sales.dispatched.monetary.edit']));

        $this->assertSame(
            Sale::EDIT_MODE_MONETARY_ONLY,
            $this->makeSale(Sale::STATUS_DISPATCHED)->resolveEditMode()
        );
        $this->assertSame(
            Sale::EDIT_MODE_MONETARY_ONLY,
            $this->makeSale(Sale::STATUS_DISPATCHED_PARTIALLY)->resolveEditMode()
        );
    }

    public function test_approved_undispatched_sale_keeps_full_edit(): void
    {
        $this->actingAs($this->userWith(['sales.edit', 'sales.approved.edit']));

        $this->assertSame(
            Sale::EDIT_MODE_FULL,
            $this->makeSale(Sale::STATUS_APPROVED)->resolveEditMode()
        );
    }

    public function test_approved_sale_denied_without_approved_edit_permission(): void
    {
        $this->actingAs($this->userWith(['sales.edit']));

        $this->assertSame(
            Sale::EDIT_MODE_NONE,
            $this->makeSale(Sale::STATUS_APPROVED)->resolveEditMode()
        );
    }

    // --- Super Admin --------------------------------------------------------

    public function test_super_admin_reaches_every_mode_without_explicit_permissions(): void
    {
        $superAdmin = $this->superAdmin();
        $this->assertEmpty($superAdmin->getAllPermissions());

        $this->actingAs($superAdmin);

        $this->assertSame(
            Purchase::EDIT_MODE_MONETARY_ONLY,
            $this->makePurchase(Purchase::STATUS_RECEIVED)->resolveEditMode()
        );
        $this->assertSame(
            Purchase::EDIT_MODE_FULL,
            $this->makePurchase(Purchase::STATUS_APPROVED)->resolveEditMode()
        );
        $this->assertSame(
            Sale::EDIT_MODE_MONETARY_ONLY,
            $this->makeSale(Sale::STATUS_DISPATCHED)->resolveEditMode()
        );
        $this->assertSame(
            Sale::EDIT_MODE_FULL,
            $this->makeSale(Sale::STATUS_APPROVED)->resolveEditMode()
        );
    }

    public function test_super_admin_still_obeys_lifecycle_rules(): void
    {
        // The bypass covers permissions, not lifecycle: a fully returned
        // document is editable by nobody.
        $this->actingAs($this->superAdmin());

        $this->assertSame(
            Purchase::EDIT_MODE_NONE,
            $this->makePurchase(Purchase::STATUS_RETURNED)->resolveEditMode()
        );
        $this->assertSame(
            Sale::EDIT_MODE_NONE,
            $this->makeSale(Sale::STATUS_RETURNED)->resolveEditMode()
        );
    }

    // --- Existing correction workflow is unaffected --------------------------

    public function test_correction_permission_is_independent_of_monetary_permission(): void
    {
        // A user holding only the correction permission gets no monetary-edit
        // access, and vice versa: the two workflows stay separate.
        $corrector = $this->userWith(['purchases.update', 'purchases.received.correct']);
        $this->actingAs($corrector);

        $purchase = $this->makePurchase(Purchase::STATUS_RECEIVED);
        $this->assertSame(Purchase::EDIT_MODE_NONE, $purchase->resolveEditMode());
        $this->assertTrue($corrector->can('purchases.received.correct'));

        $editor = $this->userWith(['purchases.update', 'purchases.received.monetary.edit']);
        $this->actingAs($editor);

        $this->assertSame(Purchase::EDIT_MODE_MONETARY_ONLY, $purchase->fresh()->resolveEditMode());
        $this->assertFalse($editor->can('purchases.received.correct'));
    }
}
