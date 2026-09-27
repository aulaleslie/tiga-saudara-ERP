## Why

An approved stock-transfer request currently dispatches and deducts inventory in one legacy action, without an independently counted, submitted, reviewed, and immutable origin manifest. The archived movement foundation now makes it possible to introduce an approved forward-dispatch workflow while preserving legacy transfers and avoiding an incomplete production cutover before blind receipt is available.

## What Changes

- Add a forward-dispatch preparation experience against an approved transfer using the existing barcode, conversion-barcode, serial, tokenized-search, base-unit normalization, condition, and duplicate-scan behavior.
- Present approved product identities to dispatch preparers while hiding requested quantities, expected serials, stock quantities, buckets, allocation, and differences unless they hold `stockTransfers.view-system-stock`.
- Require an explicit physical-count confirmation for every approved product, including a deliberate confirmed zero, while allowing unexpected products and alternate eligible serials to be recorded as physical observations.
- Submit an immutable dispatch count for independent approval; compare exact product quantities, condition, and serialized-product serial sets with the approved transfer request.
- Block approval of every mismatch or insufficient authoritative origin stock without automatically rejecting the document; preserve explicit rejection and correction through movement revisions.
- On exact approval, atomically lock and deduct origin inventory, persist applied tax/non-tax allocation and inventory references, activate serialized transit custody without moving live serial location, approve the movement, and project the transfer header to `DISPATCHED`.
- Keep workflow permissions independent from stock visibility: blind approvers receive only a neutral match/failure result, while privileged approvers may see exact request, count, stock, allocation, serial, and difference details.
- Preserve legacy workflow version `1` dispatch unchanged. Build the version `2` forward-dispatch path behind an explicit disabled activation boundary so production transfers are not stranded before Delivery 6 provides forward receipt.
- Add focused feature, Livewire, authorization, comparison, concurrency, atomicity, serial-custody, inventory, leakage, and legacy-regression verification; no full-suite requirement.

## Capabilities

### New Capabilities

- `stock-transfer-forward-dispatch`: Defines product-aware physical dispatch counting, submission and comparison, approval/rejection, atomic origin deduction, immutable allocation evidence, and serialized in-transit custody.

### Modified Capabilities

- `stock-transfer-movement-foundation`: Activates forward-dispatch records behind a disabled cutover boundary, supports explicit confirmed-zero product counts, and permits approved dispatch to activate transit custody and project legacy header state atomically.
- `stock-transfer-system-stock-visibility`: Defines blind and privileged projections for forward-dispatch preparation, approval comparison, validation feedback, and browser payloads.
- `stock-transfer-inventory-movement`: Makes approved version `2` forward-dispatch movement the authoritative origin-deduction boundary and represents serialized goods as in transit until receipt rather than moving them immediately to destination.

## Impact

- Adds forward-dispatch routes, controller/Livewire surfaces, authorization wrappers, projection/comparison services, and focused views in `Modules/Adjustment` and `app/Livewire/Transfer`.
- Extends movement-line/serial storage with count confirmation, applied bucket allocation, approval snapshots, inventory transaction references, and active transit-custody constraints.
- Introduces a version-aware forward-dispatch approval executor instead of invoking the legacy `TransferMovementService::dispatch()` for version `2` records.
- Updates serial-availability resolution across affected stock, sale, dispatch, return, and transfer paths to exclude active transfer custody.
- Leaves version `1` routes, status authority, stock behavior, serial-location behavior, return-obligation logic, and permissions compatible.
- Defers production version `2` activation, destination receipt, PKP route policy, tax reclassification, and return movements to later changes.
