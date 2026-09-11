## Human Browser Verification Checklist

Covers behavior that automated tests do not exercise directly: visual/keyboard interaction, styling, and end-to-end feel.

1. **Segmented condition control (create form)**
   - Tab to the "Kondisi Stok" control using only the keyboard; confirm each option receives a visible focus ring.
   - Confirm the selected option is distinguishable without relying on color alone (icon + label, not just a color fill).
   - Resize the browser to a narrow/mobile width and confirm the segmented control remains usable and readable.

2. **Creation-time condition confirmation**
   - Start a new transfer, pick an origin, add at least one product row, then switch the stock condition.
   - Confirm a modal appears warning that rows will be cleared.
   - Click "Batal" (cancel) and confirm the condition selector reverts to the prior value and the rows remain.
   - Repeat and click "Ya, Ganti & Hapus Baris" (confirm) and confirm all rows are cleared and the new condition applies.

3. **Read-only edit condition**
   - Open an existing DRAFT transfer for edit and confirm the stock condition renders as a read-only badge (no dropdown/radio control).
   - Open an existing PENDING transfer for edit and confirm the same read-only presentation.

4. **Draft edit discovery**
   - From the transfer list, confirm a DRAFT transfer now shows an Edit action (previously only PENDING did).
   - Confirm a user without `stockTransfers.edit` sees no Edit action on either DRAFT or PENDING rows.

5. **Pending-to-draft success feedback**
   - Edit a PENDING transfer, change a product quantity, and save.
   - Confirm a clear success message indicates the transfer was saved as a DRAFT and must be resubmitted (not a generic "updated" message).
   - Confirm the transfer's status shown on the list/show page is now DRAFT.
