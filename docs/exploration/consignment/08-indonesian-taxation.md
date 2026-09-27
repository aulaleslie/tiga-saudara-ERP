# Preliminary Indonesian Taxation Assessment

Status: exploratory tax-system analysis, not legal or tax advice. The final model must be reviewed by the company's qualified Indonesian tax adviser against its contracts, goods, parties, invoicing practices, and current regulations.

## Executive conclusion

The proposed business flow is broadly compatible with Indonesian consignment treatment:

```text
Receive deposited goods -> custody record, no ordinary supplier bill
Sell consigned goods     -> customer sale and supplier billing eligibility
Pay supplier             -> later payable settlement
```

Compliance is not automatic. The ERP must not let a later manual billing confirmation arbitrarily determine the legal sale, revenue-recognition, supplier-invoice, or VAT period.

## Regulatory basis reviewed

PP 44 Tahun 2022, Pasal 24 distinguishes the consignment delivery moment for the consignor and consignee:

- for the consignor, delivery occurs when the price is recognized as receivable/income or when the PKP consignor issues the sales invoice, consistently with generally accepted accounting principles;
- for the consignee, delivery occurs at the applicable customer-delivery, carrier handoff, recognition, or invoicing event.

PP 44 Tahun 2022, Pasal 26 requires a PKP to create the Faktur Pajak at the applicable delivery event and addresses late creation. Official source: [DJP — PP 44 Tahun 2022](https://www.pajak.go.id/id/peraturan/penerapan-terhadap-pajak-pertambahan-nilai-barang-dan-jasa-dan-pajak-penjualan-atas).

PER-11/PJ/2025 permits a combined Faktur Pajak for qualifying deliveries to the same buyer within one calendar month, made no later than the end of that month. Official source: [DJP — PER-11/PJ/2025](https://www.pajak.go.id/sites/default/files/lampiran/PER-11_PJ_2025_0.pdf).

For ordinary non-luxury goods under PMK 131 Tahun 2024, VAT is generally calculated at 12% over a DPP nilai lain of 11/12 of the price, subject to the regulation's scope and exceptions. Luxury goods and specially regulated supplies may differ. Official source: [Kemenkeu JDIH — PMK 131 Tahun 2024](https://jdih.kemenkeu.go.id/dok/pmk-131-tahun-2024/summary).

## Required separation of events

The ERP must preserve at least these dates independently:

| Event | Meaning |
|---|---|
| Consignment receipt approval date | Goods enter physical custody; no ordinary supplier bill |
| Customer sale/dispatch date | Actual consignee delivery/output-VAT event candidate |
| Billing allocation confirmation date | Internal ownership allocation and verification |
| Consignor recognition/invoice date | Supplier billing and consignor delivery event candidate |
| Supplier Faktur Pajak date | Supplier tax-document period |
| Purchase/payment due date | Commercial settlement; not the VAT event by itself |

A user may perform confirmation after the sale, but the system must retain the original source date and identify cross-period differences.

## Two independent taxable relationships

### Company to customer

The existing Sales or POS workflow remains responsible for the customer sale and output VAT. Its persisted source location determines whether the quantity is consignment-related, but supplier allocation must never alter the customer tax already calculated.

### Supplier to company

The generated consignment Purchase represents the supplier bill. Its VAT treatment depends on:

- supplier PKP status and tax identity;
- company PKP status;
- whether the goods/supply are taxable, exempt, not collected, or otherwise specially treated;
- DPP method and applicable rate;
- validity and timing of the supplier Faktur Pajak;
- whether input VAT is creditable by the company.

## PKP/non-PKP matrix

| Company | Supplier | Required system posture |
|---|---|---|
| PKP | PKP | Record supplier Faktur Pajak and separately determine input-tax creditability |
| PKP | Non-PKP | Do not invent supplier VAT/Faktur Pajak; retain non-taxable supplier-bill treatment |
| Non-PKP | PKP | Preserve VAT charged by the supplier but mark it non-creditable and post under approved cost policy |
| Non-PKP | Non-PKP | No supplier VAT/Faktur Pajak |

Therefore, `setting.is_pkp = false` must not automatically erase VAT from a supplier bill. A PKP supplier can charge VAT to a non-PKP buyer even though that buyer cannot credit it.

## Minimum tax data for urgent MVP

### Supplier master or effective snapshot

- PKP/non-PKP status;
- NPWP/tax identity required for invoices;
- effective date or at least an immutable snapshot on the bill.

### Billing allocation and generated Purchase

- company and supplier;
- actual source sale/dispatch reference and date;
- allocation confirmation date;
- supplier invoice number/date;
- Faktur Pajak number/date when applicable;
- tax period;
- line price before tax;
- DPP method and DPP amount;
- statutory VAT rate;
- VAT amount;
- tax-included indicator;
- creditable/non-creditable input VAT classification;
- original document references for returns or corrections.

## Urgent-release controls

1. Use the actual persisted Sales dispatch or POS completion/source allocation as the sale evidence.
2. Do not allow confirmation quantity beyond sold-from-consignment and supplier-received balances.
3. Default the tax period from source and supplier-invoice facts; do not default only from confirmation time.
4. Warn or block cross-period confirmation until an authorized tax user resolves it.
5. Permit monthly supplier aggregation only within the same company, supplier, currency, compatible tax treatment, and calendar tax period.
6. Require supplier invoice/Faktur Pajak references before the bill reaches the configured tax-ready state.
7. Preserve rate and DPP snapshots; do not recompute historical bills from current tax-master values.
8. Separate creditable input VAT from non-creditable VAT included in cost.
9. Require privileged, audited correction rather than silent backdating.
10. Link post-billing returns to the original Purchase, allocation, and tax document.

## Returns and corrections

Before supplier billing, a customer return reduces eligible sold quantity and restores the original ownership provenance.

After supplier billing, the system must preserve the original bill and create a linked credit/Purchase Return or other adviser-approved correction. When a Faktur Pajak has already been issued or reported, the workflow may also require a nota retur, replacement/cancellation, and tax-return adjustment. This cannot safely be implemented as deletion of the original allocation.

## Release recommendation

The urgent MVP can remain compact if it supports one supplier per confirmation and reuses existing Sales/POS tax behavior. Tax compliance still requires the date fields, supplier PKP status, DPP/VAT snapshots, creditability classification, cross-period controls, and return linkage above.

Before implementation approval, obtain written confirmation from the company's tax adviser for these worked cases:

1. PKP company buying consigned taxable non-luxury goods from a PKP supplier;
2. PKP company receiving from a non-PKP supplier;
3. non-PKP company receiving from a PKP supplier;
4. customer sale and supplier billing in different calendar months;
5. customer return before supplier billing;
6. customer return after supplier invoice and Faktur Pajak;
7. monthly combined supplier invoice/Faktur Pajak;
8. luxury or specially treated goods, if the company handles them.
