## Why

Purchase reporting currently continues to filter, sort, display, and export using the immutable purchase document date after an authorized user has assigned a reporting-date override. This makes report-period results disagree with the effective reporting date already shown on purchase operational views.

## What Changes

- Make purchase period and analytical reports resolve a purchase's effective reporting date as the active `reporting_date` when present, otherwise the original purchase `date`.
- Apply that effective date consistently to affected report filters, date sorting/grouping, visible date columns, and exports.
- Preserve original purchase-date, due-date, receiving-note-date, and ageing semantics for reports whose purpose is operational delivery tracking or outstanding-liability ageing.
- Retain reporting-date audit history as an audit trail; reports use the current active override stored on the purchase, not a historic audit entry.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `reporting-date-overrides`: Extend effective reporting-date behavior from purchase operational views to defined purchase reporting surfaces, with explicit exclusions for operational and ageing calculations.

## Impact

- Affects purchase-report query, sorting, mapping, and export paths; purchase-by-supplier, purchase-by-product, and purchase-order-completion report paths; and, subject to the specified requirements, purchase-tax period selection.
- Adds focused automated coverage for overridden, replaced, cleared, and absent reporting dates across report filters, displayed values, sorting, and exports.
- Does not change reporting-date authorization, audit storage, purchase document facts, stock/receiving behavior, payment calculations, or database schema.
