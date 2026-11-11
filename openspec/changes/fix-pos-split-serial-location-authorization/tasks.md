# Tasks

## 1. Preserve originating POS location authorization

- [x] 1.1 Preserve the trusted originating checkout business as `pos_setting_id` in split group contexts before replacing `setting_id` with the source owner; use `pos_setting_id ?? setting_id` only for the inline adapter's parent and component locked serial location checks. Verify the diff leaves ownership, customer resolution, allocation, payment, and other serial guards unchanged.
- [x] 1.2 Add focused regression cases using existing POS split/serial feature fixtures for a serialized parent and a serialized bundle component whose source location is enabled for the POS business but disabled for the owner. Invalidate fixture location caches and verify checkout succeeds with correct source-owner sale, dispatch, stock effects, and serial linkage/history.
- [x] 1.3 Add rejection coverage where only the source owner permits the serial location, including a serial moved outside the originating business's allowed locations before locked posting. Verify rejection and atomic rollback for parent and component paths, using existing posting-boundary fixtures/hooks where appropriate.
- [x] 1.4 Verify inline posting without `pos_setting_id` retains existing location authorization and sellability checks through focused fallback coverage; confirm invalid, broken, or reserved serials remain rejected by the relevant existing serial tests.

## 2. Integration verification

- [x] 2.1 Run the relevant split posting, serialized bundle, and serial validation suites using the isolated fresh-SQLite test runner (or an explicitly configured testing database). Verify all targeted tests pass and record commands/results; do not run tests against the operational database or finalize the reported real transaction.
- [x] 2.2 Run `openspec validate fix-pos-split-serial-location-authorization --strict` and review the final implementation diff against the delta: both serial paths use POS authorization, ownership remains source-aligned, and no migrations or transaction/configuration edits are present.
