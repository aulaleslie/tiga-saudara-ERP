## Context

The POS approval queue page currently uses native browser `alert()` to display success and error messages to users. These appear as separate OS-level dialogs that interrupt the user experience and visually clash with the modern Bootstrap-based UI of the rest of the application. The codebase already has SweetAlert2 globally available and uses it for other notifications elsewhere (e.g., sell.blade.php, transactions pages), but hasn't yet centralized toast notifications.

## Goals / Non-Goals

**Goals:**
- Replace native `alert()` calls with a consistent toast notification system
- Create a reusable global helper that can be used throughout the application
- Ensure toasts auto-close after 2-3 seconds without requiring user interaction
- Support both success and error message types with appropriate icons and colors
- Maintain consistency with existing SweetAlert usage patterns in the codebase

**Non-Goals:**
- Replace all SweetAlert.fire() confirmation dialogs with toasts (those serve a different purpose)
- Add persistent notification logging or history
- Implement advanced features like toast stacking or priority queues (use simple sequential approach)
- Modify other pages or modules at this time (this change is scoped to approval-queue)

## Decisions

**Decision 1: Use SweetAlert's Built-in Toast Mode**
- **Choice**: Implement toasts using `Swal.fire()` with `toast: true` configuration
- **Rationale**: SweetAlert2 is already a global dependency, supports all required features (auto-close, icons, positioning), and maintains visual consistency with other dialogs in the app
- **Alternatives Considered**:
  - Custom CSS toast: Would require building styling from scratch and managing z-index/positioning
  - Bootstrap toast component: Exists but not currently used, adds another pattern to maintain
  - Toastr.js library: Adds external dependency, SweetAlert already covers our needs

**Decision 2: Create a Global Helper Function**
- **Choice**: Add `window.showToast(message, type, duration)` function to `resources/js/app.js`
- **Rationale**: Provides a simple, reusable API across all blade templates without needing to write Swal config repeatedly
- **Location**: Added to app.js so it's available globally on all pages
- **Signature**: `showToast(message, type = 'success', duration = 2000)`

**Decision 3: Toast Positioning and Behavior**
- **Choice**: Top-end position (top-right corner), auto-close after 2 seconds, no confirm button
- **Rationale**: Follows common web app conventions (non-intrusive, visible but out of the way), 2 seconds gives users time to read but doesn't linger
- **Configuration**:
  ```javascript
  {
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2000,
    timerProgressBar: true
  }
  ```

**Decision 4: Scope to Approval Queue Only (for now)**
- **Choice**: Replace only the 3 `alert()` calls in approval-queue/index.blade.php
- **Rationale**: Focused change reduces risk, and approval-queue is a good test case. Other pages can adopt toasts in future changes
- **Future**: Once proven, recommend adopting in other modules that use alert()

## Risks / Trade-offs

**[Risk] Toast messages may be missed if users aren't looking at the top-right**
→ Mitigation: 2-second duration is reasonable for approval actions (not critical operations). Users can always check the queue status by refreshing. For truly critical operations in future, use confirmation dialogs instead.

**[Risk] Multiple toasts firing simultaneously could stack**
→ Mitigation: Current approval queue flow is sequential (approve → wait for response → load queue). Unlikely to have overlapping toasts. If it becomes an issue, add toast queuing logic later.

**[Trade-off] No persistent toast history**
→ Accepted because: Approval actions are transactional and users can verify by checking updated queue. Adding history storage is overkill for this use case.

**[Risk] SweetAlert version changes could affect toast API**
→ Mitigation: Encapsulating in a helper function means we can update the implementation in one place if needed.

## Migration Plan

**Deployment:**
1. Deploy `app.js` with new `showToast()` function
2. Deploy updated `approval-queue/index.blade.php` with toast calls
3. No database changes, no rollback complexity

**Rollback (if needed):**
- Revert approval-queue/index.blade.php to use `alert()` (can happen independently)
- The `showToast()` function in app.js is dormant if unused, no harm in keeping it

**Testing:**
- Manual test: Visit approval queue, approve/reject a request, verify toast appears and auto-closes
- Browser console: Verify no JavaScript errors

## Open Questions

None at this time. Proposal and design are aligned.
