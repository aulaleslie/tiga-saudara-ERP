## 1. Migration Repair

- [x] 1.1 Audit the current root and POS module migrations that create, drop, or reference `close_variance_approval_threshold`.
- [x] 1.2 Make the existing drop path safe for fresh installs so `migrate:fresh` no longer attempts to alter `pos_terminal_policies` before the table exists.
- [x] 1.3 Add an upgrade-safe repair migration that recreates `close_variance_approval_threshold` when it is missing and applies the agreed safe default/backfill behavior.

## 2. Runtime Contract Alignment

- [x] 2.1 Restore `close_variance_approval_threshold` in POS terminal policy configuration, including request validation, controller persistence, and the terminal form.
- [x] 2.2 Align POS runtime consumers of the threshold across terminal policy models, finalization services, reconciliation/session payloads, and frontend session handlers.
- [x] 2.3 Update or add POS tests so terminal policy persistence and session finalization both treat the threshold as an active field.

## 3. Verification and Source-of-Truth Cleanup

- [x] 3.1 Verify a clean migration flow succeeds with POS module migrations loaded and leaves `pos_terminal_policies.close_variance_approval_threshold` present.
- [x] 3.2 Verify targeted POS finalization and terminal policy scenarios behave correctly at threshold and above-threshold boundaries.
- [x] 3.3 Add any necessary supersession notes or follow-up documentation so future POS work no longer treats the threshold as deprecated or unused.
