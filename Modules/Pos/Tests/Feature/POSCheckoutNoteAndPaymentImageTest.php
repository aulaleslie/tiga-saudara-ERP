<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTemporaryPaymentImage;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-notes-and-images
 */
class POSCheckoutNoteAndPaymentImageTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

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
            'pos.checkout.payment',
            'pos.sessions.require-terminal',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_checkout_note_accepts_and_returns_value(): void
    {
        $context = $this->createCheckoutContext('NOTE-ACCEPT-TEST');
        $note = 'Test checkout note';

        $this->addCartLine($context['cashier'], $context['setting'], $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NOTE-1', 50000, false)->id, 1);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), [
                'note' => $note,
            ])
            ->assertOk()
            ->json();

        $this->assertEquals($note, $response['cart_snapshot']['note'] ?? null);
    }

    public function test_checkout_note_respects_1000_character_limit(): void
    {
        $context = $this->createCheckoutContext('NOTE-LIMIT-TEST');

        $validNote = str_repeat('a', 1000);
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), [
                'note' => $validNote,
            ])
            ->assertOk();

        $oversizedNote = str_repeat('a', 1001);
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), [
                'note' => $oversizedNote,
            ])
            ->assertStatus(422);
    }

    public function test_checkout_note_whitespace_only_converts_to_null(): void
    {
        $context = $this->createCheckoutContext('NOTE-WHITESPACE-TEST');

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), [
                'note' => '   ' . PHP_EOL . "\t",
            ])
            ->assertOk()
            ->json();

        $this->assertNull($response['cart_snapshot']['note'] ?? 'not_null');
    }

    public function test_checkout_note_appears_on_generated_sale(): void
    {
        $context = $this->createCheckoutContext('SALE-NOTE-TEST');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NOTE-2', 50000, false);
        $customer = Customer::factory()->create(['setting_id' => $context['setting']->id]);
        $context['setting']->update(['pos_walk_in_customer_id' => $customer->id]);
        $note = 'Invoice note content';

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), [
                'note' => $note,
            ])
            ->assertOk();

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-note-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 50000,
            ],
        ]);

        $response->assertStatus(201);
        $sale = Sale::findOrFail($response['json']['sale_id']);
        $this->assertEquals($note, $sale->note);
    }

    public function test_payment_image_upload_with_valid_jpeg(): void
    {
        $context = $this->createCheckoutContext('IMAGE-UPLOAD-JPEG-TEST');
        Storage::fake('pos_temporary_images');

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = 'test-cart-token-' . uniqid();

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk();

        $this->assertArrayHasKey('token', $response['data']);
        $this->assertTrue(strlen($response['data']['token']) === 64);
    }

    public function test_payment_image_upload_validates_mime_type(): void
    {
        $context = $this->createCheckoutContext('IMAGE-MIME-TEST');
        Storage::fake('pos_temporary_images');

        $invalidFile = \Illuminate\Http\UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $invalidFile,
                'cart_token' => 'test-token',
            ])
            ->assertStatus(422);
    }

    public function test_payment_image_upload_enforces_5mb_limit(): void
    {
        $context = $this->createCheckoutContext('IMAGE-SIZE-TEST');
        Storage::fake('pos_temporary_images');

        $oversizedImage = \Illuminate\Http\UploadedFile::fake()->image('oversized.jpg')->size(5001);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $oversizedImage,
                'cart_token' => 'test-token',
            ])
            ->assertStatus(422);
    }

    public function test_payment_image_token_expires_after_24_hours(): void
    {
        $context = $this->createCheckoutContext('IMAGE-EXPIRY-TEST');
        Storage::fake('pos_temporary_images');

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = 'test-cart-token-' . uniqid();

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk();

        $token = $uploadResponse['data']['token'];
        $image = PosTemporaryPaymentImage::where('token', $token)->first();
        $image->update(['expires_at' => now()->subHour()]);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.payment-image.delete'), [
                'token' => $token,
            ])
            ->assertNotFound();
    }

    public function test_payment_image_required_for_non_cash_staged_payment(): void
    {
        $context = $this->createCheckoutContext('IMAGE-REQUIRED-TEST');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-IMAGE-1', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-image-required-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'test-ref',
                'payment_image_token' => 'invalid-or-expired-token',
            ],
        ]);

        $response->assertStatus(422);
    }

    protected function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
            'pos.checkout.payment',
        ]);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);
        $methods = $this->seedPaymentMethods($setting, true);

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
            'methods' => $methods,
        ];
    }

    protected function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.note-image.' . $suffix . '@example.com',
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

    protected function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS NOTE-IMAGE LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-NOTE-IMG-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Note/Image Terminal ' . $index,
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

    protected function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $setting->update([
            'pos_walk_in_customer_id' => $customer->id,
        ]);

        return $customer;
    }

    protected function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): object
    {
        $index = $this->sequence++;
        $methods = new \stdClass();

        $cashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA CASH ' . $index,
            'account_number' => 'POS-CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transferCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA TRANSFER ' . $index,
            'account_number' => 'POS-TRF-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods->cash = PaymentMethod::query()->create([
            'name' => 'CASH POS ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $methods->transfer = PaymentMethod::query()->create([
            'name' => 'TRANSFER POS ' . $index,
            'coa_id' => $transferCoaId,
            'is_cash' => false,
            'requires_reference' => true,
        ]);

        if ($enableForSetting) {
            $timestamp = now();
            foreach (['cash' => $methods->cash, 'transfer' => $methods->transfer] as $method) {
                DB::table('setting_pos_payment_methods')->updateOrInsert(
                    [
                        'setting_id' => $setting->id,
                        'payment_method_id' => $method->id,
                    ],
                    [
                        'is_enabled' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            }
        }

        return $methods;
    }

    protected function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice,
        bool $serialRequired
    ): Product {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;

        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => $createdBy,
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

    protected function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    protected function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    protected function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}
