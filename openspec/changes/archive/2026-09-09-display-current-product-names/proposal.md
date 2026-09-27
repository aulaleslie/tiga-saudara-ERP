## Why

Purchase and sales screens, invoices, and exports can render product-name snapshots stored on transaction lines, so users continue seeing an obsolete name after the linked product is renamed. All user-facing purchase and sales output should resolve the current product name while retaining the transaction snapshot as a safe fallback.

## What Changes

- Display the linked product's current name on purchase and sales operational surfaces, invoices, printable documents, and exports.
- Fall back to the persisted transaction-line product name when the linked product cannot be resolved.
- Preserve persisted product-name snapshots; product renaming will not rewrite historical purchase or sales rows.
- Make regenerated invoices and exports reflect the latest product name from the catalog.
- Apply the same live-name precedence to operational bundle/component rows where a linked product exists.
- Add focused automated coverage for name precedence and fallback behavior; browser verification remains a human test activity.

## Capabilities

### New Capabilities

- `current-product-name-display`: Defines how purchase and sales screens, documents, and exports display the current linked product name and fall back to persisted transaction snapshots.

### Modified Capabilities

None.

## Impact

- Purchase detail, receiving, and purchase list-preview views that currently render `purchase_details.product_name` directly.
- Sales detail, operational list-preview, dispatch-related, and bundle/component views that currently render persisted line names directly.
- Purchase and sales invoice, print, report-export, and direct-export output that includes product names.
- Eloquent loading for linked products on affected purchase and sales read paths to prevent lazy loading and N+1 queries.
- No database migration, historical backfill, public API change, or external dependency is required.
