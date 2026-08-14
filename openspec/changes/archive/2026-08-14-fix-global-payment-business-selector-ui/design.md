## Context

Global Sales Payment and Global Purchase Payment use parallel Livewire table components. Each component keeps business filters in two layers: `draftGlobalBusinessFilters` represents edits in the filter panel, while URL-backed `globalBusinessFilters` drives the table and summary cards only after explicit application. Their current native `<select multiple>` controls use a Bootstrap 5 `form-select` class in an application whose global assets and conventions are CoreUI/Bootstrap 4, leaving the selectors visually inconsistent and difficult to use.

Laporan Laba Rugi already wraps a multi-select in `wire:ignore`, initializes Select2 with the globally loaded CoreUI theme, and transfers selections into Livewire. That implementation is the visual and interaction reference, but it currently hardcodes the `selectedSettingIds` Livewire property and does not need to handle URL restoration or an external reset action.

## Goals / Non-Goals

**Goals:**

- Give both Global Payment pages the same polished, searchable, removable-choice multi-business interaction used by Laporan Laba Rugi.
- Preserve the existing distinction between draft selector state and explicitly applied query state.
- Keep the Select2 display synchronized with Livewire during initial URL hydration, filter application, rerenders, and reset.
- Share one configurable integration between sales and purchase so their UI behavior cannot drift.
- Preserve the current server-side predicates, summary-card events, URL parameter shape, permissions, and empty-selection semantics.

**Non-Goals:**

- Change document-date or due-date filtering behavior, Global Payment eligibility, summary-card calculations, or payment workflows.
- Change Laporan Laba Rugi's effective-setting semantics, where an empty report selection falls back to the active setting.
- Add a JavaScript package, database migration, route, endpoint, or permission.
- Redesign the complete Global Payment filter panel or summary cards.

## Decisions

### Use the existing Select2/CoreUI interaction as the visual standard

The Global Payment selector will use Select2 with the existing `coreui` theme, full-width layout, search, clear behavior, and removable selections. The application already loads jQuery, Select2, and its CoreUI stylesheet globally, so this avoids new dependencies and matches a proven report control.

Alternative considered: change `form-select` to Bootstrap 4's `custom-select`. That would repair basic styling but retain the tall native list box, platform-dependent selection gestures, and poor usability as the business count grows.

### Make the reusable selector binding configurable

The shared Blade integration will accept a unique element ID, available setting options, a Livewire property name, and the property's current selected values. Laporan Laba Rugi can continue binding `selectedSettingIds`, while both Global Payment tables bind `draftGlobalBusinessFilters`. Unique IDs prevent collisions when Livewire components coexist on a page.

Alternative considered: copy the report JavaScript separately into the sale and purchase views. That creates three nearly identical lifecycle implementations and makes future fixes likely to drift.

### Synchronize through explicit client-side lifecycle hooks

The underlying selector remains inside `wire:ignore`, so Livewire will not reconcile Select2's generated DOM. Initialization must seed Select2 from the current Livewire values without emitting an unintended change. User changes set only the configured draft property. After Livewire commits that can alter the property—especially reset or URL hydration—the integration compares the client selection with the current server value and updates Select2 without recursively dispatching another model change.

The implementation may use a small browser event dispatched by the Livewire component or a Livewire commit/render hook. A dedicated synchronization event is preferred when it makes ownership and tests clearer. Reinitialization must remove prior event handlers or destroy an existing Select2 instance before binding again.

Alternative considered: rely solely on Select2's initial DOM options. That fails after `Reset semua filter` because `wire:ignore` prevents Livewire from clearing Select2's client-managed display.

### Preserve draft/apply and empty-as-all semantics

Selecting or removing businesses updates only `draftGlobalBusinessFilters`. `Terapkan Filter` remains the sole transition that copies draft values into `globalBusinessFilters`, updates URL state, refreshes results, and notifies summary cards. An empty Global Payment selection continues to mean all businesses; the Select2 placeholder must communicate this without introducing an `all` sentinel option.

### Keep business option loading bounded and consistent

The selector should receive an ordered, minimal list of setting IDs and company names from the Livewire render context or a shared loader rather than executing `Setting::all()` repeatedly inside Blade. This keeps presentation free of database access and gives sales and purchase the same deterministic ordering.

## Risks / Trade-offs

- [Select2 display diverges from Livewire after reset or hydration] → Seed from the property on initialization and provide an idempotent server-to-client synchronization path covered by reset and refresh tests.
- [Lifecycle hooks attach duplicate change handlers after rerenders] → Namespace/remove handlers or destroy the prior Select2 instance before initialization.
- [Programmatic synchronization creates an event loop] → Update Select2 with a non-propagating change path or guard programmatic updates before writing to Livewire.
- [Generalizing the report partial regresses report selection] → Keep defaults compatible with `selectedSettingIds` and add focused report rendering/binding coverage.
- [Search conflicts with the archived design's former non-search goal] → Treat this proposal and delta specs as the superseding UX decision; filtering semantics remain unchanged.
- [All-business meaning becomes ambiguous] → Keep the explicit helper text/placeholder and do not add a persisted sentinel value.

## Migration Plan

Deploy the reusable selector integration, both Global Payment view updates, any minimal Livewire synchronization support, and focused tests together. No stored data or URL migration is required because applied values remain the existing `globalBusinessFilters` array. Existing shared URLs continue to hydrate the same property and will additionally restore the visible Select2 choices.

Rollback consists of restoring the native controls and removing the new client synchronization integration; payment and filter data require no rollback.

## Open Questions

None.
