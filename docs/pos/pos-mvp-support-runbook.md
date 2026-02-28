# POS MVP Support and Escalation Runbook

**Version:** 1.0 (MVP Release)

This document provides Tier 1 and Tier 2 IT support staff with troubleshooting instructions and escalation paths for the POS module.

---

## 1. Escalation Contacts

| Role | Name | Contact Info | Coverage |
| --- | --- | --- | --- |
| **L1 Store Manager** | [Store specific] | [Store specific] | On-Site Hours |
| **L2 ERP Admin** | Main Helpdesk | `support@example.com` / Ext 101 | Business Hours |
| **L3 Dev Eng** | Engineering Team | `#pos-alerts` Slack | 24/7 Pager |

---

## 2. Common Issue Triage Matrix

### 2.1 Cashier Stuck at `/pos/sell` with 403 Forbidden
- **Symptom:** Cashier receives "Forbidden" or is instantly returned to the dashboard.
- **Check 1:** Verify the active business context (`session('setting_id')`). Is the POS Feature Flag enabled for this specific setting?
- **Check 2:** Does the user's role have the explicit `pos.access` and `pos.sell` permissions scoped to that setting?
- **Resolution:** Admin must assign permissions or enable the feature flag.

### 2.2 Payment Finalization Fails (500 Error / Failed State)
- **Symptom:** Cashier clicks "Confirm Payment" but gets a red error alert; transaction doesn't post.
- **Check 1:** Read the `pos_checkouts` table for that session.
  ```sql
  SELECT status, failure_reason, failure_code FROM pos_checkouts WHERE session_id = ? ORDER BY created_at DESC LIMIT 1;
  ```
- **Check 2 (Failure Code Interpretation):**
  - `STOCK_UNAVAILABLE`: Someone bought the item concurrently natively. Cashier must refresh search.
  - `POSTING_FAILURE`: The adapter failed to create the `sale` or `dispatch`. Check Laravel `storage/logs/laravel.log` for exact SQL constraint error.
  - `SERIAL_UNAVAILABLE`: Given serial was just sold. Change serial selection.
- **Resolution:** Cashier should close the modal, optionally refresh the page, and try checking out again (cart data remains intact).

### 2.3 Thermal Printer Not Printing
- **Symptom:** Receipt screen appears but printer hardware fails to print.
- **Check 1:** Verify printer IP/USB connection physically.
- **Resolution:** This is non-fatal. Cashier can ignore and serve the next customer. Later, they can go to POS History and hit **Reprint** once hardware is fixed.

### 2.4 Expected Cash / Reconciliation Mismatch
- **Symptom:** Shift close count is wildly off, or expected cash doesn't match standard revenue reports.
- **Check 1:** Has the supervisor performed the manual reconciliation via the POS Manager Dashboard (`/pos/reconciliation`)?
- **Check 2:** Inspect `pos_session_cash_events` for missing Safe Drops or manual out-of-band transfers.
  ```sql
  SELECT * FROM pos_session_cash_events WHERE pos_session_id = ? ORDER BY created_at ASC;
  ```
- **Resolution:** If POS totals are correct but accounting totals are off, check the legacy `sales` module for manual entries (which bypass POS sessions) entered during the parallel run.

### 2.5 "Item Needs Serial" Warning Won't Go Away
- **Symptom:** Cashier cannot proceed because an item is blocked demanding a serial.
- **Check 1:** Go to the Product Master data. Is the product actually marked as `is_serial_tracked = 1`?
- **Resolution:** If the physical item has no barcode, it's a data entry error. Remove item from cart, correct product master data natively, and re-add.

---

## 3. Emergency Rollback Procedure (Feature Flag Off)

If a critical database-corrupting bug is discovered in the POS posting adapter:

1. Log in to ERP as **Superadmin**.
2. Navigate to **Settings -> Business Settings**.
3. Edit the affected businesses.
4. Uncheck **"Aktifkan POS"**.
5. Save.
6. Communication: Instruct cashiers via store managers to use the legacy Sales screen immediately. No cache clear is required on the server (the middleware reads the DB configuration dynamically).

---

## 4. Useful Diagnostic Queries

**Find stuck sessions (open for >24 hours):**
```sql
SELECT pos_sessions.id, users.name, pos_terminals.name, opened_at 
FROM pos_sessions 
JOIN users ON users.id = pos_sessions.cashier_user_id
JOIN pos_terminals ON pos_terminals.id = pos_sessions.terminal_id
WHERE status = 'OPEN' AND opened_at < NOW() - INTERVAL 1 DAY;
```

**Find failed checkouts today:**
```sql
SELECT id, pos_session_id, grand_total, failure_code, failure_reason 
FROM pos_checkouts 
WHERE status = 'FAILED' AND DATE(created_at) = CURDATE();
```

**Find supervisor approval overrides:**
```sql
SELECT action_type, target_model_type, target_model_id, users.name AS supervisor
FROM pos_supervisor_approvals
JOIN users ON users.id = pos_supervisor_approvals.supervisor_user_id
WHERE DATE(created_at) = CURDATE();
```
