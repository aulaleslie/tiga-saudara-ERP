## ADDED Requirements

### Requirement: Manual unit price edit updates the cart's canonical unit price
When a user edits a Purchase cart row's unit price, the cart SHALL update every stored representation of that row's unit price — including the top-level price, `entered_unit_price`, `canonical_unit_price`, and `options.unit_price` — to the newly entered value (or its correctly-converted canonical equivalent). No stale unit-price value from before the edit SHALL be retained anywhere on the cart row.

#### Scenario: User changes unit price on a base-unit row
- **WHEN** a user edits a Purchase cart row's unit price from Rp290.000 to Rp270.000 for a row with a 1:1 base-unit conversion
- **THEN** the cart row's price, `entered_unit_price`, `canonical_unit_price`, and `options.unit_price` SHALL all reflect Rp270.000
- **AND** the row's `sub_total` SHALL equal Rp270.000 multiplied by the row quantity (subject to existing discount/tax rules)

#### Scenario: User changes unit price on a converted-unit row
- **WHEN** a user edits a Purchase cart row's unit price for a row purchased in a non-base unit with a conversion factor greater than 1
- **THEN** the cart row's canonical (base-unit) unit price SHALL be recomputed from the newly entered price divided by the conversion factor
- **AND** no field on the row SHALL retain the canonical unit price computed from the pre-edit entered price

### Requirement: Purchase normalization trusts the cart's authoritative price over a cached option
When normalizing a Purchase cart row into a persisted `purchase_details` record, the system SHALL derive the canonical unit price from the cart row's authoritative top-level price/entered price, not from a `unit_price` cache value in the row's options that may predate the user's latest edit.

#### Scenario: Normalizer resolves price consistently with a freshly edited row
- **WHEN** a Purchase cart row's top-level price and `options.unit_price` disagree (e.g., due to a prior defect or a client-supplied cart payload)
- **THEN** the normalizer SHALL use the top-level price (or `entered_unit_price` when present) as authoritative when computing the persisted `unit_price` and `price`

### Requirement: Persisted unit price stays consistent with the persisted row subtotal
For a Purchase detail row created or updated through a manual unit-price edit, the persisted `unit_price`/`price` SHALL be consistent with the persisted `sub_total`: recomputing `sub_total` from the stored `unit_price`, `quantity`, `product_discount_amount`, and applicable tax SHALL reproduce the stored `sub_total` within normal two-decimal rounding tolerance.

#### Scenario: Save and reload shows a consistent unit price
- **WHEN** a user saves a Purchase after manually editing a row's unit price and later reopens that Purchase for viewing or editing
- **THEN** the displayed unit price multiplied by quantity (adjusted for any discount and tax) SHALL equal the displayed row total
