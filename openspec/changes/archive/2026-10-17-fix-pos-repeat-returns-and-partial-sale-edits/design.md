# Design

## Context

See proposal.md for motivation and the three delta specs for behavior. PosReturnApprovalPreviewPlannerService currently compares a freshly rebuilt transaction-wide snapshot hash against the submitted snapshot. PosReturnSnapshotService includes mutable quantities and serial information. Sale.resolveEditMode excludes RETURNED PARTIALLY. PosReturnSerialReplacementChainResolver follows the first completed replacement found by serial identity and uses visited serial identities with a 50-iteration bound; this cannot reliably describe repeated occurrences such as A → B → A.

The existing return lifecycle reduces persisted Sale quantities for cash returns. Pending intake does not consume completed-return quantity; approval-plan persistence separately checks competing serial claims. These distinctions must survive this change.

## Goals / Non-Goals

**Goals:** Keep approval validation, execution, and receipt consumers consistent about remaining quantities and the currently held serialized occurrence. Reuse existing services, permissions, transaction boundaries, and persisted provenance.

**Non-Goals:** New repair/restoration workflows, changes to returned serial good/bad disposition, changes to replacement stock eligibility, full-return monetary editing, product or quantity editing through monetary-only mode, historical refund recalculation, or full-suite testing.

## Decisions

### 1. Validate affected execution dependencies

Keep original snapshots for audit. Replace blanket transaction-wide hash rejection with validation of the requested source identity, remaining quantity, current held serial occurrence, relevant bundle composition, and current financial basis. Unrelated returns or item edits do not invalidate approval. Requested items still fail on missing or conflicting source evidence. Shared header discounts/tax/shipping count as relevant when they change the affected return value.

Calculate availability from original entitlement and persisted return execution provenance without subtracting a completed cash correction twice from already-reduced Sale quantities. Replacement exchanges preserve one held unit rather than permanently consuming its return entitlement. Exclude the current request from competing claims. Independent pending requests may coexist; overlapping claims must be rejected before competing approval effects can commit. Use shared source row locks and revalidate in the execution transaction, not just when rendering preview. Do not introduce a transaction-level return count cap.

Alternative rejected: ignore all snapshot drift. That would also accept genuine source corruption or obsolete financial evidence.

### 2. Resolve serial occurrences in event order

Resolve from the original checkout unit/dispatch, then apply completed, non-reversed replacement events in deterministic causal order using persisted return/detail/dispatch provenance. A replacement dispatch creates a new held occurrence even when its serial identity appeared earlier. Track visited event/occurrence identities, not visited serial values. Scope events to the checkout unit and its proven replacement targets, including existing cross-owner and bundle lineage. Do not impose a business cap through a fixed hop count; detect repeated events or ambiguous lineage and refuse with an actionable message.

Claims attach to the currently held occurrence. A prior completed claim of serial A does not block a later occurrence of A, but an active claim on the same occurrence does. Multiple serialized units retain independent histories and explicit one-to-one replacement pairs. Reuse the resolver in lookup, submission, approval and existing receipt consumers where applicable so they agree on current serials.

Alternative rejected: globally follow serial-id edges. Reused inventory and A → B → A create ambiguous graphs and false cycles.

### 3. Preserve stock eligibility and disposition

Use existing replacement selection and locked execution checks for SKU, current stock status/condition, owner/location and reservations. Existing receiving decides whether returned A becomes good or bad. A is selectable again only if those existing checks permit it. No automatic change of condition is introduced.

### 4. Extend monetary-only editing to the remaining sale

Add RETURNED PARTIALLY to eligible Sale edit-mode handling and align ordinary/global routes, views, Livewire hydration and backend authorization. Keep existing sales.edit and sales.dispatched.monetary.edit requirements and setting rules. Normalize current remaining quantities and charges only; preserve row keys and protected execution facts. Fully returned zero-quantity audit rows stay preserved and cannot acquire new charges. Reconcile current paid/debt through existing payment logic without rewriting completed refunds, return settlement evidence, serials, stock or cost snapshots.

Alternative rejected: enabling full edit or regenerating detail rows, which would break dispatch and return links.

### 5. Require explicit review of affected monetary changes

If a pending return's monetary basis changes, show an actionable renewed-review state with updated values through the existing review/update flow. Opening a preview remains read-only; explicit review acceptance persists the revised pending basis and final execution revalidates it. Reject an old confirmation if its reviewed basis is stale. Preserve the original audit snapshot separately from accepted current review evidence using existing snapshot/audit facilities where possible. Unrelated item-only edits do not force review.

### 6. Focused verification only

Add cases to existing named tests and use php artisan test with explicit file paths or method filters. Target snapshot staleness, quantity guards, approval preview/execution, serial claims/lineage, SaleMonetaryEditTest, and affected receipt projections. Include the exact mixed-item report and A → B → A. Select bundle/cross-owner regressions only where shared resolver paths change. No full suite or composer test:fresh-sqlite gate.

## Risks / Trade-offs

- Mutable Sale quantities can cause double subtraction → assert successive partial returns reconcile to original entitlement and remaining Sale quantity.
- Reused serial identity can attach unrelated history → require checkout/unit and dispatch provenance and deterministic event ordering.
- Simultaneous approvals can consume the same unit → acquire shared source locks and repeat eligibility checks before writes; test stale competing submissions.
- Header adjustments can indirectly affect a pending item → compare the derived return financial basis, not only its unit price.
- Receipt consumers can retain an obsolete serial → focused projection checks after reused-serial replacements and subsequent refunds.
- Historical records may lack unambiguous provenance → preserve historical access and require actionable correction rather than invent lineage.

## Migration Plan

Prefer existing occurrence and audit references; no destructive migration or historical rewrite is planned. If persisting accepted review evidence needs schema support, use nullable additive fields and preserve original snapshots. Deploy with focused verification and exercise mixed-item repeat returns and partially returned monetary edits in staging. Roll back application changes without deleting completed execution or review audit history; any additive fields remain harmless.
