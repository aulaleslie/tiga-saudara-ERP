<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionEmptyBlockTest extends PosTransactionFeatureTestCase
{
    public function test_remove_last_line_of_loaded_transaction_is_blocked(): void
    {
        $setting = $this->createSetting('BIZ POS TXN EMPTY REMOVE LAST');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN EMPTY REMOVE LAST USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.cart.line.remove',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $lineId = (int) $this->getJson(route('pos.sell.cart.show'))
            ->json('cart_snapshot.lines.0.line_id');

        $this->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $lineId]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_EMPTY_BLOCKED')
            ->assertJsonPath('message', 'Transaksi yang dimuat tidak dapat dikosongkan.');

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_LOADED,
        ]);
    }

    public function test_clear_cart_with_loaded_transaction_is_blocked(): void
    {
        $setting = $this->createSetting('BIZ POS TXN EMPTY CLEAR');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN EMPTY CLEAR USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.cart.clear',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-EB-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $this->deleteJson(route('pos.sell.cart.clear'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_EMPTY_BLOCKED')
            ->assertJsonPath('message', 'Transaksi yang dimuat tidak dapat dikosongkan.');
    }

    public function test_remove_non_last_line_of_loaded_transaction_succeeds(): void
    {
        $setting = $this->createSetting('BIZ POS TXN EMPTY REMOVE NON LAST');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN EMPTY REMOVE NON LAST USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.cart.line.remove',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $productA = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-EB-002']);
        $productB = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-EB-003']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productA->id, 'qty' => 1])
            ->assertOk();

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productB->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $lineId = (int) $this->getJson(route('pos.sell.cart.show'))
            ->json('cart_snapshot.lines.0.line_id');

        $this->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $lineId]))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);
    }

    public function test_clear_cart_without_active_transaction_id_still_works_normally(): void
    {
        $setting = $this->createSetting('BIZ POS TXN EMPTY NORMAL');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN EMPTY NORMAL USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.cart.clear',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-EB-004']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 0)
            ->assertJsonPath('cart_snapshot.active_transaction_id', null);
    }
}
