## Why

The POS sessions list currently displays inconsistent metrics across session statuses. The "Metrik" column shows transaction counts for OPEN sessions but variance amounts for CLOSED sessions. The "Aktivitas Terakhir" column shows timestamps for OPEN sessions but dashes for CLOSED ones. This creates confusion about what each column represents and prevents operators from understanding transaction activity across all session states.

Additionally, the current implementation doesn't distinguish between terminal and non-terminal sessions when tracking transaction activity. Terminal sessions track cash completions, while non-terminal sessions (floor staff workflow) should track total transaction drafts created, providing complete visibility into transaction volume regardless of checkout completion status.

## What Changes

- **Metrik column** now consistently shows transaction volume for all session statuses (OPEN, CLOSED, CLOSING, FINALIZED)
  - For sessions WITH terminal: count of CASH_SALE_IN events (checkout completions)
  - For sessions WITHOUT terminal: count of all PosTransaction records created in the session (total draft volume)
  
- **Aktivitas Terakhir column** now consistently shows the most recent transaction activity timestamp for all session statuses
  - For sessions WITH terminal: last CASH_SALE_IN event timestamp
  - For sessions WITHOUT terminal: last PosTransaction created_at timestamp
  - Format: HH:mm for OPEN sessions, full datetime for CLOSED sessions
  
- Remove conditional status-based logic that previously hid metrics for CLOSED sessions
- Ensure consistent column definitions across all status filters (Semua, Aktif, Selesai)

## Capabilities

### New Capabilities
- `pos-session-activity-tracking-by-terminal-type`: Track and display transaction volume and activity timestamps differently for terminal vs non-terminal sessions, providing accurate metrics for both cashier (cash events) and floor staff (transaction drafts) workflows

### Modified Capabilities
- `pos-sessions-list`: Update the Sessions list table to display consistent Metrik and Aktivitas Terakhir columns for all session statuses, with data sources determined by terminal presence

## Impact

- **Backend (PosSessionController.index)**: Add eager load counts and max timestamps for PosTransaction to distinguish non-terminal session activity
- **Frontend (session/index.blade.php)**: Update Metrik and Aktivitas Terakhir column logic to use terminal-aware data sources and remove status-based conditionals
- **Database queries**: Minimal impact; reuses existing relationships with additional withCount/withMax aggregations
