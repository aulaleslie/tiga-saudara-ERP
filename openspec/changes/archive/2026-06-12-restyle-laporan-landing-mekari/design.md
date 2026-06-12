## Context

The reports landing page (`reports.index`, served by `ReportsController@index`, rendered by `Modules/Reports/Resources/views/index.blade.php`) was recently introduced by the `refactor-laporan-landing-tabs` change. It currently uses Bootstrap `nav-pills` tabs and icon-forward, center-aligned cards where the whole card is a link with no description text.

Most users came from Mekari Jurnal, whose Laporan page uses underline tabs and cards with a title + description + "Lihat laporan" button. This change reskins the landing to match Mekari and re-labels/re-orders the tabs to Mekari's taxonomy, so the transition feels seamless.

The controller already filters cards by `Gate::allows($card['permission'])` and `Route::has($card['route'])`, drops zero-card tabs, and resolves the active tab from `?tab=` with a first-visible fallback. Bootstrap Icons (`bi bi-*`) are loaded globally via `resources/views/includes/main-css.blade.php`.

## Goals / Non-Goals

**Goals:**
- Tab strip matches Mekari: underline style, active tab marked by a bottom border.
- Cards match Mekari: small leading icon + title, a description sentence, and a "Lihat laporan" button; whole card stays clickable.
- Tabs use Mekari labels/order: Sekilas bisnis · Penjualan · Pembelian · Produk · Aset · Bank · Pajak · Produksi · Lainnya.
- Empty Mekari tabs (Aset/Bank/Pajak/Produksi) are declared but hidden until reports map to them; when filled later they appear in their declared position.
- Per-card Indonesian descriptions authored for every existing card.

**Non-Goals:**
- No changes to routes, permissions, or any downstream report controller/view behavior.
- No new reports; the empty Mekari tabs stay empty until separate future changes add reports.
- No change to active-tab resolution logic or zero-card hiding logic (reused as-is).
- No adoption of Mekari's CSS framework (Emotion); we use the existing Bootstrap theme + Bootstrap Icons.

## Decisions

### Keep Mekari's taxonomy, map existing reports onto it
Rather than keeping the prior 5 tabs (Laba/Rugi, Penjualan, Pembelian, Stock, Lainnya), the `$config` array is rewritten to Mekari's tab slugs/labels in Mekari order. Mapping:

| Mekari tab | Slug | Cards (unchanged routes/permissions) |
|---|---|---|
| Sekilas bisnis | `sekilas-bisnis` | Laporan Laba Rugi |
| Penjualan | `penjualan` | Daftar Penjualan, Penjualan Per Customer, Penjualan Global |
| Pembelian | `pembelian` | Daftar Pembelian, Pembelian Per Supplier, Pembelian Global |
| Produk | `produk` | Mutasi Stok, Mutasi Stok Global, Valuasi Stok |
| Aset | `aset` | — (declared, no cards) |
| Bank | `bank` | — (declared, no cards) |
| Pajak | `pajak` | — (declared, no cards) |
| Produksi | `produksi` | — (declared, no cards) |
| Lainnya | `lainnya` | Mekari Converter, Mekari Invoice Generator |

**Rationale:** users recognize Mekari's shape; routes/permissions are untouched so only labels/ordering and presentation change. *Alternative considered:* keep current taxonomy and only restyle — rejected because the user explicitly wants the Mekari layout to minimize transition shock.

### Declare empty tabs in-order; rely on existing hide-empty logic
Empty Mekari tabs are included in `$config` (in order) with an empty `cards` array. The existing filter that drops tabs with zero permitted cards already hides them — matching Mekari's own behavior of hiding empty tabs via `display:none`. When a future change adds a card to, say, `aset`, it appears between Produk and Bank with no reordering.

**Rationale:** zero new controller logic; future-proofs tab positioning. *Alternative considered:* only declare tabs that have reports today and append new ones later — rejected because newly-added tabs would land out of Mekari order.

### Add a `description` field per card; author Indonesian copy
Each card entry gains `'description' => '...'`. Draft copy (editable):
- Laporan Laba Rugi — Menampilkan ringkasan pendapatan, biaya, dan laba/rugi dalam periode tertentu.
- Daftar Penjualan — Menampilkan daftar transaksi penjualan beserta total nilainya dalam periode tertentu.
- Penjualan Per Customer — Menampilkan rekap nilai penjualan yang dikelompokkan per customer.
- Penjualan Global — Menampilkan data penjualan dari semua setting/cabang dalam satu laporan.
- Daftar Pembelian — Menampilkan daftar transaksi pembelian beserta total nilainya dalam periode tertentu.
- Pembelian Per Supplier — Menampilkan rekap nilai pembelian yang dikelompokkan per supplier.
- Pembelian Global — Menampilkan data pembelian dari semua setting/cabang dalam satu laporan.
- Mutasi Stok — Menampilkan pergerakan masuk dan keluar stok per produk dalam periode tertentu.
- Mutasi Stok Global — Menampilkan pergerakan stok dari semua setting/cabang dalam satu laporan.
- Valuasi Stok — Menampilkan nilai persediaan barang berdasarkan kuantitas dan harga rata-rata.
- Mekari Converter — Mengonversi laporan Mekari ke format yang siap diproses.
- Mekari Invoice Generator — Membuat dokumen invoice PDF dari data Mekari.

### Underline tabs via existing Bootstrap + scoped CSS
Use a minimal underline style (not the boxed `nav-tabs`), implemented with the existing `nav` markup and a scoped `page_css` rule giving the active tab a colored bottom border and muting inactive tabs. *Alternative considered:* Bootstrap `nav-tabs` boxed style — rejected because the bordered box fights visually with the card grid and diverges from Mekari's flat underline.

### Whole card clickable + button affordance (valid HTML)
The card is wrapped in a single `<a>` to the report route (preserving existing behavior). The "Lihat laporan" affordance is rendered as a button-styled `<span>` inside that anchor — not a nested `<a>`/`<button>` — to keep the HTML valid while looking like Mekari's button. *Alternative considered:* make only the button navigate (Mekari's literal behavior) — rejected as a silent regression for users trained that the whole card clicks.

## Risks / Trade-offs

- **Renamed tab labels break existing label-based tests** (`ReportsLandingTest` asserts "Laba/Rugi", "Stock", "Lainnya") → Mitigation: update those assertions to the new Mekari labels as part of this change; add a description-rendering assertion.
- **Description copy is drafted, not authoritative** → Mitigation: copy is centralized in the controller config and explicitly flagged for review/edit; wording changes are a one-line edit per card.
- **Button-styled `<span>` inside card anchor could confuse assistive tech** (looks like a button, is not focusable separately) → Mitigation: the whole card anchor is the single focusable/navigable element; the span is decorative and inherits the link's destination.
- **Bundling empty tabs in config could read as "unfinished"** → Mitigation: hidden by existing logic, so no dead tabs surface to users; documented here as intentional future-proofing.
