<?php

namespace Modules\Purchase\Tests\Feature;

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

    private function actingAsUser()
    {
        $user = \App\Models\User::factory()->create();
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.create');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.access');
        $user->givePermissionTo('purchases.create', 'purchases.access');

        return $this->actingAs($user)->withSession(['setting_id' => $this->setting->id]);
    }
}
