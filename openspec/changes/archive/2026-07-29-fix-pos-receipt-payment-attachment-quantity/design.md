## Context

POS uses minor units internally for selected cart pricing and payment persistence, while customer-facing totals are Rupiah. Receipt construction currently reads a snapshotted line total without applying its storage-unit conversion. Separately, staged payment UI state uses one transient image token for the active form. After a non-cash stage succeeds, the form reset treats that token as uncommitted and deletes it even though the server-side payment chain references it.

## Goals / Non-Goals

**Goals:**

- Make every receipt line-total display use the same Rupiah unit as its checkout grand total.
- Preserve successfully staged non-cash image evidence until finalization, expiry, or full-chain reset.
- Keep cash stages unable to accept or receive payment images.
- Render POS quantities without locale or fixed-decimal formatting.

**Non-Goals:**

- Change stored payment, pricing, or checkout arithmetic units.
- Permit attachments for cash payments.
- Change monetary formatting or non-POS quantity displays.
- Add database tables or migrate historical records.

## Decisions

### Convert only at the receipt display boundary

Receipt data mapping will normalize a snapshotted line-total minor-unit value to Rupiah before it reaches the receipt template. This applies to completed and draft/loaded transaction paths, while normal non-snapshotted Sale-detail fallback values remain unchanged. This keeps arithmetic and persistence intact and makes the receipt row agree with its grand total.

Alternatives considered: changing the cart snapshot to persist Rupiah values would create broad compatibility risk; changing only the Blade template would duplicate unit knowledge and miss non-template receipt consumers.

### Separate staged attachment ownership from the active form

After a stage is accepted, its attachment token is chain-owned and must not be removed by active-form reset or by selecting Cash for a subsequent stage. The UI will clear only its active-form state after successful staging. Explicit removal before staging deletes that pending upload; full payment-chain reset continues to remove unconsumed chain-owned uploads.

Alternatives considered: allowing Cash to keep the prior token would violate payment-stage isolation; skipping all cleanup would leak abandoned temporary uploads.

### Use raw normalized values for POS quantities

POS views will pass the numeric quantity through without `number_format`, locale grouping, or forced decimal precision. Integer input displays as `1`; a real non-integer quantity retains its meaningful fraction (for example `1.5`). Currency fields retain their existing monetary formatters.

## Risks / Trade-offs

- [Historical snapshots may not identify their storage unit] → use the established transaction snapshot contract and cover completed, draft, and packed/conversion examples.
- [Async deletion can race stage transition] → do not issue deletion after successful staging; limit deletion to explicitly pending uploads and chain reset.
- [Removing quantity formatting may affect dense tables] → scope the change to quantities only and retain monetary/date formatting.

## Migration Plan

Deploy as an application-only change with focused regression tests. No data migration is required. Rollback restores prior rendering and client code; existing temporary-image expiry cleanup remains available for uploads created during the rollback window.

## Open Questions

None.
