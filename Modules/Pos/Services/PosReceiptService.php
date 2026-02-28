<?php

namespace Modules\Pos\Services;

use Carbon\Carbon;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReceiptPrintLog;

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
            'sale.saleDetails',
        ]);
        
        $setting = $checkout->setting;
        $sale = $checkout->sale;
        $session = $checkout->session;
        $terminal = $checkout->terminal;

        $lines = [];
        if ($sale) {
            foreach ($sale->saleDetails as $detail) {
                $lines[] = [
                    'product_name' => $detail->product_name,
                    'qty' => $detail->quantity,
                    'price' => $detail->unit_price,
                    'discount' => ($detail->product_discount_type === 'percentage') 
                                ? ($detail->unit_price * ($detail->product_discount_amount / 100))
                                : $detail->product_discount_amount,
                    'sub_total' => $detail->sub_total,
                ];
            }
        }

        return [
            'business_name' => $setting->company_name ?? 'Business',
            'business_address' => $setting->company_address,
            'business_phone' => $setting->company_phone,
            'receipt_number' => $checkout->receipt_number,
            'date' => $checkout->finalized_at ? $checkout->finalized_at->format('d-m-Y H:i') : now()->format('d-m-Y H:i'),
            'cashier_name' => $checkout->cashier ? $checkout->cashier->name : 'N/A',
            'terminal_name' => $terminal ? $terminal->name : 'N/A',
            'lines' => $lines,
            'subtotal' => $checkout->subtotal,
            'discount' => $checkout->discount_total,
            'tax' => $checkout->tax_total,
            'grand_total' => $checkout->grand_total,
            'payment_method' => strtoupper($checkout->payment_method_code),
            'amount_paid' => $checkout->paid_total,
            'change' => $checkout->change_total,
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
}
