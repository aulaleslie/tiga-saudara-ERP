<?php

namespace Modules\Pos\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Modules\Pos\Services\Exceptions\PosCartMutationException;
use Modules\Pos\Services\PosCartMutationLock;
use RuntimeException;
use Tests\TestCase;

class PosCartMutationLockTest extends TestCase
{
    private PosCartMutationLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->lock = app(PosCartMutationLock::class);
    }

    public function test_it_runs_the_callback_and_returns_its_value(): void
    {
        $result = $this->lock->withLock(1, 2, fn (): string => 'done');

        $this->assertSame('done', $result);
    }

    public function test_it_releases_the_lock_after_a_successful_mutation(): void
    {
        $this->lock->withLock(1, 2, fn (): bool => true);

        // A second acquisition must succeed immediately if release happened.
        $this->assertTrue($this->lock->withLock(1, 2, fn (): bool => true));
    }

    public function test_it_releases_the_lock_when_the_callback_throws(): void
    {
        try {
            $this->lock->withLock(1, 2, function (): void {
                throw new RuntimeException('mutation blew up');
            });
            $this->fail('Expected the callback exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mutation blew up', $exception->getMessage());
        }

        // Guaranteed release means the cart is not wedged for the next writer.
        $this->assertTrue($this->lock->withLock(1, 2, fn (): bool => true));
    }

    public function test_it_supports_reentrant_acquisition_of_the_same_cart(): void
    {
        // getSnapshot() is itself a cart writer and is called from within other
        // mutations, so nested acquisition must not deadlock.
        $result = $this->lock->withLock(1, 2, function (): string {
            return $this->lock->withLock(1, 2, fn (): string => 'nested');
        });

        $this->assertSame('nested', $result);
    }

    public function test_reentrant_inner_release_does_not_free_the_outer_lock(): void
    {
        $this->lock->withLock(1, 2, function (): void {
            $this->lock->withLock(1, 2, fn (): bool => true);

            // Still inside the outer lock: a competing holder must not acquire.
            $competing = Cache::lock('pos.cart.mutation.setting.1.session.2', 5);
            $this->assertFalse($competing->get(), 'Inner release freed the outer lock.');
        });
    }

    public function test_it_throws_a_retryable_error_when_the_lock_is_held(): void
    {
        $competing = Cache::lock('pos.cart.mutation.setting.1.session.2', 30);
        $this->assertTrue($competing->get());

        try {
            $this->lock->withLock(1, 2, fn (): bool => true);
            $this->fail('Expected a contended lock to raise a retryable error.');
        } catch (PosCartMutationException $exception) {
            $this->assertSame('CART_BUSY', $exception->errorCode());
            $this->assertSame(409, $exception->httpStatus());
        } finally {
            $competing->forceRelease();
        }
    }

    public function test_lock_timeout_does_not_run_the_callback(): void
    {
        $competing = Cache::lock('pos.cart.mutation.setting.1.session.2', 30);
        $competing->get();
        $ran = false;

        try {
            $this->lock->withLock(1, 2, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('Expected a retryable lock error.');
        } catch (PosCartMutationException) {
            // Expected: contention must leave the cart untouched.
        } finally {
            $competing->forceRelease();
        }

        $this->assertFalse($ran, 'Callback ran despite failing to acquire the lock.');
    }

    public function test_collaborators_in_one_scope_share_the_lock_instance(): void
    {
        // Cross-service re-entrance works only because every collaborator
        // inside one request resolves the same scoped instance.
        $this->assertSame(
            app(PosCartMutationLock::class),
            app(PosCartMutationLock::class)
        );

        // And the services that guard the cart receive that same instance.
        $lockProperty = new \ReflectionProperty(\Modules\Pos\Services\PosCartService::class, 'cartMutationLock');
        $lockProperty->setAccessible(true);

        $this->assertSame(
            app(PosCartMutationLock::class),
            $lockProperty->getValue(app(\Modules\Pos\Services\PosCartService::class)),
            'PosCartService must share the scoped lock instance.'
        );
    }

    public function test_reentrance_holds_for_collaborators_within_one_scope(): void
    {
        // The coordinator holds the lock and calls into a cart service; the
        // nested call must recognise the ownership, not block on it.
        $outer = app(PosCartMutationLock::class);
        $collaborator = app(PosCartMutationLock::class);

        $result = $outer->withLock(1, 2, function () use ($collaborator): string {
            return $collaborator->withLock(1, 2, fn (): string => 'no self-deadlock');
        });

        $this->assertSame('no self-deadlock', $result);
    }

    public function test_a_fresh_scope_cannot_inherit_reentrant_ownership(): void
    {
        // Under a long-lived worker, a new request must never bypass the real
        // cache lock because an earlier request held the same key.
        $held = Cache::lock('pos.cart.mutation.setting.1.session.2', 60);
        $this->assertTrue($held->get());

        // Simulate the next request in the same process.
        app()->forgetScopedInstances();
        $freshScopeLock = app(PosCartMutationLock::class);
        $ran = false;

        try {
            $freshScopeLock->withLock(1, 2, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('A fresh scope bypassed a lock held by another context.');
        } catch (PosCartMutationException $exception) {
            $this->assertSame('CART_BUSY', $exception->errorCode());
        } finally {
            $held->forceRelease();
        }

        $this->assertFalse($ran, 'Callback ran without genuine exclusion.');
    }

    public function test_nested_bypass_applies_only_to_the_owning_key(): void
    {
        $competing = Cache::lock('pos.cart.mutation.setting.9.session.9', 60);
        $competing->get();

        try {
            $this->lock->withLock(1, 2, function (): void {
                // Owning cart 1/2 must not grant a bypass for a different cart.
                $this->expectException(PosCartMutationException::class);
                $this->lock->withLock(9, 9, fn (): bool => true);
            });
        } finally {
            $competing->forceRelease();
        }
    }

    public function test_ownership_survives_beyond_the_former_ttl(): void
    {
        // The original 15s TTL could expire mid-callback, letting a second
        // writer in while the first still believed it held exclusion. Prove a
        // competitor cannot acquire during an operation that runs past it.
        $competitorAcquired = null;

        $this->lock->withLock(1, 2, function () use (&$competitorAcquired): void {
            // Simulate a guarded operation outliving the old TTL. Travelling the
            // clock advances cache expiry without a 16-second real-time wait.
            $this->travel(16)->seconds();

            $competing = Cache::lock('pos.cart.mutation.setting.1.session.2', 30);
            $competitorAcquired = $competing->get();

            if ($competitorAcquired) {
                $competing->forceRelease();
            }
        });

        $this->travelBack();

        $this->assertFalse(
            $competitorAcquired,
            'Lock ownership lapsed mid-operation; compensation exclusion is not guaranteed.'
        );
    }

    public function test_a_cart_mutation_is_rejected_while_checkout_holds_the_cart(): void
    {
        // Checkout holds the lock across snapshot, posting, and clear. A cashier
        // mutating meanwhile must be turned away rather than changing the cart
        // being posted, which could otherwise leave already-posted lines behind.
        $checkoutHeld = Cache::lock('pos.cart.mutation.setting.1.session.2', 60);
        $this->assertTrue($checkoutHeld->get(), 'Could not simulate checkout holding the cart.');

        $mutated = false;

        try {
            app(PosCartMutationLock::class)->withLock(1, 2, function () use (&$mutated): void {
                $mutated = true;
            });
            $this->fail('A cart mutation proceeded while checkout was posting that cart.');
        } catch (PosCartMutationException $exception) {
            $this->assertSame('CART_BUSY', $exception->errorCode());
            $this->assertSame(409, $exception->httpStatus());
        } finally {
            $checkoutHeld->forceRelease();
        }

        $this->assertFalse($mutated, 'The cart was modified during checkout posting.');
    }

    public function test_locks_are_scoped_per_setting_and_session(): void
    {
        $held = Cache::lock('pos.cart.mutation.setting.1.session.2', 30);
        $held->get();

        try {
            // A different session on the same setting is an independent cart.
            $this->assertTrue($this->lock->withLock(1, 3, fn (): bool => true));
            // As is the same session number under a different setting.
            $this->assertTrue($this->lock->withLock(9, 2, fn (): bool => true));
        } finally {
            $held->forceRelease();
        }
    }
}
