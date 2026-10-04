# Design

## Context

The POS sell page currently routes Enter, helper, and camera values through `executeScanResolve`. It returns early while `scanResolveInFlight` or a selection operation is active. A normal scan resolves the code, submits a cart mutation, and receives a full authoritative snapshot. Quantity changes also receive a full snapshot but `renderCart` replaces every cart row. Camera scanning has separate submission and duplicate-suppression rules. Existing stock, pricing, serial, bundle, and supervisor rules remain authoritative on the server.

## Goals / Non-Goals

**Goals:**

- Preserve complete keyboard and helper submissions during an in-flight scan without concurrent cart writes.
- Reuse server-returned snapshots for targeted quantity updates while keeping all summary and checkout controls current.
- Keep a safe full-render path when DOM and snapshot row identities differ.

**Non-Goals:**

- Changing scan resolution or cart APIs, server validation, pricing, approvals, or camera duplicate suppression.
- Optimizing backend query time or promising a specific latency reduction before measurement.

## Decisions

### 1. Serialize keyboard and helper submissions in one bounded client queue

Capture a complete submitted value at the Enter or helper event and clear the active input promptly so subsequent scanner keystrokes cannot append to the previous barcode. Enqueue the captured value and drain one submission at a time through the existing resolver and cart flow. Retain duplicate submitted values because two deliberate hardware scans mean two additions. Give the queue a finite capacity and visible overflow feedback rather than silently discarding values. The camera continues through its existing single-flight gate and duplicate suppression; it does not feed repeated decoded frames into this queue.

Pause draining while unit or bundle selection is active. Resume when the choice completes or is cancelled, including error paths. Associate queued entries with a transaction-context generation and discard stale entries after successful checkout, clear, save-and-new, or draft load. Existing transaction-boundary handlers should advance this generation. This avoids writes to a different cart.

Alternative considered: send concurrent cart requests. That risks order changes and lock contention, and makes returned snapshots race.

### 2. Reconcile one quantity row from the authoritative snapshot

Keep `renderCart` as the full-render entry point. Split its summary and checkout-control refresh into a reusable operation. For successful quantity mutations, compare displayed and returned line ID sequences; if they match and the target row is present, replace only that row using the existing `buildLineRow` template, then update `currentSnapshot`, totals, customer/note summary, approval and checkout controls from the returned snapshot. If identity/order differs or a required node is missing, call the full renderer. Event delegation on `cartBody` continues working for a replaced row. Never calculate accepted totals from optimistic browser state.

Alternative considered: patch individual text nodes. Reusing `buildLineRow` has less risk of omitting packed pricing, serial, discount, or approval markup.

### 3. Limit optimization to quantity mutation responses

Other cart operations may change line composition, customer context, or lifecycle state, so they continue to use full render. Quantity rejection restores the previous input value and leaves the last accepted snapshot in place. Retain the current approval flow for reductions.

## Risks / Trade-offs

- **Scan input contamination during in-flight work** → Capture and clear each submitted value synchronously; focused browser verification with a rapid hardware-style sequence.
- **Selection modal leaves queue paused** → Resume from complete, cancel, and error paths; cover each with focused tests.
- **Stale response after transaction boundary** → Check the generation before allowing a queued mutation; clear pending entries at confirmed boundaries.
- **A derived cart control stays stale after partial row update** → Reuse one shared summary/control refresh path, and fall back to full render on any reconciliation mismatch.
- **Camera scans accidentally count repeatedly** → Keep camera outside the new queue and retain existing suppression contract.

## Migration Plan

No schema or API migration. Deploy the sell-page change and verify the focused scan and quantity flows. Rollback restores the previous client behavior without data conversion.
