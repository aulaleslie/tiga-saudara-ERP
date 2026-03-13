<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\SettingPosPaymentMethod;

class POSTransactionVisibilityAndMutabilityTest extends PosTransactionFeatureTestCase
{
    public function test_transaction_list_without_status_filter_includes_completed_records(): void
    {
        $setting = $this->createSetting('TXN VISIBILITY');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'Txn Visibility Cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        $productA = $this->createStockedProduct($setting, $location, ['product_code' => 'TXN-VIS-001']);
        $productB = $this->createStockedProduct($setting, $location, ['product_code' => 'TXN-VIS-002']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productA->id, 'qty' => 1])
            ->assertOk();
        $draftId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201)
            ->json('transaction.id');

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $productB->id, 'qty' => 1])
            ->assertOk();
        $completedId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201)
            ->json('transaction.id');

        PosTransaction::query()->whereKey($completedId)->update([
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $response = $this->getJson(route('pos.transactions.data'));
        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($draftId, $ids);
        $this->assertContains($completedId, $ids);
    }

    public function test_loaded_transaction_mutation_is_blocked_when_transaction_is_completed(): void
    {
        $setting = $this->createSetting('TXN MUTABILITY');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $user = $this->createUserForSetting($setting, 'Txn Mutability Cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $this->openSession($setting, $terminal, $user);
        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'TXN-MUT-001']);

        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $transactionId = (int) $this->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201)
            ->json('transaction.id');

        $this->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        PosTransaction::query()->whereKey($transactionId)->update([
            'status' => PosTransaction::STATUS_COMPLETED,
        ]);

        $lineId = (int) $this->getJson(route('pos.sell.cart.show'))
            ->json('cart_snapshot.lines.0.line_id');

        $this->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
            'qty' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_FINALIZED');
    }

    public function test_checkout_without_loaded_draft_creates_completed_transaction_history_record(): void
    {
        $setting = $this->createSetting('TXN HISTORY CHECKOUT');
        [$terminal, $location] = $this->createTerminalWithLocation($setting);
        $cashier = $this->createUserForSetting($setting, 'Txn Checkout Cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.view',
        ]);
        $this->openSession($setting, $terminal, $cashier);
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id]);

        $paymentMethodId = $this->createPaymentMethodForSetting($setting);
        $customer = Customer::create([
            'setting_id' => $setting->id,
            'customer_name' => 'Customer Checkout',
            'contact_name' => 'Customer Checkout',
            'customer_email' => 'customer.checkout.' . uniqid() . '@example.com',
            'customer_phone' => '08123' . random_int(100000, 999999),
            'address' => 'Address',
            'city' => 'City',
            'country' => 'ID',
            'payment_term_id' => null,
            'tier' => null,
        ]);

        $product = $this->createStockedProduct($setting, $location, ['product_code' => 'TXN-HIST-001', 'sale_price' => 15000]);
        $this->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $this->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        $response = $this->postJson(route('pos.sell.checkout.finalize'), [
            'idempotency_key' => 'txn-history-' . uniqid(),
            'payment' => [
                'payment_method_id' => $paymentMethodId,
                'amount_paid' => 15000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');

        $this->assertDatabaseHas('pos_transactions', [
            'setting_id' => $setting->id,
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkoutId,
        ]);
    }

    private function createPaymentMethodForSetting(\Modules\Setting\Entities\Setting $setting): int
    {
        $coa = ChartOfAccount::create([
            'name' => 'TXN CASH ' . $setting->id,
            'account_number' => '1101-' . $setting->id . '-' . uniqid(),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        SettingPosPaymentMethod::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
            ],
            [
                'is_enabled' => true,
            ]
        );

        return (int) $method->id;
    }
}
