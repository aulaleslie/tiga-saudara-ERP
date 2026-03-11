<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionSaveAndNewTest extends PosTransactionFeatureTestCase
{
    public function test_cashier_can_save_non_empty_cart_as_draft_and_cart_is_cleared(): void
    {
        $setting = $this->createSetting('BIZ POS TXN SAVE');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN SAVE CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location);

        $this->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 2,
            ])
            ->assertOk();
        $this->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);

        $response = $this->postJson(route('pos.sell.transactions.save-and-new'));

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'transaction' => ['id', 'code', 'status'],
            ])
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_DRAFT);

        $transactionId = (int) $response->json('transaction.id');
        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'setting_id' => $setting->id,
            'owner_user_id' => $user->id,
            'status' => PosTransaction::STATUS_DRAFT,
        ]);
        $this->assertDatabaseHas('pos_transaction_lines', [
            'pos_transaction_id' => $transactionId,
            'product_id' => $product->id,
            'qty' => 2,
        ]);
        $savedTransaction = PosTransaction::query()->find($transactionId);
        $this->assertNotNull($savedTransaction?->snapshot_hash);
        $this->assertSame(64, strlen((string) $savedTransaction?->snapshot_hash));

        $this->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 0)
            ->assertJsonPath('cart_snapshot.active_transaction_id', null);
    }

    public function test_save_draft_requires_pos_transactions_save_permission(): void
    {
        $setting = $this->createSetting('BIZ POS TXN SAVE PERM');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN SAVE NO PERM', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location);
        $this->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertForbidden();
    }

    public function test_save_draft_with_empty_cart_returns_422_cart_empty(): void
    {
        $setting = $this->createSetting('BIZ POS TXN SAVE EMPTY');
        [$terminal] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN SAVE EMPTY CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CART_EMPTY');
    }

    public function test_save_draft_is_blocked_when_transactions_feature_is_disabled(): void
    {
        $setting = $this->createSetting('BIZ POS TXN SAVE FLAG OFF');
        $setting->update(['pos_transactions_enabled' => false]);
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN SAVE FLAG OFF CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-SF-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'POS_TRANSACTIONS_DISABLED');
    }

    public function test_saved_draft_persists_line_serial_assignments(): void
    {
        $setting = $this->createSetting('BIZ POS TXN SAVE SERIAL');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN SAVE SERIAL CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, [
            'serial_number_required' => true,
            'product_code' => 'SKU-TXN-SN-001',
        ]);
        $serialNumber = 'SN-TXN-001';
        $this->createSerialNumber($product, $location, $serialNumber);

        $this->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        $lineId = (int) $this->getJson(route('pos.sell.cart.show'))
            ->json('cart_snapshot.lines.0.line_id');

        $this->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $serialNumber,
            ])
            ->assertOk();

        $response = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $response->json('transaction.id');
        $this->assertDatabaseHas('pos_transaction_line_serials', [
            'serial_number' => $serialNumber,
        ]);
        $this->assertDatabaseHas('pos_transaction_lines', [
            'pos_transaction_id' => $transactionId,
            'product_id' => $product->id,
        ]);
    }

    public function test_save_and_new_updates_loaded_transaction_instead_of_creating_new_record(): void
    {
        $setting = $this->createSetting('BIZ POS TXN SAVE UPDATE');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN SAVE UPDATE CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-UPD-001']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $initialSave = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $initialSave->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED);

        $lineId = (int) $this->getJson(route('pos.sell.cart.show'))
            ->json('cart_snapshot.lines.0.line_id');

        $this->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), ['qty' => 3])
            ->assertOk();

        $secondSave = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $secondTransactionId = (int) $secondSave->json('transaction.id');
        $this->assertSame($transactionId, $secondTransactionId);

        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_DRAFT,
        ]);
        $this->assertDatabaseHas('pos_transaction_lines', [
            'pos_transaction_id' => $transactionId,
            'qty' => 3,
        ]);
    }
}
