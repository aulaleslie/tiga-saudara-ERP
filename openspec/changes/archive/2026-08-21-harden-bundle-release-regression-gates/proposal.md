## Why

Product bundle hardening is functionally complete, but release verification is obscured by reproducible pre-existing test failures and one real standard Sales Return completion inconsistency. These failures must be separated into fixture defects, assertion defects, and lifecycle defects so the bundle release has a trustworthy focused gate rather than accepting unexplained red tests.

## What Changes

- Repair Sale serial-badge feature-test permission setup so the tests reach and verify serial lineage rendering instead of failing while evaluating an unrelated reporting-date policy.
- Compare cross-owner replacement Sale dates by persisted calendar value rather than PHP object identity, while explicitly preserving the original Sale date on the generated replacement-owner Sale.
- Make a fully received and fully settled standard Sales Return archive its source Sale from cumulative effective return coverage, without deducting received stock again or depending on POS-only mutation of Sale and dispatch quantities.
- Add a focused bundle release-safety command/checklist covering definition, pricing, Normal Sales, POS split ownership, dispatch, serial lineage, HPP, reports, cash return, replacement, idempotency, and migration compatibility.
- Require every accepted baseline failure to have an explicit classification and owner; unexplained failures in the release gate block bundle enablement.

## Capabilities

### New Capabilities
- `bundle-release-safety-gates`: Defines the focused automated and migration verification required before enabling product bundles in production.
- `sale-return-completion-archival`: Defines standard Sales Return full-completion archival using effective cumulative return coverage without duplicate inventory movement.

### Modified Capabilities
- `pos-return-cross-owner-replacement`: Clarifies that a generated replacement-owner Sale preserves the original Sale calendar date and that tests compare persisted date values.

## Impact

- Test fixtures: `SaleShowSerialBadgeTest`, cross-owner POS replacement coverage, and related permission seeding helpers.
- Sales Return lifecycle: `SaleReturnLifecycleSyncService`, settlement completion, full-versus-partial coverage calculation, archive audit fields, and stock-movement idempotency.
- Release verification: focused Sale, POS, dispatch, serial, HPP/report, return/replacement, migration, and OpenSpec validation commands; no full-suite requirement.
- Deployment decision: `harden-product-bundle-hpp` may be wrapped independently, but production bundle enablement remains gated by this follow-up change.
