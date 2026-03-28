## 1. Fix Empty Field Initialization

- [x] 1.1 Remove the falsy check at line 251 in nominal-field.blade.php that skips initialization for empty values
- [x] 1.2 Change initialization logic to always call configureMask() and apply initial mask, even for empty/zero values
- [x] 1.3 Ensure empty fields display "0,00" placeholder on page load instead of blank
- [x] 1.4 Test that empty field initialization works on both create and edit pages

## 2. Fix Blur Formatting Logic

- [x] 2.1 Remove the toFixed(2) pre-formatting call in the blur handler (line 254 area)
- [x] 2.2 Modify blur handler to pass raw numeric value directly to maskMoney('mask'), not pre-formatted string
- [x] 2.3 Ensure maskMoney('mask') is called with clean raw number for correct locale-aware formatting
- [x] 2.4 Test that blur event correctly formats values like "5000" → "Rp 5.000,00" (no corruption)

## 3. Test Empty Field Scenarios

- [x] 3.1 Create page: Enter "5000" in empty price field, blur → verify displays "Rp 5.000,00"
- [x] 3.2 Create page: Check all 5 price fields initialize with "0,00" on page load
- [x] 3.3 Create page: Focus empty field → show raw "0", blur → show "Rp 0,00"
- [x] 3.4 Edit page: Start with blank price field, enter value, blur → verify formatting works

## 4. Test Edit Page Scenarios

- [x] 4.1 Edit page with existing price (50000): Load → verify displays "Rp 50.000,00"
- [x] 4.2 Edit page: Focus field → verify shows raw "50000"
- [x] 4.3 Edit page: Clear field and blur → verify shows "0,00"
- [x] 4.4 Edit page: Blur → verify value is NOT corrupted to "0.5" or other incorrect value
- [x] 4.5 Edit page: Enter decimal like "1234.56", blur → verify displays "Rp 1.234,56"

## 5. Test Conversion Table Prices

- [x] 5.1 Create page: Conversion table empty row, enter price "2000", blur → verify "Rp 2.000,00"
- [x] 5.2 Edit page: Conversion table with existing prices, focus → show raw, blur → show formatted
- [x] 5.3 Conversion table: Verify hidden input syncs with visible input on blur

## 6. Cross-Browser Testing

- [x] 6.1 Test in Chrome/Chromium browser
- [x] 6.2 Test in Firefox browser
- [x] 6.3 Test on mobile/responsive view
- [x] 6.4 Test with different input methods (keyboard, paste, drag)

## 7. Integration Testing

- [x] 7.1 Submit product create form with populated prices → verify correct values saved
- [x] 7.2 Submit product edit form after modifying prices → verify correct values saved
- [x] 7.3 Submit conversion table prices → verify correct values in database
- [x] 7.4 Check form validation still works with formatted fields

## 8. Final Verification

- [x] 8.1 Verify all 5 product price fields work correctly (purchase, sale, tier1, tier2)
- [x] 8.2 Verify conversion table price field works correctly
- [x] 8.3 Verify no JavaScript errors in browser console
- [x] 8.4 Verify component still works with Livewire reactivity (enable/disable on checkbox)
