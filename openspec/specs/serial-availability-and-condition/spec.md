# serial-availability-and-condition Specification

## Purpose
Define one canonical separation between serial lifecycle status and physical condition, and provide reusable query scopes and presentation rules so that all downstream product, report, autocomplete, transfer, and POS consumers agree on which serials are available, sellable, or available-broken.

## Requirements
### Requirement: Serial lifecycle and physical condition SHALL be evaluated separately
The system SHALL treat serial lifecycle status and physical condition as separate dimensions. `ACTIVE` with `is_broken=false` SHALL represent available sellable inventory, `ACTIVE` with `is_broken=true` SHALL represent available broken inventory, and `MISSING` SHALL represent unavailable inventory whose retained location is provenance only. New physical-condition changes SHALL use `is_broken` without changing an otherwise available serial away from `ACTIVE`.

#### Scenario: Active good serial is sellable
- **WHEN** an undispatched serial has `status=ACTIVE`, `is_broken=false`, and is not in a return process
- **THEN** the system classifies it as available and sellable

#### Scenario: Active broken serial remains inventory but is not sellable
- **WHEN** an undispatched serial has `status=ACTIVE`, `is_broken=true`, and is not in a return process
- **THEN** the system classifies it as available broken inventory
- **AND** the system does not classify or label it as sellable

#### Scenario: Missing serial retains provenance only
- **WHEN** a serial has `status=MISSING` and still has a location ID
- **THEN** the system excludes it from available, sellable, and available-broken inventory
- **AND** the retained location is used only for history and provenance

### Requirement: Shared serial availability scopes SHALL govern operational queries
`ProductSerialNumber` SHALL provide composable query scopes for operational availability, sellable availability, and available-broken inventory. Operational selectors SHALL use these scopes instead of predicates that merely exclude `RETURNED`.

#### Scenario: Known unavailable lifecycle states are excluded
- **WHEN** serial rows have `MISSING`, `SOLD`, `RETURNED`, or `RETURN_IN_PROCESS` status
- **THEN** none are returned by sellable or available-broken scopes

#### Scenario: Dispatched or returning active serial is excluded
- **WHEN** an `ACTIVE` serial has a dispatch detail or is marked in a return process
- **THEN** it is excluded from operational availability regardless of condition

#### Scenario: Nullable legacy status is active-compatible
- **WHEN** an otherwise undispatched, non-returning serial has a null status
- **THEN** it is treated as active-compatible and classified by `is_broken`

#### Scenario: Legacy broken status is compatible with broken inventory
- **WHEN** an otherwise undispatched, non-returning serial has `status=BROKEN`
- **THEN** it may appear as available broken inventory
- **AND** it never appears as sellable inventory

### Requirement: Product Detail SHALL present combined serial state in Bahasa Indonesia
Product Detail SHALL use the canonical scopes and display lifecycle plus condition with unambiguous Bahasa Indonesia labels. It SHALL provide explicit visibility for sellable, broken, missing, returning, and historical unavailable serials without including unavailable rows in operational counts.

#### Scenario: Product Detail shows correct current categories
- **WHEN** a product has five active-good serials, one active-broken serial, and three missing serials at the active business
- **THEN** Product Detail shows five under `Siap Jual`, one under `Rusak`, and three under an explicit `Hilang` or unavailable-history view

#### Scenario: Broken active serial has a combined label
- **WHEN** Product Detail renders a serial with `status=ACTIVE` and `is_broken=true`
- **THEN** it displays `Tersedia — Rusak` or an equivalent Bahasa Indonesia combined label
- **AND** it does not present the serial as ready for sale

#### Scenario: Missing serial remains auditable
- **WHEN** a user opens the missing or historical serial view
- **THEN** the serial number, `Hilang — Tidak Tersedia` state, and last-known location are visible according to existing product permissions

### Requirement: Operational serial consumers SHALL declare availability intent
Serial autocomplete, scanner, transfer, sale, and other operational consumers SHALL select an explicit sellable or available-broken rule. History, return, and recovery consumers SHALL retain explicit workflow-specific state rules and SHALL NOT be unintentionally restricted by a sale-only scope.

#### Scenario: Good-stock selector excludes broken and missing serials
- **WHEN** an operational selector requests good stock
- **THEN** it returns only sellable serials and excludes active-broken and missing serials

#### Scenario: Broken-stock selector excludes missing serials
- **WHEN** an operational selector explicitly requests broken stock
- **THEN** it returns available-broken serials and excludes missing or otherwise unavailable serials

#### Scenario: Audit lookup retains unavailable visibility
- **WHEN** a history or audit screen searches for a missing or sold serial
- **THEN** it can still find the serial without treating it as operationally available
