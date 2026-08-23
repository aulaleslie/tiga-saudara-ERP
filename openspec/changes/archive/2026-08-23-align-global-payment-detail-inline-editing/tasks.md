## 1. Global Detail Template Alignment

- [x] 1.1 Update global sales detail loading to provide the canonical sales detail view with the transaction's setting, customer, dispatch, payment DataTable, and required relationships.
- [x] 1.2 Add global-mode branches to the canonical sales detail template for global back navigation, actual-business presentation, suppressed non-inline mutations, and the eligible multi-sale payment action.
- [x] 1.3 Remove or retire the duplicate reduced global sales detail template after route coverage confirms the canonical template is used.
- [x] 1.4 Update the canonical purchase detail global branches to mount its three existing inline editors while preserving suppressed non-inline mutations and the multi-purchase payment action.

## 2. Cross-Business Inline Editing

- [x] 2.1 Extend the purchase supplier-number, tax-number, and note Livewire editors with server-authorized global context that requires both `purchasePayments.global.access` and `purchases.update`, while retaining normal active-setting guards.
- [x] 2.2 Scope purchase supplier-number and tax-number uniqueness validation to the reloaded purchase's `setting_id` in both normal and global contexts.
- [x] 2.3 Extend the sales tax-number and note Livewire editors with server-authorized global context that requires both `salePayments.global.access` and `sales.edit`, while retaining normal active-setting guards.
- [x] 2.4 Ensure every affected editor reauthorizes its record and archive state on mount and each interactive action so tampered global context cannot bypass permissions or ownership rules.

## 3. Focused Verification

- [x] 3.1 Add or update focused purchase feature/Livewire tests for canonical global-detail rendering, authorized cross-business edits, read-only users and archived records, tampered requests, business-scoped uniqueness, suppressed unrelated actions, and multi-purchase payment routing.
- [x] 3.2 Add or update focused sales feature/Livewire tests for canonical global-detail rendering, authorized cross-business edits, read-only users and archived records, tampered requests, suppressed unrelated actions, and multi-sale payment routing.
- [x] 3.3 Run only the touched global purchase/sales detail and inline-editor test files plus directly related regression tests, and resolve any failures.
