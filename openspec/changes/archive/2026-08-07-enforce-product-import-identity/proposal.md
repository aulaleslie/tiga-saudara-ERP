## Why

The fresh ERP database acquired duplicate global catalog products while historical product, purchase, sales, and snapshot imports ran. Those paths use different name-normalization and create-if-missing rules, so a formatting variant or concurrent job can create a second product; downstream price uploads correctly refuse to choose between the duplicate IDs.

## What Changes

- Establish one canonical product-name identity for every import path that resolves or creates catalog products.
- Normalize import-only owner markers and whitespace consistently before product lookup and before storing a newly created product name.
- Persist and uniquely constrain the canonical identity so concurrent import jobs cannot create duplicate catalog products.
- Route product CSV, purchase CSV, sales CSV, and product snapshot/HPP product resolution through one atomic resolve-or-create workflow.
- Preserve the first canonical catalog product as the target when later import rows differ only in case, whitespace, or import-only owner markers; preserve current owner routing and pricing/stock behaviors.
- Make non-creating price and stock snapshot imports use the same canonical identity and report unresolved identities without creating products.
- Add an initialization-safe remediation path for duplicate products already created by prior imports; it must require explicit operator selection/confirmation and preserve transaction/history links.

## Capabilities

### New Capabilities
- `import-product-catalog-identity`: Defines canonical global product identity, atomic import-time resolution/creation, concurrent duplicate prevention, and remediation of import-created duplicate catalog rows.

### Modified Capabilities
- `dual-company-tier-price-import`: Resolve existing price-upload products using the shared canonical catalog identity rather than a divergent normalized-name matcher.
- `product-sales-price-snapshot-import`: Use the shared canonical catalog identity for existing-product lookup and unmatched/ambiguous results.
- `product-stock-owner-marker-import`: Use the shared canonical catalog identity when matching an existing product without creating a product from a snapshot.

## Impact

- Affects product creation/resolution in `Modules/Purchase`, `Modules/Sale`, and `Modules/Product` import jobs, plus the `products` schema and relevant import tests.
- Affects product, purchase, sales, price-snapshot, stock-snapshot, HPP, and dual-company tier-price batch outcomes; it does not change document owner routing, price semantics, or stock mutation rules.
- Requires a migration/backfill preflight and an operator-visible remediation workflow for duplicate catalog identities found before enforcing the unique constraint.
