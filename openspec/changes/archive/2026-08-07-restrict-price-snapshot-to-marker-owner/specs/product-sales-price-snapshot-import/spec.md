## Purpose

Enforce owner-aware pricing for Sales Price & Stock Snapshot imports, ensuring each row's price tiers update only the resolved owner setting and preserving DAIZU precedence in ownership resolution.

## Requirements

## ADDED Requirements

### Requirement: Snapshot price tiers are limited to the resolved owner
For each successfully resolved Sales Price & Stock Snapshot row, the system SHALL update or create exactly one `product_prices` record: the record for the row's marker-resolved owner setting. It SHALL set that record's `sale_price`, `tier_1_price`, and `tier_2_price` to the positive imported `SellPrice` value and SHALL NOT create, update, seed, or backfill a `product_prices` record for any non-owner setting as a side effect of the row.

#### Scenario: Asterisk marker updates only CV Tiga Nusa tiers
- **WHEN** an existing product is imported with a leading `*` name marker and a positive SellPrice
- **THEN** the system SHALL update all three selling tiers only for CV Tiga Nusa Computer
- **AND** it SHALL leave every non-owner setting's existing tiers unchanged and SHALL not create an absent non-owner price row

#### Scenario: TP marker updates only CV Top IT tiers
- **WHEN** an existing product is imported with a trailing ` TP` name marker and a positive SellPrice
- **THEN** the system SHALL update all three selling tiers only for CV Top IT Internusa
- **AND** it SHALL leave every non-owner setting's existing tiers unchanged and SHALL not create an absent non-owner price row

#### Scenario: Unmarked row updates only Perdana tiers
- **WHEN** an existing product is imported without an owner marker and with a positive SellPrice
- **THEN** the system SHALL update all three selling tiers only for Perdana
- **AND** it SHALL leave every non-owner setting's existing tiers unchanged and SHALL not create an absent non-owner price row

### Requirement: DAIZU owner precedence remains intact for snapshot prices
The Sales Price & Stock Snapshot importer SHALL continue to resolve product names containing the configured DAIZU keywords KEDELE, KEDELAI, or RAGI to the DAIZU setting before evaluating a leading `*`, trailing ` TP`, or the Perdana fallback.

#### Scenario: DAIZU keyword overrides a marker for all selling tiers
- **WHEN** an existing snapshot row contains a DAIZU keyword and also carries a leading `*` or trailing ` TP` marker
- **THEN** the system SHALL update all three selling tiers only for DAIZU
- **AND** it SHALL not update or create a price row for the marker-resolved or fallback owner
