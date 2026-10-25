<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturnBalanceRepairAudit;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;

class PosRepairReturnBalancesCommandTest extends PosTransactionFeatureTestCase
{
    protected $setting;
    protected $setting2;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('Command Test Store');
        $this->setting2 = $this->createSetting('Second Store');

        $this->user = $this->createUserForSetting($this->setting, 'Cashier User', ['pos.access']);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    public function test_it_fails_when_business_setting_scope_is_missing(): void
    {
        $this->artisan('pos:repair-return-balances', [
            '--transaction' => [9218],
        ])
            ->expectsOutputToContain('A valid business setting ID must be explicitly specified with --setting=<id>.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_business_setting_scope_is_invalid(): void
    {
        $this->artisan('pos:repair-return-balances', [
            '--setting' => 999999,
            '--transaction' => [9218],
        ])
            ->expectsOutputToContain('Business setting ID 999999 does not exist')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_no_transaction_ids_are_specified(): void
    {
        $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting->id,
        ])
            ->expectsOutputToContain('At least one explicit transaction ID or code must be specified')
            ->assertExitCode(1);
    }

    public function test_it_reports_blocked_when_business_setting_mismatches_transaction(): void
    {
        $fixture = $this->createDriftFixture();

        $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting2->id,
            '--transaction' => [$fixture['transaction']->id],
        ])
            ->expectsOutputToContain('[BLOCKED]')
            ->expectsOutputToContain('bukan milik unit usaha setting')
            ->assertExitCode(1);
    }

    public function test_it_reports_unchanged_when_transaction_has_no_drift(): void
    {
        $fixture = $this->createDriftFixture(hasDrift: false);

        $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting->id,
            '--transaction' => [$fixture['transaction']->id],
        ])
            ->expectsOutputToContain('[UNCHANGED]')
            ->assertExitCode(0);
    }

    public function test_it_performs_zero_dry_run_writes_on_preview(): void
    {
        $fixture = $this->createDriftFixture(hasDrift: true);

        $initialSaleTotal = (float) $fixture['sale']->fresh()->total_amount;
        $this->assertEquals(804999.60, $initialSaleTotal);
        $this->assertSame(0, PosReturnBalanceRepairAudit::count());

        $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting->id,
            '--transaction' => [$fixture['transaction']->id],
        ])
            ->expectsOutputToContain('[REPAIRABLE]')
            ->expectsOutputToContain('0.40')
            ->assertExitCode(0);

        // Verify zero writes occurred
        $this->assertEquals(804999.60, (float) $fixture['sale']->fresh()->total_amount);
        $this->assertEquals(429999.60, (float) $fixture['sale']->fresh()->due_amount);
        $this->assertSame(0, PosReturnBalanceRepairAudit::count());
    }

    public function test_it_supports_repeatable_explicit_transaction_ids(): void
    {
        $fixture1 = $this->createDriftFixture(hasDrift: true, code: 'TXN-REP-1');
        $fixture2 = $this->createDriftFixture(hasDrift: false, code: 'TXN-REP-2');

        $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting->id,
            '--transaction' => [$fixture1['transaction']->id, $fixture2['transaction']->id],
        ])
            ->expectsOutputToContain("Evaluating POS Transaction: {$fixture1['transaction']->id}")
            ->expectsOutputToContain('[REPAIRABLE]')
            ->expectsOutputToContain("Evaluating POS Transaction: {$fixture2['transaction']->id}")
            ->expectsOutputToContain('[UNCHANGED]')
            ->assertExitCode(0);
    }

    protected function createDriftFixture(bool $hasDrift = true, string $code = 'TXN-CMD-DRIFT'): array
    {
        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook',
            'product_code' => 'NB-' . $code,
            'sale_price' => 15000,
        ]);

        $marker = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-' . $code,
            'sale_price' => 6583.33,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 805000,
            'receipt_number' => 'RCP-' . $code,
            'idempotency_key' => 'IDEM-' . $code,
            'payload_hash' => 'HASH-' . $code,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $saleTotal = $hasDrift ? 804999.60 : 805000.00;
        $dueTotal = $hasDrift ? 429999.60 : 430000.00;

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => $saleTotal,
            'paid_amount' => 375000,
            'due_amount' => $dueTotal,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $code,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 375000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-' . $code,
            'payment_method' => 'CASH',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 805000,
            'split_key' => 'SPLIT-' . $code,
            'tax_bucket' => 'NON_TAX',
        ]);

        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => $notebook->product_name,
            'qty' => 1,
            'unit_price' => 15000,
        ]);

        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $marker->id,
            'product_name_snapshot' => $marker->product_name,
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_meta' => [
                'line_total_minor' => 79000000,
                'line_gross_minor' => 79000000,
            ],
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $notebook->id,
            'product_name' => 'Notebook',
            'product_code' => 'NB-' . $code,
            'pos_transaction_line_id' => $line1->id,
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $marker->id,
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-' . $code,
            'pos_transaction_line_id' => $line2->id,
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        return [
            'transaction' => $transaction,
            'checkout' => $checkout,
            'sale' => $sale,
        ];
    }
}
