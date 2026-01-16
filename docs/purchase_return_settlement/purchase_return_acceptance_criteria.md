# Purchase Return Settlement — Acceptance Criteria

## Ticket 1: Settlement Entry Page (Kelola Penyelesaian)
Happy path
Given a purchase return with items and serials exists
When the user opens the settlement page from the return detail
Then the page shows all items/serials and allows settlement selection

Edge case
Given a purchase return has no items
When the user opens the settlement page
Then the page shows an empty state and disables saving

Failure scenario
Given the return ID is invalid
When the user opens the settlement page
Then the system shows an error and does not load settlement controls

## Ticket 2: Serial vs Non-serial Settlement Rules
Happy path
Given a return contains serial-tracked items and non-serial items
When the user selects settlement methods per serial and per non-serial item
Then the system accepts per-serial choices and a single method for each non-serial item

Edge case
Given a non-serial item has quantity greater than 1
When the user attempts to split settlement methods by quantity
Then the system rejects the split and requires one method for all quantity

Failure scenario
Given a serial-tracked item has missing serial entries
When the user saves the settlement
Then the system blocks save and indicates missing serials

## Ticket 3: Settlement Method — Perbaikan Produk
Happy path
Given a serial-tracked item with a valid serial and receive ID
When the user selects "Perbaikan Produk"
Then the system records the same product and serial as the expected return

Edge case
Given a non-serial item
When the user selects "Perbaikan Produk"
Then the system accepts the method for the full quantity

Failure scenario
Given a serial-tracked item with a serial that does not match its receive ID
When the user selects "Perbaikan Produk"
Then the system blocks save and reports the mismatch

## Ticket 4: Settlement Method — Kembali Barang Rusak
Happy path
Given a settlement with "Kembali Barang Rusak" is approved
When the approval is processed
Then broken stock is increased for the selected items

Edge case
Given multiple items are marked as broken in one settlement
When the settlement is approved
Then broken stock updates apply for each item correctly

Failure scenario
Given the inventory service is unavailable
When the settlement approval tries to update broken stock
Then the approval fails and no partial stock update is applied

## Ticket 5: Settlement Method — Ubah Nota Pembelian
Happy path
Given an unpaid approved purchase exists with sufficient outstanding balance
When the user selects "Ubah Nota Pembelian" and links the purchase
Then the system allows save and prepares stock and financial updates on approval

Edge case
Given a serial-tracked item with a valid purchase ID, receive ID, and serial
When the user selects "Ubah Nota Pembelian"
Then the system validates the serial against the selected purchase and receive

Failure scenario
Given the outstanding balance is less than the return value per item
When the user saves the settlement
Then the system blocks save and shows an insufficient balance error

## Ticket 6: Settlement Method — Simpan Sebagai Kredit
Happy path
Given an approved unpaid purchase exists
When the user selects "Simpan Sebagai Kredit" and enters a nominal amount
Then the system records the credit for the selected purchase

Edge case
Given the nominal amount equals the outstanding balance
When the user saves the settlement
Then the system accepts the credit and marks the purchase as fully paid on approval

Failure scenario
Given the nominal amount exceeds the outstanding balance
When the user saves the settlement
Then the system blocks save and shows an over-credit error

## Ticket 7: Settlement Method — Pengembalian Tunai
Happy path
Given payment methods are configured
When the user selects "Pengembalian Tunai" and enters a nominal amount
Then the system records the cash return method and amount

Edge case
Given multiple cash payment methods exist
When the user selects one method
Then the selected method is saved with the settlement

Failure scenario
Given the user enters a zero or negative nominal amount
When the user saves the settlement
Then the system blocks save and shows a validation error

## Ticket 8: Validation Rules & Blocking Save
Happy path
Given all selected settlement methods meet validation rules
When the user saves the settlement
Then the system accepts the save

Edge case
Given the return quantity equals the received quantity
When the user saves the settlement
Then the system accepts the save

Failure scenario
Given the return quantity exceeds the received quantity
When the user saves the settlement
Then the system blocks save and shows a quantity error

## Ticket 9: Pricing & Amounts
Happy path
Given an item has purchase history
When the user opens the settlement page
Then the last purchase price is displayed for that item

Edge case
Given an item has no purchase history
When the user opens the settlement page
Then the price field is empty and requires manual nominal input

Failure scenario
Given the user enters a negative nominal value
When the user saves the settlement
Then the system blocks save with a validation error

## Ticket 10: Approval & Locking
Happy path
Given a settlement is pending approval
When a manager approves it
Then the settlement status changes to approved and becomes read-only

Edge case
Given two managers attempt to approve at the same time
When the first approval completes
Then the second approval is rejected with a status conflict

Failure scenario
Given a settlement is already approved
When a user attempts to edit it
Then the system blocks the edit and shows a locked message

## Ticket 11: Inventory & Financial Effects on Approval
Happy path
Given a settlement includes "Kembali Barang Rusak" and "Ubah Nota Pembelian"
When the settlement is approved
Then broken stock updates and purchase adjustments are applied in one transaction

Edge case
Given a settlement includes only "Pengembalian Tunai"
When the settlement is approved
Then no stock adjustment is triggered

Failure scenario
Given the financial update fails during approval
When the settlement is approved
Then the system rolls back all changes and reports the failure

## Ticket 12: Permissions for Settlement Creation
Happy path
Given a user has permission to create settlements
When the user opens the settlement page
Then all settlement methods are available for selection

Edge case
Given a user loses settlement permission after opening the page
When the user attempts to save
Then the system rejects the save with an authorization error

Failure scenario
Given a user without create permission attempts to open the settlement page
When the page loads
Then the system blocks access and shows a permission error
