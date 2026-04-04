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
            'paymentMethod',
            'sale.saleDetails.product.unit',
            'sale.saleDetails.product.baseUnit',
            'transaction.lines.conversion.unit', // Task 1.1: Load unit breakdown details
            'transaction.lines.product.unit', // Load base unit details
            'transaction.lines.product.baseUnit', // Load base unit details
            'payments.paymentMethod', // Task 5.3: Load multi-payment details
        ]);

        $setting = $checkout->setting;
        $sale = $checkout->sale;
        $session = $checkout->session;
        $terminal = $checkout->terminal;

        $lines = [];
        // Task 1.2 & 1.3: Prefer PosTransactionLine for accurate unit/conversion breakdown
        if ($checkout->transaction && $checkout->transaction->lines->count() > 0) {
            foreach ($checkout->transaction->lines as $line) {
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

                if ($unitName) {
                    $pricePerUnit = $line->unit_price / $factor;
                    $unitBreakdown = sprintf(
                        "%s %s(S) @ %s",
                        (float)$line->qty,
                        $unitName,
                        format_currency($pricePerUnit)
                    );
                }

                // Simple subtotal calculation for receipt display
                $lineSubtotal = ($line->qty * $line->unit_price) - (float)($line->line_discount_value ?? 0);

                $lines[] = [
                    'product_name' => $line->product_name_snapshot,
                    'qty' => (float)$line->qty,
                    'price' => (float)$line->unit_price,
                    'discount' => (float)($line->line_discount_value ?? 0),
                    'sub_total' => $lineSubtotal,
                    'unit_breakdown' => $unitBreakdown,
                ];
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
            'business_email' => $setting->company_email, // Task 1.5: Add business email
            'receipt_number' => $checkout->receipt_number,
            'date' => $checkout->finalized_at ? $checkout->finalized_at->format('d-m-Y H:i') : now()->format('d-m-Y H:i'),
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
            'footer_text' => $setting->footer_text ?? 'Terima Kasih',
            'currency_symbol' => $setting->currency ? $setting->currency->symbol : 'Rp',
        ];
    }

    /**
     * Log a print or reprint action.
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
     * Get data required to render the receipt view for a draft transaction.
     */
    public function getTransactionReceiptData(PosTransaction $transaction): array
    {
        $transaction->loadMissing([
            'setting.currency',
            'owner',
            'customer',
            'lines.product.unit',
            'lines.product.baseUnit',
            'lines.conversion.unit',
        ]);

        $setting = $transaction->setting;
        $lines = [];

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

            if ($unitName) {
                $pricePerUnit = $line->unit_price / $factor;
                $unitBreakdown = sprintf(
                    "%s %s(S) @ %s",
                    (float)$line->qty,
                    $unitName,
                    format_currency($pricePerUnit)
                );
            }

            // Simple subtotal calculation for receipt display
            $lineSubtotal = ($line->qty * $line->unit_price) - (float)($line->line_discount_value ?? 0);

            $lines[] = [
                'product_name' => $line->product_name_snapshot,
                'qty' => (float)$line->qty,
                'price' => (float)$line->unit_price,
                'discount' => (float)($line->line_discount_value ?? 0),
                'sub_total' => $lineSubtotal,
                'unit_breakdown' => $unitBreakdown,
            ];
        }

        $totals = $transaction->snapshot_totals ?? [];

        return [
            'business_name' => $setting->company_name ?? 'Business',
            'business_address' => $setting->company_address,
            'business_phone' => $setting->company_phone,
            'business_email' => $setting->company_email,
            'receipt_number' => $transaction->code,
            'date' => $transaction->created_at->format('d-m-Y H:i'),
            'cashier_name' => $transaction->owner ? $transaction->owner->name : 'N/A',
            'terminal_name' => 'N/A',
            'lines' => $lines,
            'subtotal' => (float)($totals['total_subtotal'] ?? 0),
            'discount' => (float)($totals['total_discount'] ?? 0),
            'tax' => (float)($totals['total_tax'] ?? 0),
            'grand_total' => (float)($totals['total_grand_total'] ?? 0),
            'payment_method' => '-',
            'amount_paid' => 0,
            'payment_breakdown' => [],
            'change' => 0,
            'footer_text' => $setting->footer_text ?? 'Terima Kasih',
            'currency_symbol' => $setting->currency ? $setting->currency->symbol : 'Rp',
            'is_draft' => true,
        ];
    }
}
