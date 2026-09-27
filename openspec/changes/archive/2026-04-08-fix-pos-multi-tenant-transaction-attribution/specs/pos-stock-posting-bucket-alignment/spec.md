## ADDED Requirements

### Requirement: Localized Stock Mutations
Stock mutations (inventory history logs) must be attributed to the setting that actually owns the stock physically located in the source warehouse.

#### Scenario: Correct Mutation Attribution
- **WHEN** Stock from Setting B is sold at a terminal in Setting A.
- **THEN** The `Transaction` mutation record must have `setting_id = Setting B`.
- **THEN** The mutation log for Setting A should show no deduction for that specific stock chunk.
