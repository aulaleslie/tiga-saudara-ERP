# Purchase Return Tasks

## Theme
- Fix serial number reuse blocking in purchase return settlement receiving
- Implement serial number history tracking feature

## Alignment with existing system (required)
- **Source of truth:** Purchase Return uses Livewire flows under `app/Livewire/PurchaseReturn/*` and controllers in `Modules/PurchasesReturn/Http/Controllers/*`.
- **Serial authority:** serials tracked via `ProductSerialNumber` with `status`, `is_in_return_process`, `purchase_return_id`.
- **Settlement methods:** `PRODUCT_REPAIR`, `BROKEN_STOCK`, `MODIFY_PURCHASE` (defined in `PurchaseReturnDetail`).
- **Permissions:** use existing Gate permissions (`purchaseReturnSettlements.*`).

## Dependencies
- Models: `ProductSerialNumber`, `PurchaseReturn`, `PurchaseReturnDetail`, `PurchaseReturnItemSettlement`.
- Controllers: `PurchasesReturnSettlementController`.
- Livewire: `PurchaseReturnSettlementForm`, `PurchaseOrderSerialNumberLoader`.

## Standard DoD Requirements (applies to ALL tickets)
1) **Tests:** add/adjust PHPUnit tests for touched flows.
2) **Run tests:** `php artisan test --filter=PurchaseReturn` passes.
3) **Run full tests:** `php artisan test` passes (no regressions).

---

## EPIC PR-0: Fix Serial Number Reuse Blocking

Goal: Allow `returned` status serial numbers to be reactivated as replacement during PRODUCT_REPAIR receiving.

### PR0-BE-01 - Update serial uniqueness check in receiveItemSettlement

Scope:
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
  - In `receiveItemSettlement()` method (lines ~297-306).
  - Change uniqueness query to only block `active` serials.
  - Allow `returned` serials to be used as replacement.

Current (blocking):
```php
$existingGlobal = ProductSerialNumber::where('product_id', $productId)
    ->where('serial_number', $replacementSerialNumber)
    ->where('id', '!=', $serial->id)
    ->exists();
```

Updated (allow returned):
```php
$existingActive = ProductSerialNumber::where('product_id', $productId)
    ->where('serial_number', $replacementSerialNumber)
    ->where('id', '!=', $serial->id)
    ->whereIn('status', ['active'])
    ->where('is_in_return_process', false)
    ->exists();
```

Test Scenarios:
- Scan returned serial as replacement → allowed, serial reactivated.
- Scan active serial as replacement → blocked with error.
- Scan in_return_process serial → blocked with error.

DoD:
- Returned serials can be reused as replacement.
- Active serials still blocked.

### PR0-BE-02 - Update reactivation logic for returned serials

Scope:
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
  - In `receiveItemSettlement()` method (lines ~318-344).
  - When replacement serial exists with status `returned`, reactivate it instead of creating new.
  - Preserve original record history by updating existing record.

Test Scenarios:
- Replace with returned serial → existing record updated to `active`.
- Replace with new serial → new record created.

DoD:
- Existing returned serials reactivated correctly.
- History preserved in existing record.

### PR0-TEST-01 - Create test file for serial reuse

Scope:
- Create `tests/Feature/PurchaseReturnSerialReuseTest.php`
- Test cases:
  - `test_can_receive_returned_serial_as_replacement`
  - `test_cannot_receive_active_serial_as_replacement`
  - `test_cannot_receive_in_return_process_serial_as_replacement`
  - `test_returned_serial_reactivated_preserves_record`

DoD:
- All test cases pass.

---

## EPIC PR-1: Serial Number History Database

Goal: Create database structure for tracking serial number lifecycle events.

### PR1-BE-01 - Create serial_number_histories migration

Scope:
- Create migration `database/migrations/xxxx_create_serial_number_histories_table.php`
- Table structure:
  - `id` bigint PK auto-increment
  - `product_serial_number_id` bigint FK → product_serial_numbers
  - `event_type` string(50) 
  - `location_id` bigint FK nullable → locations
  - `reference_type` string(100) nullable (polymorphic)
  - `reference_id` bigint nullable (polymorphic)
  - `user_id` bigint FK → users
  - `note` text nullable
  - `created_at` timestamp
- Add index on `product_serial_number_id`, `created_at`.

Event types enum:
- `RECEIVED` - Initial receipt from purchase
- `SOLD` - Sold to customer (dispatch)
- `SALE_RETURNED` - Returned by customer
- `PURCHASE_RETURNED` - Returned to supplier
- `REPAIR_RECEIVED` - Received back from repair
- `LOCATION_TRANSFER` - Moved between locations
- `MARKED_BROKEN` - Marked as broken stock
- `STATUS_CHANGED` - General status change

Test Scenarios:
- Migration runs successfully.
- Migration rolls back successfully.

DoD:
- Table created with correct schema.

### PR1-BE-02 - Create SerialNumberHistory model

Scope:
- Create `Modules/Product/Entities/SerialNumberHistory.php`
- Relationships:
  - `serialNumber()` → BelongsTo ProductSerialNumber
  - `location()` → BelongsTo Location
  - `user()` → BelongsTo User
  - `reference()` → MorphTo (polymorphic)
- Add `$fillable` and `$casts`.

DoD:
- Model works with relationships.

### PR1-BE-03 - Add histories relationship to ProductSerialNumber

Scope:
- `Modules/Product/Entities/ProductSerialNumber.php`
  - Add `histories()` HasMany relationship.

DoD:
- Can access `$serialNumber->histories`.

---

## EPIC PR-2: Serial Number History Service

Goal: Create service class for recording history events consistently.

### PR2-BE-01 - Create SerialNumberHistoryService

Scope:
- Create `app/Services/SerialNumberHistoryService.php`
- Static method:
```php
public static function record(
    int $serialNumberId,
    string $eventType,
    ?int $locationId = null,
    ?Model $reference = null,
    ?string $note = null
): SerialNumberHistory
```
- Automatically captures `user_id` from `auth()->id()`.
- Handles polymorphic reference.

Test Scenarios:
- Service creates history record correctly.
- Reference polymorphism works.

DoD:
- Service can record any event type.

---

## EPIC PR-3: History Recording Integration

Goal: Record history events at all serial number lifecycle points.

### PR3-BE-01 - Record RECEIVED on purchase receiving

Scope:
- Find serial number creation point during purchase receiving.
- Call `SerialNumberHistoryService::record()` with:
  - `event_type` = `RECEIVED`
  - `reference` = ReceivedNoteDetail or ReceivedNote
  - `location_id` = receiving location

DoD:
- New serials from purchase have RECEIVED event.

### PR3-BE-02 - Record SOLD on dispatch approval

Scope:
- Find dispatch approval point for serial numbers.
- Call `SerialNumberHistoryService::record()` with:
  - `event_type` = `SOLD`
  - `reference` = DispatchDetail or Dispatch
  - `location_id` = dispatch location

DoD:
- Dispatched serials have SOLD event.

### PR3-BE-03 - Record SALE_RETURNED on sale return receiving

Scope:
- Find sale return receiving point for serial numbers.
- Call `SerialNumberHistoryService::record()` with:
  - `event_type` = `SALE_RETURNED`
  - `reference` = SaleReturnDetail or SaleReturn
  - `location_id` = receiving location

DoD:
- Returned serials have SALE_RETURNED event.

### PR3-BE-04 - Record PURCHASE_RETURNED on purchase return approval

Scope:
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
- In `approveItemSettlement()` and/or `applySettlementEffect()`:
  - For MODIFY_PURCHASE and PRODUCT_REPAIR methods.
  - Call `SerialNumberHistoryService::record()` with:
    - `event_type` = `PURCHASE_RETURNED`
    - `reference` = PurchaseReturnItemSettlement
    - `location_id` = return location

DoD:
- Purchase returned serials have PURCHASE_RETURNED event.

### PR3-BE-05 - Record REPAIR_RECEIVED on repair receiving

Scope:
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
- In `receiveItemSettlement()` for PRODUCT_REPAIR:
  - Call `SerialNumberHistoryService::record()` with:
    - `event_type` = `REPAIR_RECEIVED`
    - `reference` = PurchaseReturnItemSettlement
    - `location_id` = receiving location
  - Record for both reactivated and new replacement serials.

DoD:
- Repaired/replacement serials have REPAIR_RECEIVED event.

### PR3-BE-06 - Record MARKED_BROKEN on broken stock receiving

Scope:
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
- In `receiveItemSettlement()` for BROKEN_STOCK:
  - Call `SerialNumberHistoryService::record()` with:
    - `event_type` = `MARKED_BROKEN`
    - `reference` = PurchaseReturnItemSettlement
    - `location_id` = receiving location

DoD:
- Broken serials have MARKED_BROKEN event.

### PR3-BE-07 - Record LOCATION_TRANSFER on transfers (if applicable)

Scope:
- Find location transfer point for serial numbers (if exists).
- Call `SerialNumberHistoryService::record()` with:
  - `event_type` = `LOCATION_TRANSFER`
  - Note includes source and target location.

DoD:
- Transferred serials have LOCATION_TRANSFER event.

---

## EPIC PR-4: Serial Number History UI

Goal: Display serial number history on Product detail page.

### PR4-FE-01 - Create history table Livewire component

Scope:
- Create `app/Livewire/Product/ProductSerialHistoryTable.php`
- Props: `$productId`
- Load all serial numbers for product with their histories.
- Expandable rows showing history timeline.

Columns:
- Nomor Seri (Serial Number)
- Status
- Lokasi (Location)
- Jumlah Event (Event Count) - expandable

Expanded columns:
- Tanggal (Date)
- Jenis Event (Event Type)
- Lokasi (Location)
- Referensi (Reference) - clickable link
- User

DoD:
- Component displays serial histories.

### PR4-FE-02 - Create history table view

Scope:
- Create `resources/views/livewire/product/product-serial-history-table.blade.php`
- Expandable accordion or nested table design.
- Event type labels in Bahasa Indonesia.

Event type labels:
- `RECEIVED` → Diterima dari Pembelian
- `SOLD` → Terjual
- `SALE_RETURNED` → Retur dari Pelanggan
- `PURCHASE_RETURNED` → Retur ke Supplier
- `REPAIR_RECEIVED` → Diterima dari Perbaikan
- `LOCATION_TRANSFER` → Pindah Lokasi
- `MARKED_BROKEN` → Ditandai Rusak
- `STATUS_CHANGED` → Perubahan Status

DoD:
- View matches existing UI patterns.

### PR4-FE-03 - Integrate into Product detail page

Scope:
- Find Product detail view/page.
- Add new tab or section "Riwayat Nomor Seri".
- Include the Livewire component.

DoD:
- History visible on Product page.

---

## Manual Verification Scenarios

### Scenario 1: Serial Reuse Fix

Prerequisites:
- Product with `serial_number_required = true`
- Serial A exists in system

Steps:
1. Create Purchase Return #1 with Serial A
2. Choose settlement "Ubah Nota Pembelian"
3. Approve and complete settlement
4. Verify Serial A status = `returned`
5. Create Purchase Return #2 with Serial B
6. Choose settlement "Perbaikan Produk"
7. Approve the settlement
8. On receiving, input Serial A as replacement
9. **Expected:** Success, Serial A reactivated to `active`

### Scenario 2: History Display

Steps:
1. Go to Product detail page for serialized product
2. Find "Riwayat Nomor Seri" section
3. **Expected:** See list of serial numbers
4. Click/expand a serial number
5. **Expected:** See chronological list of events with dates, types, locations, references
