## Why

Production behavior intentionally treats dispatch as fulfillment acknowledgement: approved stock-managed rows acknowledge delivered goods and move inventory, while approved non-stock rows acknowledge completed work without inventory movement. Canonical specifications still describe non-stock demand as excluded, creating a risk that correct production behavior is later removed despite no observed data defect.

## What Changes

- Align Standard Sales requirements so stock-managed and non-stock parent/component quantities both contribute dispatch acknowledgement demand and approved Sale completion.
- Preserve inventory effects exclusively for stock-managed dispatch details; non-stock acknowledgements require no stock, serial, or inventory transaction mutation.
- Confirm approved non-stock acknowledgements remain visible in Sales Delivery reporting as completed work.
- Add focused regression coverage for bundle fulfillment identity, mixed stock/non-stock bundles, rejection/resubmission, and exactly-once inventory effects.
- Make no schema change, data backfill, historical status recalculation, or production-data repair.
- Defer prohibiting Sales/POS returns for non-stock services to the bundle return eligibility and reversal exploration; this change does not alter return behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sales-non-stock-product-lines`: Define non-stock dispatch rows as fulfillment acknowledgements that contribute to completion without inventory movement.
- `standard-sale-document-lines`: Include non-stock parent and bundle-component demand in the existing product/tax/bundle dispatch aggregation contract.
- `sale-delivery-report`: Explicitly include approved non-stock acknowledgement quantities as completed work in delivery reporting.

## Impact

- Primarily affects canonical requirements and focused tests around `Modules/Sale/Http/Controllers/SaleController.php`, dispatch entities, bundle dispatch tests, and Sales Delivery reporting tests.
- Existing production dispatch, Sale, bundle, stock, serial, transaction, report, and return rows remain untouched.
- No migration, external API, dependency, permission, or user workflow change is expected unless a focused regression test demonstrates a concrete implementation defect.
