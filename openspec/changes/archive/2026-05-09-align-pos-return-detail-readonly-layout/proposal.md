## Why

The POS Return detail page currently uses a separate summary-and-table layout, while create and edit use a grouped transaction form surface. This makes review harder because users must mentally translate the same return data between different page structures.

## What Changes

- Add a readonly POS Return detail surface that visually mirrors the create/edit grouped product-card layout.
- Keep the detail page lifecycle header and actions, but organize them as a clear title/status/action toolbar.
- Show selected resolutions as readonly badges rather than disabled editable controls.
- Show returned/actionable lines first, with a collapsible section for the full original transaction snapshot context.
- Display non-serial quantities as returned quantity only.
- Display serial returns with returned serial and replacement serial in the same row when applicable.
- Keep linked Sales Return visibility both at summary level and lightly at line level.
- Move the snapshot hash and technical audit metadata into a collapsed audit section.
- Use a dedicated readonly Blade partial instead of extending the interactive create/edit form partial.

## Capabilities

### New Capabilities
- `pos-return-readonly-detail-layout`: Defines the readonly POS Return detail layout and review behavior that mirrors create/edit without exposing editable controls.

### Modified Capabilities

None.

## Impact

- Affected views: `Modules/Pos/Resources/views/returns/show.blade.php`.
- New view surface likely under `resources/views/livewire/modules/pos/pos-return/partials/` or a POS module view partial.
- Controller loading may need additional relationships or a snapshot reconstruction path for readonly display.
- Tests should cover the detail layout, readonly resolution badges, linked Sales Return visibility, collapsible snapshot/audit context, and permission-safe lifecycle actions.
- No database schema, external dependency, API, or lifecycle-service behavior change is intended.
