<?php

namespace Tests\Feature\Services\Sequence\Concurrency;

use App\Services\Sequence\DocumentSequence;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\ProductStock;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Exercises the REAL production POS checkout path
 * (Modules\Pos\Services\FinalizePosCheckoutService::finalize(), via the
 * pos_checkout_worker.php harness) under real OS-process concurrency against
 * MySQL, per requirement #5 of the harden-purchase-sales-document-sequences
 * self-review: no reimplementation of locking/allocation/posting logic in
 * the worker — it invokes the same service the HTTP controller calls.
 *
 * @group mysql
 */
class PosProductionCheckoutConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private int $sequence = 1;

    private string $workerScriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerScriptPath = base_path('tests/Feature/Services/Sequence/Concurrency/pos_checkout_worker.php');

        DB::connection('mysql_test')->table('currencies')->insert([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_production_checkout_path_under_concurrent_os_processes_is_deadlock_free_and_produces_unique_split_references(): void
    {
        $terminalSetting = $this->createSetting('POS PROD STORE 1', 'PD-S1', 'SL');
        $sourceSetting = $this->createSetting('POS PROD STORE 2', 'PD-S2', 'SL');

        $prefixA = 'PD-S1-SL';
        $prefixB = 'PD-S2-SL';

        $cashier = $this->createUserForSetting($terminalSetting);

        $loc1 = Location::create(['name' => 'PROD LOC 1', 'setting_id' => $terminalSetting->id]);
        $loc2 = Location::create(['name' => 'PROD LOC 2', 'setting_id' => $sourceSetting->id]);

        $this->setupTerminal($terminalSetting, [$loc1, $loc2]);
        $methods = $this->seedPaymentMethods($terminalSetting);

        $terminal = PosTerminal::where('setting_id', $terminalSetting->id)->first();

        $customerA = Customer::factory()->create(['setting_id' => $terminalSetting->id]);
        $customerB = Customer::factory()->create(['setting_id' => $sourceSetting->id]);
        $terminalSetting->update(['pos_walk_in_customer_id' => $customerA->id]);
        $sourceSetting->update(['pos_walk_in_customer_id' => $customerB->id]);

        $tax = Tax::create(['name' => 'VAT 11 PROD', 'value' => 11, 'is_default' => true]);

        // $numWorkers exercises both the sequence allocator's retry budget
        // and FinalizePosCheckoutService::resolveCheckoutLedger()'s own
        // outer-transaction deadlock retry (see
        // Modules/Pos/Services/FinalizePosCheckoutService.php,
        // isDeadlockConflict()/isCheckoutIdempotencyUniqueConflict()) under
        // real MySQL contention on the checkout-ledger insert.
        $numWorkers = 4;
        $iterationsPerWorker = 2;

        // Each worker gets its own POS session (cart state and the cart
        // mutation lock key on session id), its own bundle parent product,
        // but a component product deliberately owned by the SAME two owner
        // Settings across all workers so that every worker's checkout
        // contends on the SAME two document_sequences namespaces
        // (terminalSetting / sourceSetting) — exactly the scenario the
        // review requires (real contention on shared owner namespaces).
        $componentB = $this->createStockedProduct($sourceSetting, $loc2, 'PROD-B-SHARED', 50000, 1000, $tax);
        // Distinct source-owned product used ONLY for the standalone
        // reversed-order line, so it never shares a merge key with the
        // bundle's componentB line (which would collapse quantities instead
        // of producing two distinct cart lines with the intended order).
        $standaloneSourceProduct = $this->createStockedProduct($sourceSetting, $loc2, 'PROD-C-STANDALONE', 50000, 1000, $tax);
        // PosCartService::resolveCartProduct() requires a ProductPrice row
        // scoped to the ACTIVE (terminal) setting, not just the product's
        // owning setting, before it can be added directly to a
        // terminal-owned cart (unlike a bundle component, which is priced
        // through the bundle item instead of this direct lookup).
        //
        // Deliberately no sale_tax_id here: PosCheckoutSplitPlannerService
        // resolves a cross-owner bundle child's tax bucket as NON_TAX
        // unconditionally (source setting != POS owner setting — see
        // resolveNonSerialLineChunks()'s bundled-line branch), so this
        // standalone line must also resolve to NON_TAX (no tax_id) to land
        // in the SAME split group/owner namespace as componentB's bundle
        // allocation, rather than opening a third split key
        // (source,loc2,TAX:x) alongside (source,loc2,NON_TAX).
        ProductPrice::create([
            'product_id' => $standaloneSourceProduct->id,
            'setting_id' => $terminalSetting->id,
            'sale_price' => 50000,
            'sale_tax_id' => null,
        ]);

        $workerFixtures = [];
        for ($w = 0; $w < $numWorkers; $w++) {
            $workerSessionData = $this->openSession($terminalSetting, $terminal, $cashier);

            $parent = $this->createStockedProduct($terminalSetting, $loc1, 'PROD-A-W' . $w, 50000, 1000, $tax);

            $bundle = ProductBundle::create([
                'setting_id' => $terminalSetting->id,
                'parent_product_id' => $parent->id,
                'name' => 'Combo Bundle W' . $w,
                'bundle_sale_price' => 100000,
                'price' => 50000,
            ]);
            ProductBundleItem::create([
                'bundle_id' => $bundle->id,
                'product_id' => $componentB->id,
                'quantity' => 1,
                'informational_item_price' => 50000,
            ]);

            // Half the workers add a STANDALONE source-owned line (componentB,
            // owned by $sourceSetting) as cart line 1, then the terminal-owned
            // bundle as cart line 2. PosCheckoutSplitPlannerService::plan()
            // (Modules/Pos/Services/PosCheckoutSplitPlannerService.php) builds
            // its $groupMap by iterating cart lines in order, so this actually
            // reverses which owner's group is inserted first into
            // SplitPosCheckoutPostingAdapter's $namespacesToLock BEFORE
            // lockNamespacesCanonically() sorts them — i.e. the pre-canonical
            // namespace order genuinely differs between "reversed" and
            // "forward" workers, exercising the canonical-sort deadlock
            // immunity from both directions instead of only one.
            $reverseOrder = ($w % 2 === 1);

            $workerFixtures[$w] = [
                'session_id' => $workerSessionData->id,
                'bundle_product_id' => $parent->id,
                'bundle_id' => $bundle->id,
                'standalone_source_product_id' => $reverseOrder ? $standaloneSourceProduct->id : 0,
                'reverse_order' => $reverseOrder,
            ];
        }

        $barrierFile = sys_get_temp_dir() . '/pos_prod_checkout_barrier_' . uniqid() . '.lock';
        if (file_exists($barrierFile)) {
            unlink($barrierFile);
        }

        $processes = [];
        $pipes = [];

        for ($w = 0; $w < $numWorkers; $w++) {
            $fixture = $workerFixtures[$w];

            $cmd = sprintf(
                'php %s %s %s %s %s %s %s %s %s %s %s',
                escapeshellarg($this->workerScriptPath),
                escapeshellarg((string) $terminalSetting->id),
                escapeshellarg((string) $fixture['session_id']),
                escapeshellarg((string) $cashier->id),
                escapeshellarg((string) $fixture['bundle_product_id']),
                escapeshellarg((string) $fixture['bundle_id']),
                escapeshellarg((string) $methods['cash']->id),
                escapeshellarg('W' . $w),
                escapeshellarg((string) $iterationsPerWorker),
                escapeshellarg($barrierFile),
                escapeshellarg((string) $fixture['standalone_source_product_id'])
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes[$w]);
            $this->assertIsResource($proc);
            $processes[$w] = $proc;
        }

        usleep(50000);
        touch($barrierFile);

        $allSaleIds = [];
        $allReferences = [];
        $referencesBySetting = [
            $terminalSetting->id => [],
            $sourceSetting->id => [],
        ];
        $observedLineOrders = [];

        for ($w = 0; $w < $numWorkers; $w++) {
            $stdout = stream_get_contents($pipes[$w][1]);
            $stderr = stream_get_contents($pipes[$w][2]);
            fclose($pipes[$w][0]);
            fclose($pipes[$w][1]);
            fclose($pipes[$w][2]);
            $exitCode = proc_close($processes[$w]);

            $this->assertSame(0, $exitCode, "POS production checkout worker {$w} failed with stderr: {$stderr}");

            $lines = array_filter(explode("\n", trim($stdout)));
            $pendingSaleId = null;
            $pendingSetting = null;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'LINE_ORDER:')) {
                    $observedLineOrders[] = substr($trimmed, 11);
                } elseif (str_starts_with($trimmed, 'SALE_ID:')) {
                    $pendingSaleId = (int) substr($trimmed, 8);
                } elseif (str_starts_with($trimmed, 'REF:')) {
                    $ref = substr($trimmed, 4);
                    $allReferences[] = $ref;
                    if ($pendingSaleId !== null) {
                        $allSaleIds[] = $pendingSaleId;
                    }
                } elseif (str_starts_with($trimmed, 'SETTING:')) {
                    $pendingSetting = (int) substr($trimmed, 8);
                    if (isset($referencesBySetting[$pendingSetting]) && $allReferences !== []) {
                        $referencesBySetting[$pendingSetting][] = end($allReferences);
                    }
                }
            }
        }
        @unlink($barrierFile);

        // Prove the pre-canonical namespace order actually differed across
        // workers, rather than merely being plausible: each worker printed
        // the cart-line product-ownership order it constructed
        // (<sourceOwnerSettingId>><terminalOwnerSettingId> for reversed
        // workers, or just <terminalOwnerSettingId> for forward workers).
        // PosCheckoutSplitPlannerService::plan() derives its owner-group
        // insertion order directly from this cart-line order, so distinct
        // LINE_ORDER strings here is direct evidence of distinct pre-sort
        // namespace order, not an inference.
        $forwardOrder = (string) $terminalSetting->id;
        $reversedOrderPrefix = $sourceSetting->id . '>' . $terminalSetting->id;

        $observedForward = array_filter($observedLineOrders, static fn (string $o): bool => $o === $forwardOrder);
        $observedReversed = array_filter($observedLineOrders, static fn (string $o): bool => $o === $reversedOrderPrefix);

        $expectedCheckouts = $numWorkers * $iterationsPerWorker;

        $this->assertNotEmpty($observedForward, 'Expected at least one checkout with forward pre-canonical namespace order (terminal owner first).');
        $this->assertNotEmpty($observedReversed, 'Expected at least one checkout with reversed pre-canonical namespace order (source owner first).');
        $this->assertCount($expectedCheckouts, $observedLineOrders, 'Expected one LINE_ORDER observation per checkout attempt.');

        // No deadlock exceptions escaped: all worker exit codes were 0
        // (already asserted per worker above), i.e. the retry budget inside
        // executeWholeOperationWithConflictRetry absorbed contention. This
        // includes workers with reversed pre-canonical group order (odd $w,
        // standalone source-owned line added first) alongside workers with
        // forward order (even $w, bundle line only) — canonical sorting must
        // absorb contention from both directions equally.
        $reversedWorkerCount = 0;
        foreach ($workerFixtures as $fixture) {
            if ($fixture['reverse_order']) {
                $reversedWorkerCount++;
            }
        }
        $this->assertGreaterThan(0, $reversedWorkerCount, 'At least one worker must exercise reversed pre-canonical namespace order.');
        $this->assertLessThan($numWorkers, $reversedWorkerCount, 'At least one worker must exercise forward pre-canonical namespace order.');

        // Forward-order checkouts produce 2 Sales (one group per owner, since
        // the cart contains only the cross-setting bundle). Reversed-order
        // checkouts produce 3 Sales: the standalone source-owned line
        // resolves to a taxable split group (source is PKP, so
        // resolveNonSerialLineChunks() requires tax for it), while the
        // bundle's cross-owner component resolves to a non-taxable group
        // (bundled cross-owner children are unconditionally NON_TAX per
        // PosCheckoutSplitPlannerService's bundled-line branch) — two
        // DIFFERENT (sourceSetting, loc2, taxBucket) split keys, so the
        // source owner gets 2 Sales instead of 1 for those checkouts. This
        // does not affect the finding being verified here (pre-canonical
        // namespace order), only the reference/group count, which this
        // assertion now reflects precisely rather than assuming a fixed 2.
        $reversedCheckouts = count($observedReversed);
        $forwardCheckouts = count($observedForward);
        $expectedReferenceCount = ($forwardCheckouts * 2) + ($reversedCheckouts * 3);

        $this->assertCount($expectedReferenceCount, $allReferences, 'Expected 2 sale references per forward checkout and 3 per reversed checkout.');
        $this->assertCount(count($allReferences), array_unique($allReferences), 'No duplicate Sale references across all workers.');

        $this->assertCount($forwardCheckouts + $reversedCheckouts, $referencesBySetting[$terminalSetting->id], 'Every checkout contributes exactly one terminal-owner Sale.');
        $this->assertCount($forwardCheckouts + ($reversedCheckouts * 2), $referencesBySetting[$sourceSetting->id], 'Forward checkouts contribute one source-owner Sale; reversed checkouts contribute two (taxable + non-taxable split groups).');

        foreach ($referencesBySetting[$terminalSetting->id] as $ref) {
            $this->assertStringStartsWith($prefixA . '-', $ref);
        }
        foreach ($referencesBySetting[$sourceSetting->id] as $ref) {
            $this->assertStringStartsWith($prefixB . '-', $ref);
        }

        // document_sequences counters are monotonic and match the number of
        // Sales actually created for that namespace.
        $expectedTerminalSales = $forwardCheckouts + $reversedCheckouts;
        $expectedSourceSales = $forwardCheckouts + ($reversedCheckouts * 2);

        $seqA = DocumentSequence::where('document_type', 'sale')
            ->where('setting_id', $terminalSetting->id)
            ->where('prefix', $prefixA)
            ->first();
        $this->assertNotNull($seqA);
        $this->assertEquals($expectedTerminalSales, $seqA->last_number);

        $seqB = DocumentSequence::where('document_type', 'sale')
            ->where('setting_id', $sourceSetting->id)
            ->where('prefix', $prefixB)
            ->first();
        $this->assertNotNull($seqB);
        $this->assertEquals($expectedSourceSales, $seqB->last_number);

        $salesCountA = Sale::where('setting_id', $terminalSetting->id)->count();
        $salesCountB = Sale::where('setting_id', $sourceSetting->id)->count();
        $this->assertEquals($expectedTerminalSales, $salesCountA);
        $this->assertEquals($expectedSourceSales, $salesCountB);

        // No partial checkout artifacts: every SaleDetail row belongs to a
        // Sale that exists, and every POS-originated checkout that produced
        // output is marked POSTED (FinalizePosCheckoutService's completeness
        // invariant — see PosCheckout::STATUS_POSTED).
        $orphanDetailCount = DB::connection('mysql_test')->table('sale_details as sd')
            ->leftJoin('sales as s', 's.id', '=', 'sd.sale_id')
            ->whereIn('sd.sale_id', $allSaleIds)
            ->whereNull('s.id')
            ->count();
        $this->assertEquals(0, $orphanDetailCount, 'Every SaleDetail must belong to an existing Sale.');

        foreach (array_unique($allSaleIds) as $saleId) {
            $detailCount = SaleDetails::where('sale_id', $saleId)->count();
            $this->assertGreaterThan(0, $detailCount, "Sale {$saleId} must have at least one SaleDetails row (no partial checkout artifact).");
        }

        $postedCheckoutCount = PosCheckout::where('setting_id', $terminalSetting->id)
            ->where('status', PosCheckout::STATUS_POSTED)
            ->count();
        $this->assertEquals($expectedCheckouts, $postedCheckoutCount, 'Every successful checkout must be marked POSTED (FinalizePosCheckoutService completeness invariant).');

        $failedCheckoutCount = PosCheckout::where('setting_id', $terminalSetting->id)
            ->where('status', PosCheckout::STATUS_FAILED)
            ->count();
        $this->assertEquals(0, $failedCheckoutCount, 'No checkout should have been left in a FAILED state.');

        // NOTE on "irreversible callback runs only once and only after commit":
        // FinalizePosCheckoutService registers a DB::afterCommit(...) callback
        // that calls PosCashDrawerService::triggerDrawerOpen() for cash sales
        // (see Modules/Pos/Services/FinalizePosCheckoutService.php, the
        // DB::afterCommit block inside the posting flow). A cross-process spy
        // is not practical here (bare stdout/log assertions across proc_open
        // workers cannot reliably bind a PHPUnit mock into another process's
        // container), and is unnecessary: this exact afterCommit/retry
        // interaction — rolled-back attempt fires zero times, a successful
        // retried transaction fires exactly once, and an idempotent replay
        // adds no additional call — is verified directly, in-process, with a
        // container-bound fake PosCashDrawerAdapter, by
        // tests/Feature/Services/Sequence/CashDrawerAfterCommitRetryTest.php.
        // What IS verified here, across real concurrent OS processes, is the
        // durable invariant that callback ultimately depends on:
        // idempotency-key-guarded, exactly-once Sale/SaleDetails/
        // PosCheckout(POSTED) persistence with no duplicate references and no
        // FAILED-but-counted checkouts.
    }

    private function createSetting(string $name, string $docPrefix, string $salePrefix): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'store.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $docPrefix,
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting)
    {
        $role = Role::firstOrCreate(['name' => 'CASHIER-PROD-' . $setting->id]);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function setupTerminal(Setting $setting, array $locations): void
    {
        // Note: Location::booted() already auto-creates a SettingSaleLocation
        // row (setting_id = location's own owner, location_id = the new
        // location) on Location::create() (see
        // Modules/Setting/Entities/Location.php) — MySQL enforces the
        // setting_id+location_id unique constraint that sqlite's test schema
        // does not, so re-inserting that self-mapping here would duplicate
        // it. What's still needed explicitly is any CROSS-setting mapping,
        // i.e. registering a location owned by a different setting as an
        // allowed sale/dispatch source location for this $setting (this is
        // what makes split posting reachable — the bundle's cross-owner
        // component must resolve as an "allowed location" for the terminal
        // setting's stock allocation).
        foreach ($locations as $index => $loc) {
            if ((int) $loc->setting_id === (int) $setting->id) {
                continue;
            }

            SettingSaleLocation::firstOrCreate(
                ['setting_id' => $setting->id, 'location_id' => $loc->id],
                ['is_enabled' => true, 'position' => $index + 1]
            );
        }

        \App\Support\SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'PT-' . $this->sequence++,
            'name' => 'Prod Terminal',
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
    }

    private function openSession(Setting $setting, PosTerminal $terminal, $cashier): PosSession
    {
        return app(PosSessionLifecycleService::class)->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, int $qty, Tax $tax): Product
    {
        $unit = Unit::firstOrCreate(['name' => 'UNIT', 'short_name' => 'U']);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => Category::create([
                'category_name' => 'CAT-' . $code,
                'category_code' => 'CODE-' . $code . '-' . $this->sequence++,
                'setting_id' => $setting->id,
                'created_by' => 1,
            ])->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => $qty,
            'product_cost' => 1000,
            'product_price' => $salePrice,
            'product_unit' => 'U',
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_tax' => $qty,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'sale_tax_id' => $tax->id,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA PROD ' . $this->sequence++,
            'account_number' => 'ACC-PROD-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);
        DB::table('setting_pos_payment_methods')->insert([
            'setting_id' => $setting->id,
            'payment_method_id' => $method->id,
            'is_enabled' => true,
        ]);

        return ['cash' => $method];
    }
}
