<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosReceiptSectionVisibilityTest extends PosLineageSchemaAndCheckoutTest
{
    use RefreshDatabase;

    public function test_receipt_builders_resolve_fresh_receipt_owned_business_configuration(): void
    {
        $settingA = $this->createSetting('STORE A');
        $settingB = $this->createSetting('STORE B');

        // Configure setting A: note ON, ppn ON, signature OFF
        $settingA->update([
            'pos_receipt_show_note' => true,
            'pos_receipt_show_return_history' => false,
            'pos_receipt_show_price_adjustments' => false,
            'pos_receipt_show_ppn' => true,
            'pos_receipt_show_signature_space' => false,
        ]);

        // Configure setting B: note OFF, ppn OFF, signature ON
        $settingB->update([
            'pos_receipt_show_note' => false,
            'pos_receipt_show_return_history' => true,
            'pos_receipt_show_price_adjustments' => true,
            'pos_receipt_show_ppn' => false,
            'pos_receipt_show_signature_space' => true,
        ]);

        $cashierA = $this->createUserForSetting($settingA, 'cashierA', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $locA = Location::create(['name' => 'LOC A', 'setting_id' => $settingA->id]);
        $this->createTerminalAndSaleLocations($settingA, [$locA]);
        $methodsA = $this->seedPaymentMethods($settingA, true);
        $this->openSession($settingA, PosTerminal::where('setting_id', $settingA->id)->first(), $cashierA);
        $customerA = $this->assignDefaultWalkInCustomer($settingA);
        $prodA = $this->createStockedProduct($settingA, $locA, 'PROD-A', 100000, 10);

        $this->addCartLine($cashierA, $settingA, $prodA->id, 1);
        $this->selectCustomerInCart($cashierA, $settingA, $customerA);

        $res = $this->finalize($cashierA, $settingA, [
            'idempotency_key' => 'K-VIS-001-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methodsA['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        // Active session is set to setting B (simulating cross-business operator context)
        session(['setting_id' => $settingB->id]);

        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout->fresh());

        // Receipt must follow Setting A (the receipt-owning business), NOT active session setting B
        $this->assertTrue($receiptData['receipt_visibility']['show_note']);
        $this->assertFalse($receiptData['receipt_visibility']['show_return_history']);
        $this->assertFalse($receiptData['receipt_visibility']['show_price_adjustments']);
        $this->assertTrue($receiptData['receipt_visibility']['show_ppn']);
        $this->assertFalse($receiptData['receipt_visibility']['show_signature_space']);

        // Now update setting A directly in DB and verify immediate observation on next build
        $settingA->update([
            'pos_receipt_show_note' => false,
            'pos_receipt_show_return_history' => true,
        ]);

        $freshReceiptData = $receiptService->getReceiptData($checkout->fresh());
        $this->assertFalse($freshReceiptData['receipt_visibility']['show_note']);
        $this->assertTrue($freshReceiptData['receipt_visibility']['show_return_history']);
    }

    public function test_draft_and_historical_receipt_builders_include_receipt_visibility(): void
    {
        $setting = $this->createSetting('DRAFT STORE');
        $setting->update([
            'pos_receipt_show_note' => true,
            'pos_receipt_show_signature_space' => false,
        ]);

        $user = $this->createUserForSetting($setting, 'draftuser', ['pos.access', 'pos.sessions.open']);
        $loc = Location::create(['name' => 'LOC DRAFT', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $this->seedPaymentMethods($setting, true);
        $terminal = PosTerminal::where('setting_id', $setting->id)->first();
        $this->openSession($setting, $terminal, $user);
        $session = PosSession::where('setting_id', $setting->id)->latest()->first();

        $draft = PosTransaction::create([
            'setting_id' => $setting->id,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'source_pos_session_id' => $session->id,
            'code' => 'TRX-DRAFT-VIS',
            'status' => PosTransaction::STATUS_DRAFT,
            'note' => 'A draft note',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receiptService = app(PosReceiptService::class);
        $draftData = $receiptService->getTransactionReceiptData($draft);

        $this->assertArrayHasKey('receipt_visibility', $draftData);
        $this->assertTrue($draftData['receipt_visibility']['show_note']);
        $this->assertFalse($draftData['receipt_visibility']['show_signature_space']);
    }

    public function test_compact_parameterized_rendering_matrix_for_explanatory_sections(): void
    {
        $baseData = [
            'business_name' => 'Visibility Matrix Store',
            'date' => now()->format('Y-m-d H:i:s'),
            'receipt_number' => 'REC-MAT-001',
            'pos_transaction_code' => 'TRX-MAT-001',
            'customer_name' => 'Customer Test',
            'cashier_name' => 'Cashier',
            'terminal_name' => 'T1',
            'note' => 'Important customer instructions',
            'lines' => [
                [
                    'product_name' => 'Standard Widget',
                    'qty' => 1,
                    'sub_total' => 90000,
                    'price_adjustments' => ['Harga satuan: 100.000 → 90.000'],
                ],
            ],
            'grand_total' => 90000,
            'payment_breakdown' => [
                ['method_name' => 'Tunai', 'amount' => 90000],
            ],
            'return_history' => [
                [
                    'reference' => 'RET-001',
                    'date' => now()->format('d-m-Y H:i'),
                    'status' => 'Selesai',
                    'debt_reduction' => 0,
                    'actual_refund' => 10000,
                    'items' => [
                        [
                            'product_name' => 'Returned Item',
                            'quantity' => 1,
                            'resolution' => 'cash',
                            'line_total' => 10000,
                            'price_comparison' => 'Harga satuan saat retur: 100.000 → 90.000',
                        ],
                    ],
                ],
            ],
        ];

        // 1. All optional OFF (the default state)
        $dataDefault = array_merge($baseData, [
            'receipt_visibility' => [
                'show_note' => false,
                'show_return_history' => false,
                'show_price_adjustments' => false,
                'show_ppn' => false,
                'show_signature_space' => true,
            ],
        ]);
        $htmlDefault = view('pos::receipt', ['receiptData' => $dataDefault])->render();

        $this->assertStringNotContainsString('Catatan:', $htmlDefault);
        $this->assertStringNotContainsString('Important customer instructions', $htmlDefault);
        $this->assertStringNotContainsString('RIWAYAT RETUR', $htmlDefault);
        $this->assertStringNotContainsString('Harga satuan: 100.000 → 90.000', $htmlDefault);
        $this->assertStringNotContainsString('Harga satuan saat retur:', $htmlDefault);
        $this->assertStringNotContainsString('Harga sudah termasuk PPN', $htmlDefault);
        $this->assertStringContainsString('<div class="receipt-tail-space"></div>', $htmlDefault);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $htmlDefault);

        // 2. Note only ON
        $dataNoteOnly = array_merge($baseData, [
            'receipt_visibility' => [
                'show_note' => true,
                'show_return_history' => false,
                'show_price_adjustments' => false,
                'show_ppn' => false,
                'show_signature_space' => true,
            ],
        ]);
        $htmlNote = view('pos::receipt', ['receiptData' => $dataNoteOnly])->render();
        $this->assertStringContainsString('Catatan:', $htmlNote);
        $this->assertStringContainsString('Important customer instructions', $htmlNote);
        $this->assertStringNotContainsString('RIWAYAT RETUR', $htmlNote);
        $this->assertStringNotContainsString('Harga satuan:', $htmlNote);

        // 3. Return history ON, Price adjustments OFF
        $dataRetNoAdj = array_merge($baseData, [
            'receipt_visibility' => [
                'show_note' => false,
                'show_return_history' => true,
                'show_price_adjustments' => false,
                'show_ppn' => false,
                'show_signature_space' => true,
            ],
        ]);
        $htmlRetNoAdj = view('pos::receipt', ['receiptData' => $dataRetNoAdj])->render();
        $this->assertStringContainsString('RIWAYAT RETUR', $htmlRetNoAdj);
        $this->assertStringContainsString('Returned Item', $htmlRetNoAdj);
        // Both standard price adjustment and return-time price comparison must be absent
        $this->assertStringNotContainsString('Harga satuan: 100.000 → 90.000', $htmlRetNoAdj);
        $this->assertStringNotContainsString('Harga satuan saat retur:', $htmlRetNoAdj);

        // 4. Return history OFF, Price adjustments ON
        $dataNoRetAdj = array_merge($baseData, [
            'receipt_visibility' => [
                'show_note' => false,
                'show_return_history' => false,
                'show_price_adjustments' => true,
                'show_ppn' => false,
                'show_signature_space' => true,
            ],
        ]);
        $htmlNoRetAdj = view('pos::receipt', ['receiptData' => $dataNoRetAdj])->render();
        $this->assertStringContainsString('Harga satuan: 100.000 → 90.000', $htmlNoRetAdj);
        // Return history container is absent, and return-time comparison is NOT relocated
        $this->assertStringNotContainsString('RIWAYAT RETUR', $htmlNoRetAdj);
        $this->assertStringNotContainsString('Harga satuan saat retur:', $htmlNoRetAdj);

        // 5. Both Return history ON and Price adjustments ON
        $dataBothRetAdj = array_merge($baseData, [
            'receipt_visibility' => [
                'show_note' => false,
                'show_return_history' => true,
                'show_price_adjustments' => true,
                'show_ppn' => false,
                'show_signature_space' => true,
            ],
        ]);
        $htmlBoth = view('pos::receipt', ['receiptData' => $dataBothRetAdj])->render();
        $this->assertStringContainsString('Harga satuan: 100.000 → 90.000', $htmlBoth);
        $this->assertStringContainsString('RIWAYAT RETUR', $htmlBoth);
        $this->assertStringContainsString('Harga satuan saat retur: 100.000 → 90.000', $htmlBoth);
    }

    public function test_independent_gating_of_ppn_and_signature_space(): void
    {
        $baseData = [
            'business_name' => 'Footer Store',
            'date' => now()->format('Y-m-d H:i:s'),
            'receipt_number' => 'REC-FOOTER-001',
            'lines' => [],
            'grand_total' => 50000,
        ];

        // Case A: PPN ON, Signature space ON
        $dataA = array_merge($baseData, [
            'receipt_visibility' => [
                'show_ppn' => true,
                'show_signature_space' => true,
            ],
        ]);
        $htmlA = view('pos::receipt', ['receiptData' => $dataA])->render();
        $this->assertStringContainsString('<div class="receipt-tail-space"></div>', $htmlA);
        $this->assertStringContainsString('<div class="receipt-tail-line"></div>', $htmlA);
        $this->assertStringContainsString('Harga sudah termasuk PPN', $htmlA);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $htmlA);
        $this->assertStringContainsString('REC-FOOTER-001', $htmlA);

        // Case B: PPN ON, Signature space OFF
        $dataB = array_merge($baseData, [
            'receipt_visibility' => [
                'show_ppn' => true,
                'show_signature_space' => false,
            ],
        ]);
        $htmlB = view('pos::receipt', ['receiptData' => $dataB])->render();
        $this->assertStringNotContainsString('<div class="receipt-tail-space"></div>', $htmlB);
        $this->assertStringNotContainsString('<div class="receipt-tail-line"></div>', $htmlB);
        $this->assertStringContainsString('Harga sudah termasuk PPN', $htmlB);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $htmlB);
        $this->assertStringContainsString('REC-FOOTER-001', $htmlB);

        // Case C: PPN OFF, Signature space ON (Default)
        $dataC = array_merge($baseData, [
            'receipt_visibility' => [
                'show_ppn' => false,
                'show_signature_space' => true,
            ],
        ]);
        $htmlC = view('pos::receipt', ['receiptData' => $dataC])->render();
        $this->assertStringContainsString('<div class="receipt-tail-space"></div>', $htmlC);
        $this->assertStringContainsString('<div class="receipt-tail-line"></div>', $htmlC);
        $this->assertStringNotContainsString('Harga sudah termasuk PPN', $htmlC);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $htmlC);

        // Case D: PPN OFF, Signature space OFF
        $dataD = array_merge($baseData, [
            'receipt_visibility' => [
                'show_ppn' => false,
                'show_signature_space' => false,
            ],
        ]);
        $htmlD = view('pos::receipt', ['receiptData' => $dataD])->render();
        $this->assertStringNotContainsString('<div class="receipt-tail-space"></div>', $htmlD);
        $this->assertStringNotContainsString('<div class="receipt-tail-line"></div>', $htmlD);
        $this->assertStringNotContainsString('Harga sudah termasuk PPN', $htmlD);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $htmlD);
    }

    public function test_adjusted_and_returned_transaction_proves_visibility_changes_leave_financial_and_purchase_records_unchanged(): void
    {
        $setting = $this->createSetting('FINANCIAL PARITY STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint', 'pos.returns.create', 'pos.returns.approve',
        ]);
        $loc = Location::create(['name' => 'PARITY LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $prod1 = $this->createStockedProduct($setting, $loc, 'ITEM-P1', 100000, 10);
        $prod2 = $this->createStockedProduct($setting, $loc, 'ITEM-P2', 50000, 10);

        // Checkout: 2x Prod1 (200k), 1x Prod2 (50k) = 250k
        $this->addCartLine($cashier, $setting, $prod1->id, 2);
        $this->addCartLine($cashier, $setting, $prod2->id, 1);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $res = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-FIN-001-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 250000,
            ],
        ]);
        $res->assertStatus(201);
        $checkout = PosCheckout::findOrFail((int) $res->json('pos_checkout_id'));

        $transaction = $checkout->transaction;
        $transaction->update(['note' => 'Transaction financial note']);

        // 1. Price adjustment: adjust Prod2 unit price from 50k to 40k on linked Sale
        $saleDetail2 = $checkout->sale->saleDetails()->where('product_id', $prod2->id)->first();
        $saleDetail2->update(['unit_price' => 40000, 'sub_total' => 40000]);
        $checkout->sale->update(['total_amount' => 240000, 'paid_amount' => 240000]);
        $checkout->sale->salePayments()->first()?->update(['amount' => 240000]);

        // 2. Return: return 1 unit of Prod1
        $saleDetail1 = $checkout->sale->saleDetails()->where('product_id', $prod1->id)->first();
        $trxLine1 = $transaction->lines()->where('product_id', $prod1->id)->first();

        $checkoutSale = $checkout->checkoutSales()->first();

        $return = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_checkout_id' => $checkout->id,
            'pos_transaction_id' => $transaction->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'reference' => 'RET-FIN-001',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 100000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-fin',
            'created_by' => $cashier->id,
            'settled_at' => now(),
            'approved_at' => now(),
        ]);

        PosReturnLine::create([
            'pos_return_id' => $return->id,
            'pos_checkout_sale_id' => $checkoutSale?->id ?? 1,
            'pos_transaction_line_id' => $trxLine1->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $checkout->sale_id,
            'sale_detail_id' => $saleDetail1->id,
            'product_id' => $prod1->id,
            'product_name' => $prod1->product_name,
            'product_code' => $prod1->product_code,
            'quantity' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        // Record initial database states
        $dbCheckoutBefore = PosCheckout::find($checkout->id)->toArray();
        $dbSaleBefore = \Modules\Sale\Entities\Sale::find($checkout->sale_id)->toArray();
        $dbReturnBefore = PosReturn::find($return->id)->toArray();

        $projectionService = app(PosReceiptReprintProjectionService::class);

        // Build A: All optional switches ENABLED
        $setting->update([
            'pos_receipt_show_note' => true,
            'pos_receipt_show_return_history' => true,
            'pos_receipt_show_price_adjustments' => true,
            'pos_receipt_show_ppn' => true,
            'pos_receipt_show_signature_space' => true,
        ]);
        $dataEnabled = $projectionService->getCompletedReprintReceiptData($checkout->fresh());
        $htmlEnabled = view('pos::receipt', ['receiptData' => $dataEnabled])->render();

        // Build B: All optional switches DISABLED
        $setting->update([
            'pos_receipt_show_note' => false,
            'pos_receipt_show_return_history' => false,
            'pos_receipt_show_price_adjustments' => false,
            'pos_receipt_show_ppn' => false,
            'pos_receipt_show_signature_space' => false,
        ]);
        $dataDisabled = $projectionService->getCompletedReprintReceiptData($checkout->fresh());
        $htmlDisabled = view('pos::receipt', ['receiptData' => $dataDisabled])->render();

        // 1. Financial fields in payloads are exactly identical
        $this->assertEquals($dataEnabled['grand_total'], $dataDisabled['grand_total']);
        $this->assertEquals($dataEnabled['subtotal'], $dataDisabled['subtotal']);
        $this->assertEquals($dataEnabled['amount_paid'], $dataDisabled['amount_paid']);
        $this->assertEquals($dataEnabled['change'], $dataDisabled['change']);
        $this->assertEquals($dataEnabled['outstanding_debt'], $dataDisabled['outstanding_debt']);

        // 2. Purchase lines in payloads are exactly identical (quantities, prices, subtotals, names)
        $this->assertCount(count($dataEnabled['lines']), $dataDisabled['lines']);
        for ($i = 0; $i < count($dataEnabled['lines']); $i++) {
            $this->assertEquals($dataEnabled['lines'][$i]['product_name'], $dataDisabled['lines'][$i]['product_name']);
            $this->assertEquals($dataEnabled['lines'][$i]['qty'], $dataDisabled['lines'][$i]['qty']);
            $this->assertEquals($dataEnabled['lines'][$i]['sub_total'], $dataDisabled['lines'][$i]['sub_total']);
            $this->assertEquals($dataEnabled['lines'][$i]['price'], $dataDisabled['lines'][$i]['price']);
        }

        // 3. Persisted database records are completely untouched by receipt rendering
        $this->assertEquals($dbCheckoutBefore, PosCheckout::find($checkout->id)->toArray());
        $this->assertEquals($dbSaleBefore, \Modules\Sale\Entities\Sale::find($checkout->sale_id)->toArray());
        $this->assertEquals($dbReturnBefore, PosReturn::find($return->id)->toArray());

        // 4. Rendered output verification: presentation differs, financial facts remain
        // When enabled:
        $this->assertStringContainsString('Catatan:', $htmlEnabled);
        $this->assertStringContainsString('RIWAYAT RETUR', $htmlEnabled);
        $this->assertStringContainsString('Harga satuan:', $htmlEnabled);
        $this->assertStringContainsString('Harga sudah termasuk PPN', $htmlEnabled);
        $this->assertStringContainsString('<div class="receipt-tail-space"></div>', $htmlEnabled);

        // When disabled:
        $this->assertStringNotContainsString('Catatan:', $htmlDisabled);
        $this->assertStringNotContainsString('RIWAYAT RETUR', $htmlDisabled);
        $this->assertStringNotContainsString('Harga satuan:', $htmlDisabled);
        $this->assertStringNotContainsString('Harga sudah termasuk PPN', $htmlDisabled);
        $this->assertStringNotContainsString('<div class="receipt-tail-space"></div>', $htmlDisabled);

        // Both preserve purchase items and totals
        $this->assertStringContainsString('ITEM-P1', $htmlDisabled);
        $this->assertStringContainsString('ITEM-P2', $htmlDisabled);
        $this->assertStringContainsString('240.000', $htmlDisabled);
        $this->assertStringContainsString('Terima Kasih Telah Berbelanja', $htmlDisabled);
    }
}
