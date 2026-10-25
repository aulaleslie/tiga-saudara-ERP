# Spec Delta

## ADDED Requirements

### Requirement: Remaining purchase units retain recorded selling prices
Current completed receipts SHALL display proven persisted transaction selling prices independently of return-adjusted charges. A completed return or internal monetary allocation alone MUST NOT change the displayed unit price. Proven later linked Sale price adjustments SHALL remain effective; current catalog prices MUST NOT replace recorded transaction prices.

#### Scenario: Rounded refund leaves a different effective average
- **WHEN** a packed purchase records 12 PCS for Rp85,000 with a persisted base-unit selling price of Rp7,083.33
- **AND** one PCS has a completed cash return of Rp7,100, leaving 11 PCS and an authoritative charge of Rp77,900
- **THEN** the remaining purchase displays one breakdown of `11 PCS @ Rp7.083,33` and a row total of Rp77,900 using existing currency styling
- **AND** it does not display recalculated unit prices of Rp7,081.81 or Rp7,081.82

#### Scenario: Legitimate sale price adjustment survives a return
- **WHEN** an item's linked Sale unit selling price was legitimately changed before a partial return
- **THEN** the remaining units display the proven adjusted selling price at stored currency precision
- **AND** existing eligible price-adjustment explanations compare against checkout as before
- **AND** no price adjustment is inferred merely from a rounded refund or changed charge

### Requirement: Rounding allocations do not split displayed quantities
A current receipt MUST NOT split units of an existing customer-facing line into different displayed prices merely to distribute monetary rounding remainders. Internal owner splits with the same recorded selling price SHALL remain invisible. Meaningful box/loose unit distinctions and proven genuine price differences SHALL retain their existing presentation.

#### Scenario: Identical selling prices across owner splits
- **WHEN** one customer-facing product line maps to multiple internal Sales at the same recorded selling price
- **AND** the remaining charge cannot divide evenly across its quantity
- **THEN** the receipt displays its combined remaining quantity with one recorded price for that selling unit
- **AND** it does not expose internal rounding groups or owner identifiers

#### Scenario: Meaningful packed units remain distinguishable
- **WHEN** a remaining packed line contains whole boxes and loose base units
- **THEN** existing packing quantities and snapshotted unit labels remain visible
- **AND** each unit group uses its proven recorded selling price without splitting a group into adjacent cent prices
- **AND** when only loose units remain from a box purchase, the persisted transaction base-unit selling price is used rather than the catalog loose-unit price

#### Scenario: Genuine price differences are not averaged
- **WHEN** proven linked Sale details contain genuinely different persisted selling prices
- **THEN** existing price-adjustment explanations remain eligible with their corresponding quantities
- **AND** the receipt does not invent a single average selling price or collapse unrelated POS lines

### Requirement: Displayed selling prices do not redefine remaining charges
Authoritative remaining row totals SHALL remain independent of displayed unit-price multiplication and retain existing total currency formatting. Receipt generation MUST NOT change sales, refunds, payments, or checkout snapshots to force equality. Existing total reconciliation, completed print/reprint parity, full-return omission, serial handling, historical receipts, and previews SHALL remain intact.

#### Scenario: Stored precision does not reproduce the remaining charge
- **WHEN** 11 remaining units have a recorded price of Rp7,083.33 and an authoritative remaining charge of Rp77,900
- **THEN** the receipt retains Rp77,900 as the row charge rather than replacing it with Rp77,916.63
- **AND** all ordinary completed print and reprint paths display equivalent purchase facts
- **AND** generating those receipts makes no financial or return mutations

#### Scenario: Full return and historical access
- **WHEN** the last remaining units are fully cash-returned
- **THEN** existing rules omit the item from the current main purchase list and retain eligible return details
- **AND** explicit historical receipts continue to show original checkout quantities, packing, and amounts
