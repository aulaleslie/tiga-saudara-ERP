## 1. Server Scan and Location Contracts

- [x] 1.1 Change `AdjustmentProductTable::processScan()` to accept an optional explicit captured code while retaining the bound `scanInput` fallback for compatible direct calls.
- [x] 1.2 Lock selected and pending location properties against client mutation and add an owned, non-consignment location resolver scoped to the active session setting.
- [x] 1.3 Funnel location changes and confirmations through repeated server-side resolution, restore the dropdown after rejection, and guard baseline capture/disclosure against invalid location state.
- [x] 1.4 Preserve existing stock-opname serial semantics, including recording valid existing serial evidence from another source location or status.

## 2. Resilient Browser Scan Queue

- [x] 2.1 Replace direct scan-input/button Livewire actions with one duplicate-guarded, morph-safe FIFO controller that captures and clears each submitted code synchronously.
- [x] 2.2 Resolve the current Livewire component and DOM elements at use time, retry component lookup only for a bounded period before dispatch, and drain scans sequentially by awaiting each round trip.
- [x] 2.3 Remove dispatched entries before awaiting their result, never automatically retry an ambiguous rejected request, and render an always-visible `role="alert"`/`aria-live` warning with the affected code and recovery instruction.
- [x] 2.4 Pause queue draining while scan ambiguity requires operator selection and resume deterministically after selection or dismissal without dropping or reordering captured scans.
- [x] 2.5 Preserve scan-input focus and selection behavior across successful, rejected, ambiguous, and modal flows without retaining stale morphable input references.

## 3. Visible Count Consistency

- [x] 3.1 Add stable product-ID row keys and authoritative rendered values for non-serialized Good and Bad count inputs without putting changing counts into keys.
- [x] 3.2 After each successful scan response, re-query current component inputs and synchronize displayed Good/Bad DOM values from current Livewire product state after morph completion.
- [x] 3.3 Confirm the synchronization is scan-scoped and does not overwrite a later manual `lazy` count edit; keep the submitted `count_draft` aligned with the committed visible values.

## 4. Focused Verification

- [x] 4.1 Add focused Livewire tests for explicit-code precedence, sequential ordinary/conversion accumulation, condition switching, serialized-product behavior, and ambiguity resume contracts.
- [x] 4.2 Add focused security tests proving direct selected/pending location tampering, foreign-setting locations, and consignment locations cannot capture or disclose baseline stock, while intentional cross-location serial evidence remains accepted.
- [x] 4.3 Add focused rendered-markup tests for queue hooks, accessible failure feedback, stable row keys, and authoritative Good/Bad input values; do not plan or run the repository's full test suite.
- [x] 4.4 Write a Bahasa Indonesia human browser checklist covering rapid repeated scans, different back-to-back barcodes, conversion factors, Good/Bad switching, ambiguity pause/resume, forced request failure, visible/model/draft count agreement, manual edits, focus restoration, and unauthorized location attempts.
