<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;

class POSBundleDraftRoundtripTest extends PosTransactionFeatureTestCase
{
    public function test_bundle_modal_uses_bundle_sale_price(): void
    {
        $setting = $this->createSetting('BIZ BUNDLE MODAL');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'CASHIER', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $parent = $this->createStockedProduct($setting, $location, ['product_name' => 'Parent Product']);
        $child = $this->createStockedProduct($setting, $location, ['product_name' => 'Child Product']);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Test Bundle',
            'price' => 15000, // Legacy add-on price
            'bundle_sale_price' => 125000, // Authoritative sale price
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 2,
        ]);

        $response = $this->getJson("/pos/sell/products/{$parent->id}/bundles");
        
        $response->assertOk()
            ->assertJsonPath('bundles.0.price', 125000)
            ->assertJsonPath('bundles.0.legacy_price', 15000);
    }

    public function test_bundle_metadata_is_preserved_through_save_and_new_roundtrip(): void
    {
        $setting = $this->createSetting('BIZ BUNDLE ROUNDTRIP');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'CASHIER', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 
            'pos.transactions.save', 'pos.transactions.load'
        ]);
        $session = $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $parent = $this->createStockedProduct($setting, $location, ['product_name' => 'Parent Product']);
        $child = $this->createStockedProduct($setting, $location, ['product_name' => 'Child Product', 'product_price' => 50000]);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Gold Bundle',
            'price' => 20000, // legacy addon
            'bundle_sale_price' => 150000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
            'informational_item_price' => 45000,
        ]);

        // Add to cart
        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $parent->id,
            'qty' => 1,
            'bundle_id' => $bundle->id,
        ])->assertOk();

        // Save as draft
        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);
        
        $transactionId = $saveResponse->json('transaction.id');

        // Verify database state of line_meta
        $this->assertDatabaseHas('pos_transaction_lines', [
            'pos_transaction_id' => $transactionId,
            'product_id' => $parent->id,
        ]);
        
        $line = \Modules\Pos\Entities\PosTransactionLine::where('pos_transaction_id', $transactionId)->first();
        $meta = $line->line_meta;
        
        $this->assertEquals($bundle->id, $meta['bundle_id']);
        $this->assertEquals('GOLD BUNDLE', $meta['bundle_name']);
        $this->assertEquals(20000, $meta['bundle_price']);
        $this->assertCount(1, $meta['bundle_items']);
        $this->assertEquals(45000, $meta['bundle_items'][0]['informational_item_price']);

        // Clear cart session manually to simulate fresh load
        app(\Modules\Pos\Services\PosCartSessionStore::class)->clearCart($setting->id, $session->id);

        // Load draft
        $loadResponse = $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();
            
        $cartLine = $loadResponse->json('cart_snapshot.lines.0');
        
        $this->assertEquals($bundle->id, $cartLine['bundle_id']);
        $this->assertEquals('GOLD BUNDLE', $cartLine['bundle_name']);
        $this->assertEquals(20000, $cartLine['bundle_price']);
        $this->assertEquals(150000, $cartLine['unit_price']);
        $this->assertCount(1, $cartLine['bundle_items']);
        $this->assertEquals(45000, $cartLine['bundle_items'][0]['informational_item_price']);
    }

    public function test_snapshot_drift_detects_bundle_id_tampering(): void
    {
        $setting = $this->createSetting('BIZ BUNDLE DRIFT');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'CASHIER', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 
            'pos.transactions.save', 'pos.transactions.load'
        ]);
        $session = $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $parent = $this->createStockedProduct($setting, $location);
        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Bundle A',
            'bundle_sale_price' => 1000,
        ]);

        $this->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $parent->id,
            'qty' => 1,
            'bundle_id' => $bundle->id,
        ])->assertOk();

        $saveResponse = $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);
        
        $transactionId = $saveResponse->json('transaction.id');

        // Tamper with bundle_id in line_meta
        $line = \Modules\Pos\Entities\PosTransactionLine::where('pos_transaction_id', $transactionId)->first();
        $meta = $line->line_meta;
        $meta['bundle_id'] = 99999;
        $line->update(['line_meta' => $meta]);

        // Attempt load
        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'SNAPSHOT_DRIFT');
    }
}
