## Why

POS currently treats `pos.checkout.payment` as a prerequisite for both Bayar Lunas and Kasbon. Kasbon-only users cannot use their intended workflow, and the current debt approval fallback does not distinguish permission to request approval from having no Kasbon access.

## What Changes

- Make `pos.checkout.payment` authorize Lunas independently of Kasbon.
- Keep `pos.checkout.debt` for direct Kasbon and add `pos.checkout.debt.request` for Kasbon requiring supervisor approval.
- Show Kasbon for either debt permission, label request-only access “Kasbon · Perlu Persetujuan”, and hide it for users with neither permission.
- Allow shared payment infrastructure for any checkout permission; enforce the selected flow on the server.
- Allow switching only when both flows are accessible. Preserve staged data when permissions are revoked, while blocking unauthorized completion.
- Preserve session, terminal, cart, customer, payment validation, supervisor eligibility, and approval-token rules.
- **BREAKING**: Lunas permission alone no longer permits requesting Kasbon approval. Administrators explicitly assign the new permission; no automatic user or role grants.

## Capabilities

### New Capabilities
- `pos-checkout-flow-access`: independent flow visibility, shared infrastructure access, switching, and permission changes during staged checkout.

### Modified Capabilities
- `pos-permission-governance`: distinguish direct debt access, permission to request approval, and no access.

## Impact

Affected areas include the permission registry and POS assignment matrix, role capability policy, checkout request/controller guards, debt approval request and execution authorization, payment-related endpoints, Blade actions/modals, staged-payment JavaScript, and focused POS tests. No checkout data schema changes or automatic role grants are intended. Verification is limited to affected tests and targeted manual checks; no full-suite run is required.
