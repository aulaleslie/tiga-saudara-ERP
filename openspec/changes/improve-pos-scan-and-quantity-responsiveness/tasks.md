# Tasks

## 1. Preserve rapid scan submissions

- [ ] 1.1 Capture and clear each complete Enter or helper scan value before asynchronous work, enqueue it with the current transaction generation, and verify a rapid two-code input sequence yields two intact captured values.
- [ ] 1.2 Drain queued submissions sequentially through the existing resolver and cart flow, retain repeated deliberate scans, and verify two fast scans of one barcode produce two accepted additions when stock permits.
- [ ] 1.3 Pause and resume the queue across unit or bundle selection, cancellation, and errors; keep camera submission outside the queue, and verify focused selection and camera duplicate scenarios.
- [ ] 1.4 Invalidate queued entries at confirmed transaction boundaries and report queue overflow visibly; verify old entries cannot add to a new cart and overflow is not silent.

## 2. Refresh quantity changes efficiently

- [ ] 2.1 Extract the existing cart summary and checkout-control refresh from the full renderer, and verify a normal full render still updates totals, approvals, serial guards, and checkout availability.
- [ ] 2.2 Reconcile the changed quantity row from the returned snapshot when row identities and order match, with full-render fallback otherwise; verify an unrelated row retains its DOM node after a successful quantity update.
- [ ] 2.3 Route successful quantity increase and approved reduction responses through the targeted update, preserve rejection rollback, and verify packed pricing, serial status, approval indicators, and totals match the authoritative snapshot.

## 3. Focused verification

- [ ] 3.1 Run focused POS scan and cart tests plus a browser check using rapid repeated scans and a representative multi-line cart; record observed behavior and any timing comparison, without running the full suite.
