## Why

The global purchase and sales payment detail pages no longer provide the inline metadata editors available on the normal transaction detail pages, forcing authorized users to leave the global workflow or switch businesses to maintain common document fields. Global detail should preserve those existing inline-edit capabilities while keeping payment creation on the cross-business multi-transaction workflow.

## What Changes

- Align global purchase and sales detail presentation with their normal transaction detail pages instead of maintaining a reduced sales-specific detail experience.
- Restore the existing inline editors in global detail: purchase supplier purchase number, purchase tax invoice number, purchase note, sales tax invoice number, and sales note.
- Require both the relevant global-payment access permission and the existing purchase/sales edit permission for cross-business inline updates; global access alone remains read-only.
- Resolve authorization and setting-scoped uniqueness validation from the viewed transaction's own business rather than the active session business.
- Keep archived transactions and users without the existing edit permission read-only.
- Preserve the global multi-transaction payment destination, positive live-balance guard, selected starting transaction, and global-list back navigation.
- Continue suppressing unrelated global mutations such as full edit, approval, receiving/dispatch, deletion, archive, duplication, and attachment management.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `global-purchase-multi-payment`: Allow permission-gated inline purchase metadata editing from cross-business global detail while preserving multi-purchase payment behavior and suppressing unrelated mutations.
- `global-sales-multi-payment`: Align global sales detail with normal detail and allow permission-gated inline sales metadata editing while preserving multi-sale payment behavior and suppressing unrelated mutations.

## Impact

- Affects the global purchase/sales detail controllers and Blade views, the existing purchase/sales inline Livewire editors, and focused feature/Livewire tests.
- Changes the existing global-detail contract from fully read-only to selectively editable metadata for users who already hold the corresponding domain edit permission.
- Requires no database migration, new dependency, payment-service change, or normal setting-scoped route change.
