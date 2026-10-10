<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Exceptions\PosReprintAmbiguityException;
use Modules\Pos\Exceptions\PosReprintProjectionException;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;

class PosReprintCurrentMoneyTest extends PosLineageSchemaAndCheckoutTest
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('pos.receipts.reprint', 'web');
        Permission::findOrCreate('posPayments.global.access', 'web');
    }

    /**
     * Test three-owner bundle price edit reprint projects updated line money
     * and grand total without double-counting bundle items (Tasks 2.1, 2.2).
     */
    public function test_three_owner_bundle_and_normal_item_price_edit_reprint(): void
    {
        $terminalSetting = $this->createSetting('TERM BIZ');
        $source1Setting = $this->createSetting('SOURCE1 BIZ');
        $source2Setting = $this->createSetting('SOURCE2 BIZ');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $locTerminal = Location::create(['name' => 'TERM LOC', 'setting_id' => $terminalSetting->id]);
        $locSource1 = Location::create(['name' => 'SOURCE1 LOC', 'setting_id' => $source1Setting->id]);
        $locSource2 = Location::create(['name' => 'SOURCE2 LOC', 'setting_id' => $source2Setting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource1, $locSource2]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 10, $tax);
        $compA = $this->createStockedProduct($source1Setting, $locSource1, 'COMP-A', 0, 10, $tax);
        $compB = $this->createStockedProduct($source2Setting, $locSource2, 'COMP-B', 0, 10, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle 3-Owner',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // Cart with 1 bundle line (175,000)
        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, null, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-REPRINT-3OWNER-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);
        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['transaction.lines', 'checkoutSales.sale.saleDetails'])->findOrFail($checkoutId);

        // Edit the parent-owner Sale detail price by +50,000 (e.g. parent residual becomes 125,000 instead of 75,000)
        $parentSale = $checkout->checkoutSales->firstWhere('sale.setting_id', $terminalSetting->id)->sale;
        $parentDetail = $parentSale->saleDetails->firstWhere('product_id', $parent->id);
        
        $oldPrice = (float) $parentDetail->price;
        $parentDetail->update([
            'price' => $oldPrice + 50000,
            'sub_total' => (float) $parentDetail->sub_total + 50000,
        ]);
        $parentSale->update([
            'total_amount' => (float) $parentSale->total_amount + 50000,
            'due_amount' => (float) $parentSale->due_amount + 50000,
        ]);

        // Project reprint receipt data
        $service = app(PosReceiptReprintProjectionService::class);
        $reprintData = $service->getCompletedReprintReceiptData($checkout);

        // Bundle line should now show 175,000 + 50,000 = 225,000
        $this->assertEquals(225000.0, $reprintData['grand_total']);
        $this->assertCount(1, $reprintData['lines']);
        $this->assertEquals(225000.0, $reprintData['lines'][0]['sub_total']);
        $this->assertEquals(225000.0, $reprintData['lines'][0]['price']);
        
        // Tender and change remain checkout facts
        $this->assertEquals(175000.0, $reprintData['amount_paid']);
        $this->assertEquals(0.0, $reprintData['change']);
        // Live due (Sisa Utang) is now 50,000 because total increased by 50,000 while paid remained 175,000
        $this->assertEquals(50000.0, $reprintData['outstanding_debt']);
    }

    /**
     * Test Sale header discount/shipping changes reconcile to printed line total (Task 2.2).
     */
    public function test_sale_header_discount_reconciles_to_lines(): void
    {
        $setting = $this->createSetting('STORE A');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC A', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod1 = $this->createStockedProduct($setting, $loc, 'ITEM-1', 100000, 10);
        $prod2 = $this->createStockedProduct($setting, $loc, 'ITEM-2', 100000, 10);

        $this->addCartLine($cashier, $setting, $prod1->id, 1);
        $this->addCartLine($cashier, $setting, $prod2->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-INLINE-DISC-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        // The checkout-time discount snapshot is deliberately left untouched: a real Sale edit
        // (SaleMonetaryEditService) rewrites only the Sale header and details.
        $snapshotDiscountBefore = (float) $checkout->discount_total;
        $sale = $checkout->sale;
        $sale->update([
            'discount_amount' => 20000,
            'total_amount' => 180000,
        ]);
        $this->assertSame($snapshotDiscountBefore, (float) $checkout->fresh()->discount_total);

        $service = app(PosReceiptReprintProjectionService::class);
        $reprintData = $service->getCompletedReprintReceiptData($checkout);

        $this->assertEquals(180000.0, $reprintData['grand_total']);
        $lineSum = array_sum(array_column($reprintData['lines'], 'sub_total'));
        $this->assertEquals(180000.0, $lineSum);
        // Each 100,000 item received half of the 20,000 discount => 90,000 each
        $this->assertEquals(90000.0, $reprintData['lines'][0]['sub_total']);
        $this->assertEquals(90000.0, $reprintData['lines'][1]['sub_total']);

        // The discount is already inside the line amounts: no stale or second deduction may remain.
        $this->assertEquals(0.0, $reprintData['discount']);
        foreach ($reprintData['lines'] as $line) {
            $this->assertEquals(0.0, $line['discount']);
            $this->assertEquals(0.0, $line['bill_discount']);
        }

        $receiptHtml = view('pos::receipt', ['receiptData' => $reprintData])->render();
        $this->assertStringNotContainsString('Diskon', $receiptHtml);
        // Displayed rows (no extra deduction rows) reconcile with the displayed Total.
        $this->assertStringContainsString('90.000', $receiptHtml);
        $this->assertStringContainsString('180.000', $receiptHtml);
    }

    public function test_reprint_after_sale_discount_edit_ignores_stale_checkout_discount_snapshot(): void
    {
        $setting = $this->createSetting('STORE STALE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC S', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $prod = $this->createStockedProduct($setting, $loc, 'ITEM-S', 100000, 10);

        $this->addCartLine($cashier, $setting, $prod->id, 2);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-STALE-DISC-' . uniqid(),
            'payment' => ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 200000],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        // Checkout snapshot recorded 0 discount; the Sale later gains a 30,000 header discount.
        $this->assertEquals(0.0, (float) $checkout->discount_total);
        $checkout->sale->update(['discount_amount' => 30000, 'total_amount' => 170000]);

        $data = app(PosReceiptReprintProjectionService::class)->getCompletedReprintReceiptData($checkout);

        $this->assertEquals(170000.0, $data['grand_total']);
        $this->assertEquals(170000.0, array_sum(array_column($data['lines'], 'sub_total')));
        $this->assertEquals(0.0, $data['discount']);
        $html = view('pos::receipt', ['receiptData' => $data])->render();
        $this->assertStringNotContainsString('Diskon', $html);
        $this->assertStringContainsString('170.000', $html);
    }

    public function test_packed_unit_breakdown_distributes_remainder_cents_to_match_line_total(): void
    {
        $setting = $this->createSetting('PACKED REPRINT BIZ');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $location = Location::create(['name' => 'PACKED LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$location]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $product = $this->createStockedProduct($setting, $location, 'PACKED-LOOSE', 3000, 10);

        $this->addCartLine($cashier, $setting, $product->id, 3);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-PACKED-REPRINT-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 9000,
            ],
        ]);
        $response->assertStatus(201);

        $checkout = PosCheckout::findOrFail((int) $response->json('pos_checkout_id'));
        $transactionLine = $checkout->transaction->lines->first();
        $transactionLine->update([
            'line_meta' => array_merge($transactionLine->line_meta ?? [], [
                'price_source' => 'PACKED',
                'breakdown' => [
                    'box_count' => 0,
                    'loose_count' => 3,
                    'conversion_unit_label' => 'Box',
                    'base_unit_label' => 'Pcs',
                    'box_price_applied' => 0,
                    'loose_price_applied' => 3000,
                ],
            ]),
        ]);

        $sale = $checkout->sale;
        $sale->saleDetails()->first()->update([
            'price' => 3333.33,
            'unit_price' => 3333.33,
            'sub_total' => 10000,
        ]);
        $sale->update(['total_amount' => 10000, 'due_amount' => 1000]);

        $reprintData = app(PosReceiptReprintProjectionService::class)
            ->getCompletedReprintReceiptData($checkout->fresh());

        $this->assertEquals(10000.0, $reprintData['lines'][0]['sub_total']);
        $this->assertSame([
            '2 Pcs @ RP. 3.333,33',
            '1 Pcs @ RP. 3.333,34',
        ], $reprintData['lines'][0]['unit_breakdown']);
    }

    public function test_reprint_projection_uses_line_identity_when_base_receipt_rows_are_reordered(): void
    {
        $setting = $this->createSetting('REORDERED REPRINT BIZ');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $location = Location::create(['name' => 'REORDERED LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$location]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $firstProduct = $this->createStockedProduct($setting, $location, 'REORDERED-A', 100000, 10);
        $secondProduct = $this->createStockedProduct($setting, $location, 'REORDERED-B', 100000, 10);

        $this->addCartLine($cashier, $setting, $firstProduct->id, 1);
        $this->addCartLine($cashier, $setting, $secondProduct->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-REORDERED-REPRINT-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);
        $response->assertStatus(201);

        $checkout = PosCheckout::findOrFail((int) $response->json('pos_checkout_id'));
        $sale = $checkout->sale;
        $saleDetailsByLine = $sale->saleDetails->keyBy('pos_transaction_line_id');
        $transactionLines = $checkout->transaction->lines;
        $saleDetailsByLine[$transactionLines[0]->id]->update(['sub_total' => 70000]);
        $saleDetailsByLine[$transactionLines[1]->id]->update(['sub_total' => 130000]);
        $sale->update(['total_amount' => 200000]);

        $receiptService = new class extends PosReceiptService {
            public function getReceiptData(PosCheckout $checkout): array
            {
                $data = parent::getReceiptData($checkout);
                $data['lines'] = array_reverse($data['lines']);

                return $data;
            }
        };

        $reprintService = new PosReceiptReprintProjectionService(receiptService: $receiptService);
        $receiptData = $reprintService->getCompletedReprintReceiptData($checkout);

        $this->assertSame($secondProduct->product_name, $receiptData['lines'][0]['product_name']);
        $this->assertEquals(130000.0, $receiptData['lines'][0]['sub_total']);
        $this->assertSame($firstProduct->product_name, $receiptData['lines'][1]['product_name']);
        $this->assertEquals(70000.0, $receiptData['lines'][1]['sub_total']);
        $this->assertSame(
            (int) $transactionLines[1]->id,
            $receiptData['lines'][0]['pos_transaction_line_id']
        );

        $settlementProjection = new class extends PosSettlementProjectionService {
            public function project(PosTransaction $transaction): array
            {
                $projection = parent::project($transaction);
                $projection['total_amount'] += 0.01;

                return $projection;
            }
        };
        $strictReprintService = new PosReceiptReprintProjectionService(
            settlementProjectionService: $settlementProjection,
            receiptService: $receiptService
        );

        $this->expectException(PosReprintProjectionException::class);
        $strictReprintService->getCompletedReprintReceiptData($checkout);
    }

    /**
     * Test historical single-line works and ambiguous repeated-line fails safely (Task 3.1).
     */
    public function test_historical_single_line_and_ambiguous_repeated_line(): void
    {
        $setting = $this->createSetting('HIST BIZ');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'HIST LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod = $this->createStockedProduct($setting, $loc, 'HIST-PROD', 50000, 10);

        // 1. Create a historical single-line checkout where sale_details.pos_transaction_line_id is NULL
        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-HIST-1-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 50000,
            ],
        ]);
        $checkout1 = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        // Manually strip pos_transaction_line_id to simulate pre-lineage historical data
        $checkout1->sale->saleDetails()->update(['pos_transaction_line_id' => null]);

        $service = app(PosReceiptReprintProjectionService::class);
        $reprintData1 = $service->getCompletedReprintReceiptData($checkout1);
        $this->assertEquals(50000.0, $reprintData1['grand_total']);
        $this->assertEquals(50000.0, $reprintData1['lines'][0]['sub_total']);

        $prodA = $this->createStockedProduct($setting, $loc, 'AMBIG-A', 50000, 10);
        $prodB = $this->createStockedProduct($setting, $loc, 'AMBIG-B', 60000, 10);

        // 2-line checkout with distinct products A and B
        $this->addCartLine($cashier, $setting, $prodA->id, 1);
        $this->addCartLine($cashier, $setting, $prodB->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res2 = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-HIST-2-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 110000,
            ],
        ]);
        $checkout2 = PosCheckout::findOrFail((int) $res2->json('pos_checkout_id'));
        // Make it ambiguous: strip lineage AND duplicate a product in SaleDetails so mapping is not 1-to-1
        $checkout2->sale->saleDetails()->update(['pos_transaction_line_id' => null]);
        // Duplicate first detail so there are multiple details for prodA
        $firstDetail = $checkout2->sale->saleDetails->first();
        $dupDetail = $firstDetail->replicate();
        $dupDetail->save();

        $this->expectException(PosReprintAmbiguityException::class);
        $service->getCompletedReprintReceiptData($checkout2);
    }

    /**
     * Test reprint routes refuse ambiguous multi-line without logging REPRINT (Task 3.2).
     */
    public function test_reprint_routes_refuse_ambiguous_and_do_not_log_reprint(): void
    {
        $setting = $this->createSetting('ROUTE BIZ');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint', 'pos.transactions.view',
        ]);
        $loc = Location::create(['name' => 'ROUTE LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prodA = $this->createStockedProduct($setting, $loc, 'ROUT-A', 50000, 10);
        $prodB = $this->createStockedProduct($setting, $loc, 'ROUT-B', 60000, 10);

        // Multi-line checkout
        $this->addCartLine($cashier, $setting, $prodA->id, 1);
        $this->addCartLine($cashier, $setting, $prodB->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-AMBIG-ROUTE-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 110000,
            ],
        ]);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));
        $transaction = $checkout->transaction
            ?? PosTransaction::where('completed_checkout_id', $checkout->id)->first()
            ?? PosTransaction::where('setting_id', $setting->id)->latest('id')->first();
        $transaction->update([
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);
        // Strip lineage and create duplicate detail to ensure ambiguity
        $checkout->sale->saleDetails()->update(['pos_transaction_line_id' => null]);
        $dup = $checkout->sale->saleDetails->first()->replicate();
        $dup->save();

        $initialLogsCount = PosReceiptPrintLog::count();

        // 1. PosSellController reprint route
        $response = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.checkout.receipt.reprint', $checkout));
        
        $response->assertStatus(422);
        $response->assertSee('Cetak Ulang Harga Terkini Tidak Tersedia');
        $response->assertSee('Lihat Struk Historis Asli');

        // Confirm NO reprint log was created
        $this->assertEquals($initialLogsCount, PosReceiptPrintLog::count());

        // 2. Historical view route works and shows historical banner
        $histResponse = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.checkout.receipt', ['checkout' => $checkout, 'historical' => 1]));
        $histResponse->assertStatus(200);
        $histResponse->assertSee('STRUK HISTORIS CHECKOUT');

        // 3. PosTransactionController reprint route also refuses and creates no reprint log
        $txnReprintRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction->fresh()));
        $txnReprintRes->assertStatus(422);
        $txnReprintRes->assertSee('Cetak Ulang Harga Terkini Tidak Tersedia');

        // Confirm NO REPRINT log was created (only historical initial view log if any)
        $this->assertEquals(0, PosReceiptPrintLog::where('type', PosReceiptPrintLog::TYPE_REPRINT)->count());

        $globalOperator = User::factory()->create();
        $globalOperator->givePermissionTo(['posPayments.global.access', 'pos.receipts.reprint']);
        $globalReprint = $this->actingAs($globalOperator)
            ->post(route('pos.global-payments.receipt.reprint', $transaction->id));
        $globalReprint->assertStatus(422);
        $historicalUrl = route('pos.global-payments.receipt.historical', $transaction->id);
        $globalReprint->assertSee($historicalUrl);

        $this->assertFalse($globalOperator->can('pos.transactions.view'));
        $this->actingAs($globalOperator)
            ->get($historicalUrl)
            ->assertStatus(200)
            ->assertSee('STRUK HISTORIS CHECKOUT');

        app()->instance(PosReceiptReprintProjectionService::class, new class extends PosReceiptReprintProjectionService {
            public function getCompletedReprintReceiptData(PosCheckout $checkout): array
            {
                throw new PosReprintProjectionException('Projection cannot be safely reconciled.');
            }
        });

        $failedCheckoutReprint = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.checkout.receipt.reprint', $checkout));
        $failedCheckoutReprint->assertStatus(422)
            ->assertSee('Projection cannot be safely reconciled.')
            ->assertSee(route('pos.sell.checkout.receipt', ['checkout' => $checkout, 'historical' => 1]));

        $failedTransactionReprint = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction->fresh()));
        $failedTransactionReprint->assertStatus(422)
            ->assertSee('Projection cannot be safely reconciled.')
            ->assertSee(route('pos.transactions.receipt', ['transaction' => $transaction, 'historical' => 1]));

        $failedGlobalReprint = $this->actingAs($globalOperator)
            ->post(route('pos.global-payments.receipt.reprint', $transaction->id));
        $failedGlobalReprint->assertStatus(422)
            ->assertSee('Projection cannot be safely reconciled.')
            ->assertSee($historicalUrl);

        $this->assertSame(0, PosReceiptPrintLog::where('type', PosReceiptPrintLog::TYPE_REPRINT)->count());
    }

    /**
     * Test Task 2.3: Later payments update Sisa Utang while preserving original checkout tender and change.
     */
    public function test_later_payments_update_sisa_utang_and_preserve_checkout_tender(): void
    {
        $setting = $this->createSetting('PAY BIZ');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.checkout.debt', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'PAY LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $term = \Modules\Purchase\Entities\PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);
        $prod = $this->createStockedProduct($setting, $loc, 'PAY-PROD', 100000, 10);

        // Checkout with partial payment (e.g. Total 100,000, paid 40,000, debt 60,000)
        $this->addCartLine($cashier, $setting, $prod->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-PAY-LATER-' . uniqid(),
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 40000,
            ],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        $service = app(PosReceiptReprintProjectionService::class);
        $initialReprint = $service->getCompletedReprintReceiptData($checkout);
        $this->assertEquals(100000.0, $initialReprint['grand_total']);
        $this->assertEquals(40000.0, $initialReprint['amount_paid']);
        $this->assertEquals(0.0, $initialReprint['change']);
        $this->assertEquals(60000.0, $initialReprint['outstanding_debt']);

        // Now a later payment of 35,000 is made on the Sale (outside checkout)
        SalePayment::create([
            'sale_id' => $checkout->sale_id,
            'amount' => 35000,
            'date' => now()->toDateString(),
            'reference' => 'LATER-PAY-' . uniqid(),
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $checkout->sale->update([
            'paid_amount' => 75000,
            'due_amount' => 25000,
            'payment_status' => 'Partial',
        ]);

        $laterReprint = $service->getCompletedReprintReceiptData($checkout);
        // Original checkout tender remains checkout fact (40,000)
        $this->assertEquals(40000.0, $laterReprint['amount_paid']);
        // Live due (Sisa Utang) reflects current aggregate live due: 100,000 - 75,000 = 25,000
        $this->assertEquals(25000.0, $laterReprint['outstanding_debt']);
        $this->assertEquals(100000.0, $laterReprint['grand_total']);
    }
}
