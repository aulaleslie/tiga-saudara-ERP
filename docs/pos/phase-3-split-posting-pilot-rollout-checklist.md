# Phase 3 Split Posting Pilot Rollout Checklist

## 1. Pre-Enablement Checks

- Confirm migrations applied: `pos_checkout_sales` table and `pos_checkouts.split_summary` column.
- Confirm feature flag default is OFF in production config: `POS_CHECKOUT_SPLIT_POSTING_ENABLED=false`.
- Confirm cashier checkout baseline passes with split flag OFF.
- Confirm supervisor/session/receipt flows unaffected in pilot setting.

## 2. Pilot Enablement

- Enable split posting in pilot environment only:
  - `POS_CHECKOUT_SPLIT_POSTING_ENABLED=true`
- Run smoke checkout scenarios:
  - single-group checkout
  - multi-group checkout (mixed source/location)
  - idempotent replay with same key
  - cash and non-cash payment methods

## 3. Data Reconciliation Checks

- For each pilot checkout, verify:
  - `SUM(pos_checkout_sales.grand_total) == pos_checkouts.grand_total`
  - `SUM(pos_checkout_sales.paid_total) == pos_checkouts.grand_total` (net posted amount)
  - first split group IDs match `pos_checkouts.sale_id`, `sale_payment_id`, `dispatch_ids`
- Validate `response_payload.split_groups` and `split_summary.groups` exist for split-enabled checkouts.

## 4. Operational Monitoring

- Track failure codes in `pos_checkouts.failure_code` for `POSTING_FAILURE` spikes.
- Track idempotency replay response consistency (`idempotent_replay=true` payload parity).
- Track reconciliation mismatch incidents from POS reconciliation dashboard/service.

## 5. Rollback Plan

- Immediate rollback switch:
  - set `POS_CHECKOUT_SPLIT_POSTING_ENABLED=false`
- Verify new checkouts return to inline single-posting path.
- Keep additive schema (`pos_checkout_sales`, `split_summary`) intact for audit and no-downtime rollback.

## 6. Exit Criteria for Full Rollout

- No critical checkout posting incidents during pilot window.
- Reconciliation checks clean for pilot transactions.
- Cashier UX unchanged and receipt generation remains stable.
- Approval from finance + operations for wider enablement.
