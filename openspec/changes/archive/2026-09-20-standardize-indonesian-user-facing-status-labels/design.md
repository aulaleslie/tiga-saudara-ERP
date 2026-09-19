# Design

## Context

See `proposal.md` for motivation. Purchase and Sale models already define Indonesian document-status maps, and `App\Constants\PaymentStatus` already normalizes historical casing and supplies Indonesian payment labels. However, several Blade partials and embedded views still print persisted values directly or maintain separate English mappings. The affected values are heavily used in services, policies, database queries, imports, and tests, so presentation must be separated from canonical storage.

## Goals / Non-Goals

**Goals:**

- Establish a single authoritative presentation mapping for each status domain and reuse it across server-rendered views, Livewire tables, reports, and exports.
- Make partial-state wording describe the relevant action rather than using an ambiguous generic translation.
- Handle historical casing and separator variants where they already exist in stored records.
- Verify the affected paths with focused tests proportional to this presentation-only change.

**Non-Goals:**

- Renaming enum constants or modifying persisted status values.
- Migrating historical records or changing workflow transitions.
- Changing API inputs, query semantics, permissions, or business calculations.
- Translating unrelated product names, free-form notes, third-party payment-method names, or the entire application.
- Running or requiring the complete application test suite.

## Decisions

### 1. Keep canonical values separate from display labels

Canonical English values remain the source of truth for persistence and comparisons. Presentation code resolves those values into Indonesian at the final rendering boundary.

This avoids a data migration and prevents regressions in strict comparisons, eligibility queries, imports, policies, and integrations. Changing stored values was rejected because the same status strings are broadly embedded in business logic and historical data.

### 2. Prefer domain-owned mappings with a small shared payment vocabulary

Document lifecycle mappings remain owned by their domain models or domain-specific presentation helpers because identical words such as `Partial` require different meanings in dispatch, receiving, return, payment, and settlement contexts. Shared payment states continue through `PaymentStatus`, with its partial label made explicit as `Dibayar Sebagian`.

A single universal map was rejected because it would lose business context. Repeated ad hoc Blade switches were also rejected because they caused the current inconsistencies.

### 3. Normalize only during lookup

Mapping lookup accepts known historical casing and separator variants by normalizing input in memory. It returns the canonical Indonesian label without mutating the model or database. Unknown values use a controlled fallback suitable for detection during focused verification rather than silently inventing a misleading translation.

### 4. Apply mappings at every output boundary in scope

Affected list partials, detail pages, global payment tables, POS transaction views, return views, filters, reports, printable output, and exports use the same domain mapping. Filter option labels are Indonesian while submitted/query values remain canonical.

### 5. Use focused verification only

Verification targets mapping behavior and representative rendering paths for purchases, sales, returns, global payments, POS, and affected reports/exports. Existing focused feature, Livewire, or view tests should be extended where practical; small unit tests may cover normalization and exact vocabulary. A full-suite run is explicitly excluded from the plan because this is a localized presentation change and the user requested focused verification.

## Risks / Trade-offs

- **[Missed direct rendering site]** → Search affected modules for raw status interpolation and English literals, then cover representative list, detail, report/export, and POS paths with focused assertions.
- **[Historical casing or separator variants fail lookup]** → Reuse or introduce non-mutating normalization at mapping boundaries and test known variants.
- **[Generic terminology obscures business meaning]** → Maintain domain-specific mappings for dispatch, receipt, return, payment, and settlement partial states.
- **[Exports diverge from screens]** → Route both through the same label resolver or assert identical expected vocabulary in focused export tests.
- **[Fallback exposes a new English value]** → Make new canonical statuses require an explicit label and add focused coverage for the supported status sets.

## Migration Plan

1. Add or consolidate presentation mappings without changing persisted values.
2. Replace direct/raw rendering across the scoped surfaces incrementally by domain.
3. Run focused mapping and rendering tests for each affected domain.
4. Deploy as a presentation-only change with no database migration.

Rollback consists of reverting the presentation changes; stored data remains compatible throughout.
