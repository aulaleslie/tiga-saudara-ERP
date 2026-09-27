## ADDED Requirements

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
