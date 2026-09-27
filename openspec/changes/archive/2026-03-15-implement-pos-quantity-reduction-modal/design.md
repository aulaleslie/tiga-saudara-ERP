## Context

The POS module's cart currently allows all users to edit quantities directly via a single input field. For non-privileged users, this creates an audit trail gap—quantity reductions should be intentional and require approval. The existing ApprovalManager system already handles approval workflows for actions like `CART_CLEAR` and `LINE_REMOVE`, but quantity reductions currently lack a clear UI affordance and approval pathway for non-privileged users.

**Current State:**
- All users see the same quantity input field
- Quantity reductions (newQty < prevQty) trigger ApprovalManager workflow regardless of privilege
- Non-privileged users can accidentally reduce quantities without clear intent
- No distinction between "increment" (always free) and "reduce" (always approval-gated)

**Desired State:**
- Non-privileged users have an increment-only input + dedicated Reduce button
- Privileged users retain full quantity control (current behavior unchanged)
- All quantity reductions from any user follow the same approval workflow
- UI explicitly communicates the "request reduction" action

## Goals / Non-Goals

**Goals:**

1. Implement privilege-based dual-input system for quantity controls in the POS cart
2. Provide a dedicated modal for non-privileged users to request quantity reductions
3. Enforce input-level validation to prevent accidental reduction via direct input
4. Integrate reduction requests seamlessly with existing ApprovalManager workflow
5. Maintain backward compatibility for privileged users (no behavior change)
6. Support both serial and non-serial cart line rendering

**Non-Goals:**

- Changing the approval workflow itself (uses existing ApprovalManager)
- Modifying checkout validation or stock checking (happens at checkout, not in cart)
- Creating new backend endpoints (uses existing PATCH /pos/sell/cart/lines/{id})
- Changing how privilege capabilities are defined (uses existing roleCapabilities system)
- Adding new privilege types (uses existing `can_reduce_quantity` or similar)

## Decisions

### 1. Privilege Check Location: Frontend vs. Backend

**Decision:** Privilege check performed in frontend (during cart rendering and event handlers), with backend validation as secondary safeguard.

**Rationale:**
- Frontend check provides immediate UX feedback (different controls for privileged vs. non-privileged)
- Reduces unnecessary approval requests from privileged users
- Backend should still validate to prevent privilege escalation via direct API calls
- Aligns with existing pattern (roleCapabilities already passed to frontend)

**Alternatives Considered:**
- Backend-only checks: Would require API calls for every quantity change; slower UX for privileged users
- Bi-directional (frontend + backend): Chosen—frontend optimizes UX, backend ensures security

### 2. Modal Trigger & Form Design

**Decision:** Small button (↓ icon) next to quantity input that opens a SweetAlert2 modal with new_qty and reason fields.

**Rationale:**
- Button makes "reduce" action explicit and intentional
- Modal captures context (reason) alongside quantity, aiding approval decisions
- Reuses existing SweetAlert2 setup (already used for approval modals)
- Icon (↓) is universally recognized as "reduce"
- Non-intrusive placement doesn't clutter the UI

**Alternatives Considered:**
- Popup menu (right-click): Less discoverable; not mobile-friendly
- Inline reason textarea: Clutters cart; takes screen real estate
- Automatic modal on input blur (if input < current): Confuses UX; users might think it's an error

### 3. Validation Strategy

**Decision:** Dual-layer validation—input-level (HTML5 + JS) and event-handler-level (before ApprovalManager call).

**Rationale:**
- Input-level (HTML5 max attribute, JS change listener) prevents accidental direct reduction
- Event-handler validation provides fallback for edge cases (e.g., race conditions)
- Clear error messaging guides user to the correct action (Reduce button)
- Consistent with existing validation pattern in cart.js

**Alternatives Considered:**
- Backend-only validation: Would require round-trip; poor UX for users entering invalid qty
- Single-layer client-side: Vulnerable to manual API calls or debugger manipulation
- No validation, just block reduction: Confuses users; doesn't explain why input was rejected

### 4. Approval Workflow Integration

**Decision:** Reduction requests from non-privileged users flow through existing `ApprovalManager.wrapAction()` with action_type='QTY_REDUCE', identical to privileged users.

**Rationale:**
- Reuses battle-tested ApprovalManager logic
- Creates audit trail for all reductions (privileged or not)
- Leverages existing approval request, check, and cancellation endpoints
- Reason field is passed as `payload.reason`, consistent with existing patterns

**Alternatives Considered:**
- Separate approval endpoint for non-privileged reductions: Duplicates code; harder to maintain
- Immediate application for non-privileged reductions: Loses audit trail; violates approval requirement
- Role-based approval thresholds (e.g., >50% reduction requires approval): Out of scope; keep uniform

### 5. Serial Line Rendering

**Decision:** For serial-number-required lines, render both the serial management UI (quantity + serial button) and the Reduce button (for non-privileged users).

**Rationale:**
- Maintains serial UX (users still assign/remove serials)
- Reduce button appears consistently alongside quantity for all line types
- Non-privileged users can still manage serials while being restricted from direct quantity reduction

**Alternatives Considered:**
- Hide reduce button for serial lines: Inconsistent UX; confusing
- Disable serial management for non-privileged users: Out of scope; orthogonal concern
- Single consolidated serial reduction modal: Over-complicates modal logic; unclear interaction

## Risks / Trade-offs

**[Risk] UI Clutter on Serial Lines**
- Serial lines already have quantity input + serial button; adding Reduce button may make the line visually crowded
- **Mitigation:** Use compact button styling (sm size, icon-only initially); consider horizontal flex layout with gap control

**[Risk] Modal Dismissal Without Submission**
- Users might open the modal, read the fields, close it without intending to cancel
- **Mitigation:** Confirm on close if any field is filled (similar to existing approval modal patterns); default action is Cancel (safe)

**[Risk] Race Condition: Quantity Changes During Modal Open**
- If cart updates while modal is open (e.g., another user reduces the qty), the modal's "max" value becomes stale
- **Mitigation:** Modal captures current qty at open time; if changed externally, cart re-renders and modal closes; user can retry

**[Risk] Approval Request Lost if User Navigates Away**
- User submits reduction request, doesn't wait for response, navigates away; request is "pending" but invisible
- **Mitigation:** Status message at cart-level persists; existing "Periksa Persetujuan" (Check Approval) pattern handles this

**[Trade-off] Reason Field Optional**
- Reduces friction for quick reductions but loses context in some cases
- **Decision:** Keep optional; users can add reason if relevant; approval system can still request more info if needed

**[Trade-off] No Real-time Stock Sync in Cart**
- Cart allows over-quantity (buying more than available stock) without warning
- **Decision:** Per requirements, validation happens only at checkout; allows flexibility for physical scenarios (stock on hand but not in system)

## Migration Plan

1. **Code Changes:**
   - Modify `/Modules/Pos/Resources/views/sell.blade.php`:
     - Update `renderLineRow()` to conditionally render quantity controls based on `canReduceQuantity` flag
     - Add modal HTML and JS handler for reduction modal
     - Update `cartBody.addEventListener('change', ...)` to validate based on privilege
   - Add Reduce button click handler in cart event delegation

2. **Frontend Feature Flag (Optional):**
   - Can add a feature flag to toggle the modal behavior if rollback needed during testing

3. **Testing:**
   - Unit tests for privilege check logic
   - Integration tests for modal submission (privileged and non-privileged)
   - Manual POS cashier testing with both privilege levels

4. **Rollback Strategy:**
   - If critical bug discovered: Revert sell.blade.php changes; all quantity changes default to current behavior (approval required for reduction)
   - No database migration needed (no schema changes)

## Open Questions

1. **Backend Privilege Check**: Should the cart line PATCH endpoint validate that non-privileged users cannot directly PATCH with qty < current (via API)? Or is frontend validation sufficient?
   - **Suggestion:** Add backend check for defense-in-depth; prevents privilege escalation attacks

2. **Reason Field Length Limit**: Should the reason textarea have a character limit? (e.g., 255 chars)
   - **Suggestion:** Yes, 255 characters aligns with database field sizes

3. **Modal Accessibility**: Should the modal support keyboard shortcuts (e.g., Escape to cancel, Enter to submit)?
   - **Suggestion:** SweetAlert2 handles Escape; ensure Tab focus management works in modal

4. **Multiple Reduction Requests**: Can a user submit multiple reduction requests for the same line while the first is pending?
   - **Suggestion:** Disable Reduce button while a request is pending; clear button state once approval is processed or expired
