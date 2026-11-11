# Design

## Context

See proposal.md for motivation. The existing assignment table has `is_enabled` and nullable `position`. `SalesLocationResolver` already filters enabled assignments, caches IDs, and has no ownership fallback. Assignment model events invalidate cache. The configuration controller blocks owner disablement, excludes owned locations from the available query, and invokes repair that re-enables disabled rows. The Blade view hides owned disable controls. `Location` ownership updates use `updateOrCreate` with enabled=true, overwriting existing choices.

## Goals / Non-Goals

**Goals:** Reuse assignment state and existing permission gates, ordering, resolver, and lifecycle hooks. Make an absent assignment distinct from an explicitly disabled assignment.

**Non-Goals:** New global location status, separate POS configuration tables, changing stock ownership, retroactive transaction changes, inventory/transfer policy changes, or broad checkout refactoring.

## Decisions

1. **Reuse the existing boolean.** Remove owner-only controller and view restrictions; include disabled owned locations in the available query and calculate their ownership badge correctly. Retain current access/edit permissions. A separate override column would introduce competing state without adding needed behavior.
2. **Repair absence only.** Change repair's existence check to consider any assignment, regardless of enabled state; create only missing owner assignments. Existing rows keep state and position. Removing repair entirely would lose legacy missing-assignment compatibility.
3. **Ownership transfer creates defaults only when absent.** Replace unconditional enablement with creation-if-missing semantics. Preserve existing new-owner assignment state and priority, leave former-owner assignments unchanged, and retain cache invalidation for both businesses. Continue using model creation hooks for default priority. Forcing enabled on transfer contradicts the agreed persistent-choice rule.
4. **Retain ordering and cache mechanisms.** Disable clears position; re-enable appends. Continue using Eloquent assignment events and existing reorder invalidation rather than bulk mutations that bypass events. Disabled owned rows cannot enter reorder submissions.
5. **Keep the shared enabled list authoritative.** Price Point browsing and Sales average-cost fallback continue their existing use of enabled assignments. No separate consumer override is introduced. With zero enabled locations, use existing POS rejection paths; make only a targeted adjustment if focused verification finds a missing or unclear configuration error. Do not change historical transactions or add an owner fallback.
6. **Focused verification.** Extend `SaleLocationConfigurationTest` rather than introduce a parallel suite. Cover page reload persistence, repair, ownership transfer, default creation, isolation, permission denial, priority, and warm-cache transitions. Reuse relevant POS checkout/search and non-stock tests for empty configuration, adding a focused case where coverage is missing. Exercise Price Point and cost-fallback behavior with fixtures that distinguish disabled from enabled priorities; avoid broad unrelated regression runs unless failures justify them.

## Risks / Trade-offs

- [Legacy tests expect owned disablement to fail] → Replace that assertion with the new contract and preserve borrowed-location and reorder regressions.
- [A lifecycle callback silently re-enables a location] → Test repeated reload/repair and transfer to a business with an existing disabled assignment.
- [A stale cart still references a disabled location] → Verify current checkout validation rejects ineligible allocation without posting or stock mutation.
- [Shared consumers change visibility or ranking] → Keep these deliberate effects in the spec and focused consumer checks.
- [Rollback restores forced enablement] → Code rollback may re-enable intentionally disabled owned assignments on page load or ownership change; document this operational consequence.

## Migration Plan

No schema or data migration and no rewrite of existing enabled assignments. Deploy normal application changes after focused tests pass. New locations retain enabled defaults. Smoke-check owned disable, reload, re-enable, and empty-configuration feedback. Roll back application code if needed, accounting for the previous forced-enablement behavior above.
