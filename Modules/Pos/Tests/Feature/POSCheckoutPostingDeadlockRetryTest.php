<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\PosSessionLifecycleService;
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
 * At the TRUE outermost transaction boundary (the normal production shape — no caller-owned
 * transaction wrapping finalize(), unlike RefreshDatabase-based test classes), a deadlock
 * raised mid-posting must retry the complete posting operation from scratch via
 * DocumentSequenceAllocator::executeWholeOperationWithConflictRetry(). This fix
 * (FinalizePosCheckoutService::postCheckout()) leaves that outermost retry path untouched —
 * the new transaction wrapping only activates when DB::transactionLevel() > 0 on entry, so
 * this file uses DatabaseTruncation (not RefreshDatabase) specifically so finalize() runs
 * genuinely unwrapped, exercising the real outermost code path rather than the nested one.
 */
class POSCheckoutPostingDeadlockRetryTest extends TestCase
{
    use DatabaseTruncation;

    private int $sequence = 1;

    protected function tearDown(): void
    {
        try {
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }

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

    public function test_posting_deadlock_retries_from_outermost_boundary_and_commits_exactly_once(): void
    {
        $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel(), 'This test must run at the true outermost boundary (no wrapping transaction) to exercise the production deadlock-retry path.');

        $setting = $this->createSetting('POS DEADLOCK RETRY');
        $cashier = $this->createUserForSetting($setting);
        $location = Location::create(['name' => 'DEADLOCK LOC', 'setting_id' => $setting->id]);
        SalesLocationResolver::forget($setting->id);
        $terminal = $this->createTerminalForSetting($setting);
        $methods = $this->seedPaymentMethods($setting);

        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);

        app(PosSessionLifecycleService::class)->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        $product = $this->createStockedProduct($setting, $location, 'POS-DEADLOCK-001', 25000);

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        $attemptCounter = (object) ['value' => 0];

        app()->bind(PosCheckoutPostingAdapter::class, function () use ($attemptCounter, $setting): PosCheckoutPostingAdapter {
            return new class($attemptCounter, $setting) implements PosCheckoutPostingAdapter
            {
                public function __construct(
                    private object $attemptCounter,
                    private Setting $setting
                ) {
                }

                public function post(array $context): array
                {
                    $this->attemptCounter->value++;

                    if ($this->attemptCounter->value === 1) {
                        $previous = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
                        $previous->errorInfo = ['40001', 1213];

                        throw new QueryException('sqlite', 'insert into sales ...', [], $previous);
                    }

                    $sale = Sale::query()->create([
                        'date' => Carbon::now()->toDateString(),
                        'due_date' => Carbon::now()->toDateString(),
                        'customer_id' => $context['customer_id'],
                        'customer_name' => 'DEADLOCK RETRY SUCCESS',
                        'tax_percentage' => 0,
                        'tax_amount' => 0,
                        'discount_percentage' => 0,
                        'discount_amount' => 0,
                        'shipping_amount' => 0,
                        'total_amount' => 25000,
                        'paid_amount' => 25000,
                        'due_amount' => 0,
                        'status' => 'DISPATCHED',
                        'payment_status' => 'PAID',
                        'payment_method' => 'CASH',
                        'setting_id' => $this->setting->id,
                        'is_tax_included' => false,
                    ]);

                    return [
                        'sale_id' => $sale->id,
                        'dispatch_ids' => [],
                        'sale_payment_id' => 0,
                        'receipt_number' => 'RCPT-DEADLOCK-RETRY',
                    ];
                }
            };
        });

        $response = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEADLOCK-RETRY-001',
                'payment' => [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 25000,
                ],
            ]);

        $response->assertStatus(201);

        $this->assertEquals(2, $attemptCounter->value, 'The deadlocked first attempt must have been retried exactly once before succeeding.');
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('pos_checkouts', [
            'setting_id' => $setting->id,
            'idempotency_key' => 'k-deadlock-retry-001',
            'status' => PosCheckout::STATUS_POSTED,
        ]);
        // Exactly one sequence namespace row must exist — the rolled-back first attempt
        // must not have left a second, orphaned counter.
        $this->assertDatabaseCount('document_sequences', 1);
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'deadlock.retry.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'PD-DLR',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
            'pos_enabled' => true,
            'is_pkp' => false,
        ]);
    }

    private function createUserForSetting(Setting $setting): User
    {
        $role = Role::firstOrCreate(['name' => 'DEADLOCK-RETRY-CASHIER-' . $setting->id]);
        $role->syncPermissions([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.sessions.require-terminal',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'PD-DLR-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'Deadlock Retry Terminal ' . $index,
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

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice): Product
    {
        $unit = Unit::firstOrCreate(['name' => 'UNIT', 'short_name' => 'U']);
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;

        $category = Category::create([
            'category_name' => $code . ' CATEGORY',
            'category_code' => $code . '-CAT-' . $this->sequence++,
            'setting_id' => $setting->id,
            'created_by' => $createdBy,
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 1000,
            'product_cost' => 1000,
            'product_price' => $salePrice,
            'product_unit' => 'U',
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1000,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1000,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'sale_tax_id' => null,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'DEADLOCK RETRY COA ' . $this->sequence++,
            'account_number' => 'DLR-CASH-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);
        \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->insert([
            'setting_id' => $setting->id,
            'payment_method_id' => $method->id,
            'is_enabled' => true,
        ]);

        return ['cash' => $method];
    }
}
