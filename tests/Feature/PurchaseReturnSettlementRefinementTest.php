<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\People\Entities\Supplier;
use Tests\TestCase;

class PurchaseReturnSettlementRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $supplier;
    protected $location;
    protected $product;
    protected $purchase;
    protected $purchaseReturn;

    public bool $canViewPrice = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        
        Gate::define('purchaseReturnSettlements.submit', fn() => true);
        Gate::define('purchaseReturns.viewPrice', fn() => $this->canViewPrice);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '123456789',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $setting->id,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_unit' => 'PCS',
            'product_price' => 1000,
            'product_cost' => 800,
            'category_id' => $category->id,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => 'Note',
            'setting_id' => $setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-001',
            'supplier_purchase_number' => 'SUP-REF-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'setting_id' => $setting->id,
        ]);

        // Add purchase detail so it matches loadUnpaidPurchases filter
        $this->purchase->purchaseDetails()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'setting_id' => $setting->id,
        ]);

        $this->purchaseReturn->purchaseReturnDetails()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
    }

    /** @test */
    public function it_displays_translated_methods_in_dropdown()
    {
        // To make "Simpan Sebagai DP" visible for non-serial:
        // 1. There must be an unpaid purchase (Target)
        // 2. There must NOT be an unpaid purchase with the same product (priority goes to "Ubah Nota")
        
        // The purchase in setup has the product. Let's delete its details so it becomes a generic unpaid purchase.
        $this->purchase->purchaseDetails()->delete();

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertSee('Perbaikan Produk')
            ->assertSee('Ubah Nota Pembelian')
            ->assertDontSee('Simpan Sebagai DP')
            ->assertDontSee('Pengembalian Tunai');
    }

    /** @test */
    public function it_uses_supplier_purchase_number_in_unpaid_purchases_list()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->set('settlementLines.0.method', 'MODIFY_PURCHASE')
            ->assertSee('SUP-REF-001'); // supplier_purchase_number
    }

    /** @test */
    public function it_shows_nominal_for_modify_purchase_and_hides_for_others()
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            // Case 1: PRODUCT_REPAIR -> Should not see input, should see "-"
            ->set('settlementLines.0.method', 'PRODUCT_REPAIR')
            ->assertDontSeeHtml('input["settlementLines.0.nominal"]')
            ->assertSee('-')
            
            // Case 2: MODIFY_PURCHASE -> Should see input
            ->set('settlementLines.0.method', 'MODIFY_PURCHASE')
            ->assertSeeHtml('name="settlementLines.0.nominal"');
    }

    /** @test */
    public function it_hides_nominal_column_if_no_permission()
    {
        $this->canViewPrice = false;

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $this->purchaseReturn->id])
            ->assertDontSee('Nilai Penyelesaian');
    }
}
