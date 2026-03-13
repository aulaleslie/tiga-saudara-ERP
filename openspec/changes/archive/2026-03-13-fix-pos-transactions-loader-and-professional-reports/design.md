## Context

The POS transaction list page can stay at `Memuat data...` because its page script is pushed to `scripts`, while the primary application layout renders `page_scripts`. This causes a silent bootstrap failure where data loading logic never executes. The same script-stack mismatch pattern exists across several POS operational/reporting pages.

In parallel, the POS reports page currently presents mostly raw tabular output with limited visual hierarchy. Operational users can retrieve data, but the presentation does not support fast management review or polished stakeholder-facing reporting.

Constraints:
- Keep existing report and transaction backend contracts stable where possible.
- Avoid introducing fragile or heavy frontend dependencies unless justified.
- Preserve current access control and setting scoping behavior.

## Goals / Non-Goals

**Goals:**
- Ensure transaction list pages always execute their client bootstrap code and do not remain permanently in the initial loading placeholder.
- Standardize script injection conventions for POS pages that depend on asynchronous JS rendering.
- Redesign POS reports with a professional KPI-first structure and tabbed detail views while preserving existing data semantics.
- Provide clear, deterministic loading/empty/error states on transaction/report surfaces.

**Non-Goals:**
- Rewriting reporting business logic or changing accounting semantics.
- Introducing net-new financial metrics that require data model changes.
- Replacing the broader application design system outside POS reporting/transactions scope.

## Decisions

### 1) Add script-stack compatibility bridge at layout level
Decision:
- Render both `@stack('page_scripts')` and `@stack('scripts')` in the main JS include, then migrate POS pages toward one canonical stack (`page_scripts`) for consistency.

Rationale:
- Immediately removes the runtime deadlock without requiring a risky all-at-once template migration.
- Prevents silent breakage on pages already using `scripts`.

Alternatives considered:
- Migrate every view to `page_scripts` only in one sweep: rejected for higher regression risk and larger review surface.
- Keep current mismatch and patch only transaction list: rejected because the same failure mode exists in other POS pages.

### 2) Enforce deterministic async UI state transitions
Decision:
- Transaction list and report loaders must transition out of default placeholders into one of: loaded data, empty state, or explicit error state.
- Keep user-triggered refresh actions (`Muat Data`) as first-class retry paths.

Rationale:
- Eliminates ambiguous “still loading forever” behavior.
- Improves supportability and user trust during network/API faults.

Alternatives considered:
- Silent retries only: rejected because users still lack clear feedback and control.

### 3) Professional report IA with KPI-first layout and detail tabs
Decision:
- Use a top KPI summary strip for at-a-glance insight, followed by tabbed detail sections (`Penjualan Harian`, `Ringkasan Kasir`, `Metode Pembayaran`, `Penjualan Produk`, `Persetujuan Supervisor`).
- Keep existing endpoints and derive dashboard metrics from current responses.

Rationale:
- Raises visual professionalism and readability without blocking on backend schema changes.
- Keeps implementation tractable and aligned with existing permissions and routes.

Alternatives considered:
- Introduce a new aggregate report endpoint first: deferred; useful later for optimization, not required for first UX uplift.
- Add external charting dependency immediately: deferred unless native styling/visuals prove insufficient.

### 4) Preserve data/API contracts in first pass
Decision:
- Do not require API contract changes for this change.
- Restrict scope to frontend rendering architecture and state handling.

Rationale:
- Reduces rollout risk and keeps migration straightforward.

## Risks / Trade-offs

- [Dual stack rendering may allow duplicate execution if a page pushes identical logic into both stacks] → Mitigation: standardize touched POS pages to one stack and avoid double-push patterns.
- [KPI values assembled from multiple endpoints may briefly load out of sync] → Mitigation: coordinated refresh cycle with explicit loading indicators and clear last-updated marker.
- [Visual redesign could regress mobile readability] → Mitigation: include responsive acceptance scenarios and test on common viewport breakpoints.
- [Perceived “professional” quality can be subjective] → Mitigation: define concrete structural acceptance criteria (KPI strip, tab hierarchy, state handling, spacing/typography consistency).

## Migration Plan

1. Implement compatibility bridge for script stacks in shared layout include.
2. Update POS transaction/reporting views to canonical script stack usage.
3. Deploy report IA redesign (KPI + tabs) reusing current endpoints.
4. Run smoke checks on POS pages with async loaders (transactions, reports, monitor, reconciliation).
5. Rollback strategy: revert view/layout changes; backend data flows remain unchanged.

## Open Questions

- Should the first release include lightweight sparkline/mini-chart visuals, or KPI cards + refined tables only?
- Do we want date presets (Today, 7 days, 30 days) in the same change or a follow-up?
- Is export formatting (print/PDF/Excel styling) required in this scope or later?
