<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionCancelTest extends PosTransactionFeatureTestCase
{
    public function test_user_with_direct_void_permission_can_cancel_mutable_transaction(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL VOID');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN CANCEL VOID USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
            'pos.void',
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

    public function test_cancel_without_direct_void_permission_requires_approval(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL APPROVAL REQUIRED');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN CANCEL NEED APPROVAL', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-CN-REQ-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_DRAFT,
        ]);
    }

    public function test_approved_token_allows_transaction_cancellation(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL TOKEN');
        [$cashierTerminal, $location] = $this->createTerminalWithLocation($setting);
        [$supervisorTerminal] = $this->createTerminalWithLocation($setting);

        $cashier = $this->createUserForSetting($setting, 'POS TXN CASHIER TOKEN', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $supervisor = $this->createUserForSetting($setting, 'POS TXN SUPERVISOR TOKEN', [
            'pos.access',
            'pos.supervisor.approval',
            'pos.transactions.view',
            'pos.void',
        ]);

        $this->openSession($setting, $cashierTerminal, $cashier);
        $this->openSession($setting, $supervisorTerminal, $supervisor);
        $this->actingAsInSetting($cashier, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-CN-TOKEN-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $requestId = (int) $this->postJson(route('pos.sell.approval-requests.store'), [
            'action_type' => 'TRANSACTION_CANCEL',
            'target_type' => 'pos_transaction',
            'target_id' => $transactionId,
            'payload' => [],
        ])->assertStatus(201)->json('request_id');

        $this->actingAsInSetting($supervisor, $setting);
        $this->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]), [
            'note' => 'Disetujui untuk pembatalan draft.',
        ])->assertOk();

        $this->actingAsInSetting($cashier, $setting);
        $token = (string) $this->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]))
            ->assertOk()
            ->json('approval_token');

        $this->assertNotSame('', $token);

        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]), [
            'approval_token' => $token,
        ])->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_CANCELLED);
    }

    public function test_completed_transaction_cannot_be_cancelled_even_with_void_permission(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL COMPLETED');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN CANCEL COMPLETED USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
            'pos.void',
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
    public function test_loaded_transaction_cannot_be_cancelled(): void
    {
        $setting = $this->createSetting('BIZ POS TXN CANCEL LOADED');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN CANCEL LOADED USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.transactions.view',
            'pos.void',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-CN-LOADED-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        // Load it
        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        // Attempt cancel
        $this->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_CANCELLABLE');

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_LOADED,
        ]);
    }
}
