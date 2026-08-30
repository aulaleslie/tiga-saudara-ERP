## Context

The Global Payment sale and purchase lists render each document header note directly beneath its reference. Both tables use a shared nowrap rule to keep their many columns horizontally scrollable, so note newlines collapse and a long note can increase the table's intrinsic width. The two list templates currently duplicate this presentation, while existing tests verify escaping, note-only search, normal-mode isolation, blank handling, and the string `0`.

Sale and purchase note writers normally validate a maximum of 1,000 characters, but the database columns are text fields and may contain imported or historical values. The presentation therefore needs to remain safe for longer content without changing storage, validation, queries, or Global Payment eligibility.

## Goals / Non-Goals

**Goals:**

- Keep Global Payment rows compact by default while retaining in-place access to the complete note.
- Preserve authored newline boundaries and wrap long unbroken text without widening the table.
- Keep sale and purchase behavior visually and functionally identical.
- Preserve Blade escaping, note search, permissions, routing, pagination, and payment behavior.
- Provide keyboard-operable expansion controls with accurate accessible state.

**Non-Goals:**

- Changing note storage, validation limits, normalization, or database schema.
- Changing how notes are searched or which records appear in Global Payment lists.
- Adding a page-wide expand-all control or persisting expansion state across pagination, filtering, or Livewire refreshes.
- Changing the full-note presentation in sale/purchase detail pages or allocation forms.
- Introducing a new JavaScript dependency.

## Decisions

### Use one shared anonymous Blade component for document-note presentation

Add a shared component that accepts the note and a row-unique identifier, computes whether a compact preview is needed, and renders either a short note or a preview/full-note toggle. Both Livewire table templates will call this component only in global mode.

This prevents the sales and purchase variants from drifting and centralizes escaping, preview calculation, labels, and accessibility markup. A duplicated snippet was considered but rejected because this behavior has identical requirements in both lists and is likely to evolve together.

### Use deterministic character and logical-line limits

A note will be expandable when it exceeds 120 Unicode characters or three logical lines. The collapsed value will contain only the beginning of the note, capped at those same boundaries, with a visual ellipsis. CRLF, CR, and LF input will be treated consistently when determining logical lines, while the stored value remains unchanged.

Deterministic server-side limits make the behavior stable and directly testable without browser geometry. Pure CSS line clamping or runtime overflow measurement was considered, but either makes control visibility dependent on viewport/font measurements and complicates Livewire re-rendering.

### Use Alpine for row-local presentation state

The component will use the Alpine runtime already loaded by the application to keep an `expanded` boolean per rendered note. A `type="button"` control styled as a text link will toggle the state, its Indonesian label, `aria-expanded`, and visibility of preview/full content. Full content will remain local to the row, and state may reset after a Livewire refresh.

Bootstrap collapse was considered, but it requires globally unique selector plumbing and Bootstrap lifecycle behavior inside Livewire-rendered content. Native `details` was also considered, but it provides less control over the requested link labels and the preview/full transition.

### Override nowrap only inside the note container

Add narrowly scoped styles for the shared component: a bounded maximum width, `white-space: pre-wrap`, and aggressive safe wrapping such as `overflow-wrap: anywhere`. The surrounding table retains its current nowrap and horizontal-scroll behavior. Both preview and complete text will use escaped Blade interpolation rather than raw HTML or `nl2br` output.

This avoids changing layout behavior for references, amounts, dates, statuses, or actions. Applying wrapping to the whole reference cell or table was rejected because it would materially alter the existing dense payment workspace.

### Keep allocation-form notes unchanged

The historical capabilities require notes in both list and allocation contexts, but the reported layout problem and requested interaction concern the lists. Allocation forms will continue to show their existing escaped notes. The shared component can be adopted there in a later change if allocation rows demonstrate the same usability problem.

## Risks / Trade-offs

- [A note just below the configured thresholds can still occupy several wrapped visual lines on a narrow viewport] → Bound the note width and choose conservative limits; adjust the constants through focused review if real data shows poor density.
- [The complete note exists in the DOM while collapsed] → Continue using escaped Blade output; this is presentation state, not an authorization boundary.
- [Expansion state resets after Livewire pagination, search, sorting, or filter updates] → Treat reset-to-collapsed as intentional compact-list behavior and document it in focused tests where practical.
- [A 1,000-character expanded note makes one row tall] → This is an explicit user action and preserves the requirement to expose the complete note; width remains bounded.
- [Existing exact-markup assertions for the `0` note will change] → Update only the affected focused tests to assert the shared presentation contract rather than obsolete markup.

## Migration Plan

1. Add the shared Blade component and scoped note styles.
2. Replace the duplicated global-mode note snippets in the sale and purchase Livewire tables.
3. Run the two focused Global Payment table feature-test files, including new short, long, multiline, escaping, and isolation cases.
4. Deploy as a presentation-only change with no data migration or cache backfill.

Rollback consists of restoring the two original note snippets and removing the unused component/styles; no persisted data requires reversal.

## Open Questions

None. The 120-character/three-line preview boundary is an initial explicit design constant that can be tuned in a later presentation-only change.
