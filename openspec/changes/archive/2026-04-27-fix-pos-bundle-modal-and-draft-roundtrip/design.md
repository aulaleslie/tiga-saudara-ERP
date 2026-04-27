## Context

The POS sell flow recently switched to `bundle_sale_price` as the authoritative bundle price ([PosCartService.php:135-137](../../../Modules/Pos/Services/PosCartService.php#L135-L137)) and added bundle composition to the receipt and detail views. Three call sites were not updated to match:

1. **Bundle selection modal endpoint** — `PosSellController::productBundles()` returns `$bundle->price` (the legacy add-on price, e.g. 15.000) while the cart line records `bundle_sale_price` (e.g. 125.000). Cashiers see a different price in the modal than what is charged.
2. **Draft round-trip** — `PosTransactionSnapshotMapper::persistLines()` writes a fixed `line_meta` containing only `barcode, price_source, merge_key, conversion_unit_name`. Cart-line keys `bundle_id`, `bundle_name`, `bundle_price`, `bundle_items` are silently dropped on save and not restored on hydrate. Reloaded drafts look like plain product lines and lose the bundle pill, bundle-detail modal, and bundle-aware merge key.
3. **Cancel allow list** — `PosTransactionService::cancel()` accepts both `DRAFT` and `LOADED`, and the transactions list mirrors that. A loaded transaction is, by definition, currently held in a cart; cancelling it from the list contradicts the load-concurrency guarantee enforced elsewhere by snapshot hash and lock-for-update.

The fix is small in scope and confined to four files. No schema changes are required: `pos_transaction_lines.line_meta` is already a JSON column.

## Goals / Non-Goals

**Goals:**
- Modal price always equals the cart line unit price for bundles.
- "Simpan dan Buka Baru" → "Muat" round-trip is lossless for bundle-bearing lines.
- Cancellation of a `LOADED` POS transaction is impossible from any path (UI or API).
- Snapshot hash detects tampering with the persisted bundle reference.

**Non-Goals:**
- Re-resolving bundle composition from `product_bundles` at hydrate time. Drafts are faithful to the original selection; live bundle edits do not propagate.
- Schema migrations for bundle persistence. `line_meta` JSON suffices.
- Changing the supervisor approval flow for cancel — only the allowed status set narrows.
- Touching the receipt or transaction detail bundle-display flow (already correct).

## Decisions

### Decision 1: Modal payload exposes `bundle_sale_price` as `price`, keeps legacy as `legacy_price`

`PosSellController::productBundles()` builds the response. We change `'price' => (float) $bundle->price` to `'price' => (float) ($bundle->bundle_sale_price ?? 0)` and add `'legacy_price' => (float) ($bundle->price ?? 0)`.

**Why**: the frontend modal renders `formatPrice(bundle.price)` ([sell.blade.php:1719](../../../Modules/Pos/Resources/views/sell.blade.php#L1719)). Keeping the field name `price` means no JS change beyond confirming behavior. `legacy_price` is preserved for any future consumer that needs the add-on figure (e.g. analytics, audit) without forcing a re-shape now.

**Alternatives considered**:
- Rename to `sale_price` in the response → would force a frontend rename for no real value.
- Drop legacy entirely → no consumer reads it today, but it is cheap to keep and removes a regression risk.

### Decision 2: Persist bundle metadata into existing `line_meta` JSON

`PosTransactionSnapshotMapper::persistLines()` extends `$lineMeta`:

```php
$lineMeta = [
    'barcode' => $line['barcode'] ?? null,
    'price_source' => $line['price_source'] ?? 'BASE',
    'merge_key' => $line['merge_key'] ?? null,
    'conversion_unit_name' => $line['conversion_unit_name'] ?? null,
    'bundle_id' => $line['bundle_id'] ?? null,
    'bundle_name' => $line['bundle_name'] ?? null,
    'bundle_price' => $line['bundle_price'] ?? null,
    'bundle_items' => $line['bundle_items'] ?? null,
];
```

`hydrateCart()` mirrors this: when `lineMeta.bundle_id` is present, it copies all four bundle fields back onto the hydrated cart line.

**Why**: `line_meta` is already a JSON column, no migration needed. Bundle data is purely an in-cart presentational/merge concern; we do not need to query drafts by bundle. If a future report needs that, we can lift `bundle_id` to a real column then.

**Alternatives considered**:
- Dedicated columns (`bundle_id`, `bundle_name`, `bundle_price`, `bundle_items_json`) → adds migration, indexability, but no current consumer needs the queryability. Deferred.
- Re-resolve from `product_bundles` at hydrate time → conflicts with Decision 4 (faithful drafts) and would silently change a cashier's saved cart if an admin edits the bundle.

### Decision 3: Snapshot hash includes `bundle_id`

`buildSnapshotHash()` already includes `tax_id`, `conversion_id`, `qty`, `unit_price`, `serials`. We add `bundle_id` (just the id, not the items array — items are derived from id at save time and don't need independent integrity).

**Why**: keeps the drift detector aligned with the new authoritative line shape. If someone (or a bug) flips `line_meta.bundle_id` between save and load, the load is rejected with `SNAPSHOT_DRIFT` instead of silently hydrating a different bundle.

**Alternatives considered**:
- Include the entire `bundle_items` array in the hash → over-broad; any change to product names recorded in items would invalidate hashes.
- Don't include anything bundle-related → leaves a gap where the only authoritative drift signal is `unit_price`, which conflates many possible changes.

### Decision 4: Hydrate `bundle_items` from saved snapshot, not live data

`hydrateCart()` reads `line_meta.bundle_items` directly. No call to `ProductBundleResolver` at hydrate time.

**Why**: a draft is the cashier's frozen intent at save time. If the bundle definition changes (admin renames it, removes an item) between save and load, the cashier's reload should still show what they originally chose, not a surprise. This matches the broader snapshot-hash model where `unit_price`, `tax_rate`, and `product_name_snapshot` are all already frozen at save time.

**Alternatives considered**:
- Re-resolve live → simpler code, fresher data, but breaks the "faithful draft" invariant the rest of the snapshot already maintains.

### Decision 5: Tighten cancel allow list to `DRAFT` only — backend and frontend

`PosTransactionService::cancel()` ([PosTransactionService.php:253-261](../../../Modules/Pos/Services/PosTransactionService.php#L253-L261)) drops `STATUS_LOADED` from the allow list and throws `TRANSACTION_NOT_CANCELLABLE` for loaded transactions. The transactions list `canCancelRow()` ([transactions/index.blade.php:166-172](../../../Modules/Pos/Resources/views/transactions/index.blade.php#L166-L172)) drops the `LOADED` branch and hides the button entirely (no tooltip).

**Why**: a transaction in `LOADED` is currently held in a cart somewhere; cancelling it out from under that cart would race with the cashier's edits. The rule is a domain rule, not a UI preference — the backend must own it. Frontend hiding gives a clean cashier UX; backend tightening guarantees no other path can sneak in.

**Alternatives considered**:
- Frontend-only → leaves the API endpoint permissive; any future caller (script, devtools, alternate UI) could still cancel a loaded transaction.
- Allow LOADED with a force-unload step → adds complexity for a use case that no one has asked for. The user explicitly wants this disallowed.

## Risks / Trade-offs

- **[Existing drafts saved before this change have no bundle metadata in `line_meta`]** → On load, those drafts hydrate as plain product lines, same as today. We do not retroactively backfill. Acceptable: the unit price was correctly captured at save time, so totals are right; the cashier just won't see the bundle pill on a pre-existing draft. Going forward, all newly-saved drafts round-trip correctly.

- **[Snapshot hash now includes `bundle_id` — pre-existing drafts will have hashes computed without it]** → A pre-existing draft hash was computed under the old hash function. After this change the new hash function will produce a different value, and load will throw `SNAPSHOT_DRIFT` for those rows. Mitigation: the load path already handles this error, and this is rare (drafts are short-lived). Alternative: version the hash function and keep computing the legacy hash for old rows. Rejected as over-engineering for a transient state.

- **[Tightening cancel to DRAFT-only could break a list-page row that was loaded since the page rendered]** → If the list shows a `DRAFT` row (button visible) and the user clicks it after another cashier loaded it, the backend now rejects with `TRANSACTION_NOT_CANCELLABLE`. The frontend already surfaces error messages from the cancel endpoint; cashier sees a clear failure rather than a silent racy cancel. Acceptable.

- **[`bundle_items` snapshots can become stale relative to `product_bundles`]** → By design (Decision 4). Documented behavior, not a bug.

## Migration Plan

No data migration. Deploy is a single code change. Existing drafts continue to hydrate as today (no bundle pill, correct totals); new drafts gain bundle round-trip correctness.

Rollback: revert the four edited files. No schema rollback needed.

## Open Questions

None. All decisions confirmed with the requester before drafting.
