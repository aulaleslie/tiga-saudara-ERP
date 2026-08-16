<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUomCorrectionAudit;
use Modules\Product\Entities\ProductUomCorrectionRemovedDocument;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Product\Entities\Transaction;
use Modules\Product\Services\ProductImportUomEligibilityService;
use Modules\Product\Services\ProductImportUomMutationService;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductImportUomConversionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $location;
    private Unit $boxUnit;
    private Unit $pcsUnit;
    private ProductImportUomEligibilityService $eligibilityService;
    private ProductImportUomMutationService $mutationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();
        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Utama',
        ]);

        $this->boxUnit = Unit::create(['name' => 'BUNGKUS', 'short_name' => 'BKS', 'setting_id' => $this->setting->id]);
        $this->pcsUnit = Unit::create(['name' => 'PIECES', 'short_name' => 'PCS', 'setting_id' => $this->setting->id]);

        $this->eligibilityService = app(ProductImportUomEligibilityService::class);
        $this->mutationService = app(ProductImportUomMutationService::class);
    }

    private function createImportProduct(array $attributes = []): Product
    {
        $product = Product::create(array_merge([
            'product_name' => 'Rokok Sampoerna Mild',
            'product_code' => 'SAM-001',
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->boxUnit->id,
            'stock_managed' => 1,
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 82000,
            'product_price' => 90000,
        ], $attributes));

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 6,
            'quantity_non_tax' => 4,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'average_purchase_price' => 82000,
            'last_purchase_price' => 82000,
            'sale_price' => 90000,
            'tier_1_price' => 88000,
            'tier_2_price' => 86000,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-IMPORT-' . $product->id,
            'status' => 'RECEIVED',
            'payment_status' => 'UNPAID',
            'total_amount' => 82000,
            'paid_amount' => 0,
            'due_amount' => 82000,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'unit_price' => 8200,
            'price' => 8200,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'sub_total' => 82000,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Originating ADJ transaction
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'ADJ',
            'quantity' => 10,
            'current_quantity' => 10,
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 6,
            'quantity_non_tax' => 4,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        return $product;
    }

    // --- Task 2.8 Unit tests for eligibility checks ---

    public function test_eligible_import_product_passes_eligibility()
    {
        $product = $this->createImportProduct();

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertTrue($result->isEligible());
        $this->assertEmpty($result->blockingReasons);
        $this->assertNotNull($result->preview);
        $this->assertEquals(100, $result->preview['projected_product_quantity']);
        $this->assertEquals(8200, $result->preview['prices'][0]['projected_average_purchase_price']);
    }

    public function test_product_with_buy_transaction_is_blocked()
    {
        $product = $this->createImportProduct();

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'BUY',
            'quantity' => 5,
            'current_quantity' => 15,
            'previous_quantity' => 10,
            'after_quantity' => 15,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $product->update(['product_quantity' => 15]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('BUY', implode('; ', $result->blockingReasons));
    }

    public function test_product_with_dispatch_transaction_is_blocked()
    {
        $product = $this->createImportProduct();

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'DISPATCH',
            'quantity' => 1,
            'current_quantity' => 9,
            'previous_quantity' => 10,
            'after_quantity' => 9,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 9,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $product->update(['product_quantity' => 9]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('DISPATCH', implode('; ', $result->blockingReasons));
    }

    public function test_product_with_partially_dispatched_sale_is_blocked()
    {
        $product = $this->createImportProduct();
        $customer = Customer::factory()->create();

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-PARTIAL-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DISPATCHED_PARTIALLY,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => $this->setting->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('penjualan yang sudah terkirim (penuh/sebagian)', implode('; ', $result->blockingReasons));
        $this->assertEmpty($result->removableDocuments, 'Partially dispatched sales must not be marked as removable documents');
    }

    public function test_product_with_return_or_transfer_transaction_is_blocked()
    {
        $product = $this->createImportProduct();

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'SALE_RETURN_GOOD_TAX',
            'quantity' => 1,
            'current_quantity' => 11,
            'previous_quantity' => 10,
            'after_quantity' => 11,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 11,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        $product->update(['product_quantity' => 11]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('SALE_RETURN_GOOD_TAX', implode('; ', $result->blockingReasons));
    }

    public function test_product_with_broken_stock_is_blocked()
    {
        $product = $this->createImportProduct();

        ProductStock::where('product_id', $product->id)->update(['broken_quantity' => 2]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('broken stock', implode('; ', $result->blockingReasons));
    }

    public function test_product_with_barcode_is_eligible_and_barcode_is_cleared_on_execution()
    {
        // Barcode is no longer a blocker: per operator instruction, this correction
        // path simply empties the barcode (not migrates it to a retained old-base
        // conversion, unlike the receiving-normalization tool) and removes the
        // owning barcode_identities registry row so nothing is left dangling.
        $product = $this->createImportProduct(['barcode' => '899123456789']);
        BarcodeIdentity::create([
            'canonical_key' => '899123456789',
            'value' => '899123456789',
            'product_id' => $product->id,
        ]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 82);
        $this->assertTrue($result->isEligible());
        $this->assertEquals('899123456789', $result->preview['current_barcode']);

        $audit = $this->mutationService->execute(
            product: $product,
            targetUnit: $this->pcsUnit,
            factor: 82.0,
            reason: 'Koreksi unit dengan barcode dikosongkan',
        );

        $product->refresh();
        $this->assertNull($product->barcode);
        $this->assertEquals(0, BarcodeIdentity::where('product_id', $product->id)->count());
        $this->assertStringContainsString('899123456789', (string) $audit->rounding_notes);
    }

    public function test_product_with_conversions_is_blocked()
    {
        $product = $this->createImportProduct();

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->boxUnit->id,
            'conversion_factor' => 10,
        ]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('product_unit_conversions', implode('; ', $result->blockingReasons));
    }

    public function test_product_with_price_only_footprint_in_other_settings_is_eligible()
    {
        // product_prices rows commonly exist for every setting as seeded/default cost
        // placeholders even where the product has never actually been stocked or
        // purchased there. Only settings with actual (non-zero) stock should count
        // toward the multi-setting blocker.
        $product = $this->createImportProduct();

        $otherSetting = Setting::factory()->create();

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $otherSetting->id,
            'average_purchase_price' => 82000,
            'last_purchase_price' => 82000,
            'sale_price' => 0,
        ]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 82);

        $this->assertTrue($result->isEligible());
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_product_with_actual_stock_in_multiple_settings_is_blocked()
    {
        $product = $this->createImportProduct();

        $otherSetting = Setting::factory()->create();
        $otherLocation = Location::create([
            'setting_id' => $otherSetting->id,
            'name' => 'Gudang Cabang',
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $otherLocation->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 82);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('stok aktual di lebih dari satu cabang', implode('; ', $result->blockingReasons));
    }

    public function test_product_with_ledger_drift_is_blocked()
    {
        $product = $this->createImportProduct();

        // Mutate product stock without a transaction
        $product->update(['product_quantity' => 50]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('Inkonsistensi ledger global', implode('; ', $result->blockingReasons));
    }

    public function test_removable_pos_draft_and_undispatched_unpaid_sale_are_discovered()
    {
        $product = $this->createImportProduct();

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        $session = PosSession::create([
            'setting_id' => $this->setting->id,
            'cashier_user_id' => $user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $user->id,
        ]);

        // POS Draft
        $pos = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'POS-DRAFT-001',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'source_pos_session_id' => $session->id,
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $pos->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'line_no' => 1,
            'qty' => 2,
            'unit_price' => 90000,
        ]);

        // Unpaid Draft Sale
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-DRAFT-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 90000,
            'paid_amount' => 0,
            'due_amount' => 90000,
            'setting_id' => $this->setting->id,
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 90000,
            'unit_price' => 90000,
            'sub_total' => 90000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $result = $this->eligibilityService->checkEligibility($product, $this->pcsUnit, 10);

        $this->assertTrue($result->isEligible());
        $this->assertCount(2, $result->removableDocuments);
        $types = collect($result->removableDocuments)->pluck('document_type')->all();
        $this->assertContains('POS', $types);
        $this->assertContains('SALE', $types);
    }

    // --- Task 3.6 Mutation service tests ---

    public function test_successful_mutation_rebases_quantities_costs_units_and_deletes_draft_documents()
    {
        $product = $this->createImportProduct();
        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        $session = PosSession::create([
            'setting_id' => $this->setting->id,
            'cashier_user_id' => $user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $user->id,
        ]);

        // Add POS Draft
        $pos = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'POS-DRAFT-002',
            'status' => PosTransaction::STATUS_DRAFT,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'source_pos_session_id' => $session->id,
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $pos->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'line_no' => 1,
            'qty' => 2,
            'unit_price' => 90000,
        ]);

        // Add Unpaid Sale
        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-DRAFT-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 90000,
            'paid_amount' => 0,
            'due_amount' => 90000,
            'setting_id' => $this->setting->id,
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 90000,
            'unit_price' => 90000,
            'sub_total' => 90000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Execute mutation: factor = 82
        $factor = 82.0;
        $audit = $this->mutationService->execute(
            product: $product,
            targetUnit: $this->pcsUnit,
            factor: $factor,
            reason: 'Koreksi unit import dari BKS ke PCS',
            actorUserId: $user->id
        );

        $this->assertInstanceOf(ProductUomCorrectionAudit::class, $audit);
        $this->assertEquals($product->id, $audit->product_id);
        $this->assertEquals($this->boxUnit->id, $audit->old_unit_id);
        $this->assertEquals($this->pcsUnit->id, $audit->new_unit_id);
        $this->assertEquals(82.0, (float) $audit->conversion_factor);

        // Check Product updated
        $product->refresh();
        $this->assertEquals($this->pcsUnit->id, $product->base_unit_id);
        $this->assertEquals($this->pcsUnit->id, $product->unit_id);
        $this->assertEquals(820.0, (float) $product->product_quantity);

        // Check ProductStock updated
        $stock = ProductStock::where('product_id', $product->id)->first();
        $this->assertEquals(820.0, (float) $stock->quantity);
        $this->assertEquals(492.0, (float) $stock->quantity_tax); // 6 * 82
        $this->assertEquals(328.0, (float) $stock->quantity_non_tax); // 4 * 82

        // Check Transaction updated
        $tx = Transaction::where('product_id', $product->id)->first();
        $this->assertEquals(820.0, (float) $tx->quantity);
        $this->assertEquals(820.0, (float) $tx->after_quantity);
        $this->assertEquals(820.0, (float) $tx->after_quantity_at_location);

        // Check ProductPrice updated (82000 / 82 = 1000)
        $price = ProductPrice::where('product_id', $product->id)->first();
        $this->assertEquals(1000.0, (float) $price->average_purchase_price);
        $this->assertEquals(1000.0, (float) $price->last_purchase_price);
        // Sale-side prices are rebased too (90000 / 82 = 1097.56, 88000 / 82 = 1073.17, 86000 / 82 = 1048.78)
        $this->assertEquals(1097.56, (float) $price->sale_price);
        $this->assertEquals(1073.17, (float) $price->tier_1_price);
        $this->assertEquals(1048.78, (float) $price->tier_2_price);

        // Check PurchaseDetail rebased: quantity * factor, unit_price / factor, sub_total unchanged
        $purchaseDetail = PurchaseDetail::where('product_id', $product->id)->first();
        $this->assertEquals(820.0, (float) $purchaseDetail->quantity); // 10 * 82
        $this->assertEquals(100.0, (float) $purchaseDetail->unit_price); // 8200 / 82
        $this->assertEquals(100.0, (float) $purchaseDetail->price);
        $this->assertEquals(82000.0, (float) $purchaseDetail->sub_total); // unchanged
        $this->assertEqualsWithDelta(
            (float) $purchaseDetail->quantity * (float) $purchaseDetail->unit_price,
            (float) $purchaseDetail->sub_total,
            0.01
        );

        // Check documents removed
        $this->assertNull(PosTransaction::find($pos->id));
        $this->assertNull(PosTransactionLine::where('pos_transaction_id', $pos->id)->first());
        $this->assertNull(Sale::find($sale->id));
        $this->assertNull(SaleDetails::where('sale_id', $sale->id)->first());

        // Check removed documents audit rows
        $this->assertCount(2, $audit->removedDocuments);
    }

    // --- Task 4.5 Artisan command feature tests ---

    public function test_artisan_command_dry_run_does_not_mutate()
    {
        $product = $this->createImportProduct();

        $this->artisan('product:convert-uom', [
            'product_id' => $product->id,
            'target_unit' => 'PCS',
            'factor' => '82',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('Validasi Kelayakan Berhasil (Eligible)')
            ->expectsOutputToContain('Simulasi selesai')
            ->assertExitCode(0);

        $product->refresh();
        $this->assertEquals($this->boxUnit->id, $product->base_unit_id);
        $this->assertEquals(10.0, (float) $product->product_quantity);
        $this->assertEquals(0, ProductUomCorrectionAudit::count());
    }

    public function test_artisan_command_execute_requires_reason()
    {
        $product = $this->createImportProduct();

        $this->artisan('product:convert-uom', [
            'product_id' => $product->id,
            'target_unit' => 'PCS',
            'factor' => '82',
        ])
            ->expectsOutputToContain('Opsi --reason wajib diisi')
            ->assertExitCode(1);
    }

    public function test_artisan_command_fails_on_ineligible_product()
    {
        $product = $this->createImportProduct();

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->boxUnit->id,
            'conversion_factor' => 10,
        ]);

        $this->artisan('product:convert-uom', [
            'product_id' => $product->id,
            'target_unit' => 'PCS',
            'factor' => '82',
            '--reason' => 'Test reason',
        ])
            ->expectsOutputToContain('PRODUK TIDAK MEMENUHI SYARAT KOREKSI')
            ->assertExitCode(1);
    }

    public function test_artisan_command_successful_execution()
    {
        $product = $this->createImportProduct();

        $this->artisan('product:convert-uom', [
            'product_id' => $product->id,
            'target_unit' => 'PCS',
            'factor' => '82',
            '--reason' => 'Koreksi unit import rokok',
        ])
            ->expectsOutputToContain('Koreksi UOM berhasil dieksekusi!')
            ->assertExitCode(0);

        $product->refresh();
        $this->assertEquals($this->pcsUnit->id, $product->base_unit_id);
        $this->assertEquals(820.0, (float) $product->product_quantity);
        $this->assertEquals(1, ProductUomCorrectionAudit::count());
    }
}
