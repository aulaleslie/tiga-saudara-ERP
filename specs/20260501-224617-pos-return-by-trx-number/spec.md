# Feature Specification: POS Return by Transaction Number

**Feature Branch**: `20260501-224617-pos-return-by-trx-number`  
**Created**: 2026-05-01  
**Status**: Draft  
**Input**: User description: "as an eligible user by permission I want to be able to perform return by POS trx number. There must be approval flow. The return options are return cash and product replacement. Since POS transaction splits the sales into ownership, the transaction reversal should be aligned to the sales created by POS transaction. Currently sales return is done by modifying the dispatch quantity; we will follow that. For returned product, reduce dispatch quantity based on returned quantity for the product. For POS sales with bundle, all items included in the bundle should be returned as well; cannot parent only. There should be permission for user who can create, edit, delete, view, approve, receive, dispatch product return. User initiates return POS by entering the POS trx number and then the snapshot of that POS transaction will be populated. For the UI, ensure consistency with existing sales return."

## Clarifications

### Session 2026-05-01

- Q: How should one POS transaction return be represented when the POS transaction generated multiple owner-aligned sales? → A: One POS Return header for the POS transaction, linked to owner/sale-aligned Sales Return records or lines that reuse the existing Sales Return lifecycle.
- Q: For the cash return option, how should settlement work when the original POS transaction used split or staged payments? → A: Record a manual cash refund settlement after approval and receiving, capped by returned item amounts and owner/sale mapping.
- Q: What edit/delete path should be allowed after a POS return has been submitted for approval? → A: Edit/delete is allowed before approval; after approval only reject before receiving or archive/cancel through an audited reversal path.
- Q: For product replacement, where must replacement stock be sourced from when the original POS sale was owner/location split? → A: Replacement dispatch must use the same owner and location as the original sale line; other sources require a separate transfer/override first.
- Q: Which permission namespace should gate POS return actions? → A: Use POS-specific permissions `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, and `pos.returns.dispatch`; Super Admin bypass follows existing POS conventions.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Start POS Return from Transaction Number (Priority: P1)

As a permitted sales-return user, I want to enter a POS transaction number and see the posted transaction snapshot, so I can create a return from the exact POS sale without manually searching individual sales documents.

**Why this priority**: This is the entry point for the whole workflow and prevents returns from being created against the wrong POS sale, owner, or dispatch record.

**Independent Test**: Can be tested by entering a completed POS transaction number and confirming the populated snapshot contains the transaction header, customer, receipt/transaction references, payment summary, generated sales, owner groups, dispatch status, and returnable product lines.

**Acceptance Scenarios**:

1. **Given** a user has `pos.returns.create` permission, **When** they enter a valid completed POS transaction number, **Then** the system shows a return snapshot for that transaction and its generated sales.
2. **Given** a POS transaction generated multiple sales through ownership split, **When** the snapshot is populated, **Then** each generated sale and ownership group is visible enough for the user to understand which original sale will be reversed.
3. **Given** a user enters an unknown, unposted, cancelled, or out-of-scope POS transaction number, **When** they request the snapshot, **Then** the system refuses to start the return and shows a clear correction message.

---

### User Story 2 - Submit Return for Cash or Product Replacement (Priority: P2)

As a permitted sales-return user, I want to select returned quantities and choose either cash return or product replacement, so the business can process the customer request through the supported return options.

**Why this priority**: The selected option determines the downstream settlement/dispatch path and must be captured before approval.

**Independent Test**: Can be tested by creating POS returns for normal product lines and bundled product lines, then confirming the submitted return records contain the selected quantities, return option, original sales references, and required bundle component details.

**Acceptance Scenarios**:

1. **Given** the snapshot contains returnable non-bundle product lines, **When** the user selects returned quantities and chooses cash return, **Then** the submitted return records the returned quantities and requested cash return option.
2. **Given** the snapshot contains returnable non-bundle product lines, **When** the user selects returned quantities and chooses product replacement, **Then** the submitted return records the returned quantities and requested replacement option.
3. **Given** the snapshot contains a bundled POS sale line, **When** the user returns the bundle, **Then** the return includes every product included in the bundle for the returned bundle quantity and does not allow parent-only return.

---

### User Story 3 - Approve, Receive, and Dispatch POS Returns (Priority: P3)

As a return approver or fulfillment user with the right permission, I want POS returns to move through approval, receiving, and replacement dispatch controls, so returned inventory and customer-facing replacement actions are authorized and traceable.

**Why this priority**: Approval and fulfillment controls protect inventory, cash, and replacement stock from unauthorized changes.

**Independent Test**: Can be tested by submitting a return, approving or rejecting it, receiving returned goods after approval, and dispatching replacement products only when the return option requires replacement.

**Acceptance Scenarios**:

1. **Given** a submitted POS return is pending approval, **When** an authorized approver approves it, **Then** the return becomes ready for receiving.
2. **Given** a submitted POS return is pending approval, **When** an authorized approver rejects it, **Then** the return cannot be received or dispatched unless it is resubmitted through an allowed path.
3. **Given** an approved POS return has been received and includes product replacement, **When** an authorized dispatch user dispatches replacement goods, **Then** the replacement dispatch is recorded against the return.

---

### User Story 4 - Reverse Split POS Sales by Original Ownership (Priority: P4)

As finance and inventory stakeholders, we need POS returns to reverse the correct generated sales and dispatch quantities for each ownership group, so split POS sales remain accurate after returns.

**Why this priority**: POS checkout can create multiple sales from one transaction. Returning against only one document or wrong owner would corrupt sales, dispatch, inventory, and reporting data.

**Independent Test**: Can be tested with a POS transaction that created multiple owner-aligned sales, then returning items from each group and confirming the return plan aligns every returned item to its original sale and dispatch quantity.

**Acceptance Scenarios**:

1. **Given** a POS transaction created sales for multiple owners, **When** a return is submitted, **Then** each returned quantity is aligned to the original sale and owner group created by that POS transaction.
2. **Given** a returned product has an original dispatch quantity, **When** the return is received, **Then** the effective dispatched quantity for that original dispatched product is reduced by the returned quantity.
3. **Given** a returned quantity exceeds the still-returnable dispatched quantity, **When** the return is submitted or approved, **Then** the system blocks the return and explains the quantity limit.

### Edge Cases

- POS transaction number is valid but the transaction is not completed/posted.
- POS transaction number belongs to another setting or ownership scope that the user cannot view.
- POS transaction has already been fully returned.
- POS transaction has multiple partial returns, and cumulative returned quantity approaches the original dispatched quantity.
- POS transaction includes bundled products with standalone component rows or split ownership across components.
- POS transaction includes serial-tracked items that require serial-level return identification.
- Replacement product is unavailable, out of stock, or cannot be sourced from the same owner and location as the original sale line without a separate transfer/override first.
- User loses permission between snapshot population and return submission or approval.
- User attempts to edit/delete after approval or receiving, or to archive/cancel through an unaudited path.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow users with `pos.returns.create` permission to initiate a return by entering a POS transaction number.
- **FR-002**: The system MUST resolve the entered POS transaction number against completed POS transaction and receipt identifiers, and MUST only allow returns for posted POS transactions within the user's permitted data scope.
- **FR-003**: The system MUST populate an immutable source snapshot after a valid POS transaction number is entered, including transaction header, customer, cashier/session context, receipt/transaction reference, payment summary, generated sales, owner groups, dispatch records, product lines, bundle composition, and returnable quantities.
- **FR-004**: The system MUST prevent users from submitting a POS return from a stale or manually altered source snapshot.
- **FR-005**: The system MUST provide exactly two POS return options for this feature: cash return and product replacement.
- **FR-006**: The system MUST allow returned quantities to be entered only up to the still-returnable dispatched quantity for each product or bundle component.
- **FR-007**: The system MUST align each returned product quantity to the original sale created by the POS transaction, preserving the original owner, location, tax context, and dispatch relationship from that sale.
- **FR-008**: The system MUST reduce the effective dispatched quantity for returned products by the received return quantity, following the existing sales-return business rule that returns are represented through dispatch-quantity adjustment.
- **FR-009**: The system MUST prevent a bundled POS sale from being returned as parent-only. Returning a bundle MUST include every product included in the bundle for the returned bundle quantity.
- **FR-010**: The system MUST calculate bundle component returned quantities from the returned bundle quantity and the original bundle composition.
- **FR-011**: The system MUST support partial returns for non-bundled products when the selected returned quantity does not exceed the still-returnable dispatched quantity.
- **FR-012**: The system MUST support partial returns for bundled products only when all included bundle components are returned proportionally for the selected bundle quantity.
- **FR-013**: The system MUST provide an approval flow before returned goods can be received or replacement goods can be dispatched.
- **FR-014**: The system MUST allow authorized users to approve or reject submitted POS returns and record the actor, timestamp, and rejection reason when applicable.
- **FR-015**: The system MUST allow authorized users to receive returned products only after approval.
- **FR-016**: The system MUST allow authorized users to dispatch replacement products only for POS returns whose selected option is product replacement and whose lifecycle state permits dispatch.
- **FR-016a**: The system MUST source replacement dispatch from the same owner and location as the original sale line; replacement from another owner or location MUST require a separate transfer or override path before dispatch.
- **FR-017**: The system MUST prevent cash-return settlement from being processed for returns whose selected option is product replacement, and prevent replacement dispatch for returns whose selected option is cash return.
- **FR-017a**: The system MUST process cash return settlement as a manual cash refund only after approval and receiving, capped by the returned item amount and allocated to the original owner/sale-aligned return lines.
- **FR-018**: The system MUST define and enforce POS-specific permissions for `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, and `pos.returns.dispatch`; Super Admin bypass MUST follow existing POS authorization conventions.
- **FR-019**: The system MUST allow POS returns to be edited or deleted only before approval; after approval, users MUST only reject before receiving or archive/cancel through an audited reversal path, and receiving MUST permanently block direct edit/delete actions.
- **FR-020**: The system MUST show POS return screens using the same information hierarchy, action placement, status labels, and operational conventions as the existing Sales Return flow.
- **FR-021**: The system MUST present clear user-facing messages for unknown transaction numbers, unauthorized transactions, non-posted transactions, non-returnable quantities, invalid bundle selections, and invalid lifecycle actions.
- **FR-022**: The system MUST keep an audit trail for create, edit, delete/archive, approve, reject, receive, cash-return settlement, and replacement dispatch actions.
- **FR-023**: The system MUST prevent duplicate or cumulative returns from exceeding the original returnable dispatched quantity for each original sale product or bundle component.
- **FR-024**: The system MUST preserve the relationship between the POS return, source POS transaction, source POS checkout, generated sale documents, sale detail lines, dispatch details, and bundle component rows.
- **FR-025**: The system MUST represent one POS transaction return as a single POS Return header linked to owner/sale-aligned Sales Return records or lines, reusing the existing Sales Return lifecycle for approval, receiving, settlement, and dispatch behavior.

### Key Entities

- **POS Transaction Number**: The user-entered identifier for locating a completed POS transaction. It resolves to the posted POS checkout and completed transaction record, including visible receipt or transaction references.
- **POS Return**: A return request initiated from one POS transaction. It is the single parent header for that POS transaction return, contains lifecycle status, approval state, selected return option, source snapshot reference, permissions/audit actors, and generated return reference, and links to the owner/sale-aligned Sales Return records or lines used to execute the existing Sales Return lifecycle.
- **POS Return Line**: A returned product quantity tied to an original sale line, dispatch detail, owner group, and optional bundle context.
- **POS Return Bundle Group**: A group of return lines representing one returned bundle quantity and every included component required by the original bundle composition.
- **Return Option**: The requested handling method for the return. Supported values are cash return and product replacement.
- **Ownership-Aligned Reversal**: The mapping that ensures each returned quantity reverses the sale and dispatch quantity created for the same original POS ownership group.
- **Source Snapshot**: The read-only transaction snapshot populated from the entered POS transaction number and used to validate submission.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In UAT, 100% of valid posted POS transaction numbers entered by authorized users populate a source snapshot without requiring manual sale lookup.
- **SC-002**: In UAT, 100% of invalid, unposted, unauthorized, or fully returned POS transaction numbers are blocked with a clear user-facing message.
- **SC-003**: In a test set of at least 20 POS returns covering normal products, bundles, and split-owner transactions, 100% of returned quantities are mapped to the correct original sale and ownership group.
- **SC-004**: In a test set of bundled POS returns, 100% of accepted bundle returns include all required bundle component lines for the returned bundle quantity.
- **SC-005**: In a permission matrix review, each POS product-return action is allowed only to users with the matching `pos.returns.*` permission or existing Super Admin bypass in 100% of tested cases.
- **SC-006**: Return intake users can create a POS return from a valid transaction snapshot in under 3 minutes for standard receipts of up to 25 lines.
- **SC-007**: Support or audit findings caused by POS return ownership mismatch are reduced to zero during the first full reporting period after release.

## Assumptions

- POS transaction number lookup should accept the visible POS receipt number and completed POS transaction code when both exist for the same posted checkout.
- Existing Sales Return lifecycle concepts remain the baseline for statuses, approval, receiving, settlement, and dispatch presentation.
- Cash return and product replacement are the only return options in scope for this feature; customer credit, repair-only, unprocessed, or generic sale modification are out of scope.
- Dispatch-quantity reduction is the authoritative business mechanism for representing returned sold products.
- POS returns should reuse the existing business expectation that approved returns must be received before downstream settlement or replacement dispatch.
- Super Admin or owner-level bypass behavior follows existing POS authorization conventions for the `pos.returns.*` permission namespace.
- New reporting/export behavior for POS returns is out of scope unless required for audit visibility during planning.
