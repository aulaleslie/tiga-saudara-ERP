# Design

## Context

See `proposal.md` for motivation. POS Return final approval receives returned inventory and dispatches replacement inventory inside one transaction. Receipt already updates an owner bucket and rebuilds aggregate stock, while both POS-specific replacement dispatch helpers currently decrement only aggregate `product_stocks.quantity` and global `products.product_quantity`.

Stock ownership is determined through `locations.setting_id`. In practical current operation, a PKP owner always uses tax stock and a non-PKP owner always uses non-tax stock. POS configuration is not replacement ownership authority, cross-owner replacement is valid, products are globally shared, and the legacy `products.setting_id` column is not authoritative for this behavior.

Read-only investigation found two conclusively affected stock rows: product 182/location 6 from same-owner POS Returns 84 and 86, and product 4391/location 2 from cross-owner POS Return 77. Product 4014/location 6 is an unrelated serialized cash-return inconsistency.

## Goals / Non-Goals

**Goals:**

- Centralize replacement dispatch stock mutation so same-owner and cross-owner execution follow identical owner-bucket invariants.
- Select tax versus non-tax stock from the owner of the location where each physical movement occurs.
- Make historical repair evidence-gated, observable, concurrent-safe, and idempotent.
- Verify only the affected lifecycle and repair behavior with focused tests.

**Non-Goals:**

- Redesign POS configuration, stock ownership, product tenancy, or the legacy `products.setting_id` column.
- Change cross-owner eligibility, commercial correction behavior, serial lineage rules, bundle policy, or broken-condition handling.
- Reconcile generic stock-versus-serial discrepancies or repair product 4014.
- Replay completed returns, rewrite existing ledger rows, or run/plan the full test suite.

## Decisions

### 1. Resolve movement buckets from location ownership

The mutation service will load the relevant location and its setting under the lifecycle transaction. `settings.is_pkp = true` selects `quantity_tax`; false selects `quantity_non_tax`.

Return receipt uses the original/source location owner. Replacement dispatch uses the selected replacement location owner. Same-owner execution naturally applies both movements to the same owner; cross-owner execution applies them independently.

This is preferred over POS configuration, detail tax metadata, serial `tax_id`, `product_stocks.tax_id`, or `products.setting_id` because the agreed operational invariant is owner PKP status. Those fields remain historical or supporting metadata and are not redesigned.

### 2. Use one bucket-aware replacement dispatch mutation

Same-owner and cross-owner helpers will delegate to one stock mutation abstraction that:

1. locks the product stock row and relevant owner context;
2. selects the owner bucket;
3. validates that bucket has the dispatch quantity;
4. decrements that bucket;
5. recomputes `broken_quantity` from broken buckets and `quantity` from all four condition/tax buckets;
6. maintains global product quantity according to existing aggregate rules; and
7. creates a transaction row with the actual owner setting, location, bucket movement, and accurate previous/after balances.

Recomputation is chosen over an independent aggregate decrement so latent inconsistency cannot be promoted or hidden. Stockless details continue to bypass inventory movement.

Two invariants hold inside the mutation:

- **Ledger ownership is the location owner.** A caller-supplied setting is validated against `locations.setting_id` and rejected on mismatch, so stock can never move under one owner while the ledger names another.
- **The product row is locked before its quantity is read.** `products.product_quantity` is global, so a concurrent movement at another location would otherwise lose this update.

### 3. Keep the implementation fix atomic with existing lifecycle execution

The shared mutation remains inside the existing locked final-approval or staged-dispatch database transaction. Insufficient owner-bucket stock raises a blocking error and rolls back receipt, dispatch, serial, Sales Return, and ledger effects together. Existing lifecycle status guards provide retry idempotency; focused tests will assert no duplicate effects.

### 4. Discover repair candidates from immutable lineage, not mismatch alone

The repair service will begin from completed managed POS Return replacement lines and join their Sale Return details, generated replacement dispatch details, outbound `DISPATCH_RETURN` transactions, locations/settings, and serial lineage where present. It will reconstruct each missed owner-bucket decrement and group contributions by product/location.

A row is eligible only if the evidence is complete and current values support a deterministic correction. This excludes product 4014 and other unrelated discrepancies.

**The ledger does not mark the defect.** Faulty `DISPATCH_RETURN` rows record the intended bucket quantity (verified on production rows 17381, 17538, 16590) exactly as corrected rows do, so the ledger cannot tell a faulty dispatch from a fixed one. Lineage therefore only *bounds* the correction; its size is measured from physical truth.

**Serial counts are the anchor, not mere corroboration.** These are serialized products, so the owner's good bucket must equal the sellable serial count and the aggregate must equal those plus broken. Bucket-vs-aggregate drift alone is insufficient: a later return receipt recomputes the aggregate from the already-inflated bucket, promoting earlier drift into the aggregate and hiding it. Product 182 shows this — two missed decrements, but only one unit of bucket-vs-aggregate drift survives. A non-serialized row has no independent physical count proving the expected bucket balance, so it can never satisfy the "conclusively affected only" requirement. It is reported as ambiguous and never repaired. No secondary inference rule is provided: broadening this would require a real non-serialized affected row plus an identified authoritative balance source, neither of which exists in this change's scope.

**Correlation is exact.** Dispatches are matched to ledger rows by reconstructing the two historical reason strings verbatim plus product, location, owner setting, type and quantity, requiring exactly one match. Substring matching on a Sale Return id is unsafe (`#84` matches `#184`, `#840`) and misses cross-owner rows, which name the Sale Return reference instead.

The known IDs and expected corrections are acceptance fixtures, not unconditional update targets. This prevents the command from changing a row if its provenance or current state differs in another environment.

### 5. Separate discovery from guarded apply

The Artisan command defaults to dry-run. Its output includes classification, product/location/owner, all current buckets, expected buckets, aggregate and bucket deltas, POS Return IDs, Sale Return IDs, transaction IDs, and returned/replacement serials.

Apply re-runs discovery inside the operation, locks each candidate stock row, and compares a stable plan fingerprint or equivalent exact preconditions. A mismatch produces a conflict result and no mutation. Per-row transactions limit lock duration and allow unaffected candidates to be reported independently.

### 6. Add corrective evidence without rewriting history

Existing faulty transactions, completed returns, dispatches, and serial histories remain untouched. Apply creates a dedicated corrective transaction/audit record, using a stable repair key based on the defect version and contributing faulty transaction IDs. A unique persisted identity or equivalent deterministic existence check prevents duplicate application.

The corrective record captures signed aggregate and bucket deltas plus before/after location and global balances.

**No schema expansion.** The corrective `transactions` row carries the repair identity in its `reason`, prefixed `REPAIR:<defect-version>:` followed by the repair keys of every faulty transaction it settles. A second apply re-reads those reasons for the product/location and skips contributions already named, so idempotency needs no repair table.

### 7. Focus verification on the regression surface

Verification covers non-tax and tax serialized replacement lifecycles, same-owner and cross-owner owner/bucket movement, the return → replacement → resale → second return → second replacement sequence, ledger balances after each stage, repair dry-run/apply, changed-data conflict, exclusion of unrelated discrepancies, and second-run idempotency. No full-suite execution is planned.

## Risks / Trade-offs

- **[Legacy rows may contain incomplete lineage]** → Classify them as ambiguous and leave them unchanged rather than infer a correction.
- **[A bucket may already have been manually adjusted]** → Require exact locked preconditions and corroborating evidence; abort on mismatch.
- **[Recomputing aggregate can expose prior unrelated drift]** → Apply recomputation during new replacement mutation after bucket validation; historical repair remains restricted to proven contributions.
- **[Transaction bucket fields have historical sign conventions]** → Define and test the corrective record semantics explicitly while preserving existing records.
- **[Short row locks can briefly contend with sales]** → Use per-row transactions and perform read-only discovery before acquiring mutation locks.

## Migration Plan

1. Deploy the implementation fix and focused tests before allowing further replacement approvals.
2. Run the repair command in dry-run mode and confirm it identifies only product 182/location 6 and product 4391/location 2 in the investigated dataset.
3. Apply the repair during a normal low-activity window; changed candidates are skipped or aborted.
4. Re-run dry-run and the two diagnostic stock queries to confirm no eligible defect-linked drift remains.
5. Rollback of code restores the previous execution path but does not reverse applied repairs; corrective audit records provide the evidence for any deliberate manual reversal.
