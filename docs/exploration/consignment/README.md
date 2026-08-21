# Consignment Exploration Pack

Status: exploratory, not an implementation specification.

This pack records the current-system findings, viable solution shapes, recommended phasing, decisions still required, and focused questions for later exploration sessions. It deliberately does not depend on the current uncommitted POS Return work.

## Confirmed business meaning

For this business, consignment means:

> Goods are supplied and stocked first. The supplier bill occurs only when the goods are sold.

This establishes the first-release direction as **inbound supplier consignment**. Outbound consignment and agent/reseller workflows are not part of the known requirement.

## Recommended direction

Use the existing `locations` model as the physical custody boundary, but do not make `locations.is_consignment` the sole source of consignment truth.

A consignment relationship has at least three independent facts:

1. where the goods physically are (`location_id`);
2. which business or supplier owns them while unsold (`inventory_owner`);
3. under which agreement, commercial rules, and settlement cycle they are held (`consignment_agreement_id`).

The clarified operational shape is therefore:

- add `locations.is_consignment` as the simplest current distinction, unless a broader location taxonomy is already planned;
- allow consignment receiving only into a location where that flag is true;
- preserve supplier ownership on every approved consignment receiving line and serial;
- allow multiple suppliers' non-serialized units of the same product to share the location's physical stock bucket;
- derive consignment eligibility from actual sale sourcing: the selected dispatch location for normal Sales and the existing configured location order for POS;
- use a billing-confirmation allocation ledger to assign sold non-serialized quantities to supplier receipt balances;
- convert confirmed supplier allocations into purchases/payables without receiving stock a second time.

This preserves the user's location-first idea and reuses the current stock engine. The location answers where consignment may be held; receiving provenance and billing allocations answer who owns it and who must be billed.

## Documents

- [01-current-system-fit.md](01-current-system-fit.md): codebase facts and integration seams.
- [02-solution-options.md](02-solution-options.md): architectural possibilities and tradeoffs.
- [03-recommended-domain-model.md](03-recommended-domain-model.md): recommended conceptual model and lifecycle.
- [04-phased-exploration-roadmap.md](04-phased-exploration-roadmap.md): one reusable exploration brief per phase.
- [05-integration-and-risk-matrix.md](05-integration-and-risk-matrix.md): affected workflows, risks, and safeguards.
- [06-decision-register.md](06-decision-register.md): decisions, assumptions, and questions to settle.
- [07-confirmed-workflow.md](07-confirmed-workflow.md): the clarified receiving, allocation, billing, and PKP/non-PKP workflow.
- [08-indonesian-taxation.md](08-indonesian-taxation.md): preliminary Indonesian VAT compliance assessment and mandatory tax controls.

## Suggested next session

Start with Phase 0 in the roadmap to confirm the exact sale-to-bill rule: what counts as “sold,” how the billed supplier cost is determined, whether sold items are billed individually or periodically, normal payment terms, and how returns after billing affect the amount owed.
