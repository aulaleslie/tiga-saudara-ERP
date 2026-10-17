<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Exceptions\PosReprintProjectionException;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\SaleMonetaryEditService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;

class POSReceiptNotesAndPriceAdjustmentTest extends PosLineageSchemaAndCheckoutTest
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('pos.receipts.reprint', 'web');
        Permission::findOrCreate('posPayments.global.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
        Permission::findOrCreate('pos.transactions.view', 'web');
        Permission::findOrCreate('sales.edit', 'web');
        Permission::findOrCreate('sales.dispatched.monetary.edit', 'web');
        Permission::findOrCreate('salePayments.global.access', 'web');
    }

    protected function createSetting(string $name): Setting
    {
        $setting = parent::createSetting($name);
        $setting->update([
            'pos_receipt_show_note' => true,
            'pos_receipt_show_return_history' => true,
            'pos_receipt_show_price_adjustments' => true,
        ]);

        return $setting->fresh();
    }

    /**
     * Task 1.1 & 1.2: Multiline note with HTML is escaped, newlines preserved,
     * ordered after payments and before return history/tail space.
     */
    public function test_multiline_html_escaped_transaction_note_placement_and_ordering(): void
    {
        $setting = $this->createSetting('NOTE STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC N', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $prod = $this->createStockedProduct($setting, $loc, 'NOTE-PROD', 50000, 10);

        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $rawNote = "Line 1: Special Request\n<script>alert('xss')</script> & <b>bold</b>\nLine 3: Delivery at 5pm";

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-NOTE-001-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 50000,
            ],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        // Assign note directly on the resolved POS transaction
        $transaction = $checkout->transaction;
        $this->assertNotNull($transaction);
        $transaction->update(['note' => $rawNote]);

        $service = app(PosReceiptReprintProjectionService::class);
        $receiptData = $service->getCompletedReceiptData($checkout->fresh());

        $this->assertEquals($rawNote, $receiptData['note']);

        $html = view('pos::receipt', compact('receiptData'))->render();

        // 1. Heading Catatan is rendered
        $this->assertStringContainsString('Catatan:', $html);

        // 2. HTML characters are escaped, not rendered as raw tags
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringContainsString("&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;", $html);
        $this->assertStringContainsString("&amp; &lt;b&gt;bold&lt;/b&gt;", $html);

        // 3. Multiline text preserved
        $this->assertStringContainsString('Line 1: Special Request', $html);
        $this->assertStringContainsString('Line 3: Delivery at 5pm', $html);
        $this->assertStringContainsString('white-space: pre-wrap;', $html);

        // 4. Ordering: Totals table -> Catatan -> tail space
        $posTotal = strpos($html, 'Total');
        $posCatatan = strpos($html, 'Catatan:');
        $posTail = strpos($html, 'class="receipt-tail-space"');

        $this->assertNotFalse($posTotal);
        $this->assertNotFalse($posCatatan);
        $this->assertNotFalse($posTail);
        $this->assertTrue($posTotal < $posCatatan, 'Total must appear before Catatan');
        $this->assertTrue($posCatatan < $posTail, 'Catatan must appear before tail space');
    }

    /**
     * Task 1.1: Empty and whitespace notes omit Catatan section, and Sales/checkout notes
     * are never substituted when POS transaction note is empty.
     */
    public function test_empty_whitespace_and_missing_transaction_never_substitutes_sales_notes(): void
    {
        $setting = $this->createSetting('EMPTY NOTE STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC EN', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $prod = $this->createStockedProduct($setting, $loc, 'EN-PROD', 50000, 10);

        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-ENOTE-001-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 50000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        // Case 1: Transaction note is whitespace-only, but checkout and sale have notes
        $checkout->transaction->update(['note' => "   \n\t  "]);
        $checkout->update(['note' => 'CHECKOUT NOTE SHOULD NOT SHOW']);
        $checkout->sale->update(['note' => 'SALE NOTE SHOULD NOT SHOW']);

        $service = app(PosReceiptReprintProjectionService::class);
        $receiptData = $service->getCompletedReceiptData($checkout->fresh());

        $this->assertNull($receiptData['note']);
        $html = view('pos::receipt', compact('receiptData'))->render();
        $this->assertStringNotContainsString('Catatan', $html);
        $this->assertStringNotContainsString('CHECKOUT NOTE', $html);
        $this->assertStringNotContainsString('SALE NOTE', $html);

        // Case 2: Missing transaction entirely (legacy fallback)
        $receiptService = app(PosReceiptService::class);
        $mockCheckout = clone $checkout;
        $mockCheckout->setRelation('transaction', null);
        $mockCheckout->setRelation('transactions', collect());
        $mockCheckout->pos_transaction_id = null;

        $legacyData = $receiptService->getReceiptData($mockCheckout);
        $this->assertNull($legacyData['note']);
        $htmlLegacy = view('pos::receipt', ['receiptData' => $legacyData])->render();
        $this->assertStringNotContainsString('Catatan', $htmlLegacy);
    }

    /**
     * Task 1.1: Split checkout resolves canonical transaction note, not sibling or sales notes.
     */
    public function test_split_checkout_uses_canonical_transaction_note(): void
    {
        config(['pos.checkout.split_posting.enabled' => true]);

        $terminalSetting = $this->createSetting('SPLIT NOTE TERM');
        $source1Setting = $this->createSetting('SPLIT NOTE S1');
        $source2Setting = $this->createSetting('SPLIT NOTE S2');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $locTerminal = Location::create(['name' => 'T LOC', 'setting_id' => $terminalSetting->id]);
        $locSource1 = Location::create(['name' => 'S1 LOC', 'setting_id' => $source1Setting->id]);
        $locSource2 = Location::create(['name' => 'S2 LOC', 'setting_id' => $source2Setting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource1, $locSource2]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 10, $tax);
        $compA = $this->createStockedProduct($source1Setting, $locSource1, 'COMP-A', 0, 10, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Split Note Bundle',
            'bundle_sale_price' => 100000,
            'price' => 100000,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compA->id,
            'quantity' => 1,
            'informational_item_price' => 100000,
        ]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, null, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $res = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-SPLIT-NOTE-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 100000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        $transactions = PosTransaction::where('completed_checkout_id', $checkout->id)->get();
        $this->assertGreaterThanOrEqual(1, $transactions->count());

        $canonical = $checkout->transaction ?? $transactions->first();
        $canonical->update(['note' => 'CANONICAL TRANSACTION NOTE']);

        foreach ($transactions as $txn) {
            if ($txn->id !== $canonical->id) {
                $txn->update(['note' => 'SIBLING TRANSACTION NOTE']);
            }
        }

        $service = app(PosReceiptReprintProjectionService::class);
        $receiptData = $service->getCompletedReceiptData($checkout->fresh());

        $this->assertEquals('CANONICAL TRANSACTION NOTE', $receiptData['note']);
        $html = view('pos::receipt', compact('receiptData'))->render();
        $this->assertStringContainsString('CANONICAL TRANSACTION NOTE', $html);
        $this->assertStringNotContainsString('SIBLING TRANSACTION NOTE', $html);
    }

    /**
     * Task 1.1, 2.4: Historical receipts and draft/loaded previews display the transaction
     * note while suppressing completed price-adjustment notices.
     */
    public function test_historical_and_preview_receipts_display_note_and_suppress_adjustments(): void
    {
        $setting = $this->createSetting('HIST PREVIEW STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC HP', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $prod = $this->createStockedProduct($setting, $loc, 'HP-PROD', 100000, 10);

        // 1. Test draft transaction receipt
        $session = PosSession::where('setting_id', $setting->id)->first();
        $draftTxn = PosTransaction::create([
            'setting_id' => $setting->id,
            'source_pos_session_id' => $session->id,
            'code' => 'TRX-DRAFT-001',
            'status' => PosTransaction::STATUS_DRAFT,
            'owner_user_id' => $cashier->id,
            'created_by' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'customer_id' => $customer->id,
            'note' => 'DRAFT PREVIEW NOTE',
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $draftTxn->id,
            'line_no' => 1,
            'product_id' => $prod->id,
            'product_name_snapshot' => $prod->product_name,
            'qty' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
        ]);

        $receiptService = app(PosReceiptService::class);
        $draftData = $receiptService->getTransactionReceiptData($draftTxn);
        $this->assertTrue($draftData['is_draft']);
        $this->assertEquals('DRAFT PREVIEW NOTE', $draftData['note']);

        $draftHtml = view('pos::receipt', ['receiptData' => $draftData])->render();
        $this->assertStringContainsString('Catatan:', $draftHtml);
        $this->assertStringContainsString('DRAFT PREVIEW NOTE', $draftHtml);
        $this->assertStringNotContainsString('Harga satuan:', $draftHtml);
        $this->assertStringNotContainsString('Harga penjualan diperbarui', $draftHtml);

        // 2. Test completed checkout with Sales price edit viewed as historical
        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-HP-002-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 100000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        $checkout->transaction->update(['note' => 'COMPLETED NOTE FOR HISTORICAL']);

        // Update Sale detail price to 90,000
        $checkout->sale->saleDetails->first()->update(['unit_price' => 90000, 'sub_total' => 90000]);
        $checkout->sale->update(['total_amount' => 90000]);

        // Historical view
        $historicalData = $receiptService->getReceiptData($checkout);
        $historicalData['is_historical_checkout'] = true;

        $this->assertEquals('COMPLETED NOTE FOR HISTORICAL', $historicalData['note']);
        $this->assertEquals(100000.0, (float) $historicalData['grand_total']); // Snapshot checkout total

        $histHtml = view('pos::receipt', ['receiptData' => $historicalData])->render();
        $this->assertStringContainsString('STRUK HISTORIS CHECKOUT', $histHtml);
        $this->assertStringContainsString('Catatan:', $histHtml);
        $this->assertStringContainsString('COMPLETED NOTE FOR HISTORICAL', $histHtml);
        $this->assertStringNotContainsString('Harga satuan:', $histHtml);
        $this->assertStringNotContainsString('Harga penjualan diperbarui', $histHtml);
    }

    /**
     * Task 2.1: Standard item price adjustments: single edited line, split sales with
     * distinct prices, restored original price, and repeated products.
     */
    public function test_standard_item_price_adjustments_single_split_and_restored(): void
    {
        $setting = $this->createSetting('STD PRICE STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC STD', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod1 = $this->createStockedProduct($setting, $loc, 'STD-1', 100000, 10);
        $prod2 = $this->createStockedProduct($setting, $loc, 'STD-2', 50000, 10);

        // Checkout: 1 unit of prod1 (100k), 1 unit of prod2 (50k)
        $this->addCartLine($cashier, $setting, $prod1->id, 1);
        $this->addCartLine($cashier, $setting, $prod2->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-STD-PRICE-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 150000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        // Case A: prod1 price adjusted from 100k to 90k
        $saleDetail1 = $checkout->sale->saleDetails->firstWhere('product_id', $prod1->id);
        $saleDetail1->update(['unit_price' => 90000, 'sub_total' => 90000]);
        $checkout->sale->update(['total_amount' => 140000]);

        $service = app(PosReceiptReprintProjectionService::class);
        $data = $service->getCompletedReceiptData($checkout->fresh());

        $line1 = collect($data['lines'])->firstWhere('pos_transaction_line_id', (int) $saleDetail1->pos_transaction_line_id);
        $this->assertNotEmpty($line1['price_adjustments']);
        $this->assertContains('Harga satuan: 100.000 → 90.000', $line1['price_adjustments']);

        // prod2 had NO price change
        $line2 = collect($data['lines'])->firstWhere('product_name', $prod2->product_name);
        $this->assertEmpty($line2['price_adjustments'] ?? []);

        $html = view('pos::receipt', ['receiptData' => $data])->render();
        $this->assertStringContainsString('Harga satuan: 100.000 → 90.000', $html);

        // Case B: Restored original price (adjusted back to 100k)
        $saleDetail1->update(['unit_price' => 100000, 'sub_total' => 100000]);
        $checkout->sale->update(['total_amount' => 150000]);

        $dataRestored = $service->getCompletedReceiptData($checkout->fresh());
        $line1Restored = collect($dataRestored['lines'])->firstWhere('pos_transaction_line_id', (int) $saleDetail1->pos_transaction_line_id);
        $this->assertEmpty($line1Restored['price_adjustments'] ?? []);

        $htmlRestored = view('pos::receipt', ['receiptData' => $dataRestored])->render();
        $this->assertStringNotContainsString('Harga satuan:', $htmlRestored);
    }

    /**
     * Task 2.1: Split sales with distinct current prices show distinct comparisons and quantities.
     */
    public function test_split_sales_with_distinct_current_prices(): void
    {
        $setting = $this->createSetting('SPLIT PRICING STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC SP', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod = $this->createStockedProduct($setting, $loc, 'SPLIT-PROD', 100000, 10);

        // Buy 3 units at 100k
        $this->addCartLine($cashier, $setting, $prod->id, 3);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-SPLIT-3-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 300000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        $origDetail = $checkout->sale->saleDetails->first();
        $txnLineId = $origDetail->pos_transaction_line_id;

        // Split detail into 2 units @ 90,000 and 1 unit @ 95,000 on the Sale
        $origDetail->update([
            'quantity' => 2,
            'unit_price' => 90000,
            'price' => 90000,
            'sub_total' => 180000,
        ]);

        SaleDetails::create([
            'sale_id' => $checkout->sale_id,
            'product_id' => $prod->id,
            'product_name' => $prod->product_name,
            'product_code' => $prod->product_code,
            'quantity' => 1,
            'unit_price' => 95000,
            'price' => 95000,
            'sub_total' => 95000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'pos_transaction_line_id' => $txnLineId,
        ]);

        $checkout->sale->update(['total_amount' => 275000]);

        $service = app(PosReceiptReprintProjectionService::class);
        $data = $service->getCompletedReceiptData($checkout->fresh());

        $line = $data['lines'][0];
        $this->assertNotEmpty($line['price_adjustments']);
        $this->assertContains('Harga satuan: 100.000 → 90.000 (2)', $line['price_adjustments']);
        $this->assertContains('Harga satuan: 100.000 → 95.000 (1)', $line['price_adjustments']);

        $html = view('pos::receipt', ['receiptData' => $data])->render();
        $this->assertStringContainsString('Harga satuan: 100.000 → 90.000 (2)', $html);
        $this->assertStringContainsString('Harga satuan: 100.000 → 95.000 (1)', $html);
    }

    /**
     * Task 2.2: Packed item shows generic 'Harga penjualan diperbarui' only on proven
     * blended unit price change, and omits notice on return or non-price adjustments.
     */
    public function test_packed_item_generic_notice_and_non_price_suppression(): void
    {
        $setting = $this->createSetting('PACKED STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC PACKED', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $unitBox = Unit::query()->create(['name' => 'Dus', 'short_name' => 'Dus']);
        $prod = $this->createStockedProduct($setting, $loc, 'PACKED-PROD', 12000, 100);

        ProductUnitConversion::create([
            'product_id' => $prod->id,
            'base_unit_id' => $prod->unit_id,
            'unit_id' => $unitBox->id,
            'conversion_factor' => 10,
            'selling_price' => 100000,
        ]);

        // Mock a packed transaction line
        $this->addCartLine($cashier, $setting, $prod->id, 12);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-PACKED-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 124000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        $checkout->update(['grand_total' => 124000, 'paid_total' => 124000]);
        $checkout->sale->update(['total_amount' => 124000, 'paid_amount' => 124000, 'due_amount' => 0]);
        $checkout->sale->salePayments->first()?->update(['amount' => 124000]);

        // Manually tag line_meta as PACKED
        $txnLine = $checkout->transaction->lines->first();
        $txnLine->update([
            'unit_price' => 10333.33,
            'line_meta' => [
                'price_source' => 'PACKED',
                'breakdown' => [
                    'box_count' => 1,
                    'loose_count' => 2,
                    'box_price_applied' => 10000000,
                    'loose_price_applied' => 2400000,
                    'conversion_unit_label' => 'Dus',
                    'base_unit_label' => 'Pcs',
                ],
            ],
        ]);
        $detail = $checkout->sale->saleDetails->first();
        $detail->update(['unit_price' => 10333.33, 'sub_total' => 124000]);

        $service = app(PosReceiptReprintProjectionService::class);

        // Case 1: Unchanged blended unit price -> NO notice
        $dataUnchanged = $service->getCompletedReceiptData($checkout->fresh());
        $this->assertEmpty($dataUnchanged['lines'][0]['price_adjustments'] ?? []);

        // Case 2: Blended unit price adjusted to 9500 on Sale
        $detail->update(['unit_price' => 9500, 'sub_total' => 114000]);
        $checkout->sale->update(['total_amount' => 114000]);

        $dataChanged = $service->getCompletedReceiptData($checkout->fresh());
        $lineAdjustments = $dataChanged['lines'][0]['price_adjustments'] ?? [];
        $this->assertContains('Harga penjualan diperbarui', $lineAdjustments);
        // Verify NO fabricated numeric box/loose comparison
        $this->assertStringNotContainsString('Harga satuan:', implode(' ', $lineAdjustments));

        $html = view('pos::receipt', ['receiptData' => $dataChanged])->render();
        $this->assertStringContainsString('Harga penjualan diperbarui', $html);
    }

    /**
     * Task 2.3: Fully returned standard item shows checkout price to return price
     * labeled as 'Harga satuan saat retur' in return history and stays absent from main items.
     */
    public function test_fully_returned_standard_item_shows_return_time_price(): void
    {
        $setting = $this->createSetting('FULL RET STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC FR', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod = $this->createStockedProduct($setting, $loc, 'FR-PROD', 100000, 10);

        // Checkout 1 unit @ 100k
        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-FR-001-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 100000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        $txnLine = $checkout->transaction->lines->first();

        // Fully return the item with saved return price of 90,000
        $sale = $checkout->sale;
        $saleDetail = $sale->saleDetails->first();
        $checkoutSale = $checkout->checkoutSales->first();

        $posReturn = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $checkout->transaction->id,
            'transaction_code' => $checkout->transaction->code,
            'reference' => 'RET-FR-001',
            'receipt_number' => 'RET-REC-001',
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 90000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'settled_at' => now(),
        ]);
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale?->id ?? 1,
            'pos_transaction_line_id' => null, // P2: Normal non-serialized snapshot path leaves this null!
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => 'stock_managed',
            'product_id' => $prod->id,
            'product_name' => $prod->product_name,
            'product_code' => $prod->product_code,
            'quantity' => 1,
            'unit_price' => 90000,
            'line_total' => 90000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // SaleDetail quantity is reduced to 0
        $checkout->sale->saleDetails->first()->update(['quantity' => 0, 'sub_total' => 0]);
        $checkout->sale->update(['total_amount' => 0, 'paid_amount' => 10000, 'due_amount' => 0]);

        $service = app(PosReceiptReprintProjectionService::class);
        $data = $service->getCompletedReceiptData($checkout->fresh());

        // 1. Fully returned item stays absent from main lines
        $this->assertEmpty($data['lines']);

        // 2. Return history item displays comparison
        $this->assertNotEmpty($data['return_history']);
        $retItem = $data['return_history'][0]['items'][0];
        $this->assertEquals('Harga satuan saat retur: 100.000 → 90.000', $retItem['price_comparison']);

        // 3. A later Sales edit does NOT change the return endpoint
        $checkout->sale->saleDetails->first()->update(['unit_price' => 85000]);
        $dataAfterSaleEdit = $service->getCompletedReceiptData($checkout->fresh());
        $this->assertEquals('Harga satuan saat retur: 100.000 → 90.000', $dataAfterSaleEdit['return_history'][0]['items'][0]['price_comparison']);

        $html = view('pos::receipt', ['receiptData' => $data])->render();
        $this->assertStringContainsString('RIWAYAT RETUR', $html);
        $this->assertStringContainsString('Harga satuan saat retur: 100.000 → 90.000', $html);
        $this->assertStringContainsString('(Semua barang telah diretur)', $html);
    }

    /**
     * Task 3.1 & 3.2: Authorized Sales monetary adjustment on a debt POS transaction,
     * verifying total 280,000, paid 100,000, debt 180,000; followed by a later 50,000 payment
     * yielding total 280,000, paid 150,000, debt 130,000 with reconciled method rows.
     */
    public function test_authorized_monetary_adjustment_on_debt_transaction_and_later_payment(): void
    {
        $setting = $this->createSetting('DEBT ADJ STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.checkout.debt', 'pos.receipts.reprint',
            'sales.edit', 'sales.dispatched.monetary.edit', 'salePayments.global.access',
        ]);
        $loc = Location::create(['name' => 'LOC DA', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);
        $prod = $this->createStockedProduct($setting, $loc, 'DEBT-PROD', 150000, 10);

        // Checkout: 2 units @ 150k = 300,000 total. Pay 100,000 DP, 200,000 debt.
        $this->addCartLine($cashier, $setting, $prod->id, 2);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-DEBT-ADJ-' . uniqid(),
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        $this->assertEquals(300000.0, (float) $checkout->grand_total);
        $this->assertEquals(100000.0, (float) $checkout->paid_total);
        $this->assertEquals(200000.0, (float) $checkout->sale->due_amount);

        // Step 1 (Task 3.1): Authorized Sales monetary adjustment via SaleMonetaryEditService
        // reduces total to 280,000 (unit price becomes 140,000 for each of the 2 units)
        $detail = $checkout->sale->saleDetails->first();
        $this->actingAs($cashier);
        session(['setting_id' => $setting->id]);

        $cartItems = collect([(object) [
            'id' => $detail->id,
            'name' => $detail->product_name,
            'qty' => 2,
            'price' => 140000,
            'options' => collect([
                'product_id' => $detail->product_id,
                'unit_price' => 140000,
                'sub_total' => 280000,
                'sub_total_before_tax' => 280000,
                'product_tax_amount' => 0.0,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'code' => $detail->product_code,
            ]),
        ]]);

        app(SaleMonetaryEditService::class)->apply($checkout->sale, $cartItems, ['is_pkp' => false]);

        $service = app(PosReceiptReprintProjectionService::class);
        $reprintAfterAdj = $service->getCompletedReceiptData($checkout->fresh());

        // Assert Task 3.1 requirements:
        $this->assertEquals(280000.0, $reprintAfterAdj['grand_total']);
        $this->assertEquals(100000.0, $reprintAfterAdj['amount_paid']);
        $this->assertEquals(180000.0, $reprintAfterAdj['outstanding_debt']);
        $this->assertContains('Harga satuan: 150.000 → 140.000', $reprintAfterAdj['lines'][0]['price_adjustments']);

        // Assert checkout snapshot and payment records remain untouched
        $freshCheckout = $checkout->fresh();
        $this->assertEquals(300000.0, (float) $freshCheckout->grand_total);
        $this->assertEquals(100000.0, (float) $freshCheckout->paid_total);
        $this->assertEquals(180000.0, (float) $freshCheckout->sale->due_amount);

        // Step 2 (Task 3.2): A later 50,000 payment is posted to the adjusted transaction
        SalePayment::create([
            'sale_id' => $checkout->sale_id,
            'amount' => 50000,
            'date' => now()->toDateString(),
            'reference' => 'LATER-PAY-' . uniqid(),
            'payment_method' => 'Cash',
            'payment_method_id' => $methods['cash']->id,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $checkout->sale->update([
            'paid_amount' => 150000,
            'due_amount' => 130000, // 280,000 - 150,000 paid
            'payment_status' => 'Partial',
        ]);

        $reprintAfterLaterPayment = $service->getCompletedReceiptData($checkout->fresh());

        // Assert Task 3.2 requirements:
        $this->assertEquals(280000.0, $reprintAfterLaterPayment['grand_total']);
        $this->assertEquals(150000.0, $reprintAfterLaterPayment['amount_paid']);
        $this->assertEquals(130000.0, $reprintAfterLaterPayment['outstanding_debt']);

        // Reconciled method rows sum to 150,000
        $paymentBreakdown = $reprintAfterLaterPayment['payment_breakdown'];
        $this->assertNotEmpty($paymentBreakdown);
        $sumBreakdown = array_sum(array_column($paymentBreakdown, 'amount'));
        $this->assertEquals(150000.0, $sumBreakdown);

        $html = view('pos::receipt', ['receiptData' => $reprintAfterLaterPayment])->render();
        $this->assertStringContainsString('280.000', $html);
        $this->assertStringContainsString('150.000', $html);
        $this->assertStringContainsString('130.000', $html);
        $this->assertStringContainsString('Harga satuan: 150.000 → 140.000', $html);

        // Step 3 (Finding 1): Prices return to original total (300,000)
        // Verify payment amounts do NOT become stale, reporting paid 150,000 and debt 150,000.
        $cartItemsRestore = collect([(object) [
            'id' => $detail->id,
            'name' => $detail->product_name,
            'qty' => 2,
            'price' => 150000,
            'options' => collect([
                'product_id' => $detail->product_id,
                'unit_price' => 150000,
                'sub_total' => 300000,
                'sub_total_before_tax' => 300000,
                'product_tax_amount' => 0.0,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'code' => $detail->product_code,
            ]),
        ]]);
        app(SaleMonetaryEditService::class)->apply($checkout->sale->fresh(), $cartItemsRestore, ['is_pkp' => false]);

        $reprintAfterRestore = $service->getCompletedReceiptData($checkout->fresh());

        // Total 300,000, paid 150,000, debt 150,000 — perfectly reconciled!
        $this->assertEquals(300000.0, $reprintAfterRestore['grand_total']);
        $this->assertEquals(150000.0, $reprintAfterRestore['amount_paid']);
        $this->assertEquals(150000.0, $reprintAfterRestore['outstanding_debt']);

        $breakdownRestore = $reprintAfterRestore['payment_breakdown'];
        $this->assertNotEmpty($breakdownRestore);
        $this->assertEquals(150000.0, array_sum(array_column($breakdownRestore, 'amount')));
        $this->assertEmpty($reprintAfterRestore['lines'][0]['price_adjustments'] ?? []);

        $htmlRestore = view('pos::receipt', ['receiptData' => $reprintAfterRestore])->render();
        $this->assertStringContainsString('300.000', $htmlRestore);
        $this->assertStringContainsString('150.000', $htmlRestore);
        $this->assertStringNotContainsString('Harga satuan:', $htmlRestore);
    }

    /**
     * Task 4.1: Endpoint parity across ordinary checkout receipt view & reprint,
     * transaction receipt view & reprint, and global payment receipt reprint.
     */
    public function test_print_and_reprint_endpoint_parity_and_audit_logging(): void
    {
        $setting = $this->createSetting('PARITY STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
            'pos.receipts.reprint', 'posPayments.global.access', 'pos.transactions.view',
        ]);
        $loc = Location::create(['name' => 'LOC P', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod = $this->createStockedProduct($setting, $loc, 'P-PROD', 100000, 10);

        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-PARITY-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 100000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        $transaction = $checkout->transaction;
        $transaction->update(['note' => 'PARITY TEST NOTE']);

        // Update Sale detail price to 85,000
        $checkout->sale->saleDetails->first()->update(['unit_price' => 85000, 'sub_total' => 85000]);
        $checkout->sale->update(['total_amount' => 85000]);

        $this->actingAs($cashier);
        session(['setting_id' => $setting->id]);

        // 1. Ordinary checkout receipt view (GET /pos/sell/checkout/{checkout}/receipt)
        $resView = $this->get(route('pos.sell.checkout.receipt', ['checkout' => $checkout]));
        $resView->assertStatus(200);
        $htmlView = $resView->getContent();
        $this->assertStringContainsString('PARITY TEST NOTE', $htmlView);
        $this->assertStringContainsString('Harga satuan: 100.000 → 85.000', $htmlView);
        $this->assertStringContainsString('85.000', $htmlView);

        // 2. Ordinary checkout receipt reprint (GET /pos/sell/checkout/{checkout}/receipt/reprint)
        $resReprint = $this->get(route('pos.sell.checkout.receipt.reprint', ['checkout' => $checkout]));
        $resReprint->assertStatus(200);
        $htmlReprint = $resReprint->getContent();
        $this->assertStringContainsString('PARITY TEST NOTE', $htmlReprint);
        $this->assertStringContainsString('Harga satuan: 100.000 → 85.000', $htmlReprint);

        // 3. Transaction receipt view (GET /pos/transactions/{transaction}/receipt)
        $resTxn = $this->get(route('pos.transactions.receipt', ['transaction' => $transaction]));
        $resTxn->assertStatus(200);
        $htmlTxn = $resTxn->getContent();
        $this->assertStringContainsString('PARITY TEST NOTE', $htmlTxn);
        $this->assertStringContainsString('Harga satuan: 100.000 → 85.000', $htmlTxn);

        // 4. Transaction receipt reprint (POST /pos/transactions/{transaction}/receipt/reprint)
        $resTxnReprint = $this->post(route('pos.transactions.receipt.reprint', ['transaction' => $transaction]));
        $resTxnReprint->assertStatus(200);
        $htmlTxnReprint = $resTxnReprint->getContent();
        $this->assertStringContainsString('PARITY TEST NOTE', $htmlTxnReprint);
        $this->assertStringContainsString('Harga satuan: 100.000 → 85.000', $htmlTxnReprint);

        // 5. Global payment receipt reprint (POST /pos/{transaction_id}/global-payments/receipt/reprint)
        $resGlobal = $this->post(route('pos.global-payments.receipt.reprint', [
            'transaction_id' => $transaction->id,
        ]));
        $resGlobal->assertStatus(200);
        $htmlGlobal = $resGlobal->getContent();
        $this->assertStringContainsString('PARITY TEST NOTE', $htmlGlobal);
        $this->assertStringContainsString('Harga satuan: 100.000 → 85.000', $htmlGlobal);

        // 6. Assert print logs recorded action-specific print types
        $printLogs = PosReceiptPrintLog::query()->where('setting_id', $setting->id)->get();
        $this->assertTrue($printLogs->contains('print_type', PosReceiptPrintLog::TYPE_PRINT));
        $this->assertTrue($printLogs->contains('print_type', PosReceiptPrintLog::TYPE_REPRINT));
    }

    /**
     * Task 4.3: Inspect representative 72 mm output with a long multiline note,
     * split-price annotations, packed notice, and fully returned item.
     */
    public function test_representative_72mm_thermal_output_inspection(): void
    {
        $longMultilineNote = "Catatan Penting Pengiriman Khusus Toko Tiga Saudara:\n"
            . "1. Harap antar ke pintu belakang ruko lantai 2 sebelum pukul 17:00 WIB.\n"
            . "2. Barang pecah belah jangan ditumpuk lebih dari 3 tingkat.\n"
            . "3. Nomor referensi internal: REF/2026/10/SPECIAL-EXPEDITION-TRACK-0099281726481726481927364.\n"
            . "Terima kasih atas kerjasamanya!";

        $receiptData = [
            'business_name' => 'Toko Tiga Saudara',
            'business_address' => 'Jl. Boulevard Raya No. 45, Jakarta Pusat',
            'business_phone' => '08123456789',
            'business_email' => 'toko@tigasaudara.com',
            'receipt_number' => 'REC-202610-001',
            'pos_transaction_code' => 'TXN-202610-001',
            'date' => '17-10-2026 14:30',
            'customer_name' => 'PT Pelanggan Setia Utama',
            'cashier_name' => 'Budi Santoso',
            'terminal_name' => 'Kasir 1',
            'lines' => [
                [
                    'pos_transaction_line_id' => 101,
                    'product_name' => 'Cat Tembok Putih 5Kg',
                    'qty' => 3,
                    'price' => 90000,
                    'discount' => 0,
                    'sub_total' => 275000,
                    'price_adjustments' => [
                        'Harga satuan: 100.000 → 90.000 (2)',
                        'Harga satuan: 100.000 → 95.000 (1)',
                    ],
                ],
                [
                    'pos_transaction_line_id' => 102,
                    'product_name' => 'Semen Instan 40Kg (Dus)',
                    'qty' => 2,
                    'price' => 85000,
                    'discount' => 0,
                    'sub_total' => 170000,
                    'price_adjustments' => [
                        'Harga penjualan diperbarui',
                    ],
                ],
            ],
            'subtotal' => 445000,
            'discount' => 0,
            'tax' => 0,
            'grand_total' => 445000,
            'amount_paid' => 445000,
            'payment_breakdown' => [
                [
                    'method_name' => 'Transfer BCA',
                    'amount' => 445000,
                ],
            ],
            'change' => 0,
            'outstanding_debt' => 0,
            'note' => $longMultilineNote,
            'return_history' => [
                [
                    'reference' => 'RET-202610-001',
                    'date' => '17-10-2026 15:00',
                    'status' => 'Selesai',
                    'cashier' => 'Budi Santoso',
                    'return_total' => 90000,
                    'items' => [
                        [
                            'product_name' => 'Kuas Cat 2 Inch',
                            'resolution' => 'Cash Return',
                            'quantity' => 1,
                            'line_total' => 90000,
                            'price_comparison' => 'Harga satuan saat retur: 100.000 → 90.000',
                        ],
                    ],
                ],
            ],
            'footer_text' => 'Barang yang sudah dibeli tidak dapat ditukar kecuali ada perjanjian. Terima kasih.',
            'currency_symbol' => 'Rp',
            'receipt_visibility' => [
                'show_note' => true,
                'show_return_history' => true,
                'show_price_adjustments' => true,
                'show_ppn' => true,
                'show_signature_space' => true,
            ],
        ];

        $html = view('pos::receipt', compact('receiptData'))->render();

        // 1. Verify 72mm thermal styling is preserved
        $this->assertStringContainsString('size: 72mm auto;', $html);
        $this->assertStringContainsString('max-width: 72mm;', $html);

        // 2. Verify long multiline note wrapping styles & escaped content
        $this->assertStringContainsString('Catatan:', $html);
        $this->assertStringContainsString('white-space: pre-wrap;', $html);
        $this->assertStringContainsString('word-break: break-word;', $html);
        $this->assertStringContainsString('REF/2026/10/SPECIAL-EXPEDITION-TRACK-0099281726481726481927364', $html);
        $this->assertStringContainsString('Terima kasih atas kerjasamanya!', $html);

        // 3. Verify standard split-price annotations
        $this->assertStringContainsString('Harga satuan: 100.000 → 90.000 (2)', $html);
        $this->assertStringContainsString('Harga satuan: 100.000 → 95.000 (1)', $html);

        // 4. Verify packed item generic notice
        $this->assertStringContainsString('Harga penjualan diperbarui', $html);

        // 5. Verify fully returned item price comparison in return history
        $this->assertStringContainsString('RIWAYAT RETUR', $html);
        $this->assertStringContainsString('Harga satuan saat retur: 100.000 → 90.000', $html);

        // 6. Verify intact monetary values
        $this->assertStringContainsString('445.000', $html);
        $this->assertStringContainsString('275.000', $html);
        $this->assertStringContainsString('170.000', $html);
        $this->assertStringContainsString('90.000', $html);

        // 7. Verify preserved footer and stamp tail space
        $this->assertStringContainsString('Harga sudah termasuk PPN', $html);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $html);
        $this->assertStringContainsString('class="receipt-tail-space"', $html);
    }

    /**
     * Regression Test: Bundle cash returns must NOT double-count component rows
     * towards returned bundle quantities, preventing false over-return exceptions
     * and ensuring partial returns preserve remaining bundle quantities.
     */
    public function test_whole_and_partial_bundle_cash_return_does_not_double_count_components(): void
    {
        $setting = $this->createSetting('BUNDLE RETURN STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC BUNDLE', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $bundleProduct = $this->createStockedProduct($setting, $loc, 'BUNDLE-PC', 200000, 10);
        $compProduct = $this->createStockedProduct($setting, $loc, 'COMP-GPU', 150000, 10);

        ProductBundle::create([
            'parent_product_id' => $bundleProduct->id,
            'setting_id' => $setting->id,
            'name' => 'PC Gamer Bundle',
            'bundle_sale_price' => 200000,
            'price' => 200000,
        ]);
        ProductBundleItem::create([
            'bundle_id' => 1,
            'product_id' => $compProduct->id,
            'quantity' => 2,
        ]);

        // Purchase 2 units of the bundle @ 200k = 400,000 total
        $this->addCartLine($cashier, $setting, $bundleProduct->id, 2);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-BUN-RET-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 400000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        $sale = $checkout->sale;
        $txnLine = $checkout->transaction->lines->first();
        $saleDetail = $sale->saleDetails->first();
        $checkoutSale = \Modules\Pos\Entities\PosCheckoutSale::where('pos_checkout_id', $checkout->id)->first();

        // PART 1: Partial return - return 1 bundle for cash (with 2 components)
        // Bundle purchased qty: 2. Return qty: 1 bundle + 2 components.
        $posReturn1 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $checkout->transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $checkout->transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'reference' => 'PR-BUN-PARTIAL-' . uniqid(),
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 200000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-p-' . uniqid(),
            'settled_at' => now(),
            'approved_at' => now(),
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        // Parent bundle return line: 1 bundle
        PosReturnLine::create([
            'pos_return_id' => $posReturn1->id,
            'pos_checkout_sale_id' => $checkoutSale?->id ?? 1,
            'pos_transaction_line_id' => $txnLine->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_parent_sale_detail_id' => $saleDetail->id, // Parent self-reference
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $bundleProduct->id,
            'product_name' => $bundleProduct->product_name,
            'product_code' => $bundleProduct->product_code,
            'quantity' => 1,
            'unit_price' => 200000,
            'line_total' => 200000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // Component return line: 2 GPUs belonging to this returned bundle
        PosReturnLine::create([
            'pos_return_id' => $posReturn1->id,
            'pos_checkout_sale_id' => $checkoutSale?->id ?? 1,
            'pos_transaction_line_id' => null, // Snapshot leaves null on component
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_parent_sale_detail_id' => $saleDetail->id, // Linked to parent sale detail
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $compProduct->id,
            'product_name' => $compProduct->product_name,
            'product_code' => $compProduct->product_code,
            'quantity' => 2,
            'component_quantity_per_bundle' => 2,
            'unit_price' => 0,
            'line_total' => 0,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // SaleDetail remaining quantity is reduced from 2 to 1, and payment refunded from 400k to 200k
        $saleDetail->update(['quantity' => 1, 'sub_total' => 200000]);
        $sale->update(['total_amount' => 200000, 'paid_amount' => 200000]);
        $sale->salePayments->first()->update(['amount' => 200000]);

        $service = app(PosReceiptReprintProjectionService::class);
        $dataPartial = $service->getCompletedReceiptData($checkout->fresh());

        // Assert partial return preserves remaining 1 bundle:
        $this->assertCount(1, $dataPartial['lines']);
        $this->assertEquals(1.0, (float) $dataPartial['lines'][0]['qty']);
        $this->assertEquals(200000.0, (float) $dataPartial['lines'][0]['sub_total']);
        $this->assertEquals(200000.0, (float) $dataPartial['grand_total']);
        $this->assertNotEmpty($dataPartial['return_history']);

        // PART 2: Whole return - return the remaining 1 bundle for cash (with 2 components)
        $posReturn2 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $checkout->transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $checkout->transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'reference' => 'PR-BUN-WHOLE-' . uniqid(),
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 200000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-w-' . uniqid(),
            'settled_at' => now(),
            'approved_at' => now(),
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        // Parent bundle return line: second bundle
        PosReturnLine::create([
            'pos_return_id' => $posReturn2->id,
            'pos_checkout_sale_id' => $checkoutSale?->id ?? 1,
            'pos_transaction_line_id' => $txnLine->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_parent_sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $bundleProduct->id,
            'product_name' => $bundleProduct->product_name,
            'product_code' => $bundleProduct->product_code,
            'quantity' => 1,
            'unit_price' => 200000,
            'line_total' => 200000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // Component return line: 2 GPUs for second bundle
        PosReturnLine::create([
            'pos_return_id' => $posReturn2->id,
            'pos_checkout_sale_id' => $checkoutSale?->id ?? 1,
            'pos_transaction_line_id' => null,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_parent_sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $compProduct->id,
            'product_name' => $compProduct->product_name,
            'product_code' => $compProduct->product_code,
            'quantity' => 2,
            'unit_price' => 0,
            'line_total' => 0,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // SaleDetail remaining quantity reduced to 0, and payment refunded to 0
        $saleDetail->update(['quantity' => 0, 'sub_total' => 0]);
        $sale->update(['total_amount' => 0, 'paid_amount' => 0]);
        $sale->salePayments->first()->update(['amount' => 0, 'status' => 'CANCELLED']);

        $dataWhole = $service->getCompletedReceiptData($checkout->fresh());

        // Assert whole return: bundle is fully returned, omitted from main items
        $this->assertEmpty($dataWhole['lines']);
        $this->assertEquals(0.0, (float) $dataWhole['grand_total']);
        $this->assertCount(2, $dataWhole['return_history']);

        $htmlWhole = view('pos::receipt', ['receiptData' => $dataWhole])->render();
        $this->assertStringContainsString('RIWAYAT RETUR', $htmlWhole);
        $this->assertStringContainsString('(Semua barang telah diretur)', $htmlWhole);
    }
}

