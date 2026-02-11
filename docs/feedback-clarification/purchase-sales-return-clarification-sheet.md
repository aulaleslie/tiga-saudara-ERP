# Purchase/Sales Return Clarification Sheet
Date: 2026-02-11

## How to answer
- Fill each `Answer:` line directly under each question.
- Use short values when possible (example: `yes`, `no`, `UI only`, `backend + UI`).
- If unsure, write `TBD`.

## English translation of reported items

### Bugs
- B1. Attachments are missing when choosing `Save as DP` (deposit/down payment) for another payment note/invoice.
- B2. In purchase flow, products without serial numbers have different behavior (noticed in discount handling).
- B3. Serial numbers that were already returned should be receivable again.
- B4. Sales return cannot process serial-number products.

### Feedback
- F1. Add `is PKP` in settings. If `is PKP = true`, tax selection must be required in purchase and sale creation.
- F2. Set default payment term to `COD`.
- F3. Serial numbers from purchase return should not be deleted; only change visual status/color.
- F4. Payment records should be flagged so they do not need to be deleted.
- F5. In purchase and sales, receiving and dispatch behavior needs review for reject flow.
- F6. On purchase receiving page, remove document status display.
- F7. Receiving list needs a mechanism to show products entered for receiving, including serial numbers.
- F8. In product list, `TOTAL` should include on-order and in-return quantities.
- F9. In product detail serial search, clearing search does not clear the serial field correctly.
- F10. In purchase return process, status should not be red yet; yellow is preferred.

## Clarification questions

## 1) Cross-module

### CM-01
Should `DP` be treated as deposit/credit balance that can be applied only to purchases from the same supplier?
Answer: so we lock our purchase return method to what we currently have. this should be when user select MODIFY PURCHASE, when user select other purchase, system will create payment for target purchase. thus, optional attachment is needed as attachment for the target purchase payment

### CM-02
Do you want standardized color semantics across all modules?
Suggestion: `yellow = in progress`, `red = rejected/final problem`, `green = done`.
Answer: yes

### CM-03
For this batch, should we change:
- `UI only`, or
- `backend + UI + database status rules`
Answer: backend + UI + database status rules

## 2) Purchase and receiving

### PR-01
For non-serial products in purchase discount flow, what is the exact issue?
Options: `wrong total`, `wrong line discount`, `wrong tax after discount`, `rounding`, `validation`, `other`.
Answer: this issue is about the formatting when purchase is with serial number, it's not consistent with non-serial product. should consistent with non serial product

### PR-02
When a receiving is rejected, should quantity/serial draft data be kept for edit-resubmit, or reset/cleared?
Answer: I believe this already handled separately. skip this for now

### PR-03
After reject, should user be able to edit and resubmit the same receiving document?
Answer: no, always create new

### PR-04
`Remove document status` in receiving page means:
- hide status column only, or
- hide status + badges + status-dependent actions
Answer: there is status under purchase to receive page. we don't need purchase status there since the status always APPROVED.

### PR-05
For receiving list product+serial visibility, preferred UI:
- always expanded
- expandable row
- modal/drawer detail
Answer: skip this

### PR-06
In receiving detail, should serials show:
- pending input only
- approved serials only
- both pending and approved
Answer: skip this

## 3) Purchase return and settlement

### PS-01
For `Save as DP` to another note, should attachments be copied automatically from source return/settlement to created payment record?
Answer: again, we keep current purchase return settlement method behaviour, only when target purchase is different we add optional attachment

### PS-02
If copied, should attachment data be independent copies, or linked/synced between source and target records?
Answer: attached to the target purchase payment. no need to save copies under purchase return. 

### PS-03
`Payment should not be deleted` should be implemented as:
- soft delete only
- hard delete blocked (immutable)
- allow delete only for draft, block for approved/system-generated
Answer: skip this. already implemented

### PS-04
Which payments must be non-deletable?
- all payments
- only settlement-generated payments
- only approved payments
Answer: skip this

### PS-05
For serial re-receive after purchase return, confirm behavior:
- reactivate existing serial row (do not create new row)
- update location/tax to current receiving context
Answer: skip this

### PS-06
Where should returned/reused serial color be shown?
Options: `receiving detail`, `product detail`, `purchase return detail`, `all`.
Answer: skip this

### PS-07
For purchase return process color, which statuses should be yellow and which should be red?
Answer: skip this

## 4) Sales and dispatch

### SR-01
When a dispatch is rejected, should stock reservation and serial linkage be fully rolled back immediately?
Answer: yes, check first before changes

### SR-02
After dispatch reject, should sale status auto-return to `approved/pending dispatch`?
Answer: yes, check first before changes

### SR-03
Should dispatch reject always require rejection reason and audit fields (`who`, `when`, `why`)?
Answer: yes

### SR-04
Can rejected dispatch be edited and resubmitted from the same dispatch document?
Answer: no

### SR-05
Should reject behavior in dispatch mirror receiving reject behavior exactly, or can they differ?
Answer: yes

## 5) Sales return

### SR-06
For "sales return cannot process serial products", which step fails?
Options: `reference search`, `row loading`, `serial picker`, `validation`, `approval`, `receiving`, `other`.
Answer: skip this

### SR-07
Should sales return serial eligibility include only serials from approved dispatches?
Answer: yes

### SR-08
If a serial was already returned once, should second return be blocked with explicit error?
Answer: no, already implemented you can skip

### SR-09
For serial products in sales return, should quantity always auto-follow selected serial count (read-only qty)?
Answer: yes

### SR-10
After sales return rejection, should selected serials be kept for correction, or cleared?
Answer: skip this

## 6) Product list and product detail

### PD-01
Please confirm formula for product list `TOTAL`.
Candidate: `total_stock + on_order_stock + in_return_process_stock`.
Answer: total stock + on order stock + in return process stock

### PD-02
Should `on_order_stock` include only approved/partially received purchases (and exclude rejected receiving documents)?
Answer: yes, should be careful when calculate from approved/partially received purchases

### PD-03
Should `in_return_process_stock` include only approved+dispatched returns that are not fully resolved?
Answer: dispatched returns that are not fully resolved. should calculate for resolved quantity as well

### PD-04
For serial search clear on product detail page, should clear reset both:
- UI input field, and
- server-side filter state
Answer: both

### PD-05
After clearing serial search, should pagination/tab state reset too, or only search text?
Answer: reset too
