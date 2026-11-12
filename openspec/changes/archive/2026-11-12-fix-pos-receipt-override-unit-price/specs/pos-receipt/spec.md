# Spec Delta

## ADDED Requirements

### Requirement: Overridden receipt unit prices match their quantity basis
Receipts SHALL display authoritative overridden unit prices in the unit basis of the recorded quantity, without dividing by a retained packing factor. Conversion-capable base-quantity overrides MUST use the recorded base-unit label when available. This SHALL apply to draft/loaded previews, checkout snapshots, historical receipts, and completed print/reprints while preserving current Sale price authority and existing totals.

#### Scenario: Base-unit override retains a pack conversion
- **WHEN** a row records 20 base units at overridden Rp1,750, total Rp35,000, a retained pack factor of 10, and base label PCS
- **THEN** its receipt breakdown displays 20 PCS at Rp1,750 using existing currency styling
- **AND** it displays neither Rp175 nor PACK as the pricing unit
- **AND** the row total remains Rp35,000

#### Scenario: Receipt entry points preserve the same unit basis
- **WHEN** an overridden row is rendered in a draft/loaded preview, original checkout snapshot, explicit historical receipt, or ordinary completed print/reprint
- **THEN** the displayed quantity, unit label, and unit price share the recorded quantity basis
- **AND** ordinary completed receipts retain proven current Sale prices while historical receipts retain original snapshot prices
- **AND** receipt generation does not rewrite persisted prices or financial amounts

#### Scenario: Packed pricing remains unchanged
- **WHEN** a row retains ordinary packed pricing rather than an authoritative unit-price override
- **THEN** its receipt retains the existing whole-pack and loose-unit quantities, snapshotted labels, and applied prices
- **AND** its stored subtotal and receipt total remain unchanged
