<?php

namespace Modules\Pos\Services;

use Carbon\Carbon;
use Modules\Pos\Entities\PosCheckout;
use Modules\Setting\Entities\Setting;

class PosReceiptNumberGenerator
{
    /**
     * Generate a unique receipt number for a POS checkout.
     */
    public function generate(int $settingId): string
    {
        $setting = Setting::find($settingId);
        
        $documentPrefix = $setting?->document_prefix ?: '';
        $posPrefix = $setting?->pos_receipt_prefix ?: 'RCP';
        
        $prefix = $documentPrefix ? "{$documentPrefix}-{$posPrefix}" : $posPrefix;

        $now = Carbon::now();
        $year = $now->year;
        $month = str_pad((string)$now->month, 2, '0', STR_PAD_LEFT);
        
        $lastReceipt = PosCheckout::query()
            ->where('setting_id', $settingId)
            ->whereNotNull('receipt_number')
            ->where('receipt_number', 'like', "{$prefix}-{$year}-{$month}-%")
            ->orderBy('receipt_number', 'desc')
            ->value('receipt_number');
            
        $nextNumber = 1;
        if ($lastReceipt) {
            $parts = explode('-', $lastReceipt);
            $lastCounter = (int) end($parts);
            $nextNumber = $lastCounter + 1;
        }

        $formattedNumber = str_pad((string)$nextNumber, 5, '0', STR_PAD_LEFT);
        
        return "{$prefix}-{$year}-{$month}-{$formattedNumber}";
    }
}
