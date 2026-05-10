<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Services\PosReturnLookupService;

class POSReturnLookupTest extends PosTransactionFeatureTestCase
{
    protected $user;

    protected $lookupService;

    protected $setting;

    protected $terminal;

    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Lookup Test');
        [$this->terminal] = $this->createTerminalWithLocation($this->setting);
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Lookup User', [
            'pos.access',
        ]);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
        $this->actingAsInSetting($this->user, $this->setting);
        $this->lookupService = app(PosReturnLookupService::class);
    }

    /** @test */
    public function it_can_lookup_transaction_by_code(): void
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-LOOKUP-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-LOOKUP-' . uniqid(),
            'idempotency_key' => 'IDEM-LOOKUP-' . uniqid(),
            'payload_hash' => 'HASH-LOOKUP-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $result = $this->lookupService->lookup($transaction->code);

        $this->assertSame($transaction->id, $result['pos_transaction_id'] ?? null);
        $this->assertSame($checkout->id, $result['pos_checkout_id'] ?? null);
        $this->assertSame($transaction->code, $result['transaction_code'] ?? null);
        $this->assertSame($checkout->receipt_number, $result['receipt_number'] ?? null);
    }

    /** @test */
    public function it_blocks_lookup_of_non_existent_transaction(): void
    {
        $result = $this->lookupService->lookup('INVALID-CODE');
        $this->assertNull($result);
    }
}
