## 1. Deletion Interaction

- [x] 1.1 Replace the Product Bundle native `confirm()` delete submission with a button that opens one reusable Bootstrap deletion modal on the product detail page.
- [x] 1.2 Build the Indonesian modal content with selected bundle name, irreversible-action warning, `Batal`, and `Hapus Paket` controls while preserving CSRF and DELETE method submission.
- [x] 1.3 Populate and reset the modal's selected route, bundle name, grouped/legacy state, and unchecked cross-business option every time a delete trigger is activated.
- [x] 1.4 Show `Hapus paket ini dari semua bisnis` only as an actionable unchecked checkbox for grouped bundles, and show local-only Indonesian guidance for historical ungrouped bundles.

## 2. Secure Transactional Deletion

- [x] 2.1 Validate/normalize nullable boolean `delete_from_all_businesses` input without accepting client-controlled replica lineage.
- [x] 2.2 Inspect ProductBundle model events, observers, relationships, and deletion hooks to choose set-based or deterministic per-model group deletion without bypassing required cleanup.
- [x] 2.3 Refactor deletion into a database transaction that preserves local-only deletion by default and deletes all surviving copies only when explicitly selected and the route bundle has a persisted non-null replica-group UUID.
- [x] 2.4 Preserve delete permission, nested product ownership, and active-setting checks before group scope is resolved, and explicitly guard null lineage from group fan-out.
- [x] 2.5 Preserve local deletion and partial-group behavior without creating, requiring, or repairing missing copies.

## 3. Focused Feature Verification

- [x] 3.1 Add focused rendering tests proving the native browser confirmation is absent, the reusable modal identifies the selected bundle, grouped controls are unchecked by default, historical guidance is shown, and switching triggers resets modal state and route.
- [x] 3.2 Add request tests proving cancel/no-submit behavior where testable, default deletion removes only the active-setting copy, and selected deletion removes every surviving real group member.
- [x] 3.3 Add safety tests proving another existing group's forged UUID cannot redirect deletion, null-lineage requests cannot fan out, unrelated groups survive, and route/setting/permission authorization remains enforced.
- [x] 3.4 Add a forced mid-group failure test proving already-deleted headers/component rows and remaining group copies are all restored by transaction rollback.
- [x] 3.5 Run only the new cross-business bundle-deletion feature tests and directly affected existing Product Bundle deletion, permission-rendering, replica-group, and price-synchronization regression tests; do not run the full application suite, and document any baseline failures encountered in this focused scope.
