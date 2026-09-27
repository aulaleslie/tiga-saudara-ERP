## Context

The bundle item editor on the product bundle create/edit pages is a Livewire 3 component (`App\Livewire\Product\BundleTable`) embedded inside a plain HTML `<form method="POST">`. The Livewire component renders the editable table; alongside it the same Blade emits a parallel block of `<input type="hidden" name="items[i][...]">` mirrors so that values held in the Livewire `$items` array reach the controller through the native form submit.

Two bugs are visible to users:

1. **Price edits never persist.** Editing a row's `informational_item_price` and clicking the form's save button results in the server saving the previous value.
2. **Row removal does not work.** Clicking "Hapus" on a non-last row appears to leave the row in place or causes another row's selected product to appear in the wrong row.

Investigation pinned both to wiring mistakes in [resources/views/livewire/product/bundle-table.blade.php](resources/views/livewire/product/bundle-table.blade.php):

- The per-row inputs use bare `wire:model="items.{i}.quantity"` and `wire:model="items.{i}.informational_item_price"`. In Livewire 3, bare `wire:model` is **deferred** — it does not sync to the server until a Livewire roundtrip is triggered. The native form submit fires before any roundtrip, so the hidden-input mirrors at the bottom of the Blade still hold the previously rendered (pre-edit) value.
- The nested `<livewire:modules.product.product-search-dropdown :index="$rowKey" :key="$rowKey" :selected="$item['product_id']" />` uses `:key=...` instead of `wire:key=...`. In Livewire 3, only `wire:key` participates in morph identity for child components rendered in a loop; `:key` is silently passed as a `mount()` kwarg, and since `ProductSearchDropdown::mount()` does not accept `$key`, it is dropped. The children are therefore reconciled by render position. After `removeItem()` calls `array_values()`, surviving children are re-bound to the wrong `<tr>` slots, so a removal of a non-last row visually presents as "the wrong row was removed" or "removal didn't happen."

The repo's idiom elsewhere confirms both findings: every other multi-row Livewire form (for example [resources/views/livewire/expense/expense-form.blade.php](resources/views/livewire/expense/expense-form.blade.php)) is fully Livewire-driven and uses `wire:submit`, where deferred `wire:model` is safe; and every other place that nests a Livewire component in a loop uses `wire:key`, never `:key`.

## Goals / Non-Goals

**Goals:**
- Edits to `quantity` and `informational_item_price` on any row reliably reach the controller on form submit.
- "Hapus" removes exactly the targeted row, without disturbing other rows' state.
- Minimal, surgical change — no controller, route, schema, or data changes.

**Non-Goals:**
- Migrating the bundle form to a fully Livewire-driven submit (replacing the hidden-input mirror pattern). This would be cleaner long-term but is out of scope for fixing these two bugs.
- Touching `ProductSearchDropdown` or its lifecycle.
- Any visual / styling changes.

## Decisions

### Use `wire:model.blur` for `quantity` and `informational_item_price`

`wire:model.blur` syncs the field to the Livewire server state when the input loses focus. Because clicking the form's submit button blurs the active input first, the Livewire roundtrip completes before the form posts, and the hidden-input mirrors at the bottom of the Blade are re-rendered with the typed value.

**Alternatives considered:**
- `wire:model.live` — syncs every keystroke. Works, but produces one network roundtrip per character on a numeric field for no real benefit.
- Switching the form to `wire:submit` and dropping the hidden-input mirrors — correct architecture but a larger refactor that also requires changing the controller to accept a different submission style. Out of scope here.
- Adding a JS `onclick` handler on the submit button to force a Livewire `$wire.$commit()` first — fragile and pushes complexity into a script block.

### Use `wire:key="psd-{{ $rowKey }}"` on the nested `<livewire:...product-search-dropdown />`

`wire:key` is the only attribute Livewire 3's morph uses to identify a child component across renders inside a loop. With per-row stable keys, removing a row removes its child component, and surviving children remain attached to their original `<tr>`.

**Alternatives considered:**
- Keep `:key` and rely on positional reconciliation — this is the current state and is the cause of the bug.
- Re-mount all children after a removal (e.g. by changing all keys) — defeats the purpose of `wire:key` and discards intentional child state.

The `psd-` prefix is purely defensive: it keeps the child's key in a different namespace from the `<tr>`'s `wire:key="bundle-row-{{ $rowKey }}"`, so a future refactor that moves either key cannot accidentally collide.

## Risks / Trade-offs

- **Risk:** A user has the price input focused and clicks somewhere that does not blur the input before submitting (e.g. some browser quirk). → **Mitigation:** clicking the submit button is itself a focus change and triggers blur; this is the standard browser behavior. If a regression were ever observed, escalating to `.live` is a one-character change.
- **Risk:** `wire:key` change causes existing edit sessions to morph differently on first deploy. → **Mitigation:** the bundle editor renders fresh on each page load — there is no persistent client state across deploys.
- **Trade-off:** This change leaves the dual-track (HTML form + hidden-input mirrors) architecture in place. A future change can migrate to a Livewire-driven submit; this proposal deliberately stays surgical.

## Migration Plan

No migration. The change is a Blade-only edit; deploying the new view file is sufficient. Rollback is reverting the Blade edit.
