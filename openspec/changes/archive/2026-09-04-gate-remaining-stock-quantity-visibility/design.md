## Context

POS product search (`PosProductSearchService::search`) and Price Points (`app/Livewire/PricePoint/Browser.php`) both compute `available_qty` via the same shape of raw SQL (`SUM(product_stocks.quantity)` scoped to allowed sale locations) and pass it through to their respective views, where it is rendered as "Stok N <unit>". Both already compute a derived in-stock/out-of-stock/service state from the same `available_qty` value, and that derived state (badges, disabled styling, non-selectability) is explicitly out of scope for this change — only the numeric quantity's presence in the response/view is being gated.

POS delivers results as a plain JSON payload consumed by vanilla JS in `sell.blade.php`. Price Points is a Livewire component, so its public properties/computed data are serialized into the Livewire wire payload on every render. In both cases, any field attached to the response is visible in browser devtools regardless of what the blade/JS chooses to render — so cutting visibility only in the template is insufficient for a real access boundary. The gate must happen where the response/prop data is assembled, before it reaches the client.

## Goals / Non-Goals

**Goals:**
- Introduce one shared permission, `inventory.view_remaining_stock`, checked identically in both features.
- Users without the permission never receive the numeric `available_qty` (or its formatted/denominated string) in the payload — the "Stok" element is entirely absent from the card, not blanked or replaced.
- Users with the permission see exactly what is rendered today.
- Zero change to stock-state computation (`available_qty <= 0` → out-of-stock, `stock_managed` → service badge), card selectability, search/pagination, or unit-conversion formatting math.

**Non-Goals:**
- Redesigning the permission/role model or POS permission bundles.
- Changing which roles are granted the permission by default (a seeding/admin decision, not this change's spec concern — see Open Questions).
- Any change to Terminal Harga's or POS's other displayed fields (price, unit, badges).

## Decisions

**Gate at data assembly, not at render.** Both `PosProductSearchService` and `Browser.php` will check `auth()->user()->can('inventory.view_remaining_stock')` once per request/render and conditionally include `available_qty` / `formatted_available_qty` in the mapped result — key omitted entirely, not set to `null`, so the blade/JS can treat "unset" as "don't render" without special-casing null vs. zero (zero is a legitimate value for permitted users).
- Alternative considered: gate in the blade/JS only (`@can`/`if` around the printed number). Rejected — the value would still be inspectable in the network response (POS) or Livewire wire payload (Price Points), which defeats the purpose given the user's intent is that unprivileged users "don't necessarily need to know," a genuine visibility boundary rather than a decluttering preference.

**One shared permission, not two.** `inventory.view_remaining_stock` is checked in both `PosProductSearchService` and `Browser.php`, rather than separate `pos.*`/`pricePoints.*` variants. Confirmed with the user: they want a single permission governing "remaining stock quantity visibility" as one concept, independent of which screen it's viewed from, and it should be its own dedicated permission rather than folded into `pos.sell` or `pricePoints.access`.

**Blade/JS: presence-check, not permission-check.** The view layer doesn't re-check the permission — it simply renders the "Stok" block only when the field is present in the data it already received (`isset($product['available_qty'])` / `'available_qty' in product` in JS). This keeps the permission check single-sourced in the two data-assembly points and avoids the view layer needing its own authorization logic.

## Risks / Trade-offs

- [Risk] A future code path could add a third stock-display surface that forgets the gate, re-leaking quantities. → Mitigation: both existing specs (`pos-product-search-stock-visibility`, `price-point-stock-visibility`) are updated with explicit permission-conditional scenarios, so any future consumer of `available_qty` should find the precedent when reading those specs.
- [Risk] `Browser.php` Livewire polling/refresh cycles must not have already cached `available_qty` on a component property from before a permission check — if the property is set unconditionally then filtered only at blade level, it would leak via wire:snapshot. → Mitigation: the omission happens at the point the product array/collection is built for the view, not via a later filter step.
- [Trade-off] Because the field is entirely absent rather than present-but-zero, any test or downstream code asserting on `$product['available_qty']` for a non-permitted user must be updated to assert absence, not a zero value. This is intentional per the design goal but is a minor testing-ergonomics cost.

## Migration Plan

- Add `inventory.view_remaining_stock` to `app/Config/Permissions.php`. `deploy.bat` already runs `php artisan db:seed --class="Modules\User\Database\Seeders\PermissionsTableSeeder" --force` unconditionally on every production deploy (step 7/10), which loads directly from `app/Config/Permissions.php` — so the new permission reaches production automatically with no extra deploy step required.
- No data migration; this is a pure authorization + display change. No rollback complexity — reverting the permission check reverts to current unconditional display.
- Existing roles do not automatically receive the new permission (Spatie permissions default to unassigned); an admin explicitly grants it to roles that should retain quantity visibility. This is expected and matches "opt-in" intent — quantity is hidden by default until granted.

## Open Questions

- Should any role receive `inventory.view_remaining_stock` by default as part of this change (e.g. seeded onto an existing "Manager"/"Owner" role), or is this left entirely to manual post-deploy admin configuration? Proposal currently treats default role assignment as out of scope for the spec; confirm before/during tasks if a seeder update is expected.
