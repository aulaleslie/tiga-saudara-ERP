<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\EditForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseEditGlobalDiscountConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $codTerm;
    protected Supplier $supplier;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Discount Test Setting',
            'company_email' => 'discount-test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->codTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->codTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Discount Product',
            'product_code' => 'DP-001',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 100000,
            'product_price' => 100000,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_fixed_discount_50_persists_as_fixed_on_edit_submit(): void
    {
        $purchase = $this->createPurchaseWithDiscount('fixed', 50);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->assertSet('global_discount_type', 'fixed')
            ->assertSet('global_discount', 50)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase->refresh();
        $this->assertSame(0.0, (float) $purchase->discount_percentage);
        $this->assertSame(50.0, (float) $purchase->discount_amount);
        $this->assertSame(99950.0, (float) $purchase->total_amount);
    }

    public function test_percentage_discount_10_persists_as_percentage_on_edit_submit(): void
    {
        $purchase = $this->createPurchaseWithDiscount('percentage', 10);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->assertSet('global_discount_type', 'percentage')
            ->assertSet('global_discount', 10)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase->refresh();
        $this->assertSame(10.0, (float) $purchase->discount_percentage);
        $this->assertSame(0.0, (float) $purchase->discount_amount);
        $this->assertSame(90000.0, (float) $purchase->total_amount);
    }

    public function test_existing_fixed_discount_reopen_and_save_stays_fixed(): void
    {
        $purchase = $this->createPurchaseWithDiscount('fixed', 50);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->assertSet('global_discount_type', 'fixed')
            ->assertSet('global_discount', 50)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->assertSet('global_discount_type', 'fixed')
            ->assertSet('global_discount', 50)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase->refresh();
        $this->assertSame(0.0, (float) $purchase->discount_percentage);
        $this->assertSame(50.0, (float) $purchase->discount_amount);
        $this->assertSame(99950.0, (float) $purchase->total_amount);
    }

    private function createPurchaseWithDiscount(string $type, float $value): Purchase
    {
        $discountPercentage = $type === 'percentage' ? $value : 0;
        $discountAmount = $type === 'fixed' ? $value : 0;
        $subTotal = 100000.0;
        $globalDiscountAmount = $type === 'percentage'
            ? ($subTotal * ($value / 100))
            : $value;
        $totalAmount = $subTotal - $globalDiscountAmount;

        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => null,
            'tax_ref_no' => null,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'paid_amount' => 0,
            'due_amount' => $totalAmount,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->codTerm->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        return $purchase;
    }
}
