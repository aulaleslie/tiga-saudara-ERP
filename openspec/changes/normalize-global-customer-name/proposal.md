## Why

Sales import correctly stores the customer identity in `customers.customer_name` and may leave `contact_name` empty, but several customer lists, loaders, and creation paths still treat `contact_name` as the primary name. This produces blank imported-customer labels, duplicated `NAME - NAME` display values, and inconsistent behavior between globally resolved customers and setting-scoped configuration screens.

## What Changes

- Establish `customer_name` as the canonical customer identity for creation, editing, lists, search results, selection, and transaction-facing reads.
- Keep `contact_name` nullable and optional as supplementary contact-person information; never require it at the database or application-validation layer and never copy `customer_name` into it as a display workaround.
- Normalize customer creation and editing across the full Customer CRUD form, Sales create/edit quick-add, legacy quick-add, and POS customer creation while preserving existing customer records unchanged.
- Keep customer lookup, selection, and POS walk-in customer configuration global regardless of `customers.setting_id`; `setting_id` may remain as optional provenance on manually created records.
- Use an empty-safe, duplicate-safe display fallback for historical data while retaining `customer_name` as the primary value.
- Preserve sales import exactly as-is and use its customer field mapping and global lookup behavior as the reference contract.
- Replace the historical requirement that worked around the list bug by duplicating the customer name into `contact_name`.
- Add focused regression coverage for import-shaped records, all customer creation entry points, global selection, and consistent nonblank display behavior.

## Capabilities

### New Capabilities

- `global-customer-identity`: Defines canonical customer-name reads, safe display behavior, and global customer visibility and selection independent of setting ownership.

### Modified Capabilities

- `customer-creation-field-consistency`: Replaces name duplication with a consistent write contract where `customer_name` is required and `contact_name` is optional across Customer CRUD, Sales quick-add, and POS.

## Impact

- Affected customer model and presentation code under `Modules/People`, including the customer DataTable, CRUD controller/views, shared search dropdown, and display-name accessor.
- Affected Sales customer quick-add and any legacy customer autocomplete/read surfaces under `app/Livewire/Sale` and `app/Livewire/AutoComplete`.
- Affected POS customer creation/search response formatting and Settings walk-in customer options/validation.
- Existing customer rows, foreign keys, transaction ownership, and sales-import code remain unchanged; no customer-table migration or destructive data backfill is expected.
- Existing OpenSpec behavior for `customer-creation-field-consistency` changes; existing global POS customer-resolution behavior remains compatible.
