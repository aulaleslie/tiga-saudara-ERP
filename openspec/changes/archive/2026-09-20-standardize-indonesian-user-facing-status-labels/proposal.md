# Proposal

## Why

Purchase, sales, return, payment, and POS workflows still expose some internal English status values even though the surrounding interface is Indonesian. Users need consistent, context-appropriate Indonesian labels without changing the persisted values on which business logic and historical data depend.

## What Changes

- Present document lifecycle, payment, approval, settlement, and POS transaction statuses in Indonesian across the affected list, detail, filter, report, export, and embedded POS surfaces.
- Use context-specific wording for partial states, such as `Dikirim Sebagian`, `Diterima Sebagian`, `Dikembalikan Sebagian`, `Dibayar Sebagian`, and `Diselesaikan Sebagian`.
- Centralize or reuse status-label mappings so equivalent values are rendered consistently instead of printing raw enum values or duplicating English labels in Blade views.
- Keep existing database values, enum constants, API/query inputs, and workflow comparisons unchanged.
- Replace incidental English table controls and headings in the affected global purchase and sales payment workspaces where they are part of the same user-facing surface.
- Add focused verification for the affected mappings and rendering paths; no full-suite test run is required for this presentation-layer change.

## Capabilities

### New Capabilities

- `indonesian-status-labels`: Defines consistent Indonesian presentation of internal statuses across purchase, sales, return, payment, reporting, and POS interfaces while preserving canonical stored values.

### Modified Capabilities

None.

## Impact

- Affects status constants or presentation helpers, Blade partials, Livewire list tables, DataTables output, report views/exports, payment detail views, and POS transaction/return views.
- Primarily touches `Modules/Purchase`, `Modules/Sale`, `Modules/PurchasesReturn`, `Modules/SalesReturn`, `Modules/Pos`, shared application constants/support code, and related focused tests.
- Does not require schema changes, data migration, dependency changes, API contract changes, or modification of persisted enum/status values.
