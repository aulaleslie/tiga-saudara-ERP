## Why

Approved and completed purchase and sale documents are intentionally locked from ordinary editing, but authorized operators need to defer how a document is dated operationally without changing supplier/customer due dates, payment records, stock history, or the original document record.

## What Changes

- Add a reporting-date override for eligible purchase and sale documents, gated by dedicated permissions and lifecycle rules for ordinary users while preserving the application's existing unrestricted Super Admin authorization bypass.
- Allow authorized users to create, replace, or clear an override using any valid calendar date; every successful change requires a reason and produces an immutable audit entry.
- Preserve the original document date and all financial, stock, workflow, reference-number, and due-date data.
- Show the effective date (`reporting_date` when present, otherwise the original document `date`) as the date on purchase and sale operational lists and detail pages.
- Defer changes to report queries, report filters, sorting, and exports to a later change.

## Capabilities

### New Capabilities

- `reporting-date-overrides`: Authorized, tenant-scoped management and presentation of an effective reporting date for post-approval purchase and sale documents.

### Modified Capabilities

- None.

## Impact

- Purchase and Sale persistence will need an optional reporting-date field and immutable audit storage.
- Permission configuration, backend authorization, purchase/sale routes or Livewire actions, and operational list/detail views will be affected.
- Existing document `date`, `due_date`, payments, inventory/valuation chronology, dispatch/receiving records, and report behavior remain unchanged in this change.
