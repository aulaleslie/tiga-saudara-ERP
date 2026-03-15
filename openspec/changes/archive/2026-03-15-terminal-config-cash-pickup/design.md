## Context

The POS system currently has a well-established safe drop workflow (`PosSafeDropService`) and supervisor approval system (`PosSupervisorApprovalService`), but it's only accessible through a separate monitoring dashboard. Supervisors on the POS floor need a faster way to initiate cash pickups without leaving the terminal. Additionally, terminal configuration has ambiguous fields that confuse operators about cash management thresholds.

Current state:
- `PosSafeDropService::createSafeDrop()` handles cash event creation, validation, and drawer triggers
- `PosSupervisorApprovalService` validates supervisor credentials (email + password) and permissions
- `PosSessionMonitorService` displays all open sessions with threshold breach highlighting
- Terminal policies stored in `PosTerminalPolicy` with `cash_threshold` (used) and `close_variance_approval_threshold` (unused)
- POS sell view has a top-right dropdown menu with navigation options
- `showToast()` global function available for non-blocking notifications

## Goals / Non-Goals

**Goals:**
1. Enable supervisors to initiate cash pickups directly from POS terminal without navigating to monitoring dashboard
2. Clarify terminal cash threshold configuration with descriptions, defaults, and currency formatting
3. Reuse existing safe drop and approval services (no new domain logic)
4. Provide immediate feedback via toast notifications on success/failure
5. Maintain security: require supervisor credentials and permission checks before processing

**Non-Goals:**
- Implement a new approval workflow (reuse existing PosSupervisorApprovalService)
- Add cash denomination input (keep simple amount-only)
- Create a monitoring dashboard enhancement (focus on POS terminal action)
- Add real-time cash level synchronization across terminals
- Implement partial pickup scenarios (e.g., "safe drop to threshold")

## Decisions

### Decision 1: Use two-step modal for pickup flow
**Choice**: Two-step modal (amount → credentials) rather than single-step dialog
**Rationale**:
- Step 1 (amount) confirms supervisor intent early, preventing accidental credential entry
- Step 2 (credentials) separates sensitive input from transaction confirmation
- Reduces cognitive load vs. showing all fields at once
**Alternatives Considered**:
- Single-step modal with all fields: faster but risky (supervisor might enter credentials by mistake on wrong amount)
- Separate pages: too disruptive to POS workflow
- Direct input box: insufficient validation context

### Decision 2: Reuse existing PosSafeDropService for pickup logic
**Choice**: Call existing `createSafeDrop()` without modification
**Rationale**:
- Avoid code duplication and business logic divergence
- Service already handles: amount validation, expected cash checks, drawer triggering, cash event creation
- Maintains audit trail and permissions checks
**Alternatives Considered**:
- Create new PosCashPickupService: more explicit but duplicates validation logic
- Modify PosSafeDropService: risky if changes break other safe drop use cases

### Decision 3: Use supervisor email + password (not PIN)
**Choice**: Authenticate supervisor via email and password login credentials
**Rationale**:
- PosSupervisorApprovalService already validates password via `Hash::check()`
- Simpler for users (one password instead of separate PIN)
- Leverages existing User model and authentication
- Consistent with other approval flows in the system
**Alternatives Considered**:
- Separate supervisor PIN: requires additional field management
- Biometric: scope creep for this change

### Decision 4: Validate supervisor permission `pos.safeDrops.approve`
**Choice**: Check permission after successful credential validation
**Rationale**:
- PosSupervisorApprovalService already enforces this permission
- Prevents unauthorized users from exploiting a leaked password
- Clear access control: only those with explicit permission can approve pickups
**Alternatives Considered**:
- Only check credentials (no permission): insufficient security
- Check role instead of permission: less flexible for future role changes

### Decision 5: Show toast notification with updated cash total on success
**Choice**: Toast with message + expected_cash_after value
**Rationale**:
- Non-blocking feedback (doesn't require user action like alert())
- Immediate confirmation that pickup succeeded
- Shows new expected_cash helps supervisor verify amount was deducted
- Toast auto-dismisses after 2 seconds (global default)
**Alternatives Considered**:
- Modal confirmation: blocks further actions
- Page reload: disrupts POS workflow

### Decision 6: Remove close_variance_approval_threshold field entirely
**Choice**: Delete field and create migration
**Rationale**:
- Grep search found zero references to this field in codebase
- Reduces form complexity and confusion
- Clearing technical debt before moving forward
**Alternatives Considered**:
- Keep field but hide it: adds hidden complexity
- Deprecate gradually: not worth migration burden for unused field

### Decision 7: Session data accessed via activeSession variable and PosSessionMonitorService
**Choice**: Pass activeSession from controller to view; use API endpoint to fetch session summary for modal
**Rationale**:
- `$activeSession` already passed to sell.blade.php
- Session data includes terminal, cashier, expected_cash (rendered in page)
- Modal can fetch fresh session state via API to ensure accuracy
- Decouples UI state from API state
**Alternatives Considered**:
- Embed all session JSON in page: overkill, data already rendered
- Use Livewire: scope creep, changes architecture

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Supervisor password leakage** → unauthorized pickups | Require explicit `pos.safeDrops.approve` permission; log all approvals in `PosSupervisorApproval` audit table |
| **Amount entry mistakes** → wrong cash picked up | Two-step modal allows review before credentials; amounts logged in `PosSessionCashEvent` |
| **Network failure during pickup** → partial state | PosSafeDropService uses DB transaction; atomic operation ensures cash event either fully created or fully rolled back |
| **Modal not accessible on small screens** → supervisor can't use feature | Modal uses Bootstrap modal (responsive); test on mobile devices during QA |
| **Threshold confusion persists despite description** → misconfiguration | Add example value + formatted placeholder to form; consider future admin UX review |
| **Migration drops data** → loss of close_variance values | Migration includes manual backup notice; values are unused so no functional loss |

## Migration Plan

1. **Backward Compatibility**: No breaking changes to API (new endpoint only)
2. **Database Migration**: Create migration to drop unused `close_variance_approval_threshold` column from `pos_terminal_policies` (safe—field never used)
3. **Form Rollout**: Form change deployed immediately with no migration needed (new field validation added)
4. **Feature Rollout**: Can deploy without feature flag (permission check gates access)
5. **Rollback**: If serious issue discovered:
   - Remove pickup dropdown item from view
   - Remove API endpoint route
   - Keep database migration (field already unused)
   - Modal and JavaScript can stay (harmless if not triggered)

## Open Questions

1. **Should pickup bypass the threshold?** Current design allows pickup at any amount ≤ expected_cash, regardless of threshold. Confirm this is desired (user said "threshold is one reason, but not the only one").
2. **Should system store pickup denominations?** Current PosSafeDropService accepts optional denomination breakdown. Should POS pickup modal require or suggest this?
3. **Should pickup require two-person approval?** Current design requires only supervisor credentials. Should system enforce "two people in room" for large pickups?
4. **Toast timer duration** — Using global default of 2000ms. Acceptable or should it be longer (3000ms) for cash amounts?
