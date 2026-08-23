<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MasterDataLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private MasterDataLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'company_address' => 'Test Address',
            'notification_email' => 'notif@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test Footer',
        ]);

        $role = Role::create(['name' => 'Admin']);
        $this->user = User::factory()->create();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $permissions = [
            'products.access', 'products.create', 'products.edit', 'products.delete',
            'customers.access', 'customers.create', 'customers.edit', 'customers.delete',
            'suppliers.access', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
            'taxes.access', 'taxes.create', 'taxes.edit', 'taxes.delete',
            'paymentMethods.access', 'paymentMethods.create', 'paymentMethods.edit', 'paymentMethods.delete',
            'paymentTerms.access', 'paymentTerms.create', 'paymentTerms.edit', 'paymentTerms.delete',
            'locations.access', 'locations.create', 'locations.edit',
            'units.access', 'units.create', 'units.edit', 'units.delete',
            'chartOfAccounts.access', 'chartOfAccounts.create', 'chartOfAccounts.edit', 'chartOfAccounts.delete',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $role->syncPermissions($permissions);
        $this->user->assignRole($role);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->lifecycleService = app(MasterDataLifecycleService::class);
    }

    public function test_product_deactivation_and_reactivation_lifecycle(): void
    {
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'setting_id' => $this->setting->id, 'is_active' => true]);

        $product = Product::create([
            'product_name' => 'Sample Product',
            'product_code' => 'SP001',
            'product_unit' => $unit->id,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'setting_id' => $this->setting->id,
            'is_active' => true,
        ]);

        $this->assertTrue($product->fresh()->is_active);
        $this->assertCount(1, Product::active()->get());

        // Toggle via controller
        $response = $this->patch(route('products.toggle-status', $product->id));
        $response->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);
        $this->assertCount(0, Product::active()->get());
        $this->assertCount(1, Product::inactive()->get());

        // Reactivate
        $response = $this->patch(route('products.toggle-status', $product->id));
        $response->assertRedirect();
        $this->assertTrue($product->fresh()->is_active);
        $this->assertCount(1, Product::active()->get());

        // Non-destructive destroy
        $deleteResponse = $this->delete(route('products.destroy', $product->id));
        $deleteResponse->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_customer_deactivation_and_reactivation_lifecycle(): void
    {
        $customer = Customer::create([
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '08123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'is_active' => true,
        ]);

        $this->assertTrue($customer->fresh()->is_active);

        // Deactivate via toggle
        $this->patch(route('customers.toggle-status', $customer->id))->assertRedirect();
        $this->assertFalse($customer->fresh()->is_active);
        $this->assertCount(0, Customer::active()->get());

        // Reactivate
        $this->patch(route('customers.toggle-status', $customer->id))->assertRedirect();
        $this->assertTrue($customer->fresh()->is_active);

        // Destroy deactivates safely
        $this->delete(route('customers.destroy', $customer->id))->assertRedirect();
        $this->assertFalse($customer->fresh()->is_active);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_tax_deactivation_reassigns_default_if_needed(): void
    {
        $tax1 = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true, 'is_active' => true]);
        $tax2 = Tax::create(['name' => 'PPN 12%', 'value' => 12, 'is_default' => false, 'is_active' => true]);

        $this->lifecycleService->deactivate($tax1);

        $this->assertFalse($tax1->fresh()->is_active);
        $this->assertFalse((bool) $tax1->fresh()->is_default);
        $this->assertTrue((bool) $tax2->fresh()->is_default);
    }

    public function test_location_single_active_guard(): void
    {
        $location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
            'is_consignment' => false,
            'is_active' => true,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->lifecycleService->deactivate($location);
    }

    public function test_chart_of_account_guards_child_and_payment_method_references(): void
    {
        $parent = ChartOfAccount::create([
            'name' => 'Kas Induk',
            'account_number' => '1001',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
            'is_active' => true,
        ]);

        $child = ChartOfAccount::create([
            'name' => 'Kas Kasir',
            'account_number' => '1002',
            'category' => 'Kas & Bank',
            'parent_account_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'is_active' => true,
        ]);

        // Guard parent deactivation while child is active
        try {
            $this->lifecycleService->deactivate($parent);
            $this->fail('Expected parent deactivation to fail due to active child account.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('sub-akun aktif', $e->getMessage());
        }

        // Deactivate child first
        $this->lifecycleService->deactivate($child);
        $this->assertFalse($child->fresh()->is_active);

        // Link payment method to parent
        $pm = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $parent->id,
            'is_cash' => true,
            'is_active' => true,
        ]);

        // Guard parent deactivation while linked payment method is active
        try {
            $this->lifecycleService->deactivate($parent);
            $this->fail('Expected parent deactivation to fail due to active payment method dependency.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('metode pembayaran', $e->getMessage());
        }

        // Deactivate payment method first
        $this->lifecycleService->deactivate($pm);
        $this->assertFalse($pm->fresh()->is_active);

        // Now parent deactivation succeeds
        $this->lifecycleService->deactivate($parent);
        $this->assertFalse($parent->fresh()->is_active);
    }

    /**
     * @dataProvider stockBucketProvider
     */
    public function test_location_deactivation_blocked_by_any_stock_bucket(string $bucketColumn): void
    {
        $primaryLocation = Location::create([
            'name' => 'Primary Warehouse',
            'setting_id' => $this->setting->id,
            'is_consignment' => false,
            'is_active' => true,
        ]);

        $secondaryLocation = Location::create([
            'name' => 'Secondary Warehouse',
            'setting_id' => $this->setting->id,
            'is_consignment' => false,
            'is_active' => true,
        ]);

        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'setting_id' => $this->setting->id, 'is_active' => true]);
        $product = Product::create([
            'product_name' => 'Stocked Product',
            'product_code' => 'SP-STOCK',
            'product_unit' => $unit->id,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'setting_id' => $this->setting->id,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $secondaryLocation->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            $bucketColumn => 5,
        ]);

        try {
            $this->lifecycleService->deactivate($secondaryLocation);
            $this->fail("Expected deactivation to be blocked by stock bucket '{$bucketColumn}'.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('stok aktif', $e->getMessage());
        }
    }

    public static function stockBucketProvider(): array
    {
        return [
            'quantity' => ['quantity'],
            'quantity_tax' => ['quantity_tax'],
            'quantity_non_tax' => ['quantity_non_tax'],
            'broken_quantity' => ['broken_quantity'],
            'broken_quantity_tax' => ['broken_quantity_tax'],
            'broken_quantity_non_tax' => ['broken_quantity_non_tax'],
        ];
    }

    public function test_location_deactivation_blocked_only_by_enabled_sales_location_assignment(): void
    {
        $primaryLocation = Location::create([
            'name' => 'Primary Warehouse',
            'setting_id' => $this->setting->id,
            'is_consignment' => false,
            'is_active' => true,
        ]);

        $salesLocation = Location::create([
            'name' => 'Sales Location',
            'setting_id' => $this->setting->id,
            'is_consignment' => false,
            'is_active' => true,
        ]);

        // Location::booted() auto-creates an enabled SettingSaleLocation assignment
        // on creation; reuse that row rather than creating a duplicate.
        $assignment = SettingSaleLocation::where('location_id', $salesLocation->id)->firstOrFail();
        $this->assertTrue($assignment->is_enabled);

        try {
            $this->lifecycleService->deactivate($salesLocation);
            $this->fail('Expected deactivation to be blocked by an enabled sales-location assignment.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('lokasi penjualan', $e->getMessage());
        }

        // Disabling the assignment (not deleting it) must unblock deactivation.
        $assignment->update(['is_enabled' => false]);

        $this->lifecycleService->deactivate($salesLocation);
        $this->assertFalse($salesLocation->fresh()->is_active);
    }

    public function test_tax_default_reassignment_is_atomic(): void
    {
        $tax1 = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true, 'is_active' => true]);
        $tax2 = Tax::create(['name' => 'PPN 12%', 'value' => 12, 'is_default' => false, 'is_active' => true]);

        $this->lifecycleService->deactivate($tax1);

        $tax1->refresh();
        $tax2->refresh();

        // Both writes must have committed together: the old default is inactive and
        // no longer default, and the replacement is now the sole active default.
        $this->assertFalse($tax1->is_active);
        $this->assertFalse((bool) $tax1->is_default);
        $this->assertTrue($tax2->is_active);
        $this->assertTrue((bool) $tax2->is_default);
        $this->assertSame(1, Tax::where('is_default', true)->count());
    }

    public function test_tax_default_reassignment_rolls_back_fully_on_injected_failure(): void
    {
        $tax1 = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true, 'is_active' => true]);
        $tax2 = Tax::create(['name' => 'PPN 12%', 'value' => 12, 'is_default' => false, 'is_active' => true]);

        // Force the second write (promoting tax2 to default) to fail mid-transaction,
        // proving the whole deactivate() operation is atomic: if the replacement
        // update fails, the original tax's deactivation/is_default change must not
        // be left partially applied either.
        Tax::saving(function (Tax $tax) use ($tax2) {
            if ($tax->is($tax2) && $tax->is_default) {
                throw new \RuntimeException('Injected failure during default reassignment.');
            }
        });

        try {
            $this->lifecycleService->deactivate($tax1);
            $this->fail('Expected the injected failure to propagate out of deactivate().');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected failure during default reassignment.', $e->getMessage());
        } finally {
            Tax::flushEventListeners();
        }

        $tax1->refresh();
        $tax2->refresh();

        // Nothing must have committed: tax1 remains the active default, tax2 remains
        // non-default and active, exactly as before the failed operation.
        $this->assertTrue($tax1->is_active);
        $this->assertTrue((bool) $tax1->is_default);
        $this->assertTrue($tax2->is_active);
        $this->assertFalse((bool) $tax2->is_default);
        $this->assertSame(1, Tax::where('is_default', true)->count());
    }
}
