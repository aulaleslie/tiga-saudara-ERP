<?php

namespace Modules\Purchase\Tests\Feature;

use App\Services\MonetaryEdit\MonetaryEditException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\PurchaseMonetaryEditService;
use Modules\Purchase\Services\PurchaseNormalizer;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchasePersistenceAndEditTest extends TestCase
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
        $this->setting = Setting::factory()->create(['is_pkp' => false]);
        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Test',
            'supplier_email' => 'supplier@test.local',
            'supplier_phone' => '08123456789',
            'address' => 'Jl. Test No. 1',
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

    public function test_normalization_output_carries_canonical_and_supplier_snapshots_without_trusting_client_derived_factors(): void
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

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        // Client passes tampered conversion_factor = 99 and tampered quantity = 999
        $detailInput = [
            'id' => $product->id,
            'product_id' => $product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2.0,
            'entered_unit_price' => 12000.0,
            'conversion_factor' => 99.0, // tampered!
            'quantity' => 999.0, // tampered!
        ];

        $normalizer = app(PurchaseNormalizer::class);
        $result = $normalizer->normalize([], [$detailInput], false, $this->setting->id);

        $detail = $result['details'][0];

        // Authoritative server calculation: 2 BOX * 12 factor = 24 PCS canonical
        $this->assertEquals('24.000', $detail['quantity']);
        $this->assertEquals('2.000', $detail['entered_quantity']);
        $this->assertEquals('1000.000000', $detail['unit_price']); // 12000 / 12 = 1000
        $this->assertEquals('12000.00', $detail['entered_unit_price']);
        $this->assertEquals('12.000000', $detail['conversion_factor']); // Server loaded factor 12, ignored tampered 99
        $this->assertEquals('BOX', $detail['unit_name']);
        $this->assertEquals('PCS', $detail['base_unit_name']);
    }

    public function test_purchase_create_and_edit_persists_snapshots_and_preserves_manual_overrides(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Sabun Mandi',
            'product_code' => 'SBN-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10,
            'product_price' => 5000,
            'product_cost' => 4000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 4000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 10.0,
        ]);

        $payload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-PERSIST-001',
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
                    'product_unit_conversion_id' => $conversion->id,
                    'entered_quantity' => 1.0,
                    'entered_unit_price' => 50000.0,
                    'quantity' => 1.0,
                    'unit_price' => 50000.0,
                ],
            ],
        ];

        $response = $this->actingAsUser()->post(route('purchases.store'), $payload);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->where('reference', 'PR-PERSIST-001')->firstOrFail();
        $detail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertEquals(10.0, (float) $detail->quantity); // 1 BOX * 10 = 10 PCS
        $this->assertEquals(1.0, (float) $detail->entered_quantity);
        $this->assertEquals(50000.0, (float) $detail->entered_unit_price);
        $this->assertEquals(5000.0, (float) $detail->unit_price); // 50000 / 10
        $this->assertEquals(10.0, (float) $detail->conversion_factor);
        $this->assertEquals('BOX', $detail->unit_name);
        $this->assertEquals('PCS', $detail->base_unit_name);

        // Perform edit with updated entered quantity and price
        $updatePayload = array_merge($payload, [
            'total_amount' => 120000,
            'cart' => [
                [
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $conversion->id,
                    'entered_quantity' => 2.0,
                    'entered_unit_price' => 60000.0,
                    'quantity' => 2.0,
                    'unit_price' => 60000.0,
                ],
            ],
        ]);

        $updateResponse = $this->actingAsUser()->put(route('purchases.update', $purchase->id), $updatePayload);
        $updateResponse->assertSessionHasNoErrors();

        $detailUpdated = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();
        $this->assertEquals(20.0, (float) $detailUpdated->quantity); // 2 BOX * 10 = 20 PCS
        $this->assertEquals(2.0, (float) $detailUpdated->entered_quantity);
        $this->assertEquals(60000.0, (float) $detailUpdated->entered_unit_price);
        $this->assertEquals(6000.0, (float) $detailUpdated->unit_price);
    }

    public function test_edit_cart_rehydration_from_snapshots_and_legacy_fallback(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Minyak Goreng',
            'product_code' => 'MYK-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 50,
            'product_price' => 14000,
            'product_cost' => 12000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 12000,
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-LEGACY-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'payment_method' => 'Cash',
            'total_amount' => 24000,
            'due_amount' => 24000,
            'paid_amount' => 0,
        ]);

        // Legacy purchase detail without snapshot columns
        $legacyDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_price' => 12000,
            'price' => 12000,
            'sub_total' => 24000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => null,
            'product_unit_conversion_id' => null,
            'entered_quantity' => null,
            'entered_unit_price' => null,
            'conversion_factor' => null,
            'unit_name' => null,
            'base_unit_name' => null,
        ]);

        // Legacy accessors fallback correctly
        $this->assertEquals(2.0, (float) $legacyDetail->effective_entered_quantity);
        $this->assertEquals(12000.0, (float) $legacyDetail->effective_entered_unit_price);
        $this->assertEquals(1.0, (float) $legacyDetail->effective_conversion_factor);
        $this->assertEquals('PCS', $legacyDetail->effective_unit_name);
        $this->assertEquals('PCS', $legacyDetail->effective_base_unit_name);

        // Accessing edit view does not corrupt database row
        $response = $this->actingAsUser()->get(route('purchases.edit', $purchase->id));
        $response->assertStatus(200);

        $reloadedDetail = PurchaseDetail::find($legacyDetail->id);
        $this->assertNull($reloadedDetail->entered_quantity); // Database row remains unwritten legacy row
    }

    public function test_changed_deactivated_or_deleted_conversion_config_preserves_stored_snapshots(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Beras Organik',
            'product_code' => 'BRS-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 15000,
            'product_cost' => 12000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 12000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 20.0,
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-DELETED-CONV-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 240000,
            'due_amount' => 240000,
            'paid_amount' => 0,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 20, // 1 BOX * 20 = 20 PCS
            'unit_price' => 12000,
            'price' => 12000,
            'sub_total' => 240000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 1,
            'entered_unit_price' => 240000,
            'conversion_factor' => 20,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // Delete the ProductUnitConversion record
        $conversion->delete();

        // The purchase detail still preserves its snapshotted values
        $freshDetail = PurchaseDetail::find($detail->id);
        $this->assertEquals(20.0, (float) $freshDetail->quantity);
        $this->assertEquals(1.0, (float) $freshDetail->entered_quantity);
        $this->assertEquals(20.0, (float) $freshDetail->conversion_factor);
        $this->assertEquals('BOX', $freshDetail->effective_unit_name);

        // Submitting an update for an unrelated edit (e.g. note change) succeeds and preserves the snapshot factor 20
        $updatePayload = [
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-DELETED-CONV-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term' => $this->paymentTerm->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'note' => 'Unrelated edit note',
            'total_amount' => 240000,
            'cart' => [
                [
                    'purchase_detail_id' => $detail->id,
                    'product_id' => $product->id,
                    'purchase_unit_id' => $this->boxUnit->id,
                    'product_unit_conversion_id' => $conversion->id,
                    'entered_quantity' => 1.0,
                    'entered_unit_price' => 240000.0,
                    'quantity' => 20.0,
                    'unit_price' => 12000.0,
                    'conversion_factor' => 20.0,
                    'unit_name' => 'BOX',
                    'base_unit_name' => 'PCS',
                ],
            ],
        ];

        $response = $this->actingAsUser()->put(route('purchases.update', $purchase->id), $updatePayload);
        $response->assertSessionHasNoErrors();

        $reSavedDetail = PurchaseDetail::where('purchase_id', $purchase->id)->firstOrFail();
        $this->assertEquals(20.0, (float) $reSavedDetail->quantity);
        $this->assertEquals(1.0, (float) $reSavedDetail->entered_quantity);
        $this->assertEquals(20.0, (float) $reSavedDetail->conversion_factor);
        $this->assertEquals('BOX', $reSavedDetail->unit_name);
    }

    public function test_livewire_edit_form_rehydrates_entered_quantity_unit_price_and_selected_unit(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Teh Celup',
            'product_code' => 'TEH-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 2000,
            'product_cost' => 1500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 1500,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-LIVEWIRE-EDIT-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 120000,
            'due_amount' => 120000,
            'paid_amount' => 0,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24, // 2 BOX * 12 = 24 PCS
            'unit_price' => 5000, // 60000 / 12 = 5000
            'price' => 5000,
            'sub_total' => 120000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 60000,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $this->actingAsUser();

        // Test restoreCart rehydrates 2 BOX and 60.000 into cart item
        \Livewire\Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id])
            ->assertStatus(200);

        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content();
        $this->assertCount(1, $cartContent);
        $cartItem = $cartContent->first();

        $this->assertEquals(2.0, (float) $cartItem->qty); // Rehydrated entered quantity (2 BOX), NOT canonical 24
        $this->assertEquals(60000.0, (float) $cartItem->price); // Rehydrated entered price (60.000 per BOX)
        $this->assertEquals($this->boxUnit->id, $cartItem->options->purchase_unit_id);
        $this->assertEquals($conversion->id, $cartItem->options->product_unit_conversion_id);
    }

    public function test_monetary_edit_guards_reject_omitted_unit_or_tampered_factor(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Minyak Kita',
            'product_code' => 'MYK-KITA',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 50,
            'product_price' => 14000,
            'product_cost' => 12000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 12000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-MONETARY-STRICT-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 120000,
            'due_amount' => 120000,
            'paid_amount' => 0,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24, // 2 BOX * 12 = 24 PCS
            'unit_price' => 5000,
            'price' => 5000,
            'sub_total' => 120000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 60000,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $this->actingAsUser();
        $monetaryEditService = app(PurchaseMonetaryEditService::class);

        // Test 1: Omitted purchase_unit_id (null) when detail has stored unit_id -> rejected
        $itemOmittedUnit = [
            'id' => $product->id,
            'qty' => 2,
            'price' => 60000,
            'options' => [
                PurchaseMonetaryEditService::DETAIL_ID_OPTION => $detail->id,
                'product_id' => $product->id,
                'purchase_unit_id' => null, // Omitted/cleared unit!
            ],
        ];

        try {
            $monetaryEditService->apply($purchase, [$itemOmittedUnit], ['shipping' => 0, 'global_discount' => 0]);
            $this->fail('Expected MonetaryEditException for omitted unit.');
        } catch (MonetaryEditException $e) {
            $this->assertStringContainsString('Satuan pembelian pada baris', $e->getMessage());
        }

        // Test 2: Tampered conversion_factor -> rejected
        $itemTamperedFactor = [
            'id' => $product->id,
            'qty' => 2,
            'price' => 60000,
            'options' => [
                PurchaseMonetaryEditService::DETAIL_ID_OPTION => $detail->id,
                'product_id' => $product->id,
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 5, // Tampered factor!
            ],
        ];

        try {
            $monetaryEditService->apply($purchase, [$itemTamperedFactor], ['shipping' => 0, 'global_discount' => 0]);
            $this->fail('Expected MonetaryEditException for tampered factor.');
        } catch (MonetaryEditException $e) {
            $this->assertStringContainsString('Faktor konversi pada baris', $e->getMessage());
        }
    }

    public function test_post_receipt_and_monetary_only_edit_restrictions_prevent_unit_conversion_or_factor_changes(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Gula Pasir',
            'product_code' => 'GLA-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 50,
            'product_price' => 15000,
            'product_cost' => 12000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 12000,
        ]);

        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-RCVD-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_RECEIVED, // Received!
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 60000,
            'due_amount' => 60000,
            'paid_amount' => 0,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'unit_price' => 12000,
            'price' => 12000,
            'sub_total' => 60000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->pcsUnit->id,
            'product_unit_conversion_id' => null,
            'entered_quantity' => 5,
            'entered_unit_price' => 12000,
            'conversion_factor' => 1,
            'unit_name' => 'PCS',
            'base_unit_name' => 'PCS',
        ]);

        $this->actingAsUser();
        $this->assertEquals(Purchase::EDIT_MODE_MONETARY_ONLY, $purchase->resolveEditMode());

        $monetaryEditService = app(PurchaseMonetaryEditService::class);

        // Attempting to change unit_id on a received purchase row must fail
        $cartItemTampered = [
            'id' => $product->id,
            'qty' => 5,
            'price' => 12000,
            'options' => [
                PurchaseMonetaryEditService::DETAIL_ID_OPTION => $detail->id,
                'product_id' => $product->id,
                'purchase_unit_id' => $this->boxUnit->id, // Tampered unit!
            ],
        ];

        $this->expectException(MonetaryEditException::class);
        $this->expectExceptionMessage("Satuan pembelian pada baris 'GULA PASIR' tidak boleh diubah setelah barang diterima.");

        $monetaryEditService->apply($purchase, [$cartItemTampered], [
            'shipping' => 0,
            'global_discount' => 0,
            'global_discount_type' => 'fixed',
        ]);
    }

    public function test_forged_purchase_detail_id_from_other_purchase_or_creation_is_ignored_and_does_not_grant_historical_authority(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Kapal',
            'product_code' => 'KPI-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 5000,
            'product_cost' => 4000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 4000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 20.0,
        ]);

        // Purchase A has a historical line snapshotted with factor 20
        $purchaseA = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => 'PR-AUTH-A',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 80000,
            'due_amount' => 80000,
            'paid_amount' => 0,
        ]);

        $detailA = PurchaseDetail::create([
            'purchase_id' => $purchaseA->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 20,
            'unit_price' => 4000,
            'price' => 4000,
            'sub_total' => 80000,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'manual',
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 1,
            'entered_unit_price' => 80000,
            'conversion_factor' => 20,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // Now delete the conversion record in DB
        $conversion->delete();

        // Attempt to create a NEW purchase passing detailA->id in cart options
        $normalizer = app(PurchaseNormalizer::class);
        $forgedInput = [
            'options' => [
                'purchase_detail_id' => $detailA->id, // Forged ID from Purchase A!
                'product_id' => $product->id,
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $detailA->product_unit_conversion_id,
            ],
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 80000,
        ];

        // Normalizing for a NEW purchase (no existingPurchase passed) must fail because conversion is deleted in DB and forged ID is ignored
        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize(['discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0], [$forgedInput], false, $this->setting->id);
    }

    public function test_livewire_cart_unit_selection_mutates_unit_and_recalculates_quantity_and_price_both_directions(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Susu Kotak',
            'product_code' => 'SSU-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 10000,
            'product_cost' => 8000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 8000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        // Add 2 BOX @ 60.000 to purchase cart
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        $cartItem = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 2.0,
            'price' => 60000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'PCS',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 12.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 2.0,
                'entered_unit_price' => 60000.0,
                'sub_total' => 120000.0,
            ],
        ]);

        $this->actingAsUser();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        // 1. Mutate unit from BOX -> PCS (entered qty stays 2)
        $component->call('updateUnit', $cartItem->rowId, 'base_' . $this->pcsUnit->id);

        $updatedItem = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->first();
        $this->assertEquals(2.0, (float) $updatedItem->qty); // Entered qty preserved: 2 PCS
        $this->assertEquals(5000.0, (float) $updatedItem->price); // Entered price: 60000 / 12 = 5000 per PCS
        $this->assertEquals(10000.0, (float) $updatedItem->options->sub_total); // 2 * 5000 = 10,000
        $this->assertEquals($this->pcsUnit->id, $updatedItem->options->purchase_unit_id);
        $this->assertNull($updatedItem->options->product_unit_conversion_id);

        // 2. Mutate unit back from PCS -> BOX (entered qty stays 2)
        // 2 PCS becomes 2 BOX at 60,000 per BOX, subtotal = 120,000
        $component->call('updateUnit', $updatedItem->rowId, 'conv_' . $conversion->id);

        $reUpdatedItem = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->first();
        $this->assertEquals(2.0, (float) $reUpdatedItem->qty); // Entered qty stays 2
        // Price: canonical_base_price = 5000, new_entered_price = 5000 * 12 = 60,000 per BOX
        $this->assertEquals(60000.0, (float) $reUpdatedItem->price, 'Price should be 60,000 per BOX');
        $this->assertEquals(120000.0, (float) $reUpdatedItem->options->sub_total); // 2 * 60,000 = 120,000
        $this->assertEquals($this->boxUnit->id, $reUpdatedItem->options->purchase_unit_id);
        $this->assertEquals($conversion->id, $reUpdatedItem->options->product_unit_conversion_id);
    }

    public function test_livewire_cart_supports_multiple_lines_for_same_product_without_state_collision(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mie Instan',
            'product_code' => 'MIE-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 200,
            'product_price' => 3000,
            'product_cost' => 2500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 2500,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 40.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();

        // Line 1: 2 BOX @ 100.000
        $item1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 2.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 200,
                'unit' => 'PCS',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 40.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 2.0,
                'entered_unit_price' => 100000.0,
                'sub_total' => 200000.0,
            ],
        ]);

        // Line 2: 5 PCS @ 2.500
        $item2 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 5.0,
            'price' => 2500.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 200,
                'unit' => 'PCS',
                'purchase_unit_id' => $this->pcsUnit->id,
                'product_unit_conversion_id' => null,
                'conversion_factor' => 1.0,
                'unit_name' => 'PCS',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 5.0,
                'entered_unit_price' => 2500.0,
                'sub_total' => 12500.0,
            ],
        ]);

        $this->assertNotEquals($item1->rowId, $item2->rowId);

        $this->actingAsUser();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        $quantitiesMap = $component->get('quantity');
        $rowIds = array_keys($quantitiesMap);
        $rowId1 = $rowIds[0];
        $rowId2 = $rowIds[1];

        $this->assertEquals(2.0, (float) ($quantitiesMap[$rowId1] ?? 0));
        $this->assertEquals(5.0, (float) ($quantitiesMap[$rowId2] ?? 0));

        $unitPrices = $component->get('unit_price');
        $this->assertEquals(100000.0, (float) ($unitPrices[$rowId1] ?? 0));
        $this->assertEquals(2500.0, (float) ($unitPrices[$rowId2] ?? 0));

        // Update quantity of line 1 to 3 BOX
        $component->call('updateQuantityDirect', $rowId1, $product->id, 3.0);

        // Verify line 1 updated to 3.0, while line 2 remains 5.0 in both Livewire state and Cart
        $updatedQuantities = array_values($component->get('quantity'));
        sort($updatedQuantities);
        $this->assertEquals([3.0, 5.0], $updatedQuantities);

        $freshQtys = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->pluck('qty')->map(fn ($q) => (float) $q)->values()->all();
        sort($freshQtys);
        $this->assertEquals([3.0, 5.0], $freshQtys);
    }

    public function test_unit_selector_excludes_ineligible_conversions_but_preserves_historical_snapshots(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Sachet',
            'product_code' => 'KPI-002',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 2000,
            'product_cost' => 1500,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 1500,
        ]);

        // Active conversion 1 (BOX = 10 PCS)
        $activeConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 10.0,
        ]);

        // Inactive conversion unit (SLOP = 50 PCS)
        $slopUnit = \Modules\Setting\Entities\Unit::create(['name' => 'SLOP', 'short_name' => 'SLP', 'is_active' => false]);
        $inactiveConv = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $slopUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 50.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();

        // Cart item has historical line snapshotted with inactive conv (SLOP)
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 75000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'PCS',
                'purchase_unit_id' => $slopUnit->id,
                'product_unit_conversion_id' => $inactiveConv->id,
                'conversion_factor' => 50.0,
                'unit_name' => 'SLOP',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 75000.0,
                'sub_total' => 75000.0,
            ],
        ]);

        $this->actingAsUser();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        $availableUnitsMap = $component->get('available_units');
        $availableUnits = array_values($availableUnitsMap)[0] ?? [];
        $unitIds = collect($availableUnits)->pluck('id')->all();

        // Base unit and active conversion unit must be present
        $this->assertContains('base_' . $this->pcsUnit->id, $unitIds);
        $this->assertContains('conv_' . $activeConv->id, $unitIds);

        // Inactive conversion unit MUST be preserved for this historical line
        $this->assertContains('conv_' . $inactiveConv->id, $unitIds);

        // Now test a NEW cart item without historical snapshot: inactive conversion must be excluded
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 1500.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'PCS',
                'purchase_unit_id' => $this->pcsUnit->id,
                'product_unit_conversion_id' => null,
                'conversion_factor' => 1.0,
                'unit_name' => 'PCS',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 1500.0,
                'sub_total' => 1500.0,
            ],
        ]);

        $component2 = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);
        $newAvailableUnitsMap = $component2->get('available_units');
        $newAvailableUnits = array_values($newAvailableUnitsMap)[0] ?? [];
        $newUnitIds = collect($newAvailableUnits)->pluck('id')->all();

        $this->assertContains('base_' . $this->pcsUnit->id, $newUnitIds);
        $this->assertContains('conv_' . $activeConv->id, $newUnitIds);
        $this->assertNotContains('conv_' . $inactiveConv->id, $newUnitIds);
    }

    public function test_unit_switch_rejects_unrepresentable_quantity_precision_without_silent_rounding(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Sirup Botol',
            'product_code' => 'SRP-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 20000,
            'product_cost' => 15000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 15000,
        ]);

        // Conversion factor 12 (BOX = 12 BOTOL)
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();

        // With the new rule: entered qty unchanged, canonical = entered × factor
        // A fractional entered qty with scale > 3 should be rejected (e.g., 0.1234 PCS)
        // When multiplied by factor 12: 0.1234 × 12 = 1.4808 (still scale 4 > 3)
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 0.1234, // scale 4 - exceeds the limit
            'price' => 15000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'PCS',
                'purchase_unit_id' => $this->pcsUnit->id,
                'product_unit_conversion_id' => null,
                'conversion_factor' => 1.0,
                'unit_name' => 'PCS',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 0.1234,
                'entered_unit_price' => 15000.0,
                'sub_total' => 1851.0,
            ],
        ]);
        $this->actingAsUser();

        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values();
        $rowId = $cartContent[0]->rowId;

        // Set the component state to match the cart
        $component->set("quantity.{$rowId}", 0.1234);
        $component->set("unit_price.{$rowId}", 15000);

        // Attempting to switch 0.1234 PCS to BOX should be rejected because 0.1234 has scale > 3
        $component->call('updateUnit', $rowId, 'conv_' . $conversion->id);

        // Verify session flash message warning
        $component->assertSee('tidak dapat digunakan dengan skala yang diterima');

        // Quantity in cart must remain 0.1234 PCS and unit remain PCS
        $item = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->get($rowId);
        $this->assertEquals(0.1234, (float) $item->qty);
        $this->assertEquals($this->pcsUnit->id, $item->options->purchase_unit_id);
        $this->assertNull($item->options->product_unit_conversion_id);
    }

    public function test_unit_switch_migrates_all_row_state_to_new_row_id(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Dus',
            'product_code' => 'KPI-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 50,
            'product_price' => 120000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        $this->setting->update(['is_pkp' => true]);

        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN 11%', 'value' => 11.0, 'is_default' => false]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 120000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 50,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 12.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 120000.0,
                'sub_total' => 120000.0,
                'product_discount' => 5000.0,
                'product_discount_type' => 'fixed',
                'product_tax' => $tax->id,
            ],
        ]);

        $this->actingAsUser();
        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values();
        $oldRowId = $cartContent[0]->rowId;

        // Switch unit from BOX to base unit PCS
        $component->call('updateUnit', $oldRowId, 'base_' . $this->pcsUnit->id);

        $newCartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values();
        $newRowId = $newCartContent[0]->rowId;

        $this->assertNotEquals($oldRowId, $newRowId);

        // Assert all Livewire row state migrated to $newRowId
        // Original: 1 BOX @ 5000 discount = 5000 fixed per BOX (canonical = 5000/12 = 416.67 per PCS)
        // After switch to PCS (entered qty stays 1): entered discount = 416.67 * 1 = 416.67 per PCS
        $this->assertEqualsWithDelta(416.67, (float) ($component->get('item_discount')[$newRowId] ?? 0), 0.01, 'Entered discount should scale from canonical');
        $this->assertEquals('fixed', $component->get('discount_type')[$newRowId] ?? null);
        $this->assertEquals($tax->id, $component->get('product_tax')[$newRowId] ?? null);
        $this->assertNotEmpty($component->get('quantityBreakdowns')[$newRowId] ?? '');

        // Assert old row ID state was removed
        $this->assertArrayNotHasKey($oldRowId, $component->get('item_discount'));
        $this->assertArrayNotHasKey($oldRowId, $component->get('discount_type'));
        $this->assertArrayNotHasKey($oldRowId, $component->get('product_tax'));
    }

    public function test_unit_switch_preserves_canonical_price_across_roundtrip_conversions(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Minyak Goreng',
            'product_code' => 'MYK-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        // 1 BOX = 3 PCS (repeating price: 100,000 / 3 = 33,333.333333...)
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                'sub_total' => 100000.0,
                'sub_total_before_tax' => 100000.0,
                'product_discount' => 0.0,
            ],
        ]);

        $this->actingAsUser();
        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);
        $component->set('is_tax_included', false);

        $cart1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId1 = $cart1->rowId;
        $this->assertEquals(100000.0, (float) $cart1->options->sub_total);
        // When switching, we'll calculate the canonical price from current entered price / old factor
        // Initial: entered_price = 100000, factor = 3, so canonical = 100000 / 3 = 33333.333333

        // 1. Switch BOX → PCS: entered qty stays 1, entered price scales
        // Canonical base price = 100,000 / 3 = 33,333.333333 (computed from current entered price / factor)
        // New entered price = 33,333.333333 × 1 = 33,333.333333 (displayed as 33333.33)
        $component->call('updateUnit', $rowId1, 'base_' . $this->pcsUnit->id);

        $cart2 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId2 = $cart2->rowId;
        $this->assertEquals(1.0, (float) $cart2->qty, 'After BOX→PCS switch: entered qty should still be 1');
        $this->assertEqualsWithDelta(33333.33, (float) $cart2->price, 0.01, 'After BOX→PCS: entered price = 100,000 / 3 = 33,333.33');
        $this->assertEqualsWithDelta(33333.333333, (float) $cart2->options->canonical_unit_price, 0.000001, 'Canonical base price is 100,000 / 3');
        $this->assertEqualsWithDelta(33333.33, (float) $cart2->options->sub_total, 0.01, 'Subtotal: 1 × 33,333.33 = 33,333.33');

        // 2. Switch PCS → BOX: entered qty stays 1, entered price scales
        // Canonical base price stays 33,333.333333
        // New entered price = 33,333.333333 × 3 = 100,000
        $component->call('updateUnit', $rowId2, 'conv_' . $conversion->id);

        $cart3 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $this->assertEquals(1.0, (float) $cart3->qty, 'After PCS→BOX round-trip: entered qty should still be 1');
        $this->assertEqualsWithDelta(100000.0, (float) $cart3->price, 0.01, 'After round-trip: entered price = 33,333.33 × 3 = 100,000');
        $this->assertEqualsWithDelta(33333.333333, (float) $cart3->options->canonical_unit_price, 0.01, 'Canonical base price remains constant');
        $this->assertEqualsWithDelta(100000.0, (float) $cart3->options->sub_total, 0.01, 'Subtotal: 1 × 100,000 = 100,000');
    }

    public function test_unit_switch_with_factor_12_fixed_discount(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Dus 12',
            'product_code' => 'KPI-012',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 60000,
            'product_cost' => 50000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 60000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 2.0, // 2 BOX
            'price' => 60000.0,
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
                'entered_quantity' => 2.0,
                'entered_unit_price' => 60000.0,
                'product_discount' => 5000.0, // 5000 fixed discount per BOX
                'product_discount_input' => 5000.0,
                'product_discount_type' => 'fixed',
                'sub_total' => 110000.0, // (60,000 - 5,000) * 2 = 110,000
                'sub_total_before_tax' => 110000.0,
            ],
        ]);

        $this->actingAsUser();
        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        $cart1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId1 = $cart1->rowId;

        // Switch 2 BOX to 2 PCS (entered qty preserved, canonical becomes 2 PCS * 12 = 24 PCS canonical)
        // Wait, that's wrong. Let me reconsider:
        // The rule is: entered qty unchanged. So 2 BOX → 2 PCS (entered qty = 2).
        // But the canonical qty = 2 PCS * 1 = 2 PCS (at factor 1).
        //
        // Let me re-read the instruction. It says the entered qty is never divided.
        // So if we enter 2 BOX and switch to PCS, entered qty stays 2 (now 2 PCS).
        // But that means canonical = 2 * 1 = 2, not 24.
        //
        // That doesn't match the working example in the spec which shows:
        // "2 BOX → 24 PCS decomposition"
        //
        // Let me re-read more carefully. The spec says canonical = entered × factor.
        // For BOX→PCS: factor changes from 12 to 1.
        // canonical_qty = entered_qty (2) × new_factor (1) = 2.
        //
        // But the spec explicitly says "the rule that produced 2 BOX → 24 PCS decomposition is wrong and must go."
        //
        // So the expected behavior is: 2 BOX entered → 2 PCS entered (canonical = 2 * 1 = 2).
        // The line subtotal would change from (60,000-5,000)*2 = 110,000 to (5,000-416.67)*2 = 8,166.66.
        //
        // The discount: canonical = 5,000 / 12 = 416.67 per PCS base unit.
        // For entered unit (PCS), new entered discount = 416.67 * 1 = 416.67 per PCS.
        $component->call('updateUnit', $rowId1, 'base_' . $this->pcsUnit->id);

        $cart2 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $this->assertEquals(2.0, (float) $cart2->qty, 'Entered qty: 2 BOX → 2 PCS (unchanged)');
        $this->assertEquals(5000.0, (float) $cart2->price, 'Entered price: 60,000 / 12 = 5,000 per PCS');
        $this->assertEqualsWithDelta(416.666667, (float) $cart2->options->product_discount, 0.000001, 'Discount per entered unit: 5,000 / 12 ≈ 416.67');
        $this->assertEqualsWithDelta(9166.67, (float) $cart2->options->sub_total, 0.01, 'Subtotal: (5,000 - 416.67) × 2 ≈ 9,166.67');
    }

    public function test_unit_switch_with_percentage_discount(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Teh Celup Dus',
            'product_code' => 'TEH-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 60000,
            'product_cost' => 50000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 60000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 2.0,
            'price' => 60000.0,
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
                'entered_quantity' => 2.0,
                'entered_unit_price' => 60000.0,
                'product_discount' => 6000.0, // 10% of 60,000 = 6000
                'product_discount_input' => 10.0,
                'product_discount_type' => 'percentage',
                'sub_total' => 108000.0, // (60,000 - 6,000) * 2 = 108,000
                'sub_total_before_tax' => 108000.0,
            ],
        ]);

        $this->actingAsUser();
        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);

        $cart1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId1 = $cart1->rowId;

        // Switch 2 BOX to 2 PCS (entered qty preserved)
        // Original: 2 BOX @ 60,000 with 10% discount (6,000 per BOX)
        // Canonical: price = 60,000/12 = 5,000 per PCS, discount pct = 10% (unchanged for percentages)
        // After switch: 2 PCS @ 5,000 with 10% discount (500 per PCS)
        $component->call('updateUnit', $rowId1, 'base_' . $this->pcsUnit->id);

        $cart2 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $this->assertEquals(2.0, (float) $cart2->qty, 'Entered qty: 2 BOX → 2 PCS (unchanged)');
        $this->assertEquals(5000.0, (float) $cart2->price, 'Entered price: 60,000 / 12 = 5,000 per PCS');
        $this->assertEquals(10.0, (float) ($cart2->options->product_discount_input ?? 0), 'Discount percentage stays 10%');
        $this->assertEquals(500.0, (float) ($cart2->options->product_discount ?? 0), 'Discount amount: 5,000 * 10% = 500');
        $this->assertEqualsWithDelta(9000.0, (float) $cart2->options->sub_total, 0.01, 'Subtotal: (5,000 - 500) * 2 = 9,000');
    }

    public function test_converted_line_subsequent_cart_interactions_without_monetary_drift(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Beras Karung',
            'product_code' => 'BRS-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3.0,
        ]);

        $this->setting->update(['is_pkp' => true]);
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN 11%', 'value' => 11.0, 'is_default' => false]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                'sub_total' => 100000.0,
                'sub_total_before_tax' => 100000.0,
            ],
        ]);

        $this->actingAsUser();
        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);
        $component->set('is_tax_included', false);

        $cart1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId1 = $cart1->rowId;

        // Switch to PCS: entered qty stays 1, canonical = 1 * 1 = 1 PCS
        // Canonical price: 100,000 / 3 = 33,333.333333, stays constant
        // Entered price: 33,333.333333 * 1 = 33,333.333333
        $component->call('updateUnit', $rowId1, 'base_' . $this->pcsUnit->id);
        $cart2 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId2 = $cart2->rowId;
        $this->assertEquals(1.0, (float) $cart2->qty, 'After switch: entered qty still 1 PCS');

        // Subsequent Action 1: Update quantity from 1 to 6 PCS
        $component->call('updateQuantityDirect', $rowId2, $product->id, 6.0);

        $cart3 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId3 = $cart3->rowId;
        $this->assertEquals(6.0, (float) $cart3->qty);
        $this->assertEqualsWithDelta(200000.0, (float) $cart3->options->sub_total_before_tax, 0.01, 'Exactly 200,000.00 (not 199,999.98): 6 * 33,333.333333 = 200,000');

        // Subsequent Action 2: Update tax on converted row
        $component->call('updateTax', $rowId3, $product->id, $tax->id);

        $cart4 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $this->assertEquals(200000.0, (float) $cart4->options->sub_total_before_tax);
        $this->assertEqualsWithDelta(22000.0, (float) $cart4->options->product_tax_amount, 0.01); // 11% of 200,000 = 22,000
        $this->assertEqualsWithDelta(222000.0, (float) $cart4->options->sub_total, 0.01); // 200,000 + 22,000 = 222,000
    }

    public function test_unit_switch_save_and_reload_edit_cart_stability(): void
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Gula Pasir',
            'product_code' => 'GLA-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                'sub_total' => 100000.0,
                'sub_total_before_tax' => 100000.0,
            ],
        ]);

        $this->actingAsUser();
        $cartComponent = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);
        $rowId1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0]->rowId;

        // Switch to PCS (entered qty stays 1, canonical becomes 1 * 1 = 1)
        $cartComponent->call('updateUnit', $rowId1, 'base_' . $this->pcsUnit->id);

        // Save purchase via Normalizer
        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content();
        $this->assertEquals(1.0, (float) $cartContent->first()->qty, 'After switch: entered qty should be 1');

        $normalizedPurchase = app(\Modules\Purchase\Services\PurchaseNormalizer::class)->normalize([
            'reference' => 'PO-STABILITY-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'status' => 'Pending',
            'payment_method' => 'Cash',
            'paid_amount' => 100000,
            'total_amount' => 100000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ], $cartContent, false, null);

        $purchase = Purchase::create(array_merge($normalizedPurchase['header'], [
            'reference' => 'PO-STABILITY-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'payment_term_id' => \Modules\Purchase\Entities\PaymentTerm::defaultCodTermId(),
            'paid_amount' => 0,
            'payment_method' => 'Cash',
        ]));

        foreach ($normalizedPurchase['details'] as $detail) {
            PurchaseDetail::create(array_merge($detail, [
                'purchase_id' => $purchase->id,
            ]));
        }

        // After switch from 1 BOX @ 100,000 to 1 PCS @ 33,333.33, total should be 33,333.33
        $this->assertEqualsWithDelta(33333.33, (float) $purchase->total_amount, 0.01);

        // Reload purchase in Edit form
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        $editComponent = \Livewire\Livewire::test(\App\Livewire\Purchase\EditForm::class, ['purchaseId' => $purchase->id]);

        $reloadedItem = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        // After switch from 1 BOX @ 100,000 to 1 PCS @ 33,333.33, qty should be 1
        $this->assertEquals(1.0, (float) $reloadedItem->qty);
        $this->assertEqualsWithDelta(33333.33, (float) $reloadedItem->options->sub_total_before_tax, 0.01);

        // Modify quantity on reloaded line from 3 to 6
        $editCartComponent = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase]);
        $editCartComponent->call('updateQuantityDirect', $reloadedItem->rowId, $product->id, 6.0);

        $reloadedUpdatedItem = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->first();
        $this->assertEquals(6.0, (float) $reloadedUpdatedItem->qty);
        $this->assertEquals(200000.0, (float) $reloadedUpdatedItem->options->sub_total_before_tax);
    }

    public function test_manual_line_total_override_through_unit_switch(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Kopi Spesial',
            'product_code' => 'KPI-009',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3.0,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                'sub_total' => 100000.0,
                'sub_total_before_tax' => 100000.0,
            ],
        ]);

        $this->actingAsUser();
        $component = \Livewire\Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase']);
        $rowId1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0]->rowId;

        // Manually override price of 1 BOX to Rp120,000
        $component->set('unit_price.' . $rowId1, 120000.0);
        $component->call('updatePrice', $rowId1, $product->id);

        $cart1 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $rowId2 = $cart1->rowId;
        $this->assertEquals(120000.0, (float) $cart1->options->sub_total);

        // Switch to PCS (entered qty stays 1)
        // Canonical price: 120,000 / 3 = 40,000 per PCS
        // Entered price: 40,000 × 1 = 40,000
        // Subtotal: 1 × 40,000 = 40,000
        $component->call('updateUnit', $rowId2, 'base_' . $this->pcsUnit->id);

        $cart2 = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content()->values()[0];
        $this->assertEquals(1.0, (float) $cart2->qty, 'After switch: entered qty should be 1');
        $this->assertEquals(40000.0, (float) $cart2->price); // 120,000 / 3 = 40,000
        $this->assertEquals(40000.0, (float) $cart2->options->sub_total); // 1 × 40,000 = 40,000
    }

    public function test_create_purchase_ignores_forged_canonical_unit_price_in_cart_options(): void
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Forged Canonical Supplier',
            'supplier_email' => 'forged-create@example.com',
            'supplier_phone' => '0812345678',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_name' => 'Kopi Bubuk',
            'product_code' => 'KPB-001',
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                // FORGED client-supplied totals and canonical price, all attempting to
                // claim a base price of 1.00 instead of the server-derived 33,333.333333.
                'sub_total' => 3.0,
                'sub_total_before_tax' => 3.0,
                'canonical_unit_price' => 1.00,
            ],
        ]);

        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content();
        $normalizedPurchase = app(\Modules\Purchase\Services\PurchaseNormalizer::class)->normalize([
            'reference' => 'PO-FORGE-CREATE-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'status' => 'Pending',
            'payment_method' => 'Cash',
            'paid_amount' => 100000,
            'total_amount' => 100000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ], $cartContent, false, null);

        $detail = $normalizedPurchase['details'][0];

        // Server-derived base unit price: 100,000 / 3 = 33333.333333
        $expectedServerBasePrice = 33333.333333;
        $this->assertEquals($expectedServerBasePrice, (float) $detail['unit_price']);
        $this->assertEquals($expectedServerBasePrice, (float) $detail['price']);
        $this->assertNotEquals(1.00, (float) $detail['unit_price']);
        // The entered-unit snapshot must reflect the real entered price, not the forgery.
        $this->assertEquals(100000.0, (float) $detail['entered_unit_price']);
    }

    public function test_edit_purchase_ignores_forged_canonical_unit_price_in_cart_options(): void
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Forged Edit Supplier',
            'supplier_email' => 'forged-edit@example.com',
            'supplier_phone' => '0812345679',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_name' => 'Teh Celup',
            'product_code' => 'TEH-001',
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3,
        ]);

        $purchase = Purchase::create([
            'reference' => 'PO-FORGE-EDIT-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'payment_term_id' => \Modules\Purchase\Entities\PaymentTerm::defaultCodTermId(),
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3.0,
            'unit_price' => 33333.333333,
            'price' => 33333.333333,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 100000,
            'sub_total_before_tax' => 100000,
            'product_tax_amount' => 0,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 1.0,
            'entered_unit_price' => 100000.0,
            'conversion_factor' => 3.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // Submit edit normalizer with forged canonical_unit_price = 999999.99
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                \Modules\Purchase\Services\PurchaseMonetaryEditService::DETAIL_ID_OPTION => $detail->id,
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                // FORGED client-supplied totals and canonical price.
                'sub_total' => 2999999.97,
                'sub_total_before_tax' => 2999999.97,
                'canonical_unit_price' => 999999.99,
            ],
        ]);

        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content();
        $normalizedPurchase = app(\Modules\Purchase\Services\PurchaseNormalizer::class)->normalize([
            'reference' => 'PO-FORGE-EDIT-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'status' => 'Pending',
            'payment_method' => 'Cash',
            'paid_amount' => 100000,
            'total_amount' => 100000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ], $cartContent, true, $purchase->id);

        $normDetail = $normalizedPurchase['details'][0];

        $expectedServerBasePrice = 33333.333333;
        $this->assertEquals($expectedServerBasePrice, (float) $normDetail['unit_price']);
        $this->assertEquals($expectedServerBasePrice, (float) $normDetail['price']);
        $this->assertNotEquals(999999.99, (float) $normDetail['unit_price']);
        // Trusted snapshot fields come from the stored PurchaseDetail, not cart options.
        $this->assertEquals(100000.0, (float) $normDetail['entered_unit_price']);
        $this->assertEquals(3.0, (float) $normDetail['conversion_factor']);
    }

    /**
     * A forged canonical price does not have to be obviously wrong to be dangerous.
     * These hints all reconstruct an entered price within half a cent of the real
     * Rp100,000 and so display identically at two decimals, yet each would persist a
     * different six-decimal inventory cost. None may steer the persisted value.
     *
     * @dataProvider nearEquivalentCanonicalHintProvider
     */
    public function test_near_equivalent_canonical_hint_cannot_steer_persisted_cost(float $forgedHint): void
    {
        $supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Boundary Hint Supplier',
            'supplier_email' => 'boundary-hint@example.com',
            'supplier_phone' => '0812345680',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_name' => 'Gula Pasir',
            'product_code' => 'GLP-001',
            'product_quantity' => 100,
            'product_price' => 100000,
            'product_cost' => 100000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 100000,
        ]);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 3,
        ]);

        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->destroy();
        \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $conversion->id,
                'conversion_factor' => 3.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 1.0,
                'entered_unit_price' => 100000.0,
                'sub_total' => 100000.0,
                'sub_total_before_tax' => 100000.0,
                'canonical_unit_price' => $forgedHint,
            ],
        ]);

        $cartContent = \Gloudemans\Shoppingcart\Facades\Cart::instance('purchase')->content();
        $normalizedPurchase = app(\Modules\Purchase\Services\PurchaseNormalizer::class)->normalize([
            'reference' => 'PO-HINT-BOUNDARY-001',
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'status' => 'Pending',
            'payment_method' => 'Cash',
            'paid_amount' => 100000,
            'total_amount' => 100000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ], $cartContent, false, null);

        $detail = $normalizedPurchase['details'][0];

        // Server-derived: 100,000 / 3, to six decimals.
        $expectedServerBasePrice = 33333.333333;
        $this->assertEqualsWithDelta($expectedServerBasePrice, (float) $detail['unit_price'], 0.0000005);
        $this->assertEqualsWithDelta($expectedServerBasePrice, (float) $detail['price'], 0.0000005);
        $this->assertNotEquals($forgedHint, (float) $detail['unit_price']);
        $this->assertNotEquals($forgedHint, (float) $detail['price']);
    }

    public static function nearEquivalentCanonicalHintProvider(): array
    {
        return [
            // Implies an entered price of 100,000.002 -- inside a half-cent window.
            'just above' => [33333.334000],
            // Implies an entered price of 99,999.996 -- inside a half-cent window.
            'just below' => [33333.332000],
            // Implies exactly 100,000.0049997: the extreme edge of the window.
            'edge of window' => [33333.3349999],
        ];
    }

    private function actingAsUser()
    {
        $user = \App\Models\User::factory()->create();
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.create');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.access');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.update');
        \Spatie\Permission\Models\Permission::findOrCreate('purchases.received.monetary.edit');
        $user->givePermissionTo('purchases.create', 'purchases.access', 'purchases.update', 'purchases.received.monetary.edit');

        return $this->actingAs($user)->withSession(['setting_id' => $this->setting->id]);
    }
}
