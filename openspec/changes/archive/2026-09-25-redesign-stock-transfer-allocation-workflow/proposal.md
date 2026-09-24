# Proposal

## Why

Stock Transfer currently requires operators to choose a single route before entering goods and repeats dispatch preparation after request approval. The revised workflow lets operators record goods, lets approvers configure multiple routes, and completes delivery through a single receipt confirmation, while supporting an audited reversal before receipt.

## What Changes

- Introduce a prospective workflow version 3 for new transfers, preserving existing v1/v2 data and operations.
- Create and edit a goods manifest without selecting locations; retain Barang Baik / Barang Rusak, product search, barcode/conversion/serial scanning, editable quantities, Simpan Draf, and atomic Ajukan Persetujuan. Submission requires exact quantity-to-distinct-serial agreement.
- Give approvers a manually saved allocation workspace: group selected serials by actual product/source location, allocate non-serialized quantities across stocked locations, and select a destination per allocation. Permit routes across any business using action permissions in the active business.
- Show a revision-bound summary before approval; approval immediately dispatches all allocations atomically. Permit self-approval and do not reserve stock when approval progress is saved.
- Replace new-workflow receiving counts and receipt approval with Terima Barang and a Bahasa Indonesia confirmation. Confirmation receives the whole immutable manifest into the approved destinations and completes the transfer.
- Add permission-controlled, reasoned cancellation of dispatched but unreceived transfers, reversing the exact dispatch quantities and serial custody once.
- Record immutable approval, dispatch, receipt, cancellation, and other lifecycle events; expose the detail timeline only with a dedicated history permission. Keep allocation configuration restricted to approvers.
- Track cross-business provenance per allocation independently of tax status. Retain destination tax-classification behavior, but create no new-workflow return obligations; future returns are a separate delivery.
- Redirect successful creation/save/submission to detail, retain list submission and approval-workspace links, and hide Archive.
- Proposed discovery default: authorized users can discover all new-workflow documents using permissions in their active business; no membership of participating source/destination businesses is required. Legacy discovery remains unchanged.

## Capabilities

### New Capabilities

- `stock-transfer-approval-allocations`: Multi-source allocation editing, serial grouping, manual progress persistence, complete route validation, and immutable approval summaries.

### Modified Capabilities

- `stock-transfer-entry-scanning`: Location-free v3 entry and submission while preserving existing scanner semantics and legacy entry.
- `stock-transfer-approval-lifecycle`: Prospective version boundary, approval-to-dispatch transition, event recording, cancellation lifecycle, and navigation.
- `stock-transfer-inventory-movement`: Atomic multi-route dispatch, confirmed receipt, exact cancellation reversal, and shared terminal-action locking.
- `stock-transfer-forward-receipt`: Whole-document confirmation-only receipt for v3; legacy blind receipt remains supported.
- `stock-transfer-route-policy`: Per-allocation cross-business/tax snapshots without automatic v3 returns.
- `stock-transfer-system-stock-visibility`: Active-business permissions, global v3 discovery, approver-only allocation context, dedicated history visibility, and location-free receipt projection.

## Impact

Touches Modules/Adjustment entities, migrations, services, controllers, routes, DataTables and Blade views; app/Livewire/Transfer and its views; permission configuration; and focused Stock Transfer tests. Location-independent document numbering and an additive allocation/execution schema are required because current headers and numbering require one origin. Existing serial claim infrastructure and inventory transaction conventions must remain interoperable with sale and other stock consumers.

No historical status conversion, renumbering, inventory repair, or return-history deletion is included. The read-only replica review found two completed v2 transfers, four approved movements, and no active claims or return obligations; deployment must still handle legacy records created after that snapshot.

Verification is focused automated tests against an isolated test database, plus OpenSpec validation. No full-suite testing is planned. Browser testing is performed by the human developer using the delivered checklist.
