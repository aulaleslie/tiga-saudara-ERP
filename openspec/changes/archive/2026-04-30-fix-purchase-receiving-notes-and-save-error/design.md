## Context

The Purchase Receiving process currently allows users to input optional notes for each product being received. However, these notes are not persisted in the database. Additionally, there is a reports of an intermittent error when users submit the receiving form immediately after landing on the page without making any changes.

## Goals / Non-Goals

**Goals:**
- Implement database persistence for per-product receiving notes.
- Display these notes in the Purchase Details and Receiving Approval interfaces.
- Improve the robustness of the receiving form submission to prevent intermittent errors on empty saves.

**Non-Goals:**
- Modifying the core purchase status logic (except for standard updates).
- Adding complex multi-location routing for notes.
- Changing the existing serial number validation logic.

## Decisions

1. **Database Schema**: Add a `note` column (string, nullable) to the `received_note_details` table. A new migration will be created for this.
2. **Model Layer**: Update `ReceivedNoteDetail` to include `note` in `$fillable`.
3. **Controller Layer**: 
   - Update `PurchaseController::storeReceive` to extract the `notes` array from the request.
   - Map each note to its corresponding `ReceivedNoteDetail` during creation.
4. **UI Layer**:
   - Update `receiving-details.blade.php` to add a "Catatan" column in the product list.
   - Implement client-side validation in `receive.blade.php` using a simple JavaScript check that alerts the user if no quantities or serials have been entered, preventing the "empty save" intermittent error.
   - Use a loading state or button disabling feedback that is cleared if validation fails.

## Risks / Trade-offs

- **Data Migration**: Adding a column to an existing table. Since it's nullable, there is no risk to existing data.
- **Form State**: Disabling the submit button via JS might prevent resubmission if the page doesn't reload correctly. The design ensures the button state is managed properly.
