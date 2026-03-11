<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Tests\TestCase;

class POSTransactionCancelTest extends TestCase
{
    /**
     * Test creator can cancel own draft.
     */
    public function test_creator_can_cancel_own_draft(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save', 'pos.transactions.view']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Create draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Act - Cancel own transaction
        $response = $this->postJson(route('pos.transactions.cancel', ['transaction' => $transaction]), []);

        // Assert
        $response->assertSuccessful()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_CANCELLED);

        $transaction->refresh();
        $this->assertEquals(PosTransaction::STATUS_CANCELLED, $transaction->status);
    }

    /**
     * Test other user cannot cancel without edit.any.
     */
    public function test_other_user_cannot_cancel_without_edit_any(): void
    {
        // Arrange
        $owner = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $otherUser = $this->createAuthenticatedUser(['pos.access', 'pos.transactions.view']);
        // Note: otherUser lacks pos.transactions.edit.any

        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($owner, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $owner);

        // Create draft as owner
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Act - Try to cancel as other user
        $this->actAsUser($otherUser, $setting);
        $response = $this->postJson(route('pos.transactions.cancel', ['transaction' => $transaction]), []);

        // Assert
        $response->assertStatus(409)
            ->assertJsonPath('code', 'EDIT_FORBIDDEN');
    }

    /**
     * Test user with edit.any can cancel others draft.
     */
    public function test_user_with_edit_any_can_cancel_others_draft(): void
    {
        // Arrange
        $owner = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $admin = $this->createAuthenticatedUser(['pos.access', 'pos.transactions.view', 'pos.transactions.edit.any']);

        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($owner, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $owner);

        // Create draft as owner
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Act - Cancel as admin with edit.any
        $this->actAsUser($admin, $setting);
        $response = $this->postJson(route('pos.transactions.cancel', ['transaction' => $transaction]), []);

        // Assert
        $response->assertSuccessful()
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_CANCELLED);
    }

    /**
     * Test cannot cancel completed transaction.
     */
    public function test_cannot_cancel_completed_transaction(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save', 'pos.transactions.view']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Create draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Change status to COMPLETED
        $transaction->update(['status' => PosTransaction::STATUS_COMPLETED]);

        // Act - Try to cancel completed transaction
        $response = $this->postJson(route('pos.transactions.cancel', ['transaction' => $transaction]), []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_CANCELLABLE');
    }
}
