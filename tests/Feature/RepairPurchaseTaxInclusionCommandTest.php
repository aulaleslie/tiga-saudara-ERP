<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class RepairPurchaseTaxInclusionCommandTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private PaymentTerm $paymentTerm;
    private Product $product;
    private Tax $tax11;

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
            'company_name' => 'Repair Test Company',
            'company_email' => 'repair@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => $this->setting->id]);

        PaymentTerm::create(['name' => 'COD', 'longevity' => 0]);
        $this->paymentTerm = PaymentTerm::create(['name' => 'NET 30', 'longevity' => 30]);

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Repair Test Product',
            'product_code' => 'RPT-001',
            'product_quantity' => 10,
            'setting_id' => $this->setting->id,
            'product_cost' => 100,
            'product_price' => 120,
            'product_unit' => 'pcs',
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);
    }

    public function test_repair_purchase_tax_inclusion_dry_run_does_not_mutate_and_reports_skips(): void
    {
        $candidate = $this->createPurchaseWithSingleLine('included', false);
        $this->createMixedEvidencePurchase(false);
        $this->createPurchaseWithSingleLine('no_tax', false);

        $this->artisan('purchases:repair-tax-inclusion')
            ->expectsOutputToContain('Mode: dry-run')
            ->expectsOutputToContain('CAND  purchase#'.$candidate->id.' stored=0 inferred=1')
            ->expectsOutputToContain('Summary')
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Skipped ambiguous: 1')
            ->expectsOutputToContain('Skipped no inferable: 1')
            ->assertExitCode(0);

        $this->assertFalse((bool) $candidate->fresh()->is_tax_included);
    }

    public function test_repair_purchase_tax_inclusion_apply_updates_only_filtered_purchase_ids(): void
    {
        $candidateA = $this->createPurchaseWithSingleLine('included', false);
        $candidateB = $this->createPurchaseWithSingleLine('included', false);

        $this->artisan('purchases:repair-tax-inclusion', [
            '--apply' => true,
            '--purchase-id' => [$candidateA->id],
        ])
            ->expectsOutputToContain('Mode: apply')
            ->expectsOutputToContain('FIXED purchase#'.$candidateA->id.' 0=>1')
            ->expectsOutputToContain('Updated: 1')
            ->assertExitCode(0);

        $this->assertTrue((bool) $candidateA->fresh()->is_tax_included);
        $this->assertFalse((bool) $candidateB->fresh()->is_tax_included);
    }

    private function createPurchaseWithSingleLine(string $mode, bool $storedIsTaxIncluded): Purchase
    {
        [$price, $subTotal, $taxAmount, $taxId] = match ($mode) {
            'included' => [11100.00, 11100.00, 1100.00, $this->tax11->id],
            'excluded' => [10000.00, 11100.00, 1100.00, $this->tax11->id],
            'no_tax' => [10000.00, 10000.00, 0.00, null],
            default => throw new \InvalidArgumentException("Unsupported mode [$mode]"),
        };

        $purchase = $this->createPurchase([
            'is_tax_included' => $storedIsTaxIncluded,
            'tax_amount' => $taxAmount,
            'total_amount' => $subTotal,
            'due_amount' => $subTotal,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => $price,
            'unit_price' => $price,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => $taxAmount,
            'tax_id' => $taxId,
        ]);

        return $purchase;
    }

    private function createMixedEvidencePurchase(bool $storedIsTaxIncluded): Purchase
    {
        $purchase = $this->createPurchase([
            'is_tax_included' => $storedIsTaxIncluded,
            'tax_amount' => 2200.00,
            'total_amount' => 22200.00,
            'due_amount' => 22200.00,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 11100.00,
            'unit_price' => 11100.00,
            'sub_total' => 11100.00,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 1100.00,
            'tax_id' => $this->tax11->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 10000.00,
            'unit_price' => 10000.00,
            'sub_total' => 11100.00,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 1100.00,
            'tax_id' => $this->tax11->id,
        ]);

        return $purchase;
    }

    private function createPurchase(array $overrides = []): Purchase
    {
        $today = Carbon::now()->format('Y-m-d');
        $defaults = [
            'date' => $today,
            'due_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
            'note' => 'repair command test',
        ];

        return Purchase::create(array_merge($defaults, $overrides));
    }
}
