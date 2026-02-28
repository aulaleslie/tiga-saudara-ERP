# POS MVP Parallel-Run & Support SOP

**Date:** 2026-02-28
**Scope:** POS Phase 1 (MVP) Operational Rollout

This Standard Operating Procedure (SOP) defines the operational guidelines during the "Parallel Run" transitional phase where the new POS and legacy sales processes are both active.

---

## 1. Purpose and Scope

To validate the stability and accuracy of the new POS module without disrupting store operations or corrupting financial data. During this phase, stores will process a percentage of live transactions through the POS while relying on legacy fallback methods as needed.

## 2. Duplicate Transaction Prevention Rule (CRITICAL)

**Rule: A transaction processed successfully in the new POS MUST NOT be re-entered into the legacy sales screen or manual invoice portal.**

Because the POS uses "Hybrid Posting" (it writes directly to the existing `sales`, `dispatch`, and `sale_payments` tables), the ERP ledger is updated immediately. Re-entering a POS transaction in the old system will result in double stock deduction and double revenue reporting.

## 3. Cashier Daily Procedure (POS Flow)

1. **Shift Start:**
   - Log in to the ERP.
   - Navigate to `/pos`.
   - Select your terminal and enter your **Opening Float** (must match drawer contents).
2. **Operations:**
   - Scan/search items and process sales.
   - For Price Overrides or voids, call supervisor for PIN approval.
   - Collect cash/transfer/QRIS and confirm payment. Hand receipt to customer.
3. **During Shift (Safe Drops):**
   - If the system warns you about a Cash Threshold breach, notify your supervisor immediately.
   - Perform a **Safe Drop** through the POS UI. Hand the cash to the supervisor for security.
4. **Shift End:**
   - Count drawer cash manually.
   - Enter your counted cash total into the POS "Close Session" screen.
   - If you have a variance (short/over) above the store threshold, your supervisor must enter their PIN to approve the shift close.

## 4. Supervisor Daily Procedure

1. **Monitoring:**
   - Keep the **Live Session Monitor** dashboard open.
   - Watch for threshold warnings (highlighted rows).
2. **Approvals:**
   - Enter PIN when requested by cashiers for overrides, safe drops, or shift close variances.
   - *Never share your PIN with a cashier.*
3. **Reconciliation Check:**
   - Review the POS Session Reconciliation reports at end-of-day.
   - Ensure expected cash from the POS matches the posted `sale_payments` cash records for the shift.

## 5. Fallback and Rollback Procedure

If the POS module experiences a critical failure (e.g., cannot load, cannot process payments, stock routing errors out), execute the following immediately:

### Step 1: Cease POS Operations
- Cashiers stop using the `/pos` interface.

### Step 2: Resume Legacy Operations
- Instruct cashiers to process new transactions via the old Sales/Invoice screens or manual receipt books per store backup policy.
- *Do not attempt to retroactively enter failed POS transactions unless verified they did not post.*

### Step 3: Admin Feature Flag Rollback
- Admin navigates to **Settings -> Business Settings**.
- Toggle `POS Enabled` to **OFF** for the affected business.
- This immediately blocks the `/pos` route and redirects any active sessions safetly away.
- Historical data (sales already posted by POS) remains intact and valid.

### Step 4: Escalation
- Contact ERP IT Support immediately with details:
  - Error message received
  - Transaction attempt time
  - Affected terminal/cashier

## 6. Completion Criteria for Parallel Run

The parallel-run phase ends, and legacy sales fallback is deprecated, only when:
- 7 continuous days of POS operations complete without a P0 critical failure.
- End-of-day reconciliation matches the expected POS ledger 100% for all shifts.
- Store managers sign off on cashier speed and UX acceptance.
