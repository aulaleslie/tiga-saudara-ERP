<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierPurchaseNumberScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Setting $anotherSetting;
    private Supplier $supplier;
    private PaymentTerm $paymentTerm;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->anotherSetting = Setting::create([
            'company_name' => 'Another Company',
            'company_email' => 'another@company.com',
            'company_phone' => '654321',
            'notification_email' => 'another@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'days' => 0,
            'setting_id' => $this->setting->id,
        ]);

        // Create permissions if they don't exist
        Permission::firstOrCreate(['name' => 'purchases.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.update', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchases.create');
        $this->user->givePermissionTo('purchases.update');
    }

    public function test_active_same_setting_supplier_number_blocks_creation(): void
    {
        // Create an active purchase with supplier number
        $activePurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'SUP-123',
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PO-002',
            'supplier_purchase_number' => 'SUP-123',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'tax_id' => null,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'payment_term' => $this->paymentTerm->id,
            'note' => 'Test note',
            'cart' => [],
        ]);

        $response->assertSessionHasErrors('supplier_purchase_number');
    }

    public function test_archived_supplier_number_allows_creation(): void
    {
        // Create an archived purchase with supplier number
        $archivedPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'SUP-123',
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
            'archived_at' => now(),
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PO-002',
            'supplier_purchase_number' => 'SUP-123',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'tax_id' => null,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'payment_term' => $this->paymentTerm->id,
            'note' => 'Test note',
            'cart' => [],
        ]);

        // Should fail on cart validation, not on supplier_purchase_number
        $response->assertSessionHasErrors('cart');
        $response->assertSessionDoesntHaveErrors('supplier_purchase_number');
    }

    public function test_edit_excludes_current_purchase(): void
    {
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'SUP-123',
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Pending',
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        // Update the purchase without changing the supplier number
        $response = $this->put(route('purchases.update', $purchase), [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PO-001',
            'supplier_purchase_number' => 'SUP-123',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'tax_id' => null,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'payment_term' => $this->paymentTerm->id,
            'note' => 'Test note',
            'cart' => [],
        ]);

        // Should fail on cart validation, not on supplier_purchase_number
        $response->assertSessionHasErrors('cart');
        $response->assertSessionDoesntHaveErrors('supplier_purchase_number');
    }

    public function test_supplier_number_scoped_to_same_setting(): void
    {
        // Create purchase in one setting
        $purchase1 = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'SUP-123',
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        // Create another setting and supplier
        $anotherSupplier = Supplier::create([
            'supplier_name' => 'Another Supplier',
            'supplier_phone' => '456',
            'supplier_email' => 'another@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->anotherSetting->id,
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->anotherSetting->id]);

        // Should be able to create purchase in another setting with same supplier number
        $response = $this->post(route('purchases.store'), [
            'supplier_id' => $anotherSupplier->id,
            'reference' => 'PO-002',
            'supplier_purchase_number' => 'SUP-123',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'tax_id' => null,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'payment_term' => $this->paymentTerm->id,
            'note' => 'Test note',
            'cart' => [],
        ]);

        // Should fail on cart validation, not on supplier_purchase_number
        $response->assertSessionHasErrors('cart');
        $response->assertSessionDoesntHaveErrors('supplier_purchase_number');
    }
}
