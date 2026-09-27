## Design Summary

The objective is to replace 45+ critical English user-facing strings in the POS and Auth modules with their Indonesian equivalents. 

### Strategy: Direct String Replacement
- Use the provided `ENGLISH_STRINGS_INVENTORY.md` as the source of truth for "Old" and "New" strings.
- Perform direct replacement in the PHP service files and controllers.
- No Laravel `trans()` helpers or `lang/` files will be used, adhering to the project's simplification strategy for this specific task.

### Components Impacted

#### 1. POS Checkout & Payment (`FinalizePosCheckoutService.php`, `InlinePosCheckoutPostingAdapter.php`)
- Update exception messages related to invalid context, empty carts, and payment failures.
- Ensure that the error codes (e.g., `PAYMENT_INVALID`) remain unchanged while only the message text is translated.

#### 2. POS Cart Management (`PosCartService.php`)
- Translate quantities and stock validation messages.
- Update internal cart-related exception strings.

#### 3. POS Session Management (`PosSessionFinalizeService.php`)
- Localize supervisor override and variance approval messages.

#### 4. User Authentication (`LoginController.php`)
- Translate login success, failure, and account deactivation messages.

### Constraints
- Do NOT change logical flow or exception types.
- Maintain existing quote styles (single vs. double) where possible.
- Preserve variables and HTML tags if present.

## Testing & Verification

### Automated Tests
- `php artisan test Modules/Pos/Tests/Feature/POSCheckoutFinalizeIntegrationTest.php`
- `php artisan test Modules/Pos/Tests/Feature/POSCartTest.php`
- `php artisan test Modules/Pos/Tests/Feature/POSCheckoutPostingTest.php`
- `php artisan test Modules/Pos/Tests/Feature/POSSessionFinalizeTest.php`
- `php artisan test tests/Feature/Auth/LoginControllerTest.php` (if exists) or generally `php artisan test --filter LoginController`

### Manual Verification
- Trigger common POS errors (e.g., checkout with empty cart) and verify Indonesian message in the UI or API response.
- Log in with invalid credentials and verify the "Kredensial tidak valid" message.
