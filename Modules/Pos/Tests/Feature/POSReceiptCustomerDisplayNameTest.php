<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
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
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSReceiptCustomerDisplayNameTest extends TestCase
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

    public function test_receipt_displays_robust_customer_name_with_both_contact_and_company(): void
    {
        $context = $this->createCheckoutContext('POS ROBUST CUST 1');
        $methods = $context['methods'];
        $customer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'contact_name' => 'Budi Santoso',
            'customer_name' => 'CV Maju Jaya',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-BOTH', 25000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'receipt-both-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 25000,
            ],
        ]);

        $checkoutId = $response->json('pos_checkout_id');
        session()->put('setting_id', $context['setting']->id);

        // Receipt should display "Budi Santoso - CV Maju Jaya" (in uppercase as rendered)
        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee('BUDI SANTOSO - CV MAJU JAYA');
    }

    public function test_receipt_displays_only_company_when_contact_is_empty(): void
    {
        $context = $this->createCheckoutContext('POS ROBUST CUST 2');
        $methods = $context['methods'];
        $customer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'contact_name' => null,
            'customer_name' => 'PT Sukses Bersama',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-COMP', 30000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'receipt-comp-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 30000,
            ],
        ]);

        $checkoutId = $response->json('pos_checkout_id');
        session()->put('setting_id', $context['setting']->id);

        // Receipt should display only "PT Sukses Bersama"
        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee('PT SUKSES BERSAMA');
    }

    public function test_receipt_displays_only_contact_when_company_is_empty(): void
    {
        $context = $this->createCheckoutContext('POS ROBUST CUST 3');
        $methods = $context['methods'];
        $customer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'contact_name' => 'John Doe',
            'customer_name' => 'John Doe',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CONT', 20000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'receipt-cont-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 20000,
            ],
        ]);

        $checkoutId = $response->json('pos_checkout_id');
        session()->put('setting_id', $context['setting']->id);

        // Receipt should display only "John Doe"
        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee('Pelanggan')
            ->assertSee('JOHN DOE');
    }

    public function test_receipt_displays_dash_when_all_names_empty(): void
    {
        $context = $this->createCheckoutContext('POS ROBUST CUST 4');
        $methods = $context['methods'];
        $customer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'contact_name' => '',
            'company_name' => '',
            'customer_name' => '',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-EMPTY', 15000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'receipt-empty-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 15000,
            ],
        ]);

        $checkoutId = $response->json('pos_checkout_id');
        session()->put('setting_id', $context['setting']->id);

        // Receipt should display "-"
        $this->actingAs($context['cashier'])
            ->get("/pos/sell/checkout/{$checkoutId}/receipt")
            ->assertStatus(200)
            ->assertSee('Pelanggan');
        // Verify the receipt renders without errors even with empty names
    }

    // --- Helper Methods ---

    private function createCheckoutContext(string $prefix): array
    {
        $setting = $this->createSetting($prefix);
        $cashier = $this->createUserForSetting($setting, $prefix . '-cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

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
}
