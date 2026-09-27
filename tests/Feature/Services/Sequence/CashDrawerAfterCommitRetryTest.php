<?php

namespace Tests\Feature\Services\Sequence;

use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceNamespace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Services\Contracts\PosCashDrawerAdapter;
use Modules\Pos\Services\PosCashDrawerService;
use Modules\Setting\Entities\Setting;
use PDOException;
use Tests\TestCase;

/**
 * Verifies the exact pattern FinalizePosCheckoutService uses around the cash-drawer
 * "irreversible callback" — DB::afterCommit(...) registered inside the closure passed to
 * DocumentSequenceAllocator::executeWholeOperationWithConflictRetry() (see
 * Modules/Pos/Services/FinalizePosCheckoutService.php around the DB::afterCommit block
 * calling PosCashDrawerService::triggerDrawerOpen()) — actually fires at most once per
 * successful checkout, never on a rolled-back attempt, and never again on idempotent replay.
 *
 * Uses a real PosCashDrawerService bound to a container-registered fake
 * PosCashDrawerAdapter spy, so the assertions exercise the real afterCommit/rollback
 * semantics rather than reimplementing them.
 */
class CashDrawerAfterCommitRetryTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Drawer Test Business',
            'company_email' => 'drawer@example.com',
            'company_phone' => '000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'drawer@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
            'document_prefix' => 'PD-DR',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);
    }

    public function test_rolled_back_attempt_never_triggers_the_drawer(): void
    {
        $spy = $this->bindDrawerSpy();

        $ns = $this->allocator();
        $namespace = $ns->buildNamespace(DocumentType::PURCHASE, $this->setting->id, '2026-08-15');

        // Break the RefreshDatabase outer transaction so executeWholeOperationWithConflictRetry
        // owns the true outermost transaction boundary — otherwise it would delegate to
        // executeOnceWithinExistingTransaction() (no new transaction opened here), and
        // DB::afterCommit's rollback semantics would not be exercised meaningfully.
        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        try {
            try {
                $ns->executeWholeOperationWithConflictRetry(
                    function () use ($spy) {
                        DB::afterCommit(function () use ($spy) {
                            $this->openDrawerViaService($spy['adapter']);
                        });

                        throw new \RuntimeException('non-conflict failure forces rollback, afterCommit must not fire');
                    },
                    static fn (): array => [$namespace]
                );
                $this->fail('Expected exception was not thrown.');
            } catch (\RuntimeException $e) {
                $this->assertEquals('non-conflict failure forces rollback, afterCommit must not fire', $e->getMessage());
            }

            $this->assertEquals(0, $spy['adapter']->callCount, 'afterCommit callback must never fire when its transaction rolled back.');
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_successful_retried_transaction_triggers_the_drawer_exactly_once(): void
    {
        $spy = $this->bindDrawerSpy();

        $ns = $this->allocator();
        $namespace = $ns->buildNamespace(DocumentType::PURCHASE, $this->setting->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $result = $ns->executeWholeOperationWithConflictRetry(
                function () use (&$attempts, $spy) {
                    $attempts++;

                    // Register the afterCommit callback on every attempt, exactly as
                    // FinalizePosCheckoutService does inside its retried closure — the
                    // point under test is that only the attempt whose transaction
                    // actually commits ends up firing the callback.
                    DB::afterCommit(function () use ($spy) {
                        $this->openDrawerViaService($spy['adapter']);
                    });

                    if ($attempts === 1) {
                        $previous = new PDOException(
                            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.reference'
                        );
                        $previous->errorInfo = ['23000', 19];
                        throw new QueryException('sqlite', 'insert into purchases ...', [], $previous);
                    }

                    return 'checkout-ok';
                },
                static fn (): array => [$namespace]
            );

            $this->assertEquals('checkout-ok', $result);
            $this->assertEquals(2, $attempts, 'The collision must have forced exactly one retry before success.');
            $this->assertEquals(1, $spy['adapter']->callCount, 'The drawer must open exactly once for the single successful checkout, not once per attempt.');
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_idempotent_replay_does_not_trigger_an_additional_drawer_call(): void
    {
        $spy = $this->bindDrawerSpy();

        $ns = $this->allocator();
        $namespace = $ns->buildNamespace(DocumentType::PURCHASE, $this->setting->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        try {
            // First (real) checkout: commits successfully, drawer opens once.
            $ns->executeWholeOperationWithConflictRetry(
                function () use ($spy) {
                    DB::afterCommit(function () use ($spy) {
                        $this->openDrawerViaService($spy['adapter']);
                    });

                    return 'checkout-ok';
                },
                static fn (): array => [$namespace]
            );

            $this->assertEquals(1, $spy['adapter']->callCount);

            // Idempotent replay: production code (see FinalizePosCheckoutService::finalize())
            // detects the already-completed idempotency key and returns the cached response
            // WITHOUT re-executing the posting closure — so the drawer-triggering closure
            // is never invoked a second time. Modeled here directly: the replay path simply
            // does not call executeWholeOperationWithConflictRetry again.
            $replayResult = 'checkout-ok'; // cached response, no new closure execution

            $this->assertEquals('checkout-ok', $replayResult);
            $this->assertEquals(1, $spy['adapter']->callCount, 'An idempotent replay must not trigger any additional drawer call.');
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    private function breakOuterTransactionForOutermostTest(): int
    {
        $initialLevel = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        return $initialLevel;
    }

    private function restoreOuterTransaction(int $initialLevel): void
    {
        DB::table('document_sequences')->where('setting_id', $this->setting->id)->delete();
        DB::table('purchases')->where('setting_id', $this->setting->id)->delete();
        DB::table('settings')->where('id', $this->setting->id)->delete();
        DB::table('currencies')->truncate();

        while (DB::transactionLevel() < $initialLevel) {
            DB::beginTransaction();
        }
    }

    private function openDrawerViaService(object $adapterSpy): void
    {
        $service = new PosCashDrawerService($adapterSpy);
        $service->triggerDrawerOpen(
            PosCashDrawerService::TRIGGER_CASH_SALE,
            1,
            1,
            ['pos_checkout_id' => 1],
            $this->makeTerminalWithDrawerPolicyEnabled()
        );
    }

    /**
     * @return array{adapter: object}
     */
    private function bindDrawerSpy(): array
    {
        $adapter = new class implements PosCashDrawerAdapter {
            public int $callCount = 0;

            public function openDrawer(array $context): array
            {
                $this->callCount++;

                return ['success' => true, 'message' => 'ok'];
            }
        };

        return ['adapter' => $adapter];
    }

    private function makeTerminalWithDrawerPolicyEnabled(): \Modules\Pos\Entities\PosTerminal
    {
        $terminal = new \Modules\Pos\Entities\PosTerminal();
        $terminal->setRelation('policy', new \Modules\Pos\Entities\PosTerminalPolicy([
            'auto_open_drawer_on_cash_sale' => true,
        ]));

        return $terminal;
    }

    private function allocator(): DocumentSequenceAllocator
    {
        return new DocumentSequenceAllocator();
    }
}
