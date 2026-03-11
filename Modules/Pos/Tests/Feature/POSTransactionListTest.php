<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Tests\TestCase;

class POSTransactionListTest extends TestCase
{
    /**
     * Test transaction list returns only own setting transactions.
     */
    public function test_transaction_list_returns_only_own_setting_transactions(): void
    {
        // Arrange - Create two settings
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.transactions.view']);
        $setting1 = $this->createSetting(['pos_enabled' => true]);
        $setting2 = $this->createSetting(['pos_enabled' => true]);

        // Create transactions in setting 1
        $this->actAsUser($user, $setting1);
        $product = $this->createProduct(['stock_managed' => true]);
        $session1 = $this->openPosSession($setting1, $user);

        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $txn1Response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $txn1Id = $txn1Response->json('transaction.id');

        // Create transactions in setting 2
        $this->actAsUser($user, $setting2);
        $session2 = $this->openPosSession($setting2, $user);

        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $txn2Response = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $txn2Id = $txn2Response->json('transaction.id');

        // Act - Get transaction list for setting 1
        $this->actAsUser($user, $setting1);
        $response = $this->getJson(route('pos.transactions.data'));

        // Assert
        $response->assertSuccessful();
        $data = $response->json('data');
        $transactionIds = array_map(fn ($t) => $t['id'], $data);

        $this->assertContains($txn1Id, $transactionIds);
        $this->assertNotContains($txn2Id, $transactionIds);
    }

    /**
     * Test transaction list requires pos.transactions.view permission.
     */
    public function test_transaction_list_requires_pos_transactions_view_permission(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access']);
        // Note: NO pos.transactions.view permission
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        // Act
        $response = $this->getJson(route('pos.transactions.data'));

        // Assert
        $response->assertForbidden();
    }

    /**
     * Test transaction list filters by status.
     */
    public function test_transaction_list_filters_by_status(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.sell', 'pos.transactions.save', 'pos.transactions.view']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        $product = $this->createProduct(['stock_managed' => true]);
        $session = $this->openPosSession($setting, $user);

        // Create a DRAFT transaction
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $draftTxnId = $saveResponse->json('transaction.id');

        // Create another DRAFT
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertSuccessful();

        $saveResponse2 = $this->postJson(route('pos.sell.transactions.save-and-new'), []);
        $draftTxnId2 = $saveResponse2->json('transaction.id');

        // Change one to CANCELLED
        PosTransaction::find($draftTxnId2)->update(['status' => PosTransaction::STATUS_CANCELLED]);

        // Act - Filter by DRAFT status
        $response = $this->getJson(route('pos.transactions.data', ['status' => ['DRAFT']]));

        // Assert
        $response->assertSuccessful();
        $data = $response->json('data');
        $transactionIds = array_map(fn ($t) => $t['id'], $data);

        $this->assertContains($draftTxnId, $transactionIds);
        $this->assertNotContains($draftTxnId2, $transactionIds); // CANCELLED should not appear
    }

    /**
     * Test transaction list page renders for authorized user.
     */
    public function test_transaction_list_page_renders_for_authorized_user(): void
    {
        // Arrange
        $user = $this->createAuthenticatedUser(['pos.access', 'pos.transactions.view']);
        $setting = $this->createSetting(['pos_enabled' => true]);
        $this->actAsUser($user, $setting);

        // Act
        $response = $this->get(route('pos.transactions.index'));

        // Assert
        $response->assertSuccessful()
            ->assertViewIs('pos::transactions.index');
    }
}
