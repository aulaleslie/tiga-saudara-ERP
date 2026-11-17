# Tasks

## 1. Empty Kas Bon payment initialization

- [x] 1.1 Update `public/js/pos-kas-bon-collection.js` so each newly loaded review starts with an empty selected-method list, zero derived payment amount, and blank method picker; remove automatic CASH insertion and verify the existing summary shows zero payment, full live due, and disabled submission.
- [x] 1.2 Update `tests/js/pos-kas-bon-collection-state.test.cjs` to assert the empty initial state and explicitly add CASH before the existing payment scenarios; retain the amount, free-text notes, reference, evidence, mixed-method, and submission checks. Verify with `node tests/js/pos-kas-bon-collection-state.test.cjs`.
- [x] 1.3 Extend the same DOM regression to cover opening another review, closing/reopening collection, clearing an unadded picker choice, and removing the last method; assert blank selection, zero total, full current live due, and disabled submission. Run only `node tests/js/pos-kas-bon-collection-state.test.cjs` and record the result; no full suite is required.

## 2. Focused interaction check

- [x] 2.1 When a running POS is available, check Bayar Kas Bon with an outstanding transaction: confirm empty defaults, explicitly add CASH or a non-cash method, confirm existing validation and amount defaults, then reopen to confirm reset. Record the outcome or explicitly record that the interactive environment was unavailable; use the focused DOM regression as automated evidence. (Interactive POS environment unavailable; verified via `node tests/js/pos-kas-bon-collection-state.test.cjs` — 85 assertions passed.)
