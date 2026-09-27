## 1. Backend Capability Contract

- [x] 1.1 Add top-level `can_reduce_quantity` to POS role capability payload in `PosRolePolicyService::capabilityFlags()`.
- [x] 1.2 Ensure `can_reduce_quantity` is derived from the same permission source as `direct_permissions.qty_reduce` to keep values consistent.
- [x] 1.3 Verify sell page controllers continue passing updated capability payload without breaking existing keys.

## 2. Frontend Capability Resolution

- [x] 2.1 Update `sell.blade.php` reduce-capability resolution to read `can_reduce_quantity` first, fallback to `direct_permissions.qty_reduce`, and default to `false` when missing.
- [x] 2.2 Keep quantity-control branch selection in `buildLineRow()` driven by the resolved capability so non-privileged flows render approval controls.
- [x] 2.3 Confirm pending `QTY_REDUCE` states render `Periksa Persetujuan` in both serial and non-serial non-privileged rows.

## 3. Regression Coverage

- [x] 3.1 Add/extend tests to assert capability payload includes boolean `can_reduce_quantity`.
- [x] 3.2 Add/extend tests to assert `can_reduce_quantity` matches `direct_permissions.qty_reduce` for privileged and non-privileged users.
- [x] 3.3 Add/extend POS supervised cart flow coverage to ensure non-privileged pending qty-reduction remains actionable via `Periksa Persetujuan` path.

## 4. Verification

- [x] 4.1 Run targeted POS feature tests for permission enforcement and qty-reduction approval workflows.
- [ ] 4.2 Manually verify with non-privileged user (`karyawan@tiga-computer.com`) that pending qty-reduce requests show `Periksa Persetujuan` immediately and after refresh.
