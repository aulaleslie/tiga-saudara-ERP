# Design

## Context

`ConsignmentBuyoutController::create` builds an audit list (receipt capacity + pool blockers + attribution per sold source) and, with `propose=all`, a read-only proposal from `ConsignmentBuyoutProposalService::allEligible`. The draft form then re-lists every receipt and sold row with raw inputs for quantity, TAXED/NON_TAXED buckets and comma-separated serial IDs. The lifecycle (`createDraft` → `validateDocument`) remains the authority for every rule.

## Decisions

### One page, three client-side steps
Step 1 is a GET form (supplier) because the goods depend on it. Steps 2 and 3 and the destination selects live in one POST form toggled with Alpine (bundled with Livewire 3), so moving between steps never discards selections. Changing the supplier reloads the page and therefore clears goods (different supplier = different goods); destinations survive through query/old input. Failed saves redirect back with input and the page rehydrates the selection from `old()`.

### Preparation read model
A new `ConsignmentBuyoutPreparationService::prepare($settingId, $supplierId)` calls `allEligible` with destinations deferred and maps the result into:
- `groups[product][receipt]` rows, each with `kind` (unsold/sold), maximum, serial options `{id, serial_number}`, pool key and source key, receipt unit price and unit tax, holding business, source location name, sale reference (not ID);
- `pools` (bucket availability per product/location) and `sources` (available per sold source) so the browser enforces shared limits;
- `blocked` rows with plain Indonesian explanations and links to the existing manual discovery / reconciliation pages.

Hard blockers are kept separate from the proposal's informational clip notes, so pool exhaustion caused only by proposal ordering does not block a row—the shared pool limit is enforced in the browser and on the server instead.

### Tax buckets
The existing proposal rule is reused: within a product/location pool, selected unsold quantity takes the non-taxed bucket first, then taxed, in receipt order. The browser shows the result; `store` derives buckets server-side for unsold lines that arrive without buckets using a fresh (non-memoized) `physicalPool`. Explicit buckets, if posted by another client, are still accepted and validated as before.

### Step 2 layout (refined)
The read model returns a `tree`: receiving document → product → `unsold` groups (one per current location / acquiring business) and `sold` row keys. Each serialized row renders one checkbox per eligible serial (`lines[key][serial_ids][]`, value = serial id, label = serial number). Non-serialized rows keep a checkbox + quantity input under the same hierarchy. Receipt- and product-level checkboxes derive `checked`/`indeterminate` from their units (serial = unit; quantity row = one unit, partial when below its maximum). Global and group selection call one `fill(key)` routine that respects the row maximum and the shared pool / sold-source room, in screen order.

A serial is offered once per screen (the read model tracks serials already placed). The proposal also emits only one unsold row per receipt + location, even when provenance reports several custody entries there; previously this could show the same goods twice.

### Sold documents
`soldDocuments()` batches the Sale references and any `pos_checkout_sales` → `pos_checkouts` (`receipt_number`) → `pos_transactions` (`code`) rows. The POS receipt is preferred as the label, with the Sales reference shown as secondary. Links: `pos.transactions.show` only when the POS checkout belongs to the active business, POS and POS transactions are enabled there, and the actor has `pos.access` + `pos.transactions.view`. Otherwise it falls back to `sales.show` for the active business's own sale with `sales.show`, otherwise plain text. A selling business different from the active one is named. Fully billed sold sources go into a separate `settled` summary.

### Serial selection (superseded)
The first iteration used a Select2 multi-select. It was replaced by per-serial checkboxes. The posted shape (`serial_ids[]`) and the lifecycle validation are unchanged.

### Evidence reuse
`ConsignmentBuyoutCapacityService::readOnlyEvidence(Closure)` enables an in-instance memo for `soldAttribution`, `receiptCapacity`/`soldSourceCapacity`/`soldReceiptResidual`/`physicalPool` (only when no exclusion ids are passed), `poolBlockers`, `hasCustodyEvents`, `selectableSerialIds` and the effective customer-return evidence (`returnedSerialIds` / `returnedQuantity`). The memo is cleared when the closure exits and is never enabled by store/revise/submit/approve/finalize/reverse, so mutation paths always read fresh, locking where they already do.

## Risks / Trade-offs

- Client-side limits are advisory; the lifecycle revalidates everything at save, submit and approval.
- Memo correctness depends on no writes during the preparation closure; `create` is read-only.
