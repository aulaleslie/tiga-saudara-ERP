## Why

Approved consignment allocations currently produce a Purchase at their original receipt prices as soon as the operator confirms billing. Supplier invoices can instead contain negotiated discounts or different taxable prices, and the generated consignment Purchase deliberately cannot be edited afterward. Operators need to review and finalize the payable on the conversion page before it becomes immutable.

## What Changes

- Add Purchase-style financial controls to the consignment billing conversion page: editable unit price and final row total, fixed amount or percentage discount per row, fixed amount or percentage global discount, and a live total.
- For PKP settings, allow line tax selection and a tax-included toggle defaulted on; hide tax controls and enforce zero tax for non-PKP settings.
- Recalculate and validate all submitted amounts on the server against the current approved allocation evidence before creating the Purchase. Persist the selected prices, discounts, tax, and payable on the generated Purchase while retaining the original allocation snapshots and an auditable record of the adjustment.
- Keep supplier, product, quantity, receipt allocation, and serialized lineage fixed; retain the existing post-conversion commercial edit prohibition.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `consignment-supplier-billing`: Permit reviewed financial adjustments before conversion while preserving immutable allocation provenance, exact Purchase totals, and the source-typed lifecycle guards.

## Impact

- `Modules/Consignment` billing page, preview and conversion services, request validation, audit evidence, and focused tests.
- Generated `Modules/Purchase` header and detail monetary fields; existing Purchase payment and reporting consumers must read the reviewed payable consistently.
- Existing `consignment-supplier-billing` requirement that Purchase monetary values derive exclusively from allocation snapshots must be revised to distinguish original evidence from authorized billing terms.
