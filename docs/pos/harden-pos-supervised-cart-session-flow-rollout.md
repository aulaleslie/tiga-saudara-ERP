# Rollout And Rollback Notes

## Scope

This rollout hardens POS supervised cart/session flow with:

- Role-aware terminal/session opening policy.
- Deterministic approval lifecycle for restricted cart actions.
- Async approval support for price override.
- Completed checkout representation in POS transaction history.
- Draft-only mutation guard on loaded transaction carts.

## Migration Notes

Run application migrations before enabling traffic:

```bash
php artisan migrate
```

New invariant introduced:

- One active POS session per `(setting_id, cashier_user_id)` via `pos_sessions_user_active_unique`.

Operational expectation:

- Existing duplicate active sessions for the same user in a setting must be resolved before migration.

## Validation Checklist

1. Cashier session open without terminal must be rejected.
2. Floor/Manager session open without terminal must succeed.
3. Restricted actions show approval states: `pending`, `approved`, `rejected`.
4. Approved token can be consumed once; cancelled approval does not mutate cart.
5. Checkout from active cart (without loaded draft) creates a completed `pos_transactions` row.
6. `/pos/transactions/data` with empty status filter includes completed transactions.

## Rollback

1. Revert application code to previous release.
2. Roll back migrations if needed:

```bash
php artisan migrate:rollback --step=1
```

3. Clear caches:

```bash
php artisan optimize:clear
```

If rollback is blocked by data shape changes, keep the migration and roll back only the application code.
