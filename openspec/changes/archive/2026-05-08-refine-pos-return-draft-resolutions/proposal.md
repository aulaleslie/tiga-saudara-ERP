## Why

The current POS return draft flow loses source-line identity when the same serialized product appears in both bundled and non-bundled POS lines, causing incorrect return document composition. It also derives bundled context from split Sales Detail rows instead of the original POS transaction line, so transactions like `TNC-RCP-2026-05-00001` render zero-quantity bundle component rows as top-level return cards while the actual bundled Samsung serials appear as ordinary non-bundle serial rows. Draft creation also creates linked Sales Return records too early, even though draft create/edit/delete must not mutate stock, dispatch, payment, or execution documents.

## What Changes

- Refine POS return creation so saving a return creates only a draft POS Return header and draft POS Return Lines.
- Replace the current header-level return option with line-level return resolutions: `none`, `product_replacement`, and `cash_return`.
- Represent serial-tracked products at source-serial granularity so each sold serial can choose its own resolution and replacement serial.
- Preserve source identity for same-SKU lines by original POS transaction line, sale, sale detail, dispatch detail, serial, bundle context, owner/source setting, source location, and tax context.
- Use original POS transaction line metadata as the source of truth for bundled serial parent context, while retaining Sales Detail and Sales Bundle Item rows for source allocation and accounting traceability.
- Render POS return draft rows grouped by original POS transaction line, so the form matches the receipt shape and hides zero-quantity split bundle component rows from top-level returnable cards.
- Preserve bundled serial behavior by auto-carrying required bundle components for traceability only when the serialized bundle parent has an actionable resolution.
- For bundled serial `cash_return`, expose the full original POS unit price as the customer-facing expected amount.
- For bundled serial `product_replacement`, show bundled component trace and remaining component availability counts without showing location names; availability must be computed from the POS source/location allocation context and must not reserve or mutate stock during draft.
- Require at least one actionable line before a draft POS return can be saved.
- Require replacement serial input during draft create/edit when a serial-tracked line uses `product_replacement`.
- Allow draft POS returns to be edited and hard-deleted.
- Allow rejected POS returns to be edited, resetting them to draft, and deleted through an audited soft-delete style marker.
- Defer Sales Return document creation, stock mutation, dispatch quantity reduction, payment settlement, and inventory transaction history to later approval/receiving/settlement work.

## Capabilities

### New Capabilities

- `pos-return-draft-resolutions`: Covers POS return draft create, edit, and delete behavior with per-line resolutions, serial-level replacement input, bundled source traceability, and no execution-side mutations.

### Modified Capabilities

- None.

## Impact

- Affected POS return files under `Modules/Pos`, including return services, entities, migrations, controllers, Livewire components, Blade views, and feature tests.
- Existing POS checkout, sale, dispatch, bundle, and serial data remains read-only source context during draft create/edit/delete.
- Existing Sales Return lifecycle remains out of scope for this change and must not be invoked during draft create/edit/delete.
- Database changes may be needed for `pos_return_lines` to store line-level resolution, returned serial identity, replacement serial identity, source POS line identity, and audited rejected-delete metadata.
