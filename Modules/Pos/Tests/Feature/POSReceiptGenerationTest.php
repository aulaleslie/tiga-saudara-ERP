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
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Setting\Entities\SettingSaleLocation;
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
            'pos.receipts.reprint',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_receipt_number_follows_business_config(): void
    {
        $context = $this->createCheckoutContext('POS DEFAULT RCP');
        $methods = $context['methods'];
        $setting = $context['setting'];
        
        // No prefix set, should default to RCP
        $this->assertNull($setting->pos_receipt_prefix);

        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
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
        $methods = $context['methods'];
        $setting = $context['setting'];
        $setting->update(['pos_receipt_prefix' => 'POSX']);
        
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
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
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
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
            ->assertSee('Pelanggan')
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
        $context['cashier']->givePermissionTo('pos.receipts.reprint');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
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

    public function test_receipt_reprint_requires_explicit_permission(): void
    {
        $context = $this->createCheckoutContext('POS REPRINT FORBIDDEN');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-004B', 30000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $payload = [
            'idempotency_key' => 'receipt-k-004b',
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
            ->assertForbidden();
    }
    
    public function test_cross_setting_receipt_access_is_forbidden(): void
    {
        $context1 = $this->createCheckoutContext('POS STORE 1');
        $methods = $context1['methods'];
        $context2 = $this->createCheckoutContext('POS STORE 2');
        
        $customer = $this->assignDefaultWalkInCustomer($context1['setting']);
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
    
    public function test_receipt_shows_correct_multi_payment_nominals_and_unit_breakdown(): void
    {
        $context = $this->createCheckoutContext('POS MULTI PAY');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        // Create unit for conversion
        $rimUnit = Unit::create(['name' => 'RIM', 'short_name' => 'RIM', 'operator' => '*', 'operation_value' => 1]);
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX', 'operator' => '*', 'operation_value' => 10]);

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CONV', 200000, false);
        $standardProduct = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-STD', 50000, false);
        
        // Setup ProductUnitConversion
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $rimUnit->id,
            'base_unit_id' => $boxUnit->id,
            'conversion_factor' => 10,
        ]);

        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $context['setting']->id,
            'price' => 200000, // Price for 1 BOX (which is 10 RIM internally)
        ]);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1, $conversion->id);
        $this->addCartLine($context['cashier'], $context['setting'], $standardProduct->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        
        // Finalize with multiple payments
        // Total = 200k (conv) + 50k (std) = 250k
        $payload = [
            'idempotency_key' => 'multipay-' . uniqid(),
            'payments' => [
                ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 300000],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 50000, // Total Paid = 350k. Change = 100k
                    'reference' => 'QRIS-456'
                ],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutResponse->assertStatus(201);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');
        
        session()->put('setting_id', $context['setting']->id);

        $receiptResponse = $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt");
        
        $receiptResponse->assertStatus(200);
        $receiptResponse->assertSee('PROD-CONV')
            ->assertSee('[RIM]')
            ->assertSee('PROD-STD')
            ->assertSee('PUNIT(S)') // Base unit short name
            ->assertSee('Bayar: CASH POS')
            ->assertSee('300.000')
            ->assertSee('Kembalian')
            ->assertSee('50.000')
            ->assertDontSee('Subtotal')
            ->assertDontSee('Pajak')
            ->assertSee('Bayar: QRIS POS')
            ->assertSee('50.000')
            ->assertSee('Harga sudah termasuk PPN');
    }
    
    public function test_receipt_shows_packing_split_breakdown_for_packed_lines(): void
    {
        $context = $this->createCheckoutContext('POS PACKING');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'KERTAS-A4', 50000, false);
        $boxUnit = Unit::create(['name' => 'DUS', 'short_name' => 'DUS']);
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $product->unit_id,
            'conversion_factor' => 5,
        ]);
        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $context['setting']->id,
            'price' => 210000,
        ]);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 6, null); // Add 6 base units, will be packed!
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'pack-' . uniqid(),
            'payments' => [
                ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 260000],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutResponse->assertStatus(201);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');

        session()->put('setting_id', $context['setting']->id);

        $receiptResponse = $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt");

        $receiptResponse->assertStatus(200);
        // Just verify packed receipt contains the product and breakdown info
        $receiptResponse->assertSee('KERTAS-A4')
            ->assertSee('[DUS]');  // Box indicator from breakdown (now uses actual unit short_name)
    }
    
    public function test_receipt_shows_calculated_change_if_db_value_is_zero(): void
    {
        $context = $this->createCheckoutContext('POS CHANGE FIX');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-BASE', 100000, false);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        
        $payload = [
            'idempotency_key' => 'changefix-' . uniqid(),
            'payments' => [
                ['payment_method_id' => $methods['cash']->id, 'amount_paid' => 150000], // 50k over
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');
        
        // Manually zero out change_total to mimic the scenario
        PosCheckout::where('id', $checkoutId)->update(['change_total' => 0]);
        
        session()->put('setting_id', $context['setting']->id);

        $receiptResponse = $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt");
        
        $receiptResponse->assertStatus(200)
            ->assertSee('Kembalian')
            ->assertSee('50.000')
            ->assertSee('1 PUNIT(S)'); // Confirms baseUnit relation used
    }

    public function test_receipt_shows_selected_customer_name(): void
    {
        $context = $this->createCheckoutContext('POS RECEIPT CUSTOMER');
        $methods = $context['methods'];
        $customer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'contact_name' => 'Budi Receipt',
            'customer_name' => 'Toko Budi',
        ]);
        $expectedCustomerName = $customer->fresh()->contact_name ?: $customer->fresh()->customer_name;
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CUST', 25000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'receipt-customer-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 25000,
            ],
        ]);

        $checkoutId = $response->json('pos_checkout_id');

        session()->put('setting_id', $context['setting']->id);

        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee($expectedCustomerName);
    }

    // --- Helper Methods adapted from POSCheckoutFinalizeIdempotencyTest ---
    
    private function createCheckoutContext(string $prefix): array
    {
        $setting = $this->createSetting($prefix);
        $cashier = $this->createUserForSetting($setting, $prefix . '-cashier', [
            'pos.access', 
            'pos.sell', 
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);
        
        // Seed payment methods FIRST because openSession validates them
        $methods = $this->seedPaymentMethods($setting);
        
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

        return compact('setting', 'location', 'cashier', 'terminal', 'session', 'methods');
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
        
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

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

        SettingSaleLocation::create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
            'position' => 1,
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
            $accountNumber = "ACC-$name-" . $this->sequence++;
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $this->sequence,
                'account_number' => $accountNumber,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methodName = "$name POS";
            $method = PaymentMethod::where('name', $methodName)->first();
            if (!$method) {
                $method = PaymentMethod::create([
                    'name' => $methodName,
                    'coa_id' => $coaId,
                    'is_cash' => $isCash,
                    'requires_reference' => !$isCash,
                ]);
            }

            SettingPosPaymentMethod::firstOrCreate([
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
            ], [
                'is_enabled' => true,
            ]);

            $methods[strtolower($name)] = $method;
        }

        return $methods;
    }

        private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson('/pos/sell/cart/customer', [
                'customer_id' => $customer->id,
            ], ['X-Setting-Id' => (string) $setting->id])
            ->assertOk();
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $conversionId = null): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson('/pos/sell/cart/lines', [
                'product_id' => $productId,
                'qty' => $qty,
                'conversion_id' => $conversionId,
            ], ['X-Setting-Id' => (string) $setting->id])
            ->assertStatus(200);
    }

    private function finalize(User $cashier, Setting $setting, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson('/pos/sell/checkout/finalize', $payload, [
                'X-Setting-Id' => (string) $setting->id,
            ]);
    }

    public function test_packed_pricing_with_reseller_customer_and_zero_queries(): void
    {
        $context = $this->createCheckoutContext('POS PACKED RESELLER');
        $setting = $context['setting'];

        // Create product with base price 50000 (5000000 minor)
        $product = $this->createStockedProduct($setting, $context['location'], 'KERTAS-A4', 50000, false);

        // Setup pricing: tier_2_price = 42000 (4200000 minor)
        $priceRow = ProductPrice::query()
            ->forProduct($product->id)
            ->forSetting($setting->id)
            ->first();
        $priceRow->update(['tier_2_price' => 42000]);

        // Create box conversion: factor 5, box_price 210000
        $boxUnit = Unit::create(['name' => 'DUS', 'short_name' => 'DUS']);
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $product->unit_id,
            'conversion_factor' => 5,
        ]);
        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $setting->id,
            'price' => 210000,
        ]);

        // Create reseller customer
        $reseller = Customer::factory()->create([
            'setting_id' => $setting->id,
            'customer_name' => 'Reseller Toko',
            'tier' => 'RESELLER',
        ]);

        // Add packed line (qty 6 base units)
        $this->addCartLine($context['cashier'], $setting, $product->id, 6);

        // Verify line is marked as PACKED before selecting customer
        $cartService = app(\Modules\Pos\Services\PosCartService::class);
        $cartBefore = $cartService->getSnapshot($setting->id, $context['cashier']->id);
        $this->assertCount(1, $cartBefore['lines']);
        $this->assertSame('PACKED', $cartBefore['lines'][0]['price_source']);
        // Tax may be applied; just verify it's packed and has proper breakdown
        $this->assertNotNull($cartBefore['lines'][0]['breakdown']);
        $this->assertSame(1, $cartBefore['lines'][0]['breakdown']['box_count']);
        $this->assertSame(1, $cartBefore['lines'][0]['breakdown']['loose_count']);

        // Select reseller customer
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->selectCustomerInCart($context['cashier'], $setting, $reseller);

        // Check that selectCustomerInCart made expected DB queries (only 1 for the customer selection)
        $selectQueries = DB::getQueryLog();
        $pricingQueries = array_filter($selectQueries, fn($q) => 
            str_contains($q['query'], 'product_prices') ||
            str_contains($q['query'], 'product_unit_conversion') ||
            str_contains($q['query'], 'taxes')
        );
        $this->assertCount(0, $pricingQueries, 'Selecting customer should not trigger N+1 queries for line pricing');

        $customerQueries = array_filter($selectQueries, fn($q) => str_contains($q['query'], 'customers'));
        // 3 queries: 1 validation, 1 fetch tier, 1 resource formatting
        $this->assertCount(3, $customerQueries, 'Selecting customer should query customers table exactly 3 times (no per-line queries)');
        DB::disableQueryLog();

        // Verify line total updated to reseller price: 1 box @ 210k + 1 loose @ 42k = 252k
        $cartAfter = $cartService->getSnapshot($setting->id, $context['cashier']->id);
        $this->assertCount(1, $cartAfter['lines']);
        $line = $cartAfter['lines'][0];
        $this->assertSame('PACKED', $line['price_source']);
        // Verify tier was mapped correctly in breakdown
        $this->assertSame('tier_2', $line['breakdown']['tier']);
        // Verify loose items now use tier_2 pricing
        $this->assertSame(4200000, $line['breakdown']['loose_price_applied']); // tier_2 price in minor units

        // Now test qty update - verify tier is still cached and used correctly
        // Update qty to 11 (2 boxes + 1 loose)
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $setting->id])
            ->patchJson('/pos/sell/cart/lines/' . $line['line_id'], [
                'qty' => 11,
            ], ['X-Setting-Id' => (string) $setting->id])
            ->assertOk();

        $qtyQueries = DB::getQueryLog();
        $qtyCustomerQueries = array_filter($qtyQueries, fn($q) => str_contains($q['query'], 'customers'));
        $qtyPricingQueries = array_filter($qtyQueries, fn($q) => 
            str_contains($q['query'], 'product_prices') ||
            str_contains($q['query'], 'product_unit_conversion') ||
            str_contains($q['query'], 'taxes')
        );

        $this->assertCount(1, $qtyCustomerQueries, 'Updating qty should query customers table exactly once (for response formatting)');
        $this->assertCount(0, $qtyPricingQueries, 'Updating qty should not query pricing tables');

        DB::disableQueryLog();

        // Verify line_total updated for qty 11 (2 boxes + 1 loose)
        $cartFinal = $cartService->getSnapshot($setting->id, $context['cashier']->id);
        $lineFinal = $cartFinal['lines'][0];
        // Verify correct box/loose split
        $this->assertSame(2, $lineFinal['breakdown']['box_count']);
        $this->assertSame(1, $lineFinal['breakdown']['loose_count']);
        // Verify tier_2 is still applied
        $this->assertSame('tier_2', $lineFinal['breakdown']['tier']);

        // Finalize and verify receipt
        $payload = [
            'idempotency_key' => 'packed-reseller-' . uniqid(),
            'payments' => [
                ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 462000],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $setting, $payload);
        $checkoutResponse->assertStatus(201);
    }

    public function test_box_barcode_scan_reaches_packed_path_and_seeds_factor(): void
    {
        $context = $this->createCheckoutContext('POS PACKED BOX SCAN');
        $setting = $context['setting'];

        // Create product with base price 50000 (5000000 minor)
        $product = $this->createStockedProduct($setting, $context['location'], 'KERTAS-A4', 50000, false);

        // Setup pricing: tier_2_price = 42000 (4200000 minor)
        $priceRow = ProductPrice::query()
            ->forProduct($product->id)
            ->forSetting($setting->id)
            ->first();
        $priceRow->update(['tier_2_price' => 42000]);

        // Create box conversion: factor 5, box_price 210000
        $boxUnit = Unit::create(['name' => 'DUS', 'short_name' => 'DUS']);
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $product->unit_id,
            'conversion_factor' => 5,
        ]);
        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $setting->id,
            'price' => 210000,
        ]);

        // Create reseller customer
        $reseller = Customer::factory()->create([
            'setting_id' => $setting->id,
            'customer_name' => 'Reseller Toko',
            'tier' => 'RESELLER',
        ]);
        
        $this->selectCustomerInCart($context['cashier'], $setting, $reseller);

        // Step 1: Add product via search (qty 5 base units)
        $this->addCartLine($context['cashier'], $setting, $product->id, 5, null);

        $cartService = app(\Modules\Pos\Services\PosCartService::class);
        $cartBefore = $cartService->getSnapshot($setting->id, $context['cashier']->id);
        $this->assertCount(1, $cartBefore['lines']);
        
        // Step 2: Add product via box scan (qty 1, conversion_id set). 
        // addCartLine sends qty=1, but service should multiply by factor 5 = 5 base units.
        // This should merge with the existing line, resulting in qty 10 (2 boxes)
        $this->addCartLine($context['cashier'], $setting, $product->id, 1, $conversion->id);

        $cartAfter = $cartService->getSnapshot($setting->id, $context['cashier']->id);
        
        // Assert criteria 1, 2, 3, 4, 5
        $this->assertCount(1, $cartAfter['lines'], 'Product search and box scan should merge into one line');
        
        $line = $cartAfter['lines'][0];
        $this->assertSame('PACKED', $line['price_source']);
        $this->assertSame(10, $line['qty']);
        $this->assertSame(2, $line['breakdown']['box_count']);
        $this->assertSame(0, $line['breakdown']['loose_count']);
        $this->assertSame('tier_2', $line['breakdown']['tier']);
        $this->assertEquals(420000, $line['line_total']); // 2 boxes * 210000
        
        // Now test qty update - verify zero queries
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $setting->id])
            ->patchJson('/pos/sell/cart/lines/' . $line['line_id'], [
                'qty' => 11,
            ], ['X-Setting-Id' => (string) $setting->id])
            ->assertOk();

        $qtyQueries = \Illuminate\Support\Facades\DB::getQueryLog();
        $qtyPricingQueries = array_filter($qtyQueries, fn($q) => 
            str_contains($q['query'], 'product_prices') ||
            str_contains($q['query'], 'product_unit_conversion') ||
            str_contains($q['query'], 'taxes')
        );

        $this->assertCount(0, $qtyPricingQueries, 'Updating qty should not query pricing tables');

        \Illuminate\Support\Facades\DB::disableQueryLog();

        $cartFinal = $cartService->getSnapshot($setting->id, $context['cashier']->id);
        $lineFinal = $cartFinal['lines'][0];
        $this->assertSame(2, $lineFinal['breakdown']['box_count']);
        $this->assertSame(1, $lineFinal['breakdown']['loose_count']);
        $this->assertEquals(462000, $lineFinal['line_total']); // 2 boxes * 210000 + 1 loose * 42000 = 462000
    }
}
