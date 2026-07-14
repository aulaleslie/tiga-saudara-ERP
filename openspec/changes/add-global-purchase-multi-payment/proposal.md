## Why

Users responsible for centralized supplier settlement cannot currently see payable purchases from every setting in the operational purchase menu or allocate one supplier payment across multiple received purchases. The existing global purchase page is a report, while the existing purchase payment flow is setting-scoped and creates a payment for only one purchase at a time.

## What Changes

- Add a permission-gated `Pembayaran Pembelian Global` operational menu beside `Semua Pembelian` under `Pembelian`; this is separate from the existing report under `Laporan`.
- Provide a global, payment-focused purchase list that follows the existing `Semua Pembelian` table layout but omits purchase creation, import, update, delete, receiving, approval, duplication, and archive actions.
- Limit the global payment list and payment candidates to non-archived purchases with exact status `RECEIVED` and a positive current outstanding balance.
- Add a dedicated global read-only purchase-detail route/context so authorized users can inspect purchases and payment history from any setting without weakening the normal setting-scoped purchase routes.
- Redirect every create-payment entry from the global list and global purchase detail to a new supplier multi-payment page inspired by `report-sample/pembayaran/pembelian-ui.txt`, omitting unsupported fields.
- Load payment candidates by the starting purchase's exact `supplier_id` without a `setting_id` constraint, allow an amount per eligible purchase, and create one existing `PurchasePayment` record for each positive allocation.
- Apply the shared payment date, reference, payment method, memo, and single uploaded attachment to every generated payment, recalculating each affected purchase atomically.
- Add dedicated global purchase-payment access authorization while continuing to require the existing purchase-payment creation permission to submit payments.

## Capabilities

### New Capabilities
- `global-purchase-multi-payment`: Defines operational navigation, cross-setting received-purchase visibility, read-only global purchase detail, supplier invoice allocation UI, permissions, attachment replication, and atomic creation of multiple existing purchase payments.

### Modified Capabilities

None. The existing setting-scoped operational purchase list, single-purchase payment flow, and global purchase report retain their current contracts.

## Impact

- Affects purchase navigation and permission configuration, purchase routes/controllers, the operational purchase Livewire table and action rendering, purchase detail presentation/payment history access, purchase payment form/orchestration, media attachment handling, and purchase payment tests.
- Reuses `purchases`, `purchase_payments`, `suppliers`, payment methods, Spatie permissions, and Spatie Media Library; no new payment ledger model is required for the first version.
- Requires explicit global route authorization and cross-setting query paths rather than relaxing the existing `session('setting_id')` guards.
