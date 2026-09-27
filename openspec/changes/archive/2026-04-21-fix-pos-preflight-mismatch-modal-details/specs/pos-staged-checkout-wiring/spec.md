## MODIFIED Requirements

### Requirement: Activate staged payment from checkout button
The "Pilih Pembayaran" button SHALL run checkout preflight validation before opening the multi-stage sequential payment modal. When cashier clicks the button, a cart-scoped token SHALL be generated (or reused if reload recovery), but the staged payment module SHALL only be opened after preflight success. The client-side preflight error handling MUST preserve structured backend failure payload (`code`, `message`, `details`) so mismatch routing can use machine-readable diagnostics.

#### Scenario: Fresh cart checkout with valid preflight
- **WHEN** user adds items to cart, clicks "Pilih Pembayaran", and preflight returns success
- **THEN** a new UUID token is generated (or existing token reused), staged payment modal opens with remainder = cart grand_total, and user can select first payment method

#### Scenario: Fresh cart checkout with preflight mismatch
- **WHEN** user clicks "Pilih Pembayaran" and preflight reports serial/stock mismatch
- **THEN** staged payment modal MUST NOT open
- **AND** POS shows mismatch dialog with actionable failing line details

#### Scenario: Structured preflight payload is retained in UI error branch
- **WHEN** preflight endpoint responds with non-2xx and body containing `details.unfulfilled_lines` or `details.invalid_lines`
- **THEN** client checkout handler MUST receive `error.details` in structured form
- **AND** mismatch dialog path MUST be selected instead of generic validation alert fallback

#### Scenario: Reload during incomplete payment
- **WHEN** user has committed 1+ payment stages and refreshes the page
- **THEN** the modal auto-opens at the next stage with full payment chain visible and remainder updated

#### Scenario: All payments complete
- **WHEN** remainder becomes 0 (or negative for overpayment) and checkout finalize succeeds
- **THEN** staged modal hides and gratitude modal shows with "Lanjut Jualan" button
