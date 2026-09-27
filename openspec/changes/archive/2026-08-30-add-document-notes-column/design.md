## Context

The Livewire Purchase and Sales tables serve both normal document lists and Global Payment workspaces. Global mode currently renders the document header note beneath the reference through a shared anonymous Blade component, while normal mode hides that component. The tables have many nowrap columns and horizontal scrolling; mixing the bounded, wrapping note presentation into the reference cell makes the document identity visually uneven.

Older Yajra Purchase and Sales DataTables also contain separate note-under-reference markup with different expansion thresholds. Implementation must first confirm which list routes remain active, then align every active normal list without changing persistence, querying, or authorization.

## Goals / Non-Goals

**Goals:**

- Give Purchase and Sales lists a consistent `Ref | Catatan | ...` column order.
- Apply the same position to normal, standalone Global Payment, and embedded Global Payment variants.
- Preserve the existing compact preview and row-local read-more interaction for long or multiline notes.
- Keep notes escaped, line-break preserving, safely wrapping, and bounded in width.
- Preserve Global Payment note search and all unrelated table behavior.

**Non-Goals:**

- Changing note storage, validation, normalization, or editing workflows.
- Changing payment-history `Catatan` columns or allocation-form note placement.
- Changing Global Payment eligibility, filters, sorting, pagination, permissions, or payment behavior.
- Reworking the responsive behavior of unrelated columns.

## Decisions

### Render a real note column immediately after the reference

Both Livewire templates will add a `Catatan` header and matching body cell immediately after `Ref`. The existing component call will move into that cell and will no longer be conditional on global mode, allowing the shared templates to present notes in normal and Global Payment contexts.

Keeping a dedicated cell makes column meaning explicit and avoids coupling reference layout to note length. Leaving the note beneath the reference was rejected because it is the source of the scanning and row-density problem. A tooltip-only treatment was rejected because notes must remain directly readable and accessible.

### Extend the shared component to own the blank state

The existing `x-document-note` component will remain responsible for short, preview, and expanded content and will render `-` for null, empty, or whitespace-only input when used as a table cell. This keeps sale and purchase behavior identical and avoids duplicating blank checks in both templates.

Adding a configurable blank placeholder was considered, but the affected list contexts all require the same stable placeholder. Allocation forms do not use this component and remain unchanged.

### Bound the column rather than the reference cell

The note cell/component styling will define a practical bounded width while retaining `white-space: pre-wrap`, `overflow-wrap: anywhere`, and escaped Blade interpolation. The surrounding table keeps its existing horizontal-scroll and nowrap behavior. Expanding a note may increase only that row's height; it must not increase the table's intrinsic width.

Applying wrapping to the entire row or table was rejected because it would change established presentation for identifiers, dates, amounts, statuses, and actions.

### Align only active legacy list paths

Implementation will trace the normal Purchase and Sales list routes before editing the older Yajra DataTables. If those classes still back user-visible list routes, their note-under-reference markup and columns will be aligned with the same position and presentation contract. If they are inactive, they will not be refactored solely for cleanup.

This brownfield-first decision avoids an unrelated migration while preventing an active route from retaining contradictory behavior.

### Use focused regression verification

Tests will cover only the new or directly touched behavior: header/body column order, normal and Global Payment visibility, blank placeholder, short and expandable notes, escaping/wrapping contract, and existing note-only Global Payment search. Existing focused Livewire/feature test files will be extended where practical instead of running or expanding the full application suite.

## Risks / Trade-offs

- [Adding a column increases the minimum horizontal table width] → Keep the note width bounded and rely on the workspace's existing horizontal scrolling.
- [Expanded notes can make an individual row tall] → Keep expansion opt-in and retain collapse control; compact presentation remains the default.
- [Header/body cells can become misaligned across conditional global columns] → Add the note cell unconditionally in the same fixed position in both header and row markup and assert column order in focused tests.
- [Normal routes may use a legacy DataTable rather than the shared Livewire view] → Trace active routes/controllers before implementation and align only confirmed user-visible paths.
- [Changing blank-note rendering from hidden content to `-` affects exact markup assertions] → Update only assertions tied to the new list-cell contract.

## Migration Plan

1. Trace active normal and Global Payment list renderers for Purchase and Sales.
2. Move the shared note presentation into a dedicated column in the shared Livewire tables and align any active legacy renderer.
3. Adjust narrowly scoped note-cell styles and focused tests.
4. Deploy as a presentation-only change with no data migration or backfill.

Rollback restores the prior table markup and component blank behavior; no persisted data is affected.

## Open Questions

None. The existing compact threshold remains 120 Unicode characters or three logical lines unless implementation reveals a direct regression in the current component behavior.
