## Context

POS historically supported two monetary exception paths: line-scoped `PRICE_OVERRIDE`, which accepted a unit price, and cart-scoped `TOTAL_PRICE_OVERRIDE`, which proportionally redistributed a requested grand total across all rows. The cart-wide action is retired and stays retired.

The first revision of this change also retired unit-price editing. That is superseded. Normal Sales and Purchase both expose two row corrections — `Ubah Harga Satuan` and `Ubah Total Baris` — and POS must offer both, governed by POS supervisory approval.

Three problems in the current working tree drive this design:

1. **Ambiguous UI state.** The row-total action was built by relabeling the unit-price modal in place. `pos-price-override-modal`, `pos-price-override-new`, and the `js-price-edit` handler now perform a row-total operation, and the unit-price action does not render.
2. **Unsafe supervised execution.** `PosCartService::overrideLineTotal()` persists the cart, then consumes the token and writes audit. `PosApprovalTokenService::consumeToken()` issues an unconditional `update()` on a possibly stale model with no lock and no `consumed_at IS NULL` guard, and validation and consumption are separate unsynchronized reads.
3. **Divided arithmetic authority.** `PosLineTotalAllocator` computes subtotal, tax, and discount in minor units, then discards them; `PosCartTotalsCalculator` independently reconstructs different values from `line_total`. Approval source totals are computed as `qty × unit_price`, which is wrong whenever a row discount exists.

The design must also preserve bundle-parent commercial authority, source-owner tax classification, exact split-owner reconciliation, draft/hash integrity, one-time approval semantics, and historical audit readability.

## Goals / Non-Goals

**Goals:**

- Offer two unambiguous row actions matching Sales/Purchase terminology and arithmetic.
- Keep every active cart-wide total-edit entry point retired.
- Establish one canonical minor-unit arithmetic path used by every consumer.
- Make supervised execution atomic, single-use, and failure-safe under concurrency.
- Compare submitted and approved values exactly, rejecting mismatches without consumption.
- Bind approval to action type, requested value, session, line, requester, and a real line fingerprint.
- Preserve exact checkout, bundle, tax, receipt, and owner-split reconciliation.
- Preserve historical cart-total and unit-price records as read-only audit history.

**Non-Goals:**

- Changing normal Sales or Purchase row behavior.
- Adding bundle-component price or serial editing.
- Allowing users to edit a persisted grand total independently of its rows.
- Reclassifying source-owner tax, stock priority, or serial ownership rules.
- Destructively deleting historical approvals, tokens, supervisor audits, or permissions.

## Decisions

### 1. Two distinct active action types on one existing permission

Add `LINE_UNIT_PRICE_OVERRIDE` and `LINE_TOTAL_OVERRIDE`, both targeting `pos_cart_line` with an exact `line_id`. Both authorize through the existing `pos.overrides.price` permission: a holder executes directly, a non-holder requests supervisor approval. `pos.overrides.total-price` stays deprecated and absent from active capability bundles.

The ambiguous legacy `PRICE_OVERRIDE` action is not reactivated for new requests. Historical `PRICE_OVERRIDE` and `TOTAL_PRICE_OVERRIDE` rows remain readable and render read-only, but must never authorize a new operation.

Two action types rather than one shared type with a mode flag: tokens must be action-specific, and a single type would let a unit-price approval authorize a row-total change through a mutable discriminator. Distinct types make cross-action rejection a type check rather than a payload check.

One permission rather than two: both operations carry identical monetary risk — each sets one row's price authority — and splitting them would force every live role to migrate for no security gain.

### 2. Two separate mutation contracts, no shared DOM or form state

Each action gets its own request object, endpoint, service method, modal ID, form state, JavaScript handler, label, and error surface:

| Concern | Unit price | Row total |
| --- | --- | --- |
| Action type | `LINE_UNIT_PRICE_OVERRIDE` | `LINE_TOTAL_OVERRIDE` |
| Route | `POST /pos/sell/cart/lines/{lineId}/unit-price-override` | `POST /pos/sell/cart/lines/{lineId}/line-total-override` |
| Request | `StorePosCartLineUnitPriceOverrideRequest` | `StorePosCartLineTotalOverrideRequest` |
| Service | `overrideLineUnitPrice()` | `overrideLineTotal()` |
| Modal | `pos-line-unit-price-override-modal` | `pos-line-total-override-modal` |
| Permission | `pos.overrides.price` | `pos.overrides.price` |
| Persisted source | `LINE_UNIT_PRICE_OVERRIDE` | `LINE_TOTAL_OVERRIDE` |

The retired `price_override` DOM identifiers are removed rather than reassigned, so no handler can drive the wrong operation.

Server-side, neither endpoint accepts a client-provided pricing source, fingerprint, customer context, derived price, discount, tax, or total. Each accepts only line ID, one requested monetary value, an optional reason, and an optional approval token; everything else is reconstructed from the authoritative cart.

Sales and Purchase expose these two corrections as inline editable table inputs. POS keeps modals because the supervised approval lifecycle needs a reason field and an approval state machine that an inline input cannot carry. Terminology, input formatting, non-negative validation, reason handling, and arithmetic semantics mirror Sales/Purchase; only the interaction shape differs, and this deviation is deliberate.

### 3. One canonical arithmetic authority in minor units

`PosLineTotalAllocator` currently derives values that `PosCartTotalsCalculator` then contradicts. Consolidate into one shared minor-unit calculation path consumed by direct execution, supervised execution, cart snapshot, approval source/delta, draft save/load, checkout, posting, receipts, and audit. All money is computed in integer minor units; floats appear only at display boundaries.

**Unit-price override semantics.** The requested unit price becomes the authoritative gross unit price. Row gross is unit price × effective quantity/conversion. The existing row discount applies once. Tax follows existing POS inclusive-tax rules. The final row total is derived deterministically.

**Row-total override semantics.** The requested total is authoritative *after* row discount and *before* bill-level adjustments. Gross and discount are reverse-derived:

- *Fixed discount.* `gross = net + discount`. Requested net 10.000 with fixed discount 1.000 gives gross 11.000, discount 1.000, final net 10.000.
- *Percentage discount.* `gross = round(net / (1 - pct/100))`, then `discount = gross - net`. Requested net 10.000 at 10% gives gross 11.111, discount 1.111, final net exactly 10.000.

Deriving discount as `gross × pct` and subtracting would reintroduce drift; deriving it as the residual `gross - net` makes the requested net exact by construction. Percentage discounts below 0 or at/above 100 are rejected — at 100% no gross reproduces a positive net, and the current allocator's `min(100, …)` clamp silently returns a zero discount instead of failing.

#### 3.2 Explicit minor-unit contract for persisted row totals

Raw cart lines historically carried `line_total` in minor units while calculated snapshots expose it in major units. Canonical metadata removes most of that ambiguity, but a new consumer reading `line_total` off a raw line can still misread it by a factor of 100.

Active overrides therefore persist an explicit `line_total_minor`. Readers prioritise it — the totals calculator, the snapshot mapper, and draft hydration all check it before the ambiguous field — and fall back to `line_total` only for records written before the explicit field existed. Hydration treats overridden rows like packed rows in this respect, since both store minor units.

Bill-level discounts still apply after the row's authoritative net. The canonical derived metadata is persisted so display, approval, draft, checkout, posting, receipt, and audit agree rather than reconstructing contradictory values later.

#### 3.1 Persisted metadata must never outlive the row state it describes

Persisting the derived values makes them authoritative — the totals calculator consumes them instead of recalculating. That same property makes stale metadata dangerous: a set computed at quantity 2 would keep reporting its total after the row moved to quantity 3.

Every canonical field (`line_total`, `line_gross_minor`, `line_discount_minor`, `line_net_minor`, `line_tax_minor`, `line_taxable_base_minor`) is therefore cleared through one shared helper, never field-by-field — clearing only `line_total` leaves the rest trusted and the stale total survives.

When a row with an applied override changes, the two actions diverge by intent:

- **Unit-price override** stays authoritative. The cashier set a *price*, which remains meaningful at a new quantity, so the price and source are preserved and the metadata is recomputed against the new quantity, row discount, and tax context.
- **Row-total override** cannot survive. The approved total described the old row, so the line reverts to resolved standard pricing and every canonical field is removed.

This applies wherever a row's quantity, discount, tax context, or pricing source can change: line updates, customer repricing (which replaces the override with `TIER` or another standard source), serial appends that auto-increment quantity, and merges — a merge sums quantities, so the target's pre-merge metadata is invalid by construction.

Restoring a row-total override to "standard pricing" must go through one canonical resolver that preserves the line's kind. Falling back to the parent product's ordinary price is correct only for an ordinary row; for anything else it silently reprices the line:

| Line kind | Restored price | Restored source |
| --- | --- | --- |
| Bundle parent | the selected bundle's sale price | `BUNDLE` |
| Packed | re-run packed pricing for current quantity and tier | `PACKED` |
| Customer-tier | resolved tier price | `TIER` |
| Ordinary | resolved product price | `BASE` |

A bundle parent is the sharpest case: its authoritative standard price is the bundle's sale price, so restoring it to the parent product's standalone price would convert a bundle sale into an ordinary one after nothing more than a quantity change. Bundle identity, component snapshots, and informational allocations are preserved untouched through restoration.

### 4. Canonical current-value derivation for approvals

The source value must never be `qty × unit_price`, which ignores discounts. For an approval request:

- the current unit price comes from authoritative line state;
- the current final row total comes from the canonical totals calculator with current discounts, taxes, quantity, customer, and cart context.

Both are stored in minor units. A shared server-side payload builder produces approval payloads for creation *and* execution, so the two paths cannot interpret values differently. The queue delta compares like with like: unit price against unit price for `LINE_UNIT_PRICE_OVERRIDE`, final row total against final row total for `LINE_TOTAL_OVERRIDE`.

### 5. Cart mutation lock, one execution coordinator, and bounded compensation

**Storage finding.** `PosCartSessionStore` persists the cart with `session()->put()`. The cart lives in the PHP session, not the database, so it cannot enlist in a database transaction. Compensation is therefore required, not optional.

**Why a token lock is not sufficient.** `lockForUpdate()` on the token row serializes only attempts that use *that token*. It does nothing to stop a quantity change, customer change, serial assignment, line removal, discount change, another override request, or a clear/load from mutating the same session cart while an override is mid-flight. Under that model, "revert only the overridden line's fields" can still overwrite a concurrent change to that same line. Token locking alone is rejected.

#### 5.1 `PosCartMutationLock`

Introduce a `PosCartMutationLock` keyed by `setting_id` + POS `session_id`, following the existing project idiom (`Cache::lock(...)`, as used for purchase approval).

**The binding rule is mechanical, not judgemental: every operation that calls `putCart()`, `clearCart()`, or otherwise replaces or hydrates the same POS cart MUST acquire the lock.** The guard set is defined by *who writes the cart*, not by which writes seem relevant to approval validity. This matters because compensation restores an exact snapshot: any unguarded writer that lands between persistence and compensation has its write silently erased. A note update is not relevant to approval validity, yet losing it is still data loss.

Writers found by code search, all in scope:

- unit-price and row-total overrides;
- quantity changes (`addLine`, `updateLine`);
- line removal (`removeLine`);
- serial assignment (`assignSerials`, `appendSerial`, `removeSerial`);
- customer changes (`updateCustomerSelection`);
- bill discounts (`updateBillDiscount`);
- **note updates (`updateNote`)**;
- staged-payment state changes that write the cart — `updateLine` persists `staged_payment_token` as cart state;
- cart clear (`PosCartService::clear`) and checkout clear (`FinalizePosCheckoutService`);
- transaction load/hydrate and reset (`PosTransactionService`);
- the normalization write in `getSnapshot()`.

Any writer added later is bound by the same rule. The staged-payment *chain* stored under `payment_chain_{token}` is a distinct session key and not a cart writer; only writes to the cart itself are in scope.

Because every cart writer must acquire the same lock, no other writer can enter the critical section while an override is executing — including during compensation.

*Deployment constraint.* `CACHE_DRIVER` is currently `file`, whose lock is per-server rather than distributed. The invariant holds for a single application server; a multi-server POS deployment must move the lock store to a driver with distributed atomic locks (Redis or database). This is recorded as a deployment prerequisite rather than assumed away.

*Lock TTL.* The TTL must exceed the longest guarded operation. A TTL expiring mid-callback would admit a second writer while the first still believed it held exclusion — the lock would report success while no longer providing it, silently voiding compensation. The TTL is therefore sized as a crash valve only; normal release is guaranteed by `finally`.

*Re-entrance is scope-owned, never process-wide.* Re-entrance depth is tracked on the lock instance, and the lock is bound with Laravel's `scoped` lifecycle so every collaborator inside one request or job shares it — which is what lets the coordinator call into `PosCartService` without self-deadlocking. Static state is explicitly rejected: under a long-lived worker (Octane, queue workers) one execution context could observe another's held key and bypass the real cache lock, silently losing exclusion. A fresh scope starts empty and cannot inherit ownership, and the depth counter is keyed per cart so owning one cart never grants a bypass for another. The constructor defaults that previously allowed an unshared `new PosCartMutationLock()` are removed, making injection of the scoped instance mandatory.

#### 5.1a Checkout holds the lock across its whole authoritative span

Checkout reads the authoritative cart early, posts it after stock resolution, and clears it at the end. Guarding only the final clear leaves that whole read-to-clear window open.

Compare-and-set on the clear was considered and rejected as insufficient. CAS preserves the *whole* changed cart, so if a cashier appends a line to the cart being posted, the preserved cart still contains the already-posted lines — inviting a duplicate sale. Preventing that would require an additional checkout fence that rejects every cart mutation while a revision is being posted, which is strictly more machinery than simply holding the lock.

Checkout therefore holds the cart mutation lock across snapshot capture, posting, and clear. Concurrent cart mutation receives the retryable `CART_BUSY` rather than modifying the cart being posted, and the clear can be unconditional because no writer can have intervened. The earlier objection — that the span would outlive a 15-second lease — no longer applies now that the TTL is sized as a 300-second crash valve.

#### 5.1b Monotonic cart revision

The cart still carries a `revision` for staleness detection by any consumer that cannot hold the lock for its whole span. The counter lives in its own session key and survives cart clearing, because a counter stored with the cart would restart at 1 after a clear and allow an ABA match:

```text
capture revision 1 → cart cleared → new cart created at revision 1 → stale CAS deletes the new cart
```

`clearCart()` advances the generation before forgetting the cart, so a recreated cart is always stamped with a strictly higher revision and can never collide with a revision already handed out. Snapshots expose `cart_revision` read from the store rather than the in-memory array — callers build snapshots after persisting, so the in-memory copy predates the write that advanced the revision. Carts stored before this field existed read as 0 and advance normally.

#### 5.2 `PosRowOverrideExecutionCoordinator`

Direct execution has the same failure mode as supervised execution — cart persisted, audit write fails, leaving a changed cart with no required audit — so **both authorization paths and both action types use one coordinator**, not a supervised-only one.

**Direct path:** authorize → acquire cart lock → re-read cart → calculate → persist cart → write direct audit → release. On audit failure, compensate before releasing.

**Supervised path:** acquire cart lock → open database transaction → `lockForUpdate()` the token row and the approval request → revalidate token status and expiry while locked → re-read the authoritative cart inside the protected path → validate session, action type, target type, line ID, requester, requested value, and fingerprint → calculate the complete proposed mutation → persist cart → conditionally consume the token (`UPDATE … SET consumed_at = now() WHERE id = ? AND consumed_at IS NULL`, asserting exactly one affected row) → write audit → commit → release.

All validation and calculation precede cart persistence, so any failure before that point leaves cart, token, and audit untouched with nothing to compensate. If consumption or audit fails, the database transaction rolls back *and* the cart is restored, both while the lock is still held.

#### 5.3 Session persistence boundary invariants

These invariants are binding and are mirrored in the supervised-action spec and tasks:

1. The cart mutation lock remains held through compensation or successful commit — it is never released between persisting the cart and finishing the database work.
2. Compensation restores the **exact pre-operation business content** of the cart, because no competing cart mutation can enter while the lock is held. Partial "revert only these fields" restoration is not used. The monotonic generation counter is excluded from "exact": it advances on the restoring write and is never forced backward, since rewinding it would let a stale compare-and-set match a cart it never observed.
3. The token/request database transaction rolls back if consumption or audit fails.
4. The cart is restored before the mutation lock is released, and restoration is guaranteed **independently of rollback**: rollback runs in its own guarded step so that a rollback failure cannot skip restoration and strand a mutated cart.
5. Restoration failure is never silently swallowed. Restoration is retried a bounded number of times and the stored cart is verified against the original business content; if restoration cannot be confirmed, a critical structured error is logged (setting, session, action, both failures) and a distinct `CART_COMPENSATION_FAILED` operational exception is raised with the original failure attached as its root cause.
6. Because `putCart()` stamps a fresh generation, the in-memory mutated array is stale the moment it is written. The stored representation is re-read after persistence and used for the audit payload, the coordinator's return value, and post-persistence verification.
7. Lock acquisition has a bounded timeout; on timeout the operation returns a retryable POS error without changing cart, token, or audit.
8. Lock release is guaranteed with `finally`.

The conditional update in step 9 remains a second, independent guard that holds even if a caller bypasses the coordinator. `consumeToken()` therefore stops performing an unconditional stale-model update and requires a lock or a conditional update with an affected-row check.

An alternative — moving the cart into the database to get one true transaction — was rejected as far beyond this change's scope; it would touch every POS cart consumer. The lock plus bounded compensation gives the same observable guarantee for a single-server deployment.

### 6. Exact comparison of submitted and approved values

For supervised execution the submitted requested value must exactly equal the approved value in minor units: submitted unit price against `requested_unit_price_minor`, submitted row total against `requested_total_minor`. A mismatch is rejected without consuming the token.

The current code silently replaces the submitted value with the approved one. That hides client/server divergence and would apply a value the cashier did not just confirm. Rejecting surfaces the divergence and leaves the token usable for a correct retry.

Execution additionally validates the exact active action type, `target_type === pos_cart_line`, POS session ID, exact line ID, requester ID, a non-empty fingerprint, and a fingerprint match against the current authoritative line and cart context.

The product-ID fallback is removed from approved execution. Approvals resolve only the exact cart `line_id`; a product ID could resolve to an unintended row when the same product appears more than once.

### 7. One canonical fingerprint contract

Both active actions share one line-state fingerprint service, but the approval payload binds the fingerprint to action type, exact requested monetary value, POS session ID, line ID, and requester ID — so a fingerprint cannot be replayed across actions, values, sessions, lines, or users.

Customer context is built through one shared method from authoritative `selected_customer_id`, `selected_customer_tier`, and setting PKP context. `customer_group` and any client-submitted context are not used.

The fingerprint covers the real line contract: line and product ID; quantity; current unit price and price source; conversion/UOM identity and factor; tax inputs; row discount type and value; customer context; assigned serials in canonical order; bundle identity; and canonical bundle components including bundle item ID, product ID, quantity per bundle, informational price, stock-managed and serial-required classification, and component conversion/UOM fields that affect pricing or fulfillment. `ProductBundleSnapshotMapper` is reused for component canonicalization, and tests use the real bundle shape produced by `PosCartService`.

Any relevant line or cart mutation invalidates pending approvals for **both** active override actions.

### 8. Row-scoped approval state only

Cart snapshots expose `requested_unit_price` or `requested_line_total` in the target row's `pending_approvals`, keyed by both line ID and action type. The UI reuses the existing `ApprovalManager` lifecycle:

```text
idle → submit → pending/Periksa → approved/Lanjutkan → consumed
                         └──────→ rejected/cancelled
```

Keying by line *and* action prevents the known leak where a price-override approval made the quantity control show “Periksa”. No active `total_price_override_approval` cart-level state is emitted.

### 9. Checkout allocation and snapshot contracts

At checkout an overridden row's authoritative total is allocated only among fulfillment chunks derived from that row, using deterministic minor-unit rounding and stable chunk ordering. Chunk amounts sum exactly to the row total; each chunk's tax classification still comes from its source owner setting.

For a bundle, both row actions target the billable parent row. Components stay zero-price and non-billable with unchanged informational allocations and fulfillment snapshots. Packed rows remain one customer-facing row.

Canonical POS snapshots and v2 hashes include the authoritative row total, unit price, and pricing source. Finalize, payload hashing, original-cart replay, and posting consume those values instead of rebuilding them from a displayed unit price. Applying an approval is a cart mutation and produces the normal new snapshot/hash state before checkout.

### 10. Audit only successful mutations

Both direct and supervised execution record the exact active action type, session and line ID, source and requested values in minor units, source and resulting row totals where useful, reason, fingerprint, requester, direct authorizer or supervisor, execution timestamp, and successful result.

No successful-execution audit row is created or updated before the cart mutation and token consumption have both succeeded. Historical legacy actions stay visible and read-only.

## Risks / Trade-offs

- **[Cart cannot join the database transaction]** → Session-backed storage makes strict atomicity impossible. Mitigated by the cart mutation lock, a pre-operation snapshot, ordering all validation and calculation before cart persistence, exact restoration inside the lock, database rollback on consumption/audit failure, and explicit tests for cart-store and token/audit failure.
- **[Compensation races an unrelated concurrent cart mutation]** → Every relevant cart mutation acquires `PosCartMutationLock`, so no competing writer can enter while compensation runs; the exact pre-operation snapshot is restored rather than a partial field revert.
- **[File cache lock is per-server]** → The current `CACHE_DRIVER=file` lock does not serialize across application servers. Recorded as a deployment prerequisite: multi-server POS requires Redis or database cache locks.
- **[Lock contention stalls the cashier]** → Bounded acquisition timeout returning a retryable POS error, guaranteed release with `finally`, and focused tests for lock timeout and concurrent cart mutation.
- **[Two actions double the approval surface]** → One shared fingerprint service, one shared payload builder, and one canonical arithmetic path; the actions differ only in which value is authoritative.
- **[Precision drift through legacy unit-price consumers]** → Audit every cart total, payment, receipt, split planner, posting adapter, and persistence consumer; prefer authoritative persisted values and add non-divisible regression cases.
- **[Historical approval rendering breaks after action retirement]** → Keep legacy constants and read models, add read-only compatibility tests, and disable mutation paths.
- **[UI approval states leak across actions]** → Key client and snapshot state by both `line_id` and action type; retain coexistence tests.
- **[Role behavior changes when cart permission is retired]** → Reuse `pos.overrides.price` for both actions and deprecate rather than destructively delete the legacy permission.

## Migration Plan

1. Add `PosCartMutationLock` and apply it to every relevant cart mutation path before any override work depends on it.
2. Add both action types, the canonical arithmetic path, the shared payload builder, the fingerprint contract, and `PosRowOverrideExecutionCoordinator` behind the existing row interface, with focused backend tests.
3. Harden `consumeToken()` to conditional/locked consumption with an affected-row check.
3. Restore the `Ubah Harga Satuan` row action and rebuild `Ubah Total Baris` with its own modal, state, endpoint, and handler; remove the ambiguous `price_override` identifiers.
4. Update checkout, snapshot, receipt, and split-posting consumers to honor canonical persisted values.
5. Keep cart-wide UI and mutation entry points retired; the compatibility route stays non-mutating with HTTP 422 `FEATURE_RETIRED`.
6. Update the permission registry and supported role bundles: `pos.overrides.price` governs both actions, `pos.overrides.total-price` stays deprecated.
7. Preserve historical records and verify approval/report screens render them read-only.
8. Deploy without destructive data migration. Rollback restores the prior application version; historical records and legacy permission assignments remain intact.

## Open Questions

None. The product decision is explicit: POS has no cart-wide total editor, and POS supports exactly two row-scoped monetary overrides — unit price and row total — using the established POS approval lifecycle.
