# Proposal

## Why

Editable monetary amounts on Sales, Purchase, and POS payment-creation pages use inconsistent parsing and display rules. Some fields remain raw, while others combine application-configured separators with US formatting, which can misrepresent decimals or submit a formatted string instead of the intended canonical amount.

## What Changes

- Standardize editable payment and allocation amount fields on the single Sales payment, single Purchase payment, global Sales payment, global Purchase payment, and global POS payment creation pages.
- Show the canonical raw number while an amount field is focused so operators can edit it without currency symbols or thousand separators.
- On blur, show Indonesian-style thousand separators and show exactly two decimal places only when the canonical amount has a fractional component.
- Preserve a separate canonical numeric value for totals, previews, maximum-balance checks, validation, and form submission.
- Preserve existing server-authoritative payment validation, balance limits, payment creation, attachments, and settlement behavior.
- Add focused verification for the affected payment-creation views and submission behavior; no full-suite test run is planned.

## Capabilities

### New Capabilities

- `payment-amount-input-formatting`: Defines consistent raw-on-focus, localized-on-blur display and canonical submission behavior for editable payment amounts across Sales, Purchase, and POS payment creation.

### Modified Capabilities

- `global-sales-multi-payment`: Global Sales allocation inputs will follow the shared payment amount interaction without changing allocation eligibility or settlement semantics.
- `global-purchase-multi-payment`: Global Purchase allocation inputs will follow the shared payment amount interaction without changing allocation eligibility or settlement semantics.
- `global-pos-multi-payment`: Global POS allocation inputs will follow the shared payment amount interaction without changing POS-to-Sale expansion or settlement semantics.

## Impact

- Affected views: `Modules/Sale/Resources/views/payments/create.blade.php`, `Modules/Purchase/Resources/views/payments/create.blade.php`, and the Sales, Purchase, and POS global-payment create views.
- Client-side amount parsing, formatting, total calculation, preview payload construction, maximum-due handling, and pre-submit normalization will use one consistent canonical-value contract.
- Existing controllers, services, database schemas, routes, and payment ledgers require no behavioral or schema change.
- Verification is limited to focused tests for the affected payment forms and relevant submissions.
