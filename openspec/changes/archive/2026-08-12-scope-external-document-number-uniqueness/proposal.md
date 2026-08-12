## Why

Archived Purchase documents currently continue to reserve a supplier-provided purchase number in create, edit, and document-level correction flows. This prevents legitimate reuse after an archived document is no longer operational. Sales already have one external customer invoice number, but its implementation name obscures that it is the canonical customer sales number.

## What Changes

- Scope supplier purchase number duplicate prevention to unarchived Purchases in the same business across supported create, edit, and document-level correction entry points.
- Retain duplicate prevention for active documents and preserve each edit flow's existing authorization and lifecycle restrictions.
- Define `sales.imported_sales_reference_number` as the single canonical external customer sales number/invoice number; do not introduce a second customer-sales-number field.
- Display the canonical external customer sales number consistently on Sale detail views when it is present.
- Preserve import duplicate detection semantics: archived Sales and Purchases do not block reuse of their external document numbers.

## Capabilities

### New Capabilities

- `external-document-number-governance`: Governs active-document uniqueness and canonical presentation of supplier and customer external transaction numbers.

### Modified Capabilities

<!-- None. -->

## Impact

- Purchase Livewire create/edit/correction validation and legacy Purchase request validation.
- Purchase and Sales import duplicate lookup behavior and regression coverage.
- Sale detail presentation and terminology for `imported_sales_reference_number`.
- No new database column, destructive migration, API contract, or permission is expected.
