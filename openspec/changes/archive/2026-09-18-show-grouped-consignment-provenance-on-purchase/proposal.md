## Why

The detail page for a Purchase created from consignment billing labels an internal receiving-detail ID as a receiving number, repeats that line once per allocation, and shows `SN` without the serial value. The purchase and its lineage are correct; operators need a readable, accurate view of that existing evidence.

## What Changes

- Show the consignment receival reference and receiving number for each billed source group on the Purchase detail page.
- Aggregate displayed lineage quantities by source document within each existing Purchase product row, and list the billed serial numbers in that group.
- Keep the current Purchase rows, financial amounts, allocation records, and serial identities unchanged.
- Handle non-serialized allocations and missing legacy source references without presenting database IDs as document numbers.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `consignment-supplier-billing`: Define how a generated Purchase presents its consignment source documents, grouped quantity, and serial evidence.

## Impact

- Purchase detail controller loading and Blade presentation in `Modules/Purchase`.
- Existing consignment lineage and source relationships in `Modules/Consignment`.
- Focused Purchase detail feature tests. No schema, conversion, payment, inventory, or API change.
