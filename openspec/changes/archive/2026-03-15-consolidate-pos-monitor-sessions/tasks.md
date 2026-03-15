## 1. Backend: Enhance Sessions Query with Monitor Data

- [x] 1.1 Update `PosSessionController::index()` to add monitor metrics to query (withCount relations, threshold checks, cash event timing)
- [x] 1.2 Add relation loading for `terminal.policy` to access cash threshold values
- [x] 1.3 Add withCount relations for transactions and safe drops filtered by event type/direction
- [x] 1.4 Add withMax relation for last cash event timestamp to determine last activity
- [x] 1.5 Calculate and include "Pengambilan Kas Terkini" (total cash picked up) in the query result
- [x] 1.6 Pass monitor metrics data to view and verify query performance

## 2. Backend: Enhance Summary Endpoint with Transaction Details

- [x] 2.1 Update `PosSessionController::summary()` to include transaction list from checkouts
- [x] 2.2 Load and format full checkout/transaction records with amounts, methods, timestamps
- [x] 2.3 Load and format cash event timeline (safe drops, pickups) with all metadata
- [x] 2.4 Implement pagination or limit on transaction list (suggest 50 transactions per response)
- [x] 2.5 Test summary endpoint returns correct data structure with transactions and cash events

## 3. Frontend: Update Sessions Index View

- [x] 3.1 Enhance `session/index.blade.php` to add new columns for active sessions:
  - Expected Cash Total
  - Transaction Count
  - Pengambilan Kas Terkini (Cash Pickup Amount)
  - Last Activity timestamp
- [x] 3.2 Implement conditional column rendering based on `$status` filter
  - Show monitor columns when status=OPEN
  - Show settlement columns (Counted Cash, Variance) when status=CLOSED
- [x] 3.3 Update "Setoran Aman" label to "Pengambilan Kas Terkini" throughout the view
- [x] 3.4 Format currency values for new numeric columns (IDR formatting)
- [x] 3.5 Test view rendering for all filter combinations (all, OPEN, CLOSED)

## 4. Frontend: Enhance Session Summary Details

- [x] 4.1 Update session summary detail view/modal to display transaction list
- [x] 4.2 Update session summary detail view/modal to display cash event timeline
- [x] 4.3 Format and style transaction list (transaction ID, amount, method, timestamp)
- [x] 4.4 Format and style cash event timeline (event type, amount, timestamp, user)
- [x] 4.5 Test summary detail view displays transactions and cash events correctly

## 5. Cleanup: Remove Monitor Routes and Controller Methods

- [x] 5.1 Remove lines 26-29 from `Modules/Pos/Routes/web.php` (monitor routes)
- [x] 5.2 Remove `monitor()` method from `PosSessionController`
- [x] 5.3 Remove `monitorApi()` method from `PosSessionController`
- [x] 5.4 Remove `monitor/index.blade.php` view file

## 6. Cleanup: Update Menu Navigation

- [x] 6.1 Remove monitor menu item from `resources/views/layouts/menu.blade.php`
- [x] 6.2 Verify sessions menu item still points to `/pos/sessions`
- [x] 6.3 Test menu renders correctly without monitor link

## 7. Testing and Verification

- [x] 7.1 Manual test: Access `/pos/sessions` displays all sessions correctly
- [x] 7.2 Manual test: Filter `/pos/sessions?status=OPEN` shows active sessions with monitor columns
- [x] 7.3 Manual test: Filter `/pos/sessions?status=CLOSED` shows closed sessions with settlement columns
- [x] 7.4 Manual test: Click "Detail Ringkasan" opens summary with transaction list and cash events
- [x] 7.5 Manual test: Verify `/pos/monitor` returns 404 Not Found
- [x] 7.6 Manual test: Verify menu no longer shows monitor link
- [x] 7.7 Verify database queries are optimized (check query count, no N+1 issues)
- [x] 7.8 Test with users having `pos.sessions.view` permission (no longer need `pos.monitor.access`)
