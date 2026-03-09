<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
// Removed redundant import
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSReceiptGenerationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_receipt_number_follows_business_config(): void
    {
        $context = $this->createCheckoutContext('POS DEFAULT RCP');
        $methods = $this->seedPaymentMethods($context['setting']);
        $setting = $context['setting'];
        
        // No prefix set, should default to RCP
        $this->assertNull($setting->pos_receipt_prefix);

        $customer = $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-001', 50000, false);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $payload = [
            'idempotency_key' => 'receipt-k-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 50000,
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(201);

        $checkoutId = $response->json('pos_checkout_id');
        $receiptNumber = $response->json('receipt_number');

        $this->assertNotEmpty($receiptNumber);
        $this->assertStringContainsString('RCP-', $receiptNumber);

        // Verify DB storage
        $this->assertDatabaseHas('pos_checkouts', [
            'id' => $checkoutId,
            'receipt_number' => $receiptNumber
        ]);
    }
    
    public function test_receipt_number_uses_custom_prefix(): void
    {
        $context = $this->createCheckoutContext('POS CUSTOM PREFIX');
        $methods = $this->seedPaymentMethods($context['setting']);
        $setting = $context['setting'];
        $setting->update(['pos_receipt_prefix' => 'POSX']);
        
        $customer = $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-002', 100000, false);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $payload = [
            'idempotency_key' => 'receipt-k-002',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(201);

        $receiptNumber = $response->json('receipt_number');
        $this->assertStringContainsString('POSX-', $receiptNumber);
    }
    
    public function test_receipt_view_creates_print_log(): void
    {
        $context = $this->createCheckoutContext('POS PRINT VIEW');
        $methods = $this->seedPaymentMethods($context['setting']);
        $customer = $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-003', 25000, false);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $payload = [
            'idempotency_key' => 'receipt-k-003',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 25000,
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');
        $receiptNumber = $checkoutResponse->json('receipt_number');
        
        session()->put('setting_id', $context['setting']->id);

        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee($receiptNumber)
            ->assertSee('Cetak Struk')
            ->assertSee('PROD-003');
            
        // Assert log was created
        $this->assertDatabaseHas('pos_receipt_print_logs', [
            'pos_checkout_id' => $checkoutId,
            'print_type' => PosReceiptPrintLog::TYPE_PRINT,
            'printed_by' => $context['cashier']->id,
        ]);
        
        $this->assertEquals(1, PosReceiptPrintLog::where('pos_checkout_id', $checkoutId)->count());
    }
    
    public function test_receipt_reprint_creates_separate_log(): void
    {
        $context = $this->createCheckoutContext('POS REPRINT VIEW');
        $methods = $this->seedPaymentMethods($context['setting']);
        $customer = $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-004', 30000, false);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $payload = [
            'idempotency_key' => 'receipt-k-004',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 30000,
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');
        
        session()->put('setting_id', $context['setting']->id);

        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt/reprint")
            ->assertStatus(200);
            
        $this->assertDatabaseHas('pos_receipt_print_logs', [
            'pos_checkout_id' => $checkoutId,
            'print_type' => PosReceiptPrintLog::TYPE_REPRINT,
            'printed_by' => $context['cashier']->id,
        ]);
    }
    
    public function test_cross_setting_receipt_access_is_forbidden(): void
    {
        $context1 = $this->createCheckoutContext('POS STORE 1');
        $methods = $this->seedPaymentMethods($context1['setting']);
        $context2 = $this->createCheckoutContext('POS STORE 2');
        
        $customer = $customer = $this->assignDefaultWalkInCustomer($context1['setting']);
        $product = $this->createStockedProduct($context1['setting'], $context1['location'], 'PROD-FORBID', 10000, false);
        $this->addCartLine($context1['cashier'], $context1['setting'], $product->id, 1);
        $this->selectCustomerInCart($context1['cashier'], $context1['setting'], $customer);
        $payload = [
            'idempotency_key' => 'receipt-k-forbid',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 10000,
            ],
        ];

        $checkoutResponse = $this->finalize($context1['cashier'], $context1['setting'], $payload);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');
        
        // Attempt to view using cashier 2's session
        session()->put('setting_id', $context2['setting']->id);
        
        $this->actingAs($context2['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(403);
    }
    
    // --- Helper Methods adapted from POSCheckoutFinalizeIdempotencyTest ---
    
    private function createCheckoutContext(string $prefix): array
    {
        $setting = $this->createSetting($prefix);
        $cashier = $this->createUserForSetting($setting, $prefix . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return compact('setting', 'location', 'cashier', 'terminal', 'session');
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.receipt.' . $suffix . '@example.com',
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
            'is_pkp' => false,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $name, array $permissions): User
    {
        $index = $this->sequence++;
        $user = clone User::factory()->create([
            'name' => $name,
            'email' => "user.{$index}@example.com",
        ]);

        $roleName = 'pos_role_' . $index;
        $role = Role::findOrCreate($roleName, 'web');
        $role->givePermissionTo($permissions);
        
        $user->assignRole($role);
        $setting->users()->attach($user->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS RECEIPT LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CHECKOUT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Checkout Terminal ' . $index,
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

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice,
        bool $serialRequired
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 20,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 20,
            'quantity_non_tax' => 20,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];
        
        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $this->sequence,
                'account_number' => "ACC-$name-" . $this->sequence++,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = PaymentMethod::create([
                'name' => "$name POS",
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);
        }

        return $methods;
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

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->postJson('/pos/sell/cart/lines', [
                'product_id' => $productId,
                'qty' => $qty,
            ], ['X-Setting-Id' => (string) $setting->id])
            ->assertStatus(200);
    }

    private function finalize(User $cashier, Setting $setting, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($cashier)
            ->postJson('/pos/sell/checkout/finalize', $payload, [
                'X-Setting-Id' => (string) $setting->id,
            ]);
    }
}
