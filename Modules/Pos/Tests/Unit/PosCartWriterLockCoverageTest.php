<?php

namespace Modules\Pos\Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the mechanical binding rule: every operation that persists, clears,
 * replaces, or hydrates a POS cart must run inside PosCartMutationLock.
 *
 * The guard set is defined by whether an operation writes the cart, not by
 * whether the write affects approval validity. Override compensation restores
 * an exact pre-operation snapshot, so an unguarded writer landing between
 * persistence and compensation would have its write silently erased. A note
 * update is the canonical example: irrelevant to approval validity, but losing
 * it is still data loss.
 *
 * This test reads source rather than behavior so a newly added cart writer
 * fails here instead of silently reintroducing the compensation gap.
 */
class PosCartWriterLockCoverageTest extends TestCase
{
    /** @var array<int, class-string> */
    private const CART_WRITER_CLASSES = [
        \Modules\Pos\Services\PosCartService::class,
        \Modules\Pos\Services\PosTransactionService::class,
        \Modules\Pos\Services\FinalizePosCheckoutService::class,
        \Modules\Pos\Services\PosRowOverrideExecutionCoordinator::class,
    ];

    /**
     * Writers that are lock-safe by construction rather than by taking the lock
     * themselves, with the reason each one is exempt from the source scan.
     *
     * @var array<string, string>
     */
    private const LOCK_SAFE_BY_CALLER = [
        // Compensation runs inside executeDirect()/executeApproved(), both of
        // which hold the cart mutation lock for the whole operation. Taking the
        // lock again here would be redundant, and the re-entrant lock would
        // simply pass through.
        'restoreCart' => 'called only from within an already-locked execution scope',
    ];

    public function test_every_cart_write_is_inside_a_locked_scope(): void
    {
        $unguarded = [];

        foreach (self::CART_WRITER_CLASSES as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            $lines = explode("\n", file_get_contents($path));

            $currentFunction = null;
            $sawLockInFunction = false;

            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*(?:public|private|protected)\s+function\s+(\w+)/', $line, $m) === 1) {
                    $currentFunction = $m[1];
                    $sawLockInFunction = false;
                }

                if (str_contains($line, 'withLock(') || str_contains($line, 'withCartLock(')) {
                    $sawLockInFunction = true;
                }

                if (preg_match('/->(putCart|clearCart)\(/', $line) !== 1) {
                    continue;
                }

                // Skip the store's own declaration.
                if (str_contains($line, 'function ')) {
                    continue;
                }

                $guarded = ($currentFunction !== null && str_ends_with($currentFunction, 'WithinLock'))
                    || $sawLockInFunction
                    || array_key_exists((string) $currentFunction, self::LOCK_SAFE_BY_CALLER);

                if (! $guarded) {
                    $unguarded[] = sprintf(
                        '%s:%d in %s()',
                        basename($path),
                        $index + 1,
                        $currentFunction ?? '?'
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Unguarded POS cart writes found. Every putCart()/clearCart() must run inside "
                . "PosCartMutationLock, or exact-snapshot compensation can erase it:\n"
                . implode("\n", $unguarded)
        );
    }

    public function test_known_cart_writers_are_all_still_guarded(): void
    {
        // Explicit inventory, including the two writers that are easy to miss:
        // getSnapshot() writes a staged_payment_token despite its name, and
        // updateNote() is unrelated to approvals but still loses data.
        $expected = [
            'getSnapshot',
            'addLineWithinLock',
            'updateLineWithinLock',
            'removeLineWithinLock',
            'updateBillDiscountWithinLock',
            'updateCustomerSelectionWithinLock',
            // Both row overrides now run through the shared execution
            // coordinator, which takes the lock itself.
            'executeRowOverride',
            'assignSerialsWithinLock',
            'updateNote',
            'clearWithinLock',
            'appendSerialWithinLock',
            'removeSerialWithinLock',
        ];

        $source = file_get_contents(
            (new ReflectionClass(\Modules\Pos\Services\PosCartService::class))->getFileName()
        );

        foreach ($expected as $method) {
            $this->assertMatchesRegularExpression(
                '/function ' . preg_quote($method, '/') . '\s*\(/',
                $source,
                "Expected cart writer {$method}() is missing; the lock inventory is stale."
            );
        }
    }
}
