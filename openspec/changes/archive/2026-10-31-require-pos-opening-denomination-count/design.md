# Design

## Context

See proposal.md for motivation. `CashDenomination` already stores global integer rupiah values, type, enabled status, and position without a setting scope. Its configuration permits disabling every row. The POS opening Blade form currently accepts a formatted total. `StorePosSessionOpenRequest` accepts optional denomination arrays, but `PosSessionLifecycleService::openSession` ignores them and stores null denominations in OPEN_FLOAT. Existing cash-event JSON columns support the required evidence without a schema migration.

The lifecycle service enforces positive terminal opening cash, zero terminal-free opening cash, prerequisites, active-session uniqueness, and drawer triggering. Existing tests include total-only opening expectations that must change. The old optional-terminal spec describes required manual totals; this change replaces that requirement. Older statements permitting every role to select terminals conflict with current floor-staff restrictions; this proposal preserves those runtime restrictions and does not resolve that separate historical inconsistency.

## Goals / Non-Goals

**Goals:** One authoritative server calculation and authorization boundary, reusable by HTTP and direct service callers; immutable evidence; consistent global configuration.

**Non-Goals:** No historical backfill, supervisor PIN bypass, denomination management redesign, cash-threshold restoration, close/safe-drop changes, or terminal allocation changes.

## Decisions

1. **Reuse global configuration at render and submission.** Load enabled `CashDenomination` rows ordered by position/id, grouped by type for display. Use denomination values as array keys to match the existing value-to-quantity cash-event convention. Validate every submitted key against current enabled values, including zero-quantity keys; reject stale submissions with reload guidance. Do not copy configuration into per-business settings. This avoids divergent lists and hardcoded currency values.
2. **Explicit mode with safe default.** Add `opening_cash_input_mode` (`denominations` or `nominal`), defaulting to denominations when omitted. In counted mode, blank/missing quantities normalize to zero, counts must be nonnegative integers, and integer rupiah arithmetic derives a positive total within existing storage limits. Ignore submitted `opening_float_total` in this mode. In nominal mode validate the positive amount using existing currency handling; ignore stale counts. Reject unknown modes and overflow before persistence.
3. **Authorize bypass at the service boundary.** Resolve the acting user from `openedBy` (falling back to cashier as currently), and use the existing Gate conventions for `pos.sessions.open.total-only`. Keep request authorization for `pos.sessions.open`. Add an optional trailing mode argument to `openSession` to preserve positional callers, forwarding validated input from the controller. A shared opening-cash validator/normalizer can keep request feedback and service rules aligned. Direct service total-only callers must explicitly request nominal mode and supply an authorized actor; they receive no implicit trust exception.
4. **Permission replaces the legacy terminal flag for this action.** Register the new permission in `app/Config/Permissions.php` and expose it through `PosPermissionMatrix` exception guidance and the existing assignment UI. Do not automatically grant it to existing roles or standard bundles; preserve existing Super Admin Gate behavior. Retain the database flag for compatibility but remove its editable checkbox from terminal configuration and document its retirement for opening cash. Requiring both flag and permission would leave terminal policy unexpectedly overriding the requested user capability.
5. **Persist evidence using existing event fields.** Atomically store calculated/authorized total in session opening and expected cash and OPEN_FLOAT amount. Store normalized nonzero value-to-quantity counts for denomination mode and null for nominal mode. Store `opening_cash_input_mode` in event metadata and use existing performed_by for actor evidence. Retain existing event/session behavior on terminal-free opens (zero and null counts); hide/ignore cash mode and inputs there. Do not duplicate or rewrite events on idempotent existing-session returns.
6. **Progressive form behavior.** Show quantities, unit labels, subtotals and a read-only total on terminal selection. Only authorized users see the mode switch and nominal field. Retain selected mode/counts after validation failures; default to counts on a fresh visit. Hide cash inputs for terminal-free sessions and preserve existing floor-staff UI. Server checks remain authoritative with JavaScript disabled or payloads crafted manually.

## Risks / Trade-offs

- [Old total-only requests and direct callers fail] → Update all opening call sites and focused fixtures, document the breaking request contract, and sync permissions before use.
- [All denominations disabled] → Block counted opening with configuration guidance; allow explicitly authorized nominal mode without automatically bypassing counts.
- [Configuration changes while form is open] → Revalidate submitted values and reject stale keys; historical value snapshots remain independent of enabled status.
- [Legacy terminal flag is misleading] → Remove its editable control, document replacement, retain stored data for rollback.
- [Client/server calculation differences or excessive values] → Use integer rupiah count arithmetic, storage-bound validation, and server-derived persistence.

## Migration Plan

Deploy code and run the established permission sync. Assign bypass only to selected users/roles through the existing exception assignment surface. Verify denomination configuration has enabled rows before normal cashier rollout. No data migration or historical rewrite is needed. Smoke-test counted, authorized nominal, unauthorized crafted nominal, and terminal-free openings. Roll back application code if required; retain historical cash-event JSON evidence and the permission record, which old code can ignore.
