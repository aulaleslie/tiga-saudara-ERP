## Why

1. **Bug: Product Row Notes Not Stored**: Currently, the "Penerimaan Pembelian" (Purchase Receiving) form allows users to enter optional notes for each product row. However, these notes are not saved to the database because the underlying table (`received_note_details`) lacks the necessary column, and the controller logic does not handle the input.
2. **Bug: Intermittent Save Error**: Users report an intermittent error when hitting the save button without performing any action after landing on the page. This is likely due to a combination of validation behavior (button disabling itself) and potential CSRF timeouts or missing location selection.

## What Changes

1. **Data Persistence**: Add a `note` column to the `received_note_details` table and update the model and controller to store notes per product row during the receiving process.
2. **UI/UX Enhancement**: Display the stored notes in the Purchase Details view and Receiving Approval lists.
3. **UX Resilience**: Add client-side validation to prevent empty submissions and provide immediate feedback, reducing "phantom" errors on empty saves.

## Capabilities

### New Capabilities
- `purchase-receiving-notes`: Enables storing and displaying per-product notes during the purchase receiving and approval process.

### Modified Capabilities
- `purchase-management`: Update receiving requirements to include per-item notes.

## Impact

- **Database**: New column `note` in `received_note_details`.
- **Backend**: `PurchaseController` and `ReceivedNoteDetail` model updates.
- **Frontend**: `receive.blade.php`, `receiving-details.blade.php`.
