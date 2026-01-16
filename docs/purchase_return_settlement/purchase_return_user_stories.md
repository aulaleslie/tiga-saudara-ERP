# Purchase Return Settlement — User Stories

## Settlement Entry (Kelola Penyelesaian)
As a returns staff member,
I want to open the settlement page from the purchase return detail,
So that I can configure settlement for that return.

As a returns staff member,
I want to see the list of returned items and serials,
So that I can select settlement for each item or serial.

As a returns staff member,
I want to enter settlement only after supplier agreement,
So that the settlement reflects the confirmed outcome.

## Serial vs Non-serial Scope
As a returns staff member,
I want to assign settlement per serial for serial-tracked products,
So that each serial can have a different outcome if needed.

As a returns staff member,
I want to apply one settlement method to the full quantity of a non-serial item,
So that the item is handled consistently.

## Settlement Methods
As a returns staff member,
I want to choose "Perbaikan Produk" for a serial item,
So that the same product and serial will be received back.

As a returns staff member,
I want to choose "Kembali Barang Rusak" for an item,
So that the item is recorded as broken stock.

As a returns staff member,
I want to choose "Ubah Nota Pembelian" and link it to an unpaid purchase,
So that the return value offsets that purchase.

As a returns staff member,
I want to choose "Simpan Sebagai Kredit" with a nominal amount and target purchase,
So that credit is applied to another approved unpaid purchase.

As a returns staff member,
I want to choose "Pengembalian Tunai" with a payment method and nominal,
So that cash returns are recorded correctly.

## Validation & Eligibility
As the system,
I want to require serial numbers and a matching receive ID for serial items,
So that settlement remains traceable.

As the system,
I want to block returns that exceed received quantity,
So that inventory stays accurate.

As the system,
I want to prevent "Ubah Nota Pembelian" when no unpaid/partially-paid purchase exists,
So that invalid offsets cannot be saved.

As the system,
I want to ensure the return value per item does not exceed the outstanding balance,
So that purchases are not over-credited.

As the system,
I want to block negative or zero settlement values,
So that financial records remain valid.

As the system,
I want to prevent returns that span multiple original purchases for the same SKU,
So that traceability is preserved.

As the system,
I want to block settlement save when selections are invalid,
So that users can correct errors before approval.

## Pricing & Amounts
As a returns staff member,
I want to see the last purchase price per item,
So that I have a baseline for settlement.

As a returns staff member,
I want to input settlement nominal values when needed,
So that the recorded settlement matches the supplier agreement.

As a finance user,
I want global expenses like shipping or taxes excluded from settlement value,
So that settlement reflects only return value.

## Approval & Locking
As a purchasing manager,
I want to approve settlements,
So that they become final and auditable.

As the system,
I want to lock settlements after approval,
So that they cannot be changed.

## Inventory & Financial Effects
As inventory staff,
I want broken returns to update broken stock immediately,
So that stock status is accurate.

As finance staff,
I want "Ubah Nota Pembelian" to update both stock and financial records,
So that accounting stays consistent.

## Permissions
As a settlement creator,
I want to select cash and credit methods,
So that I can complete settlement without additional roles.
