# pos-stock-posting-bucket-alignment Specification

## Purpose
TBD - created by archiving change fix-pos-stock-resolver-tax-bucket. Update Purpose after archive.
## Requirements
### Requirement: Posting adapter uses tax_bucket_used for stock decrement
The posting adapter SHALL use the `tax_bucket_used` boolean flag from each allocation chunk to determine which stock bucket to decrement. It SHALL NOT re-derive the bucket decision from `source_is_pkp` or `tax_policy_snapshot.tax_id`.

#### Scenario: Allocation with tax_bucket_used=true
- **WHEN** the posting adapter processes an allocation chunk with `tax_bucket_used=true` and `allocated_qty=3`
- **THEN** it SHALL decrement `product_stocks.quantity_tax` by 3 at the chunk's `source_location_id`
- **AND** it SHALL decrement `product_stocks.quantity` by 3

#### Scenario: Allocation with tax_bucket_used=false
- **WHEN** the posting adapter processes an allocation chunk with `tax_bucket_used=false` and `allocated_qty=2`
- **THEN** it SHALL decrement `product_stocks.quantity_non_tax` by 2 at the chunk's `source_location_id`
- **AND** it SHALL decrement `product_stocks.quantity` by 2

#### Scenario: Mixed allocations for same product across locations
- **WHEN** a line has two allocation chunks: one with `tax_bucket_used=true` at location 1 (qty=1) and one with `tax_bucket_used=false` at location 2 (qty=1)
- **THEN** the adapter SHALL decrement `quantity_tax` by 1 at location 1
- **AND** the adapter SHALL decrement `quantity_non_tax` by 1 at location 2

### Requirement: Transaction record reflects actual bucket decremented
The `Transaction` record created during stock decrement SHALL record `quantity_tax` and `quantity_non_tax` fields matching the actual bucket decremented, consistent with the `tax_bucket_used` flag.

#### Scenario: Transaction record for tax bucket decrement
- **WHEN** an allocation chunk with `tax_bucket_used=true` is posted with `allocated_qty=2`
- **THEN** the Transaction record SHALL have `quantity_tax=2` and `quantity_non_tax=0`

#### Scenario: Transaction record for non-tax bucket decrement
- **WHEN** an allocation chunk with `tax_bucket_used=false` is posted with `allocated_qty=3`
- **THEN** the Transaction record SHALL have `quantity_tax=0` and `quantity_non_tax=3`

### Requirement: Posting adapter inline stock validation matches resolver bucket
The posting adapter's inline stock validation (before decrement) SHALL check the same bucket as indicated by `tax_bucket_used`, not a re-derived bucket.

#### Scenario: Non-tax allocation at non-PKP source validated correctly
- **WHEN** the posting adapter validates an allocation chunk with `tax_bucket_used=false` at a location where `quantity_non_tax >= allocated_qty`
- **THEN** validation SHALL pass, even if `quantity_tax < allocated_qty` at that location

#### Scenario: Tax allocation at PKP source validated correctly
- **WHEN** the posting adapter validates an allocation chunk with `tax_bucket_used=true` at a location where `quantity_tax >= allocated_qty`
- **THEN** validation SHALL pass, even if `quantity_non_tax < allocated_qty` at that location

### Requirement: Localized Stock Mutations
Stock mutations (inventory history logs) must be attributed to the setting that actually owns the stock physically located in the source warehouse.

#### Scenario: Correct Mutation Attribution
- **WHEN** Stock from Setting B is sold at a terminal in Setting A.
- **THEN** The `Transaction` mutation record must have `setting_id = Setting B`.
- **THEN** The mutation log for Setting A should show no deduction for that specific stock chunk.

### Requirement: Split grouped allocations SHALL preserve resolver bucket decisions
When checkout split planning transforms resolver allocations into grouped posting allocations, the grouped allocations SHALL preserve the resolver-selected `tax_bucket_used` value, source location, source setting, and tax policy snapshot. Posting MUST use those grouped allocation values without re-deriving the stock bucket.

#### Scenario: Serial parent grouped allocation preserves tax bucket
- **WHEN** a serial-tracked parent allocation from the resolver uses the tax stock bucket
- **THEN** the grouped parent allocation passed to posting has `tax_bucket_used=true`
- **AND** posting decrements `product_stocks.quantity_tax` and total `quantity` at the allocation source location

#### Scenario: Bundle child grouped allocation preserves non-tax bucket
- **WHEN** a bundle child allocation from the resolver uses the non-tax stock bucket
- **THEN** the grouped child allocation passed to posting has `tax_bucket_used=false`
- **AND** posting decrements `product_stocks.quantity_non_tax` and total `quantity` at the allocation source location

#### Scenario: Mixed bucket grouped allocations keep independent transaction records
- **WHEN** one POS checkout posts grouped allocations containing both tax and non-tax chunks
- **THEN** each stock transaction records `quantity_tax` and `quantity_non_tax` according to the chunk's `tax_bucket_used` value
- **AND** each transaction `setting_id` matches the allocation source setting


