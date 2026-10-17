<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionResetToDraftTest extends PosTransactionFeatureTestCase
{
    private const CASHIER_PERMISSIONS = [
        'pos.access',
        'pos.sell',
        'pos.sessions.open',
        'pos.transactions.view',
        'pos.transactions.save',
        'pos.transactions.load',
    ];

    /**
     * @return array{0: \App\Models\Setting, 1: \App\Models\User, 2: int, 3: PosSession}
     */
    private function createLoadedTransaction(string $suffix): array
    {
        $setting = $this->createSetting('BIZ POS RESET ' . $suffix);
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS RESET CASHIER ' . $suffix, self::CASHIER_PERMISSIONS);
        $session = $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201)
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED);

        return [$setting, $user, $transactionId, $session];
    }

    private function savedState(int $transactionId): array
    {
        $transaction = PosTransaction::query()->with('lines')->findOrFail($transactionId);

        return [
            'code' => $transaction->code,
            'customer_id' => $transaction->customer_id,
            'note' => $transaction->note,
            'owner_user_id' => $transaction->owner_user_id,
            'snapshot_totals' => $transaction->snapshot_totals,
            'snapshot_hash' => $transaction->snapshot_hash,
            'completed_checkout_id' => $transaction->completed_checkout_id,
            'lines' => $transaction->lines->map(fn ($line) => collect($line->getAttributes())
                ->except(['created_at', 'updated_at'])
                ->all())->all(),
        ];
    }

    public function test_reset_returns_loaded_transaction_to_draft_preserving_saved_data(): void
    {
        [, , $transactionId] = $this->createLoadedTransaction('OK');
        $before = $this->savedState($transactionId);

        $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.id', $transactionId)
            ->assertJsonPath('transaction.code', $before['code'])
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_DRAFT)
            ->assertJsonPath('message', 'Transaksi dikembalikan ke draf.');

        $this->assertSame(PosTransaction::STATUS_DRAFT, PosTransaction::findOrFail($transactionId)->status);
        $this->assertSame($before, $this->savedState($transactionId));
    }

    public function test_repeated_reset_is_rejected(): void
    {
        [, , $transactionId] = $this->createLoadedTransaction('REPEAT');

        $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))->assertOk();
        $before = $this->savedState($transactionId);

        $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_LOADED');

        $this->assertSame($before, $this->savedState($transactionId));
    }

    public function test_reset_rejects_completed_and_cancelled_transactions(): void
    {
        [, , $transactionId] = $this->createLoadedTransaction('STATUS');

        foreach ([PosTransaction::STATUS_COMPLETED, PosTransaction::STATUS_CANCELLED] as $status) {
            PosTransaction::whereKey($transactionId)->update(['status' => $status]);

            $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))
                ->assertStatus(422)
                ->assertJsonPath('code', 'TRANSACTION_NOT_LOADED');

            $this->assertSame($status, PosTransaction::findOrFail($transactionId)->status);
        }
    }

    public function test_reset_succeeds_without_active_pos_session(): void
    {
        [, , $transactionId, $session] = $this->createLoadedTransaction('NOSESSION');

        $session->update(['status' => PosSession::STATUS_CLOSED, 'active_marker' => null]);

        $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_DRAFT);
    }

    public function test_reset_requires_load_permission(): void
    {
        [$setting, , $transactionId] = $this->createLoadedTransaction('NOAUTH');

        $viewer = $this->createUserForSetting($setting, 'POS RESET VIEWER', [
            'pos.access',
            'pos.sell',
            'pos.transactions.view',
        ]);
        $this->actingAsInSetting($viewer, $setting);

        $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))
            ->assertForbidden();

        $this->assertSame(PosTransaction::STATUS_LOADED, PosTransaction::findOrFail($transactionId)->status);
    }

    public function test_reset_rejects_transaction_from_another_business(): void
    {
        [, , $transactionId] = $this->createLoadedTransaction('FOREIGN');

        $otherSetting = $this->createSetting('BIZ POS RESET OTHER');
        $otherUser = $this->createUserForSetting($otherSetting, 'POS RESET OTHER CASHIER', self::CASHIER_PERMISSIONS);
        $this->actingAsInSetting($otherUser, $otherSetting);

        $response = $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]));
        $this->assertContains($response->status(), [403, 404]);

        $this->assertSame(PosTransaction::STATUS_LOADED, PosTransaction::findOrFail($transactionId)->status);
    }

    public function test_reset_rejected_when_transactions_disabled(): void
    {
        [$setting, , $transactionId] = $this->createLoadedTransaction('DISABLED');

        $setting->update(['pos_transactions_enabled' => false]);

        $response = $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]));
        $this->assertContains($response->status(), [403, 404]);

        $this->assertSame(PosTransaction::STATUS_LOADED, PosTransaction::findOrFail($transactionId)->status);
    }

    public function test_recovered_draft_loads_again_and_leaves_abandoned_cart_untouched(): void
    {
        [$setting, $user, $transactionId] = $this->createLoadedTransaction('RELOAD');
        $before = $this->savedState($transactionId);

        $abandonedCart = $this->getJson(route('pos.sell.cart.show'))->assertOk();
        $this->assertSame($transactionId, (int) $abandonedCart->json('cart_snapshot.active_transaction_id'));

        $this->postJson(route('pos.transactions.reset-to-draft', ['transaction' => $transactionId]))
            ->assertOk();

        // Recovery neither clears nor saves the abandoned cart.
        $cartAfter = $this->getJson(route('pos.sell.cart.show'))->assertOk();
        $this->assertSame($abandonedCart->json('cart_snapshot'), $cartAfter->json('cart_snapshot'));

        // Another cashier with an empty cart and an active session loads the recovered draft normally.
        [$terminal] = $this->createTerminalWithLocation($setting);
        $other = $this->createUserForSetting($setting, 'POS RESET RELOADER', self::CASHIER_PERMISSIONS);
        $this->openSession($setting, $terminal, $other);
        $this->actingAsInSetting($other, $setting);

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.id', $transactionId)
            ->assertJsonPath('transaction.code', $before['code'])
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED)
            ->assertJsonPath('cart_snapshot.meta.line_count', 1)
            ->assertJsonPath('cart_snapshot.active_transaction_id', $transactionId);

        $this->assertSame($before, $this->savedState($transactionId));
    }
}
