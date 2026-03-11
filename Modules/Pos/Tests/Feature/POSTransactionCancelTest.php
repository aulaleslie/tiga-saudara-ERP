<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionCancelTest extends PosTransactionFeatureTestCase
{
    public function test_creator_can_cancel_own_draft_transaction(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL OWNER');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN CANCEL OWNER USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_CANCELLED);

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_CANCELLED,
        ]);
    }

    public function test_other_user_without_edit_any_cannot_cancel_transaction(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL FORBIDDEN');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);

        $owner = $this->createUserForSetting($setting, 'POS TXN CANCEL OWNER 2', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $otherUser = $this->createUserForSetting($setting, 'POS TXN CANCEL OTHER USER', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $this->openSession($setting, $terminal, $owner);
        $this->actingAsInSetting($owner, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-CN-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->actingAsInSetting($otherUser, $setting);
        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'EDIT_FORBIDDEN');
    }

    public function test_user_with_edit_any_can_cancel_other_users_transaction(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL EDIT ANY');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);

        $owner = $this->createUserForSetting($setting, 'POS TXN CANCEL OWNER 3', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $admin = $this->createUserForSetting($setting, 'POS TXN CANCEL ADMIN', [
            'pos.access',
            'pos.transactions.view',
            'pos.transactions.edit.any',
        ]);

        $this->openSession($setting, $terminal, $owner);
        $this->actingAsInSetting($owner, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-CN-002']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->actingAsInSetting($admin, $setting);
        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_CANCELLED);
    }

    public function test_completed_transaction_cannot_be_cancelled(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL COMPLETED');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN CANCEL COMPLETED USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-CN-003']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        PosTransaction::whereKey($transactionId)->update(['status' => PosTransaction::STATUS_COMPLETED]);

        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_CANCELLABLE');
    }
}
