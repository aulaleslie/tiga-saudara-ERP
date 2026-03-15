## Why

Terminal cash thresholds are currently unclear to operators—form fields lack descriptions and default values, leading to confusion about when pickups should occur. Additionally, supervisors have no streamlined way to initiate cash pickups directly from the POS terminal, requiring them to navigate to separate monitoring dashboards. This change brings configuration clarity and puts pickup control directly in supervisors' hands at the point of sale.

## What Changes

- **Terminal Configuration Form**: Remove unused `close_variance_approval_threshold` field; clarify `cash_threshold` with description, default value (5,000,000), and currency formatting guidance
- **POS Dropdown Menu**: Add "Pengambilan Kas" (Cash Pickup) option to the top-right navigation menu
- **Cash Pickup Modal**: Two-step modal flow from POS terminal:
  1. Enter pickup amount with real-time validation (cannot exceed expected cash)
  2. Provide supervisor email + password for credential verification
- **Supervisor Approval**: Leverage existing `PosSupervisorApprovalService` to validate supervisor credentials and permission to approve `pos.safeDrops.approve`
- **Success Feedback**: Toast notification on successful pickup with updated expected cash total
- **API Endpoint**: New `POST /pos/sessions/{id}/pickup` to bridge POS UI with existing `PosSafeDropService`

## Capabilities

### New Capabilities
- `cash-pickup-from-pos`: Supervisors can initiate cash pickups directly from POS terminal with email+password authentication and real-time validation
- `terminal-cash-threshold-config`: Clear configuration of cash threshold with description, default value, and currency formatting for terminals

### Modified Capabilities
<!-- No existing specs require behavior changes -->

## Impact

- **Files Modified**:
  - `Modules/Pos/Resources/views/terminals/_form.blade.php` (remove field, enhance cash_threshold)
  - `Modules/Pos/Resources/views/sell.blade.php` (add dropdown item, new modal, JavaScript handler)
  - `Modules/Pos/Http/Controllers/PosSessionController.php` (new pickup endpoint)
  - `Modules/Pos/Routes/web.php` (register new route)

- **Dependencies**: Uses existing `PosSafeDropService`, `PosSupervisorApprovalService`, `PosSessionMonitorService`

- **Permissions**: Requires `pos.safeDrops.approve` for supervisor credential validation

- **Breaking Changes**: None (removal of unused field only)
