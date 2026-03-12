# pos-checkout-split-posting Specification

## Purpose
TBD - created by archiving change implement-pos-phase-3-split-posting. Update Purpose after archive.
## Requirements
### Requirement: Checkout split groups SHALL be derived by source and tax bucket
The system SHALL derive split groups for finalized POS checkout lines using key `source_setting_id + source_location_id + tax_bucket` before posting any sale documents.

#### Scenario: Grouping mixed-source checkout lines
- **WHEN** a checkout contains lines resolved to multiple source setting/location combinations and mixed tax outcomes
- **THEN** the split planner produces one group per unique `source_setting_id + source_location_id + tax_bucket`

### Requirement: Finalize SHALL post one sales bundle per split group
For each planned split group, the system SHALL create exactly one `sale`, one payment allocation record, and associated dispatch records in the same finalize operation.

#### Scenario: Posting two split groups
- **WHEN** finalize is executed for a checkout with two split groups
- **THEN** two sales bundles are created and linked to the same checkout

### Requirement: Split posting MUST reconcile totals exactly
The system MUST ensure the sum of split-group `subtotal`, `tax_total`, `grand_total`, and `paid_total` equals the corresponding checkout totals using minor-unit-safe arithmetic.

#### Scenario: Totals reconciliation after split posting
- **WHEN** finalize completes with split posting enabled
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals

### Requirement: Tax fallback SHALL be applied for split tax bucket resolution
When a taxable line has no resolved tax from source context, the system SHALL apply fallback policy in order: default tax first, otherwise latest active tax.

#### Scenario: Tax fallback on taxable line without explicit tax
- **WHEN** a taxable line is planned and no source tax is resolvable
- **THEN** the planner assigns tax bucket from default tax or latest active tax according to policy order

