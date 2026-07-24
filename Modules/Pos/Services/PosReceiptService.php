<?php

namespace Modules\Pos\Services;

use Carbon\Carbon;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutPayment;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosTransaction;

class PosReceiptService
{
    /**
     * Get data required to render the receipt view.
     */
    public function getReceiptData(PosCheckout $checkout): array
    {
        $checkout->loadMissing([
            'setting.currency',
            'terminal',
            'cashier',
            'customer',
            'paymentMethod',
            'sale.customer',
            'sale.saleDetails.product.unit',
            'sale.saleDetails.product.baseUnit',
            'transaction.lines.conversion.unit',
            'transaction.lines.product.unit',
            'transaction.lines.product.baseUnit',
            'payments.paymentMethod',
            'checkoutSales.sale.saleDetails.bundleItems.product', // Task 1.1: Load split bundle context
        ]);

        $setting = $checkout->setting;
        $sale = $checkout->sale;
        $session = $checkout->session;
        $terminal = $checkout->terminal;
        $customerName = $checkout->customer?->display_name
            ?? $sale?->customer?->display_name
            ?? '-';

        $lines = [];
        // Task 1.2 & 1.3: Prefer PosTransactionLine for accurate unit/conversion breakdown
        // Collect lines from all transactions associated with this checkout
        $allTransactions = $checkout->transactions;
        
        if ($allTransactions->count() > 0) {
            foreach ($allTransactions as $transaction) {
                // Ensure lines are loaded
                $transaction->loadMissing('lines.conversion.unit', 'lines.product.unit', 'lines.product.baseUnit', 'lines.serials');
                $bundleCompositionByLine = $this->bundleCompositionByTransactionLine($transaction);
                
                foreach ($transaction->lines as $line) {
                $unitBreakdown = null;
                $unitName = null;
                $factor = 1.0;

                if ($line->conversion && $line->conversion->unit) {
                    $unitName = $line->conversion->unit->short_name ?? $line->conversion->unit->name;
                    $factor = (float)($line->conversion->conversion_factor ?? 1);
                } elseif ($line->product) {
                    $unitName = $line->product->unit->short_name 
                                ?? $line->product->unit->name 
                                ?? $line->product->baseUnit->short_name
                                ?? $line->product->baseUnit->name
                                ?? $line->product->product_unit;
                    $factor = 1.0;
                }

                $priceSource = $line->line_meta['price_source'] ?? 'BASE';
                $breakdown = $line->line_meta['breakdown'] ?? null;
                
                if ($priceSource === 'PACKED' && is_array($breakdown)) {
                    $unitBreakdown = $this->buildPackedUnitBreakdown($breakdown, $line);
                } elseif ($unitName) {
                    $pricePerUnit = $line->unit_price / $factor;
                    $unitBreakdown = sprintf(
                        "%s %s(S) @ %s",
                        (float)$line->qty,
                        $unitName,
                        format_currency($pricePerUnit)
                    );
                }

                // Simple subtotal calculation for receipt display
                // line_meta['line_total'] is persisted in Rupiah by PosCartTotalsCalculator — no /100 needed.
                if (isset($line->line_meta['line_total'])) {
                    $lineGross = (float) $line->line_meta['line_total'];
                } else {
                    $lineGross = $line->qty * $line->unit_price;
                }
                $lineSubtotal = $lineGross - (float)($line->line_discount_value ?? 0);

                $composition = $bundleCompositionByLine[(int) $line->id] ?? [];

                $lines[] = [
                    'product_name' => $line->product_name_snapshot,
                    'qty' => (float)$line->qty,
                    'price' => (float)$line->unit_price,
                    'discount' => (float)($line->line_discount_value ?? 0),
                    'sub_total' => $lineSubtotal,
                    'unit_breakdown' => $unitBreakdown,
                    'bundle_composition' => $composition, // Task 1.2 & 1.3
                    'assigned_serials' => $line->serials->count() > 0 
                        ? $line->serials->pluck('serial_number')->toArray() 
                        : ($line->line_meta['assigned_serials'] ?? []),
                    'price_source' => $line->line_meta['price_source'] ?? 'BASE',
                    'breakdown' => $line->line_meta['breakdown'] ?? null,
                ];
            }
        }
    } elseif ($sale) {
            foreach ($sale->saleDetails as $detail) {
                $unitBreakdown = null;
                if ($detail->product) {
                    $unitName = $detail->product->unit->short_name 
                                ?? $detail->product->unit->name 
                                ?? $detail->product->baseUnit->short_name
                                ?? $detail->product->baseUnit->name
                                ?? $detail->product->product_unit;
                    if ($unitName) {
                        $unitBreakdown = sprintf(
                            "%s %s(S) @ %s",
                            (float)$detail->quantity,
                            $unitName,
                            format_currency($detail->unit_price)
                        );
                    }
                }

                $lines[] = [
                    'product_name' => $detail->product_name,
                    'qty' => $detail->quantity,
                    'price' => $detail->unit_price,
                    'discount' => ($detail->product_discount_type === 'percentage')
                                ? ($detail->unit_price * ($detail->product_discount_amount / 100))
                                : $detail->product_discount_amount,
                    'sub_total' => $detail->sub_total,
                    'unit_breakdown' => $unitBreakdown,
                ];
            }
        }

        // Task 5.3: Build payment breakdown for mixed-method payments
        $paymentMethod = $checkout->paymentMethod?->name ?? '-';
        $amountPaid = $checkout->paid_total;
        $paymentBreakdown = [];

        if ($checkout->payments && $checkout->payments->count() > 0) {
            // Multi-payment: show breakdown
            foreach ($checkout->payments as $payment) {
                $paymentBreakdown[] = [
                    'method_name' => $payment->paymentMethod?->name ?? 'Unknown',
                    'amount' => $payment->amount, // Task 1.4: Use 'amount' accessor instead of 'amount_paid'
                ];
            }
            // Use first method as primary for backward compatibility, but override with breakdown
            $paymentMethod = $checkout->payments->first()?->paymentMethod?->name ?? $paymentMethod;
        }

        return [
            'business_name' => $setting->company_name ?? 'Business',
            'business_address' => $setting->company_address,
            'business_phone' => $setting->company_phone,
            'business_email' => $setting->company_email,
            'receipt_number' => $checkout->receipt_number,
            'date' => ($checkout->finalized_at ?: $checkout->created_at)->format('d-m-Y H:i'), // Task 1.5
            'customer_name' => $customerName,
            'cashier_name' => $checkout->cashier ? $checkout->cashier->name : 'N/A',
            'terminal_name' => $terminal ? $terminal->name : 'N/A',
            'lines' => $lines,
            'subtotal' => $checkout->subtotal,
            'discount' => $checkout->discount_total,
            'tax' => $checkout->tax_total,
            'grand_total' => $checkout->grand_total,
            'payment_method' => $paymentMethod,
            'amount_paid' => $amountPaid,
            'payment_breakdown' => $paymentBreakdown, // Task 5.3: Multi-payment breakdown
            'change' => $checkout->change_total > 0 ? $checkout->change_total : max(0, $checkout->paid_total - $checkout->grand_total),
            'outstanding_debt' => $checkout->debt_amount ?? max(0, $checkout->grand_total - $checkout->paid_total),
            'footer_text' => $setting->footer_text ?? 'Terima Kasih',
            'currency_symbol' => $setting->currency ? $setting->currency->symbol : 'Rp',
        ];
    }

    /**
     * Log a print or reprint action for checkouts.
     */
    public function logPrint(int $settingId, int $checkoutId, int $userId, string $type = 'PRINT'): PosReceiptPrintLog
    {
        return PosReceiptPrintLog::query()->create([
            'setting_id' => $settingId,
            'pos_checkout_id' => $checkoutId,
            'print_type' => $type,
            'printed_by' => $userId,
            'printed_at' => Carbon::now(),
        ]);
    }

    /**
     * Log a print or reprint action for transactions.
     */
    public function logTransactionPrint(int $settingId, int $transactionId, int $userId, string $type = 'PRINT'): PosReceiptPrintLog
    {
        return PosReceiptPrintLog::query()->create([
            'setting_id' => $settingId,
            'pos_transaction_id' => $transactionId,
            'print_type' => $type,
            'printed_by' => $userId,
            'printed_at' => Carbon::now(),
        ]);
    }

    /**
     * Get print history for a transaction with user information.
     */
    public function getTransactionPrintHistory(int $transactionId): array
    {
        $logs = PosReceiptPrintLog::query()
            ->where('pos_transaction_id', $transactionId)
            ->with('printer:id,name')
            ->latest('printed_at')
            ->get();

        if ($logs->isEmpty()) {
            return [
                'count' => 0,
                'last_printer' => null,
                'last_printed_at' => null,
            ];
        }

        $lastLog = $logs->first();

        return [
            'count' => $logs->count(),
            'last_printer' => $lastLog->printer?->name ?? 'Unknown',
            'last_printed_at' => $lastLog->printed_at,
            'logs' => $logs,
        ];
    }

    /**
     * Resolve customer-facing bundle composition for each transaction line.
     *
     * Completed split checkouts can persist bundle component context on a
     * different generated Sales document from the parent receipt line. Match
     * those components only to bundled transaction lines and consume each
     * persisted bundle group once so non-bundled rows for the same product do
     * not inherit bundle components.
     */
    public function bundleCompositionByTransactionLine(PosTransaction $transaction): array
    {
        $transaction->loadMissing([
            'lines',
            'completedCheckout.checkoutSales.sale.saleDetails.bundleItems.product',
        ]);

        $saleCompositionGroups = $transaction->completedCheckout instanceof PosCheckout
            ? $this->bundleCompositionGroupsByProduct($transaction->completedCheckout)
            : [];

        $compositionByLine = [];

        foreach ($transaction->lines as $line) {
            $lineId = (int) $line->id;
            $compositionByLine[$lineId] = [];

            if (! $this->isBundleTransactionLine($line)) {
                continue;
            }

            $composition = $this->consumeMatchingBundleComposition(
                $saleCompositionGroups,
                (int) $line->product_id,
                (float) $line->qty
            );

            if (empty($composition)) {
                $composition = $this->compositionFromLineMeta($line->line_meta ?? []);
            }

            $compositionByLine[$lineId] = $composition;
        }

        return $compositionByLine;
    }

    /**
     * @return array<int, array<int, array{parent_qty: float, items: array<int, array{name: string, qty: float}>}>>
     */
    private function bundleCompositionGroupsByProduct(PosCheckout $checkout): array
    {
        $groups = [];

        foreach ($checkout->checkoutSales as $checkoutSale) {
            if (! $checkoutSale->sale) {
                continue;
            }

            foreach ($checkoutSale->sale->saleDetails as $detail) {
                if ($detail->bundleItems->isEmpty()) {
                    continue;
                }

                $items = [];
                foreach ($detail->bundleItems as $bundleItem) {
                    $key = (string) ($bundleItem->product_id ?: $bundleItem->name);

                    if (! isset($items[$key])) {
                        $items[$key] = [
                            'component_key' => $key,
                            'name' => $bundleItem->name ?? $bundleItem->product?->product_name ?? 'Unknown Component',
                            'qty' => 0.0,
                        ];
                    }

                    $items[$key]['qty'] += (float) $bundleItem->quantity;
                }

                $groups[(int) $detail->product_id][] = [
                    'parent_qty' => (float) $detail->quantity,
                    'items' => array_values($items),
                ];
            }
        }

        return $groups;
    }

    /**
     * @param  array<int, array<int, array{parent_qty: float, items: array<int, array{name: string, qty: float}>}>>  $groups
     * @return array<int, array{name: string, qty: float}>
     */
    private function consumeMatchingBundleComposition(array &$groups, int $productId, float $lineQty): array
    {
        if (empty($groups[$productId])) {
            return [];
        }

        $selectedItems = [];
        $currentQty = 0.0;
        $consumedIndices = [];

        foreach ($groups[$productId] as $index => $group) {
            $pQty = (float) $group['parent_qty'];

            // Stop if we already reached the target quantity and this group has its own parent quantity.
            // Component-only groups (p_qty = 0) are still collected if they follow/interleave.
            if ($currentQty >= $lineQty - 0.0001 && $pQty > 0.0001) {
                break;
            }

            $currentQty += $pQty;
            foreach ($group['items'] as $item) {
                $key = (string) ($item['component_key'] ?? $item['name']);
                if (! isset($selectedItems[$key])) {
                    $selectedItems[$key] = [
                        'component_key' => $key,
                        'name' => $item['name'],
                        'qty' => 0.0,
                    ];
                }
                $selectedItems[$key]['qty'] += (float) $item['qty'];
            }
            $consumedIndices[] = $index;
        }

        if (empty($consumedIndices)) {
            return [];
        }

        // Remove consumed groups from the available pool
        foreach (array_reverse($consumedIndices) as $index) {
            unset($groups[$productId][$index]);
        }
        $groups[$productId] = array_values($groups[$productId]);

        return array_values(array_map(
            static fn (array $item): array => [
                'name' => $item['name'],
                'qty' => $item['qty'],
            ],
            $selectedItems
        ));
    }

    private function isBundleTransactionLine($line): bool
    {
        $meta = $line->line_meta ?? [];

        return ! empty($meta['bundle_items'])
            || strtoupper((string) ($meta['price_source'] ?? '')) === 'BUNDLE';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, array{name: string, qty: float}>
     */
    private function compositionFromLineMeta(array $meta): array
    {
        $composition = [];

        foreach (($meta['bundle_items'] ?? []) as $item) {
            $composition[] = [
                'name' => $item['name'] ?? $item['product_name'] ?? 'Unknown Component',
                'qty' => (float) ($item['quantity'] ?? $item['qty'] ?? 0),
            ];
        }

        return $composition;
    }

    /**
     * Get data required to render the receipt view for a draft transaction.
     */
    public function getTransactionReceiptData(PosTransaction $transaction): array
    {
        $transaction->loadMissing([
            'setting.currency',
            'owner',
            'customer',
            'completedCheckout',
            'lines.product.unit',
            'lines.product.baseUnit',
            'lines.conversion.unit',
            'lines.serials',
        ]);

        if (
            $transaction->status === PosTransaction::STATUS_COMPLETED
            && $transaction->completedCheckout instanceof PosCheckout
        ) {
            $receiptData = $this->getReceiptData($transaction->completedCheckout);
            $receiptData['print_history'] = $this->getTransactionPrintHistory($transaction->id);
            $receiptData['is_draft'] = false;

            return $receiptData;
        }

        $setting = $transaction->setting;
        $lines = [];
        $customerName = $transaction->customer?->display_name ?? '-';

        foreach ($transaction->lines as $line) {
            $unitBreakdown = null;
            $unitName = null;
            $factor = 1.0;

            $priceSource = $line->line_meta['price_source'] ?? 'BASE';
            $breakdown = $line->line_meta['breakdown'] ?? null;

            if ($priceSource === 'PACKED' && is_array($breakdown)) {
                $unitBreakdown = $this->buildPackedUnitBreakdown($breakdown, $line);
            } elseif ($line->conversion && $line->conversion->unit) {
                $unitName = $line->conversion->unit->short_name ?? $line->conversion->unit->name;
                $factor = (float)($line->conversion->conversion_factor ?? 1);

                if ($unitName) {
                    $pricePerUnit = $line->unit_price / $factor;
                    $unitBreakdown = sprintf(
                        "%s %s(S) @ %s",
                        (float)$line->qty,
                        $unitName,
                        format_currency($pricePerUnit)
                    );
                }
            } elseif ($line->product) {
                $unitName = $line->product->unit->short_name
                            ?? $line->product->unit->name
                            ?? $line->product->baseUnit->short_name
                            ?? $line->product->baseUnit->name
                            ?? $line->product->product_unit;
                $factor = 1.0;

                if ($unitName) {
                    $pricePerUnit = $line->unit_price / $factor;
                    $unitBreakdown = sprintf(
                        "%s %s(S) @ %s",
                        (float)$line->qty,
                        $unitName,
                        format_currency($pricePerUnit)
                    );
                }
            }

            // Simple subtotal calculation for receipt display
            // line_meta['line_total'] is persisted in Rupiah by PosCartTotalsCalculator — no /100 needed.
            if (isset($line->line_meta['line_total'])) {
                $lineGross = (float) $line->line_meta['line_total'];
            } else {
                $lineGross = $line->qty * $line->unit_price;
            }
            $lineSubtotal = $lineGross - (float)($line->line_discount_value ?? 0);

            // Task 1.3: Draft/loaded transaction bundle context
            $composition = [];
            if (!empty($line->line_meta['bundle_items'])) {
                foreach ($line->line_meta['bundle_items'] as $item) {
                    $composition[] = [
                        'name' => $item['name'] ?? $item['product_name'] ?? 'Unknown Component',
                        'qty' => (float)($item['quantity'] ?? $item['qty'] ?? 0),
                    ];
                }
            }

            $lines[] = [
                'product_name' => $line->product_name_snapshot,
                'qty' => (float)$line->qty,
                'price' => (float)$line->unit_price,
                'discount' => (float)($line->line_discount_value ?? 0),
                'sub_total' => $lineSubtotal,
                'unit_breakdown' => $unitBreakdown,
                'bundle_composition' => $composition,
                'assigned_serials' => $line->serials->count() > 0 
                    ? $line->serials->pluck('serial_number')->toArray() 
                    : ($line->line_meta['assigned_serials'] ?? []),
                'price_source' => $line->line_meta['price_source'] ?? 'BASE',
                'breakdown' => $line->line_meta['breakdown'] ?? null,
            ];
        }

        $totals = $transaction->snapshot_totals ?? [];
        $printHistory = $this->getTransactionPrintHistory($transaction->id);

        return [
            'business_name' => $setting->company_name ?? 'Business',
            'business_address' => $setting->company_address,
            'business_phone' => $setting->company_phone,
            'business_email' => $setting->company_email,
            'receipt_number' => $transaction->code,
            'date' => $transaction->created_at->format('d-m-Y H:i'), // Consistent transaction time
            'customer_name' => $customerName,
            'cashier_name' => $transaction->owner ? $transaction->owner->name : 'N/A',
            'terminal_name' => 'N/A',
            'lines' => $lines,
            'subtotal' => (float) ($totals['subtotal'] ?? $totals['total_subtotal'] ?? 0),
            'discount' => (float) ($totals['discount_total'] ?? $totals['total_discount'] ?? 0),
            'tax' => (float) ($totals['tax_total'] ?? $totals['total_tax'] ?? 0),
            'grand_total' => (float) ($totals['grand_total'] ?? $totals['total_grand_total'] ?? 0),
            'payment_method' => '-',
            'amount_paid' => 0,
            'payment_breakdown' => [],
            'change' => 0,
            'footer_text' => $setting->footer_text ?? 'Terima Kasih',
            'currency_symbol' => $setting->currency ? $setting->currency->symbol : 'Rp',
            'is_draft' => in_array($transaction->status, [PosTransaction::STATUS_DRAFT, PosTransaction::STATUS_LOADED], true),
            'print_history' => $printHistory,
        ];
    }

    /**
     * Build packed unit breakdown presentation (Task 3.3 & 3.4).
     */
    private function buildPackedUnitBreakdown(array $breakdown, $line): array
    {
        $unitBreakdown = [];
        
        $conversionLabel = $breakdown['conversion_unit_label'] ?? null;
        $baseLabel = $breakdown['base_unit_label'] ?? null;
        
        if (!$conversionLabel || !$baseLabel) {
            $fallbackConv = $line->conversion 
                ?? \Modules\Product\Entities\ProductUnitConversion::where('product_id', $line->product_id)->with(['unit', 'baseUnit'])->first();
                
            if (!$conversionLabel) {
                $conversionLabel = $fallbackConv?->unit?->short_name 
                                ?? $fallbackConv?->unit?->name 
                                ?? 'Dus';
            }
            if (!$baseLabel) {
                $baseLabel = $fallbackConv?->baseUnit?->short_name 
                            ?? $fallbackConv?->baseUnit?->name 
                            ?? $line->product?->unit?->short_name 
                            ?? $line->product?->unit?->name 
                            ?? 'Pcs';
            }
        }

        if (($breakdown['box_count'] ?? 0) > 0) {
            $boxPriceRupiah = (float)($breakdown['box_price_applied'] ?? 0) / 100;
            $unitBreakdown[] = sprintf("%d %s @ %s", $breakdown['box_count'], $conversionLabel, format_currency($boxPriceRupiah));
        }
        if (($breakdown['loose_count'] ?? 0) > 0) {
            $loosePriceRupiah = (float)($breakdown['loose_price_applied'] ?? 0) / 100;
            $unitBreakdown[] = sprintf("%d %s @ %s", $breakdown['loose_count'], $baseLabel, format_currency($loosePriceRupiah));
        }
        
        return $unitBreakdown;
    }
}
