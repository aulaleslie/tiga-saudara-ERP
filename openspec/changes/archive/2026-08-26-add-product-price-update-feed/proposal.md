## Why

Operational users currently have no shared, permission-safe view of newly created products, product price changes, or bundle price changes across the businesses assigned to them. A future-only update feed on Home and a linked history page will make catalog changes visible without exposing purchase or sales prices that the user is not authorized to see.

## What Changes

- Record future product-created, product-price-updated, bundle-created, and bundle-price-updated events with immutable display and before/after price snapshots; do not backfill historical data.
- Add a compact `Pembaruan Produk & Harga` preview beneath Home Quick Access, with clickable event rows and a reusable styled detail modal.
- Add a full, paginated event-history page linked from Home only, without adding a sidebar or other global navigation entry.
- Support Product List-style, case-insensitive tokenized partial `LIKE` search across product name, product code, and bundle name, together with event type, business, and date filters.
- Give Super Admin unrestricted visibility into all events, businesses, and recorded price fields.
- For regular users, restrict events to assigned settings and independently expose latest purchase prices through `purchases.create`, sales price tiers through `sales.create` or the `pos.access` plus `pos.sessions.open` combination, and bundle events through the Sales/POS visibility rules.
- Capture qualifying changes from touched manual, cross-business, purchase-synchronization, import, job, and bundle mutation paths while suppressing no-op price updates.
- Add focused automated coverage for the touched capture, visibility, search, Home preview, history, and modal-detail behavior; no full-suite verification is required by this change.

## Capabilities

### New Capabilities

- `product-price-update-feed`: Defines future-only product and bundle update capture, permission-aware multi-business visibility, event grouping, searchable history, and protected modal details.

### Modified Capabilities

- `authenticated-home-dashboard`: Adds the compact update preview and Home-only navigation entry to the full event-history page beneath the existing Quick Access content.

## Impact

- Affects the authenticated Home controller/view and adds a product-update history route, page, query service, and reusable detail modal.
- Adds persistent event-ledger storage and event-recording integration around product, `product_prices`, purchase-price synchronization, imports/jobs, and replicated bundle workflows.
- Reuses the existing user-setting role assignments, Spatie permissions, `PermissionResolver` per-setting pattern, Bootstrap/CoreUI cards, list groups, badges, and modals.
- Adds database indexes for chronological business-scoped retrieval and tokenized subject search without introducing a new search dependency.
