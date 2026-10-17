# Proposal

## Why

Businesses need a simpler POS receipt by default while retaining the ability to show existing explanatory information when needed. Current receipts already calculate the expected updated prices, return state and balances; only presentation needs configuration.

## What Changes

- Group the existing inline POS controls on `/settings` under `Pengaturan POS`, preserving enablement, saved transactions and default customer behavior.
- Add five independent business-level receipt switches: Catatan, Riwayat Retur, price-adjustment information and PPN information default OFF; stamp/signature blank space defaults ON.
- Apply these defaults to existing and new businesses. Read current persisted business settings whenever receipt output is built, including historical reprints and previews.
- Hide existing information in its current position, without moving or regrouping receipt content. Price-adjustment visibility covers existing inline annotations and return-time price comparisons.
- Keep financial projection, current purchase prices, return effects, quantities, serials, totals and debt unchanged. Enabling a switch restores existing eligible content rather than creating content unavailable on historical receipts or previews.
- Remove hidden sections' own headings, dividers and gaps; preserve the thank-you message and receipt number independently of PPN and signature-space visibility.
- Limit verification to affected settings and receipt tests; no full-suite requirement.

## Capabilities

### New Capabilities

- `pos-receipt-visibility-settings`: Five business-scoped switches, defaults, persistence, POS settings grouping and build-time visibility resolution.

### Modified Capabilities

- `pos-receipt`: Make existing note, return-history and price-explanation display requirements conditional on the corresponding business visibility switches without changing content calculation.
- `pos-professional-receipt`: Make PPN and stamp/signature space independently configurable while preserving the closing footer and existing receipt layout.

## Impact

Additive settings migration and model defaults/casts, settings request/controller/cache-session synchronization, existing settings Blade page, shared receipt data/view wiring and focused tests. No changes to Sales editing, return execution, posting, payment evidence, receipt financial calculations or separate POS configuration pages. Historical receipt transaction snapshots remain unchanged; their presentation uses current business configuration.
