## Context

POS Return create and edit already share `resources/views/livewire/modules/pos/pos-return/partials/form-surface.blade.php`, which presents transaction information, payment information, grouped product cards, serial rows, resolution controls, bundle component trace, and return totals.

The current detail view at `Modules/Pos/Resources/views/returns/show.blade.php` is not aligned with that surface. It keeps important lifecycle actions and audit information, but the body uses three summary boxes and a flat line table. The desired result is a readonly review view that keeps the same mental model as create/edit while avoiding any implication that detail data can be edited.

## Goals / Non-Goals

**Goals:**
- Render POS Return detail with the same grouped product-card structure as create/edit.
- Keep lifecycle actions available in the detail header as a title/status/action toolbar.
- Display return decisions as readonly badges and text, not disabled form controls.
- Show returned lines first while preserving access to full original snapshot context in a collapsed section.
- Keep linked Sales Return visibility at both summary and line-review levels.
- Keep technical audit information available but secondary.
- Avoid changing lifecycle, permission, persistence, or stock/payment behavior.

**Non-Goals:**
- Do not make the detail page editable.
- Do not change draft create/edit behavior.
- Do not add new POS Return statuses, actions, permissions, or database columns.
- Do not change Sales Return lifecycle execution.
- Do not redesign the POS Return index page.

## Decisions

1. Use a dedicated readonly partial instead of reusing the interactive form partial.

   The create/edit partial is Livewire-oriented and contains `wire:click`, `wire:model`, validation placement, and availability calculations intended for mutation. A dedicated readonly partial can match the visual structure without carrying interactive assumptions.

   Alternative considered: add a readonly mode to `form-surface.blade.php`. This would reduce duplication, but it risks condition-heavy markup and accidental interactive behavior on the detail page.

2. Keep the detail header as a lifecycle toolbar.

   Detail is the operational page for approval, receiving, settlement, dispatch, archive, and cancellation. The header should remain action-oriented, but use a cleaner structure: title and created timestamp on the left, status badges and permitted lifecycle actions on the right.

   Alternative considered: make the header match edit/create exactly. That would hide or displace lifecycle controls that belong on detail.

3. Represent resolutions as readonly badges.

   The detail page should show only the chosen state: `Tidak Ada Aksi`, `Tunai`, or `Ganti Produk`. Disabled segmented controls look too much like editable UI and add visual noise.

4. Show returned/actionable lines first, with original snapshot context collapsed.

   Most review tasks need the actual return lines, not every original transaction line. The full snapshot remains useful for audit and context, so it should be available behind a collapse/details section.

5. Keep linked Sales Return information in two levels.

   A summary area should list linked Sales Return documents and statuses. Line-level review should show the linked Sales Return reference when available so users can trace a returned line to the execution document without leaving the detail.

## Risks / Trade-offs

- Partial duplication with the create/edit surface -> Keep the readonly partial intentionally small and mirror only visual sections that matter for review.
- Snapshot data may be incomplete for historical or manually corrected returns -> Fall back to persisted `pos_return_lines` and show unavailable snapshot context gracefully.
- Detail page may become long for large receipts -> Use returned lines first and collapsed full snapshot/audit sections.
- Tests may overfit exact markup -> Prefer assertions on visible labels, badges, actions, and absence of editable controls over brittle HTML structure.
