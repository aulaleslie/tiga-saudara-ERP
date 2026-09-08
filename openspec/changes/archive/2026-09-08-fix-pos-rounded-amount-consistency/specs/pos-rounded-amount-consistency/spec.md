## ADDED Requirements

### Requirement: POS captures row amounts and document totals together
POS SHALL persist calculated commercial row amounts and header totals from the same snapshot atomically on draft save and checkout completion. Captured amounts SHALL distinguish row discounts, rounded pre-bill net, bill-discount allocation, tax, and final charged value. Existing automatic-rounding eligibility and manual pricing authority SHALL remain unchanged.

#### Scenario: Draft reproduces transaction 3393 pricing
- **WHEN** an automatic draft contains rows of 250000, 24 × 1083, 14000, and 28000 without discounts or tax and the increment is 100
- **THEN** saved authoritative row totals SHALL be 250000, 26000, 14000, and 28000
- **AND** the header grand total SHALL be 318000
- **AND** the affected unit price SHALL remain 1083

#### Scenario: Saved draft survives configuration change
- **WHEN** the business changes its increment after a draft with authoritative row amounts is saved and the draft is loaded and saved without eligible pricing changes
- **THEN** its captured row and header amounts SHALL remain unchanged

#### Scenario: Packed and manual rows retain authority
- **WHEN** a packed automatic row or an approved manual override is saved and completed
- **THEN** its captured authoritative amount SHALL survive without reconstruction from a rounded blended unit price
- **AND** manual overrides SHALL bypass automatic increment rounding

### Requirement: POS presents captured amounts consistently
Transaction details and receipts SHALL consume the same persisted row-amount resolution contract. Monetary presentation SHALL preserve nonzero decimals up to the supported two-decimal precision across POS, transaction lists, details, and receipts. Display SHALL NOT trigger repricing or apply increment rounding. Customer-facing receipts and details SHALL show authoritative row totals without rounding wording or a separate rounding adjustment row. Rounding adjustments SHALL remain available internally for monetary reconciliation.

#### Scenario: Rounded row appears identically in detail and receipt
- **WHEN** a saved row has quantity 24, unit price 1083, and authoritative pre-bill net 26000 without discounts
- **THEN** both detail and receipt SHALL show a row total of 26000
- **AND** the receipt and detail SHALL NOT display rounding wording or a separate adjustment of 8

#### Scenario: Decimal amount remains visible
- **WHEN** an exact manually priced amount is 1234.56 or automatic rounding is disabled for that amount
- **THEN** POS, transaction list, detail, and receipt SHALL preserve the monetary value 1234.56 in their locale formatting

#### Scenario: Legacy read cannot establish rounded row authority
- **WHEN** a legacy document lacks authoritative row metadata
- **THEN** reading or printing SHALL use the documented legacy fallback without applying current rounding or writing data
- **AND** SHALL NOT label an inferred rounded amount as historically captured

### Requirement: Downstream monetary allocations reconcile to captured POS amounts
Checkout, generated sales, applied payment allocations, reports, and returns SHALL consume captured charged amounts and reconcile in minor units. Owner, bundle, and return fragments SHALL use deterministic remainder allocation without independent increment rounding. Cash tendered minus change, other applied payments, and outstanding debt SHALL reconcile to the billed amount under the existing payment model.

#### Scenario: Discounts and tax reconcile after row rounding
- **WHEN** an automatic row receives a row discount, increment rounding, and an allocated bill discount with applicable tax
- **THEN** the row discount SHALL be applied once and the bill discount SHALL be applied once
- **AND** tax and pre-tax allocations SHALL reconcile to the final charged amount
- **AND** no additional grand-total increment rounding SHALL occur

#### Scenario: Owner and payment allocations preserve the charge
- **WHEN** a rounded commercial row is split across owners and paid through multiple methods
- **THEN** generated owner sales and applied payment allocations SHALL reconcile to their respective captured charge totals
- **AND** reports SHALL reflect the same transaction charge

#### Scenario: Partial returns exhaust the original value
- **WHEN** successive eligible partial returns exhaust a rounded source row after the business increment changes
- **THEN** their allocated source valuations SHALL sum to the original returnable charged amount
- **AND** no return fragment SHALL receive current increment rounding

### Requirement: Affected draft repair is explicit and auditable
The system SHALL provide a setting-scoped repair operation for explicitly selected affected draft IDs, defaulting to read-only preview and requiring explicit apply mode for mutation. It SHALL recover missing amounts only from captured pricing evidence that reconciles to the existing header, preserve existing authoritative/manual amounts, and refuse ambiguous recovery. It SHALL NOT repair completed, cancelled, or loaded/active drafts. Apply SHALL lock and revalidate state, preserve before/after audit evidence and actor identity, and refresh the snapshot hash atomically.

#### Scenario: Preview and repair a reconcilable draft
- **WHEN** an operator previews a draft matching transaction 3393's captured inputs and an increment of 100
- **THEN** the preview SHALL show the affected row changing from fallback 25992 to authoritative 26000 and an unchanged header of 318000 without writing data
- **AND** explicit apply after successful state revalidation SHALL persist the missing amounts and audit evidence
- **AND** repeating apply SHALL make no additional monetary change

#### Scenario: Repair evidence is insufficient
- **WHEN** candidate recovered amounts do not reconcile to the saved header or historical pricing evidence is ambiguous
- **THEN** repair SHALL refuse mutation and identify the reason

#### Scenario: State changes after preview
- **WHEN** the transaction hash changes or the draft becomes loaded, active, cancelled, or completed before repair applies
- **THEN** repair SHALL refuse mutation and require a fresh eligible preview

#### Scenario: Completed transactions remain immutable
- **WHEN** deployment occurs or a completed transaction is selected for repair
- **THEN** its recorded row values, totals, payments, postings, and return snapshots SHALL remain unchanged
