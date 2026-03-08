<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
// Removed redundant import
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
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
class POSSerialValidationCheckoutTest extends TestCase
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

    public function test_can_assign_serials_to_cart_line(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL ASSIGN');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SER-001', 100000, true);
        
        // Create 2 active serials
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-001');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-002');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-001', 'SN-002'],
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-001', 'SN-002']);
    }

    public function test_assigning_duplicate_serials_fails(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL DUP');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SER-DUP', 100000, true);
        $this->createSerialNumber($product, $context['location'], 'SN-DUP');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-DUP', 'SN-DUP'],
            ])
            ->assertStatus(422);
    }

    public function test_assigning_serial_not_belonging_to_product_fails(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL WRONG PROD');
        $product1 = $this->createStockedProduct($context['setting'], $context['location'], 'P1', 100000, true);
        $product2 = $this->createStockedProduct($context['setting'], $context['location'], 'P2', 100000, true);
        $this->createSerialNumber($product2, $context['location'], 'SN-PROD2');

        $this->addCartLine($context['cashier'], $context['setting'], $product1->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-PROD2'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Serial number SN-PROD2 does not exist for this product.');
    }

    public function test_finalize_without_serial_assignment_fails(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL MISSING');
        $methods = $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SER-MISSING', 100000, true);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SERIAL-MISSING-001',
            'payment' => [
                'payment_method_id' => $methods->cash->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_INVALID')
            ->assertJsonPath('message', 'Product PROD-SER-MISSING NAME requires 1 serial number(s) but 0 assigned.');
    }

    public function test_successful_serial_checkout_updates_lifecycle(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL SUCCESS');
        $methods = $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SER-OK', 100000, true);
        $sn = $this->createSerialNumber($product, $context['location'], 'SN-SUCCESS-001');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-SUCCESS-001'],
            ])
            ->assertOk();

        // Finalize
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SERIAL-OK-001',
            'payment' => [
                'payment_method_id' => $methods->cash->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED');

        $saleId = $response->json('sale_id');
        $dispatchId = $response->json('dispatch_ids')[0];

        // Assert serial status is SOLD
        $this->assertDatabaseHas('product_serial_numbers', [
            'id' => $sn->id,
            'status' => 'SOLD',
            'dispatch_detail_id' => DB::table('dispatch_details')->where('dispatch_id', $dispatchId)->value('id'),
        ]);

        // Assert SaleDetail has serial_number_ids
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'serial_number_ids' => json_encode([(int) $sn->id]),
        ]);

        // Assert DispatchDetail has serial_numbers
        $this->assertDatabaseHas('dispatch_details', [
            'dispatch_id' => $dispatchId,
            'product_id' => $product->id,
            'serial_numbers' => json_encode(['SN-SUCCESS-001']),
        ]);

        // Assert history record
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn->id,
            'event_type' => 'SOLD',
            'reference_type' => 'Modules\Sale\Entities\DispatchDetail',
        ]);

        // Assert SalesOrderSerialTracking
        $this->assertDatabaseHas('sales_order_serial_tracking', [
            'sale_id' => $saleId,
            'product_serial_number_id' => $sn->id,
        ]);
        
        // Assert stock decrement
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'location_id' => $context['location']->id,
            'quantity' => 19,
        ]);
    }

    public function test_serial_search_endpoint_returns_available_serials(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL SEARCH');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'SEARCH-PROD', 100000, true);
        
        $this->createSerialNumber($product, $context['location'], 'SN-AVAIL-1');
        $this->createSerialNumber($product, $context['location'], 'SN-AVAIL-2');
        
        // Sold serial should not appear
        $soldSn = $this->createSerialNumber($product, $context['location'], 'SN-SOLD');
        $soldSn->update(['status' => 'SOLD']);

        // Serial at different location should not appear
        $otherLoc = Location::create(['name' => 'OTHER', 'setting_id' => $context['setting']->id]);
        $this->createSerialNumber($product, $otherLoc, 'SN-OTHER-LOC');

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.serials.search', [
                'product_id' => $product->id,
                'q' => 'SN-AVAIL'
            ]));

        $response->assertOk()
            ->assertJsonCount(2, 'serials')
            ->assertJsonPath('serials.0.serial_number', 'SN-AVAIL-1')
            ->assertJsonPath('serials.1.serial_number', 'SN-AVAIL-2');
    }

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
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

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.ser.' . $suffix . '@example.com',
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

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS SERIAL LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SER-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Serial Terminal ' . $index,
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

    private function createSerialNumber(Product $product, Location $location, string $serialNumber): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'tax_id' => null,
            'status' => 'ACTIVE',
        ]);
    }

    private function seedPaymentMethods(Setting $setting): object
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
                'is_available_in_pos' => true,
                'requires_reference' => !$isCash,
            ]);
        }

        return (object) $methods;
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    private function cartSnapshot(User $cashier, Setting $setting): array
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}
