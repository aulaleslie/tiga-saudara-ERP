# Phase 4 - Status Rendering + Settlement Method Simplification TODO

## Status
- Status: Draft (needs clarifications before implementation).
- Goal: prepare an execution-ready, agent-friendly plan for the three UI/logic changes requested.

## Requested Changes (Locked)
- `/purchase-returns/{id}/settlement`: remove selectable options `Simpan Sebagai Kredit` and `Pengembalian Tunai`.
- `/purchase-returns/{id}` detail page: show only one document status badge, and remove visible `approved` status badge in detail header.
- `/purchase-returns` list page: improve status pill contrast (dark background => bright text).
- `IN_RETURN` translation: change user-facing label to `Sedang Dalam Retur, Menunggu Input Penyelesaian`.

## Current Findings (Code + Runtime)
- Route mapping:
- `Modules/PurchasesReturn/Routes/web.php` serves all three URLs via `PurchasesReturnController`.
- Settlement page is Livewire-driven:
- `Modules/PurchasesReturn/Resources/views/settlement.blade.php` renders `purchase-return-settlement-form`.
- Settlement method options still include credit and cash:
- `Modules/PurchasesReturn/Entities/PurchaseReturnDetail.php` in `settlementMethods()` and `selectableSettlementMethods()`.
- `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php` renders options from `getMethodsForLine()`.
- Detail page currently renders two status badges:
- `Modules/PurchasesReturn/Resources/views/show.blade.php` shows both `$purchase_return->status` and `$purchase_return->approval_status`.
- List page status pill source:
- `Modules/PurchasesReturn/DataTables/PurchaseReturnsDataTable.php` uses `Modules/PurchasesReturn/Resources/views/partials/status.blade.php`.
- Current label for `IN_RETURN`:
- `Modules/PurchasesReturn/Entities/PurchaseReturn.php` maps it to `Sedang Diretur`.
- Runtime verification via `php artisan tinker` for `purchase_returns.id = 1`:
- `status_col=IN_RETURN`
- `approval_status=APPROVED`
- `dispatch_status=DISPATCHED`
- `unified_status=IN_RETURN`
- `unified_label=Sedang Diretur`
- `settlement_items=0`

## Execution Mode (Agent Friendly)
- Work top-down by ticket order.
- For each ticket: add/adjust tests first, make them fail, implement, then pass.
- Keep scope limited to status rendering + settlement option removal + label translation.
- Preserve backward compatibility for historical `CREDIT` and `CASH` records unless clarified otherwise.

## Standard Definition of Done (Applies to All Tickets)
- All affected tests pass.
- No workflow regression on approval, dispatch, settlement approval/receive.
- No hidden server-side acceptance of removed methods.
- UI texts and badge contrast are consistent across list/detail/settlement views.

---

## EPIC PR4-A - Settlement Method Options Cleanup

### PR4-A1 - Remove `CREDIT` and `CASH` from selectable settlement methods
Goal:
- Users can no longer select `Simpan Sebagai Kredit` and `Pengembalian Tunai` on settlement form.

Impacted paths:
- `Modules/PurchasesReturn/Entities/PurchaseReturnDetail.php`
- `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`
- `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php`

Test plan:
- Update/create feature test asserting dropdown only contains:
- `Perbaikan Produk`
- `Kembali Barang Rusak`
- `Ubah Nota Pembelian`
- Assert Livewire validation rejects `CREDIT` and `CASH` for editable lines.

Implementation notes:
- Keep legacy labels in `settlementMethods()` for read-only historical display.
- Remove `CREDIT` and `CASH` only from `selectableSettlementMethods()`.

Definition of Done:
- New settlements cannot be submitted with `CREDIT`/`CASH`.
- Existing old rows still render readable labels.

### PR4-A2 - Remove dead branches in settlement form logic for selectable methods
Goal:
- Prevent stale UI branches from referencing removed methods as active input paths.

Impacted paths:
- `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`
- `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php`

Test plan:
- Add test for `showDropdown` behavior with only `MODIFY_PURCHASE`.
- Add test for nominal field visibility rules after method removal.

Implementation notes:
- Clean `CREDIT`/`CASH` logic only where it affects new-entry form behavior.
- Keep display-only compatibility in read-only sections.

Definition of Done:
- No selectable flow references removed methods.
- Legacy data remains viewable.

---

## EPIC PR4-B - Unified Single Status in Detail View

### PR4-B1 - Render one status badge in `/purchase-returns/{id}` header
Goal:
- Detail view shows only unified document status, not separate `approval_status`.

Impacted paths:
- `Modules/PurchasesReturn/Resources/views/show.blade.php`

Test plan:
- Add feature test asserting detail page does not display `APPROVED` status badge.
- Assert one unified badge text is shown (from `unified_status_label`).

Implementation notes:
- Replace raw `$purchase_return->status` badge with `$purchase_return->unified_status_label`.
- Remove approval badge from header.

Definition of Done:
- Header has one status badge only.
- Badge text is user-facing label, not raw enum/constant.

### PR4-B2 - Update `IN_RETURN` translation text
Goal:
- `IN_RETURN` label becomes `Sedang Dalam Retur, Menunggu Input Penyelesaian`.

Impacted paths:
- `Modules/PurchasesReturn/Entities/PurchaseReturn.php`
- Any view using `$purchase_return->unified_status_label` (list/detail/print).

Test plan:
- Unit test for `PurchaseReturn::unifiedStatusLabels()[STATUS_IN_RETURN]`.
- Feature assertion on list/detail pages for new label.

Definition of Done:
- All pages using unified label show updated text consistently.

### PR4-B3 - Decide whether to hide approval timeline row on detail page
Goal:
- Resolve ambiguity: remove only approval badge, or remove all approval-status visuals.

Impacted paths:
- `Modules/PurchasesReturn/Resources/views/show.blade.php`

Test plan:
- Add/adjust snapshot/assertion according to final decision.

Definition of Done:
- Detail page status information follows clarified requirement exactly.

---

## EPIC PR4-C - Contrast-Safe Status Pills on List Page

### PR4-C1 - Improve `/purchase-returns` status badge contrast
Goal:
- Ensure readable foreground/background combinations on status pills.

Impacted paths:
- `Modules/PurchasesReturn/Resources/views/partials/status.blade.php`
- Optional shared styling file if custom classes are introduced.

Test plan:
- Feature/view test asserts each status outputs a known contrast-safe class pair.
- Manual visual check for all statuses:
- `DRAFT`
- `PENDING_APPROVAL`
- `REJECTED`
- `AWAITING_DISPATCH`
- `DISPATCH_PENDING_APPROVAL`
- `IN_RETURN`
- `PARTIAL_SETTLEMENT`
- `COMPLETED`

Implementation notes:
- Prefer explicit class map per status.
- Avoid ambiguous combinations with low readability.

Definition of Done:
- Every status pill uses a contrast-safe color pair.
- No status pill renders unreadable text.

### PR4-C2 - Optional alignment for other status partials
Goal:
- Keep visual consistency for other purchase-return status pills if desired.

Candidate paths:
- `Modules/PurchasesReturn/Resources/views/partials/settlement-status.blade.php`
- `Modules/PurchasesReturn/Resources/views/partials/item-settlement-status.blade.php`

Definition of Done:
- Decision documented: either intentionally scoped to list only, or aligned globally.

---

## Data Verification Checklist (Use Tinker During Implementation)
- Verify target document state:
- `php artisan tinker --execute='use Modules\\PurchasesReturn\\Entities\\PurchaseReturn; $pr=PurchaseReturn::find(1); dump($pr?->status, $pr?->approval_status, $pr?->unified_status, $pr?->unified_status_label);'`
- Verify method usage in existing settlement data before hard cleanup:
- `php artisan tinker --execute='use Illuminate\\Support\\Facades\\DB; dump(DB::table(\"purchase_return_item_settlements\")->select(\"method\", DB::raw(\"count(*) as total\"))->groupBy(\"method\")->get());'`

---

## Clarification Questions (Need Answers Before Implementation)
1. Should `CREDIT` and `CASH` be blocked only for new submissions, or also hidden/removed from historical detail tables?
2. On detail page `/purchase-returns/{id}`, do you want to remove only the top `approval_status` badge, or also remove approval-related timeline text like `Disetujui: ...`?
3. Should the single status badge in detail use `unified_status_label` everywhere, even if backend `status` column still stores legacy values?
4. Is the exact final label for `IN_RETURN` this string: `Sedang Dalam Retur, Menunggu Input Penyelesaian`?
5. On `/purchase-returns` list, should contrast improvement apply only to the main status pill, or also to settlement/item status pills in detail-related partials?
6. Do you want a fixed color palette per status (deterministic), or dynamic auto-contrast utility classes?
7. Should settlement page guard still depend on `approval_status === approved`, or should it move to unified-state checks only?
8. Do you want us to update existing tests that currently expect `CASH`/`CREDIT` availability, or keep legacy tests and add new scoped tests?

---

## Proposed Commit Slices (After Clarification)
1. `test(purchase-return): lock selectable settlement methods to repair/broken/modify`
2. `feat(purchase-return): detail page uses single unified status badge + IN_RETURN label update`
3. `style(purchase-return): contrast-safe status pills for purchase return list`
