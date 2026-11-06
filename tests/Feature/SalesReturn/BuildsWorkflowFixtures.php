<?php

namespace Tests\Feature\SalesReturn;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;

/**
 * Minimal synthetic fixtures for workflow-ownership tests. Foreign keys are off
 * here on purpose: these tests only exercise ownership classification and guards.
 */
trait BuildsWorkflowFixtures
{
    protected function disableForeignKeys(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    protected function makeSale(string $suffix = ''): Sale
    {
        return Sale::query()->create([
            'setting_id' => 1,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-WF-' . $suffix . uniqid(),
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0,
            'shipping_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 1000, 'due_amount' => 0,
            'status' => 'DISPATCHED', 'payment_status' => 'PAID', 'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);
    }

    protected function makeReturn(Sale $sale, array $overrides = []): SaleReturn
    {
        return SaleReturn::query()->create(array_merge([
            'setting_id' => 1,
            'sale_id' => $sale->id,
            'customer_id' => 1,
            'customer_name' => 'Walk-in',
            'date' => now()->toDateString(),
            'reference' => 'SR-WF-' . uniqid(),
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0,
            'shipping_amount' => 0, 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100,
            'status' => 'PENDING APPROVAL', 'approval_status' => 'PENDING',
            'payment_status' => 'UNPAID', 'payment_method' => 'CASH', 'return_type' => 'Cash Return',
        ], $overrides));
    }

    protected function linkLegacyCheckout(Sale $sale): void
    {
        DB::table('pos_checkouts')->insert([
            'setting_id' => 1, 'sale_id' => $sale->id, 'status' => 'POSTED', 'grand_total' => 1000,
            'receipt_number' => 'RCP-' . uniqid(), 'idempotency_key' => 'I-' . uniqid(), 'payload_hash' => 'H-' . uniqid(),
            'pos_transaction_id' => 1, 'pos_session_id' => 1, 'terminal_id' => 1, 'cashier_user_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function linkSplitCheckoutSale(Sale $sale): void
    {
        DB::table('pos_checkout_sales')->insert([
            'pos_checkout_id' => 1, 'sale_id' => $sale->id, 'source_setting_id' => 1, 'source_location_id' => 1,
            'grand_total' => 1000, 'split_key' => 'S-' . uniqid(), 'tax_bucket' => 'NON_TAX',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
