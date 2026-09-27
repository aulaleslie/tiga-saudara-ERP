## MODIFIED Requirements

### Requirement: Finalize SHALL post one sales bundle per split group
For each planned split group, the system SHALL create exactly one `sale` and associated dispatch records in the same finalize operation, and SHALL post that bundle using the group `source_setting_id` as owner context. Payment posting for each group SHALL support one or more allocated sale-payment rows derived from checkout multi-payment composition.

#### Scenario: Posting two split groups under different source owners
- **WHEN** finalize is executed for a checkout with two split groups owned by different `source_setting_id` values
- **THEN** two sales bundles are created and linked to the same checkout
- **AND** each created sale `setting_id` MUST equal its group `source_setting_id`
- **AND** inventory transactions created for each group line MUST use the same owner setting as that group.

#### Scenario: Owner-specific numbering follows source setting
- **WHEN** finalize posts split groups across multiple source settings
- **THEN** each sale reference MUST be generated from the owning group setting sequence/prefix rules
- **AND** no sale in the checkout MAY use another setting's numbering sequence.

#### Scenario: Mixed-method tender creates per-group payment rows
- **WHEN** checkout uses multiple payment rows and split posting produces multiple groups
- **THEN** each split group receives one or more payment allocations mapped from checkout payment rows
- **AND** sum of payment allocations for a split group equals that split group's `grand_total`.

### Requirement: Split posting MUST reconcile totals exactly
The system MUST ensure the sum of split-group `subtotal`, `tax_total`, `grand_total`, and allocated payments equals checkout totals using minor-unit-safe arithmetic.

#### Scenario: Totals reconciliation after split posting
- **WHEN** finalize completes with split posting enabled
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals
- **AND** aggregate payment allocations across all groups exactly equal checkout grand total.

#### Scenario: Multi-payment row reconciliation
- **WHEN** finalize receives multiple payment rows
- **THEN** each payment row's allocated amounts across split groups equals that payment row amount exactly
- **AND** no residual amount remains unallocated after reconciliation.

## ADDED Requirements

### Requirement: Split posting SHALL preserve method-level allocation traceability
The system SHALL persist method-level allocation traceability from checkout payment rows to split groups so downstream systems can resolve how each method funded each owner group.

#### Scenario: Method-level trace is available for a posted checkout
- **WHEN** a split checkout is successfully posted
- **THEN** the system stores allocation records containing at least split-group key, payment-row identity, method identity, and allocated amount
- **AND** these records are queryable for reporting and replay serialization.
