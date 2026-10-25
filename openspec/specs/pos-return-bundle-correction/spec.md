# POS Return Bundle Correction Specification

## Purpose

Provide a guarded, auditable way to complete an omitted component reversal from a historical whole-bundle return without replaying completed effects.

## Requirements

### Requirement: Correction requires specific evidence and explicit whole-bundle intent
The correction SHALL target the reported transaction and require original bundle composition, completed parent reversal, omitted component allocation and dispatch, absence of an existing reversal, and recorded confirmation that the component was physically returned. Missing or conflicting evidence MUST block application. This administrative correction MUST NOT expose an individual-component customer return option.

#### Scenario: Reported omitted component is confirmed
- **WHEN** correction inspects TNC-TXN-2026-10-0638 with confirmed whole-bundle physical return
- **THEN** it identifies the omitted microSD from original checkout, sale, bundle-item, and dispatch records
- **AND** links its corrective reversal to TNC-POSRT-2610-0005

#### Scenario: Evidence or return intent is uncertain
- **WHEN** physical return is unconfirmed or source evidence is inconsistent
- **THEN** correction refuses to apply and reports the missing evidence

### Requirement: Correction defaults to dry run and records its effects
The correction SHALL default to a read-only report of source identities, quantities, stock effects, financial effects, and before/after debt. Apply MUST require explicit target, actor, reason, and physical-return confirmation. It MUST preserve completed camera-return evidence and append linked corrective records for the omitted component.

#### Scenario: Default report
- **WHEN** an operator runs the correction without apply
- **THEN** it reports one microSD reversal and Rp260,000 debt reduction for the unchanged reported case
- **AND** no data changes

#### Scenario: Apply the confirmed correction
- **WHEN** an authorized operator explicitly applies the validated correction
- **THEN** one microSD is received into its original owner's appropriate stock bucket and its active dispatched quantity and commercial allocation are reduced
- **AND** the component sale debt becomes zero with no cash payout in the unchanged unpaid case
- **AND** linked corrective evidence records actor, reason, source IDs, before/after values, and the original return
- **AND** the original camera reversal is not replayed

### Requirement: Correction is atomic, concurrent-safe, and idempotent
Apply SHALL lock affected source and stock/settlement records, revalidate the reported plan, and use a stable correction identity. Changed evidence MUST cause a conflict without mutation. Failure MUST roll back every effect; repeat application MUST create no duplicate receiving, dispatch reduction, stock, cost, settlement, or audit records.

#### Scenario: Correction is repeated
- **WHEN** the same correction is applied after successful completion
- **THEN** it reports already corrected without further effects

#### Scenario: Concurrent source change or execution failure
- **WHEN** evidence changes after dry run or a correction step fails
- **THEN** apply aborts or rolls back without partial effects
