<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSCheckoutSplitPostingTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config(['pos.checkout.split_posting.enabled' => true]);

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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_finalize_posts_multi_group_split_and_keeps_legacy_compatibility_fields(): void
    {
        $context = $this->createSplitCheckoutContext();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-POST-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonCount(2, 'split_groups')
            ->assertJsonCount(2, 'sales')
            ->assertJsonCount(2, 'sale_payments');

        $payload = $response->json();
        $splitGroups = $payload['split_groups'];

        $this->assertSame($splitGroups[0]['sale_id'], $payload['sale_id']);
        $this->assertSame($splitGroups[0]['sale_payment_id'], $payload['sale_payment_id']);
        $this->assertSame($splitGroups[0]['dispatch_ids'], $payload['dispatch_ids']);

        $this->assertSame(200000.0, round(array_sum(array_column($splitGroups, 'grand_total')), 2));
        $this->assertDatabaseCount('pos_checkouts', 1);
        $this->assertDatabaseCount('pos_checkout_sales', 2);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('sale_payments', 2);
    }

    public function test_finalize_replay_returns_same_split_map_without_duplicate_posting_side_effects(): void
    {
        $context = $this->createSplitCheckoutContext();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $payload = [
            'idempotency_key' => 'K-SPLIT-REPLAY-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ];

        $first = $this->finalize($context['cashier'], $context['setting'], $payload);
        $first->assertStatus(201)->assertJsonPath('idempotent_replay', false);

        $second = $this->finalize($context['cashier'], $context['setting'], $payload);
        $second->assertStatus(200)->assertJsonPath('idempotent_replay', true);

        $firstPayload = $first->json();
        $secondPayload = $second->json();
        $secondPayload['idempotent_replay'] = false;

        $this->assertEquals($firstPayload, $secondPayload);
        $this->assertDatabaseCount('pos_checkouts', 1);
        $this->assertDatabaseCount('pos_checkout_sales', 2);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('sale_payments', 2);
    }

    private function createSplitCheckoutContext(): array
    {
        $setting = $this->createSetting('POS SPLIT BIZ');
        $cashier = $this->createUserForSetting($setting, 'pos split cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        [$terminal, $locations] = $this->createTerminalAndSaleLocations($setting);
        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $tax = Tax::query()->create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);
        $product = $this->createSplitStockProduct($setting, $locations[0], $locations[1], $tax);

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'session' => $session,
            'methods' => $methods,
            'customer' => $customer,
            'product' => $product,
            'tax' => $tax,
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => "pos.split.{$suffix}@example.com",
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
            'is_pkp' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array{0: PosTerminal, 1: array<int, Location>}
     */
    private function createTerminalAndSaleLocations(Setting $setting): array
    {
        $index = $this->sequence++;

        $locationA = Location::create([
            'name' => 'SPLIT LOC A ' . $index,
            'setting_id' => $setting->id,
        ]);
        $locationB = Location::create([
            'name' => 'SPLIT LOC B ' . $index,
            'setting_id' => $setting->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locationB->id],
            ['is_enabled' => true, 'position' => 2]
        );

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SPLIT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Split Terminal ' . $index,
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

        return [$terminal, [$locationA, $locationB]];
    }

    private function openSession(Setting $setting, PosTerminal $terminal, User $cashier)
    {
        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);

        return $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
    }

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $setting->update([
            'pos_walk_in_customer_id' => $customer->id,
        ]);

        return $customer;
    }

    private function createSplitStockProduct(Setting $setting, Location $locationA, Location $locationB, Tax $tax): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;
        $index = $this->sequence++;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-SPLIT-CAT-' . $index],
            [
                'category_name' => 'POS SPLIT CATEGORY ' . $index,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT SPLIT',
            'short_name' => 'PUS',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'POS SPLIT PRODUCT ' . $index,
            'product_code' => 'POS-SPLIT-' . $index,
            'barcode' => 'POS-SPLIT-BAR-' . $index,
            'product_quantity' => 11,
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_unit' => 'PUS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $locationA->id,
            'quantity' => 1,
            'quantity_non_tax' => 0,
            'quantity_tax' => 1,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $locationB->id,
            'quantity' => 10,
            'quantity_non_tax' => 0,
            'quantity_tax' => 10,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => 100000,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 50000,
            'average_purchase_price' => 50000,
            'purchase_tax_id' => null,
            'sale_tax_id' => $tax->id,
        ]);

        return $product;
    }

    /**
     * @return array<string, PaymentMethod>
     */
    private function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $index = $this->sequence++;
        $methods = [];

        $cashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS SPLIT COA CASH ' . $index,
            'account_number' => 'POS-SPLIT-CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods['cash'] = PaymentMethod::query()->create([
            'name' => 'CASH SPLIT ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        if ($enableForSetting) {
            DB::table('setting_pos_payment_methods')->updateOrInsert(
                [
                    'setting_id' => $setting->id,
                    'payment_method_id' => $methods['cash']->id,
                ],
                [
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $methods;
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ]);

        if ($response->status() !== 200) {
            $locationIds = SalesLocationResolver::resolveLocationIds($setting->id)->all();
            $availableQty = (int) DB::table('product_stocks')
                ->where('product_id', $productId)
                ->whereIn('location_id', $locationIds)
                ->sum('quantity');

            $this->fail(
                'addCartLine failed with status '
                . $response->status()
                . ': '
                . $response->getContent()
                . ' [location_ids='
                . json_encode($locationIds)
                . ', available_qty='
                . $availableQty
                . ']'
            );
        }
    }

    private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}
