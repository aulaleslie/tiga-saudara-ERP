# pos-kas-bon-collection Specification

## Purpose

Allow authorized POS users to collect one Kas Bon transaction at a time while accounting for funds in their receiving session.

## Requirements

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
The payment UI SHALL accept one selected POS transaction and derive the payment amount directly from the sum of payment method amounts (Jumlah), which must be greater than zero and no greater than live due. It SHALL support existing cash, non-cash, and mixed payment methods with applicable evidence rules, localized monetary inputs for Jumlah, and free-text Keterangan for every method (including CASH). In the Keterangan column, every method row SHALL accept optional free text without monetary formatting or tender calculations. For Transfer and non-cash methods, the UI SHALL provide a separate Nomor Referensi text input, required according to the payment method's existing rules, while preserving evidence upload. Method Keterangan and transfer reference SHALL be persisted separately without mutual substitution. Uang Tunai Diterima and Kembalian Tunai SHALL be removed from this flow; the entered cash Jumlah SHALL be treated as cash received and applied, with zero change. Adding or removing payment method rows SHALL preserve all previously entered amounts, descriptions, references, and uploaded evidence. The UI SHALL display Total Pembayaran and Sisa Hutang Setelah Pembayaran without requiring a separate payment amount input. It SHALL provide no finish-as-Kas-Bon option.

#### Scenario: Partial cash payment with free-text Keterangan
- **WHEN** live due is Rp150,000, cash Jumlah is Rp50,000, and Keterangan is "Cicilan kedua"
- **THEN** the debt reduces by Rp50,000, remaining debt is Rp100,000, change is zero, drawer cash increases by exactly Rp50,000, and the description "Cicilan kedua" is recorded without affecting calculations

#### Scenario: Mixed payment with separate description and transfer reference
- **WHEN** Rp100,000 is collected with Rp60,000 cash (Keterangan: "Cicilan tunai") and Rp40,000 transfer (Keterangan: "Titipan BCA", Nomor Referensi: "TRF-987654")
- **THEN** settlement records both methods, stores Keterangan and reference separately without substitution, and only Rp60,000 increases expected drawer cash

#### Scenario: Invalid amount or missing required reference
- **WHEN** amount to pay is non-positive, exceeds live due, or a method requiring reference lacks a valid reference
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
