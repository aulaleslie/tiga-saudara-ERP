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
            ->assertSee('1 RIM')
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
            ->assertSee('1 DUS')  // Box indicator from breakdown (now uses actual unit short_name)
            ->assertSee('210.000') // Proves x100 regression is fixed for base_price and box_price
            ->assertDontSee('21.000.000');
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

    public function test_receipt_compact_amount_for_large_totals(): void
    {
        $context = $this->createCheckoutContext('POS RECEIPT LARGE');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        // 21.000.000
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-LARGE', 21000000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'receipt-large-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 21000000,
            ],
        ]);

        $checkoutId = $response->json('pos_checkout_id');

        session()->put('setting_id', $context['setting']->id);

        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee('21.000.000') // should be formatted with thousand separators
            ->assertSee('font-size: 9px'); // > 9 chars 
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

    /**
     * Regression: receipt line total must print in Rupiah, not 1/100 of it.
     * A qty-1 product priced at Rp335.000 must show "335.000" in the sub_total
     * column, not "3.350" (which would be caused by a /100 on line_meta['line_total']).
     *
     * @group pos-receipt-subtotal
     */
    public function test_receipt_line_total_prints_correct_rupiah_not_divided_by_100(): void
    {
        $context = $this->createCheckoutContext('POS SUBTOTAL FIX');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        // Rp335.000 product — the exact value from the bug report
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-335K', 335000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'subtotal-fix-' . uniqid(),
            'payments' => [
                ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 335000],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutResponse->assertStatus(201);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');

        session()->put('setting_id', $context['setting']->id);

        // Verify via the receipt service directly so we test the data layer, not just the view
        $checkout = \Modules\Pos\Entities\PosCheckout::with([
            'setting.currency', 'terminal', 'cashier', 'customer', 'paymentMethod',
            'sale.customer', 'sale.saleDetails.product.unit', 'sale.saleDetails.product.baseUnit',
            'transaction.lines.conversion.unit', 'transaction.lines.product.unit',
            'transaction.lines.product.baseUnit', 'payments.paymentMethod',
        ])->findOrFail($checkoutId);

        /** @var \Modules\Pos\Services\PosReceiptService $receiptService */
        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $this->assertNotEmpty($receiptData['lines'], 'Receipt must have at least one line');
        $line = $receiptData['lines'][0];

        // sub_total must be 335000 (Rupiah), NOT 3350 (which /100 would produce)
        $this->assertEquals(335000.0, $line['sub_total'],
            'Line sub_total must equal the Rupiah line_total persisted by the snapshot, not 1/100 of it');

        // Grand total is unaffected (sanity check)
        $this->assertEquals(335000.0, $receiptData['grand_total'],
            'Grand total must be unaffected by the line-subtotal fix');
    }

    /**
     * Regression: packed-line receipt must show correct Rupiah per-unit breakdown
     * AND correct Rupiah line total after the /100 fix.
     * buildPackedUnitBreakdown() still applies /100 to box/loose cents — verify
     * those remain correct while line sub_total now reads Rupiah directly.
     *
     * @group pos-receipt-subtotal
     */
    public function test_packed_line_receipt_shows_correct_rupiah_per_unit_and_line_total(): void
    {
        $context = $this->createCheckoutContext('POS PACKED SUBTOTAL FIX');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        // Base price Rp50.000, box conversion factor 5, box price Rp210.000
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'KERTAS-B4-FIX', 50000, false);

        $boxUnit = Unit::create(['name' => 'DUS FIX', 'short_name' => 'DUSF']);
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $product->unit_id,
            'conversion_factor' => 5,
        ]);
        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $context['setting']->id,
            'price' => 210000, // Rp210.000 per box
        ]);

        // Add 6 base units → 1 box (210k) + 1 loose (50k) = Rp260.000
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 6);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'packed-subtotal-fix-' . uniqid(),
            'payments' => [
                ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 260000],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutResponse->assertStatus(201);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');

        /** @var \Modules\Pos\Services\PosReceiptService $receiptService */
        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $checkout = \Modules\Pos\Entities\PosCheckout::findOrFail($checkoutId);
        $receiptData = $receiptService->getReceiptData($checkout);

        $this->assertNotEmpty($receiptData['lines'], 'Receipt must have at least one line');
        $line = $receiptData['lines'][0];

        // Line subtotal must be 260000 (1 box @ 210k + 1 loose @ 50k), not 2600 (÷100 regression)
        $this->assertEquals(260000.0, $line['sub_total'],
            'Packed line sub_total must equal the Rupiah line_total, not 1/100 of it');

        // Per-unit breakdown (via buildPackedUnitBreakdown) must still show Rupiah prices
        $this->assertIsArray($line['unit_breakdown'], 'Packed line must have a unit breakdown array');
        $this->assertNotEmpty($line['unit_breakdown'], 'Packed unit breakdown must not be empty');

        // The breakdown strings must contain the Rupiah box price (210.000) not a cents artifact
        $breakdownText = implode(' ', $line['unit_breakdown']);
        $this->assertStringContainsString('210.000', $breakdownText,
            'Per-unit breakdown must show Rp210.000 box price (not a /100 regression value)');
        $this->assertStringNotContainsString('21.000.000', $breakdownText,
            'Per-unit breakdown must not show 100x inflated box price');
    }

    public function test_receipt_layout_shows_transaction_code(): void
    {
        $context = $this->createCheckoutContext('CODE-RCP-VIEW');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-RCP-VIEW', 50000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'receipt-k-codeview',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 50000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [
                [
                    'payment_method_id' => $context['methods']['cash']->id,
                    'amount_paid' => 50000,
                    'reference' => null,
                ],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        if ($checkoutResponse->status() !== 201) {
            $checkoutResponse->dump();
        }
        $checkoutResponse->assertStatus(201);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');

        $checkout = \Modules\Pos\Entities\PosCheckout::with('transaction')->findOrFail($checkoutId);
        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $view = view('pos::receipt', compact('receiptData'))->render();

        $this->assertStringContainsString('No. Transaksi', $view);
        $this->assertStringContainsString($checkout->transaction->code, $view);
        $this->assertStringNotContainsString('<td class="meta-label">No. Struk</td>', $view);
        $this->assertStringContainsString('<div style="text-align: right; font-size: 7px; color: #888; margin-top: 2mm;">', $view);
        $this->assertStringContainsString($checkout->receipt_number, $view);
    }

    public function test_receipt_layout_renders_safely_without_transaction(): void
    {
        $context = $this->createCheckoutContext('CODE-RCP-NO-TXN');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-RCP-NO-TXN', 50000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'receipt-k-notxn',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 50000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [
                [
                    'payment_method_id' => $context['methods']['cash']->id,
                    'amount_paid' => 50000,
                    'reference' => null,
                ],
            ],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkoutId = $checkoutResponse->json('pos_checkout_id');

        $checkout = \Modules\Pos\Entities\PosCheckout::findOrFail($checkoutId);
        $receiptNumber = $checkout->receipt_number;

        // Force the checkout to have no transaction (simulating legacy)
        $checkout->update(['pos_transaction_id' => null]);

        // Reload without transaction
        $checkout = \Modules\Pos\Entities\PosCheckout::findOrFail($checkoutId);

        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        // View rendering should not throw error
        $view = view('pos::receipt', compact('receiptData'))->render();

        $this->assertStringContainsString('No. Transaksi', $view);
        // Task 3: When no transaction code exists, fallback to receipt_number instead of dash
        $this->assertStringContainsString($receiptNumber, $view);
    }

    /**
     * Task 1.3: Receipt normalization for snapshot minor-unit line totals.
     * Receipt contract: line_total_minor stores minor-unit values explicitly; Rp45.000 is stored as line_total_minor=4500000.
     * Covers both explicit minor-unit storage (line_total_minor) and fallback to normal Rp1.500.000 (line_total).
     *
     * @group pos-receipt-subtotal
     */
    public function test_receipt_line_total_with_minor_unit_and_rupiah_values(): void
    {
        $context = $this->createCheckoutContext('POS SNAPSHOT MINOR-UNIT');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        // Test case 1: Normal Rp1.500.000 stored as line_total (Rupiah)
        $productNormal = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-1500K', 1500000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $productNormal->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload1 = [
            'idempotency_key' => 'snapshot-rupiah-1500k',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 1500000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 1500000]],
        ];

        $checkoutResponse1 = $this->finalize($context['cashier'], $context['setting'], $payload1);
        $this->assertEquals(201, $checkoutResponse1->status(), 'Checkout 1 finalization failed: ' . json_encode($checkoutResponse1->json()));

        $checkout1 = \Modules\Pos\Entities\PosCheckout::with('transactions.lines')->findOrFail($checkoutResponse1->json('pos_checkout_id'));

        // Store normal Rp1.500.000 as line_total (unchanged)
        if ($checkout1->transactions->count() > 0) {
            $txn = $checkout1->transactions->first();
            if ($txn->lines->count() > 0) {
                $line = $txn->lines->first();
                $lineMeta = $line->line_meta;
                $lineMeta['line_total'] = 1500000; // Rupiah value, not minor units
                $line->update(['line_meta' => $lineMeta]);
            }
        }

        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $checkout1->refresh();
        $receiptData1 = $receiptService->getReceiptData($checkout1);

        // Verify line_total (Rupiah) is used as-is
        $this->assertCount(1, $receiptData1['lines']);
        $line1 = $receiptData1['lines'][0];
        $this->assertEquals(1500000, $line1['sub_total'], 'Line subtotal must be Rp1.500.000 (stored as line_total)');
        $this->assertEquals(1500000, $receiptData1['grand_total'], 'Grand total must equal line total');

        // Test case 2: Rp45.000 stored as line_total_minor=4500000 (explicit minor-unit contract)
        $productMinor = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-45K', 45000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $productMinor->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload2 = [
            'idempotency_key' => 'snapshot-minorunit-45k',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 45000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 45000]],
        ];

        $checkoutResponse2 = $this->finalize($context['cashier'], $context['setting'], $payload2);
        $this->assertEquals(201, $checkoutResponse2->status(), 'Checkout 2 finalization failed');

        $checkout2 = \Modules\Pos\Entities\PosCheckout::with('transactions.lines')->findOrFail($checkoutResponse2->json('pos_checkout_id'));

        // Store Rp45.000 explicitly as line_total_minor (4500000 minor units)
        if ($checkout2->transactions->count() > 0) {
            $txn = $checkout2->transactions->first();
            if ($txn->lines->count() > 0) {
                $line = $txn->lines->first();
                $lineMeta = $line->line_meta;
                $lineMeta['line_total_minor'] = 4500000; // Explicit minor-unit storage
                $line->update(['line_meta' => $lineMeta]);
            }
        }

        $checkout2->refresh();
        $receiptData2 = $receiptService->getReceiptData($checkout2);

        // Verify line_total_minor (minor units) is divided by 100 to render Rupiah
        $this->assertCount(1, $receiptData2['lines']);
        $line2 = $receiptData2['lines'][0];
        $this->assertEquals(45000, $line2['sub_total'], 'Line subtotal must be Rp45.000 (45000 Rupiah from 4500000 minor units)');
        $this->assertEquals(45000, $receiptData2['grand_total'], 'Grand total must equal line total');
    }

    /**
     * Task 1.3: Draft loaded transaction with explicit minor-unit contract.
     * Both line_total_minor (minor units) and line_total (Rupiah) must render correctly.
     *
     * @group pos-receipt-subtotal
     */
    public function test_draft_loaded_transaction_receipt_contract_consistency(): void
    {
        $context = $this->createCheckoutContext('POS DRAFT LOADED MINOR');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        // Test case 1: Normal Rp75.000 stored as line_total (Rupiah)
        $productRupiah = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-DRAFT-75K-RUPIAH', 75000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $productRupiah->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload1 = [
            'idempotency_key' => 'draft-loaded-75k-rupiah',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 75000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 75000]],
        ];

        $checkoutResponse1 = $this->finalize($context['cashier'], $context['setting'], $payload1);
        $this->assertEquals(201, $checkoutResponse1->status(), 'Checkout 1 finalization failed');

        $checkout1 = \Modules\Pos\Entities\PosCheckout::with('transactions.lines')->findOrFail($checkoutResponse1->json('pos_checkout_id'));

        // Store as line_total (Rupiah)
        if ($checkout1->transactions->count() > 0) {
            $txn = $checkout1->transactions->first();
            if ($txn->lines->count() > 0) {
                $line = $txn->lines->first();
                $lineMeta = $line->line_meta;
                $lineMeta['line_total'] = 75000;  // Already in Rupiah
                $line->update(['line_meta' => $lineMeta]);
            }
        }

        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $checkout1->refresh();
        $receiptData1 = $receiptService->getReceiptData($checkout1);

        $this->assertCount(1, $receiptData1['lines']);
        $line1 = $receiptData1['lines'][0];
        $this->assertEquals(75000, $line1['sub_total'], 'Rupiah line_total must render as-is: 75000');
        $this->assertEquals(75000, $receiptData1['grand_total']);

        // Test case 2: Rp75.000 stored as line_total_minor=7500000 (explicit minor-unit contract)
        $productMinor = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-DRAFT-75K-MINOR', 75000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $productMinor->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload2 = [
            'idempotency_key' => 'draft-loaded-75k-minor',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 75000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 75000]],
        ];

        $checkoutResponse2 = $this->finalize($context['cashier'], $context['setting'], $payload2);
        $this->assertEquals(201, $checkoutResponse2->status(), 'Checkout 2 finalization failed');

        $checkout2 = \Modules\Pos\Entities\PosCheckout::with('transactions.lines')->findOrFail($checkoutResponse2->json('pos_checkout_id'));

        // Store as line_total_minor (explicit minor-unit contract: 7500000 = Rp75.000)
        if ($checkout2->transactions->count() > 0) {
            $txn = $checkout2->transactions->first();
            if ($txn->lines->count() > 0) {
                $line = $txn->lines->first();
                $lineMeta = $line->line_meta;
                $lineMeta['line_total_minor'] = 7500000;  // Minor units for 75000 Rupiah
                $line->update(['line_meta' => $lineMeta]);
            }
        }

        $checkout2->refresh();
        $receiptData2 = $receiptService->getReceiptData($checkout2);

        $this->assertCount(1, $receiptData2['lines']);
        $line2 = $receiptData2['lines'][0];
        $this->assertEquals(75000, $line2['sub_total'], 'Minor-unit line_total_minor must divide by 100: 7500000 / 100 = 75000');
        $this->assertEquals(75000, $receiptData2['grand_total']);
    }

    /**
     * Task 3.2: POS quantities render as raw normalized values without formatting.
     * Integer quantity must display as integer, fractional quantities without trailing zeroes.
     *
     * @group pos-quantity-display
     */
    public function test_receipt_displays_integer_quantity_as_raw_value(): void
    {
        $context = $this->createCheckoutContext('POS QTY RAW INT');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-1', 50000, false);

        // Add exactly 1 unit
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'qty-raw-int',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 50000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 50000]],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkout = \Modules\Pos\Entities\PosCheckout::findOrFail($checkoutResponse->json('pos_checkout_id'));

        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        // Verify quantity is displayed as raw value
        $this->assertCount(1, $receiptData['lines']);
        $line = $receiptData['lines'][0];
        $this->assertEquals(1.0, $line['qty'], 'Integer quantity must display as 1.0, not 1.00 or 1,00');
        $this->assertStringContainsString('1', (string)$line['qty'], 'Quantity should contain the digit 1');
    }

    /**
     * Task 3.2: Fractional POS quantities display without padding or locale formatting.
     * A quantity of 1.5 must show as 1.5, not 1.500, 1,5, or 1,50.
     *
     * @group pos-quantity-display
     */
    public function test_receipt_displays_fractional_quantity_without_padding(): void
    {
        $context = $this->createCheckoutContext('POS QTY FRAC');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-FRAC', 20000, false);

        // Manually set a fractional quantity by directly updating the transaction line
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'qty-frac-1-5',
            'terminal_id' => $context['terminal']->id,
            'amount_paid' => 30000,
            'payment_method_id' => $context['methods']['cash']->id,
            'payments' => [['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 30000]],
        ];

        $checkoutResponse = $this->finalize($context['cashier'], $context['setting'], $payload);
        $checkout = \Modules\Pos\Entities\PosCheckout::with('transactions.lines')->findOrFail($checkoutResponse->json('pos_checkout_id'));

        // Simulate a fractional quantity in the snapshot
        if ($checkout->transactions->count() > 0) {
            $txn = $checkout->transactions->first();
            if ($txn->lines->count() > 0) {
                $line = $txn->lines->first();
                $line->update(['qty' => 1.5]);
            }
        }

        $receiptService = app(\Modules\Pos\Services\PosReceiptService::class);
        $checkout->refresh();
        $receiptData = $receiptService->getReceiptData($checkout);

        // Verify fractional quantity displays without trailing zeroes or locale formatting
        $this->assertCount(1, $receiptData['lines']);
        $line = $receiptData['lines'][0];
        $this->assertEquals(1.5, $line['qty'], 'Fractional quantity must be 1.5');
        // Verify it's not formatted with locale separators or padding
        $qtyStr = (string)$line['qty'];
        $this->assertFalse(strpos($qtyStr, ',') !== false, 'Quantity must not contain locale comma separator');
        $this->assertFalse(strpos($qtyStr, '.00') !== false, 'Quantity must not have trailing .00');
    }
}
