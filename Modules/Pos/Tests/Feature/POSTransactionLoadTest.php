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

    public function test_loaded_packed_draft_preserves_pricing_basis_and_tier_switching(): void
    {
        $setting = $this->createSetting('BIZ PACKED LOAD TIER');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS PACKED LOAD CASHIER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $session = $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        // Create unit conversion: Box of 5 units
        $baseUnit = \Modules\Setting\Entities\Unit::firstOrCreate([
            'setting_id' => $setting->id,
            'name' => 'Unit',
            'short_name' => 'Unit',
        ]);

        $boxUnit = \Modules\Setting\Entities\Unit::firstOrCreate([
            'setting_id' => $setting->id,
            'name' => 'Box',
            'short_name' => 'BOX',
        ]);

        // Create product with realistic tier pricing
        // Base: Rp52.500, Reseller: Rp51.000, Wholesaler: Rp49.000
        $product = $this->createStockedProduct($setting, $location, [
            'product_code' => 'SKU-PACKED-LOAD-001',
            'product_name' => 'Packed Tier Product',
            'sale_price' => 52500,  // Base price
        ]);

        // Set ALL tier prices upfront so they're cached in pricing_basis when line is added
        \Modules\Product\Entities\ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $setting->id)
            ->update([
                'sale_price' => 52500,
                'tier_2_price' => 51000,  // RESELLER = tier_2
                'tier_1_price' => 49000,  // WHOLESALER = tier_1
            ]);

        // Create conversion: 1 box = 5 units, box price = Rp250.000
        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 5,
        ]);

        // Add conversion pricing
        \Modules\Product\Entities\ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $setting->id,
            'price' => 250000,  // Box price
        ]);

        // Create reseller customer
        $reseller = \Modules\People\Entities\Customer::factory()->create([
            'setting_id' => $setting->id,
            'tier' => 'RESELLER',
        ]);

        // Add 7 base units to cart (1 box + 2 loose)
        // Packed pricing is triggered automatically when qty > conversion_factor
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $product->id,
            'qty' => 7,
        ])->assertOk();

        // Verify cart is packed before selecting customer
        $cartService = app(\Modules\Pos\Services\PosCartService::class);
        $cartBefore = $cartService->getSnapshot($setting->id, $user->id);
        $this->assertEquals('PACKED', $cartBefore['lines'][0]['price_source']);
        // Base price: 1 box (250K) + 2 units (2 × 52.5K) = 355K
        $this->assertNotNull($cartBefore['lines'][0]['breakdown']);
        $this->assertEquals(1, $cartBefore['lines'][0]['breakdown']['box_count']);
        $this->assertEquals(2, $cartBefore['lines'][0]['breakdown']['loose_count']);

        // Select reseller customer
        $this->patchJson(route('pos.sell.cart.customer.update'), [
            'customer_id' => $reseller->id,
        ])->assertOk();

        // Verify cart remains PACKED after customer selection and recalculates price
        $cartAfterCustomer = $cartService->getSnapshot($setting->id, $user->id);
        $this->assertEquals('PACKED', $cartAfterCustomer['lines'][0]['price_source']);
        $this->assertNotNull($cartAfterCustomer['lines'][0]['breakdown']);
        // Check breakdown shows tier_2 pricing (RESELLER = tier_2)
        $this->assertEquals('tier_2', $cartAfterCustomer['lines'][0]['breakdown']['tier']);

        // Save as draft
        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) $saveResponse->json('transaction.id');

        // Clear cart to simulate fresh session
        app(\Modules\Pos\Services\PosCartSessionStore::class)->clearCart($setting->id, $session->id);

        // Load the draft
        $loadResponse = $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $cartAfterLoad = $loadResponse->json('cart_snapshot');

        // Assert: loaded line price_source is PACKED
        $this->assertEquals('PACKED', $cartAfterLoad['lines'][0]['price_source']);

        // Assert: breakdown is visible and correct (tier customer has box_count = 0, loose_count = qty)
        $this->assertNotNull($cartAfterLoad['lines'][0]['breakdown']);
        $this->assertEquals(0, $cartAfterLoad['lines'][0]['breakdown']['box_count']);
        $this->assertEquals(7, $cartAfterLoad['lines'][0]['breakdown']['loose_count']);

        // Assert: quantity is 7 base units
        $this->assertEquals(7, $cartAfterLoad['lines'][0]['qty']);

        // Assert: breakdown persists with tier information
        $this->assertNotNull($cartAfterLoad['lines'][0]['breakdown']);
        $this->assertEquals('tier_2', $cartAfterLoad['lines'][0]['breakdown']['tier']);

        // Assert: loaded cart has reseller customer
        $this->assertEquals($reseller->id, $cartAfterLoad['customer']['selected_customer_id']);
        // Verify breakdown tier state shows RESELLER pricing was applied (tier_2)
        $this->assertEquals('tier_2', $cartAfterLoad['lines'][0]['breakdown']['tier']);

        // Assert: reseller line total is exactly Rp357.000 (7 units × Rp51.000 = Rp357.000, bypassing box pricing)
        $this->assertEquals(357000, $cartAfterLoad['lines'][0]['line_total']);
        $this->assertEquals(357000, $cartAfterLoad['totals']['subtotal']);
        $this->assertEquals(357000, $cartAfterLoad['totals']['grand_total']);

        // Assert: explicit reseller tier breakdown details
        // box_count = 0, loose_count = 7
        // loose_price_applied = 5100000 minor units (Rp51.000 reseller unit price)
        $this->assertEquals(0, $cartAfterLoad['lines'][0]['breakdown']['box_count']);
        $this->assertEquals(7, $cartAfterLoad['lines'][0]['breakdown']['loose_count']);
        $this->assertEquals(0, $cartAfterLoad['lines'][0]['breakdown']['box_price_applied']);
        $this->assertEquals(5100000, $cartAfterLoad['lines'][0]['breakdown']['loose_price_applied']);

        // Reject scaling errors: 100× (35700000) or /100 (3570)
        $this->assertNotEquals(35700000, $cartAfterLoad['lines'][0]['line_total']);
        $this->assertNotEquals(3570, $cartAfterLoad['lines'][0]['line_total']);

        // Verify receipt displays correctly with PACKED source preserved
        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $transaction = PosTransaction::findOrFail($transactionId);
        $receiptData = $receiptService->getTransactionReceiptData($transaction);

        // Verify receipt line has breakdown and displays correctly
        $this->assertNotNull($receiptData['lines'][0]['breakdown']);
        $this->assertNotNull($receiptData['lines'][0]['unit_breakdown']);
        // Verify quantity is displayed raw (no x100 or /100 errors)
        $this->assertEquals(7, $receiptData['lines'][0]['qty']);
        // Verify receipt line subtotal is exactly Rp357.000 (no x100 scaling)
        $this->assertEquals(357000, $receiptData['lines'][0]['sub_total']);
        $this->assertEquals(357000, $receiptData['grand_total']);

        // Ensure no decimal padding or 100× scaling errors (35700000 = ×100, 3570 = /100)
        $this->assertNotEquals(35700000, $receiptData['lines'][0]['sub_total']);
        $this->assertNotEquals(3570, $receiptData['lines'][0]['sub_total']);

        // ===== TIER SWITCHING TEST: Create wholesaler and switch to tier_1 =====

        // Create wholesaler customer with tier_1 pricing
        $wholesaler = \Modules\People\Entities\Customer::factory()->create([
            'setting_id' => $setting->id,
            'tier' => 'WHOLESALER',
        ]);

        // Note: tier_1_price was already set upfront when product pricing was configured
        // This ensures it's cached in the pricing_basis when the line is added to the cart

        // Switch customer to wholesaler via PATCH endpoint
        $switchResponse = $this->patchJson(route('pos.sell.cart.customer.update'), [
            'customer_id' => $wholesaler->id,
        ])->assertOk();

        // Get updated cart after switch via response (fresh snapshot from endpoint)
        $cartAfterSwitch = $switchResponse->json('cart_snapshot');

        // Assert: line remains price_source = PACKED
        $this->assertEquals('PACKED', $cartAfterSwitch['lines'][0]['price_source']);

        // Assert: customer switched to wholesaler
        $this->assertEquals($wholesaler->id, $cartAfterSwitch['customer']['selected_customer_id']);

        // Assert: quantity remains exactly 7 base units
        $this->assertEquals(7, $cartAfterSwitch['lines'][0]['qty']);

        // Debug: check what pricing_basis contains
        $hasPricingBasis = isset($cartAfterSwitch['lines'][0]['pricing_basis']);
        $this->assertTrue($hasPricingBasis, 'Line should have pricing_basis for re-pricing on tier switch');

        // Assert: breakdown is recomputed for tier_1
        $this->assertNotNull($cartAfterSwitch['lines'][0]['breakdown']);
        $this->assertEquals('tier_1', $cartAfterSwitch['lines'][0]['breakdown']['tier']);

        // Assert the tier_1 base-unit breakdown prices:
        // box_count = 0, loose_count = 7, line_total = 7 × Rp49.000 = Rp343.000
        // box_price_applied = 0
        // loose_price_applied = 4900000 minor units (1 × 4900000)
        $this->assertEquals(0, $cartAfterSwitch['lines'][0]['breakdown']['box_count']);
        $this->assertEquals(7, $cartAfterSwitch['lines'][0]['breakdown']['loose_count']);
        $this->assertEquals(0, $cartAfterSwitch['lines'][0]['breakdown']['box_price_applied']);
        $this->assertEquals(4900000, $cartAfterSwitch['lines'][0]['breakdown']['loose_price_applied']);

        // Assert: cart totals are exactly Rp343.000 for tier_1
        // (1 × 245000 + 2 × 49000 = 245000 + 98000 = 343000 in Rupiah)
        $this->assertEquals(343000, $cartAfterSwitch['lines'][0]['line_total']);
        $this->assertEquals(343000, $cartAfterSwitch['totals']['subtotal']);
        $this->assertEquals(343000, $cartAfterSwitch['totals']['grand_total']);

        // Save the switched transaction and verify receipt
        $switchSaveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $switchTransactionId = (int) $switchSaveResponse->json('transaction.id');

        // Get receipt for the switched transaction
        $switchTransaction = PosTransaction::findOrFail($switchTransactionId);
        $switchReceiptData = $receiptService->getTransactionReceiptData($switchTransaction);

        // Verify receipt has correct breakdown tier and totals
        $this->assertNotNull($switchReceiptData['lines'][0]['breakdown']);
        $this->assertEquals('tier_1', $switchReceiptData['lines'][0]['breakdown']['tier']);

        // Assert receipt line and grand totals are exactly Rp343.000
        $this->assertEquals(343000, $switchReceiptData['lines'][0]['sub_total']);
        $this->assertEquals(343000, $switchReceiptData['grand_total']);

        // Reject 100× scaling error (34300000) or /100 error (3430)
        $this->assertNotEquals(34300000, $switchReceiptData['lines'][0]['sub_total']);
        $this->assertNotEquals(3430, $switchReceiptData['lines'][0]['sub_total']);
    }
}
