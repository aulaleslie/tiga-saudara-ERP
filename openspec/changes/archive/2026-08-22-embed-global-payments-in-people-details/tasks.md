## 1. Entity-Constrained Global Components

- [x] 1.1 Add an optional locked `customerId` input to the global-mode sales table and apply it to the eligible sales query without changing standalone or normal-mode behavior.
- [x] 1.2 Add the same optional locked `customerId` input to sales summary cards and apply it consistently to outstanding, overdue, and recent-payment relationship queries.
- [x] 1.3 Make the purchase table's optional `supplierId` input immutable for Livewire requests and verify it composes with global eligibility, business/date filters, card filters, search, sorting, and pagination.
- [x] 1.4 Add an optional locked `supplierId` input to purchase summary cards and apply it consistently to outstanding, overdue, and recent-payment relationship queries.

## 2. Reusable Workspace Rendering

- [x] 2.1 Extract or add a reusable Blade composition for the full global sales-payment summary and table workspace, accepting an optional customer identifier and stable context-specific Livewire keys.
- [x] 2.2 Update the standalone Pembayaran Penjualan Global page to render the shared composition without a customer constraint and confirm its existing URL/filter state remains supported.
- [x] 2.3 Extract or add a reusable Blade composition for the full global purchase-payment summary and table workspace, accepting an optional supplier identifier and stable context-specific Livewire keys.
- [x] 2.4 Update the standalone Pembayaran Pembelian Global page to render the shared composition without a supplier constraint and confirm its existing URL/filter state remains supported.

## 3. People Detail Integration and Authorization

- [x] 3.1 Render the shared global sales-payment workspace beneath customer details only when the user has `salePayments.global.access`, passing the route-bound customer ID to both summary and table components.
- [x] 3.2 Render the shared global purchase-payment workspace beneath existing supplier-detail content only when the user has `purchasePayments.global.access`, passing the route-bound supplier ID to both summary and table components.
- [x] 3.3 Verify embedded row detail/history actions continue using dedicated global routes and payment actions remain hidden and forbidden without `salePayments.create` or `purchasePayments.create`.
- [x] 3.4 Confirm customer and supplier list/detail discovery remains unscoped by the active setting and that business filters constrain only related sales or purchases.

## 4. Sales Verification

- [x] 4.1 Add customer-detail feature tests for workspace visibility with global access, absence without global access, and read-only behavior without `salePayments.create`.
- [x] 4.2 Add Livewire sales tests proving exact customer isolation across businesses, selected-business composition, other filters/card interactions, summary/table consistency, and rejection of customer-ID mutation.
- [x] 4.3 Add regression tests proving the standalone global sales-payment workspace remains unconstrained by customer and retains existing cross-customer functionality.
- [x] 4.4 Add or extend a payment-flow test proving an embedded eligible sale opens the existing same-customer multi-invoice workflow and uses existing authorization and allocation behavior.

## 5. Purchase Verification

- [x] 5.1 Add supplier-detail feature tests for workspace visibility with global access, absence without global access, read-only behavior without `purchasePayments.create`, and preservation of existing supplier content.
- [x] 5.2 Add Livewire purchase tests proving exact supplier isolation across businesses, selected-business composition, other filters/card interactions, summary/table consistency, and rejection of supplier-ID mutation.
- [x] 5.3 Add regression tests proving the standalone global purchase-payment workspace remains unconstrained by supplier and retains existing cross-supplier functionality.
- [x] 5.4 Add or extend a payment-flow test proving an embedded eligible purchase opens the existing same-supplier multi-invoice workflow and uses existing authorization and allocation behavior.

## 6. Final Verification

- [x] 6.1 Run focused People detail, GlobalSalePayment, GlobalPurchasePayment, and affected Livewire component tests.
