<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnLookupService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSReturnLookupPerformanceTest extends PosTransactionFeatureTestCase
{
    protected PosReturnLookupService $service;

    protected $setting;

    protected $user;

    protected $terminal;

    protected $location;

    protected $session;

    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnLookupService::class);
        $this->setting = $this->createSetting('POS Return Lookup Performance Test');
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Lookup Performance User', [
            'pos.access',
        ]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function it_keeps_transaction_code_lookup_within_a_two_query_budget_after_context_is_warm(): void
    {
        [$transaction] = $this->createLookupFixture('code');

        $this->warmLookupContext();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service->lookup($transaction->code);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($transaction->id, $result['pos_transaction_id'] ?? null);
        $this->assertLessThanOrEqual(2, count($queries), json_encode(array_column($queries, 'query')));
    }

    /** @test */
    public function it_keeps_receipt_number_lookup_within_a_two_query_budget_after_context_is_warm(): void
    {
        [, $checkout] = $this->createLookupFixture('receipt');

        $this->warmLookupContext();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service->lookup($checkout->receipt_number);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($checkout->id, $result['pos_checkout_id'] ?? null);
        $this->assertLessThanOrEqual(2, count($queries), json_encode(array_column($queries, 'query')));
    }

    /**
     * @return array{0: PosTransaction, 1: PosCheckout}
     */
    protected function createLookupFixture(string $suffix): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PERF-' . $suffix . '-' . uniqid(),
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
            'grand_total' => 1000,
            'receipt_number' => 'RCP-PERF-' . $suffix . '-' . uniqid(),
            'idempotency_key' => 'IDEM-PERF-' . $suffix . '-' . uniqid(),
            'payload_hash' => 'HASH-PERF-' . $suffix . '-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return [$transaction, $checkout];
    }

    protected function warmLookupContext(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);
        settings();
    }
}