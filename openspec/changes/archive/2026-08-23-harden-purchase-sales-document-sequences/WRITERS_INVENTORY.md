# Inventory of Purchase and Sale Reference Writers

This document records every production writer that creates, allocates, or reallocates internal `purchases.reference` and `sales.reference`, along with its current legacy behavior and intended shared-allocator integration.

---

## 1. Purchase Document Writers

### 1.1 `app/Livewire/Purchase/CreateForm.php` (`submit()`)
- **Current Behavior**: Calls `DocumentReferenceService::createPurchaseWithReference($data)` inside a `DB::transaction()`.
- **Legacy Issue**: `DocumentReferenceService` queries `latest('id')` within date-month scope and increments suffix.
- **Intended Integration**: Delegate to `DocumentSequenceAllocator::allocatePurchase($settingId, $purchaseDate, ...)` within the transaction, assigning the authoritative allocated reference to the Purchase record.

### 1.2 `Modules/Purchase/Http/Controllers/PurchaseController.php` (`store()`)
- **Current Behavior**: Calls raw `Purchase::create([...])` without explicit reference, relying on `Purchase::boot()` `creating` hook fallback.
- **Legacy Issue**: Model creating hook queries `latest('id')` under `Setting` row lock, vulnerable to out-of-order IDs and date-scoped mismatch.
- **Intended Integration**: Call shared allocator directly or route through normal creation service that allocates atomically with transaction protection.

### 1.3 `Modules/Purchase/Services/PurchaseImportService.php` (`processGroup()`)
- **Current Behavior**: Calls `protected function generateReference(Setting $setting, Carbon $date)` which queries `latest('id')` by `date` month, then sets `$purchase->reference = $reference` and `$purchase->save()`. Note: `supplier_purchase_number` stores `$data['no_faktur']`.
- **Legacy Issue**: Unsafe `latest('id')` query without transactional counter protection; potential collision under concurrency or out-of-order IDs.
- **Intended Integration**: Use shared sequence allocator inside the import transaction for the target setting and reference period. Supplier invoice numbers (`no_faktur`) remain strictly in `supplier_purchase_number`.

### 1.4 `app/Livewire/Purchase/CreateForm.php` (`prefillFromPurchase()` - Duplication Mode)
- **Current Behavior**: Resets reference to `null`, date to today, then during `submit()` routes through `DocumentReferenceService::createPurchaseWithReference()`.
- **Legacy Issue**: Inherits legacy allocator behavior from `DocumentReferenceService`.
- **Intended Integration**: Will route through shared allocator atomically in `submit()`.

### 1.5 `app/Livewire/Purchase/EditForm.php` (`update()` - Cross-Business Draft Move)
- **Current Behavior**: Calls `DocumentReferenceService::movePurchaseToSetting($purchase, $targetSettingId, $purchaseDate)`.
- **Legacy Issue**: `movePurchaseToSetting` uses `latest('id')` against target setting.
- **Intended Integration**: Atomically allocate from target setting's sequence namespace via shared allocator, updating `setting_id` and `reference` while keeping source sequence history unmutated. Non-draft moves remain blocked.

### 1.6 `Modules/Purchase/Entities/Purchase.php` (`Purchase::boot()` `creating` hook & `generateReference()`)
- **Current Behavior**: Model hook uses `Purchase::withArchived()->where(...)->latest('id')->value('reference')` when `reference` is not supplied.
- **Legacy Issue**: Unsafe fallback numbering algorithm based on row-ID ordering.
- **Intended Integration**: Remove independent `latest('id')` numbering. When reference is not supplied, delegate safely to the authoritative allocator or fail explicitly if outside a managed transaction.

---

## 2. Sale Document Writers

### 2.1 `Modules/Sale/Services/SaleService.php` (`createSale()`)
- **Current Behavior**: Calls `DocumentReferenceService::createSaleWithReference($data)` within a `DB::transaction()`.
- **Legacy Issue**: `DocumentReferenceService` queries `latest('id')` within date-month scope.
- **Intended Integration**: Use `DocumentSequenceAllocator::allocateSale($settingId, $saleDate, ...)` atomically inside the transaction.

### 2.2 `Modules/Sale/Http/Controllers/SaleController.php` (`store()`)
- **Current Behavior**: Calls `SaleService::createSale()`.
- **Legacy Issue**: Inherits legacy allocator behavior.
- **Intended Integration**: Uses shared allocator via `SaleService`.

### 2.3 `Modules/Sale/Services/SalesImportService.php` (`processGroup()`)
- **Current Behavior**: Instantiates `new Sale()`, sets `$sale->date`, etc., but leaves `$sale->reference` unset before `$sale->save()`, which triggers the `Sale::boot()` `creating` hook.
- **Legacy Issue**: Relies on model hook `latest('id')` fallback.
- **Intended Integration**: Explicitly allocate reference using shared allocator within the import transaction. Customer invoice numbers remain in `imported_sales_reference_number`.

### 2.4 `app/Services/DocumentReferenceService.php` (`moveSaleToSetting()`)
- **Current Behavior**: Moves draft Sale to a new setting and queries `latest('id')` for new reference.
- **Legacy Issue**: Uses `latest('id')` and date scope.
- **Intended Integration**: Atomically allocate from target setting namespace using shared allocator.

### 2.5 `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php` (`post()`)
- **Current Behavior**: Calls `Sale::query()->create([...])` with `$saleSettingId` (which may be resolved via non-stock source resolver) without providing `reference`, triggering the `Sale::boot()` `creating` hook.
- **Legacy Issue**: Relies on `Sale::boot()` `creating` hook fallback under Setting lock.
- **Intended Integration**: Supply an explicitly allocated reference from the shared allocator for the resolved effective owner setting.

### 2.6 `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php` (`post()`)
- **Current Behavior**: Iterates through planned split groups and calls `InlinePosCheckoutPostingAdapter::post($groupContext)` per group.
- **Legacy Issue**: Groups may acquire setting locks in arbitrary cart-dependent order, creating deadlock risk under concurrency. Also relies on model creating hook.
- **Intended Integration**: Pre-resolve all distinct owner namespaces required for the checkout, acquire locks in canonical sorted order (by document type, setting ID, prefix, year, month), allocate all group references, and post inside the single checkout transaction.

### 2.7 `Modules/Sale/Entities/Sale.php` (`Sale::boot()` `creating` hook & `generateReference()`)
- **Current Behavior**: Model hook queries `Sale::withArchived()->where(...)->latest('id')->value('reference')`.
- **Legacy Issue**: Unsafe fallback numbering algorithm based on row-ID ordering.
- **Intended Integration**: Remove independent `latest('id')` numbering. Delegate safely to authoritative allocator or fail closed when unallocated.

---

## 3. Scope Boundaries and Out-of-Scope Writers

The following document families have their own generators and are **deliberately out-of-scope** for this change:
- `PurchaseReturn` (`Modules/PurchasesReturn/Entities/PurchaseReturn.php`)
- `SaleReturn` (`Modules/SalesReturn/...`)
- `Quotation` (`Modules/Quotation/Entities/Quotation.php`)
- `Adjustment` (`Modules/Adjustment/Entities/Adjustment.php`)
- `Consignment` (`Modules/Consignment/...`)
- `Expense` (`Modules/Expense/...`)
- `Dispatch` (`Modules/Sale/Entities/Dispatch.php`)
- `ReceivedNote` (`Modules/Purchase/Entities/ReceivedNote.php`)
