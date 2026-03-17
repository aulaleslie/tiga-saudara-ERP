## Context

The multi-stage payment flow was implemented to allow sequential payment stages using different methods (cash, BRI, BNI, etc.) for a single transaction. The frontend tracks a running `remainder` value and updates the display after each stage.

**Current Bug**: The frontend sends `grand_total: remainder + amount` instead of the original grand total.
- Example: 60,000 total, user pays 40,000 → frontend sends `grand_total: 60,000 + 40,000 = 100,000`
- Backend initializes: `remainder = 100,000 - 40,000 = 60,000`
- Backend returns remainder = 60,000 (should be 20,000)
- Frontend displays: "Sisa Pembayaran = 60,000" ❌

**Current State**:
- `public/js/pos-staged-payment.js` contains the state machine for staged payments
- `Modules/Pos/Http/Controllers/PosSellController.php::stagePayment()` handles stage submission
- Payment method search and selection work but the input field lacks visual distinction
- Validation exists at the backend but frontend lacks comprehensive method-specific rules

**Constraints**:
- Session-based payment chain tracking (no database writes until finalization)
- Must maintain idempotency and reload recovery
- Change must work with existing POS payment finalization flow

## Goals / Non-Goals

**Goals:**
- Fix remainder calculation to correctly track balance across all payment stages
- Implement frontend validation rules that vary by payment method type (cash vs non-cash)
- Improve visual feedback for payment method selection (background styling)
- Ensure EDC reference requirement is enforced for non-cash methods

**Non-Goals:**
- Do NOT add quick-amount preset buttons (can be added in separate feature)
- Do NOT change the backend remainder calculation logic (only fix how grand_total is sent)
- Do NOT modify the finalization or receipt flow

## Decisions

### Decision 1: Send original grand_total instead of (remainder + amount)
**Chosen**: Store the original grand_total in the frontend `paymentChain` object and always send it to the backend, never (remainder + amount).

**Rationale**:
- Line 374 currently sends: `grand_total: paymentChain.remainder + amount`
- This is wrong because `remainder` is the RUNNING balance, not the original transaction total
- Fix: send the constant original grand_total instead
- Backend logic is correct; it just receives the wrong grand_total value

**Implementation**:
```javascript
// In paymentChain object:
paymentChain = {
    cart_token: "...",
    original_grand_total: 60000,  // Store at initialization, NEVER change
    remainder: 60000,              // Running balance, updates each stage
    payments: []
}

// In submitStagePayment, line 374:
const payload = {
    ...
    grand_total: paymentChain.original_grand_total,  // Send original, not (remainder + amount)
}
```

**Alternatives considered**:
- Pass grand_total from page reload: Fragile; may be lost on reload
- Fix backend to track original_grand_total: Violates principle of minimal change
- Chose this because the fix is in one line (374) and preserves backend correctness

---

### Decision 2: Method-specific validation rules in frontend
**Chosen**: Implement validation as a function that checks `is_cash` flag and applies different rules.

**Rationale**:
- Cash users expect to pay more than due for change (e.g., 100,000 due, pay 120,000 cash)
- Non-cash users cannot overpay (EDC/bank transfer is exact amount only)
- Frontend validation prevents bad requests from reaching backend
- Consistent with existing `updateStageValidation()` pattern in codebase

**Implementation**:
```javascript
function validateAmountForMethod(amount, remainder, method) {
    if (method.is_cash) {
        // Cash: amount >= remainder (can overpay)
        return amount >= remainder;
    } else {
        // Non-cash: amount <= remainder (no overpay)
        return amount <= remainder;
    }
}
```

**Alternatives considered**:
- Backend-only validation: Poorer UX (users see error after submit)
- Heuristic based on method name: Fragile; must use is_cash flag
- Chose this because it's explicit, follows existing pattern, and improves UX

---

### Decision 3: Add background styling to payment method input
**Chosen**: Add `style="background-color: #fff;"` to the payment method search input and apply Bootstrap's `.form-control` styling consistently.

**Rationale**:
- Input currently has no background, making it hard to see selection state
- Simple CSS change, no JS impact
- Bootstrap form-control already provides outline/border styling for focus

**Implementation**:
- Ensure input has `class="form-control"` with default styling
- Override any transparent backgrounds inherited from modals

**Alternatives considered**:
- Add custom CSS class: Same result; inline style is simpler for small change
- Add visual badge after selection: Adds complexity; not necessary if input is visible
- Chose this because it's minimal and immediately solves the visibility issue

---

### Decision 4: EDC reference validation for non-cash methods
**Chosen**: Check `selectedPaymentMethod.requires_reference` in frontend validation and prevent submission if empty.

**Rationale**:
- Existing backend validates format (alphanumeric, max 20 chars)
- Frontend should prevent empty references; backend already rejects invalid formats
- Matches existing pattern in `validateBeforeSubmit()`

**Implementation**:
- Existing code at line 433-438 in pos-staged-payment.js already does this
- Only enhancement: ensure message is clear and field gets focus on error

**Alternatives considered**:
- Auto-populate reference field: Users should provide their own receipt reference
- Remove frontend check and rely on backend: Worse UX (users submit, get error)
- Chose current approach; backend format validation is adequate

---

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Remainder mismatch if original grand_total not preserved on page load** | Ensure payload includes grand_total sent from server; JS tests verify grand_total is used consistently |
| **Cash overpayment changes break existing behavior** | Check existing transactions; none show overpayment yet (feature is new) |
| **EDC reference validation blocks valid references** | Frontend regex is already correct (alphanumeric 1-20); backend validates again |
| **Payment method input styling breaks on certain browsers** | Bootstrap form-control is well-tested; standard CSS |

## Migration Plan

1. **Deploy frontend JS changes**:
   - Update `public/js/pos-staged-payment.js` with new validation and grand_total tracking
   - No backend changes required; payment flow remains compatible

2. **Deploy template updates**:
   - Add background styling to payment method input in sell.blade.php
   - No database migrations needed

3. **Testing**:
   - E2E test: Submit non-cash payment 40,000 of 60,000 total → verify remainder shows 20,000
   - E2E test: Cash overpayment 120,000 of 100,000 total → verify change = 20,000
   - E2E test: Non-cash payment 120,000 of 100,000 total → verify rejected

4. **Rollback**:
   - Revert JS file and template to previous versions
   - No data cleanup needed (session-based only)

## Open Questions

- Should we add quick-amount buttons (e.g., +10k, +50k, +100k) in this change or separate feature? → Decision: Separate feature, can be added later
- Any existing transactions using multi-stage payments we should be aware of? → Check if any transactions in DB use payment_chain sessions
