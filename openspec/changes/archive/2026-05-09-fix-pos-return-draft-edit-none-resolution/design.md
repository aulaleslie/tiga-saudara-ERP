## Context

POS Return drafts support per-line resolutions: `none`, `cash_return`, and `product_replacement`. The edit form correctly lets users select `Tidak` for a serial line and sends that line as `resolution = none`.

The current update service still passes the existing header `return_option` as a default into draft-line validation. Because serial lines always carry quantity `1`, validation treats `none + quantity > 0 + header cash_return` as a missing resolution and rewrites it back to `cash_return`. On a draft such as `TNC-POSRT-2605-0001`, this means changing one returned serial to `Tidak` never persists, and changing every serial to `Tidak` is silently converted to all-cash-return instead of being rejected.

This change stays within draft persistence. Draft save must continue to avoid Sales Return creation, stock mutations, dispatch quantity reductions, payment settlement, replacement dispatch, and serial execution mutations.

## Goals / Non-Goals

**Goals:**

- Make explicit per-line `none` authoritative during draft edit.
- Preserve partial `Tidak` edits when at least one other line remains actionable.
- Reject all-`Tidak` draft edits with the existing clear validation message.
- Keep source snapshot freshness and replacement serial validation intact.
- Add regression coverage around the edit service and Livewire edit form behavior.

**Non-Goals:**

- Change approval, receiving, settlement, dispatch, archive, or cancel behavior.
- Change POS Return header `return_option` semantics outside draft edit validation.
- Add rejected-edit behavior beyond the currently allowed draft edit rule.
- Add database columns or rewrite historical POS Return data.

## Decisions

### Decision 1: Line Resolution Wins Over Header Default During Edit

For draft edit, the submitted line resolution is the authoritative user intent. If a line explicitly says `none`, validation must keep it as `none`; it must not use the POS Return header `return_option` to infer `cash_return` or `product_replacement`.

Rationale: the edit UI is per-line. Header-level fallback is a compatibility behavior for payloads that do not express line-level intent, but the Livewire edit form does express it.

Alternative considered: clear or change the header `return_option` when one line changes to `none`. Rejected because the header is not granular enough to represent mixed serial outcomes and changing it does not solve explicit per-line semantics.

### Decision 2: Keep At Least One Actionable Line Validation

After preserving explicit `none`, validation must still require at least one actionable line. A draft edit that leaves every line as `none`, or omits all actionable non-serial lines, must fail and leave the existing draft unchanged.

Rationale: POS Return drafts are return intake documents. A draft with no return action is not meaningful and existing specs already require a clear validation failure.

Alternative considered: allow empty/all-none drafts for later completion. Rejected because it weakens the current business rule and would create list/detail entries that represent no return.

### Decision 3: Preserve Existing Draft-Only Mutation Boundary

Implementation should remain limited to draft-line validation/building and tests. It should not introduce Sales Return execution records or stock/payment side effects.

Rationale: this is a correction to draft edit semantics, not a lifecycle expansion.

Alternative considered: normalize behavior later during submit-for-approval. Rejected because the user-facing bug occurs at draft save, and invalid all-`Tidak` data should be rejected before persistence.

## Risks / Trade-offs

- Existing legacy callers may rely on header fallback when line resolution is missing -> Keep fallback only for missing/implicit line resolution, not for explicit `none`, or scope the change to the edit path where Livewire sends explicit resolutions.
- Persisted `none` serial lines can affect detail/readonly displays -> Ensure detail behavior remains consistent with the readonly layout spec: returned/actionable lines stay primary, while full source snapshot can show not-returned context.
- Tests may miss the Livewire payload path if only service tests are added -> Cover both service update and Livewire edit form where practical.
- Existing draft records with header `cash_return` remain unchanged until edited -> No migration needed; the corrected behavior applies when users save an edit.

## Migration Plan

No schema migration is expected. Deploy as an application-code correction with focused tests. Rollback is the normal code rollback; no data transformation is required.

## Open Questions

None. The intended behavior is explicit: partial `Tidak` edits save, all-`Tidak` edits fail.
