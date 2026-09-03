<?php

namespace Modules\Purchase\Tests\Feature;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseCartUomConversionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private PaymentTerm $paymentTerm;
    private Unit $pcsUnit;
    private Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Utama',
            'supplier_email' => 'supplier@test.local',
            'supplier_phone' => '08123456789',
            'address' => 'Jl. Merdeka No. 123',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'is_active' => true,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'is_active' => true,
        ]);

        $this->pcsUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS', 'is_active' => true]);
        $this->boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX', 'is_active' => true]);
    }

    public function test_product_search_api_returns_eligible_conversions_and_base_unit(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Kapal Api',
            'product_code' => 'KPA-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10,
            'product_price' => 1200,
            'product_cost' => 1000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 1000,
        ]);

        // Eligible conversion (factor 12)
        $validConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        // Legacy conversion (factor 0.5) - should be excluded from API search results
        $legacyConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 0.5,
        ]);

        $response = $this->actingAsUser()
            ->getJson('/api/products/search?query=Kopi');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data);

        $pData = $data[0];
        $this->assertEquals('PCS', $pData['base_unit_name']);
        $this->assertCount(1, $pData['conversions']);
        $this->assertEquals(12.0, $pData['conversions'][0]['factor']);
        $this->assertEquals('BOX', $pData['conversions'][0]['unit_name']);
    }

    public function test_purchase_creation_persists_conversion_unit_snapshots(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Susu UHT',
            'product_code' => 'SHT-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10,
            'product_price' => 6000,
            'product_cost' => 5000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 5000,
        ]);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 24.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-TEST-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 120000,
            'cart' => [
                // Line 1: Entered in BOX (2 BOX @ Rp 60.000 = Rp 120.000 -> 48 PCS @ Rp 2.500)
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $boxConversion->id,
                    'quantity' => 2,
                    'unit_price' => 60000,
                    'conversion_factor' => 24.0,
                    'unit_name' => 'BOX',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'fixed',
                    'discount' => 0,
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        if ($response->status() !== 302) {
            dump($response->status(), $response->getContent(), session('errors')?->all());
        }

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-TEST-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        // Check canonical base values stored in existing database columns
        $this->assertEquals(48, $detail->quantity); // 2 * 24 = 48
        $this->assertEquals(2500, $detail->price); // 60.000 / 24 = 2.500

        // Check snapshot columns
        $this->assertEquals($this->boxUnit->id, $detail->purchase_unit_id);
        $this->assertEquals($boxConversion->id, $detail->product_unit_conversion_id);
        $this->assertEquals(2.0, (float) $detail->entered_quantity);
        $this->assertEquals(60000.0, (float) $detail->entered_unit_price);
        $this->assertEquals(24.0, (float) $detail->conversion_factor);
        $this->assertEquals('BOX', $detail->unit_name);
        $this->assertEquals('PCS', $detail->base_unit_name);
    }

    public function test_purchase_creation_supports_mixed_units_for_same_product(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mie Instant',
            'product_code' => 'MIE-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 3500,
            'product_cost' => 3000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 3000,
        ]);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 40.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-MIXED-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 135000,
            'cart' => [
                // Line 1: Entered in PCS (5 PCS @ Rp 3.000)
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->pcsUnit->id,
                    'product_unit_conversion_id' => null,
                    'quantity' => 5,
                    'unit_price' => 3000,
                    'conversion_factor' => 1.0,
                    'unit_name' => 'PCS',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'fixed',
                    'discount' => 0,
                ],
                // Line 2: Entered in BOX (1 BOX @ Rp 120.000)
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $boxConversion->id,
                    'quantity' => 1,
                    'unit_price' => 120000,
                    'conversion_factor' => 40.0,
                    'unit_name' => 'BOX',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'fixed',
                    'discount' => 0,
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-MIXED-001')->firstOrFail();
        $details = PurchaseDetail::where('purchase_id', $purchase->id)->get();

        $this->assertCount(2, $details);

        // Verify line 1 (PCS)
        $pcsDetail = $details->firstWhere('unit_name', 'PCS');
        $this->assertNotNull($pcsDetail);
        $this->assertEquals(5, $pcsDetail->quantity);
        $this->assertEquals(3000, $pcsDetail->price);

        // Verify line 2 (BOX)
        $boxDetail = $details->firstWhere('unit_name', 'BOX');
        $this->assertNotNull($boxDetail);
        $this->assertEquals(40, $boxDetail->quantity); // 1 * 40
        $this->assertEquals(3000, $boxDetail->price); // 120.000 / 40
    }

    public function test_purchase_creation_handles_conversion_lines_with_fixed_discount(): void
    {
        // 2 BOX @ Rp 60,000; 1 BOX = 24 PCS; Fixed Discount = Rp 5,000 per BOX
        // Expected total before tax/shipping = 2 * (60,000 - 5,000) = Rp 110,000
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Sachet',
            'product_code' => 'KS-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 3000,
            'product_cost' => 2500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 2500,
        ]);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 24.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-DISC-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 110000,
            'cart' => [
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $boxConversion->id,
                    'quantity' => 2,
                    'unit_price' => 60000,
                    'conversion_factor' => 24.0,
                    'unit_name' => 'BOX',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'fixed',
                    'discount' => 5000, // Rp 5,000 per BOX
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-DISC-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        // 48 PCS total, canonical price = 2500 per PCS, canonical discount = 5000/24 = 208.333333 per PCS
        $this->assertEquals(48, $detail->quantity);
        $this->assertEquals(110000.0, (float) $detail->sub_total);
        $this->assertEquals(110000.0, (float) $purchase->total_amount);
    }

    public function test_purchase_creation_handles_conversion_lines_with_percentage_discount(): void
    {
        // 2 BOX @ Rp 60,000; 1 BOX = 24 PCS; Percentage Discount = 10%
        // Expected total before tax/shipping = 2 * (60,000 - 6,000) = Rp 108,000
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Susu Kotak',
            'product_code' => 'SK-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 3000,
            'product_cost' => 2500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 2500,
        ]);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 24.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-PCT-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 108000,
            'cart' => [
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $boxConversion->id,
                    'quantity' => 2,
                    'unit_price' => 60000,
                    'conversion_factor' => 24.0,
                    'unit_name' => 'BOX',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'percentage',
                    'discount' => 10, // 10%
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-PCT-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertEquals(48, $detail->quantity);
        $this->assertEquals(108000.0, (float) $detail->sub_total);
        $this->assertEquals(108000.0, (float) $purchase->total_amount);
    }

    public function test_six_decimal_normalized_price_preservation_and_reload_stability(): void
    {
        // Rp 100,000 / 3 = Rp 33,333.333333 per PCS
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Permen Candy',
            'product_code' => 'PC-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 30000,
            'product_cost' => 25000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 25000,
        ]);

        $packConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-6DEC-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'cart' => [
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $packConversion->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'conversion_factor' => 3.0,
                    'unit_name' => 'PACK',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'fixed',
                    'discount' => 0,
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-6DEC-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        // 3 PCS total, unit_price must retain 6 decimals (33333.333333)
        $this->assertEquals(3.0, (float) $detail->quantity);
        $this->assertEquals(33333.333333, round((float) $detail->unit_price, 6));
        $this->assertEquals(100000.0, (float) $detail->sub_total);
    }

    public function test_fixed_discount_conversion_line_reload_and_recalculation_stability(): void
    {
        // 2 BOX @ Rp 60,000; 1 BOX = 24 PCS; Fixed Discount = Rp 5,000 per BOX
        // Initial total = Rp 110,000
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Biskuit Box',
            'product_code' => 'BB-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 3000,
            'product_cost' => 2500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 2500,
        ]);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 24.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-RELOAD-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 110000,
            'cart' => [
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $boxConversion->id,
                    'quantity' => 2,
                    'unit_price' => 60000,
                    'conversion_factor' => 24.0,
                    'unit_name' => 'BOX',
                    'base_unit_name' => 'PCS',
                    'discount_type' => 'fixed',
                    'discount' => 5000,
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-RELOAD-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        // Verify snapshot column presence
        $this->assertEquals(5000.0, (float) $detail->entered_product_discount_amount);
        $this->assertEquals(5000.0, (float) $detail->effective_entered_product_discount_amount);

        // Reload the stored detail model and re-normalize it as if rehydrating an edit cart or calculating totals
        $editCartItem = [
            'product_id' => $detail->product_id,
            'purchase_unit_id' => $detail->purchase_unit_id,
            'product_unit_conversion_id' => $detail->product_unit_conversion_id,
            'entered_quantity' => $detail->effective_entered_quantity,
            'entered_unit_price' => $detail->effective_entered_unit_price,
            'entered_product_discount_amount' => $detail->effective_entered_product_discount_amount,
            'product_discount_type' => $detail->product_discount_type,
            'pricing_source' => 'automatic',
            \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
        ];

        $normalizer = app(\Modules\Purchase\Services\PurchaseNormalizer::class);
        $recalculated = $normalizer->normalize(
            [
                'tax_id' => null,
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
            ],
            [$editCartItem],
            false,
            $this->setting->id
        );

        // Verify correct canonical quantity and price without double-conversion (48 PCS @ Rp 2,500/PCS)
        $this->assertEquals(48.0, (float) $recalculated['details'][0]['quantity']);
        $this->assertEquals(2500.0, (float) $recalculated['details'][0]['unit_price']);

        // Recalculated row subtotal MUST be EXACTLY 110000.00 (NOT 110000.16)
        $this->assertEquals(110000.0, (float) $recalculated['details'][0]['sub_total']);
    }

    public function test_purchase_creation_ignores_client_forged_conversion_factor(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Teh Celup',
            'product_code' => 'TC-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 50,
            'product_price' => 2000,
            'product_cost' => 1500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 1500,
        ]);

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 10.0, // Authoritative factor = 10
        ]);

        // Client attempts to send forged conversion_factor = 999.0
        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-FORGED-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'cart' => [
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $boxConversion->id,
                    'quantity' => 1,
                    'unit_price' => 50000,
                    'conversion_factor' => 999.0, // Forged factor!
                    'unit_name' => 'FORGED_BOX',
                    'base_unit_name' => 'FORGED_PCS',
                    'discount_type' => 'fixed',
                    'discount' => 0,
                ],
            ],
        ];

        $response = $this->actingAsUser()
            ->post(route('purchases.store'), $payload);

        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-FORGED-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        // Server must override forged values with authoritative values (factor 10.0, canonical qty 10.0)
        $this->assertEquals(10.0, (float) $detail->quantity); // 1 * 10 = 10 (NOT 999)
        $this->assertEquals(10.0, (float) $detail->conversion_factor); // 10.0 (NOT 999.0)
        $this->assertEquals('BOX', $detail->unit_name); // BOX (NOT FORGED_BOX)
    }

    public function test_unit_switch_preserves_entered_quantity_and_price(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 1000,
            'product_cost' => 800,
            'is_purchased' => true,
            'purchase_price' => 800,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        $user = $this->actingAsUser();
        Cart::instance('purchase_cart')->destroy();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_cart']);

        // Simulate adding product with qty 1 PCS at price 1000
        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'base_unit_name' => 'PCS',
            'product_unit' => 'PCS',
            'product_quantity' => 100,
            'purchase_price' => 1000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 800,
        ]);

        // Get the row ID (first and only row)
        $cartInstance = Cart::instance('purchase_cart');
        $items = $cartInstance->content();
        $this->assertCount(1, $items);
        $rowId = $items->first()->rowId;

        // Switch to BOX unit (use conversion id)
        $component->call('updateUnit', $rowId, 'conv_' . $conversion->id);

        // After switch: entered qty should still be 1, canonical should be 12
        $cartInstance = Cart::instance('purchase_cart');
        $updatedItem = $cartInstance->content()->get($rowId) ?? $cartInstance->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertEquals(1, (float) $updatedItem->qty, 'Entered quantity should remain 1 BOX');
        $this->assertEquals(12.0, (float) $updatedItem->options->conversion_factor, 'Conversion factor should be 12');
        $this->assertEquals(1.0, (float) $updatedItem->options->entered_quantity, 'Entered quantity persists as 1');
        // Canonical: 1 BOX * 12 = 12 PCS (shown in the breakdown, not stored explicitly here)

        // Assert discount fields for no-discount case (zero discount)
        $this->assertEquals(0.0, (float) ($updatedItem->options->product_discount ?? 0), 'Product discount should be 0');
        $this->assertEquals(0.0, (float) ($updatedItem->options->entered_product_discount_amount ?? 0), 'Entered discount should be 0');
    }

    public function test_unit_switch_at_default_quantity_no_error(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'SPIDOL SNOWMAN WHITE BOARD',
            'product_code' => 'SKU-436EB69E',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10,
            'product_price' => 50000,
            'product_cost' => 40000,
            'is_purchased' => true,
            'purchase_price' => 40000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.00,
        ]);

        $user = $this->actingAsUser();
        Cart::instance('purchase_cart')->destroy();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_cart']);

        // Add product with default qty 1 and price 50000
        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'base_unit_name' => 'PCS',
            'product_unit' => 'PCS',
            'product_quantity' => 10,
            'purchase_price' => 50000,
            'last_purchase_price' => 50000,
            'average_purchase_price' => 40000,
        ]);

        $cartInstance = Cart::instance('purchase_cart');
        $rowId = $cartInstance->content()->first()->rowId;

        // Switch to BOX - this should NOT error with scale rejection
        $component->call('updateUnit', $rowId, 'conv_' . $conversion->id);

        $cartInstance = Cart::instance('purchase_cart');
        $updatedItem = $cartInstance->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertEquals(1, (float) $updatedItem->qty, 'Entered qty should be 1 BOX');
        $this->assertEquals(12.0, (float) $updatedItem->options->conversion_factor, 'Factor should be 12');
        // No session flash error
        $this->assertFalse(session()->has('message'), 'Should not flash error message');
    }

    public function test_round_trip_pcs_to_box_to_pcs_preserves_canonical_price(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Round Trip Test',
            'product_code' => 'ROUNDTRIP',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 10000,
            'product_cost' => 8000,
            'is_purchased' => true,
            'purchase_price' => 8000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.00,
        ]);

        $user = $this->actingAsUser();
        Cart::instance('purchase_cart')->destroy();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_cart']);

        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'base_unit_name' => 'PCS',
            'product_unit' => 'PCS',
            'product_quantity' => 100,
            'purchase_price' => 10000,
            'last_purchase_price' => 10000,
            'average_purchase_price' => 8000,
        ]);

        $cartInstance = Cart::instance('purchase_cart');
        $rowId = $cartInstance->content()->first()->rowId;

        // Manually set to 1 PCS at price 10000
        $component->set("quantity.{$rowId}", 1);
        $component->set("unit_price.{$rowId}", 10000);
        $cartInstance->update($rowId, ['qty' => 1, 'price' => 10000]);

        // Store initial state
        $itemBefore = $cartInstance->content()->get($rowId);
        $initialCanonicalPrice = (float) ($itemBefore->options->canonical_unit_price ?? 10000);

        // Switch PCS → BOX: entered qty preserved as 1, canonical price preserved
        // Rule: entered_price = canonical_base_price * new_factor
        // canonical_base_price = 10000 / 1 = 10000
        // entered_price = 10000 * 12 = 120000
        $component->call('updateUnit', $rowId, 'conv_' . $conversion->id);

        $cartInstance = Cart::instance('purchase_cart');
        $rowId2 = $cartInstance->content()->first()->rowId;
        $itemAfterSwitch1 = $cartInstance->content()->get($rowId2);

        $qty2 = (float) $itemAfterSwitch1->qty;
        $price2 = (float) $itemAfterSwitch1->price;
        $canonicalPrice2 = (float) $itemAfterSwitch1->options->canonical_unit_price;

        $this->assertEquals(1, $qty2, 'After switch to BOX: entered qty should remain 1');
        $this->assertEqualsWithDelta(120000.0, $price2, 0.01, 'After switch to BOX: entered price = 10000 * 12 = 120000');
        $this->assertEqualsWithDelta(10000.0, $canonicalPrice2, 0.000001, 'Canonical price unchanged: 10000 per base PCS');

        // Switch BOX → PCS: entered qty stays 1, canonical price stays same
        // entered_price = 10000 * 1 = 10000
        $component->call('updateUnit', $rowId2, 'base_1');

        $cartInstance = Cart::instance('purchase_cart');
        $rowId3 = $cartInstance->content()->first()->rowId;
        $itemAfterSwitch2 = $cartInstance->content()->get($rowId3);

        $qty3 = (float) $itemAfterSwitch2->qty;
        $price3 = (float) $itemAfterSwitch2->price;
        $canonicalPrice3 = (float) $itemAfterSwitch2->options->canonical_unit_price;

        $this->assertEquals(1, $qty3, 'After round-trip: entered qty should remain 1');
        $this->assertEqualsWithDelta(10000.0, $price3, 0.000001, 'After round-trip: entered price = 10000 * 1 = 10000 (restored)');
        $this->assertEqualsWithDelta(10000.0, $canonicalPrice3, 0.000001, 'Canonical price constant throughout: 10000');
    }

    public function test_same_product_different_units_creates_separate_rows(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Multi-Unit Product',
            'product_code' => 'MULTIUNIT',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 1000,
            'product_price' => 5000,
            'product_cost' => 4000,
            'is_purchased' => true,
            'purchase_price' => 4000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.00,
        ]);

        $user = $this->actingAsUser();
        Cart::instance('purchase_cart')->destroy();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_cart']);

        // Add product first time (defaults to PCS)
        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'base_unit_name' => 'PCS',
            'product_unit' => 'PCS',
            'product_quantity' => 1000,
            'purchase_price' => 5000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 4000,
        ]);

        $cartInstance = Cart::instance('purchase_cart');
        $this->assertCount(1, $cartInstance->content());
        $row1Id = $cartInstance->content()->first()->rowId;

        // Change first row to 3 PCS
        $component->set("quantity.{$row1Id}", 3);
        $cartInstance = Cart::instance('purchase_cart');
        $cartInstance->update($row1Id, ['qty' => 3]);

        // Switch first row to BOX to demonstrate multi-unit support
        $component->call('updateUnit', $row1Id, 'conv_' . $conversion->id);

        $cartInstance = Cart::instance('purchase_cart');
        $rowId1Updated = $cartInstance->content()->first()->rowId;
        $itemBox1 = $cartInstance->content()->get($rowId1Updated);

        // After switching to BOX: entered qty should be 3 BOX, factor should be 12
        $this->assertEquals(3, (float) $itemBox1->qty, 'Row 1: entered qty should be 3 BOX');
        $this->assertEquals(12.0, (float) $itemBox1->options->conversion_factor, 'Row 1: factor should be 12');

        // Now add a second row in the base unit PCS
        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'base_unit_name' => 'PCS',
            'product_unit' => 'PCS',
            'product_quantity' => 1000,
            'purchase_price' => 5000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 4000,
        ]);

        $cartInstance = Cart::instance('purchase_cart');
        $this->assertCount(2, $cartInstance->content(), 'After adding in different unit: should have 2 rows');

        // Verify: row 1 is BOX, row 2 is PCS
        $itemBox = $cartInstance->content()->values()[0];
        $itemPcs = $cartInstance->content()->values()[1];

        // Row 1 was explicitly switched to BOX, so it has conversion_factor
        $this->assertEquals(12.0, (float) $itemBox->options->conversion_factor, 'First row: factor should be 12 (BOX)');
        // Row 2 is newly added base unit; it will have conversion_factor 1 when explicitly selected
        // But for a newly added base-unit product, it may not be set yet
        $this->assertEquals($product->id, $itemPcs->id, 'Second row: should be same product');
        $this->assertEquals(1, (float) $itemPcs->qty, 'Second row: should have qty 1');
    }

    public function test_unit_switch_with_fixed_discount_in_cart(): void
    {
        // Test that fixed discounts scale correctly: 5000 per BOX → 416.67 per PCS when switching BOX→PCS
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Fixed Discount',
            'product_code' => 'TFD-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 60000,
            'product_cost' => 50000,
            'is_purchased' => true,
            'purchase_price' => 50000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.00,
        ]);

        $user = $this->actingAsUser();
        Cart::instance('purchase_cart')->destroy();

        // Pre-populate cart with 1 BOX @ 60000 with 5000 fixed discount
        Cart::instance('purchase_cart')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 60000,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 12.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 60000.0,
                'product_discount' => 5000.0,
                'product_discount_input' => 5000.0,
                'product_discount_type' => 'fixed',
                'entered_product_discount_amount' => 5000.0,
                'sub_total' => 55000.0,
            ],
        ]);

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_cart']);

        $cartInstance = Cart::instance('purchase_cart');
        $rowId = $cartInstance->content()->first()->rowId;

        // Set component state to match cart
        $component->set("quantity.{$rowId}", 1);
        $component->set("unit_price.{$rowId}", 60000);
        $component->set("discount_type.{$rowId}", 'fixed');
        $component->set("item_discount.{$rowId}", 5000);

        // Switch BOX → PCS
        // Canonical discount: 5000 / 12 = 416.666667
        // Entered discount: 416.666667 * 1 = 416.67
        $component->call('updateUnit', $rowId, 'base_1');

        $cartInstance = Cart::instance('purchase_cart');
        $updatedItem = $cartInstance->content()->first();

        $this->assertEquals(1, (float) $updatedItem->qty, 'Entered qty remains 1');
        $this->assertEquals(5000.0, (float) $updatedItem->price, 'Entered price = 60000 / 12 = 5000');
        $this->assertEqualsWithDelta(416.67, (float) ($updatedItem->options->entered_product_discount_amount ?? 0), 0.01, 'Entered discount scales: 5000 / 12');
        $this->assertEqualsWithDelta(416.67, (float) ($updatedItem->options->product_discount ?? 0), 0.01, 'Canonical discount per base unit: ~416.67');
    }

    public function test_unit_switch_with_percentage_discount_in_cart(): void
    {
        // Test that percentage discounts stay the same percentage but amounts recompute
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Percentage Discount',
            'product_code' => 'TPD-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 60000,
            'product_cost' => 50000,
            'is_purchased' => true,
            'purchase_price' => 50000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.00,
        ]);

        $user = $this->actingAsUser();
        Cart::instance('purchase_cart')->destroy();

        // Pre-populate cart with 1 BOX @ 60000 with 10% discount
        Cart::instance('purchase_cart')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 60000,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 12.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 60000.0,
                'product_discount' => 6000.0,
                'product_discount_input' => 10.0,
                'product_discount_type' => 'percentage',
                'entered_product_discount_amount' => 10.0,
                'sub_total' => 54000.0,
            ],
        ]);

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase_cart']);

        $cartInstance = Cart::instance('purchase_cart');
        $rowId = $cartInstance->content()->first()->rowId;

        // Set component state to match cart
        $component->set("quantity.{$rowId}", 1);
        $component->set("unit_price.{$rowId}", 60000);
        $component->set("discount_type.{$rowId}", 'percentage');
        $component->set("item_discount.{$rowId}", 10);

        // Switch BOX → PCS
        // Percentage stays 10%
        // New entered price: 60000 / 12 = 5000
        // Discount amount: 5000 * 10% = 500
        $component->call('updateUnit', $rowId, 'base_1');

        $cartInstance = Cart::instance('purchase_cart');
        $updatedItem = $cartInstance->content()->first();

        $this->assertEquals(1, (float) $updatedItem->qty, 'Entered qty remains 1');
        $this->assertEquals(5000.0, (float) $updatedItem->price, 'Entered price = 60000 / 12 = 5000');
        $this->assertEquals(10.0, (float) ($updatedItem->options->product_discount_input ?? 0), 'Percentage discount stays 10%');
        $this->assertEquals(500.0, (float) ($updatedItem->options->product_discount ?? 0), 'Discount amount recomputed: 5000 * 10% = 500');
    }

    public function test_quantity_change_preserves_unit_price_base_unit(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'SPIDOL SNOWMAN WHITE BOARD',
            'product_code' => 'SPIDOL-WB-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 6105,
            'product_cost' => 5000,
            'is_purchased' => true,
            'purchase_price' => 5000,
        ]);

        // Manually create a cart item with unit price 6105
        Cart::instance('purchase')->add([
            'id'     => $product->id,
            'name'   => $product->product_name,
            'qty'    => 1,
            'price'  => 6105,
            'weight' => 1,
            'options' => [
                'product_discount'        => 0,
                'product_discount_input'  => 0,
                'product_discount_type'   => 'fixed',
                'sub_total'               => 6105,
                'sub_total_before_tax'    => 6105,
                'product_tax_amount'      => 0,
                'canonical_unit_price'    => 6105,
                'conversion_factor'       => 1.0,
                'unit_name'               => 'PCS',
                'base_unit_name'          => 'PCS',
                'pricing_source'          => 'manual_unit_price',
            ],
        ]);

        $cartItems = Cart::instance('purchase')->content();
        $cartItem = $cartItems->first();
        $rowId = $cartItem->rowId;

        // Create component and initialize it
        $component = $this->createLivewireComponent();
        $component->quantity[$rowId] = 1;
        $component->unit_price[$rowId] = 6105;
        $component->line_total[$rowId] = 6105;

        // Change quantity from 1 to 5
        $component->quantity[$rowId] = 5;
        $component->updateQuantity($rowId, $product->id);

        // Verify unit price is preserved - get the cart item by product_id since rowId may have changed
        $cartItem = Cart::instance('purchase')->search(fn ($item) => $item->id == $product->id)->first();
        $this->assertNotNull($cartItem, 'Cart item should exist');
        $this->assertEquals(6105.0, (float) $cartItem->price, 'Unit price must remain 6105 after quantity change');
        $this->assertEquals(5.0, (float) $cartItem->qty, 'Quantity should be updated to 5');
    }

    public function test_quantity_change_preserves_unit_price_conversion_unit(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'SPIDOL SNOWMAN WHITE BOARD',
            'product_code' => 'SPIDOL-WB-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 1200,
            'product_price' => 60000,
            'product_cost' => 50000,
            'is_purchased' => true,
            'purchase_price' => 50000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        // Manually create a cart item in BOX unit with unit price 60000
        Cart::instance('purchase')->add([
            'id'     => $product->id,
            'name'   => $product->product_name,
            'qty'    => 1,
            'price'  => 60000,
            'weight' => 1,
            'options' => [
                'product_discount'        => 0,
                'product_discount_input'  => 0,
                'product_discount_type'   => 'fixed',
                'sub_total'               => 60000,
                'sub_total_before_tax'    => 60000,
                'product_tax_amount'      => 0,
                'canonical_unit_price'    => 5000,
                'conversion_factor'       => 12.0,
                'unit_name'               => 'BOX',
                'base_unit_name'          => 'PCS',
                'purchase_unit_id'        => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'pricing_source'          => 'manual_unit_price',
            ],
        ]);

        $cartItems = Cart::instance('purchase')->content();
        $cartItem = $cartItems->first();
        $rowId = $cartItem->rowId;

        // Create component and initialize it
        $component = $this->createLivewireComponent();
        $component->quantity[$rowId] = 1;
        $component->unit_price[$rowId] = 60000;
        $component->line_total[$rowId] = 60000;

        // Change quantity from 1 to 3 (boxes)
        $component->quantity[$rowId] = 3;
        $component->updateQuantity($rowId, $product->id);

        // Verify unit price is preserved - get the cart item by product_id since rowId may have changed
        $cartItem = Cart::instance('purchase')->search(fn ($item) => $item->id == $product->id)->first();
        $this->assertNotNull($cartItem, 'Cart item should exist');
        $this->assertEquals(60000.0, (float) $cartItem->price, 'Unit price must remain 60000 after quantity change');
        $this->assertEquals(3.0, (float) $cartItem->qty, 'Quantity should be updated to 3 boxes');
    }

    public function test_quantity_change_preserves_unit_price_with_manual_override(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 10000,
            'product_cost' => 8000,
            'is_purchased' => true,
            'purchase_price' => 8000,
        ]);

        // Manually create a cart item with manually overridden unit price
        Cart::instance('purchase')->add([
            'id'     => $product->id,
            'name'   => $product->product_name,
            'qty'    => 1,
            'price'  => 12500,
            'weight' => 1,
            'options' => [
                'product_discount'        => 0,
                'product_discount_input'  => 0,
                'product_discount_type'   => 'fixed',
                'sub_total'               => 12500,
                'sub_total_before_tax'    => 12500,
                'product_tax_amount'      => 0,
                'canonical_unit_price'    => 12500,
                'conversion_factor'       => 1.0,
                'unit_name'               => 'PCS',
                'base_unit_name'          => 'PCS',
                'pricing_source'          => 'manual_unit_price',
            ],
        ]);

        $cartItems = Cart::instance('purchase')->content();
        $cartItem = $cartItems->first();
        $rowId = $cartItem->rowId;

        // Create component and initialize it
        $component = $this->createLivewireComponent();
        $component->quantity[$rowId] = 1;
        $component->unit_price[$rowId] = 12500;
        $component->line_total[$rowId] = 12500;

        // Change quantity
        $component->quantity[$rowId] = 2;
        $component->updateQuantity($rowId, $product->id);

        // Verify unit price is preserved and pricing source remains manual - get by product_id
        $cartItem = Cart::instance('purchase')->search(fn ($item) => $item->id == $product->id)->first();
        $this->assertNotNull($cartItem, 'Cart item should exist');
        $this->assertEquals(12500.0, (float) $cartItem->price, 'Unit price must remain 12500 after quantity change');
        $this->assertEquals(2.0, (float) $cartItem->qty, 'Quantity should be updated to 2');
        $this->assertEquals('manual_unit_price', $cartItem->options->pricing_source, 'Pricing source remains manual_unit_price');
    }

    public function test_manual_line_total_override_derives_unit_price_correctly(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 10000,
            'product_cost' => 8000,
            'is_purchased' => true,
            'purchase_price' => 8000,
        ]);

        // Create a cart item and then override its line total
        Cart::instance('purchase')->add([
            'id'     => $product->id,
            'name'   => $product->product_name,
            'qty'    => 2,
            'price'  => 10000,
            'weight' => 1,
            'options' => [
                'product_discount'        => 0,
                'product_discount_input'  => 0,
                'product_discount_type'   => 'fixed',
                'sub_total'               => 20000,
                'sub_total_before_tax'    => 20000,
                'product_tax_amount'      => 0,
                'canonical_unit_price'    => 10000,
                'conversion_factor'       => 1.0,
                'unit_name'               => 'PCS',
                'base_unit_name'          => 'PCS',
                'pricing_source'          => 'automatic',
            ],
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Create component and initialize it
        $component = $this->createLivewireComponent();
        $component->quantity[$rowId] = 2;
        $component->unit_price[$rowId] = 10000;
        $component->line_total[$rowId] = 20000;

        // Override line total to 25000 (for qty 2, unit price should become 12500)
        $component->line_total[$rowId] = 25000;
        $component->updateLineTotal($rowId, $product->id);

        $cartItem = Cart::instance('purchase')->search(fn ($item) => $item->id == $product->id)->first();
        $this->assertNotNull($cartItem, 'Cart item should exist');
        $this->assertEquals(12500.0, (float) $cartItem->price, 'Derived unit price from line total: 25000 / 2 = 12500');
        $this->assertEquals('manual_line_total', $cartItem->options->pricing_source);

        // Now change quantity from 2 to 3 while manual line total is active
        $newRowId = $cartItem->rowId;
        $component->quantity[$newRowId] = 3;
        $component->updateQuantity($newRowId, $product->id);

        // The unit price should be preserved, not re-derived from the old line total
        $cartItem = Cart::instance('purchase')->search(fn ($item) => $item->id == $product->id)->first();
        $this->assertNotNull($cartItem, 'Cart item should exist after quantity update');
        $this->assertEquals(12500.0, (float) $cartItem->price, 'Unit price is preserved after quantity change');
        $this->assertEquals(3.0, (float) $cartItem->qty, 'Quantity updated to 3');
    }

    private function createLivewireComponent()
    {
        $component = new class extends \App\Livewire\Purchase\ProductCart {
        };
        $component->cart_instance = 'purchase';
        $component->is_tax_included = true;
        $component->setting_id = $this->setting->id;
        $component->selectedSettingId = $this->setting->id;
        $component->taxes = collect();
        $component->isPkp = false;

        return $component;
    }

    private function actingAsUser()
    {
        $user = \App\Models\User::factory()->create();
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.create');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.access');
        $user->givePermissionTo('purchases.create', 'purchases.access');

        return $this->actingAs($user)->withSession(['setting_id' => $this->setting->id]);
    }
}
