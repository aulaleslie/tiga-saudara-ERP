# global-supplier-identity Specification

## Purpose
Establish suppliers as a single global identity: `setting_id` on a supplier record is historical provenance only and never scopes or restricts supplier search, matching, selection, or duplicate detection.

## Requirements
### Requirement: Supplier setting_id is provenance-only
The system SHALL treat `suppliers.setting_id` as historical provenance (which setting's action first created the supplier record) and MUST NOT use it to filter, restrict, or scope supplier search, matching, selection, or duplicate detection.

#### Scenario: Supplier from another setting is searchable
- **WHEN** an existing supplier has a `setting_id` different from the active setting
- **THEN** authorized supplier search and browse surfaces SHALL include that supplier in results

#### Scenario: Supplier from another setting is selectable
- **WHEN** an existing supplier has a `setting_id` different from the active setting
- **THEN** authorized Purchase, Expense, and Supplier surfaces SHALL permit that supplier to be found and selected by id

#### Scenario: Supplier without a setting is selectable
- **WHEN** an existing supplier has a null `setting_id`
- **THEN** authorized supplier search, selection, and matching surfaces SHALL permit that supplier to be found and selected

### Requirement: Global supplier does not change transaction ownership
Selecting or matching a supplier record regardless of its `setting_id` SHALL NOT alter the setting ownership of the transaction (Purchase, Expense) referencing it.

#### Scenario: Global supplier does not change transaction ownership
- **WHEN** a supplier from another or no setting is selected or matched for a setting-owned Purchase or Expense
- **THEN** the transaction SHALL retain its independently resolved setting ownership

### Requirement: Import supplier matching is global
`PurchaseImportService::findOrCreateSupplier` and `ExpenseImportService::findOrCreateSupplier` SHALL match an existing supplier by `supplier_name` (case-insensitive, trimmed) regardless of `setting_id`, creating a new supplier record only when no existing match is found across all settings.

#### Scenario: Import matches existing supplier from a different setting
- **WHEN** a purchase or expense import references a supplier name that already exists as a supplier record created under a different setting
- **THEN** the import SHALL match and use that existing supplier record
- **AND** SHALL NOT create a new supplier record

#### Scenario: Import creates a new supplier when no match exists in any setting
- **WHEN** a purchase or expense import references a supplier name with no existing match in any setting
- **THEN** the import SHALL create a new supplier record
- **AND** the new record's `setting_id` SHALL reflect the importing setting as provenance only
