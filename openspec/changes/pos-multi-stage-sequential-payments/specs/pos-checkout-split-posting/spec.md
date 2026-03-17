## MODIFIED Requirements

### Requirement: Finalize SHALL post one sales bundle per split group
For each planned split group, the system SHALL create exactly one `sale`, one payment allocation record, and associated dispatch records in the same finalize operation, and SHALL post that bundle using the group `source_setting_id` as owner context.

**CHANGE SUMMARY**: Multi-stage payment flow alters payment submission from a single batch request to individual per-stage submissions. Each payment stage is committed and recorded independently during the checkout flow, before the final finalize call. The finalize operation now processes a pre-computed list of committed payments (from session state) rather than payments submitted inline.

#### Scenario: Posting split groups with pre-committed payments
- **WHEN** finalize is executed for a checkout where payments have been committed across multiple stages (e.g., BRI 1M, BNI 1M, CASH 950k) and stored in session
- **THEN** two split groups are created (if multi-source), finalize receives the pre-committed payment list, and each sale bundle is linked to the committed payments in order
- **AND** no payment re-posting occurs; finalize uses the pre-committed amounts directly

#### Scenario: Posting two split groups under different source owners (unchanged core behavior)
- **WHEN** finalize is executed for a checkout with two split groups owned by different `source_setting_id` values
- **THEN** two sales bundles are created and linked to the same checkout
- **AND** each created sale `setting_id` MUST equal its group `source_setting_id`
- **AND** inventory transactions created for each group line MUST use the same owner setting as that group.

#### Scenario: Owner-specific numbering follows source setting (unchanged core behavior)
- **WHEN** finalize posts split groups across multiple source settings
- **THEN** each sale reference MUST be generated from the owning group setting sequence/prefix rules
- **AND** no sale in the checkout MAY use another setting's numbering sequence.

### Requirement: Split posting MUST reconcile totals exactly
The system MUST ensure the sum of split-group `subtotal`, `tax_total`, `grand_total`, and `paid_total` equals the corresponding checkout totals using minor-unit-safe arithmetic. **When multi-stage payments are used, `paid_total` must equal the sum of all committed payment stages, NOT inline payment inputs.**

#### Scenario: Totals reconciliation after split posting with staged payments (new scenario)
- **WHEN** finalize completes with split posting enabled and checkout has pre-committed staged payments (remainder = 0)
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals
- **AND** `paid_total` of the sale equals the sum of all committed payment stages from session state

#### Scenario: Totals reconciliation after split posting (unchanged core behavior)
- **WHEN** finalize completes with split posting enabled
- **THEN** the aggregate totals from all split groups exactly equal the checkout totals
