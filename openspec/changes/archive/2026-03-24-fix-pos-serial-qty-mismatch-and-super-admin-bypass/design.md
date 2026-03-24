## Context

Currently, when a user reduces the quantity on a serial-required cart line, `PosCartService.updateLine()` clears all assigned serials (lines 262-266). This contradicts the guard logic immediately above it (lines 247-252) which prevents qty reduction below the assigned serial count.

Additionally, `PosCartActionAuthorizationService.authorize()` has no Super Admin detection. It requires either a direct permission (`pos.cart.line.reduce`) or an approval token, even for users with Super Admin role who should bypass all restrictions.

The frontend validation (sell.blade.php:2423-2430) already correctly validates that `assignedCount === qty` for serial-required items and blocks save/checkout when mismatched. We need to preserve serials on qty change and let this frontend validation enforce the constraint.

## Goals / Non-Goals

**Goals:**
1. Preserve assigned serials when quantity changes, eliminating user re-entry friction
2. Leverage existing frontend validation to block save/checkout until serial-qty mismatch resolves
3. Allow Super Admin users to reduce quantity without requiring supervisory approval
4. Maintain backward compatibility with existing approval workflows for non-Super-Admin users

**Non-Goals:**
- Auto-trim serials to match new quantity (requires user decision on which to keep)
- Change serial assignment UI or modal behavior
- Modify the supervisory approval infrastructure itself
- Handle serial removal (separate from qty changes)

## Decisions

### Decision 1: Preserve Serials on Qty Change
**Choice**: Remove the serial clearing logic (lines 262-266 in `PosCartService.updateLine()`), preserving `assigned_serials` across qty mutations.

**Rationale**:
- User intent matters. If they assigned 2 serials and reduce qty to 1, they likely intend to keep those serials and will adjust.
- Frontend validation already exists and correctly blocks save when mismatch occurs.
- Preserving context reduces friction vs. forcing re-entry.
- The guard (lines 247-252) already prevents `qty < serialCount`, but that guard contradicts the clearing behavior. Removing the clear resolves this contradiction.

**Alternatives Considered**:
- Auto-trim serials to new qty: Risky - assumes removing from end, user can't specify which to keep
- Require user to manually pick which serials to remove: More complex UI, same outcome as user manually removing them in current flow

### Decision 2: Super Admin Bypass in Authorization
**Choice**: Add role check in `PosCartActionAuthorizationService.authorize()` before permission/token checks. If user has `Super Admin` role, auto-authorize without checking permission or token.

**Rationale**:
- Super Admin should have unrestricted access across the system
- Existing `pos-supervised-cart-actions` spec (line 103-109) already documents Super Admin privilege for cart clear; extending to qty reduce is consistent
- Simple, non-invasive change: one `if` statement before existing checks
- Doesn't affect non-Super-Admin authorization flow

**Alternatives Considered**:
- Grant `pos.cart.line.reduce` permission to all Super Admin role assignments: Verbose, requires role/permission updates
- Create new Super Admin permission check method: Overengineering for a single use

### Decision 3: Frontend Mismatch Messaging
**Choice**: Enhance error display in sell.blade.php when `allSerialsValid` is false, showing which line has the mismatch.

**Rationale**:
- Current validation blocks buttons but doesn't explain why
- User needs to know: "Line 1 has 2 serials but qty is 1"
- Minimal UI change; reuse existing error message area

**Alternatives Considered**:
- Add inline chip warnings on each serial-required line: More complex CSS, but could be done later
- Modal warning: Interrupts workflow unnecessarily

### Decision 4: Guard Behavior After Serial Preservation
**Choice**: Keep the guard (lines 247-252) that prevents `qty < serialCount`, but now the guard merely prevents an invalid state from being reached. If user has serials assigned, they must remove them before reducing qty below their count (or increase qty back to match).

**Rationale**:
- Ensures DB consistency: cart never stores `qty < serialCount`
- User will see save/checkout blocked by frontend validation, prompting them to fix the mismatch
- Guard provides defense-in-depth at API level

**Alternatives Considered**:
- Remove the guard: Allows inconsistent state in cart, relies entirely on frontend
- Auto-remove oldest N serials: Destructive, user loses context

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| User confused why save is blocked after qty reduction | Enhanced error messaging explains the serial-qty mismatch clearly |
| Qty reduction with serials assigned feels broken (buttons disabled) | Clear inline error + save/checkout button disabled state + error message clarifies expected action |
| Super Admin bypass bypasses too much (security concern) | Super Admin role is top-tier; trusting this level of privilege is intentional system design. No new attack surface. |
| Serial preservation across qty fluctuations could be confusing UX | Frontend validation and clear messaging mitigate; user explicitly removes serials to match qty, not auto-wiped |
| Performance: Serials now persisted longer in session | Negligible; JSON array in session, same session storage layer as before |

