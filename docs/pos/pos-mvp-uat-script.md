# POS MVP UAT Script

**Date:** 2026-02-28
**Scope:** POS Phase 1 (MVP) Release
**Status:** [DRAFT]

This script covers manual/hardware/workflow scenarios that cannot be fully validated by unit/integration tests alone. It must be executed successfully before the POS module is enabled for production parallel run.

---

## 1. Cashier Workflow Usability

### `POS-UAT-001` Keyboard-Heavy Cashier Workflow

- **Preconditions:** Store with keyboard-heavy operations; cashier logged in with active POS session.
- **Steps:**
  1. Cashier completes 10 common retail transactions natively (search, adjust qty, pay cash, confirm) using ONLY keyboard shortcuts (no mouse/touch).
- **Expected Results:**
  - Cashier is not forced to use the mouse to complete any happy-path transaction.
  - Focus flows logically from search -> cart -> payment -> confirmation -> new sale.
- **Pass/Fail:** [ ] Pass  [ ] Fail

### `POS-UAT-002` Touchscreen Cashier Workflow

- **Preconditions:** Store with touchscreen operations; cashier logged in with active POS session.
- **Steps:**
  1. Cashier completes a standard sell-pay-print flow exclusively using the touchscreen.
- **Expected Results:**
  - UI targets (buttons, search results, number pads) are sufficiently large.
  - No precision taps are required to complete the sale.
- **Pass/Fail:** [ ] Pass  [ ] Fail

### `POS-UAT-003` Barcode Scanning Cadence

- **Preconditions:** Barcode scanner connected.
- **Steps:**
  1. Cashier scans 5 distinct items in rapid succession (normal counter speed).
- **Expected Results:**
  - Search/scan debouncing handles the input stream correctly.
  - All 5 items are successfully added to the cart without dropped inputs or race conditions.
- **Pass/Fail:** [ ] Pass  [ ] Fail

---

## 2. Hardware and Operations

### `POS-UAT-010` Network Thermal Printer Compatibility

- **Preconditions:** Representative 80mm or 58mm network thermal printer connected/configured.
- **Steps:**
  1. Complete a transaction and trigger receipt print.
- **Expected Results:**
  - Print layout aligns correctly (not cut off, fonts legible).
  - Printer cuts paper (if supported) accurately at the end of the receipt.
- **Pass/Fail:** [ ] Pass  [ ] Fail

### `POS-UAT-011` Print Failure Recovery

- **Preconditions:** Printer offline / disconnected.
- **Steps:**
  1. Complete a transaction; observe print failure.
  2. Reconnect printer.
  3. Locate the transaction in reporting/history and execute the reprint action.
- **Expected Results:**
  - First transaction posts to ERP successfully despite print failure (non-fatal).
  - Reprint completes successfully after recovery.
  - Reprint action is logged in `pos_receipt_print_logs`.
- **Pass/Fail:** [ ] Pass  [ ] Fail

### `POS-UAT-012` Cash Drawer Policy Verification

- **Preconditions:** Cash drawer connected to receipt printer. Terminal policy set to allow drawer opening.
- **Steps:**
  1. Cashier performs a Cash Sale.
  2. Cashier performs a Safe Drop.
  3. Cashier closes session.
- **Expected Results:**
  - Drawer kicks open automatically on each target event according to the store's terminal policy.
- **Pass/Fail:** [ ] Pass  [ ] Fail

---

## 3. Parallel Run and Fallback

### `POS-UAT-020` Outage Fallback Simulation

- **Preconditions:** POS enabled for the business. Cashier mid-transaction.
- **Steps:**
  1. Admin disables the `pos_enabled` feature flag for the business.
  2. Cashier attempts to continue/navigate in POS.
  3. Cashier switches to legacy sales screen/manual invoice process.
- **Expected Results:**
  - Cashier is cleanly blocked from POS posting after flag disable.
  - Legacy operations resume unimpeded.
  - No POS data corruption occurs.
- **Pass/Fail:** [ ] Pass  [ ] Fail

### `POS-UAT-021` Parallel-Run Duplicate Prevention

- **Preconditions:** POS and legacy sales screen both available (Parallel Run Phase).
- **Steps:**
  1. Cashier completes a sale in the POS interface.
  2. Supervisor audits the posted `sales` and `sale_payments` logs for the shift.
- **Expected Results:**
  - Transaction exists ONCE in `sales`.
  - Cashiers correctly follow SOP to NOT re-enter POS transactions into the legacy screen.
- **Pass/Fail:** [ ] Pass  [ ] Fail

### `POS-UAT-022` Shift Close Reconciliation

- **Preconditions:** Cashier shift completed with known variance.
- **Steps:**
  1. Cashier blind-counts cash drawer and submits close.
  2. Supervisor logs in and reviews variance.
  3. Supervisor enters PIN to approve the shift close.
- **Expected Results:**
  - Cashier is prevented from seeing expected cash.
  - Supervisor successfully reviews and signs off; session transitions to CLOSED.
- **Pass/Fail:** [ ] Pass  [ ] Fail

---

## Release Sign-Off Checklist

Before enabling POS for production parallel-run, ensure:

- [ ] 1. All P0 automated scenarios passing (`php artisan test --testsuite=Pos --group=pos-critical-path`)
- [ ] 2. No duplicate posting observed in idempotency/concurrency tests
- [ ] 3. Multi-location + tax-source scenarios validated for serial and non-serial items
- [ ] 4. Session close variance approval path validated
- [ ] 5. Safe drop threshold monitoring and approval path validated
- [ ] 6. Printer failure and reprint recovery validated on representative hardware/store setup
- [ ] 7. UAT manual scenarios (above) executed successfully
- [ ] 8. Fallback/parallel-run SOP trained and understood by store staff

**Sign-off By:** ___________________________  **Date:** _______________
