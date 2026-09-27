## 1. Request Layer Preparation

- [x] 1.1 Create `Modules/Setting/Http/Requests/StorePaymentMethodRequest.php` by extracting validation from the controller.
- [x] 1.2 Add unique check for `is_cash` in `StorePaymentMethodRequest`.
- [x] 1.3 Create `Modules/Setting/Http/Requests/UpdatePaymentMethodRequest.php` with similar logic.
- [x] 1.4 Refine `UpdatePaymentMethodRequest` to exclude the current record ID from the `is_cash` uniqueness check.

## 2. Controller Implementation

- [x] 2.1 Update `PaymentMethodController` to import and use the new request classes in `store()` and `update()` methods.
- [x] 2.2 Remove manual `request->validate()` calls from both controller methods.
- [x] 2.3 Optimize `store()` and `update()` to use `$request->validated()` for model creation and updates.

## 3. Testing & UI Check

- [x] 3.1 Verify creation of a new "Cash" payment method fails if one already exists.
- [x] 3.2 Verify updating a second method to "Cash" fails and returns the custom error message.
- [x] 3.3 Ensure the UI displays the validation error clearly next to the `is_cash` switch/checkbox.
