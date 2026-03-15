## Why

The POS sessions table view displays misaligned columns and data rows due to conditional column rendering in the HTML template. When filtering by session status (OPEN vs CLOSED), different columns appear in the `<thead>` and `<tbody>` at different positions, causing data to display under wrong headers. This creates confusion and makes the data hard to read.

## What Changes

- Standardize the table structure to always render all columns, regardless of session status
- Show `-` placeholders in status-specific columns when data is not applicable (e.g., "Trx" count for closed sessions)
- Update the Blade template to remove conditional column rendering logic
- Simplify the table with consistent column ordering across all session statuses

## Capabilities

### New Capabilities
- `pos-sessions-table-normalization`: Unified POS sessions table with consistent columns for all session statuses

### Modified Capabilities
- `pos-sessions-list`: Updated requirements for the sessions index view to always display a normalized table structure

## Impact

- **Code**: Modules/Pos/Resources/views/session/index.blade.php
- **Behavior**: The table will be consistent across all status filters, with all columns always visible
- **User Experience**: Clearer data alignment improves readability and reduces user confusion
