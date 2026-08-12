<?php

namespace Tests\Feature;

use App\Livewire\Purchase\CreateForm;
use App\Livewire\Purchase\EditForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseCreateEditSupplierNumberScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting1;
    private Setting $setting2;
    private Supplier $supplier1;
    private Supplier $supplier2;
    private Location $location1;
    private Location $location2;
    private \Modules\Product\Entities\Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a product for tests
        $this->product = \Modules\Product\Entities\Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'setting_id' => 1,
            'is_purchased' => 1,
            'is_sold' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'product_quantity' => 0,
        ]);

        // Create first business setting
        $this->setting1 = Setting::create([
            'company_name' => 'Business 1',
            'company_email' => 'test1@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify1@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
        ]);

        // Create second business setting
        $this->setting2 = Setting::create([
            'company_name' => 'Business 2',
            'company_email' => 'test2@company.com',
            'company_phone' => '654321',
            'notification_email' => 'notify2@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address 2',
        ]);

        // Create suppliers for each setting
        $this->supplier1 = Supplier::create([
            'supplier_name' => 'Supplier 1',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier1@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting1->id,
        ]);

        $this->supplier2 = Supplier::create([
            'supplier_name' => 'Supplier 2',
            'supplier_phone' => '456',
            'supplier_email' => 'supplier2@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting2->id,
        ]);

        // Create locations for each setting
        $this->location1 = Location::create([
            'name' => 'Location 1',
            'setting_id' => $this->setting1->id,
        ]);

        $this->location2 = Location::create([
            'name' => 'Location 2',
            'setting_id' => $this->setting2->id,
        ]);

        // Create permissions
        Permission::firstOrCreate(['name' => 'purchases.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.update', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchases.create');
        $this->user->givePermissionTo('purchases.update');
    }

    public function test_create_form_active_same_setting_supplier_number_blocks_creation(): void
    {
        // Create an active purchase with a supplier number in setting1
        $conflictingPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'CONFLICT-123',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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
            'archived_at' => null,
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting1->id]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token-1'])
            ->set('supplier_id', $this->supplier1->id)
            ->set('supplier_purchase_number', 'CONFLICT-123')
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(30)->format('Y-m-d'))
            ->set('payment_term', 1)
            ->call('submit')
            ->assertHasErrors('supplier_purchase_number');
    }

    public function test_create_form_archived_same_setting_supplier_number_allows_creation(): void
    {
        // Create an archived purchase with a supplier number in setting1
        $archivedPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'ARCHIVED-123',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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
        session(['setting_id' => $this->setting1->id]);

        // Should allow creating a new purchase with the same supplier number since the conflicting one is archived
        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token-2'])
            ->set('supplier_id', $this->supplier1->id)
            ->set('supplier_purchase_number', 'ARCHIVED-123')
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(30)->format('Y-m-d'))
            ->set('payment_term', 1)
            ->call('submit')
            // Supplier_purchase_number validation passes (archived numbers allowed), other validations may fail
            ->assertHasNoErrors('supplier_purchase_number');
    }

    public function test_create_form_different_setting_supplier_number_allows_creation(): void
    {
        // Create an active purchase with a supplier number in setting2
        $conflictingPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier2->id,
            'supplier_name' => $this->supplier2->supplier_name,
            'supplier_purchase_number' => 'SAME-NUMBER-123',
            'setting_id' => $this->setting2->id,
            'location_id' => $this->location2->id,
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
            'archived_at' => null,
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting1->id]);

        // Should allow creating a new purchase with the same supplier number in a different setting
        Livewire::test(CreateForm::class, ['idempotencyToken' => 'test-token-3'])
            ->set('supplier_id', $this->supplier1->id)
            ->set('supplier_purchase_number', 'SAME-NUMBER-123')
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(30)->format('Y-m-d'))
            ->set('payment_term', 1)
            ->call('submit')
            // Supplier_purchase_number validation passes (different setting), other validations may fail
            ->assertHasNoErrors('supplier_purchase_number');
    }

    public function test_edit_form_self_ignore_allows_same_number(): void
    {
        // Create a purchase with a detail to have a valid cart
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'ORIGINAL-456',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '1.000',
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting1->id]);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->set('supplier_purchase_number', 'ORIGINAL-456')
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(30)->format('Y-m-d'))
            ->set('payment_term', 1)
            ->call('submit')
            // Self is excluded from uniqueness check on edit, so no error
            ->assertHasNoErrors('supplier_purchase_number');
    }

    public function test_edit_form_active_same_setting_supplier_number_blocks_edit(): void
    {
        // Create an active purchase with a conflicting supplier number
        $conflictingPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'CONFLICT-789',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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
            'archived_at' => null,
        ]);

        // Create the purchase to be edited
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'ORIGINAL-456',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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
        session(['setting_id' => $this->setting1->id]);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->set('supplier_purchase_number', 'CONFLICT-789')
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(30)->format('Y-m-d'))
            ->set('payment_term', 1)
            ->call('submit')
            ->assertHasErrors('supplier_purchase_number');
    }

    public function test_edit_form_archived_same_setting_supplier_number_allows_edit(): void
    {
        // Create an archived purchase with a supplier number
        $archivedPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'ARCHIVED-789',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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

        // Create the purchase to be edited
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->supplier_name,
            'supplier_purchase_number' => 'ORIGINAL-456',
            'setting_id' => $this->setting1->id,
            'location_id' => $this->location1->id,
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

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => '1.000',
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 1000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting1->id]);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->set('supplier_purchase_number', 'ARCHIVED-789')
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(30)->format('Y-m-d'))
            ->set('payment_term', 1)
            ->call('submit')
            // Archived records don't block the validation
            ->assertHasNoErrors('supplier_purchase_number');
    }
}
