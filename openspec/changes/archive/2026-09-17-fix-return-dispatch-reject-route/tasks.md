## 1. Route Wiring

- [x] 1.1 Register the named POST `transfers.movements.return.reject` route beside the return-dispatch approval route, targeting `ReturnDispatchMovementController@reject`.

## 2. Focused Verification

- [x] 2.1 Add a focused feature test showing an authorized approver can render a pending version 2 return-dispatch review page with the rejection form URL.
- [x] 2.2 Extend the focused test to submit a nonempty reason through the named route and verify rejected status, stored reason, and rejection history.
- [x] 2.3 Run the focused feature test and confirm `php artisan route:list --name=transfers.movements.return.reject` shows the registered POST route.
