<?php

namespace Modules\Pos\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Modules\Pos\Entities\PosTransaction;
use Modules\Setting\Entities\Setting;

class PosTransactionCodeGenerator
{
    /**
     * Generate a unique transaction code for the given setting.
     * Format: TXN-YYYY-MM-XXXX or [PREFIX]-TXN-YYYY-MM-XXXX if setting has document prefix.
     */
    public function generate(int $settingId): string
    {
        $setting = Setting::find($settingId);
        $documentPrefix = $setting?->document_prefix ?: '';
        $posPrefix = 'TXN';
        $prefix = $documentPrefix ? "{$documentPrefix}-{$posPrefix}" : $posPrefix;

        $now = Carbon::now();
        $year = $now->year;
        $month = str_pad((string) $now->month, 2, '0', STR_PAD_LEFT);

        // Find the highest counter for this prefix/year/month
        $lastCode = PosTransaction::query()
            ->where('setting_id', $settingId)
            ->where('code', 'like', "{$prefix}-{$year}-{$month}-%")
            ->orderByDesc('code')
            ->value('code');

        $counter = 1;
        if ($lastCode) {
            $parts = explode('-', $lastCode);
            $lastCounter = (int) end($parts);
            $counter = $lastCounter + 1;
        }

        $code = sprintf('%s-%d-%s-%04d', $prefix, $year, $month, $counter);

        // Verify uniqueness (race condition guard)
        $attempts = 0;
        $maxAttempts = 5;

        while ($attempts < $maxAttempts) {
            try {
                // Check if code already exists
                if (!PosTransaction::where('setting_id', $settingId)
                    ->where('code', $code)
                    ->exists()) {
                    return $code;
                }

                // Code exists, retry with incremented counter
                $counter++;
                $code = sprintf('%s-%d-%s-%04d', $prefix, $year, $month, $counter);
                $attempts++;
            } catch (QueryException $e) {
                // Retry on database errors
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not generate unique transaction code after maximum attempts');
    }
}
