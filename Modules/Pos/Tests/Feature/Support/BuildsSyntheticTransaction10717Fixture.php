<?php

namespace Modules\Pos\Tests\Feature\Support;

use App\Models\User;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransactionLineSerial;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

trait BuildsSyntheticTransaction10717Fixture
{
    /**
     * Build a reusable synthetic reproduction of POS transaction 10717 / checkout 10438:
     * - Owner A (Source setting): Printer Rp2,225,000 (SN XE4Q304308) + SIDU Paper Rp45,000 = Rp2,270,000
     * - Owner U (Unrelated setting or separate split owner): Logitech Rp330,000
     * - Owner B (Replacement setting): Replacement serial XE4Q318288 for the printer
     *
     * @param string $state 'pending_approval' | 'legacy_stale_duplicated'
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function buildSyntheticTransaction10717Fixture(
        string $state = 'pending_approval',
        array $options = []
    ): array {
        $fixtureTag = substr(uniqid(), -6);

        /** @var Setting $settingA */
        $settingA = $options['setting_a'] ?? $this->createSetting('Toko Tiga Saudara 10717 Owner A ' . $fixtureTag);
        $settingA->forceFill(['pos_receipt_show_return_history' => true])->save();
        /** @var PosTerminal $terminalA */
        /** @var Location $locationA */
        if (isset($options['terminal_a'], $options['location_a'])) {
            $terminalA = $options['terminal_a'];
            $locationA = $options['location_a'];
        } else {
            [$terminalA, $locationA] = $this->resolveFixtureTerminalAndLocation($settingA);
        }

        /** @var Setting $settingB */
        $settingB = $options['setting_b'] ?? $this->createSetting('Toko Tiga Saudara 10717 Owner B ' . $fixtureTag);
        $settingB->forceFill(['pos_receipt_show_return_history' => true])->save();
        /** @var Location $locationB */
        if (isset($options['location_b'])) {
            $locationB = $options['location_b'];
        } else {
            [, $locationB] = $this->resolveFixtureTerminalAndLocation($settingB);
        }

        /** @var Setting $settingUnrelated */
        $settingUnrelated = $options['setting_unrelated'] ?? $this->createSetting('Toko Tiga Saudara 10717 Unrelated Owner ' . $fixtureTag);
        $settingUnrelated->forceFill(['pos_receipt_show_return_history' => true])->save();
        /** @var Location $locationUnrelated */
        if (isset($options['location_unrelated'])) {
            $locationUnrelated = $options['location_unrelated'];
        } else {
            [, $locationUnrelated] = $this->resolveFixtureTerminalAndLocation($settingUnrelated);
        }

        /** @var User $cashier */
        if (isset($options['cashier'])) {
            $cashier = $options['cashier'];
        } else {
            foreach ([
                'pos.access',
                'pos.sell',
                'pos.sessions.open',
                'pos.checkout.payment',
                'pos.returns.create',
                'pos.returns.approve',
                'pos.transactions.view',
                'pos.transactions.print-current',
                'pos.receipts.reprint',
                'posPayments.global.access',
            ] as $permissionName) {
                \Spatie\Permission\Models\Permission::findOrCreate($permissionName, 'web');
            }

            $cashier = $this->createUserForSetting($settingA, 'Cashier 10717 ' . uniqid(), [
                'pos.access',
                'pos.sell',
                'pos.sessions.open',
                'pos.checkout.payment',
                'pos.returns.create',
                'pos.returns.approve',
                'pos.transactions.view',
                'pos.transactions.print-current',
                'pos.receipts.reprint',
                'posPayments.global.access',
            ]);
        }

        /** @var PosSession $session */
        $session = $options['session'] ?? $this->resolveFixtureOpenSession($settingA, $terminalA, $cashier);

        $customerA = Customer::query()->create([
            'setting_id' => $settingA->id,
            'customer_name' => 'Pelanggan 10717 ' . $fixtureTag,
            'customer_email' => 'cust10717a.' . $fixtureTag . '@example.com',
            'customer_phone' => '08121071' . random_int(1000, 9999),
        ]);

        $customerB = Customer::query()->create([
            'setting_id' => $settingB->id,
            'customer_name' => 'Pelanggan 10717 ' . $fixtureTag,
            'customer_email' => 'cust10717b.' . $fixtureTag . '@example.com',
            'customer_phone' => '08121072' . random_int(1000, 9999),
        ]);

        // Products:
        // 1. Printer Rp2,225,000 (serialized)
        $printer = $this->resolveFixtureStockedProduct($settingA, $locationA, [
            'product_code' => 'PRINTER-10717-' . uniqid(),
            'product_name' => 'Epson EcoTank L3210 Printer',
            'sale_price' => 2225000,
            'stock_qty' => 5,
            'serial_number_required' => true,
        ]);

        ProductStock::query()->updateOrCreate(
            [
                'product_id' => $printer->id,
                'location_id' => $locationB->id,
            ],
            [
                'quantity' => 5,
                'quantity_non_tax' => 5,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
                'tax_id' => null,
            ]
        );

        ProductPrice::query()->updateOrCreate(
            [
                'product_id' => $printer->id,
                'setting_id' => $settingB->id,
            ],
            [
                'sale_price' => 2225000,
                'last_purchase_price' => 1800000,
                'average_purchase_price' => 1800000,
            ]
        );

        // 2. SIDU Paper Rp45,000
        $paper = $this->resolveFixtureStockedProduct($settingA, $locationA, [
            'product_code' => 'PAPER-10717-' . uniqid(),
            'product_name' => 'Kertas HVS SIDU A4 70gr',
            'sale_price' => 45000,
            'stock_qty' => 20,
            'serial_number_required' => false,
        ]);

        // 3. Logitech Rp330,000 (Unrelated owner)
        $logitech = $this->resolveFixtureStockedProduct($settingUnrelated, $locationUnrelated, [
            'product_code' => 'LOGI-10717-' . uniqid(),
            'product_name' => 'Logitech Wireless Combo MK270r',
            'sale_price' => 330000,
            'stock_qty' => 10,
            'serial_number_required' => false,
        ]);

        // Serials:
        $originalSerialCode = (string) ($options['original_serial'] ?? 'XE4Q304308');
        $replacementSerialCode = (string) ($options['replacement_serial'] ?? 'XE4Q318288');

        $originalSerial = $this->resolveFixtureSerialNumber($printer, $locationA, $originalSerialCode);
        $originalSerial->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        $replacementSerial = $this->resolveFixtureSerialNumber($printer, $locationB, $replacementSerialCode);
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        // POS Transaction & Checkout
        $transaction = PosTransaction::query()->create([
            'setting_id' => $settingA->id,
            'code' => (string) ($options['transaction_code'] ?? ('TXN-10717-' . uniqid())),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
            'customer_id' => $customerA->id,
        ]);

        $linePrinter = PosTransactionLine::query()->create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $printer->id,
            'product_name_snapshot' => $printer->product_name,
            'qty' => 1,
            'unit_price' => 2225000,
            'line_total' => 2225000,
            'line_total_minor' => 222500000,
            'line_meta' => [
                'price_source' => 'BASE',
                'assigned_serials' => [$originalSerial->serial_number],
            ],
        ]);

        PosTransactionLineSerial::query()->create([
            'pos_transaction_line_id' => $linePrinter->id,
            'product_serial_number_id' => $originalSerial->id,
            'serial_number' => $originalSerial->serial_number,
        ]);

        $linePaper = PosTransactionLine::query()->create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $paper->id,
            'product_name_snapshot' => $paper->product_name,
            'qty' => 1,
            'unit_price' => 45000,
            'line_total' => 45000,
            'line_total_minor' => 4500000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $lineLogitech = PosTransactionLine::query()->create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 3,
            'product_id' => $logitech->id,
            'product_name_snapshot' => $logitech->product_name,
            'qty' => 1,
            'unit_price' => 330000,
            'line_total' => 330000,
            'line_total_minor' => 33000000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $checkout = PosCheckout::query()->create([
            'setting_id' => $settingA->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminalA->id,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customerA->id,
            'status' => PosCheckout::STATUS_POSTED,
            'subtotal' => 2600000,
            'grand_total' => 2600000,
            'paid_total' => 2600000,
            'change_total' => 0,
            'debt_amount' => 0,
            'receipt_number' => (string) ($options['receipt_number'] ?? ('RCP-10438-' . uniqid())),
            'idempotency_key' => 'IDEM-10717-' . uniqid(),
            'payload_hash' => 'HASH-10717-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $saleDate = (string) ($options['sale_date'] ?? now()->toDateString());
        $paymentMethod = (string) ($options['payment_method'] ?? 'CASH');

        // Unrelated Sale (214728 equivalent): Rp330,000
        $unrelatedSale = Sale::query()->create([
            'setting_id' => $settingUnrelated->id,
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'reference' => 'SO-214728-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 330000,
            'paid_amount' => 330000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => $paymentMethod,
            'date' => $saleDate,
        ]);

        $checkoutSaleUnrelated = PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $unrelatedSale->id,
            'source_setting_id' => $settingUnrelated->id,
            'source_location_id' => $locationUnrelated->id,
            'grand_total' => 330000,
            'split_key' => 'SPLIT-UNRELATED',
            'tax_bucket' => 'NON_TAX',
        ]);

        $detailLogitech = SaleDetails::query()->create([
            'sale_id' => $unrelatedSale->id,
            'pos_transaction_line_id' => $lineLogitech->id,
            'product_id' => $logitech->id,
            'product_name' => $logitech->product_name,
            'product_code' => $logitech->product_code,
            'quantity' => 1,
            'price' => 330000,
            'unit_price' => 330000,
            'sub_total' => 330000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $unrelatedPayment = SalePayment::query()->create([
            'sale_id' => $unrelatedSale->id,
            'amount' => 330000,
            'date' => $saleDate,
            'reference' => 'PAY-214728-' . uniqid(),
            'payment_method' => $paymentMethod,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $unrelatedDispatch = Dispatch::query()->create([
            'sale_id' => $unrelatedSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::query()->create([
            'dispatch_id' => $unrelatedDispatch->id,
            'sale_id' => $unrelatedSale->id,
            'sale_detail_id' => $detailLogitech->id,
            'product_id' => $logitech->id,
            'dispatched_quantity' => 1,
            'location_id' => $locationUnrelated->id,
            'is_inventory_managed' => true,
        ]);

        // Source Sale (214729 equivalent): Printer Rp2,225,000 + Paper Rp45,000 = Rp2,270,000
        $sourcePaidInitial = (float) ($options['source_paid_initial'] ?? 2270000);
        $sourceDueInitial = max(0.0, 2270000 - $sourcePaidInitial);
        $sourcePaymentStatusInitial = $sourceDueInitial <= 0 ? 'Paid' : ($sourcePaidInitial > 0 ? 'Partial' : 'Unpaid');

        $sourceSale = Sale::query()->create([
            'setting_id' => $settingA->id,
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'reference' => 'SO-214729-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2270000,
            'paid_amount' => $sourcePaidInitial,
            'due_amount' => $sourceDueInitial,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => $sourcePaymentStatusInitial,
            'payment_method' => $paymentMethod,
            'date' => $saleDate,
        ]);

        $checkoutSaleSource = PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sourceSale->id,
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locationA->id,
            'grand_total' => 2270000,
            'split_key' => 'SPLIT-SOURCE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $detailPrinter = SaleDetails::query()->create([
            'sale_id' => $sourceSale->id,
            'pos_transaction_line_id' => $linePrinter->id,
            'product_id' => $printer->id,
            'product_name' => $printer->product_name,
            'product_code' => $printer->product_code,
            'quantity' => 1,
            'price' => 2225000,
            'unit_price' => 2225000,
            'sub_total' => 2225000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $detailPaper = SaleDetails::query()->create([
            'sale_id' => $sourceSale->id,
            'pos_transaction_line_id' => $linePaper->id,
            'product_id' => $paper->id,
            'product_name' => $paper->product_name,
            'product_code' => $paper->product_code,
            'quantity' => 1,
            'price' => 45000,
            'unit_price' => 45000,
            'sub_total' => 45000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sourcePayment = null;
        if ($sourcePaidInitial > 0) {
            $sourcePayment = SalePayment::query()->create([
                'sale_id' => $sourceSale->id,
                'amount' => $sourcePaidInitial,
                'date' => $saleDate,
                'reference' => 'PAY-214729-' . uniqid(),
                'payment_method' => $paymentMethod,
                'status' => SalePayment::STATUS_ACTIVE,
            ]);
        }

        $sourceDispatch = Dispatch::query()->create([
            'sale_id' => $sourceSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetailPrinter = DispatchDetail::query()->create([
            'dispatch_id' => $sourceDispatch->id,
            'sale_id' => $sourceSale->id,
            'sale_detail_id' => $detailPrinter->id,
            'product_id' => $printer->id,
            'dispatched_quantity' => 1,
            'location_id' => $locationA->id,
            'serial_numbers' => json_encode([$originalSerial->serial_number]),
            'is_inventory_managed' => true,
        ]);

        $originalSerial->update(['dispatch_detail_id' => $dispatchDetailPrinter->id]);

        SalesOrderSerialTracking::query()->create([
            'sale_id' => $sourceSale->id,
            'product_serial_number_id' => $originalSerial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
            'return_date' => null,
        ]);

        $dispatchDetailPaper = DispatchDetail::query()->create([
            'dispatch_id' => $sourceDispatch->id,
            'sale_id' => $sourceSale->id,
            'sale_detail_id' => $detailPaper->id,
            'product_id' => $paper->id,
            'dispatched_quantity' => 1,
            'location_id' => $locationA->id,
            'is_inventory_managed' => true,
        ]);

        // PosReturn & SaleReturn for cross-owner printer replacement
        $posReturn = PosReturn::query()->create([
            'setting_id' => $settingA->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'reference' => (string) ($options['return_reference'] ?? ('PR-10717-' . uniqid())),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 2225000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-10717-' . uniqid(),
            'is_reversed' => false,
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $settingA->id,
            'location_id' => $locationA->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sourceSale->id,
            'sale_reference' => $sourceSale->reference,
            'return_type' => 'Replacement',
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'reference' => 'SR-10717-' . uniqid(),
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
            'payment_method' => $paymentMethod,
            'date' => $saleDate,
        ]);

        $returnLinePrinter = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleSource->id,
            'pos_transaction_line_id' => $linePrinter->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sourceSale->id,
            'sale_detail_id' => $detailPrinter->id,
            'dispatch_detail_id' => $dispatchDetailPrinter->id,
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locationA->id,
            'tax_id' => null,
            'product_id' => $printer->id,
            'product_name' => $printer->product_name,
            'product_code' => $printer->product_code,
            'quantity' => 1,
            'unit_price' => 2225000,
            'line_total' => 2225000,
            'serial_number_ids' => [$originalSerial->id],
            'returned_serial_id' => $originalSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $printer->id,
            'replacement_quantity' => 1,
            'line_meta' => [
                'execution_mode' => 'serial_inventory_replacement',
            ],
        ]);

        // Unchanged paper row on the return with resolution = none
        $returnLinePaper = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleSource->id,
            'pos_transaction_line_id' => $linePaper->id,
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'sale_id' => $sourceSale->id,
            'sale_detail_id' => $detailPaper->id,
            'dispatch_detail_id' => $dispatchDetailPaper->id,
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locationA->id,
            'product_id' => $paper->id,
            'product_name' => $paper->product_name,
            'product_code' => $paper->product_code,
            'quantity' => 1,
            'unit_price' => 45000,
            'line_total' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $saleReturnDetailPrinter = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $returnLinePrinter->id,
            'sale_detail_id' => $detailPrinter->id,
            'dispatch_detail_id' => $dispatchDetailPrinter->id,
            'product_id' => $printer->id,
            'product_name' => $printer->product_name,
            'product_code' => $printer->product_code,
            'location_id' => $locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 2225000,
            'unit_price' => 2225000,
            'sub_total' => 2225000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$originalSerial->id],
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_kind' => 'serial_inventory_replacement',
                'execution_mode' => 'cross_owner_replacement',
                'replacement_serial_owner_setting_id' => $settingB->id,
                'replacement_serial_location_id' => $locationB->id,
                'original_sale_correction_quantity' => 1,
                'original_sale_correction_amount' => 2225000,
                'generated_replacement_sale_effects' => [
                    'setting_id' => $settingB->id,
                    'setting_name' => $settingB->company_name,
                    'location_id' => $locationB->id,
                    'location_name' => $locationB->name,
                    'sale_reference' => 'generated_on_approval',
                    'customer_id' => $customerB->id,
                    'customer_resolution_source' => 'existing',
                    'payment_amount' => 2225000.0,
                    'dispatch_quantity' => 1.0,
                ],
            ],
        ]);

        $replacementSale = null;
        $replacementSaleDetail = null;
        $replacementPayment = null;
        $replacementDispatch = null;
        $replacementDispatchDetail = null;

        if ($state === 'legacy_stale_duplicated') {
            $executedAt = now();
            // Source detail 430485 (printer) is reduced to zero, while source sale 214729 header & payment retain Rp2,270,000!
            $detailPrinter->update([
                'quantity' => 0,
                'sub_total' => 0,
            ]);

            $originalSerial->update([
                'dispatch_detail_id' => null,
                'location_id' => $locationA->id,
                'status' => ProductSerialNumber::STATUS_ACTIVE,
            ]);

            SalesOrderSerialTracking::query()
                ->where('sale_id', $sourceSale->id)
                ->where('product_serial_number_id', $originalSerial->id)
                ->update(['return_date' => $executedAt]);

            $replacementSale = Sale::query()->create([
                'date' => $saleDate,
                'due_date' => $saleDate,
                'customer_id' => $customerA->id,
                'customer_name' => $customerA->customer_name,
                'tax_id' => null,
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => 2225000,
                'paid_amount' => 2225000,
                'due_amount' => 0,
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => 'Paid',
                'note' => 'Generated from POS Return ' . $posReturn->reference . ' cross-owner replacement. Original Sale: ' . $sourceSale->reference,
                'setting_id' => $settingB->id,
                'reference' => 'POSREPL/' . now()->format('Ymd') . '/0001',
                'is_tax_included' => false,
                'payment_method' => $paymentMethod,
            ]);

            $replacementSaleDetail = SaleDetails::query()->create([
                'sale_id' => $replacementSale->id,
                'pos_transaction_line_id' => $linePrinter->id,
                'product_id' => $printer->id,
                'product_name' => $printer->product_name,
                'product_code' => $printer->product_code,
                'quantity' => 1,
                'price' => 2225000,
                'unit_price' => 2225000,
                'sub_total' => 2225000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
            ]);

            $replacementPayment = SalePayment::query()->create([
                'sale_id' => $replacementSale->id,
                'amount' => 2225000,
                'date' => $saleDate,
                'reference' => 'CROSS-OWNER-REPL/' . $posReturn->reference,
                'payment_method' => $paymentMethod,
                'note' => 'Generated cross-owner replacement payment from POS Return ' . $posReturn->reference,
                'status' => SalePayment::STATUS_ACTIVE,
            ]);

            $replacementDispatch = Dispatch::query()->create([
                'sale_id' => $replacementSale->id,
                'dispatch_date' => $executedAt,
                'status' => Dispatch::STATUS_APPROVED,
                'approved_by' => $cashier->id,
                'approved_at' => $executedAt,
            ]);

            $replacementDispatchDetail = DispatchDetail::query()->create([
                'dispatch_id' => $replacementDispatch->id,
                'sale_id' => $replacementSale->id,
                'sale_detail_id' => $replacementSaleDetail->id,
                'product_id' => $printer->id,
                'dispatched_quantity' => 1,
                'location_id' => $locationB->id,
                'serial_numbers' => json_encode([$replacementSerial->serial_number]),
                'pos_return_line_id' => $returnLinePrinter->id,
                'is_inventory_managed' => true,
            ]);

            $replacementSerial->update([
                'dispatch_detail_id' => $replacementDispatchDetail->id,
                'location_id' => $locationB->id,
                'status' => ProductSerialNumber::STATUS_SOLD,
            ]);

            SalesOrderSerialTracking::query()->create([
                'sale_id' => $replacementSale->id,
                'product_serial_number_id' => $replacementSerial->id,
                'quantity_allocated' => 1,
                'dispatch_date' => $executedAt,
                'return_date' => null,
            ]);

            $saleReturn->update([
                'status' => 'COMPLETED',
                'approval_status' => 'APPROVED',
                'approved_by' => $cashier->id,
                'approved_at' => $executedAt,
                'received_by' => $cashier->id,
                'received_at' => $executedAt,
                'settled_by' => $cashier->id,
                'settled_at' => $executedAt,
            ]);

            $posReturn->update([
                'status' => PosReturn::STATUS_COMPLETED,
                'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
                'approved_by' => $cashier->id,
                'approved_at' => $executedAt,
                'received_by' => $cashier->id,
                'received_at' => $executedAt,
                'settled_by' => $cashier->id,
                'settled_at' => $executedAt,
            ]);
        }

        return [
            'setting_a' => $settingA,
            'location_a' => $locationA,
            'terminal_a' => $terminalA,
            'setting_b' => $settingB,
            'location_b' => $locationB,
            'setting_unrelated' => $settingUnrelated,
            'location_unrelated' => $locationUnrelated,
            'cashier' => $cashier,
            'session' => $session,
            'customer_a' => $customerA,
            'customer_b' => $customerB,
            'printer' => $printer,
            'paper' => $paper,
            'logitech' => $logitech,
            'original_serial' => $originalSerial->fresh(),
            'replacement_serial' => $replacementSerial->fresh(),
            'transaction' => $transaction->fresh(),
            'checkout' => $checkout->fresh(),
            'line_printer' => $linePrinter->fresh(),
            'line_paper' => $linePaper->fresh(),
            'line_logitech' => $lineLogitech->fresh(),
            'source_sale' => $sourceSale->fresh(),
            'unrelated_sale' => $unrelatedSale->fresh(),
            'checkout_sale_source' => $checkoutSaleSource->fresh(),
            'checkout_sale_unrelated' => $checkoutSaleUnrelated->fresh(),
            'detail_printer' => $detailPrinter->fresh(),
            'detail_paper' => $detailPaper->fresh(),
            'detail_logitech' => $detailLogitech->fresh(),
            'source_payment' => $sourcePayment?->fresh(),
            'unrelated_payment' => $unrelatedPayment->fresh(),
            'dispatch_detail_printer' => $dispatchDetailPrinter->fresh(),
            'dispatch_detail_paper' => $dispatchDetailPaper->fresh(),
            'pos_return' => $posReturn->fresh(),
            'sale_return' => $saleReturn->fresh(),
            'return_line_printer' => $returnLinePrinter->fresh(),
            'return_line_paper' => $returnLinePaper->fresh(),
            'sale_return_detail_printer' => $saleReturnDetailPrinter->fresh(),
            'replacement_sale' => $replacementSale?->fresh(),
            'replacement_sale_detail' => $replacementSaleDetail?->fresh(),
            'replacement_payment' => $replacementPayment?->fresh(),
            'replacement_dispatch' => $replacementDispatch?->fresh(),
            'replacement_dispatch_detail' => $replacementDispatchDetail?->fresh(),
        ];
    }

    /**
     * @return array{0: PosTerminal, 1: Location}
     */
    protected function resolveFixtureTerminalAndLocation(Setting $setting): array
    {
        if (method_exists($this, 'createTerminalWithLocation')) {
            return $this->createTerminalWithLocation($setting);
        }

        $location = Location::query()->create([
            'name' => 'LOC-10717-' . uniqid(),
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::query()->create([
            'setting_id' => $setting->id,
            'code' => 'TRM-10717-' . uniqid(),
            'name' => 'Terminal 10717 ' . $setting->id,
            'is_active' => true,
        ]);

        return [$terminal, $location];
    }

    protected function resolveFixtureOpenSession(Setting $setting, PosTerminal $terminal, User $cashier): PosSession
    {
        return PosSession::query()->create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);
    }

    protected function resolveFixtureStockedProduct(Setting $setting, Location $location, array $overrides): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create(['is_active' => true])->id;
        $category = \Modules\Product\Entities\Category::firstOrCreate(
            ['category_code' => 'CAT-10717-' . $setting->id],
            [
                'category_name' => 'Category 10717 ' . $setting->id,
                'setting_id' => $setting->id,
                'created_by' => $createdBy,
            ]
        );
        $unit = \Modules\Setting\Entities\Unit::firstOrCreate([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $code = (string) ($overrides['product_code'] ?? ('SKU-10717-' . uniqid()));
        $name = (string) ($overrides['product_name'] ?? ('Product ' . $code));
        $salePrice = (float) ($overrides['sale_price'] ?? 10000);
        $stockQty = (int) ($overrides['stock_qty'] ?? 10);
        $serialRequired = (bool) ($overrides['serial_number_required'] ?? false);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => $stockQty,
            'product_cost' => round($salePrice * 0.8, 2),
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $stockQty,
            'quantity_non_tax' => $stockQty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'setting_id' => $setting->id,
            ],
            [
                'sale_price' => $salePrice,
                'last_purchase_price' => round($salePrice * 0.8, 2),
                'average_purchase_price' => round($salePrice * 0.8, 2),
            ]
        );

        return $product;
    }

    protected function resolveFixtureSerialNumber(Product $product, Location $location, string $serialNumber): ProductSerialNumber
    {
        if (method_exists($this, 'createSerialNumber')) {
            return $this->createSerialNumber($product, $location, $serialNumber);
        }

        return ProductSerialNumber::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'dispatch_detail_id' => null,
        ]);
    }
}
