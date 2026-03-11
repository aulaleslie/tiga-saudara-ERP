<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\Exceptions\PosTransactionValidationException;
use Tests\TestCase;

class POSTransactionSaveAndNewTest extends TestCase
{
    /**
     * Test cashier can save non-empty cart as draft and receives code.
     */
    public function test_cashier_can_save_non_empty_cart_as_draft_and_receives_code(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Add product to cart
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 2,
        ])->assertSuccessful();

        // Act - Save and new
        $response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);

        // Assert
        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'transaction' => ['id', 'code', 'status'],
            ]);

        $transactionId = $response->json('transaction.id');
        $transaction = PosTransaction::find($transactionId);

        $this->assertNotNull($transaction);
        $this->assertEquals(PosTransaction::STATUS_DRAFT, $transaction->status);
        $this->assertEquals($user->id, $transaction->owner_user_id);
        $this->assertStringContainsString('TXN-', $transaction->code);

        // Verify cart is cleared
        $cartResponse = $this->getJson(route('pos.sell.cart.show'));
        $cartResponse->assertJsonPath('meta.line_count', 0);
    }

    /**
     * Test save draft requires pos.transactions.save permission.
     */
    public function test_save_draft_requires_pos_transactions_save_permission(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell']);
        // Note: NO 'pos.transactions.save' permission
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $this->openPosSession($setting, $user);

        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        // Act - Try to save without permission
        $response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);

        // Assert
        $response->assertForbidden();
    }

    /**
     * Test save draft with empty cart returns 422 CART_EMPTY.
     */
    public function test_save_draft_with_empty_cart_returns_422_cart_empty(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $this->openPosSession($setting, $user);

        // Act - Try to save with empty cart
        $response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('code', 'CART_EMPTY');
    }

    /**
     * Test saved draft persists serials to line_serials table.
     */
    public function test_saved_draft_persists_serials_to_line_serials_table(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true, 'require_serial' => true]);
        $session = $this->openPosSession($setting, $user);

        // Add product to cart
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        // Get cart to find line_id
        $cartResponse = $this->getJson(route('pos.sell.cart.show'));
        $lineId = $cartResponse->json('lines')[0]['line_id'];

        // Add serial to line
        $this->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
            'serial_number' => 'SN12345',
        ])->assertSuccessful();

        // Act - Save draft
        $response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);

        // Assert
        $response->assertStatus(201);
        $transactionId = $response->json('transaction.id');

        $transaction = PosTransaction::with('lines.serials')->find($transactionId);
        $this->assertNotNull($transaction);
        $this->assertCount(1, $transaction->lines);
        $this->assertCount(1, $transaction->lines[0]->serials);
        $this->assertEquals('SN12345', $transaction->lines[0]->serials[0]->serial_number);
    }

    /**
     * Test save draft requires active session.
     */
    public function test_save_draft_requires_active_session(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        // NO active session!

        // Act - Try to save without session
        $response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);

        // Assert
        $response->assertStatus(500); // No active session error
    }
}
