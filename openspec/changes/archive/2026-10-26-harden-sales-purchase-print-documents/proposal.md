# Proposal

## Why

Purchase and sales PDFs omit tax and commercial adjustments, use inconsistent balances and business identity, and can print unapproved dispatches. Users need reliable printable commercial documents, including sales offers and purchase orders, from both ordinary and Global Payments detail pages.

## What Changes

- Add Nota Penawaran alongside Faktur Penjualan and Pesanan Pembelian alongside Pembelian, available in every status, including archived records, with clear status labels.
- Use the owning business’s current identity and current `is_pkp`: PKP copies show recorded tax breakdowns; non-PKP copies hide all tax details while retaining tax-inclusive commercial amounts and saved totals.
- Present authoritative saved rows, units, discounts, shipping, and totals without recalculating or rewriting historical transactions.
- Show active payments, supported applied credits separately, and live outstanding balances on Faktur and Pembelian; omit settlement on Penawaran and Pesanan Pembelian.
- Restrict Surat Jalan to approved dispatches; provide latest-approved and individual approved-dispatch print actions.
- Enforce business access and document-view permissions on every print endpoint, including archived and Global Payments contexts.
- Verify with focused feature/rendering tests and representative PDF inspection only; do not require a full test suite.

## Capabilities

### New Capabilities

- `sales-purchase-print-documents`: Commercial PDF variants, current-business tax presentation, accurate saved monetary presentation, settlement, status/access rules, and approved-dispatch printing.

### Modified Capabilities

None. Existing product-name, purchase conversion-unit/discount, settlement, and document-reference rules are preserved and reused.

## Impact

- Purchase and Sale module routes, controllers, PDF templates, ordinary detail and Global Payments detail integration, and approved dispatch history actions.
- Reuse existing Eloquent business relationships, archived lookup, permissions, settlement methods, product-name accessors, conversion snapshots, and DomPDF.
- Add focused tests around print access, PKP rendering, monetary reconciliation, lifecycle visibility, and dispatch selection.
- No new dependency, schema migration, transaction repricing, tax recalculation, reference allocation, payment mutation, or inventory mutation is intended.
