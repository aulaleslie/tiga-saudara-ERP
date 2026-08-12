## 1. Permission and lifecycle authorization

- [x] 1.1 Add canonical `purchases.approved.edit`, `purchases.received.monetary.edit`, and `sales.dispatched.monetary.edit` entries, labels, and existing permission synchronization coverage without changing `purchases.received.correct` or `sales.approved.edit` behavior.
- [x] 1.2 Centralize Purchase and Sale edit-mode resolution from persisted status, active setting, ordinary edit permission, and lifecycle-specific permission.
- [x] 1.3 Apply the resolved authorization consistently in Purchase/Sale controllers, Livewire edit mounts/actions, and edit-action visibility; preserve full approved-unfulfilled editing and deny unauthorized fulfilled edits.

## 2. Restricted existing-form experience

- [x] 2.1 Extend Purchase edit-form/cart hydration with stable existing detail identifiers and a monetary-only mode that locks protected header and line controls after receipt.
- [x] 2.2 Extend Sale edit-form/cart hydration with stable existing detail identifiers and a monetary-only mode that locks protected header and line controls after dispatch.
- [x] 2.3 Update the shared Purchase and Sale form/cart views to hide or disable quantity, product, row add/remove, bundle, counterparty, date, reference, payment-method, and business-selection controls in monetary-only mode while retaining supported monetary inputs.
- [x] 2.4 Clearly label the restricted edit state and retain the existing received-purchase correction entry point as a separate action.

## 3. In-place monetary persistence

- [x] 3.1 Implement Purchase fulfilled-document validation and an atomic in-place monetary update branch that locks the header/details, validates exact row identity and all protected fields, normalizes permitted monetary values, and preserves received-note/serial links.
- [x] 3.2 Implement Sale fulfilled-document validation and an atomic in-place monetary update branch that locks the header/details, validates exact row identity and all protected fields, normalizes permitted monetary values, and preserves dispatch/bundle/serial links and stored cost snapshots.
- [x] 3.3 Ensure the restricted branches retain payment-record amounts and derive/reject document payment summaries consistently with the existing active payment state.
- [x] 3.4 Guard the restricted branches against calls to stock, receipt, dispatch, product-price, purchase-cost replay/recalculation, and sales-cost-snapshot services.

## 4. Centralization and defect correction

- [x] 4.1 Extract one shared, atomic monetary-edit abstraction (`AbstractMonetaryEditService`) with `PurchaseMonetaryEditService` and `SaleMonetaryEditService` implementations, so Livewire and HTTP callers share a single protected persistence path.
- [x] 4.2 Re-derive the edit mode inside the transaction from the locked persisted record; enforce active-setting ownership and an exact one-to-one submitted-row/detail-ID mapping rather than trusting disabled Blade controls.
- [x] 4.3 Key Purchase cart rows by a persisted `purchase_detail_id` in cart metadata (replacing `product_id` keying) and key Sale rows by `sale_detail_id` (replacing `firstWhere('options.product_id', ...)`), so repeated product lines map correctly.
- [x] 4.4 Reject `MONETARY_ONLY` at the legacy `purchases.update` / `sales.update` endpoints with 422 in each `FormRequest::authorize()`, ahead of `rules()`, so they cannot reach the delete-and-recreate persistence.
- [x] 4.5 Pass `$editMode` to `purchase::edit` (previously referenced but never provided).
- [x] 4.6 Preserve Sale global discount and shipping from persisted state instead of hardcoding both to zero and overwriting the stored values.
- [x] 4.7 Remove trailing whitespace; `git diff --check` reports no errors.

## 5. Verification

- [x] 5.1 Add Purchase authorization tests for missing/assigned approved and post-receipt permissions, including tenant scoping and preservation of `purchases.received.correct` access.
- [x] 5.2 Add Sale authorization tests for missing/assigned approved and post-dispatch permissions, including tenant scoping.
- [x] 5.3 Add Purchase feature/Livewire tests proving approved unreceived quantity edits work, post-receipt monetary edits retain detail IDs and receipt links, and protected changes are rejected.
- [x] 5.4 Add Sale feature/Livewire tests proving approved undispatched quantity edits work, post-dispatch monetary edits retain detail IDs/dispatch links/cost snapshots, and protected changes are rejected.
- [x] 5.5 Add regression tests confirming post-fulfillment edits do not alter stock, product last/average purchase prices, payment records, or the existing received-purchase correction workflow.
- [x] 5.6 Add Super Admin coverage proving permission bypass without explicit assignment while lifecycle rules still apply.
- [x] 5.7 Add direct HTTP PUT tests proving legacy `purchases.update` / `sales.update` cannot reach destructive normal update logic in monetary-only mode.
- [x] 5.8 Run focused Purchase and Sale test suites, then run the prescribed fresh SQLite test command or an equivalent full regression check and record results.

## Verification results

- New coverage: 45 tests / 124 assertions, all passing.
  - `Modules/Purchase/Tests/Feature/PurchaseMonetaryEditTest.php` — 15 passed
  - `Modules/Purchase/Tests/Feature/MonetaryEditAuthorizationTest.php` — 15 passed
  - `Modules/Sale/Tests/Feature/SaleMonetaryEditTest.php` — 15 passed
- `composer test:fresh-sqlite` (full suite, fresh schema):
  - Branch: **378 failed, 4 skipped, 3172 passed** (13816 assertions)
  - Untouched baseline: **378 failed, 4 skipped, 3127 passed** (13695 assertions)
  - Identical failure count; the branch adds 45 passing tests and introduces no
    new failures. All 378 are pre-existing (missing routes, serial-visibility
    fixtures, permission-catalog fixtures, 2FA) and unrelated to this change.
- Existing `purchases.received.correct` coverage
  (`PurchaseCorrectionWorkflowTest`, 18 passed) is unchanged.
- `git diff --check`: no whitespace errors.

## 6. Review-finding corrections

- [x] 6.1 Derive PKP status inside the transaction from the locked document's own
  `setting_id` (`AbstractMonetaryEditService::resolveIsPkp()`); remove `is_pkp`
  and `is_tax_included` as trusted service inputs. `is_tax_included` now comes
  from the persisted document and is forced false for a non-PKP business. The
  active-setting ownership check is unchanged.
- [x] 6.2 Reconcile the Purchase payment summary from existing active payment
  rows: persist `paid_amount`, `due_amount`, and `payment_status` in the
  application's existing uppercase Purchase vocabulary (`PAID`/`PARTIAL`/
  `UNPAID`), mirroring `PurchaseCorrectionService`'s arithmetic without
  creating, editing, invalidating, or deleting any payment row. A corrected
  total below effective paid amount is still rejected.
- [x] 6.3 Expose global discount and shipping as editable header inputs on the
  restricted Sale form: hydrated from the Sale in `mount()`, kept in sync via
  the product cart's existing `globalDiscountUpdated` / `shippingUpdated`
  events, and passed to the service. Submitted values — including an explicit
  zero — are normalized and persisted; only an absent key falls back to the
  stored figure. All non-monetary locks retained.
- [x] 6.4 Boundary re-verified: no delete/recreate, header and detail rows still
  locked, stable detail-ID mapping intact, and no calls to product prices,
  stock, receipts, dispatches, bundles, serials, payment rows, historical
  replay, or cost snapshots. `git diff --check` reports no whitespace errors.

### Correction-pass test results

Focused runs only (full suite deliberately not re-run for this pass):

- `Modules/Purchase/Tests/Feature/PurchaseMonetaryEditTest.php` — **18 passed** (62 assertions)
- `Modules/Sale/Tests/Feature/SaleMonetaryEditTest.php` — **18 passed** (73 assertions)
- `Modules/Purchase/Tests/Feature/PurchaseCorrectionWorkflowTest.php` — **18 passed** (38 assertions), unchanged

New tests added in this pass: forged-PKP-context rejection (one per service),
Purchase `PARTIAL` and `PAID` reconciliation with payment rows asserted
unchanged, dispatched-Sale line price + header discount + shipping edit with
dispatch details / line IDs / cost snapshots / stock / payment rows asserted
unchanged, and explicit-zero discount clearing.

### Defects found and fixed during verification

Reconciling the full-suite run against baseline surfaced two real problems that
focused per-suite runs had hidden:

1. **`approval_status` written by the new Purchase test fixtures.** No migration
   defines that column; a stale `database/testing.sqlite` accepted it silently
   while a fresh schema rejected the insert. Removed from both Purchase tests.
2. **`resolveEditMode()` over-tightened pre-approval editing.** Gating DRAFTED /
   WAITING_APPROVAL / REJECTED on `purchases.update` / `sales.edit` was stricter
   than the behaviour it replaced (which gated only on status) and broke 59
   existing Livewire edit tests. The ordinary-permission check now applies only
   from APPROVED onward, where it is a genuine prerequisite for the
   lifecycle-specific permission; route and controller gates continue to guard
   entry for drafts.

Both new test suites also call `forgetCachedPermissions()` after granting
permissions, since Spatie's process-wide permission cache otherwise leaks a
stale map in from earlier suites during a full run.
