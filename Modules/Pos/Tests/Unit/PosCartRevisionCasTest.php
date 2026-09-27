<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCartSessionStore;
use Tests\TestCase;

/**
 * Behavioral coverage for the cart revision compare-and-set contract.
 *
 * Checkout captures an authoritative cart snapshot early and clears the cart
 * much later, after stock resolution and posting. Locking only the final clear
 * does not protect that read-to-clear span: a cashier can build a new cart in
 * between, and an unconditional clear would delete it. The revision CAS makes
 * the clear apply only to the exact cart that was posted.
 */
class PosCartRevisionCasTest extends TestCase
{
    private PosCartSessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PosCartSessionStore();
    }

    public function test_new_cart_starts_at_revision_zero(): void
    {
        $this->assertSame(0, $this->store->getCart(1, 2)['revision']);
        $this->assertSame(0, $this->store->currentRevision(1, 2));
    }

    public function test_every_write_advances_the_revision(): void
    {
        $cart = $this->store->emptyCart(1, 2);

        $this->store->putCart(1, 2, $cart);
        $this->assertSame(1, $this->store->currentRevision(1, 2));

        $this->store->putCart(1, 2, $cart);
        $this->assertSame(2, $this->store->currentRevision(1, 2));

        $this->store->putCart(1, 2, $cart);
        $this->assertSame(3, $this->store->currentRevision(1, 2));
    }

    public function test_get_cart_reports_the_stored_revision(): void
    {
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));

        $this->assertSame(2, $this->store->getCart(1, 2)['revision']);
    }

    public function test_clear_succeeds_when_the_revision_still_matches(): void
    {
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));
        $posted = $this->store->currentRevision(1, 2);

        $this->assertTrue($this->store->clearCartIfRevisionMatches(1, 2, $posted));
        $this->assertSame([], $this->store->getCart(1, 2)['lines']);
    }

    public function test_stale_compare_and_set_never_clears_a_changed_cart(): void
    {
        // Checkout itself now holds the cart lock across its whole span, so it
        // cannot observe this race. The compare-and-set primitive remains for
        // any consumer that cannot hold the lock for its full read-to-write
        // span, and must refuse to clear a cart that changed underneath it.
        $cart = $this->store->emptyCart(1, 2);
        $cart['note'] = 'captured revision';
        $this->store->putCart(1, 2, $cart);
        $capturedRevision = $this->store->currentRevision(1, 2);

        $newer = $this->store->emptyCart(1, 2);
        $newer['note'] = 'changed afterwards';
        $this->store->putCart(1, 2, $newer);

        $cleared = $this->store->clearCartIfRevisionMatches(1, 2, $capturedRevision);

        $this->assertFalse($cleared, 'A stale revision cleared a cart that had changed.');
        $this->assertSame(
            'changed afterwards',
            $this->store->getCart(1, 2)['note'],
            'The changed cart was destroyed by a stale compare-and-set.'
        );
    }

    public function test_clear_is_idempotent_when_the_cart_is_already_absent(): void
    {
        // Idempotent checkout replays must not fail because the cart is gone.
        $this->assertTrue($this->store->clearCartIfRevisionMatches(1, 2, 7));
    }

    public function test_revision_never_restarts_after_the_cart_is_cleared(): void
    {
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));
        $before = $this->store->currentRevision(1, 2);

        $this->store->clearCart(1, 2);
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));

        $this->assertGreaterThan(
            $before,
            $this->store->currentRevision(1, 2),
            'A recreated cart reused an earlier revision; stale compare-and-set could match it.'
        );
    }

    public function test_stale_revision_cannot_clear_a_cart_created_after_a_clear(): void
    {
        // ABA: capture revision -> clear -> create a new cart. If the counter
        // restarted, the new cart would reuse the captured revision and a stale
        // compare-and-set would delete a cart it never posted.
        $original = $this->store->emptyCart(1, 2);
        $original['note'] = 'cart the checkout captured';
        $this->store->putCart(1, 2, $original);
        $capturedRevision = $this->store->currentRevision(1, 2);

        $this->store->clearCart(1, 2);

        $recreated = $this->store->emptyCart(1, 2);
        $recreated['note'] = 'brand new cart';
        $this->store->putCart(1, 2, $recreated);

        // The core invariant: the recreated cart must not be stamped with the
        // captured revision. If generations restarted, these would collide.
        $this->assertNotSame(
            $capturedRevision,
            $this->store->getCart(1, 2)['revision'],
            'A cart created after a clear reused the captured revision (ABA).'
        );

        $cleared = $this->store->clearCartIfRevisionMatches(1, 2, $capturedRevision);

        $this->assertFalse($cleared, 'A stale revision matched a cart created after a clear (ABA).');
        $this->assertSame(
            'brand new cart',
            $this->store->getCart(1, 2)['note'],
            'The recreated cart was destroyed by a stale compare-and-set.'
        );
    }

    public function test_revision_is_tracked_per_cart(): void
    {
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));
        $this->store->putCart(1, 2, $this->store->emptyCart(1, 2));
        $this->store->putCart(1, 3, $this->store->emptyCart(1, 3));

        $this->assertSame(2, $this->store->currentRevision(1, 2));
        $this->assertSame(1, $this->store->currentRevision(1, 3));
    }

    public function test_legacy_cart_without_a_revision_reads_as_zero_and_advances(): void
    {
        // Carts stored before the revision field existed must not break.
        session()->put('pos.cart.setting.1.session.2', [
            'setting_id' => 1,
            'session_id' => 2,
            'lines' => [],
        ]);

        $this->assertSame(0, $this->store->getCart(1, 2)['revision']);

        $this->store->putCart(1, 2, $this->store->getCart(1, 2));

        $this->assertSame(1, $this->store->currentRevision(1, 2));
    }
}
