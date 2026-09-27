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
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\PosCheckoutGroupCustomerResolverService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
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
            'pos.checkout.payment',
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

        $saleIds = array_values(array_map(
            static fn (array $group): int => (int) ($group['sale_id'] ?? 0),
            $splitGroups
        ));
        /** @var \Illuminate\Support\Collection<int, Sale> $sales */
        $sales = Sale::query()->whereIn('id', $saleIds)->get()->keyBy('id');

        $prefixBySetting = [
            (int) $context['setting']->id => $context['setting']->document_prefix . '-' . $context['setting']->sale_prefix_document . '-',
            (int) $context['source_setting']->id => $context['source_setting']->document_prefix . '-' . $context['source_setting']->sale_prefix_document . '-',
        ];

        $checkoutId = (int) ($payload['pos_checkout_id'] ?? 0);
        $seenSourceSettings = [];
        foreach ($splitGroups as $group) {
            $sale = $sales->get((int) ($group['sale_id'] ?? 0));
            $this->assertNotNull($sale, 'Expected sale record to exist for split group.');

            $sourceSettingId = (int) ($group['source_setting_id'] ?? 0);
            $seenSourceSettings[] = $sourceSettingId;

            $this->assertSame($sourceSettingId, (int) $sale->setting_id);
            $this->assertStringStartsWith(
                (string) ($prefixBySetting[$sourceSettingId] ?? ''),
                (string) $sale->reference
            );

            $this->assertDatabaseHas('transactions', [
                'product_id' => $context['product']->id,
                'setting_id' => $sourceSettingId,
                'location_id' => (int) ($group['source_location_id'] ?? 0),
                'reason' => 'POS CHECKOUT #' . $checkoutId,
                'type' => 'DISPATCH',
            ]);
        }

        sort($seenSourceSettings);
        $this->assertSame(
            [(int) $context['setting']->id, (int) $context['source_setting']->id],
            array_values(array_unique($seenSourceSettings))
        );

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

    public function test_finalize_succeeds_with_selected_global_customer_when_source_walk_in_is_not_configured(): void
    {
        $context = $this->createSplitCheckoutContext(false);

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-GLOBAL-CUSTOMER-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonCount(2, 'split_groups');

        $payload = $response->json();
        $saleIds = array_values(array_map(
            static fn (array $group): int => (int) ($group['sale_id'] ?? 0),
            $payload['split_groups'] ?? []
        ));
        /** @var \Illuminate\Support\Collection<int, Sale> $sales */
        $sales = Sale::query()->whereIn('id', $saleIds)->get()->keyBy('id');

        foreach ($payload['split_groups'] as $group) {
            $sourceSettingId = (int) ($group['source_setting_id'] ?? 0);
            $sale = $sales->get((int) ($group['sale_id'] ?? 0));
            $this->assertNotNull($sale, 'Expected sale record to exist for split group.');
            $this->assertSame($sourceSettingId, (int) $sale->setting_id);
            $this->assertSame((int) $context['customer']->id, (int) $sale->customer_id);
        }
    }

    public function test_group_customer_resolver_fails_with_actionable_details_when_selected_and_source_walk_in_customers_are_unresolved(): void
    {
        $terminalSetting = $this->createSetting('POS SPLIT RESOLVE TERMINAL', 'TNC', 'JL');
        $sourceSetting = $this->createSetting('POS SPLIT RESOLVE SOURCE', 'TOP', 'JL');
        $sourceSetting->update([
            'pos_walk_in_customer_id' => 999999,
        ]);

        /** @var PosCheckoutGroupCustomerResolverService $resolver */
        $resolver = app(PosCheckoutGroupCustomerResolverService::class);

        try {
            $resolver->resolve(
                (int) $terminalSetting->id,
                (int) $sourceSetting->id,
                888888
            );
            $this->fail('Expected split group resolver to throw CUSTOMER_UNRESOLVED.');
        } catch (PosCheckoutValidationException $exception) {
            $this->assertSame('CUSTOMER_UNRESOLVED', $exception->errorCode());

            $details = $exception->details();
            $this->assertSame('SOURCE_CUSTOMER_UNRESOLVED', $details['reason_code'] ?? null);
            $this->assertSame((int) $sourceSetting->id, (int) ($details['source_setting_id'] ?? 0));
            $this->assertSame((int) $terminalSetting->id, (int) ($details['terminal_setting_id'] ?? 0));
            $this->assertSame(888888, (int) ($details['selected_customer_id'] ?? 0));
            $this->assertSame(999999, (int) ($details['source_walk_in_customer_id'] ?? 0));
        }
    }

    public function test_finalize_pkp_owner_consuming_quantity_tax_persists_taxable_sale(): void
    {
        $context = $this->createSplitCheckoutContext();
        // Default context settings are already is_pkp = true and stock is seeded entirely
        // into quantity_tax at both locations, so this is the valid PKP/tax-bucket pairing.

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-PKP-TAX-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201)->assertJsonCount(1, 'split_groups');

        $payload = $response->json();
        $sale = Sale::query()->with('saleDetails')->findOrFail((int) $payload['sale_id']);
        $saleDetail = $sale->saleDetails->sole();

        $this->assertSame((int) $context['tax']->id, (int) $saleDetail->tax_id);
        $this->assertGreaterThan(0, (float) $sale->tax_amount);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $context['product']->id,
            'setting_id' => $context['setting']->id,
            'location_id' => $context['terminal_location']->id,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'type' => 'DISPATCH',
        ]);
    }

    public function test_finalize_non_pkp_owner_consuming_quantity_non_tax_persists_non_taxable_sale(): void
    {
        $context = $this->createSplitCheckoutContext();
        $context['setting']->update(['is_pkp' => false]);
        $context['source_setting']->update(['is_pkp' => false]);

        ProductPrice::query()
            ->where('product_id', $context['product']->id)
            ->update(['sale_tax_id' => null]);

        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->where('location_id', $context['terminal_location']->id)
            ->update([
                'quantity' => 2,
                'quantity_tax' => 0,
                'quantity_non_tax' => 2,
                'tax_id' => null,
            ]);

        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->where('location_id', $context['source_location']->id)
            ->update([
                'quantity' => 0,
                'quantity_tax' => 0,
                'quantity_non_tax' => 0,
                'tax_id' => null,
            ]);

        $context['product']->update(['product_quantity' => 2]);

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-NONPKP-NONTAX-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201)->assertJsonCount(1, 'split_groups');

        $payload = $response->json();
        $sale = Sale::query()->with('saleDetails')->findOrFail((int) $payload['sale_id']);
        $saleDetail = $sale->saleDetails->sole();

        $this->assertNull($saleDetail->tax_id);
        $this->assertEquals(0, (float) $saleDetail->product_tax_amount);
        $this->assertEquals(0, (float) $sale->tax_amount);

        $this->assertDatabaseHas('dispatch_details', [
            'sale_id' => $sale->id,
            'product_id' => $context['product']->id,
            'tax_id' => null,
            'location_id' => $context['terminal_location']->id,
        ]);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $context['product']->id,
            'setting_id' => $context['setting']->id,
            'location_id' => $context['terminal_location']->id,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'type' => 'DISPATCH',
        ]);
    }

    public function test_finalize_rejects_non_pkp_owner_whose_only_available_stock_is_quantity_tax(): void
    {
        $context = $this->createSplitCheckoutContext();
        $context['setting']->update(['is_pkp' => false]);
        $context['source_setting']->update(['is_pkp' => false]);

        // Stock exists only in the tax bucket, which a non-PKP owner is never allowed to consume.
        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->where('location_id', $context['terminal_location']->id)
            ->update(['quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0]);

        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->where('location_id', $context['source_location']->id)
            ->update(['quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0]);

        $saleCountBefore = Sale::query()->count();
        $dispatchDetailCountBefore = DB::table('dispatch_details')->count();
        $salePaymentCountBefore = DB::table('sale_payments')->count();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-NONPKP-REJECT-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'STOCK_UNAVAILABLE');

        $this->assertSame($saleCountBefore, Sale::query()->count());
        $this->assertSame($dispatchDetailCountBefore, DB::table('dispatch_details')->count());
        $this->assertSame($salePaymentCountBefore, DB::table('sale_payments')->count());
    }

    public function test_finalize_rejects_pkp_owner_whose_only_available_stock_is_quantity_non_tax(): void
    {
        $context = $this->createSplitCheckoutContext();
        // Default context settings are is_pkp = true; force stock into the incompatible
        // non-tax bucket only, which a PKP owner is never allowed to consume.
        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->where('location_id', $context['terminal_location']->id)
            ->update(['quantity' => 1, 'quantity_tax' => 0, 'quantity_non_tax' => 1]);

        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->where('location_id', $context['source_location']->id)
            ->update(['quantity' => 10, 'quantity_tax' => 0, 'quantity_non_tax' => 10]);

        $saleCountBefore = Sale::query()->count();
        $dispatchDetailCountBefore = DB::table('dispatch_details')->count();
        $salePaymentCountBefore = DB::table('sale_payments')->count();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-PKP-REJECT-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'STOCK_UNAVAILABLE');

        $this->assertSame($saleCountBefore, Sale::query()->count());
        $this->assertSame($dispatchDetailCountBefore, DB::table('dispatch_details')->count());
        $this->assertSame($salePaymentCountBefore, DB::table('sale_payments')->count());
    }

    public function test_finalize_missing_tax_id_for_pkp_tax_stock_uses_configured_fallback(): void
    {
        $context = $this->createSplitCheckoutContext();
        // Default context settings are is_pkp = true and stock lives entirely in quantity_tax.
        // Clear the explicit sale_tax_id and the stock's own tax_id so resolution must fall
        // back to the configured default Tax record.
        ProductPrice::query()
            ->where('product_id', $context['product']->id)
            ->update(['sale_tax_id' => null]);

        ProductStock::query()
            ->where('product_id', $context['product']->id)
            ->whereIn('location_id', [$context['terminal_location']->id, $context['source_location']->id])
            ->update(['tax_id' => null]);

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SPLIT-PKP-FALLBACK-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201)->assertJsonCount(1, 'split_groups');

        $payload = $response->json();
        $sale = Sale::query()->with('saleDetails')->findOrFail((int) $payload['sale_id']);
        $saleDetail = $sale->saleDetails->sole();

        // The default Tax record (created with is_default = true in createSplitCheckoutContext)
        // must be used as the fallback since the owner is established as PKP.
        $this->assertSame((int) $context['tax']->id, (int) $saleDetail->tax_id);
        $this->assertGreaterThan(0, (float) $sale->tax_amount);
    }

    public function test_split_posting_copies_note_to_every_sale(): void
    {
        $context = $this->createSplitCheckoutContext();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.note.update'), ['note' => 'Split posting test note'])
            ->assertOk();

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-split-note-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201);
        
        $splitGroups = $response->json('split_groups');
        $this->assertCount(2, $splitGroups);

        $checkoutId = $response->json('pos_checkout_id');
        $checkout = \Modules\Pos\Entities\PosCheckout::find($checkoutId);
        $transactionCode = \Modules\Pos\Entities\PosTransaction::find($checkout->pos_transaction_id)->code;
        $expectedNote = mb_strtoupper('POS ' . $transactionCode . "\nSplit posting test note", 'UTF-8');

        foreach ($splitGroups as $group) {
            $sale = Sale::findOrFail($group['sale_id']);
            $this->assertEquals($expectedNote, $sale->note);
        }
    }

    public function test_split_posting_without_note_keeps_provenance_without_blank_suffix(): void
    {
        $context = $this->createSplitCheckoutContext();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-split-no-note-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 200000,
            ],
        ])->assertCreated();

        $splitGroups = $response->json('split_groups');
        $this->assertCount(2, $splitGroups);
        $checkoutId = $response->json('pos_checkout_id');
        $checkout = \Modules\Pos\Entities\PosCheckout::find($checkoutId);
        $transactionCode = \Modules\Pos\Entities\PosTransaction::find($checkout->pos_transaction_id)->code;
        $expectedNote = 'POS ' . $transactionCode;

        foreach ($splitGroups as $group) {
            $sale = Sale::findOrFail($group['sale_id']);
            $this->assertSame($expectedNote, $sale->note);
            $this->assertFalse(str_ends_with($sale->note, "\n"));
        }
    }

    public function test_one_staged_image_attached_to_every_sale_payment_in_split_posting(): void
    {
        $context = $this->createSplitCheckoutContext();

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        // Upload image
        \Illuminate\Support\Facades\Storage::fake();
        $image = \Illuminate\Http\UploadedFile::fake()->image('split.jpg');
        $cartToken = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot.staged_payment_token');
        $uploadResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.payment-image.upload'), [
                'image' => $image,
                'cart_token' => $cartToken,
            ])->assertOk();
            
        $imageToken = $uploadResponse->json('token');

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'test-split-image-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']['transfer']->id,
                'amount_paid' => 200000,
                'reference' => 'SPLIT123',
                'payment_image_token' => $imageToken,
            ],
        ]);

        $response->assertStatus(201);
        
        $splitGroups = $response->json('split_groups');
        $this->assertCount(2, $splitGroups);

        foreach ($splitGroups as $group) {
            $sale = Sale::findOrFail($group['sale_id']);
            $payment = $sale->salePayments()->first();
            $this->assertNotNull($payment, 'Sale payment missing');
            
            $media = $payment->getMedia('attachments');
            $this->assertCount(1, $media);
            $this->assertSame('split.jpg', $media->first()->file_name);
        }
    }

    public function test_component_captured_stockless_currently_stock_managed_allocates_and_posts_as_stock_managed(): void
    {
        $context = $this->createSplitCheckoutContext();
        $setting = $context['setting'];
        $cashier = $context['cashier'];
        $customer = $context['customer'];
        $parent = $context['product'];

        $child = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $parent->category_id,
            'unit_id' => $parent->unit_id,
            'product_name' => 'Child Component',
            'product_code' => 'CHILD-001',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 20000,
            'sale_price' => 20000,
            'product_unit' => 'PCS',
            'stock_managed' => false,
            'is_sold' => true,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $setting->id,
            'name' => 'Bundle Stockless to Stock',
            'bundle_sale_price' => 100000,
        ]);
        \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        // Add line when child is stockless
        $this->addCartLine($cashier, $setting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        // Child becomes stock-managed and stock is added to location 1
        $child->update(['stock_managed' => true]);
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $child->id,
            'location_id' => $context['terminal_location']->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'test-stockless-to-stock-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
            'acknowledge_lifecycle_warning' => true,
        ]);

        $response->assertStatus(201);
        $checkout = \Modules\Pos\Entities\PosCheckout::findOrFail($response->json('pos_checkout_id'));
        $this->assertNotNull($checkout->original_cart_snapshot);
    }

    public function test_component_captured_stock_managed_currently_stockless_resolves_and_posts_as_stockless(): void
    {
        $context = $this->createSplitCheckoutContext();
        $setting = $context['setting'];
        $cashier = $context['cashier'];
        $customer = $context['customer'];
        $parent = $context['product'];

        $child = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $parent->category_id,
            'unit_id' => $parent->unit_id,
            'product_name' => 'Child Component 2',
            'product_code' => 'CHILD-002',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 20000,
            'sale_price' => 20000,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'is_sold' => true,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $setting->id,
            'name' => 'Bundle Stock to Stockless',
            'bundle_sale_price' => 100000,
        ]);
        \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        // Add line when child is stock-managed
        $this->addCartLine($cashier, $setting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        // Child becomes stockless
        $child->update(['stock_managed' => false]);

        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'test-stock-to-stockless-' . uniqid(),
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
            'acknowledge_lifecycle_warning' => true,
        ]);

        $response->assertStatus(201);
    }

    private function createSplitCheckoutContext(bool $configureSourceWalkIn = true): array
    {
        $setting = $this->createSetting('POS SPLIT TERMINAL BIZ', 'TNC', 'JL');
        $sourceSetting = $this->createSetting('POS SPLIT SOURCE BIZ', 'TOP', 'JL');
        $cashier = $this->createUserForSetting($setting, 'pos split cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);
        $sourceLocation = Location::create([
            'name' => 'SPLIT SOURCE LOC ' . $this->sequence++,
            'setting_id' => $sourceSetting->id,
        ]);
        [$terminal, $locations] = $this->createTerminalAndSaleLocations($setting, $sourceLocation);
        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        if ($configureSourceWalkIn) {
            $this->assignDefaultWalkInCustomer($sourceSetting);
        }
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
            'source_setting' => $sourceSetting,
            'terminal_location' => $locations[0],
            'source_location' => $locations[1],
            'product' => $product,
            'tax' => $tax,
        ];
    }

    private function createSetting(
        string $name,
        string $documentPrefix = 'DOC',
        string $salePrefix = 'SO'
    ): Setting
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
            'document_prefix' => $documentPrefix,
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => $salePrefix,
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
    private function createTerminalAndSaleLocations(Setting $setting, Location $sourceLocation): array
    {
        $index = $this->sequence++;

        $locationA = Location::create([
            'name' => 'SPLIT LOC A ' . $index,
            'setting_id' => $setting->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $sourceLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

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

        return [$terminal, [$locationA, $sourceLocation]];
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

        $transferCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS SPLIT COA TRANSFER ' . $index,
            'account_number' => 'POS-SPLIT-TRANSFER-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods['transfer'] = PaymentMethod::query()->create([
            'name' => 'TRANSFER SPLIT ' . $index,
            'coa_id' => $transferCoaId,
            'is_cash' => false,
            'requires_reference' => true,
        ]);

        if ($enableForSetting) {
            foreach ($methods as $method) {
                DB::table('setting_pos_payment_methods')->updateOrInsert(
                    [
                        'setting_id' => $setting->id,
                        'payment_method_id' => $method->id,
                    ],
                    [
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
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
