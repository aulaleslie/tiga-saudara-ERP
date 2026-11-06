# Proposal

## Why

Standard Sales Return settlement pages call a deleted model method, older tests fail before reaching their assertions, and lifecycle gaps can duplicate effects or complete unresolved returns. POS Return shares records and status services but has distinct execution rules and existing defects, so a reliable POS regression baseline and explicit workflow ownership are prerequisites to repairing standard returns.

## What Changes

- Stabilize POS first: preserve replacement commercial preview amounts, value normalized draft resolutions consistently, repair invalid fixtures and obsolete assertions, and investigate failures exposed after monetary guards pass. Keep rounding, bundle scope, owner/tax buckets, serial lineage, note-only replacements, and atomic final approval intact.
- **BREAKING**: POS-origin sales must use POS Return; linked Sales Return records become read-only through standard mutation surfaces. Existing ambiguous/mixed histories are reported and blocked from unsafe new actions, never automatically corrected.
- Restore the three standard settlement choices: repair/replacement, cash return, and cannot process; preserve historical labels for legacy choices.
- Enforce server-side lifecycle, ownership, quantity/serial coverage, business permissions, locked state revalidation, and idempotency across standard creation, approval, receiving, settlement, inspection, dispatch, and completion.
- **BREAKING**: future standard receiving holds goods outside sellable stock until an authorized, audited inspection releases them. Original goods returned after repair or inability to process leave custody through approved dispatch; replacement dispatch consumes separate sellable stock.
- Standard cash returns reduce outstanding source-sale debt first, then refund only verified remaining payments; debt-only completion creates no cash payment. Ambiguous financial allocation blocks financial settlement without blocking physical custody.
- **BREAKING**: standard source-sale full-return archival requires completed cash-return coverage; repair/replacement or cannot-process completion does not archive the sale or change its financial amounts.
- Provide a read-only historical audit for stock, financial, completion, and mixed-workflow anomalies, with no automatic data repair.

## Capabilities

### New Capabilities

- `sales-return-workflow`: standard workflow ownership, settlement choices, guarded lifecycle and complete item/serial settlement coverage.
- `sales-return-custody`: held goods, inspected release, original-goods dispatch and auditable stock/serial reconciliation.
- `sales-return-financial-settlement`: source-valued, debt-first settlement with payment caps and explicit debt/refund evidence.
- `sales-return-history-audit`: read-only identification of historical and mixed-workflow anomalies.

### Modified Capabilities

- `pos-return-approval-preview`: preserve replacement commercial values independently of cash valuation.
- `pos-return-draft-resolutions`: use normalized resolution choices consistently in valuation, persistence, and totals.
- `sale-return-completion-archival`: require completed cash-return coverage, preserve custody through completion, and exclude repair/replacement from full-return archival while preserving POS semantics.

## Impact

Affects `Modules/SalesReturn`, `app/Livewire/SalesReturn`, `app/Support/SalesReturn`, permissions, additive migrations, and standard return tests. Targeted POS changes affect `PosReturnApprovalPreviewPlannerService`, `PosReturnSubmissionService`, and relevant POS regression fixtures; shared status services require explicit workflow branches. Product/serial selection and payment/report projections must respect held custody and source-sale adjustments without changing POS contracts. No new external dependencies or automatic historical mutation. Implementation proceeds through POS stabilization, workflow isolation, standard lifecycle repair, then combined regression verification.
