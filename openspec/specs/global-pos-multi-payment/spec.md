# global-pos-multi-payment Specification

## Purpose

Provide an authorized global POS transaction register and customer-level collection workflow that presents POS transactions as the user-facing unit while settling their generated Sales through the existing payment ledger.

## Requirements

### Requirement: Global POS payment authorization
The system SHALL expose a global POS payment workspace protected by dedicated access, create, and history permissions, while receipt reprinting SHALL additionally require the existing POS receipt-reprint permission.

#### Scenario: Authorized user opens the workspace
- **WHEN** a user has global POS payment access
- **THEN** the user can open the global list and read-only transaction detail independently of the active setting

#### Scenario: Create permission is separate
- **WHEN** a user has global POS payment access but lacks global POS payment create permission
- **THEN** paid and payable transactions remain inspectable
- **AND** payment form and submission actions are forbidden

#### Scenario: Global receipt reprint requires both permissions
- **WHEN** a user has global POS payment access and POS receipt-reprint permission
- **THEN** the user can reprint an eligible completed transaction receipt from global detail
- **AND** a user missing either permission cannot reprint it

### Requirement: Completed POS transaction register
The system SHALL list POS transactions across settings when the transaction is completed, its checkout is posted, and at least one generated Sale is resolvable, without requiring original debt intent or an existing payment.

#### Scenario: Paid and payable transactions are listed
- **WHEN** the global POS register loads without a payment-status filter
- **THEN** it includes qualifying fully paid, partially paid, and unpaid POS transactions
- **AND** ordinary full-payment and Kas Bon checkouts are treated consistently

#### Scenario: Zero-down debt remains visible
- **WHEN** a completed posted POS transaction has generated Sales with positive live due but no Sale payment
- **THEN** the transaction is listed as unpaid
- **AND** it is eligible for payment

#### Scenario: Non-financial lifecycle records are excluded
- **WHEN** a POS transaction is draft, loaded, cancelled, lacks a posted completed checkout, or has no resolvable generated Sale
- **THEN** it is excluded from the global POS payment register

### Requirement: Canonical POS settlement projection
The system SHALL derive each POS transaction's effective paid amount, live outstanding amount, payment status, effective due date, and overdue balance from every reachable generated Sale and canonical active settlement data.

#### Scenario: Split transaction is one financial row
- **WHEN** a POS checkout generated multiple owner-aligned Sales
- **THEN** the register displays one POS transaction row
- **AND** its effective paid and live outstanding amounts aggregate the generated Sales without duplication

#### Scenario: Payment status follows current balances
- **WHEN** aggregate live due is zero
- **THEN** the POS transaction is paid
- **AND WHEN** live due is positive and effective paid is positive
- **THEN** it is partially paid
- **AND WHEN** live due is positive and effective paid is zero
- **THEN** it is unpaid

#### Scenario: Invalidated payment reopens balance
- **WHEN** an active child-Sale payment is invalidated
- **THEN** the POS aggregate excludes that payment
- **AND** its live due and derived status update accordingly

#### Scenario: Original checkout facts remain immutable
- **WHEN** a later collection is recorded
- **THEN** checkout paid totals, checkout payment allocations, original receipt tender, change, and historical session cash facts remain unchanged

### Requirement: POS payment summary cards
The system SHALL provide clickable outstanding, overdue, and paid-within-30-days cards whose counts use distinct POS transactions and whose totals use the same settlement projection as the register.

#### Scenario: Outstanding card
- **WHEN** the outstanding card is selected
- **THEN** the list contains qualifying POS transactions with positive aggregate live due
- **AND** the card total is their aggregate live due

#### Scenario: Overdue card
- **WHEN** the overdue card is selected
- **THEN** the list contains qualifying POS transactions having at least one currently outstanding child Sale due before today
- **AND** the effective POS due date is the earliest due date among outstanding child Sales

#### Scenario: Paid-within-30-days card
- **WHEN** the recent paid card is selected
- **THEN** the list contains qualifying currently paid POS transactions having an active child-Sale payment dated from today minus 30 days through today
- **AND** invalidated and future-dated payments are excluded

### Requirement: Global POS filters and search
The system SHALL provide Sales-style global filters and a search that uses AND semantics across whitespace tokens, OR semantics across ordinary searchable fields for each token, and exact case-insensitive identity matching for barcodes and serial numbers.

#### Scenario: Structured filters combine
- **WHEN** business, customer, transaction-date, due-date, payment-status, cashier, terminal, or summary-card filters are applied
- **THEN** the register applies them by intersection without duplicating POS transaction rows

#### Scenario: Tokens can match across fields
- **WHEN** separate search tokens match the customer, POS note, and product identity of one transaction
- **THEN** that transaction matches
- **AND** a transaction missing any token does not match

#### Scenario: POS note is searchable
- **WHEN** a token matches the POS transaction note
- **THEN** the completed transaction is discoverable without requiring a matching generated Sale note

#### Scenario: Product and bundle identity is searchable
- **WHEN** tokens match a historical POS product name or code, current linked product identity, bundle name, component identity, Sale reference, receipt number, transaction code, payment reference, or business identity
- **THEN** the containing POS transaction matches

#### Scenario: Barcode lookup is exact
- **WHEN** the complete input matches a captured POS barcode, current primary barcode, or conversion barcode case-insensitively
- **THEN** the containing transaction matches
- **AND** a partial barcode does not match through the barcode branch

#### Scenario: Serial lookup is exact
- **WHEN** the complete normalized input matches POS snapshot or generated Sale and dispatch serial provenance
- **THEN** the containing transaction matches
- **AND** a partial serial does not match

### Requirement: Same-customer multi-POS allocation form
The system SHALL open a customer-level payment form from an eligible POS transaction and list payable POS transactions for that exact customer, with the starting transaction first.

#### Scenario: Selected transaction is first
- **WHEN** a user opens payment creation from a payable POS transaction
- **THEN** that transaction is the first allocation row and defaults to its full live due
- **AND** other payable transactions for the same customer follow with zero allocation

#### Scenario: Paid and other-customer transactions are excluded
- **WHEN** payment candidates load
- **THEN** fully paid transactions and transactions belonging to another customer are excluded

#### Scenario: Shared payment fields are available
- **WHEN** the form is rendered
- **THEN** it provides payment date, reference, payment method, memo, one optional attachment, allocation totals, preview, cancel, and save controls
- **AND** customer credit is not offered

### Requirement: POS-priority child-Sale allocation
The system MUST expand each positive POS allocation across the transaction's current eligible child-Sale balances using the established POS ownership and payment prioritization, skipping settled balances and never exceeding live due.

#### Scenario: Partial collection follows priority
- **WHEN** a POS allocation is smaller than the aggregate live due across multiple generated Sales
- **THEN** the allocation fills each current child-Sale balance in POS priority order
- **AND** any remainder continues to the next priority Sale

#### Scenario: Settled Sale is skipped
- **WHEN** an earlier-priority child Sale has no live due
- **THEN** it receives no new payment
- **AND** allocation continues against the next eligible child Sale

#### Scenario: Preview exposes expansion
- **WHEN** a POS-level allocation expands into multiple child Sales
- **THEN** the user can review each Sale, owning business, live due, and planned amount before submission

### Requirement: Atomic Sale-ledger settlement and POS audit mapping
The system SHALL lock and revalidate the selected POS transactions and generated Sales, create ordinary active Sale payments, reconcile every affected Sale, and persist POS batch/allocation audit links in one atomic submission.

#### Scenario: Successful multi-POS collection
- **WHEN** a user submits valid positive allocations for one customer's POS transactions
- **THEN** ordinary active Sale payments are created for each positive child-Sale allocation
- **AND** every affected Sale is reconciled
- **AND** audit links preserve the batch, POS transaction, Sale, Sale payment, amount, actor, and shared payment context

#### Scenario: Concurrent balance change rejects all allocations
- **WHEN** a locked live balance, customer, lifecycle, mapping, or eligibility differs from the submitted preview
- **THEN** the complete batch is rejected
- **AND** no partial payment or audit mapping remains

#### Scenario: Overpayment is rejected
- **WHEN** a submitted POS allocation exceeds its current aggregate live due
- **THEN** the complete batch is rejected

#### Scenario: Attachment replication is atomic
- **WHEN** an optional attachment is submitted
- **THEN** each generated Sale payment receives an independently accessible copy
- **AND** a copy failure rolls back all payments and cleans partial media artifacts

### Requirement: Global POS transaction detail and payment history
The system SHALL provide a dedicated read-only cross-setting POS detail that uses the transaction's actual business context and displays transaction, product, settlement, generated Sale, return, payment, and print information.

#### Scenario: Cross-setting detail is read-only
- **WHEN** an authorized user opens a POS transaction belonging to another setting
- **THEN** the page loads without changing or depending on the active setting
- **AND** cart load, cancellation, checkout editing, and unrelated mutation controls are absent

#### Scenario: Detail explains aggregate status
- **WHEN** a split POS transaction is viewed
- **THEN** the page shows original checkout tender separately from later collections
- **AND** it shows each generated Sale's business, total, effective paid amount, live due, and payment status

#### Scenario: History retains invalidated entries and origin
- **WHEN** payment history is viewed
- **THEN** active and invalidated child-Sale payments remain visible with status, Sale, business, method, reference, attachment, and known origin
- **AND** only active settlement contributes to effective totals

### Requirement: Global receipt reprinting preserves historical truth
The system SHALL reuse the completed POS receipt presentation and existing print log when reprinting from global detail. Reprints with proven Sale-to-POS-line mapping SHALL show current Sale-derived line prices and transaction total, and `Sisa Utang` SHALL use the same current settlement projection as global POS payment. Original checkout payment methods, tender, and change SHALL remain historical checkout facts and SHALL be distinguishable from current settlement. Persisted checkout and print-history data SHALL remain unchanged.

#### Scenario: Authorized global reprint
- **WHEN** an authorized user reprints a qualifying completed transaction from global detail and its monetary line mapping is proven
- **THEN** the receipt uses the originating business, existing receipt layout, current Sale-derived prices, and current live due
- **AND** the action is recorded as a reprint with the authenticated actor

#### Scenario: Later collection does not rewrite original tender
- **WHEN** a receipt is reprinted after later Sale payments
- **THEN** original methods, tender, and change remain checkout-time facts
- **AND** `Sisa Utang` reflects the current aggregate live due across generated Sales, including later active payments.

#### Scenario: Historical mapping is ambiguous
- **WHEN** a global reprint requests current prices for a multi-line checkout whose Sale details cannot be assigned to POS lines with certainty
- **THEN** no updated-price receipt or misleading reprint log is produced
- **AND** the operator receives an actionable explanation and can access the original checkout receipt.

### Requirement: Global POS allocations use standardized payment amount formatting
Every editable monetary allocation on the global POS payment creation form SHALL follow the `payment-amount-input-formatting` focus, blur, canonical calculation, validation, preview, and submission behavior.

#### Scenario: Operator edits a POS allocation
- **WHEN** an operator focuses, edits, and blurs a global POS allocation
- **THEN** the allocation SHALL transition between raw editing and localized display without changing its canonical value
- **AND** the POS payment total SHALL use that canonical value.

#### Scenario: Operator previews and submits POS allocations
- **WHEN** the operator previews or submits valid formatted POS allocations
- **THEN** the preview and submission payloads SHALL contain the same canonical numeric values
- **AND** existing POS transaction eligibility, live-balance, child-Sale expansion, and atomic settlement validation SHALL remain authoritative.

### Requirement: Fully returned zero-value split Sales do not block sibling collection
The system SHALL allow global payment of a completed POS transaction with valid posted-checkout Sale mappings and positive aggregate live due when each reachable Sale either meets normal payment eligibility or is fully returned with zero current total and zero canonical live due. A fully returned zero-value Sale SHALL qualify for this exception whether archived or unarchived, as soon as its return quantity and financial corrections are committed, without requiring return completion. The system SHALL apply this rule consistently to register payment actions, payment candidates, form access, preview, and submission.

#### Scenario: Returned split reaches zero before return completion
- **GIVEN** a split Sale is fully returned with committed zero quantity, zero total, and zero canonical live due while its return is not yet completed
- **AND** an eligible sibling Sale has Rp100,000 live due
- **WHEN** an authorized user opens and previews global POS payment
- **THEN** the transaction is payable for its remaining Rp100,000 balance
- **AND** return completion is not required

#### Scenario: Archived returned split remains non-blocking
- **GIVEN** a fully returned split Sale has zero total and zero canonical live due and is archived
- **AND** an eligible sibling Sale has positive live due
- **WHEN** the transaction appears in global payment and the user opens, previews, and submits a valid payment
- **THEN** the archived returned Sale does not block collection
- **AND** its original Sale link and history remain available

#### Scenario: Payment is allocated only to outstanding eligible siblings
- **GIVEN** a transaction includes a fully returned zero-value Sale and eligible sibling Sales with outstanding debt
- **WHEN** a valid POS payment is submitted
- **THEN** payment follows existing owner priority across outstanding eligible siblings
- **AND** the returned zero-value Sale receives no new payment or positive payment allocation audit entry

#### Scenario: All split balances are zero
- **WHEN** every reachable Sale has zero canonical live due
- **THEN** the POS transaction remains non-payable and is excluded from payable candidates
- **AND** it retains its existing financial projection and history

#### Scenario: Returned Sale retains a bill or outstanding debt
- **WHEN** a fully returned reachable Sale has a nonzero total or positive canonical live due
- **THEN** it does not qualify for the exception
- **AND** the transaction is rejected for global payment under existing lifecycle eligibility rules

#### Scenario: Unrelated ineligible Sale does not qualify
- **WHEN** a reachable Sale is archived or otherwise lifecycle-ineligible and is not fully returned, even if its total and live due are zero
- **THEN** it does not qualify for the exception
- **AND** existing transaction payment restrictions remain in effect

#### Scenario: Broken Sale mapping remains blocking
- **WHEN** the POS transaction has missing or inconsistent generated Sale mappings alongside a returned zero-value Sale
- **THEN** the transaction remains invalid for global payment
- **AND** the returned Sale is not removed from mapping diagnostics

#### Scenario: Submission checks fresh locked state
- **GIVEN** a preview accepted a returned zero-value Sale alongside a payable sibling
- **WHEN** locked submission finds that the returned Sale no longer qualifies, the sibling no longer meets payment eligibility, or the submitted allocation exceeds current live due
- **THEN** the complete batch is rejected atomically
- **AND** no partial payments or payment allocation audit entries are persisted
