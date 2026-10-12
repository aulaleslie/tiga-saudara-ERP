# Tasks

## 1. Persist POS-line provenance

- [x] 1.1 Add an additive nullable indexed `sale_details.pos_transaction_line_id` link and verify a focused migration test or schema assertion on SQLite.
- [x] 1.2 Preserve stable cart-line identity through finalized POS transaction-line persistence and verify an inline checkout test maps each generated Sale detail to its actual POS line.
- [x] 1.3 Carry the same identity through owner-split posting, including parent residual and component-only groups; verify a focused three-owner bundle test and repeated-line test establish distinct links.

## 2. Project current receipt money

- [x] 2.1 Build a shared completed-reprint monetary projection from reachable Sales and linked Sale details, excluding nested bundle-item amounts; verify focused tests for normal and three-owner bundle price edits.
- [x] 2.2 Reconcile Sale header discounts/shipping and per-unit/packed breakdown displays in minor units without changing the receipt layout; verify focused tests that printed line charges sum to the current Sale-derived Total.
- [x] 2.3 Source reprint Total and `Sisa Utang` from the current global POS settlement projection while preserving original checkout tender and change; verify focused tests for later payment, reopened balance, and current due alongside historical change.

## 3. Handle historical transactions and routes

- [x] 3.1 Resolve historical single-line and uniquely provable multi-line checkout mapping, and refuse ambiguous repeated-line mapping; verify focused tests for each case and confirm the original checkout receipt remains accessible.
- [x] 3.2 Apply the shared projection to global, transaction, and checkout completed-reprint routes without changing draft previews or authorization; verify focused route tests that successful prints log once and refused reprints create no success log.

## 4. Focused verification and manual browser handoff

- [x] 4.1 Run only affected POS receipt, split checkout, monetary edit, and global POS payment tests with focused `php artisan test --filter` invocations; record passing results and fix failures in the changed behavior.
- [x] 4.2 Provide a human developer browser checklist for a three-owner bundle edit, a mixed multi-line checkout, current `Sisa Utang`, and historical ambiguous reprint; verify the checklist is included in the implementation handoff. Browser execution is performed by the human developer.
