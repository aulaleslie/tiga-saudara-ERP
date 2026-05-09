<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Product\Entities\ProductBundleItem;
use Spatie\Permission\Models\Permission;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosReturn\PosReturnCreateForm;

class POSReturnBundleRegressionTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $user;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->setting = $this->createSetting('Bundle Regression Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.returns.create', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_correctly_groups_serialized_bundled_products_by_pos_transaction_line()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // 1. Setup Bundle Components
        $comp1 = $this->createStockedProduct($this->setting, $this->location, ['product_name' => 'Comp 1', 'product_code' => 'C1', 'stock_qty' => 30]);
        $comp2 = $this->createStockedProduct($this->setting, $this->location, ['product_name' => 'Comp 2', 'product_code' => 'C2', 'stock_qty' => 30]);

        // 2. Setup Parent Product (Serialized Samsung-style)
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Samsung Bundle',
            'product_code' => 'S-BUNDLE',
            'serial_number_required' => true,
            'sale_price' => 5000000
        ]);

        // Define Bundle
        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Samsung Bundle Header',
        ]);

        $bi1 = \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp1->id,
            'quantity' => 1,
        ]);
        $bi2 = \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp2->id,
            'quantity' => 2,
        ]);

        // Create Serials for Parent
        $sn1 = $this->createSerialNumber($parent, $this->location, 'SN-001');
        $sn2 = $this->createSerialNumber($parent, $this->location, 'SN-002');

        // 3. Setup Transaction
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TNC-RCP-2026-05-00001',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-BUNDLE',
            'grand_total' => 10000000,
            'idempotency_key' => 'IDEM-BUNDLE',
            'payload_hash' => 'HASH-BUNDLE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $createSale = function($sn) use ($parent, $checkout, $comp1, $comp2, $bundle, $bi1, $bi2) {
            $sale = Sale::create([
                'setting_id' => $this->setting->id,
                'customer_id' => null,
                'customer_name' => 'Walk-in Customer',
                'total_amount' => 5000000,
                'paid_amount' => 5000000,
                'due_amount' => 0,
                'date' => now()->toDateString(),
                'status' => 'DISPATCHED',
                'payment_status' => 'PAID',
                'payment_method' => 'CASH',
                'reference' => 'SO-' . $sn->serial_number,
            ]);
            PosCheckoutSale::create([
                'pos_checkout_id' => $checkout->id,
                'sale_id' => $sale->id,
                'source_setting_id' => $this->setting->id,
                'source_location_id' => $this->location->id,
                'grand_total' => 5000000,
                'split_key' => 'SPLIT-' . $sn->serial_number,
                'tax_bucket' => 'NON_TAX',
            ]);
            $sd = SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $parent->id,
                'quantity' => 1,
                'price' => 5000000,
                'unit_price' => 5000000,
                'sub_total' => 5000000,
                'product_name' => $parent->product_name,
                'product_code' => $parent->product_code,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
                'serial_number_ids' => [$sn->id],
            ]);
            
            \Modules\Sale\Entities\SaleBundleItem::create([
                'sale_id' => $sale->id,
                'sale_detail_id' => $sd->id,
                'bundle_id' => $bundle->id,
                'bundle_item_id' => $bi1->id,
                'product_id' => $comp1->id,
                'name' => $comp1->product_name,
                'quantity' => 1,
                'price' => 0,
                'sub_total' => 0,
            ]);
            \Modules\Sale\Entities\SaleBundleItem::create([
                'sale_id' => $sale->id,
                'sale_detail_id' => $sd->id,
                'bundle_id' => $bundle->id,
                'bundle_item_id' => $bi2->id,
                'product_id' => $comp2->id,
                'name' => $comp2->product_name,
                'quantity' => 2,
                'price' => 0,
                'sub_total' => 0,
            ]);

            $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
            $dd = DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $parent->id,
                'dispatched_quantity' => 1,
                'location_id' => $this->location->id
            ]);
            $sn->update(['dispatch_detail_id' => $dd->id, 'status' => 'SOLD']);
            
            return [$sale, $sd];
        };

        [$sale1, $sd1] = $createSale($sn1);
        [$sale2, $sd2] = $createSale($sn2);

        // Task 2.7: Manually create Transaction Line to act as bundle source of truth
        $ptl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 2,
            'unit_price' => 5000000,
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_items' => [
                    ['product_id' => $comp1->id, 'product_name' => $comp1->product_name, 'quantity' => 1],
                    ['product_id' => $comp2->id, 'product_name' => $comp2->product_name, 'quantity' => 2],
                ]
            ]
        ]);

        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => 'SN-001'
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => 'SN-002'
        ]);

        // Task 2.8: Create a zero-quantity split component SD to verify it is hidden
        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $comp1->id,
            'quantity' => 0, // Split component
            'unit_price' => 0,
            'price' => 0,
            'sub_total' => 0,
            'product_name' => $comp1->product_name . ' (Split)',
            'product_code' => $comp1->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 4. Verify Snapshot Logic (Task 7.12)
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->assertCount(2, $snapshot['lines'], "Should only see 2 serial lines, hidden zero-qty component.");

        // Task 7.12: Explicit check — the zero-qty split component must not appear as a top-level line
        $snapshotProductNames = array_column($snapshot['lines'], 'product_name');
        $this->assertNotContains($comp1->product_name, $snapshotProductNames,
            "Zero-qty split bundle component must not appear as a top-level snapshot line.");
        foreach ($snapshot['lines'] as $snapshotLine) {
            $this->assertTrue($snapshotLine['is_tracked'], "All visible snapshot lines must be serial-tracked parent rows.");
            $this->assertGreaterThan(0, $snapshotLine['original_quantity'], "No zero-quantity rows may appear in snapshot lines.");
        }
        
        // 5. Verify Livewire Form (Task 7.13, 7.14, 7.16)
        $lineKey1 = $ptl->id . "-" . $sn1->id;
        Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $transaction->code)
            ->call('lookup')
            ->assertHasNoErrors()
            ->assertSee('SAMSUNG BUNDLE')
            ->assertSee('SN-001')
            ->assertSee('SN-002')
            ->assertDontSee('Comp 1 (Split)') // Task 7.14
            ->set("lineSelections.{$lineKey1}.resolution", "product_replacement")
            ->assertSee('Komponen Trace') // Task 7.13
            ->assertSee('COMP 1')
            ->assertSee('(Stok: 30)'); // Task 7.16

        // 6. Verify Draft Save (Task 7.15, 7.17)
        $initialComp1Stock = \Modules\Product\Entities\ProductStock::where('product_id', $comp1->id)->where('location_id', $this->location->id)->value('quantity');
        
        Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $transaction->code)
            ->call('lookup')
            ->set("lineSelections.{$lineKey1}.resolution", "cash_return")
            ->call('submit')
            ->assertHasNoErrors();
            
        $finalComp1Stock = \Modules\Product\Entities\ProductStock::where('product_id', $comp1->id)->where('location_id', $this->location->id)->value('quantity');
        $this->assertEquals($initialComp1Stock, $finalComp1Stock, "Stock should not be mutated during draft save (Task 7.17).");

        $this->assertDatabaseHas('pos_returns', [
            'pos_transaction_id' => $transaction->id,
            'total_amount' => 5000000, // Task 7.15: Full original POS unit price
        ]);

        // Task 7.15: Verify expected_cash_amount on the return line itself
        $posReturn = \Modules\Pos\Entities\PosReturn::where('pos_transaction_id', $transaction->id)->first();
        $this->assertNotNull($posReturn, "POS Return should be created after submit.");
        $this->assertDatabaseHas('pos_return_lines', [
            'pos_return_id' => $posReturn->id,
            'returned_serial_id' => $sn1->id,
            'expected_cash_amount' => 5000000,
        ]);
    }

    /**
     * Task 7.13: Bundled and non-bundled serials of the same SKU render in separate PTL groups.
     *
     * @test
     */
    public function it_separates_bundled_and_non_bundled_serials_of_same_sku_into_distinct_ptl_groups()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $comp1 = $this->createStockedProduct($this->setting, $this->location, ['product_name' => 'Comp A', 'product_code' => 'CA', 'stock_qty' => 10]);

        $samsung = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Samsung S24',
            'product_code' => 'S24',
            'serial_number_required' => true,
            'sale_price' => 5000000,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $samsung->id,
            'setting_id' => $this->setting->id,
            'name' => 'S24 Bundle',
        ]);
        $bi1 = \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp1->id,
            'quantity' => 1,
        ]);

        // Serial numbers: SN-B1, SN-B2 = bundled; SN-NB = non-bundled
        $snB1 = $this->createSerialNumber($samsung, $this->location, 'SN-B1');
        $snB2 = $this->createSerialNumber($samsung, $this->location, 'SN-B2');
        $snNB = $this->createSerialNumber($samsung, $this->location, 'SN-NB');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SPLIT-TEST',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-SPLIT',
            'grand_total' => 15000000,
            'idempotency_key' => 'IDEM-SPLIT',
            'payload_hash' => 'HASH-SPLIT',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        // Helper: create a sale for one serial
        $createSaleForSerial = function ($sn, bool $isBundle) use ($samsung, $checkout, $comp1, $bundle, $bi1) {
            $sale = Sale::create([
                'setting_id' => $this->setting->id,
                'customer_id' => null,
                'customer_name' => 'Walk-in Customer',
                'total_amount' => 5000000,
                'paid_amount' => 5000000,
                'due_amount' => 0,
                'date' => now()->toDateString(),
                'status' => 'DISPATCHED',
                'payment_status' => 'PAID',
                'payment_method' => 'CASH',
                'reference' => 'SO-' . $sn->serial_number,
            ]);
            PosCheckoutSale::create([
                'pos_checkout_id' => $checkout->id,
                'sale_id' => $sale->id,
                'source_setting_id' => $this->setting->id,
                'source_location_id' => $this->location->id,
                'grand_total' => 5000000,
                'subtotal' => 5000000,
                'split_key' => 'SPL-' . $sn->serial_number,
                'tax_bucket' => 'NON_TAX',
            ]);
            $sd = SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $samsung->id,
                'quantity' => 1,
                'price' => 5000000,
                'unit_price' => 5000000,
                'sub_total' => 5000000,
                'product_name' => $samsung->product_name,
                'product_code' => $samsung->product_code,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
                'serial_number_ids' => [$sn->id],
            ]);
            if ($isBundle) {
                \Modules\Sale\Entities\SaleBundleItem::create([
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $sd->id,
                    'bundle_id' => $bundle->id,
                    'bundle_item_id' => $bi1->id,
                    'product_id' => $comp1->id,
                    'name' => $comp1->product_name,
                    'quantity' => 1,
                    'price' => 0,
                    'sub_total' => 0,
                ]);
            }
            $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
            $dd = DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $samsung->id,
                'dispatched_quantity' => 1,
                'location_id' => $this->location->id,
            ]);
            $sn->update(['dispatch_detail_id' => $dd->id, 'status' => 'SOLD']);
            return $sd;
        };

        $createSaleForSerial($snB1, true);
        $createSaleForSerial($snB2, true);
        $createSaleForSerial($snNB, false);

        // Bundle PTL (SN-B1 and SN-B2)
        $bundlePtl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $samsung->id,
            'product_name_snapshot' => $samsung->product_name,
            'product_code_snapshot' => $samsung->product_code,
            'qty' => 2,
            'unit_price' => 5000000,
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_items' => [
                    ['product_id' => $comp1->id, 'product_name' => $comp1->product_name, 'quantity' => 1],
                ],
            ],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create(['pos_transaction_line_id' => $bundlePtl->id, 'serial_number' => 'SN-B1']);
        \Modules\Pos\Entities\PosTransactionLineSerial::create(['pos_transaction_line_id' => $bundlePtl->id, 'serial_number' => 'SN-B2']);

        // Non-bundle PTL (SN-NB)
        $nonBundlePtl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $samsung->id,
            'product_name_snapshot' => $samsung->product_name,
            'product_code_snapshot' => $samsung->product_code,
            'qty' => 1,
            'unit_price' => 5000000,
            'line_no' => 2,
            'line_meta' => [],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create(['pos_transaction_line_id' => $nonBundlePtl->id, 'serial_number' => 'SN-NB']);

        // Task 7.13: Snapshot must have 3 lines; bundled serials on bundle PTL, non-bundled on separate PTL
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->assertCount(3, $snapshot['lines'], "All three serials must appear in snapshot.");

        $bundledLines = array_values(array_filter($snapshot['lines'],
            fn($l) => $l['pos_transaction_line_id'] === $bundlePtl->id));
        $nonBundledLines = array_values(array_filter($snapshot['lines'],
            fn($l) => $l['pos_transaction_line_id'] === $nonBundlePtl->id));

        $this->assertCount(2, $bundledLines, "Two bundled Samsung serials should appear under the bundle PTL.");
        $this->assertCount(1, $nonBundledLines, "One non-bundled Samsung serial should appear under the non-bundle PTL.");

        foreach ($bundledLines as $line) {
            $this->assertTrue($line['is_bundle'], "Bundled Samsung serials must have is_bundle=true (task 2.7).");
            $this->assertNotEmpty($line['bundle_items'], "Bundled Samsung serials must carry component trace.");
        }
        foreach ($nonBundledLines as $line) {
            $this->assertFalse($line['is_bundle'], "Non-bundled Samsung serial must have is_bundle=false.");
        }
    }

    /**
     * Task 7.15: Bundled serial cash_return uses the full PTL unit price, not the split-allocation residual.
     *
     * @test
     */
    public function it_uses_ptl_unit_price_for_bundled_serial_cash_return_not_residual_allocation()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $comp = $this->createStockedProduct($this->setting, $this->location, ['product_name' => 'Component X', 'product_code' => 'CX', 'stock_qty' => 20]);

        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Bundle Parent',
            'product_code' => 'BP',
            'serial_number_required' => true,
            'sale_price' => 6000000,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Full Bundle',
        ]);
        $bi = \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 1,
        ]);

        $sn = $this->createSerialNumber($parent, $this->location, 'SN-PRICE-TEST');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PRICE-TEST',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-PRICE',
            'grand_total' => 6000000,
            'idempotency_key' => 'IDEM-PRICE',
            'payload_hash' => 'HASH-PRICE',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 6000000,
            'paid_amount' => 6000000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-PRICE',
        ]);

        // Split-posting: parent SD has unit_price=0 (residual allocation stored separately),
        // checkout_sale subtotal = 5920000 (parent residual), but PTL unit_price = 6000000 (full price).
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 5920000,
            'subtotal' => 5920000,  // Residual allocation (not full PTL price)
            'split_key' => 'SPL-PRICE',
            'tax_bucket' => 'NON_TAX',
        ]);

        // Parent SD with unit_price=0 and SaleBundleItems (triggers $isBundle=true via SD path)
        $sd = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,   // split posting: price on SD = 0
            'sub_total' => 0,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$sn->id],
        ]);
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $sd->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        $sn->update(['dispatch_detail_id' => $dd->id, 'status' => 'SOLD']);

        // PTL with the full original POS unit price = 6000000 (what the customer was charged)
        $ptl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 1,
            'unit_price' => 6000000,  // Full customer-visible price
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_items' => [
                    ['product_id' => $comp->id, 'product_name' => $comp->product_name, 'quantity' => 1],
                ],
            ],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => 'SN-PRICE-TEST',
        ]);

        // Task 2.7: Snapshot must use PTL unit price (6000000) not checkout_sale subtotal (5920000)
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->assertCount(1, $snapshot['lines']);
        $this->assertEquals(6000000, $snapshot['lines'][0]['unit_price'],
            "Snapshot must use full PTL unit price for bundled serial (Task 2.7).");

        // Task 7.15: Draft save must persist expected_cash_amount = 6000000 (PTL price)
        $lineKey = $ptl->id . '-' . $sn->id;
        Livewire::test(PosReturnCreateForm::class)
            ->set('identifier', $transaction->code)
            ->call('lookup')
            ->assertHasNoErrors()
            ->set("lineSelections.{$lineKey}.resolution", 'cash_return')
            ->call('submit')
            ->assertHasNoErrors();

        $posReturn = \Modules\Pos\Entities\PosReturn::where('pos_transaction_id', $transaction->id)->first();
        $this->assertNotNull($posReturn);

        // Task 7.15: expected_cash_amount must be the full PTL price, not the residual allocation
        $this->assertDatabaseHas('pos_return_lines', [
            'pos_return_id' => $posReturn->id,
            'returned_serial_id' => $sn->id,
            'expected_cash_amount' => 6000000,
        ]);
        $this->assertEquals(6000000, $posReturn->total_amount,
            "POS Return total_amount must reflect full PTL unit price for bundled serial cash_return.");

        // Task 7.17: Component stock must not be mutated
        $compStock = \Modules\Product\Entities\ProductStock::where('product_id', $comp->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $this->assertEquals(20, $compStock, "Component stock must not be mutated during draft save.");
    }
}
