## Context

The POS return draft-resolution flow persists bundle trace metadata for actionable bundled lines using `pos_return_lines.line_meta`. The `PosReturnLine` model already includes `line_meta` in fillable attributes and casts, and `PosReturnSubmissionService` writes `bundle_trace` into that JSON field after creating a draft line.

The current MySQL database can reach a drifted state where draft-resolution columns such as `resolution`, `pos_transaction_line_id`, `returned_serial_id`, `replacement_serial_id`, and `expected_cash_amount` exist, but `line_meta` does not. In that state, bundled POS return draft submission fails with an unknown-column SQL error. The drift is complicated by an already-recorded migration name that differs from the currently pending migration file, so rerunning the pending migration as-is is unsafe because several columns already exist.

## Goals / Non-Goals

**Goals:**

- Restore schema compatibility by ensuring `pos_return_lines.line_meta` exists as nullable JSON.
- Keep the fix forward-only and safe for partially repaired brownfield databases.
- Preserve the existing migration history and avoid manual edits to the `migrations` table.
- Verify the exact failing path with focused POS return draft submission coverage.

**Non-Goals:**

- Do not change POS return draft resolution rules.
- Do not change bundle trace payload shape.
- Do not normalize bundle trace metadata into a separate table.
- Do not alter Sales Return lifecycle, stock mutation, payment settlement, dispatch behavior, permissions, routes, or UI.
- Do not repair or rewrite historical POS return draft data.
- Do not make the older pending draft-resolution migration idempotent in this change.

## Decisions

### Decision 1: Add a Dedicated Repair Migration

Create a new migration in `Modules/Pos/Database/Migrations` that checks whether `pos_return_lines.line_meta` exists before adding it. The column will be nullable JSON and placed near the existing replacement/metadata fields when supported by the database grammar.

Rationale: this avoids touching migration history and handles databases where the draft-resolution schema is partially present. A fresh or already-correct database can run the repair migration without duplicate-column failure.

Alternative considered: run the pending `2026_05_07_000100_add_draft_resolution_to_pos_return_lines` migration. Rejected because the current database already has most of that migration's columns, so rerunning it can fail before reaching `line_meta`.

Alternative considered: manually add `line_meta` directly in MySQL. Rejected because it does not create a repeatable path for staging, production, or other developer databases.

### Decision 2: Keep `line_meta` JSON as the Metadata Store

Continue using `pos_return_lines.line_meta` for draft-only bundle trace metadata, including `bundle_trace`.

Rationale: existing model casts, submission logic, and tests already treat `line_meta` as a flexible metadata field. The data is not currently a reporting or query contract that needs normalization.

Alternative considered: add a normalized `pos_return_line_bundle_traces` table. Rejected as unnecessary scope for a schema repair and higher risk for a draft-only metadata payload.

### Decision 3: Verify Through the Failing Submit Path

Run a focused POS return submission test that creates or submits an actionable bundled return line and asserts that the draft saves with persisted `line_meta.bundle_trace`.

Rationale: the schema check alone proves the column exists, but the user-facing failure occurs when submit writes bundle trace metadata. Focused behavioral verification covers the actual regression without running the full suite.

Alternative considered: run only migration status and schema inspection. Rejected because it would not prove the Livewire/service submit path is repaired.

## Risks / Trade-offs

- Partial schema drift may differ across environments -> The repair migration must guard with `Schema::hasColumn` before adding or dropping the column.
- JSON column behavior differs between MySQL and SQLite -> Use Laravel schema builder JSON column support and focused Laravel tests in the existing project setup.
- Existing pending migration remains fragile in this branch -> This change intentionally avoids broad migration rewrites; future cleanup can harden the older migration if needed.
- Dropping `line_meta` in rollback could remove draft metadata created after deployment -> Rollback should be treated as a schema rollback for this repair and used only when the repair migration itself must be reverted.

## Migration Plan

1. Add a new timestamped migration under `Modules/Pos/Database/Migrations`.
2. In `up()`, add nullable JSON `line_meta` to `pos_return_lines` only if the table exists and the column is absent.
3. In `down()`, drop `line_meta` only if the table and column exist.
4. Run the new migration in the target environment.
5. Execute focused POS return submission verification for bundled actionable lines.

## Open Questions

None. The selected path is a dedicated repair migration, retained JSON metadata, unchanged migration history, no hardening of the older pending migration, and focused submit-path verification.
