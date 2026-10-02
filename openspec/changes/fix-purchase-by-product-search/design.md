# Design

## Context

See [proposal.md](proposal.md). The Livewire report component currently scopes product suggestions through `products.setting_id`, then clears both `productSearch` and `productOptions` in `selectProduct`. The Blade list is rendered whenever the search has two characters. The report query separately scopes purchases through `purchases.setting_id`.

## Goals / Non-Goals

**Goals:** Keep suggestion visibility separate from search state and preserve unique product IDs in the pending filter.

**Non-Goals:** Change supplier, category, or tag suggestions; remove the legacy product setting column; change report row or export scope.

## Decisions

- Remove the setting predicate only from `updatedProductSearch`. Preserve the two character threshold and result limit. The report query continues to scope purchases by setting and filter selected product IDs.
- Retain `productSearch` and `productOptions` after `selectProduct`. Use a local visibility state in the Blade/Alpine product control: show suggestions while the input is focused and the query is eligible, hide after selection or outside interaction, and show the retained results on refocus. This keeps visibility changes immediate without an extra server request. A new query still updates options through the existing Livewire hook.
- Keep selected suggestions visible as disabled entries marked `Sudah dipilih`. Maintain the component's ID membership guard so repeated calls to `selectProduct` cannot append a duplicate. The selected ID remains the source of truth; labels are display data.

## Risks / Trade-offs

- [A Livewire DOM update could reopen a list after selection] → Keep visibility in stable local control state and verify the select/refocus sequence in the rendered view.
- [A retained search term could appear after closing and reopening the drawer] → Preserve existing `cancelFilters` and `resetFilters` behavior, which clear pending search state when appropriate.
- [Global suggestions can include products with no purchases in the selected setting] → Accept this because products are global; the report correctly returns no matching rows for that selection.
