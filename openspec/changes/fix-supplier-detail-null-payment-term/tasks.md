## 1. Supplier Detail Rendering

- [ ] 1.1 Update the supplier detail payment-term field to use null-safe relationship access and display `-` when no related payment term resolves.
- [ ] 1.2 Confirm a supplier with a valid payment term continues to display the related payment term name.

## 2. Regression Coverage

- [ ] 2.1 Add a People module feature test proving an authorized supplier show request succeeds and displays `-` when `payment_term_id` is null.
- [ ] 2.2 Add a feature test proving an authorized supplier show request displays the assigned payment term name.
- [ ] 2.3 Run the focused supplier detail tests and resolve any fixture or Livewire rendering issues without broadening the behavioral scope.

## 3. Deployment Verification

- [ ] 3.1 Document or confirm the production deployment step clears compiled Blade views so the corrected template is recompiled.
