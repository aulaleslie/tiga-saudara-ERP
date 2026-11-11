<?php

namespace Tests\Feature;

use App\Livewire\Purchase\CreateForm;
use App\Livewire\Purchase\EditForm;
use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseManualLineTotalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Currency $currency;
    protected Unit $unit;
    protected Supplier $supplier;
    protected PaymentTerm $paymentTerm;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'is_active' => true,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'Cash',
            'longevity' => 0,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456789',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'payment_term_id' => $this->paymentTerm->id,
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'PROD-01',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1200000,
            'product_price' => 1200000,
            'product_unit' => 'PCS',
            'unit_id' => $this->unit->id,
        ]);

        Cart::instance('purchase')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('purchase')->destroy();
        parent::tearDown();
    }

    public function test_create_purchase_with_overridden_line_total_persists_derived_unit_price_and_displays_on_show(): void
    {
        // 1. Mount CreateForm first (it clears the cart on mount)
        $createComponent = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-create-line-total'])
            ->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->paymentTerm->id);

        // 2. Mount ProductCart and add product (unit price Rp1.200.000, qty 2)
        $cartComponent = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'PCS',
                'last_purchase_price' => 1200000,
                'average_purchase_price' => 1200000,
            ]);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $cartComponent->set("quantity.$rowId", 2)
            ->call('updateQuantity', $rowId, $this->product->id);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        // Override row total to Rp2.157.350
        $cartComponent->set("line_total.$rowId", 2157350)
            ->call('updateLineTotal', $rowId, $this->product->id);

        // 3. Submit CreateForm
        $createComponent->call('submit', (string) $this->supplier->id, (string) $this->paymentTerm->id)
            ->assertHasNoErrors();

        // 4. Assert saved Purchase and PurchaseDetail
        $purchase = Purchase::query()->where('supplier_id', $this->supplier->id)->latest('id')->firstOrFail();
        $detail = PurchaseDetail::query()->where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertEquals(1078675.00, (float) $detail->entered_unit_price);
        $this->assertEquals(1078675.00, (float) $detail->unit_price);
        $this->assertEquals(2157350.00, (float) $detail->sub_total);

        // 5. Assert show page displays the derived unit price Rp1.078.675
        $response = $this->get(route('purchases.show', $purchase->id));
        $response->assertStatus(200);
        $response->assertSee(formatRupiah(1078675));
    }

    public function test_edit_purchase_with_overridden_line_total_persists_derived_unit_price(): void
    {
        // 1. Create an existing purchase with unit price Rp1.200.000, quantity 2, sub_total Rp2.400.000
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'reference' => 'PR-EDIT-TEST',
            'supplier_name' => $this->supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2400000,
            'due_amount' => 2400000,
            'paid_amount' => 0,
            'payment_date' => now()->format('Y-m-d'),
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'is_pkp' => false,
            'is_tax_included' => false,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 1200000,
            'unit_price' => 1200000,
            'sub_total' => 2400000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'purchase_unit_id' => $this->unit->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 1200000,
            'conversion_factor' => 1.0,
            'unit_name' => 'PCS',
            'base_unit_name' => 'PCS',
        ]);

        // 2. Open EditForm (hydrates Cart)
        $editComponent = Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);

        // 3. In ProductCart, override row total to Rp2.157.350
        $cartComponent = Livewire::test(ProductCart::class, [
            'cartInstance' => 'purchase',
            'data' => $purchase,
        ]);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $cartComponent->set("line_total.$rowId", 2157350)
            ->call('updateLineTotal', $rowId, $this->product->id);

        // 4. Save edit form
        $editComponent->call('submit', (string) $this->supplier->id, (string) $this->paymentTerm->id)
            ->assertHasNoErrors();

        // 5. Assert detail updated to new derived price
        $updatedDetail = PurchaseDetail::query()->where('purchase_id', $purchase->id)->firstOrFail();
        $this->assertEquals(1078675.00, (float) $updatedDetail->entered_unit_price);
        $this->assertEquals(1078675.00, (float) $updatedDetail->unit_price);
        $this->assertEquals(2157350.00, (float) $updatedDetail->sub_total);
    }
}
