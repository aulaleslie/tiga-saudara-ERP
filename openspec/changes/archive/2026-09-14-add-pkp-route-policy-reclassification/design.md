## Context

Deliveries 4–6 provide immutable movement attempts, approved forward dispatch, blind forward receipt, serialized transit custody, and atomic destination application for workflow version `2`. Activation currently excludes PKP-involved routes because receipt preserves source tax buckets and always completes the transfer. Delivery 7 must make the route decision immutable, classify received stock for the destination business, and create durable full-return obligations without exposing the later return-dispatch/receipt surfaces.

Products are canonical transfer identities even across businesses; inventory ownership is represented by location/setting-scoped stock and transaction rows. Existing data structures can hold both tax and non-tax buckets, but current operational behavior treats PKP business stock as taxed and non-PKP business stock as non-taxed. This change enforces that operational invariant at the receipt boundary without refactoring the general inventory schema.

## Goals / Non-Goals

**Goals:**

- Freeze route and tax decisions at approval so later setting changes cannot reinterpret a transfer.
- Enable all five agreed route classes prospectively on workflow version `2`.
- Reclassify exact approved forward receipts into destination-compatible good/broken buckets.
- preserve source, destination-before, destination-after, and serialized tax provenance immutably.
- Create exact full-product return obligations and project mandatory routes to `AWAITING_RETURN` atomically.
- Keep browser projections tenant- and stock-visibility-safe.

**Non-Goals:**

- Return-dispatch preparation, scanning, comparison, approval, or stock deduction.
- Blind return receipt, obligation fulfillment, or final mandatory-route completion.
- Partial obligation acceptance in the first return workflow.
- Historical transfer backfill or reinterpretation.
- Refactoring tax/non-tax columns into a new inventory model.

## Decisions

### Persist one immutable route-policy snapshot per approved transfer revision

Store a normalized snapshot bound to the transfer and approved revision, containing origin/destination location and setting IDs, both PKP flags, same-business flag, mandatory-return flag, condition, destination classification (`TAX`, `NON_TAX`, or `PRESERVE`), resolved destination tax identity/name/rate where applicable, resolver provenance, and approval actor/time. Approval creates the snapshot within the same transaction as the `APPROVED` transition and rejects conflicting replay.

Alternative: evaluate `settings.is_pkp` at receipt time. Rejected because mutable business configuration would change in-progress obligations and historical meaning.

### Resolve tax identity deterministically at approval

For a taxable destination, use its explicitly configured default applicable tax. If none exists, choose the first applicable active destination tax using a stable primary-key ordering. Snapshot both identity and descriptive values. Approval fails if a PKP destination has no applicable tax; it does not defer an ambiguous decision to receipt. Non-PKP classification snapshots no tax identity. Same-business `PRESERVE` does not require a destination fallback because source provenance remains authoritative.

Alternative: resolve tax at receipt. Rejected because tax configuration drift between approval and receipt would alter the approved route contract.

### Reclassify only at exact forward-receipt approval

Dispatch continues preserving immutable origin bucket provenance. Receipt comparison remains about product, total base quantity, condition, and exact serial identity. Once comparison and locked invariants pass, receipt application maps the total per line into exactly one destination-compatible bucket:

| Route classification | Good transfer | Broken transfer |
|---|---|---|
| `PRESERVE` | source good tax/non-tax buckets | source broken tax/non-tax buckets |
| `TAX` | all quantity to good tax | all quantity to broken tax |
| `NON_TAX` | all quantity to good non-tax | all quantity to broken non-tax |

The receipt line records source allocation plus applied destination allocation and before/after snapshots. Serialized receipt updates live `tax_id` to the snapshot tax ID for `TAX`, to `null` for `NON_TAX`, or preserves it for `PRESERVE`; immutable serial history records old and new classification.

Alternative: rewrite dispatch provenance. Rejected because dispatch facts describe what left the origin and must remain immutable.

### Create product-level full-return obligations at receipt approval

For `mandatory_return = true`, create one obligation per exact received product and condition for the full approved receipt base quantity. Tax buckets and forward serial identities are provenance, not obligation identity: return dispatch must return the same product and condition in full, while Delivery 8 may select eligible substitute serials. Unique transfer/product/condition identity and receipt-movement lineage make creation idempotent.

No-return routes create no obligation and become `COMPLETED`. Mandatory routes become `AWAITING_RETURN`. Delivery 7 exposes obligation state for audit/domain consumption but provides no operational mutation surface.

Alternative: obligate only received taxed quantities or original serial IDs. Rejected because it conflicts with the confirmed full-quantity PKP route policy and substitute-serial return rule.

### Integrate policy into the existing locked approval boundaries

Transfer approval locks participating locations/settings and tax configuration before snapshot creation and workflow-version resolution. Forward receipt approval locks the transfer, policy, dispatch/receipt documents, obligation identity, stocks, products, serials, custody, and transaction provenance in stable order. Reclassification, serial history, obligation creation, receipt approval, and header projection commit together and share existing action-scoped idempotency.

### Apply prospectively without legacy backfill

Only transfers approved after activation receive the new snapshot and PKP-capable workflow version `2`. Existing records retain their stored workflow version and behavior. This is deterministic compatibility without data migration because stock transfer has no operational historical transaction population.

## Risks / Trade-offs

- [Default tax configuration is missing or ambiguous] → Resolve and validate at approval, use stable fallback ordering, and block approval when no applicable PKP tax exists.
- [Settings or tax configuration changes during approval] → Lock authoritative rows and persist descriptive snapshots rather than relying on later joins.
- [Receipt partially applies before obligation creation fails] → Perform classification, inventory, serial, obligation, history, and header effects in one transaction with rollback tests.
- [Old taxed-only specifications conflict] → Explicitly modify the existing cross-tenant tax-return requirements in this change.
- [Return screens accidentally become active] → Add no Delivery 8/9 routes and keep return movement entry operationally dormant.
- [Sensitive stock or policy detail leaks] → Continue using permission-aware server projections; route-policy and obligation quantities follow stock-visibility rules.

## Migration Plan

1. Add nullable/additive route-policy snapshot and return-obligation tables plus receipt provenance fields and indexes.
2. Deploy model relationships and policy resolution while the existing activation gate remains safe.
3. Integrate snapshot creation into new transfer approval and enable PKP-involved workflow version `2` prospectively.
4. Integrate reclassification and obligation creation into the locked forward-receipt executor.
5. Run focused migration, five-route, tax-resolution, inventory, serial, authorization, idempotency, concurrency, and rollback verification before enabling the gate.

Rollback disables new activation and code paths while retaining additive snapshot/obligation audit rows. Approved transfers that already carry a policy snapshot must continue using it; rollback must not reinterpret them through live settings.

## Open Questions

None. Return-dispatch serial substitution details are intentionally deferred to Delivery 8.
