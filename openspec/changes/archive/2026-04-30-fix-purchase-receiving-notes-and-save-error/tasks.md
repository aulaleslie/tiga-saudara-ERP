## 1. Database and Model Updates

- [x] 1.1 Create a migration to add a nullable `note` column to the `received_note_details` table.
- [x] 1.2 Run the migration.
- [x] 1.3 Add `note` to the `$fillable` array in `Modules/Purchase/Entities/ReceivedNoteDetail.php`.

## 2. Backend Logic

- [x] 2.1 Update `storeReceive` in `Modules/Purchase/Http/Controllers/PurchaseController.php` to extract `notes` from the request and save them into the `ReceivedNoteDetail` records.

## 3. UI/UX Improvements

- [x] 3.1 Modify `Modules/Purchase/Resources/views/receive.blade.php` to include client-side validation that prevents submission if no quantities or serials are entered.
- [x] 3.2 Update `Modules/Purchase/Resources/views/receivings/receiving-details.blade.php` to display the "Catatan" column in the product list.

## 4. Verification

- [x] 4.1 Verify that notes entered in the receiving form are correctly saved to the database.
- [x] 4.2 Verify that saved notes are displayed in the Purchase Details expansion.
- [x] 4.3 Verify that hitting save with empty quantities/serials triggers a client-side alert and prevents submission.
- [x] 4.4 Run existing purchase receiving tests to ensure no regressions.
