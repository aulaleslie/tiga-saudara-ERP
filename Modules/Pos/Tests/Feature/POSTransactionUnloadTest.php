<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionUnloadTest extends PosTransactionFeatureTestCase
{
    public function test_super_admin_can_unload_draft(): void
    {
        $setting = $this->createSetting('BIZ POS UNLOAD SA');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        
        $superAdmin = $this->createUserForSetting($setting, 'Super Admin', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.cart.clear',
        ]);
        // Note: Super Admin bypasses most checks by role name in many systems, 
        // but here we ensure they have the necessary direct permissions or role.
        
        $this->openSession($setting, $terminal, $superAdmin);
        $this->actingAsInSetting($superAdmin, $setting);

        $product = $this->createStockedProduct($setting, $location);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_LOADED,
        ]);

        // Unload via cart clear
        $this->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.meta.line_count', 0)
            ->assertJsonPath('cart_snapshot.active_transaction_id', null);

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_DRAFT,
        ]);
    }

    public function test_authorized_user_can_unload_draft(): void
    {
        $setting = $this->createSetting('BIZ POS UNLOAD AUTH');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        
        $user = $this->createUserForSetting($setting, 'Cashier with Clear', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.cart.clear',
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

        $this->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_DRAFT,
        ]);
    }

    public function test_unauthorized_user_is_still_blocked_by_approval_requirement(): void
    {
        $setting = $this->createSetting('BIZ POS UNLOAD UNAUTH');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        
        $user = $this->createUserForSetting($setting, 'Cashier without Clear', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
            // Missing pos.cart.clear
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

        $this->deleteJson(route('pos.sell.cart.clear'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        $this->assertDatabaseHas('pos_transactions', [
            'id' => $transactionId,
            'status' => PosTransaction::STATUS_LOADED,
        ]);
    }
}
