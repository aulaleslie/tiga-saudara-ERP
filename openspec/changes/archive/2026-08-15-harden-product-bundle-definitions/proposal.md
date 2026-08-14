## Why

Product bundles are about to be used more broadly, but their authoring contract currently permits ambiguous definitions, cross-product route mutation, and product deletion that can silently remove bundle composition. Bundle creation is also limited to the active setting even though every current business must begin with the same definition before managing its copy independently.

## What Changes

- Create one independent bundle copy, including its component rows, for every setting available when an administrator creates a bundle; make the complete replication atomic.
- Keep replicated copies independent after creation so each setting can edit, enable, disable, or remove its own copy without affecting the others.
- Add a per-setting bundle enabled state to Product Bundle administration, defaulting new copies to enabled. Runtime eligibility enforcement outside administration is deferred to the bundle lifecycle change.
- Reject duplicate component products within one bundle while allowing users to express repeated demand through component quantity.
- Explicitly permit a parent product as its own component and permit components that have bundles of their own; bundle composition remains exactly one level and does not recursively expand component bundles.
- Require update routes to target a bundle owned by the route product and active setting.
- Prevent deleting a product while it is used as a bundle parent or component; administrators must remove the affected definitions first.
- Preserve last-write-wins bundle editing; concurrent edit conflict protection is not introduced.
- Exclude replica grouping, coordinated cross-setting management, and backfill for settings created after a bundle from this change.

## Capabilities

### New Capabilities

- `product-bundle-definition-integrity`: Defines valid first-level bundle composition, per-setting administrative enabled state, nested-route ownership, and deletion protection for referenced products.

### Modified Capabilities

- `bundle-setting-scope`: Changes bundle creation from one active-setting record to atomic independent copies for every currently available setting while preserving per-setting administration afterward.

## Impact

- Product Bundle schema, Eloquent models, controller validation/persistence, administration forms, product deletion flow, routes, permissions presentation, and focused Product module tests.
- Existing Sales and POS bundle consumption remains one-level. Comprehensive filtering by enabled state and activation dates is deferred to the separate activation/lifecycle hardening sequence.
- No existing bundle backfill is required because production currently has no bundle definitions.
