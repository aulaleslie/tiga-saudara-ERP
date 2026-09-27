<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

class POSCustomerStoreRegressionTest extends PosTransactionFeatureTestCase
{
    public function test_pos_customer_store_keeps_contact_name_null_and_customer_name_canonical(): void
    {
        $setting = $this->createSetting('POS Customer Store Regression');
        [$terminal] = $this->createTerminalWithLocation($setting);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS CUSTOMER STORE CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $this->openSession($setting, $terminal, $cashier);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.customers.store'), [
                'customer_name' => 'Toko ABC',
                'customer_phone' => '081299900001',
            ]);

        $responseCustomerName = $response->json('customer_name');
        $responseContactName = $response->json('contact_name');

        $response
            ->assertOk()
            ->assertJsonPath('contact_name', null)
            ->assertJsonPath('display_name', $responseCustomerName);

        $customer = Customer::query()->where('setting_id', $setting->id)->sole();

        $this->assertNull($responseContactName);
        $this->assertNull($customer->contact_name);
        $this->assertSame($responseCustomerName, $customer->customer_name);
    }
}
