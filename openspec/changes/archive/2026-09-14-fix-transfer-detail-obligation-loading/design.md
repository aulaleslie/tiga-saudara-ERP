## Context

The transfer detail endpoint currently eager-loads product identity, version-2 route policies, and version-2 movement return obligations, but the Blade template also reads each `TransferProduct::returnObligation` relation without loading it. Because lazy loading is disabled, that implicit query raises an exception and prevents the page from rendering. The relation access also occurs before the stock-visibility authorization boundary, even though return-obligation information is classified as protected system-stock data.

The fix must preserve the existing detail page and permission model, support both legacy line-level obligations and version-2 movement obligations, avoid N+1 queries, and require no schema or workflow changes.

## Goals / Non-Goals

**Goals:**

- Render transfer detail successfully when Eloquent lazy loading is disabled.
- Ensure only users with `stockTransfers.view-system-stock` load or render protected route-policy and return-obligation data.
- Keep safe product identity and lifecycle context available to blind viewers.
- Preserve the existing privileged detail presentation for both legacy and version-2 transfers.
- Cover the boundary with focused HTTP tests.

**Non-Goals:**

- Changing return-obligation calculations, lifecycle transitions, inventory mutations, or transfer permissions.
- Adding migrations, routes, API resources, or new detail-page features.
- Refactoring every transfer query into a new projection architecture.
- Expanding verification to the full application test suite.

## Decisions

### Conditionally eager-load protected relationships in the detail controller

The controller will always eager-load relationships required for safe document rendering, including transfer products and product identity. It will load `products.returnObligation`, `routePolicies`, and `movementReturnObligations.product` only when the current viewer has `stockTransfers.view-system-stock`.

This keeps relationship loading aligned with the established permission and prevents both accidental lazy loading and unnecessary retrieval of protected records. Always eager-loading all relationships and merely hiding their markup was rejected because it weakens the data-access boundary for blind users. Loading relations individually from the Blade template was rejected because it reintroduces query behavior in presentation code and risks N+1 queries.

### Keep obligation evaluation inside the authorization boundary

The Blade template will not read `returnObligation`, route-policy fields, or movement-obligation fields until execution is inside the stock-visibility permission branch. The privileged branch may rely on the controller's eager-loading contract and must not trigger additional relationship queries.

Using `relationLoaded()` as a substitute for authorization was rejected: load state is an implementation detail and must not decide whether protected information is visible. Authorization remains the governing condition.

### Verify both confidentiality and strict-loading compatibility through HTTP behavior

Focused tests will enable strict lazy-loading prevention and request transfer detail as both a privileged viewer and a blind viewer. The privileged case will verify that existing legacy and version-2 obligation content remains available. The blind case will verify successful rendering, retention of permitted identity/lifecycle context, and absence of distinctive protected obligation values.

Testing only the controller's relationship collection was rejected because the original failure occurs when the complete controller/view path renders.

## Risks / Trade-offs

- [Controller and Blade permission checks drift apart] -> Use the same established `stockTransfers.view-system-stock` ability on both sides and cover both viewer classes through HTTP tests.
- [A future template change accesses a protected unloaded relation] -> Keep strict lazy loading enabled in the focused regression tests so such access fails immediately.
- [Conditional eager loading adds privileged queries] -> Load protected collections in bounded eager-loading queries, avoiding per-line N+1 access; blind users avoid those queries entirely.
- [A protected value leaks through surrounding markup] -> Seed distinctive obligation and policy values and assert their absence from the blind response.

## Migration Plan

Deploy the controller, Blade, and focused tests together. No database migration or data backfill is required. Rollback consists of reverting these code changes; no persistent data is affected.

## Open Questions

None. The existing stock-visibility permission and current privileged presentation define the required boundary.
