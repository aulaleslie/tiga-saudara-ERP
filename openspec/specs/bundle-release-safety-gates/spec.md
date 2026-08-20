## ADDED Requirements

### Requirement: Bundle release uses focused explainable regression gates
Production bundle enablement SHALL be gated by focused automated verification of bundle definition, pricing, snapshot identity, Normal Sales persistence, POS split ownership, dispatch and serial lineage, HPP and reporting, return and replacement lifecycle, idempotency, and additive migration compatibility. The gate MUST NOT accept an unexplained failing test as baseline noise.

#### Scenario: Focused gate passes
- **WHEN** every focused bundle release test and migration check passes
- **THEN** the bundle release SHALL be classified as technically eligible for controlled production enablement
- **AND** the verification record SHALL identify the exact commands and covered boundaries

#### Scenario: Gate encounters a failure
- **WHEN** a focused release test fails
- **THEN** the failure SHALL be reproduced independently and classified as an implementation defect, test-fixture defect, assertion defect, environmental issue, or confirmed flaky test
- **AND** an implementation defect or unexplained failure SHALL block production enablement

### Requirement: Release tests reach their intended assertions
Feature-test fixtures SHALL seed every permission and prerequisite exercised by the rendered route so unrelated authorization exceptions do not prevent the test from checking bundle, Sale, serial, return, or reporting behavior.

#### Scenario: Sale serial badge tests render the detail page
- **WHEN** Sale serial-lineage feature tests request the Sale detail route
- **THEN** their fixture SHALL include the reporting-date permission record evaluated by the view policy
- **AND** the tests SHALL reach their red/blue returned-versus-active serial assertions

### Requirement: Release assertions compare persisted domain values
Release tests SHALL compare dates, decimals, statuses, identifiers, and other persisted values by their domain representation rather than PHP object identity unless identity itself is the behavior under test.

#### Scenario: Replacement Sale date is compared
- **WHEN** a cross-owner replacement Sale is expected to preserve the original Sale calendar date
- **THEN** the test SHALL compare normalized persisted date values
- **AND** it SHALL NOT require two separately hydrated date objects to be the same object instance

### Requirement: Release verification remains focused
The bundle release gate SHALL run only tests and migration checks that exercise changed or high-risk bundle integration boundaries. Full unrelated module or application suites SHALL remain optional diagnostic evidence and MUST NOT be added as required release tasks for this change.

#### Scenario: Focused verification is executed
- **WHEN** the release-safety change is verified
- **THEN** the commands SHALL target the enumerated bundle, HPP, report, return, replacement, serial, and migration test files or filters
- **AND** unrelated pre-existing suite failures SHALL not obscure the focused gate result

