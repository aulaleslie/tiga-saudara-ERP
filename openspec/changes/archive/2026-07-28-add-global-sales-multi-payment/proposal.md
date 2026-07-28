## Why

Sales payments are currently entered from one setting-scoped sale at a time, which makes collecting one customer payment against multiple outstanding invoices across business settings slow and error-prone. The ERP already has a global multi-invoice workflow for purchase payments, so sales needs an equivalent controlled workspace that also covers POS Kas Bon receivables.

## What Changes

- Add an authorized `Pembayaran Penjualan Global` workspace under the Sales menu.
- List non-archived, not-fully-paid sales across all settings when their status is `APPROVED`, `DISPATCHED PARTIALLY`, or `DISPATCHED`.
- Include ordinary sales and POS Kas Bon sales, with existing sale, POS receipt, and POS transaction search identifiers.
- Add dedicated cross-setting, read-only sale detail and payment-history routes.
- Add a customer-based payment form that allocates one shared payment across multiple eligible sales for the exact same customer.
- Create one existing `SalePayment` record per positive allocation atomically, with shared date, reference, payment method, memo, and independently accessible attachment.
- Standardize live sales balance calculation from active payments and use it for eligibility, validation, concurrency protection, and sale payment-status reconciliation.
- Exclude customer-credit selection and `SalePaymentCreditApplication` creation from the global workflow; customer credits remain available only through the existing single-sale payment flow.
- Add a dedicated global sales-payment permission while continuing to require the existing sale-payment create permission for mutations.

## Capabilities

### New Capabilities

- `global-sales-multi-payment`: Cross-setting sales receivable discovery, read-only inspection, and atomic customer-level multi-invoice cash/payment-method allocation, including POS Kas Bon sales.

### Modified Capabilities

None.

## Impact

- Affects `Modules/Sale` controllers, routes, services, entities, DataTables, views, Livewire summary/list components, and feature tests.
- Affects the application permission registry and Sales sidebar navigation.
- Reuses existing `sales`, `sale_payments`, payment methods, media attachments, customer records, and POS-to-sale links; no new payment ledger table or external dependency is required.
- Normal setting-scoped sales and customer-credit payment workflows remain unchanged.
