## ADDED Requirements

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
