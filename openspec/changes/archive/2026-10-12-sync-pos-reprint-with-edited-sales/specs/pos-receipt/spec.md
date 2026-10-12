# Spec Delta

## ADDED Requirements

### Requirement: Completed POS reprints show current Sale-derived prices by receipt line
When a completed POS receipt is reprinted after generated Sale monetary values change, the system SHALL show each customer-facing line's current amount from its associated authoritative Sale details and SHALL show the current transaction total equal to the sum of reachable generated Sale totals. Bundle components SHALL remain included beneath one priced parent bundle line and their nested allocation snapshots MUST NOT be billed again. Product identity, quantities, composition, serials, and receipt layout SHALL retain their existing presentation. The current monetary projection SHALL NOT overwrite persisted checkout snapshots.

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
- **AND** unchanged product, quantity, composition, and checkout tender facts remain intact.

### Requirement: Historical reprints require proven monetary line mapping
For a completed checkout posted before direct POS-line lineage existed, the system SHALL use current Sale amounts for a reprint only when every Sale detail can be assigned to the correct customer-facing POS line without ambiguity. A single-line checkout with complete, valid generated-Sale mapping SHALL be treated as unambiguous. For an ambiguous multi-line checkout, the system MUST NOT guess using product identity or ordering, MUST NOT print an updated receipt with an incorrect line amount, and SHALL explain why an updated-price reprint is unavailable while allowing the original checkout receipt to remain accessible.

#### Scenario: Historical single-line split bundle
- **WHEN** an older completed checkout contains one POS line and all generated Sales resolve consistently
- **THEN** its current generated-Sale total can be shown on that one reprinted line.

#### Scenario: Historical repeated bundle is ambiguous
- **WHEN** an older checkout contains repeated or shared bundle products and persisted records do not prove each Sale detail's POS line
- **THEN** the updated-price reprint is refused with an actionable explanation
- **AND** the operator can still access the original checkout receipt without fabricated current line prices.
