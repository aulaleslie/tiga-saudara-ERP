<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Process all pos_checkouts with null payment_method_id
        DB::table('pos_checkouts')
            ->whereNull('payment_method_id')
            ->orderBy('id')
            ->lazy(100)
            ->each(function ($checkout) {
                $methodCode = strtolower($checkout->payment_method_code);
                $paymentMethodId = $this->resolvePaymentMethodId($methodCode);

                if ($paymentMethodId) {
                    DB::table('pos_checkouts')
                        ->where('id', $checkout->id)
                        ->update(['payment_method_id' => $paymentMethodId]);
                }
            });
    }

    /**
     * Resolve payment method ID from code using same logic as posting adapter.
     */
    private function resolvePaymentMethodId(string $methodCode): ?int
    {
        if ($methodCode === 'cash') {
            // Primary: exact match via is_cash flag
            $cashMethodId = DB::table('payment_methods')
                ->where('is_cash', true)
                ->orderBy('id')
                ->value('id');
            if ($cashMethodId) {
                return (int) $cashMethodId;
            }

            // Secondary: name contains 'cash'
            $fallbackCashId = DB::table('payment_methods')
                ->whereRaw('LOWER(name) LIKE ?', ['%cash%'])
                ->orderBy('id')
                ->value('id');
            if ($fallbackCashId) {
                return (int) $fallbackCashId;
            }
        }

        if ($methodCode === 'transfer') {
            $transferMethodId = DB::table('payment_methods')
                ->whereRaw('LOWER(name) LIKE ?', ['%transfer%'])
                ->orderBy('id')
                ->value('id');
            if ($transferMethodId) {
                return (int) $transferMethodId;
            }
        }

        if ($methodCode === 'qris') {
            $qrisMethodId = DB::table('payment_methods')
                ->whereRaw('LOWER(name) LIKE ?', ['%qris%'])
                ->orderBy('id')
                ->value('id');
            if ($qrisMethodId) {
                return (int) $qrisMethodId;
            }
        }

        return null;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set all payment_method_id to null (data-only rollback, safe)
        DB::table('pos_checkouts')->update(['payment_method_id' => null]);
    }
};
