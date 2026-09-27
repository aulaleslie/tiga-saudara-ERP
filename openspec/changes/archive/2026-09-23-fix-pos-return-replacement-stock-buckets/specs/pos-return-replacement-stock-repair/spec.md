# Spec Delta

## Purpose

Provide a guarded and auditable repair mechanism for historical stock-bucket drift conclusively caused by POS Return replacement dispatch while leaving unrelated inventory discrepancies unchanged.

## ADDED Requirements

### Requirement: Repair discovery SHALL require conclusive POS Return replacement evidence
The repair mechanism SHALL classify a stock row as repairable only when completed POS Return product-replacement lineage, linked Sale Return detail, replacement dispatch, and outbound `DISPATCH_RETURN` transaction prove that aggregate stock was decremented without the replacement owner's required PKP or non-PKP bucket decrement. Generic aggregate/bucket or stock/serial mismatch alone SHALL NOT make a row repairable.

#### Scenario: Confirmed same-owner affected row
- **WHEN** historical same-owner replacement records conclusively prove one or more missed owner-bucket decrements for a stock row
- **THEN** dry-run classifies the row as repairable and reports every supporting POS Return, Sale Return, dispatch, transaction, and serial identifier

#### Scenario: Confirmed cross-owner affected row
- **WHEN** historical cross-owner replacement records prove that stock left the replacement owner while its PKP/non-PKP bucket was not decremented
- **THEN** dry-run classifies the replacement owner's stock row as repairable using that owner's `is_pkp` value

#### Scenario: Unrelated serialized discrepancy
- **WHEN** a serialized stock row differs from its sellable serial count but lacks conclusive faulty replacement-dispatch lineage
- **THEN** the mechanism reports or excludes the discrepancy as non-repairable
- **AND** does not mutate it

### Requirement: Repair SHALL be dry-run-first and narrowly scoped
The repair command SHALL default to dry-run and SHALL require an explicit apply option before changing data. It SHALL propose corrections only for conclusively affected rows; for the currently investigated dataset these are product 182/location 6 and product 4391/location 2, while product 4014/location 6 is explicitly unrelated and SHALL remain unchanged.

#### Scenario: Default invocation
- **WHEN** an operator invokes the command without the apply option
- **THEN** it prints current values, expected values, deltas, classification, and supporting evidence
- **AND** performs no database mutation

#### Scenario: Unrelated row remains unchanged during apply
- **WHEN** apply runs while an unrelated stock or serial discrepancy exists
- **THEN** that row is not updated and receives no corrective audit record

### Requirement: Repair apply SHALL be concurrent-safe and auditable
For each repairable row, apply SHALL lock the affected stock row, re-read the evidence and current values, and mutate only when they still match the validated repair plan. It SHALL record explicit corrective transaction or audit evidence containing the before and after stock values and the faulty source record identifiers. Existing POS Returns, Sales Returns, dispatches, transactions, and serial histories SHALL remain immutable.

#### Scenario: Validated row is repaired
- **WHEN** an operator applies a repair and the locked row and evidence still match the dry-run expectations
- **THEN** only the proven incorrect aggregate and owner-bucket values are corrected
- **AND** an explicit corrective audit record is created

#### Scenario: Data changes after dry-run
- **WHEN** a candidate stock value or supporting record changes before apply obtains its lock
- **THEN** apply skips or aborts that candidate with a conflict result
- **AND** does not overwrite the newer data

### Requirement: Repair SHALL be idempotent
The repair mechanism SHALL use stable evidence and repair identity so that an already corrected faulty dispatch contribution cannot be applied again.

#### Scenario: Repair is run twice
- **WHEN** an operator applies the repair successfully and then runs apply again
- **THEN** the second run makes no stock changes
- **AND** creates no duplicate corrective audit record

