<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class POSReturnReplacement10717RegressionTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    /** @test */
    public function lifecycle_execution_of_10717_fixture_conserves_rp2600000_charges_payments_and_receipt(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');

        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($fixture['pos_return']->id);

        $posReturn = $fixture['pos_return']->fresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);

        $sourceSale = $fixture['source_sale']->fresh();
        $unrelatedSale = $fixture['unrelated_sale']->fresh();
        $replacementSale = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->latest('id')
            ->firstOrFail();

        $settlement = app(PosSettlementProjectionService::class)->project($fixture['transaction']->fresh());
        $this->assertTrue($settlement['is_valid']);

        // Conserved commercial charges across Owner A (remaining Rp45,000 + unrelated Rp330,000) and Owner B (replacement Rp2,225,000) = Rp2,600,000
        $this->assertEquals(45000.0, (float) $sourceSale->total_amount);
        $this->assertEquals(45000.0, (float) $sourceSale->paid_amount);
        $this->assertEquals(0.0, (float) $sourceSale->due_amount);
        $this->assertEquals(45000.0, $sourceSale->getEffectivePaidAmount());

        $this->assertEquals(2225000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(2225000.0, (float) $replacementSale->paid_amount);
        $this->assertEquals(0.0, (float) $replacementSale->due_amount);
        $this->assertEquals(2225000.0, $replacementSale->getEffectivePaidAmount());

        $this->assertEquals(330000.0, (float) $unrelatedSale->total_amount);
        $this->assertEquals(330000.0, $unrelatedSale->getEffectivePaidAmount());

        // No synthetic CROSS-OWNER-REPL SalePayment created on replacement Sale
        $this->assertFalse(
            SalePayment::query()->where('sale_id', $replacementSale->id)->exists(),
            'Live cross-owner replacement must not create an unbacked duplicate SalePayment row.'
        );

        // PosOwnerPaymentTransfer links original payment to replacement sale
        $transfers = PosOwnerPaymentTransfer::query()
            ->where('source_sale_id', $sourceSale->id)
            ->where('replacement_sale_id', $replacementSale->id)
            ->get();
        $this->assertCount(1, $transfers);
        $this->assertEquals(2225000.0, (float) $transfers->first()->amount);
        $this->assertSame(PosOwnerPaymentTransfer::SOURCE_LIVE_EXECUTION, $transfers->first()->transfer_source);

        // Settlement projection conserved at Rp2,600,000
        $this->assertEquals(2600000.0, (float) $settlement['total_amount']);
        $this->assertEquals(2600000.0, (float) $settlement['effective_paid']);
        $this->assertEquals(0.0, (float) $settlement['live_due']);
        $this->assertSame('Paid', $settlement['payment_status']);

        // Receipt reprint projection shows Rp2,600,000 total and Rp45,000 paper line
        $receiptData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($fixture['checkout']->fresh());
        $this->assertEquals(2600000.0, (float) $receiptData['grand_total']);
        $this->assertEquals(2600000.0, (float) $receiptData['amount_paid']);
        $this->assertEquals(0.0, (float) $receiptData['outstanding_debt']);

        $linesByLineNo = collect($receiptData['lines'])->keyBy('pos_transaction_line_id');
        $this->assertEquals(2225000.0, (float) $linesByLineNo[$fixture['line_printer']->id]['sub_total']);
        $this->assertEquals(45000.0, (float) $linesByLineNo[$fixture['line_paper']->id]['sub_total']);
        $this->assertEquals(330000.0, (float) $linesByLineNo[$fixture['line_logitech']->id]['sub_total']);
        $this->assertSame(['XE4Q318288'], $linesByLineNo[$fixture['line_printer']->id]['assigned_serials']);
    }

    /** @test */
    public function partial_quantity_cross_owner_replacement_conserves_source_and_replacement_charges_and_payments(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        // Make printer line a 2-unit purchase (Rp4,450,000) where only 1 unit (Rp2,225,000) is replaced cross-owner
        $secondSerialA = $this->createSerialNumber($fixture['printer'], $fixture['location_a'], 'XE4Q304309');
        $secondSerialA->forceFill([
            'dispatch_detail_id' => $fixture['dispatch_detail_printer']->id,
            'status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_SOLD,
        ])->save();

        \Modules\Pos\Entities\PosTransactionLineSerial::query()->create([
            'pos_transaction_line_id' => $fixture['line_printer']->id,
            'product_serial_number_id' => $secondSerialA->id,
            'serial_number' => $secondSerialA->serial_number,
        ]);

        $fixture['line_printer']->forceFill([
            'qty' => 2,
            'line_meta' => [
                'price_source' => 'BASE',
                'assigned_serials' => ['XE4Q304308', 'XE4Q304309'],
                'serial_number_ids' => [$fixture['original_serial']->id, $secondSerialA->id],
                'serial_numbers' => ['XE4Q304308', 'XE4Q304309'],
            ],
        ])->save();

        $fixture['detail_printer']->forceFill([
            'quantity' => 2,
            'sub_total' => 4450000,
        ])->save();

        $fixture['dispatch_detail_printer']->forceFill([
            'dispatched_quantity' => 2,
            'serial_numbers' => json_encode(['XE4Q304308', 'XE4Q304309']),
        ])->save();

        $fixture['source_sale']->forceFill([
            'total_amount' => 4495000,
            'paid_amount' => 4495000,
        ])->save();

        $fixture['source_payment']->forceFill([
            'amount' => 4495000,
        ])->save();

        $fixture['checkout']->forceFill([
            'subtotal' => 4825000,
            'grand_total' => 4825000,
            'paid_total' => 4825000,
        ])->save();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($fixture['pos_return']->id);

        $sourceSale = $fixture['source_sale']->fresh();
        $replacementSale = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $fixture['pos_return']->reference . '%')
            ->latest('id')
            ->firstOrFail();

        // Source sale keeps 1 printer (Rp2,225,000) + 1 paper (Rp45,000) = Rp2,270,000
        $this->assertEquals(2270000.0, (float) $sourceSale->total_amount);
        $this->assertEquals(2270000.0, $sourceSale->getEffectivePaidAmount());
        $this->assertEquals(1, (int) $fixture['detail_printer']->fresh()->quantity);
        $this->assertEquals(2225000.0, (float) $fixture['detail_printer']->fresh()->sub_total);

        // Replacement sale gets 1 printer (Rp2,225,000)
        $this->assertEquals(2225000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(2225000.0, $replacementSale->getEffectivePaidAmount());

        // Settlement projection across all 3 sales = Rp2,270,000 + Rp2,225,000 + Rp330,000 = Rp4,825,000
        $settlement = app(PosSettlementProjectionService::class)->project($fixture['transaction']->fresh());
        $this->assertTrue($settlement['is_valid']);
        $this->assertEquals(4825000.0, (float) $settlement['total_amount']);
        $this->assertEquals(4825000.0, (float) $settlement['effective_paid']);
        $this->assertEquals(0.0, (float) $settlement['live_due']);

        // Receipt shows 2 printers (XE4Q318288 and XE4Q304309) for Rp4,450,000 and 1 paper for Rp45,000
        $receiptData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($fixture['checkout']->fresh());
        $this->assertEquals(4825000.0, (float) $receiptData['grand_total']);
        $linesByLineNo = collect($receiptData['lines'])->keyBy('pos_transaction_line_id');
        $this->assertEquals(4450000.0, (float) $linesByLineNo[$fixture['line_printer']->id]['sub_total']);
        $this->assertEquals(45000.0, (float) $linesByLineNo[$fixture['line_paper']->id]['sub_total']);
        $this->assertSame(['XE4Q318288', 'XE4Q304309'], $linesByLineNo[$fixture['line_printer']->id]['assigned_serials']);
    }

    /** @test */
    public function chronological_repeated_cross_owner_replacements_chain_from_current_owner_and_resist_duplicate_retry(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        $lifecycle = app(PosReturnLifecycleService::class);

        // Hop 1: Owner A (XE4Q304308) -> Owner B (XE4Q318288)
        $lifecycle->executeApprovalFromPreview($fixture['pos_return']->id);

        // Retry Hop 1: must be rejected as already completed and create no duplicate Sale/Transfer/Stock mutation
        try {
            $lifecycle->executeApprovalFromPreview($fixture['pos_return']->id);
            $this->fail('Expected retry of completed return to be rejected.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $saleB = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $fixture['pos_return']->reference . '%')
            ->firstOrFail();

        $this->assertSame(1, Sale::query()->where('setting_id', $fixture['setting_b']->id)->where('note', 'like', '%' . $fixture['pos_return']->reference . '%')->count());
        $this->assertSame(1, PosOwnerPaymentTransfer::query()->where('pos_return_id', $fixture['pos_return']->id)->count());

        $detailB = \Modules\Sale\Entities\SaleDetails::query()->where('sale_id', $saleB->id)->firstOrFail();
        $dispatchDetailB = \Modules\Sale\Entities\DispatchDetail::query()->where('sale_id', $saleB->id)->firstOrFail();

        // Hop 2: Owner B (XE4Q318288) -> Owner C (XE4Q399999)
        $settingC = $this->createSetting('Setting C - Owner C');
        [, $locationC] = $this->createTerminalWithLocation($settingC, [
            'terminal_code' => 'TERM-10717-C',
            'location_name' => 'Warehouse C',
        ]);
        $customerC = \Modules\People\Entities\Customer::query()->create([
            'setting_id' => $settingC->id,
            'customer_name' => 'Test Customer 10717',
            'customer_email' => 'cust10717@example.com',
            'customer_phone' => '081234567890',
        ]);
        \Modules\Product\Entities\ProductStock::query()->create([
            'product_id' => $fixture['printer']->id,
            'location_id' => $locationC->id,
            'quantity' => 2,
            'quantity_tax' => 0,
            'quantity_non_tax' => 2,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => null,
        ]);
        $serialC = $this->createSerialNumber($fixture['printer'], $locationC, 'XE4Q399999');
        $serialC->forceFill(['status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE])->save();

        // Create second PosReturn replacing XE4Q318288 (now on saleB under Setting B) with XE4Q399999 (under Setting C)
        $posReturn2 = PosReturn::query()->create([
            'setting_id' => $fixture['setting_a']->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'transaction_code' => $fixture['transaction']->code,
            'receipt_number' => $fixture['checkout']->receipt_number,
            'source_snapshot' => [],
            'source_snapshot_hash' => hash('sha256', 'hop-2'),
            'reference' => 'PR-10717-0002',
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 2225000,
            'created_by' => $fixture['cashier']->id,
            'updated_by' => $fixture['cashier']->id,
        ]);

        $saleReturn2 = \Modules\SalesReturn\Entities\SaleReturn::query()->create([
            'setting_id' => $fixture['setting_b']->id,
            'location_id' => $fixture['location_b']->id,
            'pos_return_id' => $posReturn2->id,
            'sale_id' => $saleB->id,
            'sale_reference' => $saleB->reference,
            'return_type' => 'Replacement',
            'customer_id' => $saleB->customer_id,
            'customer_name' => $saleB->customer_name,
            'reference' => 'SR-10717-0002',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2225000,
            'paid_amount' => 0,
            'due_amount' => 2225000,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => '2026-04-16',
        ]);

        $returnLine2 = \Modules\Pos\Entities\PosReturnLine::query()->create([
            'pos_return_id' => $posReturn2->id,
            'pos_checkout_sale_id' => $fixture['checkout_sale_source']->id,
            'pos_transaction_line_id' => $fixture['line_printer']->id,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn2->id,
            'sale_id' => $saleB->id,
            'sale_detail_id' => $detailB->id,
            'dispatch_detail_id' => $dispatchDetailB->id,
            'source_setting_id' => $fixture['setting_b']->id,
            'source_location_id' => $fixture['location_b']->id,
            'tax_id' => null,
            'product_id' => $fixture['printer']->id,
            'product_name' => $fixture['printer']->product_name,
            'product_code' => $fixture['printer']->product_code,
            'quantity' => 1,
            'unit_price' => 2225000,
            'line_total' => 2225000,
            'serial_number_ids' => [$fixture['replacement_serial']->id],
            'returned_serial_id' => $fixture['replacement_serial']->id,
            'replacement_serial_id' => $serialC->id,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $fixture['printer']->id,
            'replacement_quantity' => 1,
        ]);

        $saleReturnDetail2 = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn2->id,
            'pos_return_line_id' => $returnLine2->id,
            'sale_detail_id' => $detailB->id,
            'dispatch_detail_id' => $dispatchDetailB->id,
            'product_id' => $fixture['printer']->id,
            'product_name' => $fixture['printer']->product_name,
            'product_code' => $fixture['printer']->product_code,
            'location_id' => $fixture['location_b']->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 2225000,
            'unit_price' => 2225000,
            'sub_total' => 2225000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$fixture['replacement_serial']->id],
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'cross_owner_replacement',
                'replacement_serial_owner_setting_id' => $settingC->id,
                'replacement_serial_location_id' => $locationC->id,
                'original_sale_correction_quantity' => 1,
                'original_sale_correction_amount' => 2225000,
                'generated_replacement_sale_effects' => [
                    'setting_id' => $settingC->id,
                    'setting_name' => $settingC->company_name,
                    'location_id' => $locationC->id,
                    'location_name' => $locationC->name,
                    'sale_reference' => 'generated_on_approval',
                    'customer_id' => $customerC->id,
                    'customer_resolution_source' => 'existing',
                    'payment_amount' => 2225000.0,
                    'dispatch_quantity' => 1.0,
                ],
            ],
        ]);
        $returnLine2->forceFill(['sale_return_detail_id' => $saleReturnDetail2->id])->save();

        $lifecycle->executeApprovalFromPreview($posReturn2->id);

        $saleA = $fixture['source_sale']->fresh();
        $saleB->refresh();
        $saleC = Sale::query()
            ->where('setting_id', $settingC->id)
            ->where('note', 'like', '%' . $posReturn2->reference . '%')
            ->firstOrFail();

        // Owner A keeps Rp45,000; Owner B is reduced to Rp0; Owner C now holds Rp2,225,000
        $this->assertEquals(45000.0, (float) $saleA->total_amount);
        $this->assertEquals(45000.0, $saleA->getEffectivePaidAmount());

        $this->assertEquals(0.0, (float) $saleB->total_amount);
        $this->assertEquals(0.0, $saleB->getEffectivePaidAmount());

        $this->assertEquals(2225000.0, (float) $saleC->total_amount);
        $this->assertEquals(2225000.0, $saleC->getEffectivePaidAmount());

        // Transfer 2 links predecessor_transfer_id from Transfer 1 and original_sale_payment_id from Sale A
        $transfer1 = PosOwnerPaymentTransfer::query()->where('pos_return_id', $fixture['pos_return']->id)->firstOrFail();
        $transfer2 = PosOwnerPaymentTransfer::query()->where('pos_return_id', $posReturn2->id)->firstOrFail();
        $this->assertSame((int) $transfer1->id, (int) $transfer2->predecessor_transfer_id);
        $this->assertSame((int) $fixture['source_payment']->id, (int) $transfer2->original_sale_payment_id);

        // Settlement and receipt projection remain conserved at Rp2,600,000 with final serial XE4Q399999
        $settlement = app(PosSettlementProjectionService::class)->project($fixture['transaction']->fresh());
        $this->assertTrue($settlement['is_valid']);
        $this->assertEquals(2600000.0, (float) $settlement['total_amount']);
        $this->assertEquals(2600000.0, (float) $settlement['effective_paid']);
        $this->assertEquals(0.0, (float) $settlement['live_due']);

        $receiptData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($fixture['checkout']->fresh());
        $this->assertEquals(2600000.0, (float) $receiptData['grand_total']);
        $linesByLineNo = collect($receiptData['lines'])->keyBy('pos_transaction_line_id');
        $this->assertEquals(2225000.0, (float) $linesByLineNo[$fixture['line_printer']->id]['sub_total']);
        $this->assertEquals(45000.0, (float) $linesByLineNo[$fixture['line_paper']->id]['sub_total']);
        $this->assertSame(['XE4Q399999'], $linesByLineNo[$fixture['line_printer']->id]['assigned_serials']);
    }

    /** @test */
    public function mixed_cash_return_and_cross_owner_replacement_in_one_pos_return_conserves_refund_and_transfer(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        // Make paper line a cash_return in the same PosReturn alongside the cross-owner printer replacement
        $fixture['pos_return']->forceFill([
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 2270000,
        ])->save();

        $fixture['sale_return']->forceFill([
            'return_type' => 'Mixed',
            'total_amount' => 45000,
            'due_amount' => 45000,
        ])->save();

        $fixture['return_line_paper']->forceFill([
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $fixture['sale_return']->id,
            'expected_cash_amount' => 45000,
            'line_total' => 45000,
        ])->save();

        $paperReturnDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->create([
            'sale_return_id' => $fixture['sale_return']->id,
            'pos_return_line_id' => $fixture['return_line_paper']->id,
            'sale_detail_id' => $fixture['detail_paper']->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_paper']->id,
            'product_id' => $fixture['paper']->id,
            'product_name' => $fixture['paper']->product_name,
            'product_code' => $fixture['paper']->product_code,
            'location_id' => $fixture['location_a']->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 45000,
            'unit_price' => 45000,
            'sub_total' => 45000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                'planned_amount' => 45000,
            ],
        ]);
        $fixture['return_line_paper']->forceFill(['sale_return_detail_id' => $paperReturnDetail->id])->save();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($fixture['pos_return']->id);

        $sourceSale = $fixture['source_sale']->fresh();
        $replacementSale = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $fixture['pos_return']->reference . '%')
            ->latest('id')
            ->firstOrFail();

        // Source sale is now Rp0 (both printer and paper left it: paper cash-returned Rp45,000, printer replaced cross-owner Rp2,225,000)
        $this->assertEquals(0.0, (float) $sourceSale->total_amount);
        $this->assertEquals(0.0, (float) $sourceSale->paid_amount);
        $this->assertEquals(0.0, (float) $sourceSale->due_amount);
        $this->assertEquals(0.0, $sourceSale->getEffectivePaidAmount());

        // Replacement sale holds Rp2,225,000 paid via PosOwnerPaymentTransfer from the split active payment on sourceSale
        $this->assertEquals(2225000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(2225000.0, (float) $replacementSale->paid_amount);
        $this->assertEquals(2225000.0, $replacementSale->getEffectivePaidAmount());

        // Cash refund recorded on SaleReturn is exactly Rp45,000 (never Rp2,270,000!)
        $saleReturn = $fixture['sale_return']->fresh();
        $this->assertEquals(45000.0, (float) $saleReturn->paid_amount);

        // Settlement projection across checkout is Rp2,225,000 (printer) + Rp330,000 (Logitech) = Rp2,555,000
        $settlement = app(PosSettlementProjectionService::class)->project($fixture['transaction']->fresh());
        $this->assertTrue($settlement['is_valid']);
        $this->assertEquals(2555000.0, (float) $settlement['total_amount']);
        $this->assertEquals(2555000.0, (float) $settlement['effective_paid']);
        $this->assertEquals(0.0, (float) $settlement['live_due']);
    }

    /** @test */
    public function later_cash_return_after_cross_owner_replacement_preserves_debt_first_and_transfer_accounting(): void
    {
        // Partial-paid source sale: Rp2,270,000 total, Rp1,135,000 paid, Rp1,135,000 due (50% paid)
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval', [
            'source_paid_initial' => 1135000,
        ]);
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        $lifecycle = app(PosReturnLifecycleService::class);

        // Step 1: Execute cross-owner printer replacement (Rp2,225,000 charge)
        $lifecycle->executeApprovalFromPreview($fixture['pos_return']->id);

        $sourceSale = $fixture['source_sale']->fresh();
        $replacementSale = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $fixture['pos_return']->reference . '%')
            ->latest('id')
            ->firstOrFail();

        // Source sale has Rp45,000 total, Rp22,500 paid, Rp22,500 due
        // Replacement sale has Rp2,225,000 total, Rp1,112,500 paid, Rp1,112,500 due
        $this->assertEquals(45000.0, (float) $sourceSale->total_amount);
        $this->assertEquals(22500.0, $sourceSale->getEffectivePaidAmount());
        $this->assertEquals(22500.0, (float) $sourceSale->due_amount);

        $this->assertEquals(2225000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(1112500.0, $replacementSale->getEffectivePaidAmount());
        $this->assertEquals(1112500.0, (float) $replacementSale->due_amount);

        // Step 2: Later cash return of the remaining paper (Rp45,000) on sourceSale
        // Since sourceSale has Rp22,500 unpaid debt and Rp22,500 effective paid:
        // Debt-first reduction absorbs Rp22,500 of debt, and actual cash refund is Rp22,500!
        $posReturnCash = PosReturn::query()->create([
            'setting_id' => $fixture['setting_a']->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'transaction_code' => $fixture['transaction']->code,
            'receipt_number' => $fixture['checkout']->receipt_number,
            'source_snapshot' => [],
            'source_snapshot_hash' => hash('sha256', 'later-cash'),
            'reference' => 'PR-10717-CASH2',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 45000,
            'created_by' => $fixture['cashier']->id,
            'updated_by' => $fixture['cashier']->id,
        ]);

        $saleReturnCash = \Modules\SalesReturn\Entities\SaleReturn::query()->create([
            'setting_id' => $fixture['setting_a']->id,
            'location_id' => $fixture['location_a']->id,
            'pos_return_id' => $posReturnCash->id,
            'sale_id' => $sourceSale->id,
            'sale_reference' => $sourceSale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => $sourceSale->customer_id,
            'customer_name' => $sourceSale->customer_name,
            'reference' => 'SR-10717-CASH2',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 45000,
            'paid_amount' => 0,
            'due_amount' => 45000,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => '2026-04-16',
        ]);

        $returnLineCash = \Modules\Pos\Entities\PosReturnLine::query()->create([
            'pos_return_id' => $posReturnCash->id,
            'pos_checkout_sale_id' => $fixture['checkout_sale_source']->id,
            'pos_transaction_line_id' => $fixture['line_paper']->id,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturnCash->id,
            'sale_id' => $sourceSale->id,
            'sale_detail_id' => $fixture['detail_paper']->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_paper']->id,
            'source_setting_id' => $fixture['setting_a']->id,
            'source_location_id' => $fixture['location_a']->id,
            'tax_id' => null,
            'product_id' => $fixture['paper']->id,
            'product_name' => $fixture['paper']->product_name,
            'product_code' => $fixture['paper']->product_code,
            'quantity' => 1,
            'unit_price' => 45000,
            'line_total' => 45000,
            'expected_cash_amount' => 45000,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $saleReturnDetailCash = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturnCash->id,
            'pos_return_line_id' => $returnLineCash->id,
            'sale_detail_id' => $fixture['detail_paper']->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_paper']->id,
            'product_id' => $fixture['paper']->id,
            'product_name' => $fixture['paper']->product_name,
            'product_code' => $fixture['paper']->product_code,
            'location_id' => $fixture['location_a']->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 45000,
            'unit_price' => 45000,
            'sub_total' => 45000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                'planned_amount' => 45000,
            ],
        ]);
        $returnLineCash->forceFill(['sale_return_detail_id' => $saleReturnDetailCash->id])->save();

        $lifecycle->executeApprovalFromPreview($posReturnCash->id);

        $sourceSale->refresh();
        $replacementSale->refresh();
        $saleReturnCash->refresh();

        // Debt-first reduction of Rp22,500 + cash refund of Rp22,500
        $this->assertEquals(22500.0, (float) $saleReturnCash->paid_amount);
        $this->assertEquals(0.0, (float) $saleReturnCash->due_amount);

        // Source sale is now Rp0 total, Rp0 effective paid, while its direct active SalePayment retains Rp1,112,500 backing the outgoing transfer!
        $this->assertEquals(0.0, (float) $sourceSale->total_amount);
        $this->assertEquals(0.0, $sourceSale->getEffectivePaidAmount());
        $this->assertEquals(1112500.0, (float) SalePayment::query()->where('sale_id', $sourceSale->id)->active()->sum('amount'));

        // Replacement sale still holds its Rp1,112,500 incoming transfer and Rp1,112,500 due
        $this->assertEquals(2225000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(1112500.0, $replacementSale->getEffectivePaidAmount());
        $this->assertEquals(1112500.0, (float) $replacementSale->due_amount);
    }

    /** @test */
    public function failure_after_source_correction_or_replacement_creation_rolls_back_all_accounting_stock_serial_and_cost_mutations(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        $stockABefore = (int) \Modules\Product\Entities\ProductStock::query()
            ->where('product_id', $fixture['printer']->id)
            ->where('location_id', $fixture['location_a']->id)
            ->value('quantity');
        $stockBBefore = (int) \Modules\Product\Entities\ProductStock::query()
            ->where('product_id', $fixture['printer']->id)
            ->where('location_id', $fixture['location_b']->id)
            ->value('quantity');
        $historyCountBefore = \Modules\Product\Entities\SerialNumberHistory::query()->count();

        // Case 1: Failure right after source Sale header correction
        $failAfterSource = new class extends PosReturnLifecycleService {
            protected function recalculateCrossOwnerCorrectedSourceSale(
                \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
                \Illuminate\Support\Collection $crossOwnerDetails,
                ?array $crossOwnerTransferPlan = null
            ): void {
                parent::recalculateCrossOwnerCorrectedSourceSale($saleReturn, $crossOwnerDetails, $crossOwnerTransferPlan);
                throw new \RuntimeException('Simulated failure immediately after source sale correction');
            }
        };

        try {
            $failAfterSource->executeApprovalFromPreview($fixture['pos_return']->id);
            $this->fail('Expected failure after source sale correction.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after source sale correction', $e->getMessage());
        }

        // Case 2: Failure right after replacement Sale and PosOwnerPaymentTransfer creation (during finalizeApprovalExecution)
        $failAfterReplacement = new class extends PosReturnLifecycleService {
            protected function finalizeApprovalExecution(
                \Modules\Pos\Entities\PosReturn $posReturn,
                ?int $actorId,
                \Illuminate\Support\Carbon $completedAt
            ): void {
                throw new \RuntimeException('Simulated failure after replacement sale and transfer creation');
            }
        };

        try {
            $failAfterReplacement->executeApprovalFromPreview($fixture['pos_return']->id);
            $this->fail('Expected failure after replacement sale creation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after replacement sale and transfer creation', $e->getMessage());
        }

        // Verify complete atomic rollback across all tables
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $fixture['pos_return']->fresh()->status);
        $this->assertSame('PENDING APPROVAL', $fixture['sale_return']->fresh()->status);
        $this->assertNull($fixture['sale_return_detail_printer']->fresh()->cost_effective_at);

        $sourceSale = $fixture['source_sale']->fresh();
        $this->assertEquals(2270000.0, (float) $sourceSale->total_amount);
        $this->assertEquals(2270000.0, (float) $sourceSale->paid_amount);
        $this->assertEquals(1, (int) $fixture['detail_printer']->fresh()->quantity);
        $this->assertEquals(2225000.0, (float) $fixture['detail_printer']->fresh()->sub_total);

        $this->assertSame(0, Sale::query()->where('setting_id', $fixture['setting_b']->id)->count());
        $this->assertSame(0, PosOwnerPaymentTransfer::query()->count());

        $this->assertSame($stockABefore, (int) \Modules\Product\Entities\ProductStock::query()
            ->where('product_id', $fixture['printer']->id)
            ->where('location_id', $fixture['location_a']->id)
            ->value('quantity'));
        $this->assertSame($stockBBefore, (int) \Modules\Product\Entities\ProductStock::query()
            ->where('product_id', $fixture['printer']->id)
            ->where('location_id', $fixture['location_b']->id)
            ->value('quantity'));

        $this->assertSame(\Modules\Product\Entities\ProductSerialNumber::STATUS_SOLD, $fixture['original_serial']->fresh()->status);
        $this->assertSame(\Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE, $fixture['replacement_serial']->fresh()->status);
        $this->assertSame($historyCountBefore, \Modules\Product\Entities\SerialNumberHistory::query()->count());
    }

    /** @test */
    public function stale_legacy_10717_fixture_refuses_normal_receipt_projection_with_historical_access_guidance_and_makes_no_writes(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        $sourceTotalBefore = (float) $fixture['source_sale']->fresh()->total_amount;
        $replacementTotalBefore = (float) $fixture['replacement_sale']->fresh()->total_amount;
        $paymentsCountBefore = SalePayment::query()->count();
        $transfersCountBefore = PosOwnerPaymentTransfer::query()->count();

        try {
            app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($fixture['checkout']->fresh());
            $this->fail('Expected PosReprintProjectionException on stale duplicated cross-owner replacement fixture.');
        } catch (\Modules\Pos\Exceptions\PosReprintProjectionException $e) {
            $this->assertStringContainsString('struk historis', strtolower($e->getMessage()));
        }

        // Verify zero financial writes occurred during receipt projection refusal
        $this->assertEquals($sourceTotalBefore, (float) $fixture['source_sale']->fresh()->total_amount);
        $this->assertEquals($replacementTotalBefore, (float) $fixture['replacement_sale']->fresh()->total_amount);
        $this->assertSame($paymentsCountBefore, SalePayment::query()->count());
        $this->assertSame($transfersCountBefore, PosOwnerPaymentTransfer::query()->count());
    }

    /** @test */
    public function full_refund_of_replacement_sale_deletes_incoming_transfer_without_nonpositive_amount_exception(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        $lifecycle = app(PosReturnLifecycleService::class);
        $lifecycle->executeApprovalFromPreview($fixture['pos_return']->id);

        $posReturn = $fixture['pos_return']->fresh();
        $replacementSale = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->firstOrFail();

        $this->assertEquals(2225000.0, $replacementSale->getEffectivePaidAmount());
        $this->assertSame(1, $replacementSale->incomingOwnerPaymentTransfers()->count());

        // Now initiate a 100% full cash refund on replacementSale
        $posReturnRefund = PosReturn::query()->create([
            'setting_id' => $fixture['setting_b']->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'transaction_code' => $fixture['transaction']->code,
            'receipt_number' => $fixture['checkout']->receipt_number,
            'source_snapshot' => [],
            'source_snapshot_hash' => hash('sha256', 'full-refund-repl'),
            'reference' => 'PR-10717-FULLREFUND',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 2225000,
            'created_by' => $fixture['cashier']->id,
            'updated_by' => $fixture['cashier']->id,
        ]);

        $saleReturnRefund = \Modules\SalesReturn\Entities\SaleReturn::query()->create([
            'setting_id' => $fixture['setting_b']->id,
            'location_id' => $fixture['location_b']->id,
            'pos_return_id' => $posReturnRefund->id,
            'sale_id' => $replacementSale->id,
            'sale_reference' => $replacementSale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => $replacementSale->customer_id,
            'customer_name' => $replacementSale->customer_name,
            'reference' => 'SR-10717-FULLREFUND',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2225000,
            'paid_amount' => 0,
            'due_amount' => 2225000,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => '2026-04-16',
        ]);

        $replDetail = $replacementSale->saleDetails()->firstOrFail();
        $ptlRefund = \Modules\Pos\Entities\PosTransactionLine::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'line_no' => 10,
            'product_id' => $fixture['printer']->id,
            'product_name_snapshot' => $fixture['printer']->product_name,
            'qty' => 1,
            'unit_price' => 2225000,
            'line_total' => 2225000,
            'line_total_minor' => 222500000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $returnLineRefund = \Modules\Pos\Entities\PosReturnLine::query()->create([
            'pos_return_id' => $posReturnRefund->id,
            'pos_checkout_sale_id' => $fixture['checkout_sale_source']->id,
            'pos_transaction_line_id' => $ptlRefund->id,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturnRefund->id,
            'sale_id' => $replacementSale->id,
            'sale_detail_id' => $replDetail->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_printer']->id,
            'source_setting_id' => $fixture['setting_b']->id,
            'source_location_id' => $fixture['location_b']->id,
            'tax_id' => null,
            'product_id' => $fixture['printer']->id,
            'product_name' => $fixture['printer']->product_name,
            'product_code' => $fixture['printer']->product_code,
            'quantity' => 1,
            'unit_price' => 2225000,
            'line_total' => 2225000,
            'expected_cash_amount' => 2225000,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $saleReturnDetailRefund = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturnRefund->id,
            'pos_return_line_id' => $returnLineRefund->id,
            'sale_detail_id' => $replDetail->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_printer']->id,
            'product_id' => $fixture['printer']->id,
            'product_name' => $fixture['printer']->product_name,
            'product_code' => $fixture['printer']->product_code,
            'location_id' => $fixture['location_b']->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 2225000,
            'unit_price' => 2225000,
            'sub_total' => 2225000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                'planned_amount' => 2225000,
            ],
        ]);
        $returnLineRefund->forceFill(['sale_return_detail_id' => $saleReturnDetailRefund->id])->save();

        // Must succeed without throwing nonpositive amount InvalidArgumentException
        $lifecycle->executeApprovalFromPreview($posReturnRefund->id);

        $replacementSale->refresh();
        $this->assertEquals(0.0, (float) $replacementSale->total_amount);
        $this->assertEquals(0.0, $replacementSale->getEffectivePaidAmount());
        $this->assertSame(0, $replacementSale->incomingOwnerPaymentTransfers()->count());
    }

    /** @test */
    public function mixed_funding_refund_trims_both_direct_payments_and_incoming_transfers(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $this->actingAsInSetting($fixture['cashier'], $fixture['setting_a']);

        $lifecycle = app(PosReturnLifecycleService::class);
        $lifecycle->executeApprovalFromPreview($fixture['pos_return']->id);

        $posReturn = $fixture['pos_return']->fresh();
        $replacementSale = Sale::query()
            ->where('setting_id', $fixture['setting_b']->id)
            ->where('note', 'like', '%' . $posReturn->reference . '%')
            ->firstOrFail();

        // Simulate mixed funding on replacementSale:
        // Detail subtotal 2,425,000, 2 units.
        // Funded by Rp2,225,000 incoming transfer + Rp200,000 direct payment = Rp2,425,000 total & paid
        $replDetail = $replacementSale->saleDetails()->firstOrFail();
        $replDetail->update([
            'quantity' => 2,
            'unit_price' => 1212500,
            'price' => 1212500,
            'sub_total' => 2425000,
        ]);
        $replacementSale->update([
            'total_amount' => 2425000,
            'paid_amount' => 2425000,
        ]);
        SalePayment::query()->create([
            'sale_id' => $replacementSale->id,
            'amount' => 200000,
            'reference' => 'SP-MIXED-DIRECT',
            'date' => '2026-04-16',
            'status' => SalePayment::STATUS_ACTIVE,
            'payment_method' => 'CASH',
        ]);

        $replacementSale->refresh();
        $this->assertEquals(2425000.0, $replacementSale->getEffectivePaidAmount());

        // Refund Rp500,000: returning partial amount (Rp500k), new total is Rp1,925,000.
        // Direct payment (Rp200k) should be invalidated, and transfer reduced by Rp300k to Rp1,925,000!
        $posReturnRefund = PosReturn::query()->create([
            'setting_id' => $fixture['setting_b']->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'transaction_code' => $fixture['transaction']->code,
            'receipt_number' => $fixture['checkout']->receipt_number,
            'source_snapshot' => [],
            'source_snapshot_hash' => hash('sha256', 'mixed-refund-repl'),
            'reference' => 'PR-10717-MIXEDREFUND',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 500000,
            'created_by' => $fixture['cashier']->id,
            'updated_by' => $fixture['cashier']->id,
        ]);

        $saleReturnRefund = \Modules\SalesReturn\Entities\SaleReturn::query()->create([
            'setting_id' => $fixture['setting_b']->id,
            'location_id' => $fixture['location_b']->id,
            'pos_return_id' => $posReturnRefund->id,
            'sale_id' => $replacementSale->id,
            'sale_reference' => $replacementSale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => $replacementSale->customer_id,
            'customer_name' => $replacementSale->customer_name,
            'reference' => 'SR-10717-MIXEDREFUND',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => '2026-04-16',
        ]);

        $ptlRefund = \Modules\Pos\Entities\PosTransactionLine::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'line_no' => 11,
            'product_id' => $fixture['printer']->id,
            'product_name_snapshot' => $fixture['printer']->product_name,
            'qty' => 1,
            'unit_price' => 500000,
            'line_total' => 500000,
            'line_total_minor' => 50000000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $returnLineRefund = \Modules\Pos\Entities\PosReturnLine::query()->create([
            'pos_return_id' => $posReturnRefund->id,
            'pos_checkout_sale_id' => $fixture['checkout_sale_source']->id,
            'pos_transaction_line_id' => $ptlRefund->id,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturnRefund->id,
            'sale_id' => $replacementSale->id,
            'sale_detail_id' => $replDetail->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_printer']->id,
            'source_setting_id' => $fixture['setting_b']->id,
            'source_location_id' => $fixture['location_b']->id,
            'tax_id' => null,
            'product_id' => $fixture['printer']->id,
            'product_name' => $fixture['printer']->product_name,
            'product_code' => $fixture['printer']->product_code,
            'quantity' => 1,
            'unit_price' => 500000,
            'line_total' => 500000,
            'expected_cash_amount' => 500000,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $saleReturnDetailRefund = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturnRefund->id,
            'pos_return_line_id' => $returnLineRefund->id,
            'sale_detail_id' => $replDetail->id,
            'dispatch_detail_id' => $fixture['dispatch_detail_printer']->id,
            'product_id' => $fixture['printer']->id,
            'product_name' => $fixture['printer']->product_name,
            'product_code' => $fixture['printer']->product_code,
            'location_id' => $fixture['location_b']->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 500000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                'planned_amount' => 500000,
            ],
        ]);
        $returnLineRefund->forceFill(['sale_return_detail_id' => $saleReturnDetailRefund->id])->save();

        $lifecycle->executeApprovalFromPreview($posReturnRefund->id);

        $replacementSale->refresh();
        $this->assertEquals(1925000.0, (float) $replacementSale->total_amount);
        $this->assertEquals(1925000.0, $replacementSale->getEffectivePaidAmount());
        $this->assertEquals(1925000.0, (float) $replacementSale->incomingOwnerPaymentTransfers()->sum('amount'));
        $this->assertSame(0, (int) SalePayment::query()->where('sale_id', $replacementSale->id)->active()->count());
    }
}
