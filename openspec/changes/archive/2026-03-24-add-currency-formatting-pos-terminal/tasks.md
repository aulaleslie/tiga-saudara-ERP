## 1. Update POS Terminal Form

- [x] 1.1 Open `Modules/Pos/Resources/views/terminals/_form.blade.php`
- [x] 1.2 Add `@push('page_scripts')` section at the end of the file (after the form closing)
- [x] 1.3 Include `jquery-mask-money.js` script: `<script src="{{ asset('js/jquery-mask-money.js') }}"></script>`
- [x] 1.4 Initialize maskMoney on both currency fields using the pattern: `$('#field-id').maskMoney({...})`
- [x] 1.5 Use `settings()->currency->symbol`, `thousand_separator`, and `decimal_separator` for localization
- [x] 1.6 Add form submit handler to extract unmasked values before posting

## 2. Test Currency Formatting on Create Page

- [x] 2.1 Open `/pos/terminals/create` in a browser
- [x] 2.2 Verify `close_variance_approval_threshold` field displays as plain number on focus
- [x] 2.3 Enter a value (e.g., "1000") and blur the field; verify it displays as "Rp 1.000,00"
- [x] 2.4 Verify `cash_threshold` field has the same focus/blur behavior
- [x] 2.5 Enter multiple values with decimals (e.g., "5000.50") and verify formatting to "Rp 5.000,50"
- [x] 2.6 Submit the form and verify the values are saved correctly (numeric, not formatted)

## 3. Test Currency Formatting on Edit Page

- [x] 3.1 Navigate to `/pos/terminals/edit/<id>` for an existing terminal
- [x] 3.2 Verify pre-populated currency fields are displayed in formatted style on page load
- [x] 3.3 Click on a formatted field and verify it reverts to plain number
- [x] 3.4 Modify a value and blur the field; verify new formatting applied
- [x] 3.5 Submit the edit form and verify changes are saved as numeric values

## 4. Verify Data Integrity

- [x] 4.1 Create a new terminal with formatted currency values
- [x] 4.2 Query the database to confirm stored values are numeric (not formatted strings)
- [x] 4.3 Edit an existing terminal and verify the database receives unformatted numbers
- [x] 4.4 Test edge cases: zero values, decimal values, and large amounts

## 5. Browser Compatibility Check

- [x] 5.1 Test in Chrome/Chromium
- [x] 5.2 Test in Firefox
- [x] 5.3 Test in Safari (if available)
- [x] 5.4 Verify field behavior on mobile browsers (focus/blur, keyboard input)

## 6. Documentation and Cleanup

- [x] 6.1 Verify no console errors in browser DevTools
- [x] 6.2 Confirm the form styling is not affected by maskMoney initialization
- [x] 6.3 Review the code for any commented-out or temporary code and clean up
