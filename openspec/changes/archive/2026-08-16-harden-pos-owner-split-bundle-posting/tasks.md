## 1. Establish Ownership and Source-Order Regression Tests

- [x] 1.1 Update resolver tests to prove stock-managed non-serial quantities consume available stock in exact enabled POS location configuration order without PKP-owner reordering.
- [x] 1.2 Add resolver tests for partial fulfillment across consecutive configured locations and skipping earlier locations with no available stock.
- [x] 1.3 Add serial allocation tests proving each selected serial keeps its persisted `location_id` and derives `source_setting_id` from that location, including multiple owners in one line.
- [x] 1.4 Replace first-non-PKP non-stock tests with first-enabled-configured-location ownership tests for non-stock parents and bundle components, including the no-configured-source failure.
- [x] 1.5 Add validation coverage rejecting any planned or posted `source_setting_id` that disagrees with the source location's persisted setting.

## 2. Align Fulfillment Source Resolution

- [x] 2.1 Update `ResolvePosStockAllocationsService` to retain exact configured location order for stock-managed non-serial allocation while preserving existing per-location stock-bucket consumption.
- [x] 2.2 Ensure serial allocation uses assigned serial locations directly and emits location-derived owner settings without passing through non-serial location selection.
- [x] 2.3 Update `PosNonStockSourceResolverService` and planner callers so non-stock content uses the first enabled configured location regardless of PKP status and fails clearly when none exists.
- [x] 2.4 Centralize or add authoritative location-to-setting validation so resolver, planner, and posting contexts cannot persist mismatched ownership.

## 3. Harden Bundle Split Planning

- [x] 3.1 Update planner tax resolution so bundled revenue is taxable only in the POS transaction owner's group when that owner is PKP, while every foreign fulfillment-owner bundle allocation remains commercial non-tax.
- [x] 3.2 Preserve resolver `tax_bucket_used` independently from commercial bundle tax grouping so physical stock decrements continue to use the selected inventory bucket.
- [x] 3.3 Restore the canonical three-owner and non-PKP POS-owner fixtures, asserting owner totals, tax, source locations, stock movements, and receipt tax reconcile exactly.
- [x] 3.4 Restore bundled parent price-override checkout so fixed component allocations remain unchanged, only the parent residual changes, and unsupported discounts remain absent.
- [x] 3.5 Correct multi-location parent and component allocation slicing so each group receives only its fulfilled parent/component shares and aggregate quantities and minor units reconcile exactly.
- [x] 3.6 Add planner coverage for a component-only owner, an owner with partial parent plus complete component fulfillment, and one component quantity split across multiple locations.
- [x] 3.7 Add same-SKU regression coverage where a product appears as bundle parent, component, and standalone line without allocation leakage or duplication.

## 4. Enforce Owner-Aware Posting and Persistence

- [x] 4.1 Update owner-aware adapter selection to inspect actual planned source settings and force split posting for every cross-owner or foreign-sole-owner plan even when the legacy feature flag is disabled.
- [x] 4.2 Preserve inline posting for plans wholly owned by the POS transaction setting and add regression coverage for both routing outcomes.
- [x] 4.3 Ensure every generated Sale, owner-specific reference, dispatch, stock transaction, serial mutation, payment allocation, and `pos_checkout_sales` row uses the validated source location and its owner setting.
- [x] 4.4 Verify component-only groups persist their bundle items with zero parent quantity and without parent stock or serial movement.
- [x] 4.5 Assert each successful split key produces exactly one complete checkout-to-Sale mapping with deterministic ordering and reconciled group totals.

## 5. Prove Atomicity, Replay, and Customer Presentation

- [x] 5.1 Add an injected later-group posting failure test asserting rollback of earlier Sales, details, bundle items, dispatches, inventory transactions, serial state, payments, and checkout mappings.
- [x] 5.2 Verify the failed checkout marker is persisted only after posting rollback, the failed key cannot replay as success, and a corrected checkout can post once with a new key.
- [x] 5.3 Extend successful replay coverage to assert the complete ordered owner map and receipt result are returned without any duplicate side effects.
- [x] 5.4 Extend receipt and transaction-detail tests to show one captured-price parent with complete zero/free composition while hiding component allocations, source metadata, and zero-quantity bookkeeping parents.
- [x] 5.5 Add mixed stock/non-stock bundle coverage proving each line follows its own source rule while the customer total and composition remain unchanged.

## 6. Verification

- [x] 6.1 Run focused resolver, split-planner, bundle captured-pricing, split-posting, serial, stock-bucket, receipt, and idempotency test classes.
- [x] 6.2 Run only the focused POS feature and unit tests directly touching changed services, plus the smallest neighboring checkout/payment tests needed to verify integration boundaries.
- [x] 6.3 Run strict OpenSpec validation and confirm no Normal Sales, HPP, returns, reporting, schema, or historical-record behavior was changed outside the proposal boundary.
