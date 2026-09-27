## Why

Most users of this ERP migrated from Mekari Jurnal, where the "Laporan" page uses a recognizable layout: underline-style tabs, and report cards that show a title, a short description, and a "Lihat laporan" button. The current landing uses Bootstrap pill tabs and icon-forward cards with no descriptions, creating a jarring transition for those users. Adopting Mekari's visual language and tab taxonomy reduces relearning cost and makes the page feel familiar from day one.

## What Changes

- Replace the Bootstrap `nav-pills` tab strip with Mekari-style **underline tabs** (active tab marked by a colored bottom border, not a filled pill).
- Restyle report cards to the Mekari layout: a small leading icon next to the title, a **description paragraph**, and a **"Lihat laporan" button** anchored at the bottom. The whole card remains clickable (the button is a visual affordance, not the only navigation target).
- Add a per-card **`description`** string to the landing config (the config has none today) and author Indonesian descriptions for every existing card.
- Re-label and re-order the tabs to match Mekari's taxonomy and order: **Sekilas bisnis · Penjualan · Pembelian · Produk · Aset · Bank · Pajak · Produksi**, with a trailing **Lainnya** tab for custom tools (Mekari Converter, Invoice Generator) that have no Mekari equivalent. Existing reports map onto these tabs (e.g. Laporan Laba Rugi → Sekilas bisnis, stock reports → Produk).
- Declare the full Mekari tab set in config order even when a tab currently has no reports; **empty tabs stay hidden** (the existing zero-card drop logic already does this, matching Mekari's own `display:none` behavior). As future reports are added for Aset/Bank/Pajak/Produksi, those tabs light up in their correct position automatically.

No route, permission, or controller authorization logic changes. Card permission gating, route existence filtering, and active-tab resolution remain as-is.

## Capabilities

### New Capabilities
<!-- None. This change restyles and re-labels an existing landing capability. -->

### Modified Capabilities
- `reports-landing-navigation`: Tab labels and ordering change to the Mekari taxonomy; the landing renders tabs in a fixed declared order with empty tabs hidden; each report card gains a required description and is presented with a "Lihat laporan" call-to-action alongside the existing whole-card link.

## Impact

- `Modules/Reports/Http/Controllers/ReportsController.php` — rewrite the `$config` array: Mekari tab slugs/labels/order, add `description` to every card, declare empty Mekari tabs.
- `Modules/Reports/Resources/views/index.blade.php` — underline tab markup + restyled card layout (icon + title + description + button) and accompanying `page_css`.
- `Modules/Reports/Tests/Feature/ReportsLandingTest.php` — assertions that match tabs by the old labels (Laba/Rugi, Stock, Lainnya) must update to the new Mekari labels; add coverage that card descriptions render.
- No changes to routes, permissions, or downstream report controllers/views.
