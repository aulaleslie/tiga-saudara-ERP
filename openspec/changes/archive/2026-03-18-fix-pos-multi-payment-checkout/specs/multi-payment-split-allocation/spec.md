## ADDED Requirements

### Requirement: Multiple payment methods stored per sale

When a POS checkout uses multiple payment methods (e.g., cash + non-cash), the system SHALL create one `SalePayment` record for each payment method used, with the allocated amount for that payment method.

#### Scenario: Mixed cash and non-cash single setting
- **WHEN** a customer purchases 60K worth of items from the current POS setting and pays with Non-Cash 40K and Cash 50K
- **THEN** the system creates one `Sale` and two `SalePayment` records: one for Non-Cash 40K and one for Cash 20K (the remainder after reaching 60K total)

#### Scenario: Multiple settings with cash priority
- **WHEN** a customer purchases items worth 100K from Setting A and 100K from Setting B (different settings), and pays with Non-Cash 150K and Cash 50K
- **THEN** the system creates two `Sale` records (one per setting) with `SalePayment` records allocated as follows:
  - Setting B (non-POS setting): Cash 50K + Non-Cash 50K (2 SalePayment records)
  - Setting A (POS setting): Non-Cash 100K (1 SalePayment record)

#### Scenario: Cash overpayment with single setting
- **WHEN** a customer purchases 60K worth of items and pays with Non-Cash 40K and Cash 50K
- **THEN** the system creates one `Sale` with two `SalePayment` records (Non-Cash 40K, Cash 20K) and tracks 30K as change

### Requirement: Payment allocation follows ownership priority

The system SHALL allocate multiple payments using ownership-priority logic: cash goes to non-POS-setting products first, then non-cash fills gaps, with any remaining payments going to the POS terminal setting.

#### Scenario: Cash allocated to non-POS settings first
- **WHEN** allocating payments across split sales where Setting B is non-POS and Setting A is the POS terminal
- **THEN** all available cash is allocated to Setting B first before any cash goes to Setting A

#### Scenario: Non-cash allocated to POS setting first
- **WHEN** allocating payments across split sales where Setting B is non-POS and Setting A is the POS terminal
- **THEN** all available non-cash (that didn't overflow) is allocated to Setting A first

#### Scenario: Overflow allocation
- **WHEN** a payment method has more amount than the groups it's allocated to require
- **THEN** the overflow is distributed proportionally to remaining groups with remaining balance

### Requirement: Change calculated from cash component only

When a multi-payment checkout has a cash component, the system SHALL calculate change based only on the cash amount, not the total of all payments.

#### Scenario: Change with mixed payment
- **WHEN** a customer pays with Non-Cash 40K and Cash 50K for a 60K transaction
- **THEN** the system calculates change as 50K - (60K - 40K) = 30K

#### Scenario: No change with non-cash overpayment
- **WHEN** a customer pays with only Non-Cash and no cash component exists
- **THEN** the system calculates change as 0K (no change in non-cash payments)
