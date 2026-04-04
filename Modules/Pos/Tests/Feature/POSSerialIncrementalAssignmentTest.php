<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
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
class POSSerialIncrementalAssignmentTest extends TestCase
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

    public function test_can_append_serials_incremental_assignment(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL APPEND');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-APPEND', 100000, true);
        
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-APPEND-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-APPEND-2');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign first serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-APPEND-1'],
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-APPEND-1']);

        // Append second serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-APPEND-2',
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-APPEND-1', 'SN-APPEND-2']);
    }

    public function test_can_remove_assigned_serials(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL REMOVE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-REMOVE', 100000, true);
        
        $this->createSerialNumber($product, $context['location'], 'SN-REMOVE-1');
        $this->createSerialNumber($product, $context['location'], 'SN-REMOVE-2');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign both serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-REMOVE-1', 'SN-REMOVE-2'],
            ])
            ->assertOk();

        // Remove first serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.lines.serials.remove', ['lineId' => $lineId, 'serial' => 'SN-REMOVE-1']))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-REMOVE-2']);

        // Remove second serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.lines.serials.remove', ['lineId' => $lineId, 'serial' => 'SN-REMOVE-2']))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', []);
    }

    public function test_cannot_append_duplicate_serial(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL DUP APPEND');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-DUPAPP', 100000, true);
        
        $this->createSerialNumber($product, $context['location'], 'SN-DUPAPP');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign first time
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-DUPAPP'],
            ])
            ->assertOk();

        // Try to append same serial - should fail
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-DUPAPP',
            ])
            ->assertStatus(422);
    }

    public function test_append_serial_in_pending_dispatch_is_rejected_with_expected_message(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL PENDING APPEND');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-PENDAPP', 100000, true);

        $this->createSerialNumber($product, $context['location'], 'SN-PENDAPP');
        $this->createDispatchForSerial($context['setting'], $product, $context['location'], 'SN-PENDAPP');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-PENDAPP',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Serial number SN-PENDAPP sedang dalam proses pengiriman.');
    }

    public function test_append_serial_not_in_pending_dispatch_succeeds(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL APPEND AVAILABLE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-APP-OK', 100000, true);

        $this->createSerialNumber($product, $context['location'], 'SN-APP-OK');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-APP-OK',
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-APP-OK']);
    }

    public function test_replace_serials_overwrites_assignment(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL REPLACE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-REPLACE', 100000, true);
        
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-OLD-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-OLD-2');
        $sn3 = $this->createSerialNumber($product, $context['location'], 'SN-NEW-1');
        $sn4 = $this->createSerialNumber($product, $context['location'], 'SN-NEW-2');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign old serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-OLD-1', 'SN-OLD-2'],
            ])
            ->assertOk();

        // Replace with new serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->putJson(route('pos.sell.cart.lines.serials.update', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-NEW-1', 'SN-NEW-2'],
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-NEW-1', 'SN-NEW-2']);
    }

    public function test_appending_exceeding_qty_is_rejected(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL EXCEED');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-EXCEED', 100000, true);
        
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-EXCEED-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-EXCEED-2');
        $sn3 = $this->createSerialNumber($product, $context['location'], 'SN-EXCEED-3');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2); // Qty = 2
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 2 serials initially
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-EXCEED-1', 'SN-EXCEED-2'],
            ])
            ->assertOk();

        // Try to append 3rd serial - should fail (exceeds qty of 2)
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-EXCEED-3',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_EXCEEDS_QTY');
    }

    public function test_removing_nonexistent_serial_is_safe(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL SAFE REMOVE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SAFE', 100000, true);
        
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-SAFE-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-SAFE-2');

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 1 serial
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-SAFE-1'],
            ])
            ->assertOk();

        // Try to remove serial that wasn't assigned - should be idempotent
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.lines.serials.remove', ['lineId' => $lineId, 'serial' => 'SN-SAFE-2']))
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-SAFE-1']);
    }

    public function test_qty_increase_preserves_assigned_serials(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL QTY PRESERVE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-PRESERVE', 100000, true);
        
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-PRESERVE-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-PRESERVE-2');
        $sn3 = $this->createSerialNumber($product, $context['location'], 'SN-PRESERVE-3');

        // Add line with qty=2
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 2 serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-PRESERVE-1', 'SN-PRESERVE-2'],
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-PRESERVE-1', 'SN-PRESERVE-2']);

        // Increase qty to 3 - serials should be preserved
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 3,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 3)
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-PRESERVE-1', 'SN-PRESERVE-2']);

        // Verify we can append a third serial now (qty=3, 2 assigned, so 1 slot available)
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => 'SN-PRESERVE-3',
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-PRESERVE-1', 'SN-PRESERVE-2', 'SN-PRESERVE-3']);
    }

    public function test_qty_decrease_rejected_for_serial_line(): void
    {
        $context = $this->createCheckoutContext('POS SERIAL QTY DECREASE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-DECREASE', 100000, true);
        
        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-DECREASE-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-DECREASE-2');

        // Add line with qty=3
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 3);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 2 serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-DECREASE-1', 'SN-DECREASE-2'],
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 3)
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-DECREASE-1', 'SN-DECREASE-2']);

        // Try to decrease qty to 2 - should be rejected by increase-only guard
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 2,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        // Verify cart line is unchanged (serials still assigned)
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(3, $snapshot['lines'][0]['qty']);
        $this->assertEquals(['SN-DECREASE-1', 'SN-DECREASE-2'], $snapshot['lines'][0]['assigned_serials']);
    }

    // ==== HELPER METHODS ====

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);
        $this->seedPaymentMethods($setting);

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
            'company_email' => 'pos.incr.' . $suffix . '@example.com',
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
            'name' => 'POS INCR LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-INCR-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Incr Terminal ' . $index,
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
            'product_quantity' => 100,
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
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
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

    private function createDispatchForSerial(
        Setting $setting,
        Product $product,
        Location $location,
        string $serialNumber,
        string $status = Dispatch::STATUS_PENDING
    ): DispatchDetail {
        $paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'POS INCR TERM'],
            ['longevity' => 0]
        );

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $sale = Sale::query()->create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'POS-INCR-DSP-' . $this->sequence++,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => $status,
        ]);

        return DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode([$serialNumber]),
        ]);
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];
        
        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $methodSuffix = $this->sequence++;
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $methodSuffix,
                'account_number' => "ACC-$name-" . $methodSuffix,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = PaymentMethod::create([
                'name' => "$name POS $methodSuffix",
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);

            DB::table('setting_pos_payment_methods')->updateOrInsert(
                [
                    'setting_id' => $setting->id,
                    'payment_method_id' => $methods[strtolower($name)]->id,
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
}
