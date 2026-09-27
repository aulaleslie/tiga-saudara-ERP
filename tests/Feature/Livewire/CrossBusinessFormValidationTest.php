<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm as PurchaseCreateForm;
use App\Livewire\Sale\CreateForm as SaleCreateForm;
use App\Livewire\Purchase\EditForm as PurchaseEditForm;
use App\Livewire\Sale\EditForm as SaleEditForm;
use App\Models\User;
use Carbon\Carbon;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CrossBusinessFormValidationTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $activeBusiness;
    private Setting $pkpBusiness;
    private User $authorizedUser;
    private Supplier $supplier;
    private Customer $customer;
    private Product $product;
    private Tax $pkpTax;
    private PaymentTerm $paymentTerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->activeBusiness = Setting::create([
            'company_name' => 'Active Business',
            'company_email' => 'active@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-active@example.com',
            'footer_text' => 'Active Business Footer',
            'company_address' => '123 Active St',
            'is_pkp' => false,
            'document_prefix' => 'AB',
            'sale_prefix_document' => 'SL',
            'purchase_prefix_document' => 'PR',
        ]);

        $this->pkpBusiness = Setting::create([
            'company_name' => 'PKP Business',
            'company_email' => 'pkp@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-pkp@example.com',
            'footer_text' => 'PKP Business Footer',
            'company_address' => '456 PKP Ave',
            'is_pkp' => true,
            'document_prefix' => 'PB',
            'sale_prefix_document' => 'SL',
            'purchase_prefix_document' => 'PR',
        ]);

        $this->authorizedUser = User::factory()->create();

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '111111',
            'address' => 'Supplier Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->activeBusiness->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '222222',
            'address' => 'Customer Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->activeBusiness->id,
        ]);

        $category = Category::create([
            'created_by' => $this->authorizedUser->id,
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->activeBusiness->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'setting_id' => $this->activeBusiness->id,
            'category_id' => $category->id,
            'product_quantity' => 100,
            'product_cost' => 100000,
            'product_price' => 150000,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->activeBusiness->id,
        ]);

        // Create a global tax for PKP business
        $this->pkpTax = Tax::create([
            'name' => 'PKP Tax',
            'value' => 10,
            'is_default' => true,
        ]);

        Permission::create(['name' => 'documents.business.override']);
        $this->authorizedUser->givePermissionTo('documents.business.override');
    }

    public function test_purchase_create_form_switches_to_pkp_target_business(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        $component = Livewire::test(PurchaseCreateForm::class, [
            'idempotencyToken' => (string) Str::uuid(),
        ]);

        // Verify initial state is non-PKP
        $this->assertFalse($component->get('isPkp'));

        // Switch to PKP business and verify it requires tax
        $component->set('selectedSettingId', $this->pkpBusiness->id);
        $this->assertTrue($component->get('isPkp'));

        // Verify the business persisted correctly
        $this->assertEquals($this->pkpBusiness->id, $component->get('selectedSettingId'));
    }

    public function test_purchase_edit_form_switches_to_pkp_target_business(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a draft purchase in non-PKP business
        $purchase = Purchase::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->activeBusiness->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $component = Livewire::test(PurchaseEditForm::class, ['purchaseId' => $purchase->id]);

        // Verify initial state is non-PKP (active business)
        $this->assertFalse($component->get('isPkp'));

        // Switch to PKP business
        $component->set('selectedSettingId', $this->pkpBusiness->id);

        // Verify it's now PKP
        $this->assertTrue($component->get('isPkp'));
        $this->assertEquals($this->pkpBusiness->id, $component->get('selectedSettingId'));
    }

    public function test_purchase_create_persists_selected_business(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a supplier in PKP business
        $pkpSupplier = Supplier::create([
            'supplier_name' => 'PKP Supplier',
            'supplier_email' => 'pkp-supplier@example.com',
            'supplier_phone' => '333333',
            'address' => 'PKP Supplier Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $component = Livewire::test(PurchaseCreateForm::class, [
            'idempotencyToken' => (string) Str::uuid(),
        ]);

        // Switch to PKP business
        $component->set('selectedSettingId', $this->pkpBusiness->id);
        $this->assertTrue($component->get('isPkp'));
        $this->assertEquals($this->pkpBusiness->id, $component->get('selectedSettingId'));

        // Verify the state persists
        $this->assertTrue($component->get('isPkp'));
    }

    public function test_sale_create_form_switches_to_pkp_target_business(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        $component = Livewire::test(SaleCreateForm::class, [
            'idempotencyToken' => (string) Str::uuid(),
        ]);

        // Verify initial state is non-PKP
        $this->assertFalse($component->get('isPkp'));

        // Switch to PKP business
        $component->set('selectedSettingId', $this->pkpBusiness->id);
        $this->assertTrue($component->get('isPkp'));

        // Verify the business persisted correctly
        $this->assertEquals($this->pkpBusiness->id, $component->get('selectedSettingId'));
    }

    public function test_sale_edit_form_switches_to_pkp_target_business(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a draft sale in non-PKP business
        $sale = Sale::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->activeBusiness->id,
            'is_tax_included' => false,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'bank',
        ]);

        $component = Livewire::test(SaleEditForm::class, ['sale' => $sale]);

        // Verify initial state is non-PKP (active business)
        $this->assertFalse($component->get('isPkp'));

        // Switch to PKP business
        $component->set('selectedSettingId', $this->pkpBusiness->id);

        // Verify it's now PKP
        $this->assertTrue($component->get('isPkp'));
        $this->assertEquals($this->pkpBusiness->id, $component->get('selectedSettingId'));
    }

    public function test_purchase_create_with_pkp_target_and_no_tax_fails_validation(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a supplier in PKP business
        $pkpSupplier = Supplier::create([
            'supplier_name' => 'PKP Supplier',
            'supplier_email' => 'pkp-supplier@example.com',
            'supplier_phone' => '333333',
            'address' => 'PKP Supplier Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        // Create a payment term for PKP business
        $pkpPaymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $component = Livewire::test(PurchaseCreateForm::class, [
            'idempotencyToken' => (string) Str::uuid(),
        ]);

        // Switch to PKP business
        $component->set('selectedSettingId', $this->pkpBusiness->id);
        $this->assertTrue($component->get('isPkp'));

        // Add product to cart without tax (should trigger validation failure)
        Cart::instance('purchase')->add([
            'id'        => $this->product->id,
            'name'      => $this->product->product_name,
            'qty'       => 1,
            'price'     => $this->product->product_price,
            'weight'    => 0,
            'options'   => [
                'product_id' => $this->product->id,
                'product_tax' => null,  // No tax selected
                'sub_total' => $this->product->product_price,
            ],
        ]);

        // Call submit - should fail validation
        $component->call('submit', $pkpSupplier->id, $pkpPaymentTerm->id);

        // Verify no purchase was created
        $this->assertEquals(0, Purchase::count());
    }

    public function test_purchase_create_with_pkp_target_and_global_tax_succeeds(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a global tax (not scoped to any business)
        $globalTax = Tax::create([
            'name' => 'Global Tax',
            'value' => 10,
        ]);

        $pkpSupplier = Supplier::create([
            'supplier_name' => 'PKP Supplier',
            'supplier_email' => 'pkp-supplier@example.com',
            'supplier_phone' => '333333',
            'address' => 'PKP Supplier Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $pkpPaymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->pkpBusiness->id,
        ]);

        // Test creating a purchase directly with cross-business setting_id and global tax
        $purchase = Purchase::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'supplier_id' => $pkpSupplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $pkpPaymentTerm->id,
            'setting_id' => $this->pkpBusiness->id,  // Cross-business setting
            'is_tax_included' => true,
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'payment_method' => 'bank',
            'tax_id' => $globalTax->id,  // Global tax (not scoped to business)
        ]);

        // Verify purchase was created with correct setting_id and global tax
        $this->assertNotNull($purchase->id);
        $this->assertEquals($this->pkpBusiness->id, $purchase->setting_id);
        $this->assertEquals($globalTax->id, $purchase->tax_id);
    }

    public function test_purchase_create_with_pkp_target_and_matching_tax_succeeds(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a product in PKP business
        $pkpCategory = Category::create([
            'created_by' => $this->authorizedUser->id,
            'category_name' => 'PKP Category',
            'category_code' => 'PKPCAT',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $pkpProduct = Product::create([
            'product_name' => 'PKP Product',
            'product_code' => 'PKP-001',
            'setting_id' => $this->pkpBusiness->id,
            'category_id' => $pkpCategory->id,
            'product_quantity' => 100,
            'product_cost' => 100000,
            'product_price' => 150000,
        ]);

        $pkpSupplier = Supplier::create([
            'supplier_name' => 'PKP Supplier',
            'supplier_email' => 'pkp-supplier@example.com',
            'supplier_phone' => '333333',
            'address' => 'PKP Supplier Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $pkpPaymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->pkpBusiness->id,
        ]);

        // Test creating a purchase directly with cross-business setting_id and matching tax
        $purchase = Purchase::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'supplier_id' => $pkpSupplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $pkpPaymentTerm->id,
            'setting_id' => $this->pkpBusiness->id,  // Cross-business setting
            'is_tax_included' => true,
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'payment_method' => 'bank',
            'tax_id' => $this->pkpTax->id,  // Tax from matching business
        ]);

        // Verify purchase was created with correct setting_id
        $this->assertNotNull($purchase->id);
        $this->assertEquals($this->pkpBusiness->id, $purchase->setting_id);
        $this->assertEquals($this->pkpTax->id, $purchase->tax_id);
        $this->assertNotNull($purchase->reference);
        $this->assertStringContainsString('PB-PR', $purchase->reference);  // Should use PKP business prefix
    }

    public function test_sale_create_with_pkp_target_and_no_tax_fails_validation(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a product in PKP business
        $pkpCategory = Category::create([
            'created_by' => $this->authorizedUser->id,
            'category_name' => 'PKP Category',
            'category_code' => 'PKPCAT',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $pkpProduct = Product::create([
            'product_name' => 'PKP Product',
            'product_code' => 'PKP-001',
            'setting_id' => $this->pkpBusiness->id,
            'category_id' => $pkpCategory->id,
            'product_quantity' => 100,
            'product_cost' => 100000,
            'product_price' => 150000,
        ]);

        $pkpCustomer = Customer::create([
            'customer_name' => 'PKP Customer',
            'customer_email' => 'pkp-customer@example.com',
            'customer_phone' => '444444',
            'address' => 'PKP Customer Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $pkpPaymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $component = Livewire::test(SaleCreateForm::class, [
            'idempotencyToken' => (string) Str::uuid(),
        ]);

        // Switch to PKP business
        $component->set('selectedSettingId', $this->pkpBusiness->id);
        $this->assertTrue($component->get('isPkp'));

        // Add product to cart without tax
        Cart::instance('sale')->add([
            'id'        => $pkpProduct->id,
            'name'      => $pkpProduct->product_name,
            'qty'       => 1,
            'price'     => $pkpProduct->product_price,
            'weight'    => 0,
            'options'   => [
                'product_id' => $pkpProduct->id,
                'product_tax' => null,  // No tax
                'sub_total' => $pkpProduct->product_price,
            ],
        ]);

        // Call submit - should fail validation
        $component->call('submit', $pkpCustomer->id, $pkpPaymentTerm->id);

        // Verify no sale was created
        $this->assertEquals(0, Sale::count());
    }

    public function test_sale_create_with_pkp_target_and_matching_tax_succeeds(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a customer in PKP business
        $pkpCustomer = Customer::create([
            'customer_name' => 'PKP Customer',
            'customer_email' => 'pkp-customer@example.com',
            'customer_phone' => '444444',
            'address' => 'PKP Customer Address',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->pkpBusiness->id,
        ]);

        $pkpPaymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->pkpBusiness->id,
        ]);

        // Test creating a sale directly with cross-business setting_id and matching tax
        $sale = Sale::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'customer_id' => $pkpCustomer->id,
            'customer_name' => $pkpCustomer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $pkpPaymentTerm->id,
            'setting_id' => $this->pkpBusiness->id,  // Cross-business setting
            'is_tax_included' => true,
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'payment_method' => 'bank',
            'tax_id' => $this->pkpTax->id,  // Tax from matching business
        ]);

        // Verify sale was created with correct setting_id
        $this->assertNotNull($sale->id);
        $this->assertEquals($this->pkpBusiness->id, $sale->setting_id);
        $this->assertEquals($this->pkpTax->id, $sale->tax_id);
        $this->assertNotNull($sale->reference);
        $this->assertStringContainsString('PB-SL', $sale->reference);  // Should use PKP business prefix
    }

    public function test_purchase_draft_can_use_shared_tax_across_pkp_businesses(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a second PKP business
        $secondPkpBusiness = Setting::create([
            'company_name' => 'Second PKP',
            'company_email' => 'second-pkp@example.com',
            'company_phone' => '777777',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-second@example.com',
            'footer_text' => 'Second PKP Footer',
            'company_address' => '789 Second PKP Ave',
            'is_pkp' => true,
            'document_prefix' => 'SP',
            'sale_prefix_document' => 'SL',
            'purchase_prefix_document' => 'PR',
        ]);

        // Create a shared global tax that both PKP businesses can use
        $sharedTax = Tax::create([
            'name' => 'Shared Global Tax',
            'value' => 10,
            'is_default' => true,
        ]);

        // Create purchase in first PKP business
        $purchase1 = Purchase::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->pkpBusiness->id,
            'is_tax_included' => true,
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'payment_method' => 'bank',
            'tax_id' => $sharedTax->id,
            'reference' => 'PB-PR-2024-01-001',
        ]);

        // Verify purchase uses shared tax in first PKP business
        $this->assertEquals($this->pkpBusiness->id, $purchase1->setting_id);
        $this->assertEquals($sharedTax->id, $purchase1->tax_id);

        // Create purchase in second PKP business with same shared tax
        $purchase2 = Purchase::create([
            'date' => '2024-01-16',
            'due_date' => '2024-01-16',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $secondPkpBusiness->id,
            'is_tax_included' => true,
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
            'payment_method' => 'bank',
            'tax_id' => $sharedTax->id,  // Same shared tax across businesses
            'reference' => 'SP-PR-2024-01-001',
        ]);

        // Verify purchase uses same shared tax in second PKP business
        $this->assertEquals($secondPkpBusiness->id, $purchase2->setting_id);
        $this->assertEquals($sharedTax->id, $purchase2->tax_id);
        $this->assertEquals($purchase1->tax_id, $purchase2->tax_id);  // Both use same tax
    }

    public function test_sale_draft_can_use_shared_tax_across_pkp_businesses(): void
    {
        session()->put('setting_id', $this->activeBusiness->id);
        $this->actingAs($this->authorizedUser);

        // Create a second PKP business
        $secondPkpBusiness = Setting::create([
            'company_name' => 'Second PKP',
            'company_email' => 'second-pkp@example.com',
            'company_phone' => '777777',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-second@example.com',
            'footer_text' => 'Second PKP Footer',
            'company_address' => '789 Second PKP Ave',
            'is_pkp' => true,
            'document_prefix' => 'SP',
            'sale_prefix_document' => 'SL',
            'purchase_prefix_document' => 'PR',
        ]);

        // Create a shared global tax that both PKP businesses can use
        $sharedTax = Tax::create([
            'name' => 'Shared Global Tax',
            'value' => 10,
            'is_default' => true,
        ]);

        // Create sale in first PKP business
        $sale1 = Sale::create([
            'date' => '2024-01-15',
            'due_date' => '2024-01-15',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->pkpBusiness->id,
            'is_tax_included' => true,
            'total_amount' => 150000,
            'paid_amount' => 0,
            'due_amount' => 150000,
            'payment_method' => 'bank',
            'tax_id' => $sharedTax->id,
            'reference' => 'PB-SL-2024-01-001',
        ]);

        // Verify sale uses shared tax in first PKP business
        $this->assertEquals($this->pkpBusiness->id, $sale1->setting_id);
        $this->assertEquals($sharedTax->id, $sale1->tax_id);

        // Create sale in second PKP business with same shared tax
        $sale2 = Sale::create([
            'date' => '2024-01-16',
            'due_date' => '2024-01-16',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $secondPkpBusiness->id,
            'is_tax_included' => true,
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
            'payment_method' => 'bank',
            'tax_id' => $sharedTax->id,  // Same shared tax across businesses
            'reference' => 'SP-SL-2024-01-001',
        ]);

        // Verify sale uses same shared tax in second PKP business
        $this->assertEquals($secondPkpBusiness->id, $sale2->setting_id);
        $this->assertEquals($sharedTax->id, $sale2->tax_id);
        $this->assertEquals($sale1->tax_id, $sale2->tax_id);  // Both use same tax
    }
}
