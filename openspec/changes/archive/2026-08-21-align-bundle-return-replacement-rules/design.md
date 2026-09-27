## Context

The current POS Return flow models cash return and product replacement through shared line resolution, approval planning, Sales Return persistence, and lifecycle execution. Existing bundle rules require a parent line for every component action and deliberately exclude component effects from product replacement. Existing ordinary non-serial replacement receives returned stock and dispatches replacement stock automatically.

Business policy draws a different boundary. Refundability follows the customer-facing bundle: a refund must cover complete bundle units. Replaceability follows the physical product: either a parent or component may be replaced independently. Automated physical effects are justified only when returned and replacement serial identities provide auditable evidence. A non-serial replacement is documentation only; staff handles any exchange, breakage, or color substitution outside automated inventory.

## Goals / Non-Goals

**Goals:**

- Enforce complete-composition cash refunds for bundle quantities.
- Allow independent parent and component replacement.
- Execute receiving, outgoing dispatch, inventory transactions, serial lineage, and replacement HPP only for serial-tracked replacements.
- Persist non-serial replacement intent and approval as an auditable note without stock or financial mutation.
- Preserve cross-owner source lineage, atomic approval, exclusivity, and idempotency.

**Non-Goals:**

- Adding a quarantine, inspection, color-variant, warranty, or breakage workflow.
- Preventing a returned serial from appearing saleable after receiving; physical quarantine is an operator responsibility.
- Automatically moving stock for any non-serial replacement.
- Refunding individual bundle parents or components.
- Changing original Sale revenue, bundle allocation, tax, payment, or receipt.
- Running broad module or repository test suites.

## Decisions

### 1. Separate refund completeness from replacement eligibility

Draft validation and approval planning will classify resolution intent before applying bundle guards. `cash_return` on bundled merchandise requires a selected parent bundle quantity and all originally fulfilled components for that quantity. An independent parent or component `product_replacement` is valid and does not synthesize actions for the rest of the bundle.

Keeping one parent-required guard for both resolutions was rejected because it confuses customer refund scope with physical warranty replacement.

### 2. Use persisted transaction lineage, never the live bundle

Whole-bundle refund expansion and component replacement identity use the POS transaction snapshot, `SaleDetail`, `SaleBundleItem`, original `DispatchDetail`, serial assignments, and owner/location lineage. Current bundle composition and prices are not authoritative after sale.

For repeated components or the same SKU sold standalone and bundled, exact sale-bundle-item/dispatch/line-group identity must disambiguate the selected physical origin. Ambiguity blocks execution before mutation.

### 3. Serial-tracked replacement is a real physical lifecycle

A serial replacement requires one eligible returned serial and one available replacement serial of the same product for each selected unit. Approval receives the returned serial into its original owner/location as active stock, preserves returned lineage, dispatches the replacement serial using the existing owner-aware replacement rules, and links both serials. Cross-owner replacement continues using its existing explicit commercial/dispatch path where applicable.

Returned serials become immediately active/saleable in system state. No quarantine state is added; staff physically isolates defective units and may later record breakage through the existing process.

### 4. Non-serial replacement is note-only for every product

For ordinary products, bundle parents, and bundle components without serial tracking, approval persists the replacement note, selected product/origin, quantity, reason, actors, and lifecycle audit but creates no receiving detail that changes stock, no replacement dispatch, no inventory transaction, and no cost reversal or outgoing replacement HPP.

Attempting to approximate physical movement by quantity was rejected because color-only exchanges and manual breakage cannot be proven from indistinguishable units.

### 5. Replacement never changes original commercials

Neither serial nor non-serial replacement reduces Sale/SaleDetail/SaleBundleItem quantity or subtotal, creates a refund payment, changes tax, or alters original customer payment. Serial replacement HPP reflects real returned and outgoing physical effects; note-only replacement has no HPP effect because the system records no physical movement.

### 6. Approval preview must disclose execution mode

Preview rows identify whether approval will perform `serial_inventory_replacement` or `non_serial_note_only`, including returned/replacement serial and source/replacement owner/location when applicable. Note-only preview explicitly states that staff must handle physical exchange and breakage separately.

## Risks / Trade-offs

- [Returned defective serial becomes system-saleable immediately] → Make this approved behavior explicit in UI/audit notes and rely on physical quarantine plus later breakage.
- [Operators may expect non-serial stock to move automatically] → Label note-only execution clearly in draft, preview, completion, and deployment guidance.
- [Component identity can be ambiguous in historical data] → Require exact persisted lineage and block before mutation when it cannot be proven.
- [Existing replacement code may create generic Sale Return details before branching] → Ensure note-only persistence cannot trigger downstream stock, dispatch, HPP, or commercial effects.
- [Mixed resolutions in one document can leak effects] → Plan per line, retain one transaction boundary, and test mixed serial replacement plus whole-bundle cash return.
- [Duplicate replacement of an original serial] → Reuse active-return serial claim and idempotency guards for parent and component origins.

## Migration Plan

1. Update draft eligibility and presentation to express the new resolution matrix.
2. Update preview planning/persistence with explicit execution modes and exact component lineage.
3. Branch lifecycle execution into serial physical replacement and non-serial note-only completion.
4. Run focused draft, preview, approval, bundle serial lineage, replacement HPP, cross-owner, atomicity, and idempotency tests.
5. Deploy without a feature flag; brief operators that non-serial replacement stock handling is now manual.

Rollback can restore prior application behavior without schema rollback if no schema addition is required. Notes created under the new policy remain historical audit records and must not be converted into stock movements during rollback.

## Open Questions

None. Immediate serial saleability and external physical quarantine are accepted business decisions.
