## 1. Database Migration

- [x] 1.1 Create migration to make `pos_checkout_id` nullable in `pos_receipt_print_logs` table
- [x] 1.2 Add `pos_transaction_id` column to `pos_receipt_print_logs` for efficient transaction lookups
- [x] 1.3 Create index on `(pos_transaction_id, print_type)` for fast queries
- [x] 1.4 Run migration and verify schema changes

## 2. Controller Updates

- [x] 2.1 Update `PosTransactionController::receipt()` to log PRINT event when first viewing receipt
- [x] 2.2 Add `receiptReprint()` method to `PosTransactionController` for reprint endpoint
- [x] 2.3 Implement permission check using `pos.receipts.reprint` in receiptReprint method
- [x] 2.4 Add print log loading to receipt views (both controllers)
- [x] 2.5 Calculate print history summary (count + last printer) in both controller methods

## 3. Routes and Middleware

- [x] 3.1 Add route for transaction receipt reprint: `POST /pos/transactions/{transaction}/receipt/reprint`
- [x] 3.2 Protect reprint route with `can:pos.receipts.reprint` middleware
- [x] 3.3 Test route is accessible with proper permission

## 4. Service Layer Updates

- [x] 4.1 Update `PosReceiptService::logPrint()` to handle nullable checkout_id
- [x] 4.2 Add method to `PosReceiptService::getTransactionPrintHistory()` to retrieve and summarize logs
- [x] 4.3 Add print history data to receipt data array in both `getReceiptData()` and `getTransactionReceiptData()`
- [x] 4.4 Ensure eager loading of user relationships in print logs

## 5. View Updates - Receipt Template

- [x] 5.1 Update `receipt.blade.php` to receive print history data
- [x] 5.2 Add print history summary section after business header in receipt template
- [x] 5.3 Format print history display: "Printed X times. Last printed by [name] at [timestamp]"
- [x] 5.4 Ensure print history is visible when printing (not excluded by @media print styles)
- [x] 5.5 Test receipt displays correctly in browser and PDF print

## 6. View Updates - Transaction List

- [x] 6.1 Update `transactions/index.blade.php` table to add reprint button in action column
- [x] 6.2 Conditionally show reprint button only if user has `pos.receipts.reprint` permission
- [x] 6.3 Style reprint button consistently with existing action buttons
- [x] 6.4 Test button visibility with and without permission

## 7. View Updates - Transaction Detail

- [x] 7.1 Update `transactions/show.blade.php` card header to add reprint button
- [x] 7.2 Position reprint button next to existing action buttons (Load, Cancel)
- [x] 7.3 Conditionally show reprint button only if user has `pos.receipts.reprint` permission
- [x] 7.4 Style reprint button to match design system
- [x] 7.5 Test button visibility and click functionality

## 8. Entity Relationship Updates

- [x] 8.1 Update `PosReceiptPrintLog` entity to add `pos_transaction_id` fillable attribute
- [x] 8.2 Add relationship method `transaction()` to PosReceiptPrintLog entity
- [x] 8.3 Ensure relationships are properly defined for eager loading

## 9. JavaScript Functionality (if applicable)

- [x] 9.1 Add click handlers for transaction list reprint buttons
- [x] 9.2 Add click handlers for transaction detail reprint button
- [x] 9.3 Open receipt in new tab/window on reprint button click
- [x] 9.4 Handle loading states and error messages

## 10. Testing

- [x] 10.1 Create feature test for transaction receipt initial view logging
- [x] 10.2 Create feature test for transaction receipt reprint logging
- [x] 10.3 Create feature test for print history display on receipt
- [x] 10.4 Create feature test for reprint button visibility (with/without permission)
- [x] 10.5 Test reprint buttons on transaction list with various statuses
- [x] 10.6 Test reprint buttons on transaction detail with various statuses
- [x] 10.7 Test permission checks on reprint endpoint
- [x] 10.8 Test print logs are correctly associated with transactions

## 11. Documentation and Polish

- [x] 11.1 Add inline comments to explain print logging logic
- [x] 11.2 Update permission matrix if documentation exists
- [x] 11.3 Test end-to-end: create transaction → view → reprint → verify logs
- [x] 11.4 Verify no regression in existing receipt printing functionality
- [x] 11.5 Clean up any debug code or temporary implementations
