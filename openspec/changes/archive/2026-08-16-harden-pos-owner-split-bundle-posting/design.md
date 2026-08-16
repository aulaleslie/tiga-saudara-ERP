## Context

POS checkout has two distinct ownership concepts that the current implementation partially conflates. The current setting of the active POS session owns the customer transaction, receipt, payment, captured bundle price, and tax policy. Physical fulfillment may come from configured locations owned by other settings, and each generated Split Sale must belong to the setting that owns its actual source location.

The existing resolver, planner, posting adapters, and finalize transaction already provide most of the required structure. However, historical rules introduced non-PKP owner priority for stock allocation and stockless components, bundle tax is still re-derived from source-owner PKP state in parts of the planner, and adapter selection can collapse a stock-only multi-owner checkout when the feature flag is disabled. Focused tests also currently expose foreign bundle tax, price-override, and multi-source grouping regressions.

## Goals / Non-Goals

**Goals:**

- Establish one authoritative and testable mapping from POS transaction owner, fulfillment source, and Split Sale owner.
- Allocate non-serial stock in exact enabled POS location order while preserving availability, stock-bucket, and setting ownership evidence.
- Preserve selected serial location as the authoritative serial source.
- Give non-stock content deterministic ownership through the first enabled configured location.
- Preserve captured bundle allocations, parent residuals, component identities, and customer presentation across every owner group.
- Apply bundle tax from POS-owner policy without changing physical stock-bucket selection.
- Make multi-owner routing, reconciliation, rollback, and replay safe regardless of the legacy feature flag.

**Non-Goals:**

- Changing Normal Sales ownership or dispatch rules.
- Redesigning POS location administration, bundle authoring/lifecycle, payments, HPP, returns, or reports.
- Adding recursive bundle expansion or new bundle-component serial selection behavior.
- Changing database schema or rewriting historical transactions.

## Decisions

### 1. Separate transaction ownership from fulfillment ownership

The current setting of the active POS session is the immutable `terminal_setting_id` and POS transaction owner. Every fulfillment chunk carries an authoritative `source_location_id`; `source_setting_id` is derived from that location and must agree with `locations.setting_id` before posting.

This preserves one customer transaction while allowing owner-specific Sales, references, dispatches, stock movements, and HPP lookup contexts. Accepting a caller-provided source setting without validating the location relationship is rejected because it permits ownership misattribution.

### 2. Use exact configured location order for non-serial stock

Enabled `setting_sale_locations` entries are consumed by `position`, with existing deterministic tie breakers. At each location, the resolver consumes available stock using the existing stock-bucket rules before moving to the next location. It does not reorder locations by source-owner PKP status.

This makes configuration order the business-controlled priority. The previous non-PKP-first alternative is rejected because tax ownership is now explicitly separated from physical fulfillment ownership.

### 3. Use selected serial location directly

For serial-tracked lines, each selected serial's persisted `location_id` determines its fulfillment group, and that location's setting determines Split Sale ownership. Validation still requires the serial to belong to the product, be available, and reside in an enabled POS source location.

Reallocating a selected serial according to general location order is rejected because serial identity already fixes the physical stock source.

### 4. Assign non-stock content to the first configured location

Non-stock parents and components have no availability evidence. They are therefore assigned to the first enabled configured POS location, and the owning setting of that location owns their Split Sale. No PKP filtering or fallback to the terminal setting is performed; an empty location configuration fails validation.

This supersedes the historical first-non-PKP-source rule. Tax is handled separately, so using ownership selection to force a tax result is no longer appropriate.

### 5. Route from the actual plan, not only the feature flag

The owner-aware adapter evaluates the authoritative plan whenever necessary and uses split posting if the plan contains more than one source setting or a sole source setting different from the terminal setting. The legacy flag may force split posting but may not authorize collapsing a genuinely cross-owner plan into a terminal-owned Sale.

Inline posting remains valid for a plan wholly owned by the terminal setting. This minimizes behavioral change for single-owner checkouts.

### 6. Keep revenue allocation and physical fulfillment independent

Component amounts come only from the POS owner's captured bundle snapshot. The planner calculates:

```text
parent residual = captured bundled row amount - sum(fixed component allocations)
```

It then distributes parent residual and component allocations to the locations that fulfill their corresponding quantities, using minor-unit arithmetic. A manual row-price override changes only the parent residual. Negative residuals fail before any posting side effect.

Each grouped line receives only its parent and child allocation slices. A component-only group receives a zero-quantity, non-moving bookkeeping parent context; allocations are never copied wholesale between groups.

### 7. Separate commercial bundle tax from stock buckets

For bundled revenue, only the allocation posted to the POS transaction owner's Sale is taxable when that owner is PKP. Every foreign source-owner allocation is commercial non-tax even if its owner is PKP or its physical stock came from `quantity_tax`. When the POS owner is non-PKP, all bundle allocations are commercial non-tax.

The physical `tax_bucket_used` flag remains unchanged and continues to control which stock quantity bucket is decremented. Commercial tax grouping must not rewrite that inventory decision.

Non-bundle tax behavior is left unchanged by this proposal.

### 8. Treat all groups as one atomic posting result

Planning and validation complete before group side effects. Final posting remains inside one database transaction; an exception from any group rolls back Sales, details, bundle items, dispatches, inventory transactions, serial mutations, payments, and checkout mappings from all groups. The checkout failure marker is recorded separately after rollback.

Successful replay returns the stored ordered `pos_checkout_sales` map and receipt result without invoking posting again. A failed key remains non-replayable; a corrected attempt uses a new key.

### 9. Reconstruct receipts from customer transaction truth

Receipts retain the original POS transaction line: full captured price on the parent and zero/free component presentation. Persisted split groups provide historical composition, but owner allocations, component prices, zero-quantity bookkeeping parents, and source metadata remain internal. Aggregation uses stable line identity so identical SKUs in parent, component, and standalone roles cannot leak into each other.

## Risks / Trade-offs

- [Changing allocation order moves fulfillment between businesses for previously ambiguous carts] → Make exact configured order executable in resolver tests and document the superseded non-PKP-priority rule.
- [Commercial tax and physical tax-stock buckets can differ] → Preserve separate fields and assert both tax persistence and inventory decrement behavior in the same tests.
- [Planner grouping can merge or duplicate identical products] → Key parent and child allocations by stable cart-line role and component index/identity, then test same-SKU mixed roles.
- [An exception after an early group posts could leave partial data] → Add an injected later-group failure test asserting rollback across every affected table and serial/stock state.
- [Feature-flag deployments may observe newly enforced split routing] → Limit forced routing to plans that would otherwise misattribute ownership; retain inline posting for terminal-owned plans.
- [Historical malformed split data cannot always be reconstructed unambiguously] → Do not rewrite history; preserve current best-effort receipt fallback outside the posting changes.

## Migration Plan

1. Update resolver and non-stock source rules with focused unit tests.
2. Align planner ownership, bundle tax, and allocation isolation.
3. Harden adapter selection and atomic failure coverage.
4. Restore the canonical allocation, override, multi-source, receipt, and replay feature tests.
5. Run focused POS suites, followed by the project's fresh SQLite test command.

No data migration or backfill is required. Rollback is a code rollback; already-posted checkout mappings remain historical records and are not reinterpreted.

## Open Questions

None. The exploration resolved transaction ownership, source ordering, serial sourcing, non-stock ownership, tax routing, Normal Sales exclusion, and retry semantics.
