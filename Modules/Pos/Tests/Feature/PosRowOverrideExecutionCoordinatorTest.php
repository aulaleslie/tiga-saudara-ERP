<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosActionApprovalToken;
use Modules\Pos\Services\Exceptions\PosCartMutationException;
use Modules\Pos\Services\PosApprovalTokenService;
use Modules\Pos\Services\PosCartMutationLock;
use Modules\Pos\Services\PosCartSessionStore;
use Modules\Pos\Services\PosRowOverrideExecutionCoordinator;
use Modules\Pos\Services\Exceptions\PosCartCompensationFailedException;
use RuntimeException;
use Throwable;
use Tests\TestCase;

/**
 * Covers the execution ordering guarantees for row overrides:
 *
 *   - failure before persistence changes nothing;
 *   - failure after persistence rolls back the database, restores the exact
 *     cart, and leaves the token unconsumed;
 *   - a token can be consumed exactly once, including under concurrency;
 *   - direct execution compensates when its audit write fails;
 *   - an unrelated cart write is serialized by the mutation lock and is never
 *     erased by compensation.
 */
class PosRowOverrideExecutionCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private PosRowOverrideExecutionCoordinator $coordinator;
    private PosCartSessionStore $store;
    private User $user;
    private int $settingId;
    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();

        $this->coordinator = app(PosRowOverrideExecutionCoordinator::class);
        $this->store = app(PosCartSessionStore::class);
        $this->user = User::factory()->create();

        [$this->settingId, $this->sessionId] = $this->createPosContext();

        $this->seedCart(['note' => 'original', 'unit_price' => 1000]);
    }

    /**
     * Approval rows carry real foreign keys, so the coordinator tests need a
     * genuine setting/terminal/session rather than synthetic identifiers.
     *
     * @return array{0:int, 1:int}
     */
    private function createPosContext(): array
    {
        \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'BIZ ROW OVERRIDE COORDINATOR',
            'company_email' => 'coordinator@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => \Modules\Currency\Entities\Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => false,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'name' => 'COORDINATOR LOC',
            'setting_id' => $setting->id,
        ]);

        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'name' => 'COORDINATOR TERMINAL',
            'code' => 'CRD-1',
            'is_active' => true,
        ]);

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => \Modules\Pos\Entities\PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $this->user->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [(int) $setting->id, (int) $session->id];
    }

    /**
     * @param  array{note?: string, unit_price?: int}  $overrides
     */
    private function seedCart(array $overrides = []): void
    {
        $cart = $this->store->emptyCart($this->settingId, $this->sessionId);
        $cart['note'] = $overrides['note'] ?? 'original';
        $cart['lines'] = [
            1 => [
                'line_id' => 1,
                'product_id' => 10,
                'qty' => 2,
                'unit_price' => $overrides['unit_price'] ?? 1000,
                'price_source' => 'BASE',
            ],
        ];

        $this->store->putCart($this->settingId, $this->sessionId, $cart);
    }

    private function currentCart(): array
    {
        return $this->store->getCart($this->settingId, $this->sessionId);
    }

    /**
     * Build an APPROVED request plus its live token.
     *
     * @return array{request: PosActionApprovalRequest, token: string}
     */
    private function approvedToken(): array
    {
        $request = PosActionApprovalRequest::query()->create([
            'setting_id' => $this->settingId,
            'pos_session_id' => $this->sessionId,
            'action_type' => PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            'target_type' => 'pos_cart_line',
            'target_id' => 1,
            'request_payload' => ['requested_total_minor' => 500000],
            'requested_by' => $this->user->id,
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
        ]);

        return [
            'request' => $request,
            'token' => app(PosApprovalTokenService::class)->issueToken($request),
        ];
    }

    private function applyOverride(array $cart): array
    {
        $cart['lines'][1]['unit_price'] = 2500;
        $cart['lines'][1]['price_source'] = 'LINE_TOTAL_OVERRIDE';

        return $cart;
    }

    // ---------------------------------------------------------------- direct

    public function test_direct_execution_persists_cart_and_audit(): void
    {
        $audited = false;

        $this->coordinator->executeDirect(
            $this->settingId,
            $this->sessionId,
            fn (array $cart): array => $this->applyOverride($cart),
            function () use (&$audited): void {
                $audited = true;
            }
        );

        $this->assertTrue($audited);
        $this->assertSame(2500, $this->currentCart()['lines'][1]['unit_price']);
    }

    public function test_direct_calculation_failure_leaves_cart_unchanged(): void
    {
        try {
            $this->coordinator->executeDirect(
                $this->settingId,
                $this->sessionId,
                function (): array {
                    throw new DomainException('calculation failed');
                },
                fn () => $this->fail('Audit must not run when calculation fails.')
            );
            $this->fail('Expected the calculation failure to propagate.');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(1000, $this->currentCart()['lines'][1]['unit_price']);
    }

    public function test_direct_audit_failure_restores_the_exact_cart(): void
    {
        // Direct execution is not exempt from compensation: a persisted cart
        // with no audit record is exactly the state we must never leave behind.
        try {
            $this->coordinator->executeDirect(
                $this->settingId,
                $this->sessionId,
                fn (array $cart): array => $this->applyOverride($cart),
                function (): void {
                    throw new RuntimeException('audit write failed');
                }
            );
            $this->fail('Expected the audit failure to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $cart = $this->currentCart();
        $this->assertSame(1000, $cart['lines'][1]['unit_price'], 'Cart was not restored after audit failure.');
        $this->assertSame('BASE', $cart['lines'][1]['price_source']);
        $this->assertSame('original', $cart['note']);
    }

    // ------------------------------------------------------------ supervised

    public function test_approved_execution_applies_consumes_and_audits(): void
    {
        ['token' => $token] = $this->approvedToken();
        $audited = false;

        $this->coordinator->executeApproved(
            $this->settingId,
            $this->sessionId,
            $token,
            $this->user->id,
            ['action_type' => PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE],
            fn () => null,
            fn ($request, array $cart): array => $this->applyOverride($cart),
            function () use (&$audited): void {
                $audited = true;
            }
        );

        $this->assertTrue($audited);
        $this->assertSame(2500, $this->currentCart()['lines'][1]['unit_price']);
        $this->assertNotNull(PosActionApprovalToken::query()->where('token_hash', $token)->first()->consumed_at);
    }

    public function test_revalidation_failure_changes_nothing(): void
    {
        ['token' => $token] = $this->approvedToken();

        try {
            $this->coordinator->executeApproved(
                $this->settingId,
                $this->sessionId,
                $token,
                $this->user->id,
                [],
                function (): void {
                    throw new DomainException('fingerprint mismatch');
                },
                fn ($r, array $cart): array => $this->fail('Mutation must not run after failed revalidation.'),
                fn () => $this->fail('Audit must not run after failed revalidation.')
            );
            $this->fail('Expected revalidation failure to propagate.');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(1000, $this->currentCart()['lines'][1]['unit_price']);
        $this->assertNull(
            PosActionApprovalToken::query()->where('token_hash', $token)->first()->consumed_at,
            'A rejected attempt consumed the token; it must stay usable for a correct retry.'
        );
    }

    public function test_audit_failure_rolls_back_consumption_and_restores_cart(): void
    {
        ['token' => $token] = $this->approvedToken();

        try {
            $this->coordinator->executeApproved(
                $this->settingId,
                $this->sessionId,
                $token,
                $this->user->id,
                [],
                fn () => null,
                fn ($r, array $cart): array => $this->applyOverride($cart),
                function (): void {
                    throw new RuntimeException('audit write failed');
                }
            );
            $this->fail('Expected the audit failure to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            1000,
            $this->currentCart()['lines'][1]['unit_price'],
            'Cart override survived a failed audit.'
        );
        $this->assertNull(
            PosActionApprovalToken::query()->where('token_hash', $token)->first()->consumed_at,
            'Token consumption was not rolled back with the transaction.'
        );
    }

    public function test_cart_store_failure_leaves_token_and_audit_untouched(): void
    {
        ['token' => $token] = $this->approvedToken();

        try {
            $this->coordinator->executeApproved(
                $this->settingId,
                $this->sessionId,
                $token,
                $this->user->id,
                [],
                fn () => null,
                function (): array {
                    // Simulate the cart store rejecting the write.
                    throw new RuntimeException('cart store unavailable');
                },
                fn () => $this->fail('Audit must not run when the cart cannot be written.')
            );
            $this->fail('Expected the cart-store failure to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1000, $this->currentCart()['lines'][1]['unit_price']);
        $this->assertNull(PosActionApprovalToken::query()->where('token_hash', $token)->first()->consumed_at);
    }

    public function test_replay_after_success_fails(): void
    {
        ['token' => $token] = $this->approvedToken();

        $execute = fn (): array => $this->coordinator->executeApproved(
            $this->settingId,
            $this->sessionId,
            $token,
            $this->user->id,
            [],
            fn () => null,
            fn ($r, array $cart): array => $this->applyOverride($cart),
            fn () => null
        );

        $execute();

        $this->expectException(DomainException::class);
        $execute();
    }

    public function test_expired_token_is_rejected_without_mutation(): void
    {
        ['token' => $token] = $this->approvedToken();
        PosActionApprovalToken::query()
            ->where('token_hash', $token)
            ->update(['expires_at' => now()->subMinute()]);

        try {
            $this->coordinator->executeApproved(
                $this->settingId,
                $this->sessionId,
                $token,
                $this->user->id,
                [],
                fn () => null,
                fn ($r, array $cart): array => $this->applyOverride($cart),
                fn () => null
            );
            $this->fail('Expected an expired token to be rejected.');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(1000, $this->currentCart()['lines'][1]['unit_price']);
    }

    public function test_conditional_consumption_permits_exactly_one_winner(): void
    {
        ['token' => $token] = $this->approvedToken();
        $tokenService = app(PosApprovalTokenService::class);
        $model = PosActionApprovalToken::query()->where('token_hash', $token)->first();

        $succeeded = 0;
        $failed = 0;

        // Two callers holding the same pre-read model race to consume it. The
        // conditional update decides the winner, not the stale in-memory state.
        foreach ([clone $model, clone $model] as $staleModel) {
            try {
                $tokenService->consumeToken($staleModel, $this->user->id, []);
                $succeeded++;
            } catch (DomainException) {
                $failed++;
            }
        }

        $this->assertSame(1, $succeeded, 'Exactly one consumption must succeed.');
        $this->assertSame(1, $failed, 'The losing consumption must fail.');
    }

    // ------------------------------------------------ unrelated writer safety

    public function test_unrelated_note_write_is_preserved_and_never_erased_by_compensation(): void
    {
        // The mutation lock serializes every cart writer, so a note update
        // cannot land between an override's persist and its compensation. It
        // therefore applies cleanly after compensation and is never erased.
        try {
            $this->coordinator->executeDirect(
                $this->settingId,
                $this->sessionId,
                fn (array $cart): array => $this->applyOverride($cart),
                function (): void {
                    throw new RuntimeException('audit write failed');
                }
            );
        } catch (RuntimeException) {
            // expected; the cart is restored during compensation
        }

        // A cart writer that runs after compensation must see the restored cart
        // and its own write must survive.
        $cart = $this->currentCart();
        $cart['note'] = 'cashier note added afterwards';
        $this->store->putCart($this->settingId, $this->sessionId, $cart);

        $final = $this->currentCart();
        $this->assertSame('cashier note added afterwards', $final['note']);
        $this->assertSame(1000, $final['lines'][1]['unit_price'], 'Compensation did not restore the row.');
    }

    public function test_a_competing_cart_writer_is_blocked_during_execution(): void
    {
        $observed = null;

        $this->coordinator->executeDirect(
            $this->settingId,
            $this->sessionId,
            function (array $cart) use (&$observed): array {
                // Simulate a different request attempting to mutate this cart
                // while the override holds the lock.
                $competing = new PosCartMutationLock();

                try {
                    $competing->withLock($this->settingId, $this->sessionId, fn () => null);
                    $observed = 'entered';
                } catch (PosCartMutationException $exception) {
                    $observed = $exception->errorCode();
                }

                return $this->applyOverride($cart);
            },
            fn () => null
        );

        $this->assertSame('CART_BUSY', $observed, 'A competing writer entered during override execution.');
    }

    // ------------------------------------------- persistence boundary fidelity

    public function test_successful_execution_returns_the_currently_stored_cart(): void
    {
        // putCart() stamps a new generation, so returning the in-memory array
        // would hand callers a cart carrying a revision that was never stored.
        $returned = $this->coordinator->executeDirect(
            $this->settingId,
            $this->sessionId,
            fn (array $cart): array => $this->applyOverride($cart),
            fn () => null
        );

        $stored = $this->currentCart();

        $this->assertSame($stored['revision'], $returned['revision'], 'Returned cart carries a stale revision.');
        $this->assertEquals($stored, $returned, 'Returned cart differs from the stored cart.');
    }

    public function test_audit_receives_the_persisted_revision(): void
    {
        $auditedRevision = null;

        $this->coordinator->executeDirect(
            $this->settingId,
            $this->sessionId,
            fn (array $cart): array => $this->applyOverride($cart),
            function (array $cart) use (&$auditedRevision): void {
                $auditedRevision = $cart['revision'];
            }
        );

        $this->assertSame(
            $this->store->currentRevision($this->settingId, $this->sessionId),
            $auditedRevision,
            'Audit was handed a stale revision rather than the persisted one.'
        );
    }

    public function test_approved_execution_returns_and_audits_the_persisted_revision(): void
    {
        ['token' => $token] = $this->approvedToken();
        $auditedRevision = null;

        $returned = $this->coordinator->executeApproved(
            $this->settingId,
            $this->sessionId,
            $token,
            $this->user->id,
            [],
            fn () => null,
            fn ($r, array $cart): array => $this->applyOverride($cart),
            function ($r, array $cart) use (&$auditedRevision): void {
                $auditedRevision = $cart['revision'];
            }
        );

        $storedRevision = $this->store->currentRevision($this->settingId, $this->sessionId);

        $this->assertSame($storedRevision, $returned['revision']);
        $this->assertSame($storedRevision, $auditedRevision);
    }

    public function test_compensation_restores_content_but_advances_the_generation(): void
    {
        $revisionBefore = $this->store->currentRevision($this->settingId, $this->sessionId);

        try {
            $this->coordinator->executeDirect(
                $this->settingId,
                $this->sessionId,
                fn (array $cart): array => $this->applyOverride($cart),
                function (): void {
                    throw new RuntimeException('audit write failed');
                }
            );
        } catch (RuntimeException) {
            // expected
        }

        $restored = $this->currentCart();

        // Business content is exactly the pre-operation state...
        $this->assertSame(1000, $restored['lines'][1]['unit_price']);
        $this->assertSame('BASE', $restored['lines'][1]['price_source']);
        $this->assertSame('original', $restored['note']);

        // ...but the monotonic generation moved forward. Forcing it backward
        // would let a stale compare-and-set match a cart it never observed.
        $this->assertGreaterThan(
            $revisionBefore,
            $restored['revision'],
            'Compensation rewound the generation counter.'
        );
    }

    public function test_restoration_still_runs_when_rollback_fails(): void
    {
        // A redundant DB::rollBack() is a no-op in Laravel, so forcing a genuine
        // rollback failure requires a connection whose rollBack() throws.
        // Restoration must be guaranteed independently of that failure, or the
        // mutated cart is left behind.
        DB::shouldReceive('rollBack')->andThrow(new RuntimeException('rollback failed'));
        DB::shouldReceive('beginTransaction')->andReturnNull();
        DB::shouldReceive('commit')->andReturnNull();

        try {
            $this->coordinator->executeDirect(
                $this->settingId,
                $this->sessionId,
                fn (array $cart): array => $this->applyOverride($cart),
                function (): void {
                    throw new RuntimeException('audit write failed');
                }
            );
            $this->fail('Expected the audit failure to propagate.');
        } catch (Throwable $exception) {
            $this->assertNotInstanceOf(
                PosCartCompensationFailedException::class,
                $exception,
                'Restoration should have succeeded despite the rollback failure.'
            );
            $this->assertSame('audit write failed', $exception->getMessage());
        }

        $this->assertSame(
            1000,
            $this->currentCart()['lines'][1]['unit_price'],
            'Cart was left mutated because rollback failure skipped restoration.'
        );
    }

    public function test_failed_restoration_surfaces_cart_compensation_failed(): void
    {
        // Make every restoration write fail so compensation cannot be confirmed.
        $this->app->instance(PosCartSessionStore::class, new class extends PosCartSessionStore {
            public bool $failWrites = false;

            public function putCart(int $settingId, int $sessionId, array $cart): void
            {
                if ($this->failWrites) {
                    throw new RuntimeException('session store unavailable');
                }

                parent::putCart($settingId, $sessionId, $cart);
            }
        });

        $store = $this->app->make(PosCartSessionStore::class);
        $coordinator = $this->app->make(PosRowOverrideExecutionCoordinator::class);

        $store->putCart($this->settingId, $this->sessionId, $this->currentCart());

        try {
            $coordinator->executeDirect(
                $this->settingId,
                $this->sessionId,
                fn (array $cart): array => $this->applyOverride($cart),
                function () use ($store): void {
                    // The cart is persisted; now break restoration too.
                    $store->failWrites = true;
                    throw new RuntimeException('audit write failed');
                }
            );
            $this->fail('Expected CART_COMPENSATION_FAILED.');
        } catch (PosCartCompensationFailedException $exception) {
            $this->assertSame('CART_COMPENSATION_FAILED', $exception->errorCode());
            $this->assertSame($this->settingId, $exception->settingId());
            $this->assertSame($this->sessionId, $exception->posSessionId());
            $this->assertInstanceOf(
                RuntimeException::class,
                $exception->getPrevious(),
                'The original failure must remain attached as the root cause.'
            );
            $this->assertSame('audit write failed', $exception->getPrevious()->getMessage());
        }
    }

    public function test_lock_is_released_after_execution(): void
    {
        $this->coordinator->executeDirect(
            $this->settingId,
            $this->sessionId,
            fn (array $cart): array => $this->applyOverride($cart),
            fn () => null
        );

        // A subsequent mutation must not be blocked by a leaked lock.
        $this->assertTrue(
            app(PosCartMutationLock::class)->withLock($this->settingId, $this->sessionId, fn (): bool => true)
        );
    }
}
