## 1. Bundle Matrix Data and Trusted State

- [x] 1.1 Extend `CrossBusinessPriceService` to load non-null bundle replica groups for the routed product and build deterministic group headers plus a complete setting-by-group matrix whose missing cells contain no editable bundle payload.
- [x] 1.2 Include existing bundle cell IDs, product/setting/group membership, prices, versions, and the complete setting list in signed trusted loaded-state evidence.
- [x] 1.3 Extend `CrossBusinessPriceController::edit` to provide the bundle matrix and trusted snapshot to the existing cross-business price view.

## 2. Validation and Atomic Persistence

- [x] 2.1 Extend `CrossBusinessPriceUpdateRequest` with non-negative two-decimal bundle-price validation and duplicate submitted bundle/cell detection while allowing no bundle input when the product has no grouped bundles.
- [x] 2.2 Extend snapshot verification and locked database checks to reject stale setting membership, bundle presence, price/version, product ownership, setting ownership, replica lineage, incomplete matrices, unavailable cells, and foreign identities.
- [x] 2.3 Extend the existing transaction to update only `bundle_sale_price` on verified existing bundle copies while preserving all bundle definition and lineage fields and rolling back base, conversion, and bundle changes together on any failure.
- [x] 2.4 Record only changed bundle prices through the existing `bundle_price_updated` feed contract with per-setting before/after snapshots and the combined save's operation UUID.
- [x] 2.5 Add forward migration dropping `product_price_feed_events_operation_uuid_unique` and adding `product_price_feed_events_operation_uuid_index` to allow multiple feed event rows per combined save operation, with safe rollback handling and schema verification tests.

## 3. Cross-Business Price Interface

- [x] 3.1 Add a responsive `Harga Paket` matrix with business rows, deterministic named replica-group columns, inactive/differing-name guidance, and an empty state when the product has no grouped bundles.
- [x] 3.2 Render existing bundle copies as initially read-only masked price inputs and missing copies as permanent read-only `Paket tidak tersedia` cells that have no submitted input.
- [x] 3.3 Integrate bundle inputs with `Ubah`, `Batal`, validation redisplay, dirty-state detection, submit unmasking, and duplicate-save protection without changing existing base or conversion behavior.
- [x] 3.4 Add apply-to-all controls that copy a changed bundle price only among existing cells with the same persisted replica-group identity and never fill missing cells.

## 4. Focused Verification

- [x] 4.1 Add focused backend feature tests for grouped loading, distinct per-business prices, unrelated/null-lineage exclusion, missing-copy preservation, zero-versus-missing semantics, inactive visibility, and permission enforcement.
- [x] 4.2 Add focused save tests for valid independent and copied price updates, validation failures, forged/duplicate/incomplete identities, stale price and structure conflicts, preserved bundle metadata, atomic rollback across all price sections, and changed-only feed events.
- [x] 4.3 Extend focused price-mask/interface assertions for bundle edit/cancel state, validation restoration, apply-to-all scoping, missing-cell immutability, and stable Rupiah magnitude.
- [x] 4.4 Run only the relevant Product module cross-business price, mask, bundle synchronization, and price-feed test filters; document results for handoff without scheduling the full application suite.
- [x] 4.5 Provide a concise human browser checklist covering matrix layout, horizontal responsiveness, edit/cancel/save, inactive indicators, differing business prices, apply-to-all behavior, and unavailable cells.
