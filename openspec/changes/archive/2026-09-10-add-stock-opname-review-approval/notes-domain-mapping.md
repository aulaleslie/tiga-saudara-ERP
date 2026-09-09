# Task 1.1 — Domain Mapping Notes

Investigation output backing the schema/service design for the remaining tasks. Source: read-only exploration of the current codebase (2026-09-10).

## 1. Adjustment model / migrations

- `Modules/Adjustment/Entities/Adjustment.php`: `$guarded = []`, `$casts = ['count_draft' => 'array']`.
- No status enum/class exists — bare strings compared via `strtolower(trim(...))`.
- `isVersionedCountDraft()`: `!empty($this->count_draft) && isset($this->count_draft['schema_version'])` — today's redesigned-vs-legacy switch.
- `boot()` auto-generates `reference` (`ADJ-{year}-{month}-{n}`).
- Relations: `adjustedProducts()`, `location()`.

Migrations:
- `2021_07_22_003941_create_adjustments_table.php`: `id, date, reference, note, timestamps`.
- `2024_08_27_163344_add_type_and_status_to_adjustments_table.php`: `type` (`normal|breakage`, default `normal`), `status` (`pending|approved|rejected`, default `pending`).
- `2024_08_27_184310_add_location_id_to_adjustments_table.php`: `location_id`.
- `2026_09_07_140700_add_count_draft_to_adjustments_table.php`: nullable `count_draft` json.
- Missing (needed for 1.2): `submitted_by/at`, `approved_by/at`, `rejected_by/at`, `rejection_reason`, `approval_result`.

## 2. Controller / routes

`Modules/Adjustment/Http/Controllers/AdjustmentController.php`:
- `store()` (~L75), `update()` (~L406): branch on `count_draft` vs `product_ids` presence.
- `edit()`/`update()` guarded by `CountDraftService::canEditAdjustment()` — only `status === 'pending' && type === 'normal'`.
- `show()` (~L325): does **not** branch on `isVersionedCountDraft()` yet — gap for task 5.x.
- `approve()` (~L660): blocks versioned drafts outright — the block task 3.4 replaces. Otherwise dispatches `approveNormal()`/`approveBreakage()` (legacy, operates on `AdjustedProduct` rows).
- `reject()` (~L1040): sets `status='rejected'`, no reason captured (no column).
- `destroy()` (~L649): unconditional delete, no status guard — gap for 3.2.

`Modules/Adjustment/Services/CountDraftService.php` owns redesigned-format validation/persistence — natural home for new lifecycle methods (submit/approve/reject). Also owns baseline logic via `AdjustmentProductResolver` (`captureBaseline`, `getBaselineSnapshot`, `verifyBaselineSignature`).

## 3. ProductStock

`Modules/Product/Entities/ProductStock.php`, table `product_stocks`. One row per (product_id, location_id), 4 buckets:
- `quantity_tax`, `quantity_non_tax` (good/available)
- `broken_quantity_tax`, `broken_quantity_non_tax` (bad/broken)
- plus rollups `quantity`, `broken_quantity`, and `tax_id` (legacy/unused-looking).

Confirmed by existing read/write in `approveNormal()`/`approveBreakage()`.

## 4. Product aggregate quantity

`Product::product_quantity` (decimal:3). No observer — manually kept in sync. Pattern from `approveNormal()` (~L795-807): compute `$quantityDiff` at location, apply to `product_quantity` with `max(0, ...)`, save only if changed, then call `StockNotificationService::checkGlobalStock()`. Replicate this "net diff, before/after, notify" pattern for task 4.2.

## 5. ProductSerialNumber

`Modules/Product/Entities/ProductSerialNumber.php`, table `product_serial_numbers`.
- `serial_number` normalized uppercase.
- `tax_id` nullable FK — presence/absence **is** the tax bucket (no boolean flag).
- `is_broken` boolean — the only "condition" representation (no separate enum).
- `status` string, constants: `STATUS_ACTIVE`, `STATUS_SOLD`, `STATUS_RETURN_IN_PROCESS`, `STATUS_RETURNED`, `STATUS_BROKEN`.
- "Unsafe to move/delete" signals: non-null `dispatch_detail_id`; `is_in_return_process` true; `purchase_return_id`/`purchaseReturn()`; `status` in `SOLD`/`RETURN_IN_PROCESS`; Consignment module's `consignmentActiveClaim()` / `consignmentSerializedAllocations()`.
- `histories()` (SerialNumberHistory) for provenance (`resolveCurrentPurchaseId()`, `resolveCurrentConsignmentReceivalId()`).
- `CountDraftService::validateAndStructureDraft()` (~L204-250) already re-resolves serial identity by `product_id + normalized serial_number` from DB, ignoring client-supplied source/location/tax — reuse this pattern for reconciliation (2.x/4.x).

## 6. Inventory transactions

`Modules/Product/Entities/Transaction.php`. Fields: `product_id, setting_id, type, quantity, current_quantity, broken_quantity, location_id, user_id, reason, previous_quantity, previous_quantity_at_location, after_quantity, after_quantity_at_location, quantity_tax, quantity_non_tax, broken_quantity_tax, broken_quantity_non_tax`, optional `received_note_detail_id`, `consignment_receiving_detail_id`.

Existing example: `approveNormal()` (~L812-837), `type => 'ADJ'`. Reuse `type='ADJ'` unless a distinguishing sub-type is preferred (check for a documented convention before inventing one).

Pair every stock mutation with `StockNotificationService::checkLocationStock()` and `::checkGlobalStock()`.

## 7. Notifications

`app/Services/Notification/DocumentNotificationService.php`:
- Config map keyed by `Model::class` (or `Class:workflow`). Adjustment already registered: `approval_permission => 'adjustments.approval'`, `edit_permission => 'adjustments.edit'`, `title_prefix => 'Penyesuaian Stok'`, `route_prefix => 'adjustments'`.
- `notifyApprovalNeeded()` writes one `Notification` per recipient with idempotency `fingerprint`.
- `resolveApproval()` / `notifyRevisionNeeded()` / `resolveRevision()` — category `approval`/`revision`.
- **Bug for 1.4**: `CountDraftService::saveDraft()` (~L662-669) calls `notifyApprovalNeeded()` on every save (create AND update) — must move to a dedicated submit action.
- Idempotency is via `fingerprint`, not call suppression — the new submit action itself must guard against repeat transitions at the DB status level (task 3.1).

## 8. Active-setting / ownership guards

**No shared trait/middleware exists anywhere in the codebase.** Pattern is inline per-controller:
- `AdjustmentController::create()` (~L48-53): `Location::where('setting_id', session('setting_id'))->where('is_consignment', false)`.
- Repeated inline validation closure (4x across store/update/storeBreakage/updateBreakage) checking `$location->is_consignment` with Bahasa Indonesia fail message.
- Cross-document precedent: `PurchaseController.php` (~L833-838) checks `$loc->setting_id !== $purchase->setting_id || $loc->is_consignment`.
- **`Adjustment` has no `setting_id` column** — ownership must derive via `$adjustment->location->setting_id === session('setting_id')`, matching `CountDraftService::validateAndStructureDraft()`'s existing approach.
- Task 1.3 will be the **first shared** ownership-guard implementation for Adjustment — plan as a new addition, not a refactor of existing shared code.

## 9a. Known pre-existing gap surfaced during 1.2-1.4 (not fixed, out of scope)

`Modules/Product/Services/SerialConversionEligibilityService.php` (~L286-310) blocks
serial-conversion eligibility on "active adjustments" via
`whereHas('adjustedProducts', ...)`, which only matches legacy `AdjustedProduct` child
rows. Versioned Stock Opname documents store their product list in the `count_draft`
JSON column instead, so this blocker never catches an active redesigned
draft/waiting_approval/rejected document at all, regardless of status value. Fixed the
status-value list and enum cast handling as part of 1.2-1.4 fallout, but the
`whereHas('adjustedProducts', ...)` scope itself still needs updating (likely to also
check `count_draft->rows[].product_id`) — flag for whoever implements reconciliation
(task 2.x) or approval (task 4.x), since that's where the domain knowledge of
`count_draft` row shape already lives.

## 9. Existing tests

Convention: **PHPUnit** (`Tests\TestCase`, `RefreshDatabase`), not Pest.
- `tests/Feature/Adjustment/StockOpnameRedesignTest.php` (2183 lines) — primary existing suite for the redesigned workflow. Builds `Currency`, `Setting` (explicit `is_pkp`), `Location`, `Unit`, `Category` via direct `::create()` (no factories). Uses `Livewire::test(AdjustmentProductTable::class)` plus direct `CountDraftService`/`AdjustmentProductResolver` calls. **Read in full before writing task 6.x tests** — establishes the fixture idiom to match.
- `Modules/Adjustment/Tests/Feature/ApproveBreakageTest.php` — legacy breakage approval precedent (locking, stock assertions, Transaction assertions).
- `Modules/Adjustment/Tests/Feature/Transfer*.php` + `Modules/Adjustment/Tests/Unit/Transfer*.php` — unrelated Stock Transfer feature, but closest architectural analogue for a `StockOpnameLifecycleService`/`StockOpnameReconciliationService` unit-test structure (e.g. `TransferLifecycleServiceTest`, `TransferAllocationPreviewServiceTest`).
- No `Adjustment` factory found — tests build via direct `::create()`/service calls.
