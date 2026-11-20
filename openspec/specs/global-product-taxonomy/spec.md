# Global Product Taxonomy

## Purpose

Provide shared product brands and hierarchical categories across businesses, with consistent identity and safe reconciliation of legacy duplicates.

## Requirements

### Requirement: Shared taxonomy independent of business ownership
Product brands and categories SHALL be available across businesses under existing taxonomy permissions. Their storage, selection, creation, and editing SHALL not depend on a business setting or its continued existence.

#### Scenario: Switching business preserves taxonomy selection
- **WHEN** an authorized user switches businesses and searches brands, categories, or category parents
- **THEN** the same shared records SHALL remain available, including the product's selected records

#### Scenario: Original business is removed
- **WHEN** the business formerly associated with a brand or category is removed
- **THEN** shared taxonomy records SHALL not be deleted through business ownership

#### Scenario: User lacks mutation permission
- **WHEN** a user without the existing create, edit, or delete permission attempts that taxonomy action
- **THEN** the action SHALL remain denied

### Requirement: Canonical brand and category identity
Brand identity SHALL use a normalized global name. Category identity SHALL use a normalized name within its canonical parent path, with roots sharing one root namespace. Normalization SHALL trim, collapse whitespace including Unicode spaces, and ignore letter case while preserving spelling and punctuation.

#### Scenario: Equivalent formatting
- **WHEN** names differ only in case or surrounding, repeated, or Unicode whitespace within the same namespace
- **THEN** they SHALL resolve to one identity

#### Scenario: Distinct paths and spellings
- **WHEN** category names have different parent paths or taxonomy names differ in spelling or punctuation
- **THEN** they SHALL remain distinct

### Requirement: Durable uniqueness across taxonomy writers
Forms, quick-add, and imports SHALL use the same identities with database-backed uniqueness under concurrent writes. Interactive duplicates SHALL produce validation errors; imports SHALL reuse the matching active record. Category moves SHALL preserve uniqueness and reject cycles.

#### Scenario: Concurrent creation
- **WHEN** competing requests create the same brand or category identity
- **THEN** at most one active record SHALL exist and each request SHALL reuse it or return a controlled duplicate error

#### Scenario: Import has an ambiguous category name
- **WHEN** a name-only import matches categories under multiple parent paths
- **THEN** the row SHALL report an actionable ambiguity without choosing an arbitrary category or creating a duplicate

#### Scenario: Category move conflicts
- **WHEN** a category move would create a cycle or duplicate a sibling identity
- **THEN** the move SHALL be rejected without modifying the hierarchy

### Requirement: Read-only duplicate reconciliation preview
The system SHALL provide a non-mutating preview of duplicate groups, canonical parents, survivor IDs, category codes, metadata conflicts, and product and parent reference counts. The survivor SHALL be the lowest existing active ID within each canonical identity. Invalid hierarchies and unsupported references SHALL block execution with actionable details.

#### Scenario: Legacy duplicates are previewed
- **WHEN** an operator previews reconciliation
- **THEN** the report SHALL show the proposed old-to-survivor mapping and affected reference counts without changing data

#### Scenario: Parent groups are duplicated
- **WHEN** duplicate parent categories contain equivalent child names
- **THEN** the preview SHALL evaluate child identities against the merged parent path

### Requirement: Atomic audited reference repair
Explicit execution of a current reviewed preview SHALL atomically remap products and category parents before removing redundant records. It SHALL preserve survivor metadata and category codes, record the old records and mapping durably, preserve null product relations, and leave products, stock, pricing, documents, and historical audit payloads otherwise unchanged.

#### Scenario: Reconciliation succeeds
- **WHEN** an operator executes an unchanged reviewed plan
- **THEN** all affected product and parent references SHALL point to survivors and no duplicate or orphan SHALL remain
- **AND** the audit SHALL record the operator, original metadata, survivor mapping, and changed reference counts

#### Scenario: Failure or stale preview
- **WHEN** execution fails or the reviewed mapping or reference state has changed
- **THEN** execution SHALL roll back all reconciliation mutations or reject the stale plan before mutation

#### Scenario: Repeated execution
- **WHEN** reconciliation is rerun after successful completion
- **THEN** it SHALL report no remaining work without changing references again

### Requirement: Deleted brand identities remain protected
Soft-deleted brands SHALL remain unavailable for normal selection. A deleted brand name SHALL not be silently recreated or restored by normal creation or import; it SHALL produce an actionable conflict. Reconciliation SHALL report deleted records separately and SHALL not automatically restore or merge them into active groups.

#### Scenario: Import encounters a deleted brand
- **WHEN** an import resolves a name reserved by a deleted brand
- **THEN** it SHALL report the conflict without creating or restoring a brand
