## 1. Data Preparation

- [x] 1.1 Update `PosReceiptService::getReceiptData` to load `transaction.lines.conversion.unit` relations to access unit breakdown data.
- [x] 1.2 Modify item line builder in `PosReceiptService` to use `PosTransactionLine` snapshots for item quantity, price, and conversion.
- [x] 1.3 Implement unit breakdown logic: construct a string like "1 BOX(ES) @ 270.000" using the conversion name and unit price.
- [x] 1.4 Fix payment nominal mapping: update the multi-payment loop to use the `$payment->amount` accessor instead of an invalid `$payment->amount_paid` property.
- [x] 1.5 Add business email (`company_email`) to the `receiptData` array for header display.

## 2. Receipt View Redesign

- [x] 2.1 Center align the business header in `receipt.blade.php`, including the company name, address, phone, and new email field.
- [x] 2.2 Reformat the receipt date display to match the "01 Dec, 2025 22:38" format using Carbon.
- [x] 2.3 Redesign the item table with dashed line dividers and the header: `Qty  Nama Barang      Total`.
- [x] 2.4 Implement the indented unit conversion breakdown below the item name on each line.
- [x] 2.5 Right-align and format the totals section, ensuring each payment method used is listed with its correct amount.
- [x] 2.6 Add hardcoded footer message "Harga sudah termasuk PPN" as per the reference image.

## 3. Verification

- [x] 3.1 Run the existing `Modules/Pos/Tests/Feature/POSReceiptGenerationTest.php` to ensure no regressions in receipt number generation and logging.
- [x] 3.2 Add a new verification test to `POSReceiptGenerationTest.php` that asserts non-zero payment nominals and the presence of unit breakdown text in the rendered view.
- [x] 3.3 Perform manual verification at `http://localhost:8000/pos/sell/checkout/{id}/receipt` to confirm pixel-perfect alignment with the reference image.
