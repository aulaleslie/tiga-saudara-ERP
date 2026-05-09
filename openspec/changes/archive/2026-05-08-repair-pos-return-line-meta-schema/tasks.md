## 1. Schema Repair

- [x] 1.1 Add a new migration under `Modules/Pos/Database/Migrations` that repairs `pos_return_lines.line_meta`.
- [x] 1.2 In the migration `up()`, add nullable JSON column `line_meta` only when `pos_return_lines` exists and the column is absent.
- [x] 1.3 In the migration `down()`, drop `line_meta` only when `pos_return_lines` exists and the column is present.

## 2. Focused Coverage

- [x] 2.1 Add or update a focused POS return submission test for an actionable bundled draft line that writes `line_meta.bundle_trace`.
- [x] 2.2 Assert the POS return draft saves successfully and the persisted return line contains `bundle_trace` under `line_meta`.

## 3. Verification

- [x] 3.1 Run the new repair migration and confirm `pos_return_lines.line_meta` exists on the local database.
- [x] 3.2 Run the focused POS return submit test that covers bundled draft metadata persistence.
- [x] 3.3 Confirm the change does not require edits to POS return business logic, Sales Return lifecycle code, stock mutation behavior, or migration history.
