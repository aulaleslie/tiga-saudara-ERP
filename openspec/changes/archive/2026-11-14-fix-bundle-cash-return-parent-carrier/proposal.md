# Proposal

## Why

A whole-bundle cash return ("Tunai" + "Paket Lengkap") cannot be saved for almost any bundle sold since October 2026. The save fails with "Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel (produk utama dan semua komponennya)." Production example: TPI-TXN-2026-11-0170 (sale 215753, sale_detail 432029).

Current POS checkout stores bundle components only as `sale_bundle_items` attached to the parent's own `sale_details` row. It does not create zero-price sibling rows; 2 of 978 bundle items since 2026-10-01 have one.

- **Synthesis:** component-line synthesis still requires a zero-price sibling row, so with none present it skips every component without any error.
- **Completeness check:** since commit b11259c9 (2026-10-23), this check uses `PosBundleComponentResolver`. The resolver resolves those same components (carrier = the parent row) and reports them all as missing.

Cashiers can currently refund a bundle only as "Hanya Utama", which leaves the components out of stock.

## What Changes

- Whole-bundle cash return creates one component return line per captured component. Each line's carrier and dispatch detail come from the bundle component resolver, including when the carrier is the parent's own sale row. The search for a sibling row is removed.
- Component lines are recognised by `component_quantity_per_bundle > 0`, falling back to product_id differing from the carrier row's product. This replaces `bundle_parent_sale_detail_id != sale_detail_id` in submission, draft resubmit and approval preview/planning. Draft resubmit passes the component line's real product_id.
- Refundable-quantity and returnable-quantity guards count per (sale_detail_id, product_id). Component lines on a shared carrier therefore no longer use up the parent's quantity.
- Component lines carry refund 0, `unit_price` 0 and `line_total` 0. The parent line carries the full bundle refund. The component's allocated price is kept in `line_meta` for reference.
- Unchanged: parent-only ("Hanya Utama") cash returns and whole-bundle replacement ("Ganti").
- No data backfill. The blocked return can be retried after deploy.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `pos-return-draft-resolutions`: adds a requirement that whole-bundle cash return includes every captured component, whatever the shape of the component's carrier row. It also covers how component lines are valued and counted when they share the parent's sale row.

## Impact

- `Modules/Pos/Services/PosReturnSubmissionService.php`: `synthesizeBundleCashReturnComponents`, `store`/`update` line building, `submitDraftForApproval`, `validateDraftLines`, `assertBundleCashReturnCompleteness`.
- `app/Support/PosReturn/PosReturnQuantityGuard.php`: `getRefundableQuantity`, `getReturnableQuantity`.
- `Modules/Pos/Services/PosReturnApprovalPreviewPlannerService.php`: component-line classification.
- `Modules/Pos/Entities/PosReturnLine.php`: a shared component-line helper.
- Approval execution and stock-in (`PosReturnLifecycleService`, `PosReturnApprovalPlanPersistenceService`): to be verified for component lines whose carrier is the parent's row.
- Tests: a new feature test with the production data shape. Existing bundle return tests are re-run, focused by filter.
- No schema migration.
