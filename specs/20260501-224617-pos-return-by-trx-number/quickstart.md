# Quickstart: POS Return by Transaction Number

## 1. Focused automated verification
```bash
php artisan test --filter=POSReturn
php artisan test --filter=PosReturn
php artisan test --filter=SalesReturn
```

## 2. Higher-confidence verification
```bash
composer test:fresh-sqlite -- --filter=POSReturn
```

## 3. Manual verification flow
1. Sign in as a user with `pos.returns.create` and `pos.returns.view`.
2. Open `/pos/returns/create`.
3. Enter an unknown transaction number and confirm the lookup is blocked with a clear message.
4. Enter a draft/unposted/cancelled POS transaction number and confirm the lookup is blocked.
5. Enter a valid completed POS transaction code or receipt number.
6. Confirm the snapshot shows transaction header, customer, cashier/session context, receipt reference, payment summary, generated sales, owner groups, dispatch state, product lines, bundle composition, and returnable quantities.
7. Submit a cash return for a normal product line.
8. Confirm the POS Return is pending approval and linked to the correct sale/owner group.
9. Approve, receive, and process manual cash refund settlement.
10. Confirm replacement dispatch is unavailable for the cash return.
11. Submit a product replacement return for another completed POS transaction.
12. Approve and receive the return.
13. Confirm cash refund settlement is unavailable and replacement dispatch is available only from the original owner/location.
14. Run a split-owner POS transaction return and confirm each returned item maps to the correct generated sale, dispatch detail, owner, location, and tax context.
15. Run a bundle POS transaction return and confirm all bundle components are returned proportionally; parent-only return must be blocked.
16. Attempt a second/partial return exceeding still-returnable quantity and confirm it is blocked.
17. Attempt edit/delete after approval and after receiving; confirm lifecycle guards block direct mutation.
18. Review audit actors/timestamps for create, edit/delete/archive, approve, reject, receive, settlement, and dispatch.

## 4. Files expected to change in implementation
- `app/Config/Permissions.php`
- `Modules/Pos/Support/PosPermissionMatrix.php`
- `Modules/Pos/Routes/web.php`
- `Modules/Pos/Entities/PosReturn.php`
- `Modules/Pos/Entities/PosReturnLine.php`
- `Modules/Pos/Database/Migrations/*pos_return*`
- `Modules/Pos/Http/Controllers/PosReturnController.php`
- `Modules/Pos/Resources/views/returns/*`
- `app/Livewire/PosReturn/*`
- `resources/views/livewire/pos-return/*`
- `app/Support/PosReturn/*`
- Existing Sales Return integration points where POS links or permission-specific wrappers are needed
- `Modules/Pos/Tests/Feature/POSReturn*.php`
- `tests/Feature/Livewire/PosReturn/*`

## 5. Residual risk notes
- The plan intentionally reuses Sales Return receiving/settlement/dispatch logic. Implementation tasks must verify that linked multi-sale POS returns cannot leave the POS wrapper in a partially advanced status without an audit trail.
- Serial-tracked POS returns require focused test coverage because serial state is mutated by Sales Return receiving.
- Replacement from non-original owner/location is out of scope unless a separate transfer/override path is completed before dispatch.
