<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Tests\TestCase;

class POSTransactionEmptyBlockTest extends TestCase
{
    /**
     * Test remove last line of loaded transaction returns 422 TRANSACTION_EMPTY_BLOCKED.
     */
    public function test_remove_last_line_of_loaded_transaction_returns_422(): void
    {
        // Arrange - Setup
        $user = $this->createAuthenticatedUser([
            'pos.access', 'pos.sell', 'pos.transactions.save',
            'pos.transactions.load', 'pos.cart.line.remove'
        ]);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Save draft with one product
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Open new session and load draft
        $session2 = $this->openPosSession($setting, $user);
        $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), [])
            ->assertSuccessful();

        // Get current cart to find the line_id
        $cartResponse = $this->getJson(route('pos.sell.cart.show'));
        $lineId = $cartResponse->json('lines')[0]['line_id'];

        // Act - Try to remove the only line
        $response = $this->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $lineId]));

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('message', '*Transaksi yang dimuat tidak dapat dikosongkan*');
    }

    /**
     * Test clear cart with loaded transaction returns 422 TRANSACTION_EMPTY_BLOCKED.
     */
    public function test_clear_cart_with_loaded_transaction_returns_422(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser([
            'pos.access', 'pos.sell', 'pos.transactions.save',
            'pos.transactions.load', 'pos.cart.clear'
        ]);
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

        // Load draft
        $session2 = $this->openPosSession($setting, $user);
        $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), [])
            ->assertSuccessful();

        // Act - Try to clear cart with loaded transaction
        $response = $this->deleteJson(route('pos.sell.cart.clear'));

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('message', '*Transaksi yang dimuat tidak dapat dikosongkan*');
    }

    /**
     * Test remove non-last line of loaded transaction succeeds.
     */
    public function test_remove_non_last_line_of_loaded_transaction_succeeds(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser([
            'pos.access', 'pos.sell', 'pos.transactions.save',
            'pos.transactions.load', 'pos.cart.line.remove'
        ]);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Save draft with TWO products
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $transaction = PosTransaction::find($saveResponse->json('transaction.id'));

        // Load draft
        $session2 = $this->openPosSession($setting, $user);
        $this->postJson(route('pos.transactions.load', ['transaction' => $transaction]), [])
            ->assertSuccessful();

        // Get current cart
        $cartResponse = $this->getJson(route('pos.sell.cart.show'));
        $lineId = $cartResponse->json('lines')[0]['line_id'];

        // Act - Remove first of two lines
        $response = $this->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $lineId]));

        // Assert - Should succeed
        $response->assertSuccessful()
            ->assertJsonPath('meta.line_count', 1);
    }

    /**
     * Test normal cart without transaction allows clearing.
     */
    public function test_normal_cart_without_transaction_allows_clearing(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.cart.clear']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Add product without saving as draft
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        // Act - Clear cart (no active transaction)
        $response = $this->deleteJson(route('pos.sell.cart.clear'));

        // Assert
        $response->assertSuccessful()
            ->assertJsonPath('meta.line_count', 0);
    }
}
