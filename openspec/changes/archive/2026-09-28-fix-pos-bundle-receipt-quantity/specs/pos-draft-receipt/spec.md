# Spec Delta

## ADDED Requirements

### Requirement: Draft receipts SHALL display total bundle component quantities
A draft or loaded-transaction receipt SHALL derive each bundle component's displayed total quantity from the parent line quantity and the snapshotted component quantity per bundle.

#### Scenario: Draft receipt scales bundle composition
- **WHEN** a draft or loaded transaction contains a parent bundle line with quantity 2 and a snapshotted component quantity per bundle of 1
- **THEN** the receipt SHALL display the component quantity as `x2`

#### Scenario: Draft receipt scales a multi-unit component
- **WHEN** a draft or loaded transaction contains a parent bundle line with quantity 2 and a snapshotted component quantity per bundle of 2
- **THEN** the receipt SHALL display the component quantity as `x4`

