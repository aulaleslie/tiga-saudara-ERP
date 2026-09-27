<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSWalkInCustomerSelectionTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.cart.clear',
            'pos.checkout.payment',
            'pos.transactions.load',
            'pos.transactions.save',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_customer_search_is_global_and_supports_name_or_phone(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER SEARCH');
        $otherSetting = $this->createSetting('BIZ POS CUSTOMER OTHER');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER SEARCH');

        $allowedByName = $this->createCustomer($setting, 'Walk In Utama', '08123450001');
        $allowedByPhone = $this->createCustomer($setting, 'Pelanggan Lama', '08177771234');
        $crossSetting = $this->createCustomer($otherSetting, 'Walk In Lintas', '08123450001');

        $nameResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.customers.search', ['q' => 'walk in']));

        $nameResponse->assertOk();

        $nameResultIds = collect($nameResponse->json('results'))->pluck('id')->all();
        $this->assertContains($allowedByName->id, $nameResultIds);
        $this->assertContains($crossSetting->id, $nameResultIds);

        $phoneResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.customers.search', ['q' => '1234']));

        $phoneResponse->assertOk();

        $resultIds = collect($phoneResponse->json('results'))->pluck('id')->all();
        $this->assertContains($allowedByName->id, $resultIds);
        $this->assertContains($allowedByPhone->id, $resultIds);
        $this->assertContains($crossSetting->id, $resultIds);
    }

    public function test_selecting_valid_customer_sets_selected_resolution_source(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER SELECT');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER SELECT');

        $defaultCustomer = $this->createCustomer($setting, 'Walk In Default', '08110000001');
        $selectedCustomer = $this->createCustomer($setting, 'Pelanggan Prioritas', '08110000002');

        $setting->update(['pos_walk_in_customer_id' => $defaultCustomer->id]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $selectedCustomer->id,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', $selectedCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $selectedCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'selected');
    }

    public function test_clearing_customer_selection_sets_customer_to_null(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER CLEAR');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER CLEAR');

        $selectedCustomer = $this->createCustomer($setting, 'Pelanggan Tetap', '08120000002');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $selectedCustomer->id,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'none');
    }

    public function test_cross_setting_customer_selection_is_allowed(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER STRICT');
        $otherSetting = $this->createSetting('BIZ POS CUSTOMER STRICT OTHER');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER STRICT');

        $otherCustomer = $this->createCustomer($otherSetting, 'Pelanggan Asing', '08990000001');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $otherCustomer->id,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', $otherCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $otherCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'selected');
    }

    public function test_customer_selection_changes_do_not_reprice_non_tier_products(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER TOTALS');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER TOTALS');

        $customer1 = $this->createCustomer($setting, 'Pelanggan Satu', '08880000001');
        $customer2 = $this->createCustomer($setting, 'Pelanggan Dua', '08880000002');

        // Use non-tier product: pricing should remain stable across customer change
        $product = $this->createStockedProduct($setting, $location, 'SKU-CUST-001', 'Produk A', 12345, $cashier->id);

        $baseline = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 2,
            ])
            ->assertOk()
            ->json('cart_snapshot.totals.grand_total');

        $afterSelect = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer1->id,
            ])
            ->assertOk()
            ->json('cart_snapshot.totals.grand_total');

        $afterSwitch = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer2->id,
            ])
            ->assertOk()
            ->json('cart_snapshot.totals.grand_total');

        // Non-tier customers: totals should remain unchanged (base price applies)
        $this->assertSame($baseline, $afterSelect);
        $this->assertSame($baseline, $afterSwitch);
    }

    public function test_fresh_cart_on_page_load_prepopulates_default_walk_in_customer(): void
    {
        $setting = $this->createSetting('BIZ DEFAULT WALKIN LOAD');
        $walkIn = $this->createCustomer($setting, 'Pelanggan Umum Toko', '08129999001');
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier] = $this->createCashierAndOpenSession($setting, 'DEFAULT WALKIN LOAD');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $walkIn->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'walk_in')
            ->assertJsonPath('cart_snapshot.customer.selected_customer.id', $walkIn->id);
    }

    public function test_clear_cart_reapplies_default_walk_in_customer(): void
    {
        $setting = $this->createSetting('BIZ DEFAULT WALKIN CLEAR');
        $walkIn = $this->createCustomer($setting, 'Pelanggan Umum Toko', '08129999002');
        $vipCustomer = $this->createCustomer($setting, 'Pelanggan VIP', '08129999003');
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'DEFAULT WALKIN CLEAR');
        $product = $this->createStockedProduct($setting, $location, 'SKU-CLR-001', 'Produk Clear', 10000, $cashier->id);

        // Switch to VIP customer and add a product
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $vipCustomer->id])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        // Clear the cart
        $clearResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'));

        $clearResponse->assertOk()
            ->assertJsonPath('cart_snapshot.lines', [])
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $walkIn->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'walk_in');
    }

    public function test_fresh_cart_after_successful_checkout_reapplies_default_walk_in_customer(): void
    {
        $setting = $this->createSetting('BIZ DEFAULT WALKIN CHECKOUT');
        $walkIn = $this->createCustomer($setting, 'Pelanggan Umum Toko', '08129999004');
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'DEFAULT WALKIN CHECKOUT');
        $product = $this->createStockedProduct($setting, $location, 'SKU-CHK-001', 'Produk Checkout', 25000, $cashier->id);
        
        // Seed payment method
        $coaId = \DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA Cash Checkout ' . $setting->id,
            'account_number' => 'ACC-CSH-' . $setting->id,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pm = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash Checkout ' . $setting->id,
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);
        \Modules\Setting\Entities\SettingPosPaymentMethod::create([
            'setting_id' => $setting->id,
            'payment_method_id' => $pm->id,
            'is_enabled' => true,
        ]);

        // Add line to cart (customer will resolve to walkIn)
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        // Finalize checkout
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-WALKIN-CHK-' . uniqid(),
                'payment' => [
                    'payment_method_id' => $pm->id,
                    'amount_paid' => 25000,
                ],
            ])
            ->assertStatus(201);

        // Next cart read builds fresh cart with default walk-in customer resolved
        $nextCartResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        $nextCartResponse->assertOk()
            ->assertJsonPath('cart_snapshot.lines', [])
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $walkIn->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'walk_in');
    }

    public function test_no_configured_default_leaves_customer_null_on_cart_starts(): void
    {
        $setting = $this->createSetting('BIZ NO WALKIN DEFAULT');
        $setting->update(['pos_walk_in_customer_id' => null]);

        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'NO WALKIN DEFAULT');

        // First load
        $loadResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        $loadResponse->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'none');

        // Clear cart
        $clearResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'));

        $clearResponse->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'none');
    }

    public function test_two_terminals_under_same_setting_both_receive_default_independently(): void
    {
        $setting = $this->createSetting('BIZ DUAL TERMINAL WALKIN');
        $walkIn = $this->createCustomer($setting, 'Pelanggan Walkin Bersama', '08129999005');
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier1, $loc1, $session1] = $this->createCashierAndOpenSession($setting, 'DUAL T1');
        [$cashier2, $loc2, $session2] = $this->createCashierAndOpenSession($setting, 'DUAL T2');

        $response1 = $this->actingAs($cashier1)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        $response2 = $this->actingAs($cashier2)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        $response1->assertOk()
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $walkIn->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'walk_in');

        $response2->assertOk()
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $walkIn->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'walk_in');
    }

    public function test_cashier_can_override_default_and_tier_repricing_applies_normally(): void
    {
        $setting = $this->createSetting('BIZ OVERRIDE WALKIN TIER');
        $walkIn = $this->createCustomer($setting, 'Pelanggan Regular', '08129999006');
        $wholesaler = $this->createCustomer($setting, 'Pelanggan Grosir', '08129999007');
        $wholesaler->update(['tier' => 'WHOLESALER']);
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'OVERRIDE WALKIN TIER');

        $product = $this->createStockedProduct($setting, $location, 'SKU-TIER-001', 'Produk Bertingkat', 100000, $cashier->id);
        ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('setting_id', $setting->id)
            ->update([
                'sale_price' => 100000,
                'tier_1_price' => 80000,
            ]);

        // Add line with default regular customer (base price)
        $addResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        $addResponse->assertJsonPath('cart_snapshot.lines.0.unit_price', 100000);

        // Override default customer with wholesaler
        $overrideResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $wholesaler->id,
            ])
            ->assertOk();

        // Repriced to wholesaler tier 1 price (80000)
        $overrideResponse->assertJsonPath('cart_snapshot.customer.selected_customer_id', $wholesaler->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'selected')
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 80000)
            ->assertJsonPath('cart_snapshot.totals.grand_total', 80000);
    }

    public function test_loading_draft_with_different_or_no_customer_is_not_overridden_by_default(): void
    {
        $setting = $this->createSetting('BIZ DRAFT LOAD WALKIN');
        $walkIn = $this->createCustomer($setting, 'Walkin Default Setting', '08129999008');
        $otherCustomer = $this->createCustomer($setting, 'Pelanggan Khusus Draf', '08129999009');
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, 'DRAFT LOAD WALKIN');
        $product = $this->createStockedProduct($setting, $location, 'SKU-DFT-001', 'Produk Draf', 50000, $cashier->id);

        // Create a DRAFT transaction with otherCustomer
        $draftWithCustomer = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $setting->id,
            'source_pos_session_id' => $session->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'customer_id' => $otherCustomer->id,
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_DRAFT,
            'code' => 'TRX-DFT-001',
        ]);
        $draftLine1 = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $draftWithCustomer->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 1,
            'unit_price' => 50000,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'tax_rate_snapshot' => 0,
        ]);
        $mapper = app(\Modules\Pos\Services\PosTransactionSnapshotMapper::class);
        $draftWithCustomer->update([
            'snapshot_hash' => $mapper->buildSnapshotHash($draftWithCustomer->fresh()),
        ]);

        // Load the draft into cart
        $load1Response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $draftWithCustomer->id]))
            ->assertOk();

        // Customer must be otherCustomer, NOT the setting's walkIn default
        $load1Response->assertJsonPath('cart_snapshot.customer.selected_customer_id', $otherCustomer->id);

        // Clear/unload cart to test draft with NO customer
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();

        $draftWithoutCustomer = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $setting->id,
            'source_pos_session_id' => $session->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'customer_id' => null,
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_DRAFT,
            'code' => 'TRX-DFT-002',
        ]);
        $draftLine2 = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $draftWithoutCustomer->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 1,
            'unit_price' => 50000,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'tax_rate_snapshot' => 0,
        ]);
        $draftWithoutCustomer->update([
            'snapshot_hash' => $mapper->buildSnapshotHash($draftWithoutCustomer->fresh()),
        ]);

        $load2Response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $draftWithoutCustomer->id]))
            ->assertOk();

        // Customer selection remains null, but resolves to setting walk_in default
        $load2Response->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $walkIn->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'walk_in');
    }

    public function test_save_draft_without_explicit_customer_persists_resolved_walk_in_customer(): void
    {
        $setting = $this->createSetting('BIZ SAVE DRAFT WALKIN');
        $walkIn = $this->createCustomer($setting, 'Walkin Default Draft', '08129999010');
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SAVE DRAFT WALKIN');
        $product = $this->createStockedProduct($setting, $location, 'SKU-SAV-001', 'Produk Save Draft', 30000, $cashier->id);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        // Save as draft (save-and-new) without explicitly picking a customer
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = $response->json('transaction.id');
        $savedTransaction = \Modules\Pos\Entities\PosTransaction::findOrFail($transactionId);

        $this->assertEquals($walkIn->id, $savedTransaction->customer_id);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array{0: User, 1: Location, 2: PosSession}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.cart.clear', 'pos.checkout.payment', 'pos.transactions.load', 'pos.transactions.save']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location, $session];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'POS CUST LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CUST-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Customer Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function createCustomer(Setting $setting, string $name, string $phone): Customer
    {
        return Customer::create([
            'setting_id' => $setting->id,
            'contact_name' => $name,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_email' => strtolower(str_replace(' ', '.', $name)) . '.' . $setting->id . '@example.com',
            'address' => 'Address',
            'city' => 'City',
            'country' => 'Country',
        ]);
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        float $salePrice,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'POS-CUST-CAT-' . $setting->id],
            [
                'category_name' => 'POS Customer Category ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
        ]);

        return $product->fresh();
    }
}
