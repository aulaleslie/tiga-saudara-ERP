## Why

The redesigned Stock Opname can capture trustworthy location-based physical counts, but every saved document is immediately treated as pending approval while compatible review and approval behavior remains unavailable. Reviewers need a permission-aware, Bahasa Indonesia explanation of quantity, serial-location, condition, and tax effects before approval, while counters must remain unable to see system stock information.

## What Changes

- Separate editable Stock Opname drafts from explicitly submitted documents that are ready for approval; send approval notifications only after submission.
- Make the detail page minimal for counters without system-stock permission, showing only document metadata and the products, quantities, conditions, and serial numbers they entered.
- Give permitted reviewers a current reconciliation preview covering selected-location differences, same-owner totals across eligible locations, stock drift, projected stock effects, new serials, cross-location serial moves, condition changes, and PKP/non-PKP reclassification.
- Warn when an entered product total at the selected location exceeds its current total across all eligible locations in the same owner scope.
- On approval, update only explicitly entered products; leave omitted products and unrelated locations unchanged except when an entered serial must move from its current location.
- Make the destination location setting's `is_pkp` value authoritative for every resulting good/bad tax bucket, including changing an existing taxable serial to non-tax when moved to a non-PKP location and the reverse for a PKP destination.
- Re-resolve and lock current product stocks and serials during approval, apply all mutations atomically, and preserve an immutable applied-result snapshot for later explanation.
- Require a Bahasa Indonesia rejection reason and allow rejected documents to return to an editable draft before resubmission.
- Use Bahasa Indonesia for every Stock Opname user-facing label, status, warning, validation, confirmation, notification, and audit message; project discussion and source-level terminology may remain English.
- Treat the redesigned workflow as the supported path; historical legacy-adjustment compatibility is outside this change.
- Add focused automated lifecycle, permission, preview, tax, serial, and inventory-mutation verification plus a human-executed browser checklist; do not plan a full-suite or automated browser run.

## Capabilities

### New Capabilities

- `stock-opname-review-approval`: Permission-aware review, explicit submission, reviewer reconciliation previews, rejection, atomic approval, applied-result history, and Bahasa Indonesia presentation.

### Modified Capabilities

- `stock-opname-count-drafts`: Saved counts become editable drafts rather than immediately approval-ready documents, and destination PKP classification plus serial source evidence feed the new review and approval lifecycle.

## Impact

- Adjustment status schema and lifecycle/audit metadata, adjustment routes/controllers/services, notification timing, and authorization/active-setting guards.
- `Modules/Adjustment/Resources/views/show.blade.php`, create/edit actions, index actions/status presentation, and related Livewire state exposure.
- `ProductStock`, `ProductSerialNumber`, product aggregate quantity, inventory transactions, stock notifications, and good/bad tax buckets for explicitly entered products.
- Focused tests under the Adjustment module/application test suites and new OpenSpec requirements for the supported Stock Opname workflow.
