# Spec Delta

## MODIFIED Requirements

### Requirement: Persisted override metadata SHALL NOT outlive the row state it describes
Canonical derived metadata persisted by a row override SHALL be refreshed or removed whenever the row's quantity, discount, tax context, or pricing source changes. A unit-price override SHALL retain its authoritative price and source with recomputed metadata. When quantity changes on a row carrying a row-total override, the previously displayed two-decimal effective unit price SHALL become the authoritative unit price for the new quantity, and canonical totals and tax metadata SHALL be recomputed from that unit price. The original row-total override SHALL no longer be authoritative after that quantity change. A discount or tax-context change on a row-total override SHALL continue to restore resolved standard pricing unless a separate rule explicitly governs it.

#### Scenario: Row-total override stays exact at its entered quantity
- **WHEN** a cashier overrides a quantity-three row total to Rp10.000,00
- **THEN** the authoritative row total MUST remain Rp10.000,00 at quantity three
- **AND** the displayed effective unit price MUST be Rp3.333,33

#### Scenario: Quantity change carries the displayed unit price forward
- **WHEN** that row's quantity increases from three to four
- **THEN** its authoritative unit price MUST remain Rp3.333,33
- **AND** its new row total MUST be Rp13.333,32 before any applicable row discount
- **AND** the row MUST no longer claim that Rp10.000,00 is its authoritative total
- **AND** its canonical monetary and tax metadata MUST describe the new quantity and total

#### Scenario: Row-total override is invalidated by a row change
- **WHEN** a row carrying an applied row-total override has its quantity changed
- **THEN** the original row total MUST cease to be authoritative
- **AND** the previously displayed two-decimal effective unit price MUST become authoritative for the new quantity
- **AND** the row's canonical derived fields MUST be refreshed for the new total

#### Scenario: Discount or tax-context change restores standard pricing
- **WHEN** a row carrying an applied row-total override has its discount or tax context changed without a quantity change
- **THEN** the row MUST revert to resolved standard pricing
- **AND** every canonical derived field from the old override MUST be removed

#### Scenario: Customer selection continues to reprice the row
- **WHEN** a cashier selects a different customer after applying a row-total override
- **THEN** the row MUST use the new customer's resolved standard pricing
- **AND** the old override MUST no longer control its unit price or total
