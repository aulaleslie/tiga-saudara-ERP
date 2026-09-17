## 1. Preparation view data

- [x] 1.1 In the HTML path of `ForwardDispatchMovementController::prepare`, explicitly load missing movement line product and serial relations before returning the Blade view.
- [x] 1.2 Load transfer products for the HTML view only when the dispatcher has system-stock visibility; retain the existing blind requested-quantity behavior.

## 2. Focused verification

- [x] 2.1 Add or extend a focused feature test that opens a new multi-line forward-dispatch draft as HTML with lazy loading disabled, then reopens the existing draft; assert both responses render the expected product and count content.
- [x] 2.2 Verify the requested-quantity column appears only for a stock-visible dispatcher, and run the focused test file or filter.
