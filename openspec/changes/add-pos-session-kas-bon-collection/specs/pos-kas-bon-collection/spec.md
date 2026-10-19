## Purpose

Allow authorized POS users to collect one Kas Bon transaction at a time while accounting for funds in their receiving session.

## ADDED Requirements

### Requirement: Collection requires dedicated permission and an active session
The system SHALL protect discovery, review, and submission with a dedicated Kas Bon collection permission and the actor's open POS session in the active business. A valid session without a terminal SHALL be supported under existing session rules.

#### Scenario: Authorized session without terminal
- **WHEN** an authorized user opens collection from POS using their open session without a terminal
- **THEN** discovery and payment are available and attributed to that session

#### Scenario: Unauthorized or stale session
- **WHEN** permission is absent or the receiving session is missing, belongs to another actor or business, or is no longer open
- **THEN** access or submission is rejected server-side without financial or cash entries

### Requirement: Searchable outstanding Kas Bon list
The POS SHALL offer a Bayar Kas Bon modal listing completed posted transactions originally checked out as Kas Bon with positive canonical live due in the active business, across original cashiers, terminals, and sessions. Columns SHALL include transaction number, customer, notes, original checkout total, current bill total, already paid, and remaining debt. All displayed column values SHALL be searchable.

#### Scenario: Previous cashier debt is discoverable
- **WHEN** an authorized cashier searches an outstanding Kas Bon from a previous session in the active business
- **THEN** its transaction appears regardless of its originating cashier or terminal

#### Scenario: Every displayed column is searchable
- **WHEN** search text matches a displayed identifier, customer, note, or monetary value using its displayed or canonical numeric representation
- **THEN** the matching transaction is discoverable

#### Scenario: Ineligible rows are excluded
- **WHEN** a transaction is fully settled, from another business, not originally Kas Bon, or lacks a valid completed posted checkout
- **THEN** it is excluded from the collection list

### Requirement: Current bill review precedes collection
The cashier SHALL review one POS transaction's customer, identifiers, notes, current items, quantities, unit prices, line totals, applicable adjustments, current bill total, effective paid amount, and live due before payment. Underlying Sale allocation SHALL remain automatic. Ambiguous current item mapping SHALL block collection with an actionable explanation rather than display invented prices.

#### Scenario: Modified price is authoritative
- **WHEN** a generated Sale's price has changed since checkout
- **THEN** review shows the current authoritative bill and debt rather than the historical checkout price
- **AND** the cashier is not required to choose underlying Sales or owners

#### Scenario: Bill changes after review
- **WHEN** the bill, settlement balance, or eligibility changes before submission
- **THEN** submission is rejected without writes and requires refreshed review

### Requirement: Checkout-style single-transaction payment
The payment UI SHALL accept one selected POS transaction and an amount to pay greater than zero and no greater than live due, defaulting to full due. It SHALL support existing cash, non-cash, and mixed payment methods with applicable evidence rules, localized monetary inputs, tender and change. It SHALL provide no finish-as-Kas-Bon option.

#### Scenario: Partial payment with change
- **WHEN** live due is Rp150,000, amount to pay is Rp50,000, and cash tender is Rp100,000
- **THEN** change is Rp50,000, the debt reduces by Rp50,000, and remaining debt is Rp100,000

#### Scenario: Mixed payment
- **WHEN** Rp100,000 is collected with Rp60,000 cash and Rp40,000 transfer
- **THEN** settlement records both methods and only Rp60,000 increases expected drawer cash

#### Scenario: Invalid amount or tender
- **WHEN** amount to pay is non-positive, exceeds live due, or payment tender cannot cover it under existing method rules
- **THEN** submission is rejected without settlement or session entries

### Requirement: Collection is atomic and idempotent
The system SHALL revalidate locked current transaction, Sale, and receiving-session state, allocate payment by existing POS ownership priority, and persist ordinary Sale payments, collection audit links, tender, change, and session movements atomically. Replaying a successful submission SHALL return the same collection without duplicate entries.

#### Scenario: Split transaction partial payment
- **WHEN** a partial collection concerns multiple generated Sales
- **THEN** eligible outstanding Sales receive payment in established priority and canonical aggregate due decreases by the collection amount

#### Scenario: Write failure or concurrent settlement
- **WHEN** any payment, attachment, audit, or cash write fails, or locked review state is stale
- **THEN** the entire collection is rejected and no partial settlement or session movement remains

#### Scenario: Successful request is retried
- **WHEN** a completed submission is retried with its original idempotency identity
- **THEN** the existing result is returned without additional Sale payments, collections, or cash events

### Requirement: Printable collection receipt
A successful collection SHALL provide a printable receipt with a unique collection reference, original POS transaction reference, customer, receiving cashier/session, time, collected amount, method breakdown, tender, change, and remaining debt immediately after collection. Printing or reprinting SHALL not create another collection or rewrite original checkout facts.

#### Scenario: Partial collection receipt
- **WHEN** a partial payment succeeds
- **THEN** its receipt shows the amount collected and the resulting remaining debt

#### Scenario: Collection receipt is reprinted later
- **WHEN** the receipt is printed again after subsequent payments or price changes
- **THEN** it preserves that collection's recorded facts and remaining debt at collection time
