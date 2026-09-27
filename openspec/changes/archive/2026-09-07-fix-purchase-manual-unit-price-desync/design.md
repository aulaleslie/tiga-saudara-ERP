## Context

`app/Livewire/Purchase/ProductCart.php::updatePrice()` handles a user manually editing a purchase cart row's unit price. It correctly updates the cart item's top-level `price`/`unit_price` and the `entered_unit_price`/`canonical_unit_price`/`sub_total`/`sub_total_before_tax` options, and sets `pricing_source = 'manual_unit_price'`. But the options merge:

```php
'options' => array_merge($cart_item->options->toArray(), [
    'sub_total' => ...,
    'entered_unit_price' => $new_price,
    'canonical_unit_price' => $canonicalUnitPrice,
    'pricing_source' => $pricingSource,
    ...
]),
```

never assigns a fresh `options['unit_price']`, so the stale value from when the row was first added (`ProductCart::productSelected()` sets `options.unit_price` at add-time) survives the `array_merge()` untouched.

`Modules/Purchase/Services/PurchaseNormalizer.php::normalizeDetail()` then resolves the canonical unit price with `options['unit_price']` taking priority over the cart item's authoritative top-level `price`:

```php
$unitPrice = array_key_exists('unit_price', $options)
    ? $this->toFloat($options['unit_price'])          // stale
    : $this->toFloat(data_get($detailInput, 'unit_price', data_get($detailInput, 'price')));
```

and again when building `$rawPrice` for `PurchaseUomConversionService::convert()`. `convert()` re-derives `normalizedUnitPrice` from that stale price and this overwrites `$unitPrice`/`$price` back to the pre-edit value before they are persisted. `sub_total` escapes the bug only because `resolveIncomingSubTotal()` trusts `options['sub_total']` directly for non-automatic pricing sources, never recomputing it from `$price`.

Net effect: `sub_total` (and therefore `total_amount`, payments, and inventory cost postings that depend on it) is correct, but the displayed `unit_price`/`price` on the purchase detail page is stuck at the pre-edit value. A production scan found 41 affected rows across 29 purchases since the `manual_unit_price` pricing source was introduced (2026-08-21 onward).

## Goals / Non-Goals

**Goals:**
- Stop future manual unit-price edits from persisting a stale `unit_price`/`price`.
- Correct the 41 known-affected rows' `unit_price`/`price` so what the user sees on the purchase detail page matches what they actually committed (i.e., matches the row's existing, correct `sub_total`).

**Non-Goals:**
- Do not touch `sub_total`, `sub_total_before_tax`, `product_tax_amount`, `total_amount`, `due_amount`, payments, or any payment status — these are already correct.
- Do not touch stock, receiving, or dispatch records.
- Do not recalculate or correct `products.average_purchase_price` or `products.last_purchase_price`, even though they may have been seeded from the stale `unit_price` at the time — explicitly out of scope per product decision (display-only fix).
- Do not attempt a general audit/fix of every possible cart-mutator stale-options pattern beyond what's needed to close this specific hole; only fix mutators confirmed to share the exact `array_merge()`-drops-`unit_price` defect.

## Decisions

**Fix at the write site (`updatePrice()`), not just the read site (`PurchaseNormalizer`).** Both are technically broken (the normalizer's preference for `options['unit_price']` over the cart's authoritative price is itself backwards), but fixing only the normalizer leaves other cart mutators free to keep writing stale `options['unit_price']`. Fixing both closes the hole for this path and hardens the normalizer against the same mistake elsewhere.

**Normalizer fix: prefer the cart item's top-level `price`/`entered_unit_price` over `options['unit_price']` when both are present and disagree.** `options['unit_place']` is best understood as a cache of the last-known canonical price, not a fresher source of truth than the price the cart item itself carries. Reorder the `??` precedence in `normalizeDetail()` (`$unitPrice` and `$rawPrice` resolution) so the cart item's own `price` wins.

**Audit sibling mutators for the same array_merge gap before fixing broadly.** `updateUnit()`, `updateTax()`, `updateQuantity()`, `setProductDiscount()`, `updateLineTotal()` all do a similar `array_merge($cart_item->options->toArray(), [...])`. Each will be checked for whether it (a) changes the effective unit price and (b) omits `unit_price` from its merge array. Only those matching both conditions are fixed in this change.

**Backfill via a one-off Artisan command, not a migration.** This is a data correction affecting real purchase records (many already RECEIVED), not a schema change. A command keeps it: reviewable, dry-run-able, run once by a human with production DB access, and easy to scope to exactly the known-affected row IDs rather than a broad heuristic scan (avoiding false positives on legitimately-inconsistent legacy data from other causes).

**Backfill derives `unit_price`/`price` from `sub_total`, not the other way around.** `sub_total` is the authoritative, already-correct value (it reflects what the user actually committed to and what downstream totals/payments were computed from). The corrected `unit_price` is back-derived as:
```
net_unit_price = (sub_total_before_tax) / quantity
unit_price     = net_unit_price + (product_discount_amount / quantity)   // for a per-unit discount, matches existing discount semantics
```
using each row's own `sub_total_before_tax`, `product_discount_amount`, and `quantity`, with `is_tax_included`/PKP status only affecting which subtotal field is treated as pre-tax (already resolved per-row by the existing `sub_total_before_tax` column — no need to re-derive tax logic in the backfill). `price` is set equal to the corrected `unit_price` (matching the existing invariant that both columns hold the same canonical value, per `PurchaseNormalizer`'s own comment: "Both columns are decimal(15,6) and hold the same canonical base-unit price").

**Backfill scope is the exact 41 row IDs identified during exploration, not a live re-scan at run time.** This avoids the backfill accidentally picking up unrelated legacy mismatches with different root causes. The command will accept an explicit list (or re-run the same detection query but require `--confirm` and print every row it's about to touch before writing, so the operator can eyeball it against the known list).

## Risks / Trade-offs

- **[Risk] Backfilling `unit_price` on a RECEIVED purchase could look like it should cascade into receiving/costing records.** → Mitigation: explicitly scope the backfill to `purchase_details.unit_price`/`price` columns only, inside a DB transaction, with no calls into receiving, stock, or product-costing services. Verify via `git grep` that no model observer/event on `PurchaseDetail` save reacts to `unit_price` changes in a way that would mutate other tables; if one exists, use a raw `DB::table('purchase_details')->update()` instead of Eloquent `save()` to bypass it.
- **[Risk] Re-deriving `unit_price` from `sub_total` could disagree with the user's literally-typed price if discount/tax rounding isn't reproduced exactly.** → Mitigation: focused verification (see tasks) recomputes `sub_total` from the corrected `unit_price` using the same formula the normalizer uses and asserts it still equals the stored (untouched) `sub_total` within rounding tolerance, for every backfilled row.
- **[Risk] Fixing sibling mutators (`updateUnit()` etc.) could be scope creep or introduce new regressions in flows not covered by this bug report.** → Mitigation: only fix a sibling mutator if it demonstrably has the same array_merge-drops-unit_price defect; otherwise leave it and note it as a follow-up.

## Migration Plan

1. Ship the `ProductCart::updatePrice()` (and confirmed sibling) fix and the `PurchaseNormalizer` precedence fix together — both are required to fully close the hole.
2. Run the backfill command in a dry-run/preview mode first against the local MySQL copy (via tinker/artisan against the docker `mysql-local` container), inspect the diff for all 41 rows, then run for real.
3. No rollback beyond restoring `unit_price`/`price` from a pre-backfill snapshot (command should log old→new values per row for auditability, e.g. to a log file or table dump before writing).

## Open Questions

- None blocking; sibling-mutator audit happens as part of task execution rather than being pre-decided here.
