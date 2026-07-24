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
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Pos\Services\PosTemporaryPaymentImageService;
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
    use \Illuminate\Foundation\Testing\LazilyRefreshDatabase;

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
            'pos.transactions.save',
            'pos.transactions.load',
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

        $this->assertNull($response['cart_snapshot']['note'] ?? null);
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
                'payment_method_id' => $context['methods']->cash->id,
                'amount_paid' => 50000,
            ],
        ]);

        $response->assertStatus(201);
        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertStringContainsStringIgnoringCase($note, $sale->note);
    }

    public function test_payment_image_upload_with_valid_jpeg(): void
    {
        $context = $this->createCheckoutContext('IMAGE-UPLOAD-JPEG-TEST');
        Storage::fake();

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = 'test-cart-token-' . uniqid();

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('token', $response);
        $this->assertTrue(strlen($response['token']) > 10);
    }

    public function test_payment_image_upload_validates_mime_type(): void
    {
        $context = $this->createCheckoutContext('IMAGE-MIME-TEST');
        Storage::fake();

        $invalidFile = \Illuminate\Http\UploadedFile::fake()->create('document.pdf', 100);

        $this->actingAs($context['cashier'])
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
        Storage::fake();

        $oversizedImage = \Illuminate\Http\UploadedFile::fake()->image('oversized.jpg')->size(6000);

        $this->actingAs($context['cashier'])
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
        Storage::fake();

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = 'test-cart-token-' . uniqid();

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $token = $uploadResponse['token'];
        $image = PosTemporaryPaymentImage::where('token', $token)->first();
        $image->update(['expires_at' => now()->subHour()]);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.payment-image.delete'), [
                'token' => $token,
                'cart_token' => $cartToken,
            ])
            ->assertNotFound();
    }

    public function test_non_cash_payment_optional_without_image(): void
    {
        $context = $this->createCheckoutContext('NONCASH-NO-IMAGE-TEST');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NONCASH-1', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-noncash-no-image-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'test-ref',
            ],
        ]);

        $response->assertStatus(201);
    }

    public function test_invalid_payment_image_token_blocks_checkout(): void
    {
        $context = $this->createCheckoutContext('INVALID-IMAGE-TOKEN-TEST');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-IMAGE-INVALID-1', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-invalid-token-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'test-ref',
                'payment_image_token' => 'invalid-or-expired-token',
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_note_persists_in_single_payment_finalization(): void
    {
        $context = $this->createCheckoutContext('NOTE-SINGLE-PAY-TEST');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NOTE-SINGLE-1', 25000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $note = 'Single payment with note';

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => $note])
            ->assertOk();

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-note-single-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->cash->id,
                'amount_paid' => 25000,
            ],
        ]);

        $response->assertStatus(201);
        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertStringContainsStringIgnoringCase($note, $sale->note);
    }

    public function test_payment_image_attached_to_sale_payment(): void
    {
        $context = $this->createCheckoutContext('IMAGE-ATTACH-TEST');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-IMAGE-ATTACH-1', 40000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $imageToken = $uploadResponse['token'];

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-image-attach-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 40000,
                'reference' => 'test-ref-attach',
                'payment_image_token' => $imageToken,
            ],
        ]);

        $response->assertStatus(201);
        $sale = Sale::findOrFail($response->json('sale_id'));
        $salePayment = $sale->salePayments()->first();
        $this->assertNotNull($salePayment);
        $this->assertGreaterThan(0, $salePayment->media()->count());
    }

    public function test_cash_payment_rejects_image_token(): void
    {
        $context = $this->createCheckoutContext('CASH-REJECT-IMAGE-TEST');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CASH-NO-IMG-1', 35000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $imageToken = $uploadResponse['token'];

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-cash-reject-image-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->cash->id,
                'amount_paid' => 35000,
                'payment_image_token' => $imageToken,
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_payment_image_idempotent_replay_succeeds_despite_consumed_token(): void
    {
        $context = $this->createCheckoutContext('IMAGE-IDEMPOTENT-TEST');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-IMAGE-IDEMPOTENT-1', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 100, 100);
        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $imageToken = $uploadResponse['token'];
        $idempotencyKey = 'test-idempotent-image-' . uniqid();

        // First request: should succeed with 201
        $response1 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'test-ref-idempotent',
                'payment_image_token' => $imageToken,
            ],
        ]);

        $response1->assertStatus(201);
        $checkoutId1 = $response1->json('pos_checkout_id');
        $saleId1 = $response1->json('sale_id');

        // Verify image was consumed and attached
        $sale1 = Sale::findOrFail($saleId1);
        $salePayment1 = $sale1->salePayments()->first();
        $this->assertNotNull($salePayment1);
        $this->assertGreaterThan(0, $salePayment1->media()->count());

        // Verify temporary image is now consumed
        $tempImage = PosTemporaryPaymentImage::where('token', $imageToken)->first();
        $this->assertNotNull($tempImage);
        $this->assertNotNull($tempImage->consumed_at);

        // Second request (identical): should return 200 replay without validating consumed image
        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'test-ref-idempotent',
                'payment_image_token' => $imageToken,
            ],
        ]);

        $response2->assertStatus(200);
        $this->assertEquals($checkoutId1, $response2->json('pos_checkout_id'));
        $this->assertEquals($saleId1, $response2->json('sale_id'));
    }

    public function test_idempotent_replay_rejects_payload_mismatch_on_image_token(): void
    {
        $context = $this->createCheckoutContext('IMAGE-MISMATCH-TEST');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-IMAGE-MISMATCH-1', 60000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $fakeImage1 = \Illuminate\Http\UploadedFile::fake()->image('receipt1.jpg', 100, 100);
        $fakeImage2 = \Illuminate\Http\UploadedFile::fake()->image('receipt2.jpg', 100, 100);
        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];

        // Upload two images
        $upload1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage1,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $upload2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $fakeImage2,
                'cart_token' => $cartToken,
            ])
            ->assertOk()
            ->json();

        $imageToken1 = $upload1['token'];
        $imageToken2 = $upload2['token'];
        $idempotencyKey = 'test-mismatch-' . uniqid();

        // First request: succeeds with image token 1
        $response1 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 60000,
                'reference' => 'test-ref-mismatch',
                'payment_image_token' => $imageToken1,
            ],
        ]);

        $response1->assertStatus(201);
        $saleId1 = $response1->json('sale_id');
        $sale1 = Sale::findOrFail($saleId1);
        $salePayment1 = $sale1->salePayments()->first();
        $attachmentCount1 = $salePayment1->media()->count();
        $this->assertGreaterThan(0, $attachmentCount1);

        // Second request (same key, different image token): should fail with 409 IDEMPOTENCY_PAYLOAD_MISMATCH
        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 60000,
                'reference' => 'test-ref-mismatch',
                'payment_image_token' => $imageToken2, // Different image token
            ],
        ]);

        $response2->assertStatus(409);
        $this->assertEquals('IDEMPOTENCY_PAYLOAD_MISMATCH', $response2->json('code'));

        // Verify the attachment count hasn't changed (original sale unchanged)
        $sale1Refreshed = Sale::findOrFail($saleId1);
        $salePayment1Refreshed = $sale1Refreshed->salePayments()->first();
        $this->assertEquals($attachmentCount1, $salePayment1Refreshed->media()->count());
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
            'pos.transactions.save',
            'pos.transactions.load',
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
            'pos_transactions_enabled' => true,
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

    protected function getCartSnapshot(User $cashier, Setting $setting): array
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');
    }

    public function test_note_participates_in_stored_checkout_payload_hash(): void
    {
        $context = $this->createCheckoutContext('NOTE-HASH-ID');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-HASH-A', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $noteA = 'Checkout note A';

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => $noteA])
            ->assertOk();

        $idempotencyKey = \Illuminate\Support\Str::uuid()->toString();

        $response1 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);
        $response1->assertCreated();
        $saleId1 = $response1->json('sale_id');

        // Verify the note was persisted
        $sale1 = Sale::findOrFail($saleId1);
        $this->assertStringContainsStringIgnoringCase($noteA, $sale1->note);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => 'Checkout note B'])
            ->assertOk();

        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);

        $response2->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        $this->assertSame($saleId1, (int) $response1->json('sale_id'));
    }

    public function test_note_survives_cart_reload(): void
    {
        $context = $this->createCheckoutContext('NOTE-RELOAD');
        $note = 'Cart reload test note';

        $this->addCartLine($context['cashier'], $context['setting'], $this->createStockedProduct($context['setting'], $context['location'], 'PROD-RELOAD-1', 50000, false)->id, 1);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => $note])
            ->assertOk();

        $snapshot1 = $this->getCartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals($note, $snapshot1['note'] ?? null);

        $snapshot2 = $this->getCartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals($note, $snapshot2['note'] ?? null);
    }

    public function test_note_survives_draft_save_and_load(): void
    {
        $context = $this->createCheckoutContext('NOTE-DRAFT');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-DRAFT-1', 50000, false);
        $note = 'Draft save and load note';

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => $note])
            ->assertOk();

        $saveResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertCreated();

        $transactionId = (int) $saveResponse->json('transaction.id');
        $this->assertSame($note, PosTransaction::query()->findOrFail($transactionId)->note);
        $this->assertNull(
            $this->getCartSnapshot($context['cashier'], $context['setting'])['note'] ?? null,
            'Save-and-new should clear the active cart note.'
        );

        $loadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $this->assertSame($note, $loadResponse->json('cart_snapshot.note'));
        $this->assertSame(
            $note,
            $this->getCartSnapshot($context['cashier'], $context['setting'])['note'] ?? null
        );
    }

    public function test_legacy_null_note_is_supported(): void
    {
        $context = $this->createCheckoutContext('NOTE-LEGACY-NULL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-LEGACY-1', 50000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $saveResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertCreated();

        $transactionId = (int) $saveResponse->json('transaction.id');
        PosTransaction::query()->whereKey($transactionId)->update(['note' => null]);

        $loadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $this->assertNull($loadResponse->json('cart_snapshot.note'));
    }

    public function test_inline_posting_places_note_on_generated_sale(): void
    {
        $context = $this->createCheckoutContext('INLINE-SALE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-INLINE', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $note = 'Inline sale note';

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => $note])
            ->assertOk();

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertStringContainsStringIgnoringCase($note, $sale->note);
    }

    public function test_split_posting_without_note_has_provenance_without_blank_suffix(): void
    {
        $context = $this->createCheckoutContext('SPLIT-NO-NOTE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SPLIT-1', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertStringContainsStringIgnoringCase('POS CHECKOUT', $sale->note);
        $this->assertStringNotContainsString('null', $sale->note);
    }

    public function test_when_note_is_absent_every_sale_retains_pos_provenance_without_blank_note_line(): void
    {
        $context = $this->createCheckoutContext('PROVENANCE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-PROV', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertStringContainsStringIgnoringCase('POS CHECKOUT', $sale->note);
        $this->assertStringNotContainsString('null', $sale->note);
    }
    
    public function test_valid_png_and_jpeg_upload_succeeds(): void
    {
        $context = $this->createCheckoutContext('IMAGE-PNG');
        Storage::fake();

        $cartToken = 'test-cart-token-' . uniqid();
        $validImage1 = \Illuminate\Http\UploadedFile::fake()->image('receipt.png');

        $response1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage1,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $this->assertArrayHasKey('token', $response1->json());

        $validImage2 = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $response2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage2,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $this->assertArrayHasKey('token', $response2->json());
    }

    public function test_payment_image_token_belonging_to_another_setting_is_rejected(): void
    {
        $context = $this->createCheckoutContext('IMAGE-SETTING-1');
        Storage::fake();

        $cartToken = 'test-cart-token-' . uniqid();
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $response->json('token');

        // Second setting
        $context2 = $this->createCheckoutContext('IMAGE-SETTING-2');
        $product = $this->createStockedProduct($context2['setting'], $context2['location'], 'PROD-SETTING', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context2['setting']);

        $this->addCartLine($context2['cashier'], $context2['setting'], $product->id, 1);
        $this->selectCustomerInCart($context2['cashier'], $context2['setting'], $customer);

        $response2 = $this->finalize($context2['cashier'], $context2['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => [
                'payment_method_id' => $context2['methods']->transfer->id,
                'amount_paid' => 50000,
                'payment_image_token' => $token
            ]
        ]);

        $response2->assertStatus(422);
    }

    public function test_payment_image_token_from_another_pos_session_is_rejected(): void
    {
        $context = $this->createCheckoutContext('IMAGE-SESS-1');
        Storage::fake();

        $cartToken = 'test-cart-token-' . uniqid();
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $response->json('token');

        // open a completely new session and context
        $context2 = $this->createCheckoutContext('IMAGE-SESS-2');
        $product = $this->createStockedProduct($context2['setting'], $context2['location'], 'PROD-SESS', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context2['setting']);

        $this->addCartLine($context2['cashier'], $context2['setting'], $product->id, 1);
        $this->selectCustomerInCart($context2['cashier'], $context2['setting'], $customer);

        $response2 = $this->finalize($context2['cashier'], $context2['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => [
                'payment_method_id' => $context2['methods']->transfer->id,
                'amount_paid' => 50000,
                'payment_image_token' => $token
            ]
        ]);

        $response2->assertStatus(422);
    }
    
    public function test_payment_image_token_from_another_cashier_is_rejected(): void
    {
        $context1 = $this->createCheckoutContext('IMAGE-CASHIER-1');
        Storage::fake();

        $cartToken = 'test-cart-token-' . uniqid();
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($context1['cashier'])
            ->withSession(['setting_id' => $context1['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $response->json('token');

        $upload = PosTemporaryPaymentImage::where('token', $token)->firstOrFail();
        $this->assertEquals($context1['cashier']->id, $upload->cashier_id);

        $cashier2 = $this->createUserForSetting(
            $context1['setting'],
            'IMAGE-CASHIER-2',
            ['pos.access', 'pos.sell', 'pos.checkout.payment']
        );
        $imageService = app(PosTemporaryPaymentImageService::class);

        $this->assertNotNull($imageService->resolveImage(
            $token,
            $context1['setting']->id,
            $context1['session']->id,
            $context1['cashier']->id,
            $cartToken
        ));
        $this->assertNull($imageService->resolveImage(
            $token,
            $context1['setting']->id,
            $context1['session']->id,
            $cashier2->id,
            $cartToken
        ));
    }
    
    public function test_unauthenticated_upload_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake();
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $this->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
            ])
            ->assertStatus(401);
    }
    
    public function test_unauthenticated_deletion_is_rejected(): void
    {
        $this->deleteJson(route('pos.sell.payment-image.delete'), [
                'token' => 'some-token',
            ])
            ->assertStatus(401);
    }
    
    public function test_reuploading_image_returns_new_token_and_invalidates_old(): void
    {
        $context = $this->createCheckoutContext('IMAGE-REUPLOAD');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-REUP', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];
        $image1 = \Illuminate\Http\UploadedFile::fake()->image('receipt1.jpg');

        $response1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $image1,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token1 = $response1->json('token');

        // Delete token 1
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.payment-image.delete'), [
                'token' => $token1,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        // Upload replacement image to get token 2
        $image2 = \Illuminate\Http\UploadedFile::fake()->image('receipt2.jpg');
        $response2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $image2,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token2 = $response2->json('token');

        // Token 2 should differ from token 1
        $this->assertNotEquals($token1, $token2, 'Reuploaded image should get a different token');

        // Token 1 should now be unusable
        $response3 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'ref-reup',
                'payment_image_token' => $token1
            ]
        ]);

        $response3->assertStatus(422, 'Deleted token 1 should no longer be valid');

        // Token 2 should remain usable (add new product for second checkout)
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response4 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 100000,
                'reference' => 'ref-reup-2',
                'payment_image_token' => $token2
            ]
        ]);

        $response4->assertStatus(201, 'New token 2 should remain usable');
    }

    public function test_reloaded_payment_chain_preserves_payment_image_token(): void
    {
        $context = $this->createCheckoutContext('IMAGE-RECOVERY');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-RECOVERY', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $uploadResponse->json('token');
        $this->assertNotEmpty($token, 'Upload should return token');

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount' => 50000,
                'grand_total' => 50000,
                'cart_token' => $cartToken,
                'edc_reference' => 'test-ref',
                'payment_image_token' => $token
            ])
            ->assertStatus(201);

        $recovered = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.checkout.payment-chain', ['cart_token' => $cartToken]))
            ->assertOk()
            ->assertJsonPath('has_chain', true);

        $this->assertSame(
            $token,
            $recovered->json('payment_chain.payments.0.payment_image.token')
        );
        $this->assertSame(
            'receipt.jpg',
            $recovered->json('payment_chain.payments.0.payment_image.original_name')
        );
    }

    public function test_image_tokens_do_not_alter_edc_reference_validation(): void
    {
        // Image tokens are independent from EDC/reference validation rules
        // This verifies that image upload/token validation does not interfere with payment method requirements
        $context = $this->createCheckoutContext('IMAGE-EDC');

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-EDC', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];
        $image = \Illuminate\Http\UploadedFile::fake()->image('receipt.png');
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $image,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $response->json('token');

        // Checkout with transfer method requires reference. Should still work with image token present.
        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'ref-edc-test',
                'payment_image_token' => $token
            ]
        ]);

        $response2->assertCreated();
    }
    
    public function test_resetting_payment_chain_removes_image_token(): void
    {
        $context = $this->createCheckoutContext('IMAGE-RESET');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-RESET', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $uploadResponse->json('token');

        // Verify upload exists before reset
        $upload = PosTemporaryPaymentImage::where('token', $token)->firstOrFail();
        $this->assertNotNull($upload, 'Upload should exist');
        $this->assertNull($upload->consumed_at);

        // Stage payment with image
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount' => 50000,
                'grand_total' => 50000,
                'cart_token' => $cartToken,
                'edc_reference' => 'test-ref-reset',
                'payment_image_token' => $token
            ])
            ->assertStatus(201);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.checkout.payment-chain.reset'), [
                'cart_token' => $cartToken,
            ])
            ->assertOk();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.checkout.payment-chain', ['cart_token' => $cartToken]))
            ->assertOk()
            ->assertJsonPath('has_chain', false)
            ->assertJsonPath('payment_chain', null);

        $this->assertDatabaseMissing('pos_temporary_payment_images', ['token' => $token]);
        Storage::assertMissing($upload->path);
    }
    
    public function test_failed_checkout_retains_image_for_retry(): void
    {
        $context = $this->createCheckoutContext('IMAGE-RETRY');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-RETRY', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');

        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken
            ])
            ->assertOk();

        $token = $uploadResponse->json('token');

        // Verify token is not consumed before use
        $upload = PosTemporaryPaymentImage::where('token', $token)->firstOrFail();
        $this->assertNull($upload->consumed_at, 'Token should not be consumed before checkout');

        $idempotencyKey = 'IDEM-' . uniqid();
        $failed = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 40000,
                'reference' => 'ref-retry',
                'payment_image_token' => $token,
            ]
        ]);
        $failed->assertStatus(422);
        $this->assertDatabaseCount('sales', 0);
        $this->assertNull(
            PosTemporaryPaymentImage::query()->where('token', $token)->firstOrFail()->consumed_at
        );

        $retry = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'ref-retry',
                'payment_image_token' => $token,
            ],
        ]);
        $retry->assertCreated();

        $uploadAfter = PosTemporaryPaymentImage::query()->where('token', $token)->firstOrFail();
        $this->assertNotNull($uploadAfter->consumed_at);
        $salePayment = Sale::findOrFail($retry->json('sale_id'))->salePayments()->firstOrFail();
        $this->assertCount(1, $salePayment->getMedia('attachments'));
    }

    public function test_payment_image_token_from_another_cart_is_rejected(): void
    {
        $context = $this->createCheckoutContext('IMAGE-CART-1');
        Storage::fake();

        $cartToken1 = 'test-cart-token-1-' . uniqid();
        $validImage = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg');
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $validImage,
                'cart_token' => $cartToken1
            ])
            ->assertOk();

        $token = $response->json('token');

        // Upload with different cart token - should reject old token
        $cartToken2 = 'test-cart-token-2-' . uniqid();
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CART2', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'edc_reference' => 'REF-CART',
                'payment_image_token' => $token
            ]
        ]);

        $response2->assertStatus(422);
    }

    public function test_cash_stage_never_receives_payment_image(): void
    {
        $context = $this->createCheckoutContext('CASH-NO-IMG-STAGE');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CASH-STAGE', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->cash->id,
                'amount_paid' => 50000
            ]
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertNotNull($sale->id);

        // Cash payments should never have media attachments
        $salePayments = $sale->salePayments()->get();
        $this->assertNotEmpty($salePayments);
        foreach ($salePayments as $payment) {
            $this->assertEquals(0, $payment->media()->count(), 'Cash payment should not have attachments');
        }
    }

    public function test_stage_without_image_remains_attachment_free(): void
    {
        $context = $this->createCheckoutContext('IMAGE-NONE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NO-IMG', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-'.uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'no-image-reference',
            ]
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertNotNull($sale->id);

        // Query sale payments and verify no attachments
        $salePayments = $sale->salePayments()->get();
        $this->assertNotEmpty($salePayments);
        foreach ($salePayments as $payment) {
            $this->assertEquals(0, $payment->media()->count(), 'Payment without image should not have attachments');
        }
    }

    public function test_identical_replay_returns_200(): void
    {
        $context = $this->createCheckoutContext('REPLAY-IDENTICAL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-REPLAY', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $idempotencyKey = \Illuminate\Support\Str::uuid()->toString();

        $response1 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);
        $response1->assertCreated();
        $checkoutId1 = $response1->json('pos_checkout_id');
        $saleId1 = $response1->json('sale_id');
        $mediaCount1 = Sale::findOrFail($saleId1)->salePayments()->first()?->media()->count() ?? 0;

        // Identical replay with same idempotency key and payload
        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => ['payment_method_id' => $context['methods']->cash->id, 'amount_paid' => 50000],
        ]);

        $response2->assertStatus(200);
        $this->assertEquals($checkoutId1, $response2->json('pos_checkout_id'), 'Replay should return same checkout');
        $this->assertEquals($saleId1, $response2->json('sale_id'), 'Replay should return same sale');

        // Verify attachments didn't increase
        $mediaCount2 = Sale::findOrFail($saleId1)->salePayments()->first()?->media()->count() ?? 0;
        $this->assertEquals($mediaCount1, $mediaCount2, 'Media count should not increase on replay');
    }

    public function test_changed_image_token_returns_409(): void
    {
        $context = $this->createCheckoutContext('REPLAY-IMAGE-CHANGE');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-IMGCH', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];

        // Upload two images
        $image1 = \Illuminate\Http\UploadedFile::fake()->image('receipt1.jpg');
        $uploadResponse1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $image1,
                'cart_token' => $cartToken
            ])
            ->assertOk();
        $token1 = $uploadResponse1->json('token');

        $image2 = \Illuminate\Http\UploadedFile::fake()->image('receipt2.jpg');
        $uploadResponse2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $image2,
                'cart_token' => $cartToken
            ])
            ->assertOk();
        $token2 = $uploadResponse2->json('token');

        $idempotencyKey = \Illuminate\Support\Str::uuid()->toString();

        // First request: uses token1
        $response1 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'ref-img-change',
                'payment_image_token' => $token1
            ]
        ]);
        $response1->assertStatus(201);
        $saleId1 = $response1->json('sale_id');
        $sale1 = Sale::findOrFail($saleId1);
        $attachmentCount1 = $sale1->salePayments()->first()?->media()->count() ?? 0;

        // Second request (same key, different token): should fail with 409
        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'ref-img-change',
                'payment_image_token' => $token2
            ]
        ]);

        $response2->assertStatus(409);
        $this->assertEquals('IDEMPOTENCY_PAYLOAD_MISMATCH', $response2->json('code'));

        // Original sale should be unchanged
        $sale1Refreshed = Sale::findOrFail($saleId1);
        $attachmentCount1Refreshed = $sale1Refreshed->salePayments()->first()?->media()->count() ?? 0;
        $this->assertEquals($attachmentCount1, $attachmentCount1Refreshed, 'Original sale attachments should not change');
    }

    public function test_missing_physical_image_rolls_back_checkout(): void
    {
        $context = $this->createCheckoutContext('IMAGE-MISSING-FILE');
        Storage::fake();

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-MISSING-IMG', 50000, false);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $cartToken = $this->getCartSnapshot($context['cashier'], $context['setting'])['staged_payment_token'];
        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => \Illuminate\Http\UploadedFile::fake()->image('missing.jpg'),
                'cart_token' => $cartToken,
            ])
            ->assertOk();

        $token = (string) $uploadResponse->json('token');
        $temporaryImage = PosTemporaryPaymentImage::query()->where('token', $token)->firstOrFail();
        Storage::delete($temporaryImage->path);
        Storage::assertMissing($temporaryImage->path);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']->transfer->id,
                'amount_paid' => 50000,
                'reference' => 'ref-missing-img',
                'payment_image_token' => $token,
            ]
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('code', 'MISSING_PAYMENT_IMAGE_FILE');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertNull($temporaryImage->fresh()->consumed_at);
    }
}
