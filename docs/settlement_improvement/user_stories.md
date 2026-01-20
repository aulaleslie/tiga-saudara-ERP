# User Stories: Purchase Return Settlement Improvement

## Settlement Method Options
As a purchasing/AP staff member,
I want cash settlement to be removed from method choices,
So that I only configure supported settlement methods.

As an approver,
I want legacy cash settlements to still display correctly in read-only views,
So that historical data remains understandable.

## Modify Purchase Selection
As a purchasing/AP staff member,
I want to select paid, unpaid, or partial purchases for Modify Purchase,
So that I can target the correct purchase regardless of payment status.

As a purchasing/AP staff member,
I want a warning when return quantity exceeds the selected purchase quantity for non-serial items,
So that I can review the risk without being blocked.

As a purchasing/AP staff member,
I want to be prevented from selecting purchases from a different supplier,
So that settlement adjustments remain valid.

## Modify Purchase Approval Effects
As an approver,
I want Modify Purchase approval to update purchase item quantities and totals,
So that the purchase reflects the actual returned items.

As an approver,
I want all payments removed and the purchase set to Unpaid when approving Modify Purchase on paid/partial purchases,
So that payment status stays consistent with the adjusted purchase.

## Credit Approval and Payment Creation
As an approver,
I want to add notes and upload attachments when approving Credit settlements,
So that approvals are auditable.

As an approver,
I want a purchase payment created for the selected purchase using the settlement nominal value,
So that the credit is applied to the correct purchase.

As an auditor,
I want credit-related attachments stored with the created payment record,
So that supporting documents are traceable.

## Product Repair Receive Flow
As a receiving staff member,
I want serial-based repair quantities locked to 1,
So that repaired serial items are handled correctly.

As a receiving staff member,
I want to see the old serial and enter a replacement serial,
So that I can record repair or replacement accurately.

As a system administrator,
I want old serials marked permanently returned and excluded from search,
So that returned serials are not reused.

As a system administrator,
I want replacement serials created as new serial records,
So that inventory history remains accurate.

## Broken Stock Receive Flow
As a receiving staff member,
I want received quantity to be read-only for broken stock,
So that I only choose a location without changing quantities.

As a receiving staff member,
I want to select a destination location for broken stock,
So that stock movement is recorded properly.
