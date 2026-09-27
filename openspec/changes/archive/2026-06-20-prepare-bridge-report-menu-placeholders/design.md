## Context

The Reports module already has a server-rendered `/reports` landing page. `ReportsController@index` defines tab/card metadata, filters cards by permission and route existence, hides empty tabs, resolves the active tab from `?tab=`, and renders the cards through `Modules/Reports/Resources/views/index.blade.php`.

The bridge samples under `report-sample/bridge` are HTML snapshots of a broader report menu:

- `sekilas-bisnis.txt`
- `penjualan.txt`
- `pembelian.txt`
- `produk.txt`
- `pajak.txt`

The current implementation cannot show menu-only future reports because a card is only rendered when its named route exists. This change prepares the menu without implementing reports by introducing explicit disabled placeholder cards.

## Goals / Non-Goals

**Goals:**

- Preserve every existing report card, route, permission gate, and destination.
- Append bridge-derived cards to the existing landing page categories.
- Show unimplemented bridge-derived reports as disabled `Belum tersedia` cards.
- Keep disabled cards permission-aware so tabs still reflect the user's report access.
- Keep `Bank`, `Aset`, and `Produksi` out of scope.
- Omit `Jurnal`, `Perubahan Modal`, and `Ringkasan Bisnis` from Sekilas bisnis.

**Non-Goals:**

- No report implementation.
- No new report routes, controllers, Livewire components, query services, exports, or database tables.
- No changes to existing report pages or report exports.
- No sidebar restructure beyond the existing single `Laporan` entry.
- No new permission names unless implementation discovers an existing report permission cannot reasonably cover a placeholder.

## Decisions

### Decision: Use explicit disabled placeholder card metadata

Add a card-level state, such as `available => false` or `status => placeholder`, for bridge-derived reports that do not have implementations. Existing implemented cards stay route-backed and clickable. Placeholder cards render without an anchor, show a `Belum tersedia` badge, and do not show an active `Lihat laporan` link.

**Why:** This keeps the menu visible without adding fake routes or broken links.

**Alternative considered: placeholder route/page:** Rejected because it creates navigable report pages without report behavior, which can confuse users and increases routing/test surface for no functional gain.

**Alternative considered: keep using `Route::has()` only:** Rejected because future cards would remain invisible until their routes exist, which does not satisfy "prepare the menu."

### Decision: Keep existing implemented cards unchanged and append around them

The existing cards stay as-is:

- Sekilas bisnis: `Laporan Laba Rugi`
- Penjualan: `Daftar Penjualan`, `Penjualan Per Customer`, `Penjualan Global`
- Pembelian: `Daftar Pembelian`, `Pembelian Per Supplier`, `Pembelian Global`
- Produk: `Mutasi Stok`, `Mutasi Stok Global`, `Valuasi Stok`
- Lainnya: Mekari tooling

Bridge-derived cards are appended to the matching category. If a bridge item is clearly the same report as an existing card, do not create a duplicate.

**Why:** The user explicitly asked not to touch existing reports and to append menu entries.

### Decision: Scope tabs to available bridge sample files

This change populates only Sekilas bisnis, Penjualan, Pembelian, Produk, and Pajak from the bridge folder. Bank and Aset are intentionally skipped. Produksi is skipped because the bridge folder has no `produksi.txt` sample.

**Why:** The bridge folder is the requested source of truth for this proposal, and missing samples should not be invented.

### Decision: Permission gates reuse current report permission families

Use existing permission families for placeholder visibility:

- Sekilas bisnis and Pajak placeholders: `reports.access`
- Penjualan placeholders: `saleReports.access`
- Pembelian placeholders: `purchaseReports.access`
- Produk placeholders: use stock-related permissions where a close existing permission exists; otherwise use the broadest relevant product/report access already used by the category.

**Why:** The current landing page is permission-aware, and placeholder cards should not leak menu categories to users who cannot access the corresponding report family.

## Risks / Trade-offs

- **[Users may expect disabled cards to work]** -> Render a clear `Belum tersedia` badge and disabled visual treatment instead of a clickable link.
- **[Permission mapping for future reports may change later]** -> Keep mappings conservative and test current visibility behavior; future report implementation can refine the card's permission when the real route is added.
- **[Duplicate-looking cards in Produk]** -> Keep existing local ERP cards unchanged and append bridge cards with sample labels. If a bridge label is effectively identical to an existing card, do not duplicate it.
- **[Large controller config becomes harder to maintain]** -> Keep this change scoped, but prefer small helper methods if the card array becomes difficult to read during implementation.
- **[Existing `Route::has()` filtering could hide placeholders]** -> Adjust filtering so route existence is required only for available/clickable cards, not disabled placeholders.

## Migration Plan

1. Extend the report card config with placeholder state and bridge-derived cards.
2. Update filtering so clickable cards require `Route::has()`, while disabled placeholders require only permission.
3. Update the Blade view to render clickable and disabled cards safely.
4. Add/adjust feature tests for all-permission users, restricted users, skipped tabs/cards, and disabled placeholder rendering.
5. Rollback by reverting the controller/view/test changes; no schema or data migration is involved.

## Open Questions

None. The current proposal assumes disabled `Belum tersedia` cards are the desired menu-only behavior.
