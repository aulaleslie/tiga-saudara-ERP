## 1. Bundle selection modal price

- [x] 1.1 In `Modules/Pos/Http/Controllers/PosSellController.php::productBundles()`, change the per-bundle payload so `price` returns `(float) ($bundle->bundle_sale_price ?? 0)` and add a new `legacy_price => (float) ($bundle->price ?? 0)` field.
- [x] 1.2 Verify `Modules/Pos/Resources/views/sell.blade.php` `renderBundleOptions()` continues to render `bundle.price` (no change needed), and that the resulting cashier-facing figure equals the cart line `unit_price` for the same bundle.
- [x] 1.3 Add or update a feature test under `Modules/Pos/Tests/Feature/` covering the bundle selection endpoint shape: asserts `price === bundle_sale_price`, `legacy_price === bundle->price`, and that both fields are present.

## 2. Save-and-New bundle persistence

- [x] 2.1 In `Modules/Pos/Services/PosTransactionSnapshotMapper.php::persistLines()`, extend the `$lineMeta` array to include `bundle_id`, `bundle_name`, `bundle_price`, and `bundle_items` from the cart line (each defaulting to `null` when absent).
- [x] 2.2 In `Modules/Pos/Services/PosTransactionSnapshotMapper.php::hydrateCart()`, when `lineMeta` carries a non-null `bundle_id`, populate the hydrated cart line with `bundle_id`, `bundle_name`, `bundle_price`, and `bundle_items` taken directly from `lineMeta` (no live re-resolution against `product_bundles`).
- [x] 2.3 Confirm the hydrated cart line shape matches what `PosCartService` produces for a freshly added bundled line (same keys, same types) so the frontend cart row renders the bundle pill and `openBundleDetailModal` works.
- [x] 2.4 Verify the `merge_key` round-trip remains correct for bundled lines (the persisted `merge_key` already includes `bundle_id` upstream; hydrate continues to read it from `lineMeta.merge_key`).
- [x] 2.5 Add a feature test under `Modules/Pos/Tests/Feature/` that saves a draft containing a bundled line via "Simpan dan Buka Baru", reloads it, and asserts the hydrated snapshot contains `bundle_id`, `bundle_name`, `bundle_price`, and `bundle_items` matching the original add.
- [x] 2.6 Add a feature test asserting that a draft saved without any bundled line hydrates with no `bundle_id`/`bundle_items` fields and behaves identically to the pre-existing flow.

## 3. Snapshot hash bundle drift detection

- [x] 3.1 In `Modules/Pos/Services/PosTransactionSnapshotMapper.php::buildSnapshotHash()`, include `'bundle_id' => $line->line_meta['bundle_id'] ?? null` in each line entry, alongside existing `tax_id` and `conversion_id` keys.
- [x] 3.2 Ensure the hash function casts `bundle_id` to `int` when present and `null` when absent, and that the canonicalization path in `canonicalizeForHash()` treats it deterministically.
- [x] 3.3 Add a feature test that mutates `line_meta.bundle_id` directly in the database between save and load and asserts the loadToCart endpoint rejects with `SNAPSHOT_DRIFT`.
- [x] 3.4 Add a feature test that saves a draft with no bundle, mutates nothing, and confirms the load succeeds (regression guard for the canonical null shape).

## 4. Cancel allow list tightening

- [x] 4.1 In `Modules/Pos/Services/PosTransactionService.php::cancel()`, remove `PosTransaction::STATUS_LOADED` from the allow list so only `PosTransaction::STATUS_DRAFT` is cancellable; throw `PosTransactionValidationException('TRANSACTION_NOT_CANCELLABLE', ...)` for any other status, including `LOADED`.
- [x] 4.2 In `Modules/Pos/Resources/views/transactions/index.blade.php::canCancelRow()`, restrict the predicate to `row.status === 'DRAFT'` so the "Batalkan" button is hidden entirely for `LOADED` rows.
- [x] 4.3 Verify `Modules/Pos/Resources/views/transactions/show.blade.php` (transaction detail) does not expose a cancel control for `LOADED` transactions; if it currently does, hide it on that status.
- [x] 4.4 Add a feature test asserting `POST /pos/transactions/{id}/cancel` returns a `TRANSACTION_NOT_CANCELLABLE` error when the transaction is in `LOADED` status, both with and without an approval token, and with a user who has `pos.void`.
- [x] 4.5 Add a feature test asserting the transactions list JSON payload no longer surfaces a cancel action affordance for `LOADED` rows (or adjust the existing list test if it checks button presence).

## 5. Regression sweep and validation

- [x] 5.1 Run the existing POS bundle test suite (`Modules/Pos/Tests/Feature/POSBundleCartManagementTest.php`, `POSCustomerTierRepricingTest.php`, `POSSplitSerialBundleCheckoutTest.php`, `POSStandaloneBundleRowPersistenceTest.php`) to confirm no regressions.
- [x] 5.2 Run the POS transaction lifecycle tests covering save-and-new, load-to-cart, and cancel flows; update fixtures as needed for the new bundle metadata in `line_meta`.
- [x] 5.3 Manually exercise the POS sell view: add a bundle-parent product, confirm the modal price equals the eventual cart line unit price, save the cart with "Simpan dan Buka Baru", reload from the transaction list, and verify the bundle pill, bundle-detail modal, and totals match the original cart.
- [x] 5.4 Manually verify the transactions list: a transaction in `LOADED` status shows no "Batalkan" button; the same transaction in `DRAFT` status still shows it for permitted users.
- [x] 5.5 Run `openspec validate fix-pos-bundle-modal-and-draft-roundtrip` (or the project's equivalent) to confirm spec deltas resolve cleanly against current `openspec/specs/`.
