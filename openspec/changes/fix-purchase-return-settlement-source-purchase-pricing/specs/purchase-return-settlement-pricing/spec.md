## ADDED Requirements

### Requirement: Selected source purchase is the authority for a targeted settlement value

When a purchase return settlement line uses the `MODIFY_PURCHASE` method and has a target purchase selected, the system SHALL derive that line's settlement value from the target purchase's own line price, computed as the target purchase detail's `unit_price` for the line's product multiplied by the line's settled quantity. The system SHALL NOT derive the value from the product catalogue or from the return line's stored value while a target purchase is selected.

#### Scenario: Targeted line is valued from the target purchase

- **WHEN** a draft settlement line uses `MODIFY_PURCHASE` and has a target purchase whose detail for that product records a unit price
- **THEN** the settlement value SHALL equal that unit price multiplied by the line's settled quantity

#### Scenario: Target purchase price differs from the return's recorded value

- **WHEN** the target purchase's unit price for the line's product differs from the value stored on the return line
- **THEN** the settlement value SHALL follow the target purchase's unit price
- **AND** the value stored on the return line SHALL NOT constrain the result

#### Scenario: Target purchase has no matching product detail

- **WHEN** the selected target purchase has no purchase detail for the line's product, or that detail's unit price cannot be resolved
- **THEN** the system SHALL leave the line's existing settlement value unchanged
- **AND** the system SHALL NOT set the settlement value to zero

### Requirement: The target-derived value is not capped by the return line's stored value

The system SHALL NOT limit a targeted `MODIFY_PURCHASE` settlement value to the return line's stored ceiling. When the target purchase's derived value exceeds that ceiling, the system SHALL use the derived value.

#### Scenario: Target purchase price exceeds the stored ceiling

- **WHEN** the target purchase's derived value for a draft line is greater than the return line's stored ceiling
- **THEN** the settlement value SHALL equal the derived value
- **AND** the settlement value SHALL NOT be reduced to the stored ceiling

#### Scenario: Stored ceiling is zero

- **WHEN** a draft line's stored ceiling is zero because the return was created with an unresolved catalogue price, and a target purchase with a non-zero unit price is selected
- **THEN** the settlement value SHALL equal the value derived from the target purchase
- **AND** the settlement value SHALL NOT be zero

### Requirement: Submission validation accepts the target-derived value

The system SHALL validate a settlement line's value against the target-derived value when the line is targeted, and against the return line's stored ceiling when it is not. A value correctly derived from the selected target purchase SHALL be accepted for submission.

#### Scenario: Submitting a targeted line valued above the stored ceiling

- **WHEN** a user submits a targeted `MODIFY_PURCHASE` line whose value was derived from the target purchase and exceeds the return line's stored ceiling
- **THEN** the system SHALL accept the submission
- **AND** the system SHALL NOT reject it for exceeding the stored ceiling

#### Scenario: Submitting an untargeted line above its ceiling

- **WHEN** a user submits a `MODIFY_PURCHASE` line with no target purchase and a value exceeding the return line's stored ceiling
- **THEN** the system SHALL reject the submission

### Requirement: Approval validation bounds a targeted settlement by its target purchase

At approval, the system SHALL bound a `MODIFY_PURCHASE` settlement that has an explicitly selected target purchase by that purchase's total value rather than by the return line's subtotal. The system SHALL continue to bound every other settlement, including a `MODIFY_PURCHASE` settlement with no selected target purchase, by the return line's subtotal. The originating purchase recorded on the return line SHALL NOT by itself qualify a settlement for the target-purchase bound.

#### Scenario: Approving a targeted settlement valued above the return line subtotal

- **WHEN** an approver approves a `MODIFY_PURCHASE` settlement with a selected target purchase, whose value exceeds the return line's subtotal but not the target purchase's total value
- **THEN** the system SHALL accept the approval

#### Scenario: Approving a targeted settlement above the target purchase total

- **WHEN** an approver approves a `MODIFY_PURCHASE` settlement with a selected target purchase, whose value exceeds that purchase's total value
- **THEN** the system SHALL reject the approval

#### Scenario: Approving an untargeted settlement on a line with a recorded originating purchase

- **WHEN** an approver approves a `MODIFY_PURCHASE` settlement with no selected target purchase, on a return line that records an originating purchase, and whose value exceeds the return line's subtotal
- **THEN** the system SHALL reject the approval

#### Scenario: Approving a non-modify settlement above the return line subtotal

- **WHEN** an approver approves a settlement using a method other than `MODIFY_PURCHASE` whose value exceeds the return line's subtotal
- **THEN** the system SHALL reject the approval

### Requirement: Settlement value is recomputed wherever a target purchase becomes associated with a line

The system SHALL recompute the settlement value from the target purchase in every situation where a target purchase becomes associated with an eligible line, including automatic association, and SHALL NOT require the user to re-select the target purchase to obtain a correct value.

#### Scenario: Target purchase is auto-selected for a serialized line

- **WHEN** a user selects the `MODIFY_PURCHASE` method on an eligible serialized line and the system automatically associates the serial's originating purchase as the target
- **THEN** the settlement value SHALL be recomputed from that purchase

#### Scenario: Target purchase is auto-selected for a non-serialized line

- **WHEN** a user selects the `MODIFY_PURCHASE` method on an eligible non-serialized line and the system automatically associates the return line's originating purchase as the target
- **THEN** the settlement value SHALL be recomputed from that purchase

#### Scenario: Target purchase is chosen manually

- **WHEN** a user selects a target purchase for an eligible `MODIFY_PURCHASE` line
- **THEN** the settlement value SHALL be recomputed from the selected purchase

#### Scenario: Settlement form is opened with a target already associated

- **WHEN** a user opens the settlement form and an eligible line already has a target purchase associated, whether previously saved or automatically assigned during loading
- **THEN** the displayed settlement value SHALL be recomputed from that target purchase before it is presented

### Requirement: Settlement quantity semantics are preserved per line type

The system SHALL treat each serialized settlement line as one unit when deriving its value from the target purchase, and SHALL use the line's recorded quantity for non-serialized lines.

#### Scenario: Serialized line on a multi-quantity return detail

- **WHEN** a serialized settlement line belongs to a return detail whose quantity is greater than one, and its value is derived from a target purchase
- **THEN** the settlement value SHALL equal the target purchase's unit price for one unit
- **AND** the settlement value SHALL NOT be multiplied by the return detail's quantity

#### Scenario: Non-serialized line with a recorded quantity

- **WHEN** a non-serialized settlement line with a recorded quantity derives its value from a target purchase
- **THEN** the settlement value SHALL equal the target purchase's unit price multiplied by that recorded quantity

### Requirement: Recomputation is limited to draft settlement lines

The system SHALL recompute settlement values only for lines in draft status, including lines reset to draft after rejection. The system SHALL preserve the stored settlement value of lines that have been submitted or approved.

#### Scenario: Submitted line is not recomputed

- **WHEN** a settlement line has been submitted for approval and the settlement form is opened
- **THEN** the line SHALL retain its stored settlement value

#### Scenario: Approved line is not recomputed

- **WHEN** a settlement line has been approved and the settlement form is opened
- **THEN** the line SHALL retain its stored settlement value

#### Scenario: Rejected line reset to draft is recomputed

- **WHEN** a rejected settlement line is reset to draft and a target purchase is associated with it
- **THEN** the settlement value SHALL be recomputed from that target purchase

### Requirement: Settlement without a target purchase retains its stored valuation

The system SHALL continue to value a `MODIFY_PURCHASE` settlement line that has no target purchase selected from the return line's stored value, subject to that line's existing ceiling. This supports settling a return against an already-paid purchase where the supplier refunds the amount rather than a purchase document being modified.

#### Scenario: No target purchase is selected

- **WHEN** a user selects the `MODIFY_PURCHASE` method and leaves the target purchase unselected
- **THEN** the settlement value SHALL be the return line's stored value
- **AND** no purchase-derived recomputation SHALL be applied

#### Scenario: Target purchase selection is cleared

- **WHEN** a user clears a previously selected target purchase on a draft line
- **THEN** the line SHALL be valued from the return line's stored value

### Requirement: Full-quantity settlement against an unpaid source purchase clears its outstanding balance

When a purchase return covers the full received quantity of an unpaid source purchase and every line is settled with `MODIFY_PURCHASE` targeting that purchase, the system SHALL produce settlement values whose total equals the source purchase's own recorded line values, so that applying the resulting credit leaves no outstanding balance on that purchase.

#### Scenario: Full-quantity return targeting the source purchase

- **WHEN** all lines of a purchase return covering the full quantity of an unpaid source purchase are settled with `MODIFY_PURCHASE` targeting that purchase
- **THEN** the total of the settlement values SHALL equal the total of the source purchase's corresponding line values
- **AND** the source purchase's outstanding balance SHALL be reduced to zero once the settlement is approved
