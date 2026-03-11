<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Tests\TestCase;

class POSTransactionLoadTest extends TestCase
{
    /**
     * Test cashier can load draft into empty cart.
     */
    public function test_cashier_can_load_draft_into_empty_cart(): void
    {
        // Arrange - Create and save a draft
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save', 'pos.transactions.load']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Add product and save draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 2,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transactionId = $saveResponse->json('transaction.id');
        $transaction = PosTransaction::find($transactionId);

        // Open new session for loading
        $session2 = $this->openPosSession($setting, $user);

        // Act - Load draft
        $loadResponse = $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), []);

        // Assert
        $loadResponse->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'cart_snapshot',
                'transaction' => ['id', 'code', 'status'],
            ])
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED)
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);

        // Verify transaction status updated
        $transaction->refresh();
        $this->assertEquals(PosTransaction::STATUS_LOADED, $transaction->status);
    }

    /**
     * Test load fails with 409 CART_NOT_EMPTY when lines exist.
     */
    public function test_load_fails_with_409_cart_not_empty_when_lines_exist(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save', 'pos.transactions.load']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Save a draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Add a product to current (new) cart
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        // Act - Try to load with non-empty cart
        $response = $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), []);

        // Assert
        $response->assertStatus(409)
            ->assertJsonPath('code', 'CART_NOT_EMPTY');
    }

    /**
     * Test load fails with 403 EDIT_FORBIDDEN for other user without edit.any.
     */
    public function test_load_fails_with_403_edit_forbidden_for_other_user(): void
    {
        // Arrange
        $owner = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $otherUser = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.load']);
        // Note: otherUser does NOT have pos.transactions.edit.any

        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($owner, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $owner);

        // Save draft as owner
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Switch to other user
        $this->actAsUser($otherUser, $setting);
        $session2 = $this->openPosSession($setting, $otherUser);

        // Act - Try to load as different user
        $response = $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), []);

        // Assert
        $response->assertStatus(409)
            ->assertJsonPath('code', 'EDIT_FORBIDDEN');
    }

    /**
     * Test load allowed for user with edit.any permission.
     */
    public function test_load_allowed_for_user_with_edit_any_permission(): void
    {
        // Arrange
        $owner = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $admin = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.load', 'pos.transactions.edit.any']);

        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($owner, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $owner);

        // Save draft as owner
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Switch to admin
        $this->actAsUser($admin, $setting);
        $session2 = $this->openPosSession($setting, $admin);

        // Act - Load as admin with edit.any
        $response = $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), []);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('transaction.status', PosTransaction::STATUS_LOADED);
    }

    /**
     * Test cannot load non-draft transaction.
     */
    public function test_cannot_load_non_draft_transaction(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save', 'pos.transactions.load']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Save draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Change status to CANCELLED manually
        $transaction->update(['status' => PosTransaction::STATUS_CANCELLED]);

        $session2 = $this->openPosSession($setting, $user);

        // Act - Try to load CANCELLED transaction
        $response = $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_NOT_LOADABLE');
    }

    /**
     * Test load requires pos.transactions.load permission.
     */
    public function test_load_requires_pos_transactions_load_permission(): void
    {
        // Arrange
        $owner = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $otherUser = $this->createAuthenticatedUser(['pos.access', 'pos.sell']);
        // Note: otherUser lacks pos.transactions.load

        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($owner, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $owner);

        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Switch to other user
        $this->actAsUser($otherUser, $setting);
        $session2 = $this->openPosSession($setting, $otherUser);

        // Act - Try to load without permission
        $response = $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), []);

        // Assert
        $response->assertForbidden();
    }
}
