<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionLoadTest extends PosTransactionFeatureTestCase
{
    public function test_cashier_can_load_draft_into_empty_cart(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN LOAD CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 2])
            ->assertOk();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $saveResponse->json('transaction.id');

        $loadResponse = $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]));

        $loadResponse->assertOk()
            ->assertJsonPath('transaction.id', $transactionId)
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED)
            ->assertJsonPath('cart_snapshot.meta.line_count', 1)
            ->assertJsonPath('cart_snapshot.active_transaction_id', $transactionId);
    }

    public function test_load_fails_with_409_when_cart_not_empty(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD NOT EMPTY');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN LOAD NOT EMPTY CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-001']);
        $productB = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-002']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productB->id, 'qty' => 1])
            ->assertOk();

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'CART_NOT_EMPTY');
    }

    public function test_user_with_load_permission_can_load_other_users_mutable_draft(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD OWNER');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);

        $owner = $this->createUserForSetting($setting, 'POS TXN LOAD OWNER USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $otherUser = $this->createUserForSetting($setting, 'POS TXN LOAD OTHER USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.load',
        ]);

        $this->openSession($setting, $terminal, $owner);
        $this->actingAsInSetting($owner, $setting);
        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-003']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        [$otherTerminal] = $this->createTerminalWithLocation($setting);
        $this->openSession($setting, $otherTerminal, $otherUser);
        $this->actingAsInSetting($otherUser, $setting);

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED)
            ->assertJsonPath('transaction.id', $transactionId);
    }

    public function test_user_with_edit_any_still_cannot_bypass_missing_load_permission(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD EDIT ANY');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);

        $owner = $this->createUserForSetting($setting, 'POS TXN LOAD OWNER 2', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $admin = $this->createUserForSetting($setting, 'POS TXN LOAD ADMIN', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.edit.any',
        ]);

        $this->openSession($setting, $terminal, $owner);
        $this->actingAsInSetting($owner, $setting);
        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-004']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        [$adminTerminal] = $this->createTerminalWithLocation($setting);
        $this->openSession($setting, $adminTerminal, $admin);
        $this->actingAsInSetting($admin, $setting);

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertForbidden();
    }

    public function test_cannot_reload_loaded_transaction(): void
    {
        $setting = $this->createSetting('BIZ POS TXN RELOAD');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN RELOAD CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $session = $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-005']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        app(\Modules\Pos\Services\PosCartSessionStore::class)->clearCart($setting->id, $session->id);

        // Transition from LOADED to LOADED is now forbidden to ensure concurrency lock
        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_LOADABLE');
    }

    public function test_cannot_load_cancelled_transaction(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD CANCELLED');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN LOAD CANCELLED CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-006']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        PosTransaction::whereKey($transactionId)->update(['status' => PosTransaction::STATUS_CANCELLED]);

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_LOADABLE');
    }

    public function test_load_requires_pos_transactions_load_permission(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD PERM');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);

        $owner = $this->createUserForSetting($setting, 'POS TXN LOAD OWNER 3', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $otherUser = $this->createUserForSetting($setting, 'POS TXN LOAD NO PERM', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);

        $this->openSession($setting, $terminal, $owner);
        $this->actingAsInSetting($owner, $setting);
        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-007']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        [$otherTerminal] = $this->createTerminalWithLocation($setting);
        $this->openSession($setting, $otherTerminal, $otherUser);
        $this->actingAsInSetting($otherUser, $setting);

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertForbidden();
    }

    public function test_load_returns_409_when_snapshot_hash_detects_drift(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LOAD DRIFT');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN LOAD DRIFT CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LD-DRIFT-001']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        DB::table('pos_transaction_lines')
            ->where('pos_transaction_id', $transactionId)
            ->update(['qty' => 2]);

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'SNAPSHOT_DRIFT');
    }
}
