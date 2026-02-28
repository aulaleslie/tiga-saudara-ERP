# POS MVP Activation Checklist

**Date:** ________________
**Business / Setting ID:** ________________
**Activated By:** ________________
**Approved By:** ________________

This checklist is to be executed *per business* when enabling the POS MVP module. The goal is to ensure controlled rollout and verification without disrupting existing sales processes.

---

## 1. Pre-Activation Prerequisites

These configuration steps must be verified by the ERP Admin before the feature flag is toggled on.

- [ ] **Walk-in Customer Mapping:** A generic walk-in customer exists in `customers` for this business and is mapped in **Settings -> Business Settings -> Pelanggan Walk-In POS**.
- [ ] **Receipt Number Prefix:** The receipt prefix (e.g., `RCP-`) is configured in Business Settings.
- [ ] **Terminal Setup:** At least one active terminal is registered for the business (`/pos/terminals`).
- [ ] **Terminal Policy:** The terminal has an actively configured policy (drawer hooks, float requirements, variance approval threshold set appropriately).
- [ ] **Role Assignment:** Cashier and Supervisor roles for this business explicitly have `pos.access` and `pos.sell` (plus `pos.sessions.*` and `pos.reports.*` depending on the role).
- [ ] **Staff Training:** Store staff have completed POS UAT scenarios and understand the Parallel-Run SOP (specifically duplicate transaction prevention).

## 2. Activation Steps

- [ ] Log in as an Admin.
- [ ] Navigate to **Settings -> Business Settings**.
- [ ] Select the target business/setting.
- [ ] Toggle **"Aktifkan POS"** (POS Enabled) to **ON**.
- [ ] Save changes.

## 3. Post-Activation Verification (Smoke Test)

*Note: These operations produce real financial records! Perform a small test transaction and immediately void/reverse it or use a dedicated test item.*

- [ ] **Access Guard:** Log in as a Cashier for the target business. Navigate to `/pos/sell`. Ensure the UI loads and does not redirect to `sales.index`.
- [ ] **Session Open:** Start a new session. Enter opening float successfully.
- [ ] **Product Search:** Scan or search an item. Ensure stock lookups return fast and accurate results.
- [ ] **Transaction Posting:** Complete a cash checkout for a test item. Verify receipt previews correctly.
- [ ] **Ledger Check:** Supervisor verifies the transaction appears correctly in the standard ERP `sales` list.
- [ ] **Session Close:** Close the session. Ensure blind close flow works as intended.

## 4. Rollback Procedure (If Verification Fails)

If a critical failure occurs during the smoke test or within the first week of parallel-run operations:

- [ ] Navigate to **Settings -> Business Settings**.
- [ ] Toggle **"Aktifkan POS"** to **OFF**.
- [ ] Cashiers are instructed to refresh their browsers. They will be actively redirected to `sales.index`.
- [ ] Inform IT Support to investigate logs using the Support Runbook.

## 5. Sign-off

- **Store Manager Signature:** ___________________________
- **ERP Admin Signature:** ___________________________
- **Date Completed:** ___________________________
