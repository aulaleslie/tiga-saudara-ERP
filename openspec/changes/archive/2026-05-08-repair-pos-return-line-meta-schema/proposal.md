## Why

POS return draft submission can fail for bundled actionable lines when the service persists bundle trace metadata into `pos_return_lines.line_meta`, but the current MySQL schema is missing that column. The code, model casts, and draft-resolution behavior already treat `line_meta` as the storage point for draft-only bundle trace JSON, so the database needs a narrow forward repair.

## What Changes

- Add a repair migration that creates nullable JSON column `pos_return_lines.line_meta` only when the column is absent.
- Keep `line_meta` as the persisted JSON container for draft POS return line metadata, including `bundle_trace`.
- Leave existing migration history unchanged, including the already-recorded draft-resolution migration and the pending historical migration file.
- Do not change POS return submission business logic, Sales Return lifecycle behavior, stock mutation rules, or draft resolution semantics.
- Verify the fix through a focused POS return submit path that exercises bundled actionable draft lines.

## Capabilities

### New Capabilities
- `pos-return-line-meta-schema-repair`: Covers schema compatibility for POS return draft line metadata persistence.

### Modified Capabilities
- None.

## Impact

- Affected schema: `pos_return_lines` table.
- Affected implementation surface: POS Return draft submission and edit flows that persist bundle trace metadata through `PosReturnSubmissionService`.
- Affected tests: focused POS Return submission test coverage for bundled actionable lines and persisted `line_meta.bundle_trace`.
- No external APIs, permissions, routes, UI behavior, or Sales Return execution behavior are changed.
