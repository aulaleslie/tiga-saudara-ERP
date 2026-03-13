<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSTransactionListTest extends PosTransactionFeatureTestCase
{
    public function test_transaction_list_data_returns_only_current_setting_transactions(): void
    {
        $settingA = $this->createSetting('BIZ POS TXN LIST A');
        $settingB = $this->createSetting('BIZ POS TXN LIST B');

        [$terminalA, $locationA] = $this->createTerminalWithLocation($settingA);
        [$terminalB, $locationB] = $this->createTerminalWithLocation($settingB);

        $user = $this->createUserForSetting($settingA, 'POS TXN LIST USER A', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $user->settings()->attach($settingB->id, ['role_id' => $user->roles()->firstOrFail()->id]);

        $this->openSession($settingA, $terminalA, $user);
        $this->actingAsInSetting($user, $settingA);
        $productA = $this->createStockedProduct($settingA, $locationA, ['product_code' => 'SKU-TXN-LS-001']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productA->id, 'qty' => 1])
            ->assertOk();
        $txnA = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->openSession($settingB, $terminalB, $user);
        $this->actingAsInSetting($user, $settingB);
        $productB = $this->createStockedProduct($settingB, $locationB, ['product_code' => 'SKU-TXN-LS-002']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productB->id, 'qty' => 1])
            ->assertOk();
        $txnB = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->actingAsInSetting($user, $settingA);
        $response = $this->getJson(route('pos.transactions.data'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($txnA, $ids);
        $this->assertNotContains($txnB, $ids);
    }

    public function test_transaction_list_data_requires_pos_transactions_view_permission(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST PERM');
        $user = $this->createUserForSetting($setting, 'POS TXN LIST NO VIEW', [
            'pos.access',
        ]);

        $this->actingAsInSetting($user, $setting);
        $this->getJson(route('pos.transactions.data'))
            ->assertForbidden();
    }

    public function test_transaction_list_data_is_blocked_when_transactions_feature_is_disabled(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST FLAG OFF');
        $setting->update(['pos_transactions_enabled' => false]);
        $user = $this->createUserForSetting($setting, 'POS TXN LIST FLAG OFF USER', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $this->actingAsInSetting($user, $setting);
        $this->getJson(route('pos.transactions.data'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'POS_TRANSACTIONS_DISABLED');
    }

    public function test_transaction_list_data_can_filter_by_status(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST STATUS');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN LIST STATUS USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LS-003']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $draftId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $cancelledId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        PosTransaction::whereKey($cancelledId)->update(['status' => PosTransaction::STATUS_CANCELLED]);

        $response = $this->getJson(route('pos.transactions.data', ['status' => ['DRAFT']]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($draftId, $ids);
        $this->assertNotContains($cancelledId, $ids);
    }

    public function test_transaction_list_page_renders_for_authorized_user(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST PAGE');
        $user = $this->createUserForSetting($setting, 'POS TXN LIST PAGE USER', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $this->actingAsInSetting($user, $setting);
        $this->get(route('pos.transactions.index'))
            ->assertOk()
            ->assertViewIs('pos::transactions.index')
            ->assertSee('Transaksi POS');
    }

    public function test_transaction_list_page_includes_client_bootstrap_script(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST SCRIPT');
        $user = $this->createUserForSetting($setting, 'POS TXN LIST SCRIPT USER', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.index'));

        $response->assertOk()
            ->assertSee('const dataEndpoint =', false)
            ->assertSee('loadRows();', false);
    }

    public function test_transaction_list_data_can_filter_by_date_range(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST DATE RANGE');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN LIST DATE RANGE USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LS-005']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $olderTransactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $newerTransactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $oldDate = Carbon::now()->subDays(5);
        $newDate = Carbon::now()->subDays(1);

        PosTransaction::whereKey($olderTransactionId)->update([
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
        ]);
        PosTransaction::whereKey($newerTransactionId)->update([
            'created_at' => $newDate,
            'updated_at' => $newDate,
        ]);

        $response = $this->getJson(route('pos.transactions.data', [
            'date_from' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'date_to' => Carbon::now()->format('Y-m-d'),
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($newerTransactionId, $ids);
        $this->assertNotContains($olderTransactionId, $ids);
    }

    public function test_transaction_list_data_returns_invalid_date_range_error(): void
    {
        $setting = $this->createSetting('BIZ POS TXN LIST DATE INVALID');
        $user = $this->createUserForSetting($setting, 'POS TXN LIST DATE INVALID USER', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $this->actingAsInSetting($user, $setting);
        $this->getJson(route('pos.transactions.data', [
            'date_from' => '2026-03-12',
            'date_to' => '2026-03-11',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_DATE_RANGE');
    }

    public function test_transaction_detail_page_renders_for_authorized_user(): void
    {
        $setting = $this->createSetting('BIZ POS TXN DETAIL PAGE');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'POS TXN DETAIL USER', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAsInSetting($user, $setting);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'SKU-TXN-LS-004']);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->json('transaction.id');

        $this->get(route('pos.transactions.show', ['transaction' => $transactionId]))
            ->assertOk()
            ->assertViewIs('pos::transactions.show');
    }
}
