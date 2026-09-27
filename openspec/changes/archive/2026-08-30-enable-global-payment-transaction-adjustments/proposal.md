## Why

Authorized finance users can inspect purchase and sale transactions across businesses from Global Payment, but they must leave that context—and cannot safely use the setting-scoped routes for another business—to perform the monetary, reporting-date, or due-date adjustments already supported on normal transaction pages. Global detail should expose these narrowly authorized adjustments without turning the payment workspace into a general cross-business transaction editor.

## What Changes

- Add monetary-only adjustment actions to eligible purchase and sale Global Payment detail pages when the user has both global-payment access and the existing ordinary/lifecycle monetary-edit permissions.
- Add the combined reporting-date and due-date adjustment action to Global Payment details, exposing only fields authorized by the user's existing override permissions.
- Provide explicitly global, cross-setting adjustment entry/update paths that use the viewed transaction's actual setting and preserve the setting guards on normal transaction routes.
- Keep every unrelated mutation—full edit, approval, receiving/dispatch, archive, delete, duplication, and attachment management—unavailable in Global Payment context.
- Return successful adjustments to Global Payment context and handle transactions whose changed monetary balance no longer matches the currently selected payment view/filter.
- Replace existing assertions that Global Payment detail is universally read-only with permission-specific behavior and focused regression coverage.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-purchase-multi-payment`: Permit narrowly authorized monetary and date adjustments from cross-setting purchase detail while retaining payment-focused isolation.
- `global-sales-multi-payment`: Permit narrowly authorized monetary and date adjustments from cross-setting sale detail while retaining payment-focused isolation.
- `privileged-post-fulfillment-monetary-edits`: Extend existing monetary-only editing to dedicated Global Payment context without weakening normal setting-scoped routes.
- `reporting-date-overrides`: Allow authorized reporting-date operations through an explicitly authorized Global Payment cross-setting context.
- `due-date-adjustments`: Allow authorized due-date operations through an explicitly authorized Global Payment cross-setting context.

## Impact

- Affects Global Purchase Payment and Global Sales Payment detail controllers, routes, shared detail/action rendering, monetary edit entry/save flow, and date-adjustment authorization/context handling.
- Reuses existing monetary edit services/forms, reporting/due-date adjustment service, audit records, lifecycle rules, and permissions; no schema or new permission is expected.
- Changes prior Global Payment requirements and tests that prohibited all non-payment transaction mutations.
- Verification is limited to touched authorization/rendering paths, cross-setting saves, redirect/eligibility behavior, and likely normal-route isolation regressions.
