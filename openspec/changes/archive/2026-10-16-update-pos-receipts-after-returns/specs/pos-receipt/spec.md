## MODIFIED Requirements

### Requirement: Completed POS reprints show current Sale-derived prices by receipt line
When a completed POS receipt is printed or reprinted after generated Sale monetary values change, the system SHALL show each customer-facing line's current amount from its associated authoritative Sale details and SHALL show the current transaction total equal to the sum of reachable generated Sale totals. Bundle components SHALL remain included beneath one priced parent bundle line and their nested allocation snapshots MUST NOT be billed again. For completed, non-reversed returns, quantities, composition quantities, serial identities, payments, and return details SHALL reflect the current purchase as specified below. Without such returns, existing product, quantity, composition, and serial presentation SHALL remain intact. The current monetary projection SHALL NOT overwrite persisted checkout snapshots.

#### Scenario: Parent remainder is edited on a three-owner bundle
- **WHEN** a quantity-two bundle originally totals 2000 across parent-owner and two component-owner Sales, and the parent-owner Sale detail is increased by 50
- **THEN** the reprinted bundle line and current receipt total show 2050
- **AND** the bundle quantity stays two and nested components are not priced as extra customer lines.

#### Scenario: Another owner allocation changes
- **WHEN** a generated component-owner Sale detail changes while its bundle-item allocation snapshot remains unchanged
- **THEN** the associated customer-facing bundle line reflects the current Sale detail amount
- **AND** the bundle-item snapshot remains excluded from the arithmetic.

#### Scenario: Multiple receipt lines share a Sale
- **WHEN** one owner Sale contains details for two different POS receipt lines
- **THEN** a monetary edit to one detail changes only its associated receipt line
- **AND** the sum of displayed line charges, discounts, and header charges reconciles to the current generated-Sale total without duplicating any detail.

#### Scenario: Sale header discount or shipping changes
- **WHEN** an authorized monetary edit changes a generated Sale's header discount or shipping amount
- **THEN** the reprinted line and receipt monetary presentation reconciles to the current generated-Sale total without adding a new receipt layout section
- **AND** unchanged product, quantity, and composition facts remain intact and checkout tender facts remain available in explicit historical receipts.

## ADDED Requirements

### Requirement: Completed receipt print paths show the same current purchase
All ordinary completed POS receipt print and reprint entry points SHALL show the same current purchase state, including checkout, transaction, and global-payment receipt paths. Existing access checks and PRINT versus REPRINT audit distinctions SHALL remain intact. Explicit historical receipts SHALL preserve original checkout quantities, serials, totals, tender, and change and SHALL be clearly identified as historical. Draft and loaded previews SHALL retain existing behavior. Reading or printing a receipt MUST NOT mutate financial or return records.

#### Scenario: Print and Reprint after a completed return
- **WHEN** a completed return exists and an authorized operator opens ordinary checkout Print, transaction Print/Reprint, checkout Reprint, or global-payment Reprint
- **THEN** each receipt shows equivalent current items, return history, total, paid amount, and debt
- **AND** successful access records the existing action-specific audit type

#### Scenario: Historical original remains available
- **WHEN** an authorized operator explicitly requests the historical receipt after a completed return
- **THEN** the receipt shows the original checkout facts with a historical indication and no current-state adjustments

### Requirement: Main receipt items reflect completed cash returns
Main items SHALL show remaining quantities and authoritative current line totals after all completed, non-reversed cash returns. A zero-remaining item SHALL be omitted from main items and SHALL remain visible in return details. A partial return MUST NOT be represented by dividing the reduced amount by the original quantity. Packed-unit and bundle component quantities SHALL reflect the remaining customer-facing quantity, without counting split-owner execution rows as additional customer returns. All-item returns SHALL produce a valid receipt with an empty main item list and zero purchase total.

#### Scenario: Partial return keeps correct quantity and amount
- **WHEN** three items were bought for Rp100,000 each and one is cash-returned with a completed return
- **THEN** the main line shows quantity two and total Rp200,000, with unit price Rp100,000
- **AND** the returned quantity one appears below in return details

#### Scenario: All items are returned
- **WHEN** completed cash returns consume every purchased item
- **THEN** main items are empty and current total, paid amount, and debt are zero
- **AND** all returned items remain visible in return details

#### Scenario: Packed and split bundle returns
- **WHEN** a completed cash return reduces a packed line or split-owner bundle parent quantity
- **THEN** the main unit breakdown and nested component quantities reflect the remaining quantity
- **AND** each customer-facing quantity reduction is counted once

### Requirement: Replacement receipts show retained items and effective serials
Completed product replacements SHALL preserve customer-facing purchased quantity and value. Non-serial replacements SHALL be explained in return details. Serialized main items SHALL show effective replacement serials in place of returned serials, while return details SHALL retain original-to-replacement lineage. Unaffected serials SHALL remain present. Replacement chains SHALL resolve chronologically, and subsequent cash returns SHALL remove the affected effective serial. Informational bundle component rows MUST NOT cause a replacement of component serials when only the parent was replaced.

#### Scenario: Non serial replacement
- **WHEN** one of three non-serial items is replaced through a completed return
- **THEN** the main quantity stays three with unchanged customer-facing value
- **AND** return details explain the quantity replaced

#### Scenario: Serial replacement chain and later refund
- **WHEN** serial A is replaced by B, B is replaced by C, and another purchased serial D is unaffected
- **THEN** the main items show C and D and return details show both A to B and B to C
- **AND** a subsequent completed cash return for C removes C from main items and records C in return details

### Requirement: Receipt return history includes completed effective returns
The receipt SHALL list every completed, non-reversed return in execution order with a stable tie-break, including reference, completion date, completed status, product, returned quantity, resolution, and actual cash-refund amount or replacement details. Serial replacements SHALL identify both returned and replacement serials. Cash-return details SHALL distinguish the purchase-value reduction, debt reduction, and actual refund so a debt offset is not described as cash paid. Draft, pending, approval-only, incomplete, rejected, cancelled, archived, reversed, and deleted returns SHALL NOT contribute return adjustments or appear as completed history.

#### Scenario: Multiple mixed returns
- **WHEN** a receipt has multiple completed cash returns and replacements alongside a pending return
- **THEN** all completed effective returns appear once with their individual details and the main purchase reflects their combined effect
- **AND** the pending return has no receipt adjustment or completed-history entry

#### Scenario: Approval alone is insufficient
- **WHEN** a return is approved but its execution is not completed
- **THEN** no completed return history or corresponding quantity or serial adjustment is shown

### Requirement: Current receipt payment presentation offsets debt before cash refund
Current receipts SHALL display current purchase total, retained paid amount, and current outstanding debt reconciled with persisted authoritative payment and return execution evidence, including later payments. A return SHALL reduce unpaid debt first; only value exceeding the unpaid debt SHALL be a cash refund. Paid amount SHALL exclude refunded money and SHALL NOT exceed current purchase total. Original checkout tender and change MUST NOT be presented as current paid or change after returns; adjusted payment-method rows SHALL reconcile to current retained paid amount, and refunds SHALL be explained in return details. Receipt generation MUST NOT create refunds, rewrite checkout snapshots, or silently conceal contradictory execution evidence; an unreconcilable current receipt SHALL be refused with an actionable explanation and access to the historical receipt.

#### Scenario: Return reduces unpaid debt
- **WHEN** original total is Rp300,000, paid amount is Rp200,000, debt is Rp100,000, and a Rp50,000 return completes
- **THEN** the receipt shows total Rp250,000, paid Rp200,000, and debt Rp50,000
- **AND** return details show Rp50,000 debt reduction and zero cash refund

#### Scenario: Return exceeds unpaid debt
- **WHEN** original total is Rp300,000, paid amount is Rp200,000, debt is Rp100,000, and a Rp150,000 return completes
- **THEN** the receipt shows total Rp150,000, paid Rp150,000, and debt zero
- **AND** return details show Rp100,000 debt reduction and Rp50,000 cash refund

#### Scenario: Fully paid purchase partially refunded
- **WHEN** a fully paid Rp300,000 purchase has a completed Rp100,000 cash return
- **THEN** current total and paid amount are Rp200,000 and debt is zero
- **AND** return details show the actual Rp100,000 refund without displaying it as change

#### Scenario: Mapping or payment evidence cannot reconcile
- **WHEN** persisted records cannot prove current item, serial, or payment allocation
- **THEN** current receipt printing is refused with an actionable explanation and historical receipt access
- **AND** no successful print audit or financial mutation is recorded
