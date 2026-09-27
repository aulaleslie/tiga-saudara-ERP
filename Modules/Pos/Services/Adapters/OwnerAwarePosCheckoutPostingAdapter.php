<?php

namespace Modules\Pos\Services\Adapters;

use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\PosCheckoutSplitPlannerService;
use Modules\Product\Entities\Product;

/**
 * Chooses the posting path from the checkout's actual ownership, not only from config.
 *
 * Split posting is normally config-gated. That gate is safe for stock-only carts, whose
 * content all belongs to whatever the allocator picked, but it is not safe once non-stock
 * content is involved: non-stock ownership comes from the configured first POS sales
 * location, which may be a different business than the one owning the stock allocations.
 * Posting that through the inline single-Sale path would create one terminal-owned Sale
 * combining service revenue from source A with stock content owned by B.
 *
 * So this adapter routes on persisted content classification first:
 *
 * - No non-stock content: always inline. Stock-only carts keep their historical behavior
 *   even when allocation spans several source locations.
 * - Non-stock content present: plan the cart, then use split posting when the plan spans
 *   more than one owner group, or when its single group belongs to a business other than
 *   the terminal setting. Inline posting is used only when the sole planned group is the
 *   terminal setting's own.
 */
class OwnerAwarePosCheckoutPostingAdapter implements PosCheckoutPostingAdapter
{
    public function __construct(
        private readonly InlinePosCheckoutPostingAdapter $inlinePostingAdapter,
        private readonly SplitPosCheckoutPostingAdapter $splitPostingAdapter,
        private readonly PosCheckoutSplitPlannerService $splitPlanner
    ) {
    }

    public function post(array $context): array
    {
        if ($this->requiresOwnerAwarePosting($context)) {
            return $this->splitPostingAdapter->post($context);
        }

        return $this->inlinePostingAdapter->post($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function requiresOwnerAwarePosting(array $context): bool
    {
        $cartSnapshot = is_array($context['cart_snapshot'] ?? null) ? $context['cart_snapshot'] : [];
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];

        if ($lines === []) {
            return false;
        }

        $terminalSettingId = (int) ($context['setting_id'] ?? 0);

        $plan = $this->splitPlanner->plan([
            'setting_id' => $terminalSettingId,
            'cart_snapshot' => $cartSnapshot,
            'allocations' => is_array($context['allocations'] ?? null) ? $context['allocations'] : [],
        ]);

        $groups = is_array($plan['groups'] ?? null) ? $plan['groups'] : [];

        if ($groups === []) {
            return false;
        }

        // Distinct owner settings across all groups
        $distinctOwnerSettings = array_unique(array_map(
            static fn (array $group): int => (int) ($group['source_setting_id'] ?? 0),
            $groups
        ));

        // If plan spans multiple owner settings, split posting is mandatory
        if (count($distinctOwnerSettings) > 1) {
            return true;
        }

        // If sole owner setting is not the terminal setting, split posting is mandatory
        if (count($distinctOwnerSettings) === 1 && reset($distinctOwnerSettings) !== $terminalSettingId) {
            return true;
        }

        // Wholly owned by the terminal setting: if feature flag is enabled, split posting can be used, otherwise inline
        return (bool) config('pos.checkout.split_posting.enabled', false);
    }

    /**
     * True when any parent or bundle component is non-stock-managed by persisted
     * classification. The cart snapshot's own flags are untrusted input.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function containsNonStockContent(array $lines): bool
    {
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0 || (int) ($line['qty'] ?? 0) <= 0) {
                continue;
            }

            // A group line whose parent is not fulfilled here is planner-authored bookkeeping,
            // not a non-stock claim, so its parent classification is still consulted normally.
            if (! $this->isPersistedStockManaged($productId)) {
                return true;
            }

            foreach ((is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : []) as $item) {
                $childProductId = (int) ($item['product_id'] ?? 0);
                if ($childProductId > 0 && ! $this->isPersistedStockManaged($childProductId)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isPersistedStockManaged(int $productId): bool
    {
        if (! array_key_exists($productId, $this->stockManagedProductCache)) {
            $product = Product::query()->whereKey($productId)->first(['id', 'stock_managed']);
            // An unresolvable product is reported by the posting adapter's authoritative
            // check; treat it as stock-managed here so routing stays conservative.
            $this->stockManagedProductCache[$productId] = $product ? (bool) $product->stock_managed : true;
        }

        return $this->stockManagedProductCache[$productId];
    }

    /** @var array<int, bool> */
    private array $stockManagedProductCache = [];
}
