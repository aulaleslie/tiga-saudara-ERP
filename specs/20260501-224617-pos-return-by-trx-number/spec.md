# Feature Specification: POS Return by Transaction Number

**Feature Branch**: `20260501-224617-pos-return-by-trx-number`  
**Created**: 2026-05-01  
**Status**: Draft  
**Input**: User description: "as an eligible user by permission I want to be able to perform return by POS trx number. There must be approval flow. The return options are return cash and product replacement. Since POS transaction splits the sales into ownership, the transaction reversal should be aligned to the sales created by POS transaction. Currently sales return is done by modifying the dispatch quantity; we will follow that. For returned product, reduce dispatch quantity based on returned quantity for the product. For POS sales with bundle, all items included in the bundle should be returned as well; cannot parent only. There should be permission for user who can create, edit, delete, view, approve, receive, dispatch product return. User initiates return POS by entering the POS trx number and then the snapshot of that POS transaction will be populated. For the UI, ensure consistency with existing sales return."

## Clarifications

### Session 2026-05-01

- Q: How should one POS transaction return be represented when the POS transaction generated multiple owner-aligned sales? → A: One POS Return header for the POS transaction, linked to owner/sale-aligned Sales Return records or lines that reuse the existing Sales Return lifecycle.
- Q: For the payment return option, how should settlement work when the original POS transaction used split or staged payments? → A: Record a payment return settlement after approval and receiving, capped by returned item amounts and owner/sale mapping.
- Q: What edit/delete path should be allowed after a POS return has been submitted for approval? → A: Edit/delete is allowed before approval; pending returns may be approved or rejected; after approval and before receiving, cancellation/archive must use an audited reversal path; receiving permanently blocks direct edit/delete/reject actions.
- Q: For product replacement, where must replacement stock be sourced from when the original POS sale was owner/location split? → A: Replacement dispatch must use the same owner and location as the original sale line; other sources require a separate transfer/override first.
- Q: Which permission namespace should gate POS return actions? → A: Use POS-specific permissions `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, `pos.returns.settle`, and `pos.returns.dispatch`; Super Admin bypass follows existing POS conventions.

### Session 2026-05-02

- Q: For product replacement, what replacement item rule should the POS return spec enforce? → A: Replacement must be the same product/SKU as the returned line, with replacement quantity equal to the received returned quantity.
- Q: How should the POS transaction number lookup behave if the entered value matches more than one eligible identifier? → A: The lookup must require exactly one active posted POS transaction match; zero or multiple matches are blocked with a correction message.
- Q: Which source changes should invalidate a previously populated POS return snapshot before submission? → A: Invalidate when transaction/checkout status, generated sales, dispatch details or quantities, active prior returns, bundle composition, serial assignment, owner/location/tax mapping, or payment allocation changes.
- Q: How should the spec define recovery if a POS return lifecycle action partially fails after some owner-aligned changes were prepared or applied? → A: Treat each submit, approve, reject, receive, payment return settlement, replacement-dispatch, and audited archive/cancel action as atomic: rollback all database changes on failure; if non-rollbackable external effects occur, block completion and require audited manual correction.
- Q: How should terminal or inactive POS returns count toward future return eligibility and cumulative quantity limits? → A: Count only active, non-reversed returns toward cumulative quantity limits; rejected, deleted, and fully audited cancelled/archived returns release eligibility, while received/settled/dispatched/completed returns always count.
- Q: How should bundled POS returns handle stock-managed versus stockless components? → A: Include every bundle component; stock-managed components affect dispatch and inventory quantities, while stockless components are recorded for audit and monetary mapping only.
- Q: What composition must the 20-return UAT test set include? → A: Include at least 4 normal non-bundle returns, 4 bundled returns, 4 split-owner returns, 3 partial returns, 2 serial-tracked returns, and at least 5 returns for each option; categories may overlap.
- Q: How should the 3-minute POS return intake target be measured? → A: Measure in UAT/staging with production-like data, a trained authorized return intake user, a standard 25-line receipt, and include lookup, snapshot review, quantity/option entry, and submit.
- Q: How should the zero ownership-mismatch success criterion be measured after release? → A: During the first full reporting period after release, there must be zero confirmed support or audit findings where a POS return maps quantity, owner, sale, dispatch, location, or tax context differently from the original POS-generated sale.
- Q: Which permission should authorize payment return settlement? → A: Add new `pos.returns.settle` permission for payment return settlement.
- Q: How should replacement dispatch behave when stock would need to come from another owner or location? → A: Block replacement dispatch and show a message that a separate transfer/override must be completed first.
- Q: Which module should own POS Return migrations that add nullable links to Sales Return tables? → A: POS module owns all POS Return migrations, including nullable Sales Return linkage migrations.
- Q: Should User Story 3 acceptance criteria explicitly cover payment return settlement? → A: Add a US3 acceptance scenario for authorized `pos.returns.settle` users settling approved and received payment-return POS returns.
- Q: Should reject and audited archive/cancel actions be atomic database operations? → A: Include reject and audited archive/cancel in the atomic lifecycle transaction scope.

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

### User Story 2 - Submit Return for Payment Return or Product Replacement (Priority: P2)

As a permitted sales-return user, I want to select returned quantities and choose either payment return or product replacement, so the business can process the customer request through the supported return options.

**Why this priority**: The selected option determines the downstream payment return settlement or replacement dispatch path and must be captured before approval.

**Independent Test**: Can be tested by creating POS returns for normal product lines and bundled product lines, then confirming the submitted return records contain the selected quantities, return option, original sales references, and required bundle component details.

**Acceptance Scenarios**:

1. **Given** the snapshot contains returnable non-bundle product lines, **When** the user selects returned quantities and chooses payment return, **Then** the submitted return records the returned quantities and requested payment return option.
2. **Given** the snapshot contains returnable non-bundle product lines, **When** the user selects returned quantities and chooses product replacement, **Then** the submitted return records the returned quantities and requested replacement option.
3. **Given** the snapshot contains a bundled POS sale line, **When** the user returns the bundle, **Then** the return includes every product included in the bundle for the returned bundle quantity and does not allow parent-only return.

---

### User Story 3 - Approve, Receive, and Dispatch POS Returns (Priority: P3)

As a return approver or fulfillment user with the right permission, I want POS returns to move through approval, receiving, and replacement dispatch controls, so returned inventory and customer-facing replacement actions are authorized and traceable.

**Why this priority**: Approval and fulfillment controls protect inventory, payment returns, and replacement stock from unauthorized changes.

**Independent Test**: Can be tested by submitting a return, approving or rejecting it, receiving returned goods after approval, and dispatching replacement products only when the return option requires replacement.

**Acceptance Scenarios**:

1. **Given** a submitted POS return is pending approval, **When** an authorized approver approves it, **Then** the return becomes ready for receiving.
2. **Given** a submitted POS return is pending approval, **When** an authorized approver rejects it, **Then** the return cannot be received or dispatched unless it is resubmitted through an allowed path.
3. **Given** an approved POS return has been received and includes product replacement, **When** an authorized dispatch user dispatches replacement goods, **Then** the replacement dispatch is recorded against the return.
4. **Given** an approved POS return has been received and uses payment return, **When** an authorized user with `pos.returns.settle` settles the payment return, **Then** the settlement is recorded against the owner/sale-aligned return lines and capped by the returned item amount.

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
- POS transaction has prior rejected, deleted, cancelled, archived, received, settled, dispatched, or completed returns that affect future return eligibility differently by lifecycle state.
- POS transaction includes bundled products with standalone component rows, split ownership across components, or a mix of stock-managed and stockless components.
- POS transaction includes serial-tracked items that require serial-level return identification.
- Replacement product is unavailable, out of stock, or cannot be sourced from the same owner and location as the original sale line without a separate transfer/override first.
- User loses permission between snapshot population and return submission or approval.
- User attempts to edit/delete after approval or receiving, or to archive/cancel through an unaudited path.
- Submit, approve, reject, receive, payment return settlement, replacement-dispatch, or audited archive/cancel processing fails after owner-aligned changes have been prepared or partially applied.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow users with `pos.returns.create` permission to initiate a return by entering a POS transaction number.
- **FR-002**: The system MUST resolve the entered POS transaction number against completed POS transaction and receipt identifiers, MUST require exactly one active posted POS transaction match, and MUST block zero or multiple matches with a clear correction message. The system MUST only allow returns for posted POS transactions within the user's permitted data scope.
- **FR-003**: The system MUST populate an immutable source snapshot after a valid POS transaction number is entered, including transaction header, customer, cashier/session context, receipt/transaction reference, payment summary, generated sales, owner groups, dispatch records, product lines, bundle composition, and returnable quantities.
- **FR-004**: The system MUST prevent users from submitting a POS return from a stale or manually altered source snapshot. A snapshot MUST be treated as stale when transaction or checkout status, generated sales, dispatch details or quantities, active prior returns, bundle composition, serial assignment, owner/location/tax mapping, or payment allocation changes before submission.
- **FR-005**: The system MUST provide exactly two POS return options for this feature: payment return and product replacement.
- **FR-006**: The system MUST allow returned quantities to be entered only up to the still-returnable dispatched quantity for each stock-managed product or bundle component, and up to the original sold component quantity for stockless bundle components.
- **FR-007**: The system MUST align each returned product quantity to the original sale created by the POS transaction, preserving the original owner, location, tax context, and dispatch relationship from that sale.
- **FR-008**: The system MUST reduce the effective dispatched quantity for returned stock-managed products by the received return quantity, following the existing sales-return business rule that returns are represented through dispatch-quantity adjustment.
- **FR-009**: The system MUST reject parent-only bundled POS returns. A bundled POS sale line can be returned only through its bundle component return lines.
- **FR-010**: The system MUST calculate bundle component returned quantities from the returned bundle quantity and the original bundle composition.
- **FR-011**: The system MUST support partial returns for non-bundled products when the selected returned quantity does not exceed the still-returnable dispatched quantity.
- **FR-012**: The system MUST support partial returns for bundled products only when all included bundle components are returned proportionally for the selected bundle quantity.
- **FR-012a**: For accepted bundled POS returns, the system MUST include every original bundle component. Stock-managed components MUST participate in dispatch and inventory quantity effects, while stockless components MUST be retained on the return for audit and monetary mapping and MUST NOT create inventory or dispatch quantity reductions.
- **FR-013**: The system MUST provide an approval flow before returned goods can be received or replacement goods can be dispatched.
- **FR-014**: The system MUST allow authorized users to approve or reject submitted POS returns and record the actor, timestamp, and rejection reason when applicable.
- **FR-015**: The system MUST allow authorized users to receive returned products only after approval.
- **FR-016**: The system MUST allow authorized users to dispatch replacement products only for POS returns whose selected option is product replacement and whose lifecycle state permits dispatch.
- **FR-016a**: The system MUST source replacement dispatch from the same owner and location as the original sale line. If replacement stock would need to come from another owner or location, the POS return workflow MUST block replacement dispatch with a clear message that a separate transfer or override must be completed before dispatch can proceed.
- **FR-016b**: The system MUST replace a returned line only with the same product/SKU as the original returned line, and the replacement dispatch quantity MUST equal the received returned quantity for that line.
- **FR-017**: The system MUST prevent payment return settlement from being processed for returns whose selected option is product replacement, and prevent replacement dispatch for returns whose selected option is payment return.
- **FR-017a**: The system MUST process payment return settlement only after approval and receiving, capped by the returned item amount and allocated to the original owner/sale-aligned return lines.
- **FR-018**: The system MUST define and enforce POS-specific permissions for `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, `pos.returns.settle`, and `pos.returns.dispatch`; Super Admin bypass MUST follow existing POS authorization conventions.
- **FR-019**: The system MUST allow POS returns to be edited or deleted only before approval. Pending POS returns MAY be approved or rejected by authorized users. After approval and before receiving, users MUST use an audited archive/cancel reversal path instead of direct edit/delete/reject actions. The audited archive/cancel path MUST record actor, timestamp, reason, previous status, resulting status, and linked Sales Return state changes; MUST release cumulative return eligibility only when no receiving, payment return settlement, dispatch, inventory, or financial mutation has completed; and MUST block archive/cancel after receiving, payment return settlement, dispatch, or completion.
- **FR-020**: The system MUST show POS return screens using the existing Sales Return list, create/edit, and detail page conventions as the UI reference. POS return screens MUST match the Sales Return table structure, status badge placement, primary action placement, approval/receiving/payment-return-settlement action grouping, validation message placement, and Bootstrap/CoreUI layout patterns unless POS-specific transaction snapshot, ownership group, bundle, or payment details require additional fields.
- **FR-021**: The system MUST present clear user-facing messages for unknown transaction numbers, unauthorized transactions, non-posted transactions, non-returnable quantities, invalid bundle selections, and invalid lifecycle actions.
- **FR-022**: The system MUST keep an audit trail for create, edit, delete/archive, approve, reject, receive, payment return settlement, and replacement dispatch actions.
- **FR-023**: The system MUST prevent duplicate or cumulative returns from exceeding the original returnable dispatched quantity for each original sale product or bundle component. Cumulative quantity checks MUST count only active, non-reversed returns; rejected, deleted, and fully audited cancelled or archived returns MUST release return eligibility, while received, settled, dispatched, and completed returns MUST always count.
- **FR-024**: The system MUST preserve the relationship between the POS return, source POS transaction, source POS checkout, generated sale documents, sale detail lines, dispatch details, and bundle component rows.
- **FR-025**: The system MUST represent one POS transaction return as a single POS Return header linked to owner/sale-aligned Sales Return records or lines, reusing the existing Sales Return lifecycle for approval, receiving, payment return settlement, and dispatch behavior.
- **FR-026**: The system MUST treat submit, approve, reject, receive, payment return settlement, replacement-dispatch, and audited archive/cancel actions as atomic database operations across all owner/sale-aligned records. If an action fails, all database changes for that action MUST roll back; if a non-rollbackable external effect has already occurred, the system MUST block completion and require an audited manual correction before further lifecycle progress.

### Key Entities

- **POS Transaction Number**: The user-entered identifier for locating a completed POS transaction. It resolves to exactly one active posted POS checkout and completed transaction record, including visible receipt or transaction references; zero or multiple matches are not eligible for snapshot creation.
- **POS Return**: A return request initiated from one POS transaction. It is the single parent header for that POS transaction return, contains lifecycle status, approval state, selected return option, source snapshot reference, permissions/audit actors, and generated return reference, and links to the owner/sale-aligned Sales Return records or lines used to execute the existing Sales Return lifecycle.
- **POS Return Line**: A returned product quantity tied to an original sale line, dispatch detail when stock-managed, owner group, stock behavior, and optional bundle context.
- **POS Return Bundle Group**: A group of return lines representing one returned bundle quantity and every included component required by the original bundle composition.
- **Return Option**: The requested handling method for the return. Supported values are payment return and product replacement.
- **Ownership-Aligned Reversal**: The mapping that ensures each returned quantity reverses the sale and dispatch quantity created for the same original POS ownership group.
- **Source Snapshot**: The read-only transaction snapshot populated from the entered POS transaction number and used to validate submission. It is invalidated before submission by changes to transaction or checkout status, generated sales, dispatch details or quantities, active prior returns, bundle composition, serial assignment, owner/location/tax mapping, or payment allocation.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In UAT, 100% of valid posted POS transaction numbers entered by authorized users populate a source snapshot without requiring manual sale lookup.
- **SC-002**: In UAT, 100% of invalid, unposted, unauthorized, or fully returned POS transaction numbers are blocked with a clear user-facing message.
- **SC-003**: In a test set of at least 20 POS returns, including at least 4 normal non-bundle returns, 4 bundled returns, 4 split-owner returns, 3 partial returns, 2 serial-tracked returns, and at least 5 returns for each option (`payment_return` and `product_replacement`) with categories allowed to overlap, 100% of returned quantities are mapped to the correct original sale and ownership group.
- **SC-004**: In a test set of bundled POS returns, 100% of accepted bundle returns include all required bundle component lines for the returned bundle quantity.
- **SC-005**: In a permission matrix review, each POS product-return action is allowed only to users with the matching `pos.returns.*` permission or existing Super Admin bypass in 100% of tested cases.
- **SC-006**: In UAT/staging with production-like data, a trained authorized return intake user can create a POS return in under 3 minutes for a standard receipt of up to 25 lines, measured from transaction-number lookup through snapshot review, quantity/option entry, and submit.
- **SC-007**: During the first full reporting period after release, there are zero confirmed support or audit findings where a POS return maps quantity, owner, sale, dispatch, location, or tax context differently from the original POS-generated sale.

## Assumptions

- POS transaction number lookup should accept the visible POS receipt number and completed POS transaction code when both exist for the same posted checkout.
- Existing Sales Return lifecycle concepts remain the baseline for statuses, approval, receiving, payment return settlement, and dispatch presentation.
- Payment return and product replacement are the only return options in scope for this feature; customer credit, repair-only, unprocessed, or generic sale modification are out of scope.
- “Payment return” is the user-facing return option name. “Payment return settlement” is the post-approval, post-receiving lifecycle action used to settle an approved payment return across the applicable original payment method or payment return process.
- Dispatch-quantity reduction is the authoritative business mechanism for representing returned sold products.
- POS returns should reuse the existing business expectation that approved returns must be received before downstream payment return settlement or replacement dispatch.
- Super Admin or owner-level bypass behavior follows existing POS authorization conventions for the `pos.returns.*` permission namespace.
- New reporting/export behavior for POS returns is out of scope unless required for audit visibility during planning.
