## Why

Transfer-stock entry has a stabilized mutation boundary, but its operator workflow still differs from the proven stock-opname and breakage create/edit interactions. Adapting those interaction patterns now will make rapid barcode entry, ambiguous matches, product discovery, serialized entry, feedback, and focus behavior predictable without weakening transfer-specific stock, condition, tenant, or visibility rules.

## What Changes

- Separate exact scanner input from explicit product search: scans resolve only exact product, conversion, or serial identifiers, while a deliberate “Cari Produk” interaction supports tokenized discovery by product name, code, barcode, category, and brand.
- Replace silent fixed-precedence handling of exact identifier collisions with an ambiguity flow that presents only currently eligible, visibility-safe choices and requires an explicit operator selection.
- Adapt the stock-opname/breakage FIFO capture, immediate input clearing, Livewire lifecycle, focus restoration, and visible success/warning/error feedback patterns to transfer create and edit entry.
- Consolidate scan resolution, ambiguity, product-search selection, row mutation, feedback, and queue completion under one authoritative Livewire entry coordinator, while retaining server-side canonical reload and validation at every mutation boundary.
- Adapt serialized-product interaction: scanning a serialized product identifies or focuses a zero-quantity row, exact serial scans add eligible origin serials, and row-level serial management allows review/removal and fallback selection of existing eligible serials.
- Preserve transfer-specific behavior: one immutable stock condition per mounted entry context, origin-gated entry, destination-only changes preserving rows, authoritative base-unit conversion factors, unique serial allocation, tenant isolation, and permission-aware non-revealing feedback.
- Add focused automated and manual browser verification for ambiguity, search, rapid scanning, serialized entry, visibility boundaries, create/edit parity, and focus recovery; a full test-suite run is outside this change’s verification plan.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-transfer-entry-scanning`: Extend the transfer-entry contract from stabilized exact scanning to the complete stock-opname/breakage-inspired interaction model, including separate product search, explicit ambiguity resolution, serialized-row management, queue ownership, feedback, and focus behavior.

## Impact

- Affects the transfer-stock Livewire form, product-entry component(s), Blade views, scan resolver, browser event/queue wiring, and focused transfer tests.
- Reuses established adjustment/opname/breakage interaction patterns but keeps transfer eligibility, stock condition, location, conversion, authorization, and visibility policies authoritative.
- Does not change database schema, stock movement timing, dispatch/receipt behavior, permissions, routes, inventory reservation, or support for unregistered serial numbers.
