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
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

/**
 * A stored Purchase must be lossless when opened in the edit form and saved
 * without any row edit: hydration reads stored monetary values as authoritative
 * rather than recomputing price x quantity (1200 x 1216.67 = 1_460_004).
 */
class PurchaseStoredDocumentLineTotalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected const TARGET_LINE_TOTAL = 1460000.00;
    protected const DRIFTED_LINE_TOTAL = 1460004.0;
    protected const TAX_AMOUNT = 144684.68;

    protected User $user;
    protected Setting $setting;
    protected Setting $pkpSetting;
    protected PaymentTerm $paymentTerm;
    protected Supplier $supplier;
    protected Supplier $pkpSupplier;
    protected Product $product;
    protected Product $pkpProduct;
    protected Tax $tax11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Non PKP Setting',
            'company_email' => 'non-pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Setting',
            'company_email' => 'pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        $this->paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash'],
            ['longevity' => 0]
        );

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->pkpSupplier = Supplier::factory()->create([
            'setting_id' => $this->pkpSetting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'product_quantity' => 2000,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        $this->pkpProduct = Product::create([
            'product_name' => 'PKP Product',
            'product_code' => 'PKP-01',
            'product_quantity' => 2000,
            'setting_id' => $this->pkpSetting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        session(['setting_id' => $this->setting->id]);
        Cart::instance('purchase')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('purchase')->destroy();
        parent::tearDown();
    }

    public function test_non_pkp_edit_save_reload_preserves_stored_line_total(): void
    {
        [$purchase, $detail] = $this->createStoredPurchase(
            setting: $this->setting,
            supplier: $this->supplier,
            product: $this->product,
            reference: 'TEST-001',
            taxAmount: 0.0,
            taxId: null,
            isTaxIncluded: false,
        );

        $this->runLosslessEditCycle($purchase, $detail, isPkp: false);
    }

    public function test_pkp_tax_included_edit_save_reload_preserves_stored_line_total(): void
    {
        [$purchase, $detail] = $this->createStoredPurchase(
            setting: $this->pkpSetting,
            supplier: $this->pkpSupplier,
            product: $this->pkpProduct,
            reference: 'TEST-PKP-001',
            taxAmount: self::TAX_AMOUNT,
            taxId: $this->tax11->id,
            isTaxIncluded: true,
        );

        $this->runLosslessEditCycle($purchase, $detail, isPkp: true);
    }

    public function test_pkp_tax_exclusive_edit_save_reload_preserves_stored_line_total(): void
    {
        [$purchase, $detail] = $this->createStoredPurchase(
            setting: $this->pkpSetting,
            supplier: $this->pkpSupplier,
            product: $this->pkpProduct,
            reference: 'TEST-PKP-EXCL-001',
            taxAmount: self::TAX_AMOUNT,
            taxId: $this->tax11->id,
            isTaxIncluded: false,
        );

        $this->runLosslessEditCycle($purchase, $detail, isPkp: true);
    }

    /**
     * @return array{0: Purchase, 1: PurchaseDetail}
     */
    protected function createStoredPurchase(
        Setting $setting,
        Supplier $supplier,
        Product $product,
        string $reference,
        float $taxAmount,
        ?int $taxId,
        bool $isTaxIncluded,
    ): array {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'reference' => $reference,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => $taxAmount > 0 ? 11 : 0,
            'tax_amount' => $taxAmount,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => self::TARGET_LINE_TOTAL,
            'paid_amount' => 0,
            'due_amount' => self::TARGET_LINE_TOTAL,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $setting->id,
            'is_tax_included' => $isTaxIncluded,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1200,
            'price' => 1216.67,
            'unit_price' => 1216.67,
            'sub_total' => self::TARGET_LINE_TOTAL,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => $taxAmount,
            'tax_id' => $taxId,
        ]);

        session(['setting_id' => $setting->id]);
        Cart::instance('purchase')->destroy();

        return [$purchase, $detail];
    }

    /**
     * Open the edit form, assert authoritative hydration, save without touching the
     * row, then reopen from scratch and assert the same authoritative values.
     */
    protected function runLosslessEditCycle(Purchase $purchase, PurchaseDetail $detail, bool $isPkp): void
    {
        $editComponent = Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);
        $editComponent->assertViewHas('purchase');

        $this->assertAuthoritativeCartRow($isPkp);

        $editComponent->call('submit', (string) $purchase->supplier_id, (string) $purchase->payment_term_id);

        $detail = PurchaseDetail::where('purchase_id', $purchase->id)
            ->where('product_id', $detail->product_id)
            ->firstOrFail();
        $purchase->refresh();

        $this->assertSame(self::TARGET_LINE_TOTAL, (float) $detail->sub_total);
        $this->assertSame(self::TARGET_LINE_TOTAL, (float) $purchase->total_amount);
        $this->assertNotSame(self::DRIFTED_LINE_TOTAL, (float) $detail->sub_total);

        Cart::instance('purchase')->destroy();

        $freshEdit = Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);
        $freshEdit->assertViewHas('purchase');

        $this->assertAuthoritativeCartRow($isPkp);
    }

    protected function assertAuthoritativeCartRow(bool $isPkp): void
    {
        $hydrated = Cart::instance('purchase')->content()->firstOrFail();

        $this->assertSame(self::TARGET_LINE_TOTAL, (float) $hydrated->options->sub_total);

        if ($isPkp) {
            $this->assertSame(
                self::TARGET_LINE_TOTAL,
                round(
                    (float) $hydrated->options->sub_total_before_tax
                    + (float) $hydrated->options->product_tax_amount,
                    2
                )
            );

            return;
        }

        $this->assertSame(self::TARGET_LINE_TOTAL, (float) $hydrated->options->sub_total_before_tax);
        $this->assertSame(0.0, (float) $hydrated->options->product_tax_amount);
    }
}
