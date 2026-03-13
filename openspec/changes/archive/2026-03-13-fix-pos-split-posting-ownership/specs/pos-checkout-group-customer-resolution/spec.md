## ADDED Requirements

### Requirement: Split posting SHALL resolve customer per source-setting group deterministically
For each split group finalize posts, the system SHALL resolve `customer_id` using this precedence: selected checkout customer when it exists in the group source setting, otherwise source setting walk-in customer when configured.

#### Scenario: Selected customer exists in source setting
- **WHEN** finalize posts a split group whose selected checkout customer belongs to that group `source_setting_id`
- **THEN** the group posting context MUST use the selected checkout customer as `customer_id`.

#### Scenario: Source walk-in fallback is used
- **WHEN** finalize posts a split group whose selected checkout customer does not exist in that group `source_setting_id`
- **AND** the source setting has `pos_walk_in_customer_id` configured to a valid customer in the same setting
- **THEN** the group posting context MUST use that source walk-in customer as `customer_id`.

### Requirement: Unresolvable source-group customer MUST fail finalize with actionable diagnostics
If neither the selected checkout customer nor source walk-in customer is valid for a split group source setting, finalize MUST fail with `CUSTOMER_UNRESOLVED` and machine-readable source-resolution details.

#### Scenario: Source customer cannot be resolved
- **WHEN** a split group is posted and the selected checkout customer is not present in that group `source_setting_id`
- **AND** the source setting has no valid walk-in customer
- **THEN** finalize MUST fail with `error_code=CUSTOMER_UNRESOLVED`
- **AND** failure details MUST include `reason_code=SOURCE_CUSTOMER_UNRESOLVED`, `source_setting_id`, `terminal_setting_id`, and `selected_customer_id`.
