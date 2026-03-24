## 1. Modify Controller

- [x] 1.1 Update PosSessionController::summary() to render view instead of returning JSON
- [x] 1.2 Ensure controller passes all service data to the view

## 2. Create Session Detail View

- [x] 2.1 Create session/summary.blade.php with session overview header section
- [x] 2.2 Add cash events timeline section with event type filtering buttons
- [x] 2.3 Add transactions ledger section with table and aggregates
- [x] 2.4 Add action buttons (close, finalize, admin-close) with conditional display
- [x] 2.5 Add back button to return to sessions list
- [x] 2.6 Style the page to match existing POS module design (use Bootstrap classes)

## 3. Create Checkout Detail Modal

- [x] 3.1 Create modal HTML structure for displaying full checkout details
- [x] 3.2 Implement checkout detail fetching (AJAX or inline data)
- [x] 3.3 Display receipt number, customer, items, amounts, payment method, timestamps
- [x] 3.4 Include close button and backdrop click to close modal

## 4. Implement JavaScript Handlers

- [x] 4.1 Create pos-session-detail-handlers.js for transaction row click interactions
- [x] 4.2 Implement cash event filtering logic (show/hide by event type)
- [x] 4.3 Implement transaction row click handler to open checkout detail modal
- [x] 4.4 Wire up existing session action buttons (close/finalize) to modal handlers
- [x] 4.5 Include modal handlers from pos-session-handlers.js or adapt them for the detail page

## 5. Testing & Verification

- [x] 5.1 Test accessing the detail page as session owner (should see full details)
- [x] 5.2 Test accessing the detail page with pos.sessions.view permission (should see full details)
- [x] 5.3 Test accessing the detail page without permission (should get 403)
- [x] 5.4 Verify cash events timeline displays all events in reverse chronological order
- [x] 5.5 Verify cash event filtering works (click filter button, only matching events show)
- [x] 5.6 Verify transaction ledger displays last 50 transactions with correct data
- [x] 5.7 Verify clicking a transaction row opens checkout detail modal
- [x] 5.8 Verify checkout detail modal displays receipt, customer, items, amounts correctly
- [x] 5.9 Verify action buttons (close/finalize) open modals and work correctly
- [x] 5.10 Verify back button returns to sessions list
- [x] 5.11 Test with OPEN, CLOSED, CLOSING, FINALIZED session statuses

## 6. Cleanup & Documentation

- [x] 6.1 Remove any logging or debug code
- [x] 6.2 Verify no console errors in browser DevTools
- [x] 6.3 Test responsive design on mobile/tablet (if applicable)
- [x] 6.4 Run any existing POS module tests to ensure no regressions
