/**
 * End-to-end DOM regression test for POS Kas Bon payment method rows.
 *
 * Executes the ACTUAL production modules:
 * - public/js/financial-input.js
 * - public/js/pos-kas-bon-collection.js
 *
 * Verifies all requirements:
 * 1. Jumlah is numeric amount applied to debt (enhanced by FinancialInput).
 * 2. Keterangan is optional free text for every method (including CASH, accepting e.g. "Cicilan kedua").
 *    No monetary formatting, numeric input restrictions, or tender calculations on this field.
 * 3. Transfer provides separate Nomor Referensi text input, required when requires_reference is true.
 *    Preserves evidence upload.
 * 4. Keterangan and transfer reference are saved separately without substitution.
 * 5. Uang Tunai Diterima and Kembalian Tunai removed; entered cash Jumlah is cash received and applied, zero change.
 * 6. State preservation of amounts, descriptions, references, and evidence across adding/removing methods.
 *
 * Run with: node tests/js/pos-kas-bon-collection-state.test.cjs
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { createDOMEnvironment } = require('./lib/dom-harness.cjs');

let passed = 0;
function check(label, actual, expected) {
    assert.strictEqual(actual, expected, label + ': expected ' + JSON.stringify(expected) + ' got ' + JSON.stringify(actual));
    passed++;
}

async function runTests() {
    // 1. Initialize DOM Environment
    const { document, jQuery, DOMElement } = createDOMEnvironment();
    global.HTMLElement = DOMElement;

    global.window = {
        document: document,
        addEventListener: (t, fn) => document.addEventListener(t, fn),
        dispatchEvent: (e) => document.body.dispatchEvent(e),
        CustomEvent: class CustomEvent {
            constructor(type, opt) {
                this.type = type;
                this.detail = opt?.detail;
            }
        }
    };
    global.document = document;
    global.jQuery = jQuery;
    global.$ = jQuery;

    // Register all elements present in the Kas Bon modal
    const elementIds = [
        'pos-btn-bayar-kas-bon', 'pos-menu-bayar-kas-bon', 'pos-kas-bon-modal',
        'pos-kb-candidates-view', 'pos-kb-review-view', 'pos-kb-success-view',
        'pos-kb-modal-footer', 'pos-kb-global-alert', 'pos-kb-review-error',
        'pos-kb-review-error-msg', 'pos-kb-review-trx-code', 'pos-kb-detail-customer',
        'pos-kb-detail-receipt', 'pos-kb-detail-note', 'pos-kb-items-body',
        'pos-kb-summary-total', 'pos-kb-summary-paid', 'pos-kb-summary-due',
        'pos-kb-methods-body', 'pos-kb-method-select', 'pos-kb-add-method-btn',
        'pos-kb-calc-total-payment', 'pos-kb-calc-remaining-debt', 'pos-kb-calc-cash-tender',
        'pos-kb-calc-change', 'pos-kb-submit-payment', 'pos-kb-input-note',
        'pos-kb-res-ref', 'pos-kb-res-amount', 'pos-kb-res-change-row',
        'pos-kb-res-change', 'pos-kb-res-remaining', 'pos-kb-search-input',
        'pos-kb-search-clear', 'pos-kb-refresh-candidates', 'pos-kb-prev-page',
        'pos-kb-next-page', 'pos-kb-pagination-info', 'pos-kb-back-to-candidates',
        'pos-kb-reload-review', 'pos-kb-review-error-refresh', 'pos-kb-btn-print-receipt',
        'pos-kb-btn-finish'
    ];
    elementIds.forEach(id => {
        const tag = id.includes('select') ? 'select' : (id.includes('body') ? 'tbody' : 'div');
        document.registerElement(id, document.createElement(tag));
    });

    // Mock review data for TRX #123 (live debt Rp6.500.000)
    const mockReviewData = {
        id: 123,
        code: 'TRX-2026-001',
        receipt_number: 'REC-001',
        live_due: 6500000,
        current_total: 6500000,
        effective_paid: 0,
        fingerprint: 'fp-auth-12345',
        customer: { name: 'Budi Santoso' },
        notes: 'Order kas bon toko',
        items: []
    };

    const mockPaymentMethods = [
        { id: 1, name: 'Tunai', is_cash: true, requires_reference: false },
        { id: 2, name: 'Transfer Bank BCA', is_cash: false, requires_reference: true },
        { id: 3, name: 'QRIS', is_cash: false, requires_reference: false }
    ];

    let lastSubmittedPayload = null;
    let lastSubmittedUrl = null;

    // Mock fetch for API requests
    global.fetch = async (url, options) => {
        if (url.includes('/payment-methods')) {
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    methods: mockPaymentMethods
                })
            };
        }
        if (url.includes('/candidates')) {
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    data: [],
                    total: 0,
                    current_page: 1,
                    last_page: 1
                })
            };
        }
        if (url.includes('/review')) {
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    success: true,
                    data: mockReviewData
                })
            };
        }
        if (url.includes('/collect')) {
            lastSubmittedUrl = url;
            lastSubmittedPayload = JSON.parse(options.body);
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    success: true,
                    reference: 'COL-REC-2026',
                    amount_collected: lastSubmittedPayload.amount_to_pay,
                    remaining_debt: Math.max(0, 6500000 - lastSubmittedPayload.amount_to_pay),
                    change_total: 0,
                    receipt_url: '/pos/receipt/col-123'
                })
            };
        }
        return {
            ok: true,
            status: 200,
            json: async () => ({})
        };
    };

    // Load actual production modules
    require(path.join(__dirname, '..', '..', 'public', 'js', 'financial-input.js'));
    require(path.join(__dirname, '..', '..', 'public', 'js', 'pos-kas-bon-collection.js'));

    const F = global.window.FinancialInput;
    check('FinancialInput module initialized', typeof F.enhance, 'function');
    check('PosKasBonCollection module initialized', typeof global.window.PosKasBonCollection.loadReview, 'function');

    // -------------------------------------------------------------
    // Step 1: Open modal and load review for TRX #123 through the production code
    // -------------------------------------------------------------
    await global.window.PosKasBonCollection.openModal();
    await global.window.PosKasBonCollection.loadReview(123);

    const methodsBody = document.getElementById('pos-kb-methods-body');
    const rowsInitial = methodsBody.querySelectorAll('tr[data-client-id]');
    check('1. Initial methods row count is 1 (Tunai)', rowsInitial.length, 1);

    const cashRow = rowsInitial[0];
    const cashAmtInput = cashRow.querySelector('.js-method-amount');
    const cashNoteInput = cashRow.querySelector('.js-method-note');
    const cashTenderInput = cashRow.querySelector('.js-method-tender');
    const cashRefInput = cashRow.querySelector('.js-method-reference');

    check('1. Cash amount input exists', !!cashAmtInput, true);
    check('1. Cash amount display initialized to 6.500.000', cashAmtInput.value, '6.500.000');
    check('1. Cash note input exists', !!cashNoteInput, true);
    check('1. Cash note placeholder is Keterangan', cashNoteInput.getAttribute('placeholder'), 'Keterangan');
    check('1. Cash tender input does NOT exist (removed from flow)', cashTenderInput, null);
    check('1. Cash reference input does NOT exist (non-cash only)', cashRefInput, null);
    check('1. Cash note does NOT have data-financial-amount', cashNoteInput.hasAttribute('data-financial-amount'), false);

    check('1. Summary Total Pembayaran is Rp6.500.000', document.getElementById('pos-kb-calc-total-payment').textContent, 'Rp6.500.000');
    check('1. Summary Sisa Hutang is Rp0', document.getElementById('pos-kb-calc-remaining-debt').textContent, 'Rp0');
    check('1. Submit button is enabled', document.getElementById('pos-kb-submit-payment').disabled, false);

    // -------------------------------------------------------------
    // Step 2: Cashier enters Cash amount Rp1.000.000 and Keterangan 'Cicilan kedua'
    // -------------------------------------------------------------
    F.setCanonicalValue(cashAmtInput, '1000000');
    cashAmtInput.dispatchEvent({ type: 'financial-amount:change', target: cashAmtInput });

    cashNoteInput.value = 'Cicilan kedua';
    cashNoteInput.dispatchEvent({ type: 'input', target: cashNoteInput });

    check('2. Cash amount input display is 1.000.000', cashAmtInput.value, '1.000.000');
    check('2. Cash note input accepted free text "Cicilan kedua"', cashNoteInput.value, 'Cicilan kedua');
    check('2. Summary Total Pembayaran is Rp1.000.000', document.getElementById('pos-kb-calc-total-payment').textContent, 'Rp1.000.000');
    check('2. Summary Sisa Hutang is Rp5.500.000', document.getElementById('pos-kb-calc-remaining-debt').textContent, 'Rp5.500.000');
    // Note text does not affect payment totals or cash calculations
    check('2. Note text did not change payment amount', document.getElementById('pos-kb-calc-total-payment').textContent, 'Rp1.000.000');

    // -------------------------------------------------------------
    // Step 3: Cashier selects Transfer and clicks #pos-kb-add-method-btn
    // EXECUTES THE ACTUAL PRODUCTION EVENT HANDLER!
    // -------------------------------------------------------------
    const methodSelect = document.getElementById('pos-kb-method-select');
    methodSelect.value = '2'; // Method ID 2: Transfer Bank BCA (requires_reference = true)

    const addBtn = document.getElementById('pos-kb-add-method-btn');
    addBtn.click(); // Triggers the real production click listener

    const rowsAfterAddTransfer = methodsBody.querySelectorAll('tr[data-client-id]');
    check('3. Rows count after adding Transfer is 2', rowsAfterAddTransfer.length, 2);

    const cashRowAfterTransfer = rowsAfterAddTransfer[0];
    const cashAmtAfterTransfer = cashRowAfterTransfer.querySelector('.js-method-amount');
    const cashNoteAfterTransfer = cashRowAfterTransfer.querySelector('.js-method-note');

    // CRITICAL ASSERTION: The cash values must NOT have been reset to zero or blank!
    check('3. [STATE PRESERVATION] Cash amount preserved as 1.000.000', cashAmtAfterTransfer.value, '1.000.000');
    check('3. [STATE PRESERVATION] Cash note preserved as "Cicilan kedua"', cashNoteAfterTransfer.value, 'Cicilan kedua');

    const transferRow = rowsAfterAddTransfer[1];
    const transferAmtInput = transferRow.querySelector('.js-method-amount');
    const transferNoteInput = transferRow.querySelector('.js-method-note');
    const transferRefInput = transferRow.querySelector('.js-method-reference');
    const transferAttachControl = transferRow.querySelector('.method-attachment-control');

    check('3. Transfer amount defaulted to remaining needed 5.500.000', transferAmtInput.value, '5.500.000');
    check('3. Transfer note input exists with placeholder Keterangan', transferNoteInput.getAttribute('placeholder'), 'Keterangan');
    check('3. Transfer reference input exists with placeholder Nomor Referensi *', transferRefInput.getAttribute('placeholder'), 'Nomor Referensi *');
    check('3. Transfer evidence upload control exists', !!transferAttachControl, true);
    check('3. Summary Total Pembayaran is Rp6.500.000 (1M + 5.5M)', document.getElementById('pos-kb-calc-total-payment').textContent, 'Rp6.500.000');
    check('3. Summary Sisa Hutang is Rp0', document.getElementById('pos-kb-calc-remaining-debt').textContent, 'Rp0');

    // Transfer requires reference according to payment method rules, and it is empty -> submit disabled!
    const submitBtn = document.getElementById('pos-kb-submit-payment');
    check('3. Submit button disabled because required reference is empty', submitBtn.disabled, true);

    // -------------------------------------------------------------
    // Step 4: Cashier edits Transfer amount to Rp500.000, Keterangan to 'Titipan transfer BCA', and Nomor Referensi to 'TRF-BCA-987654'
    // -------------------------------------------------------------
    F.setCanonicalValue(transferAmtInput, '500000');
    transferAmtInput.dispatchEvent({ type: 'financial-amount:change', target: transferAmtInput });

    transferNoteInput.value = 'Titipan transfer BCA';
    transferNoteInput.dispatchEvent({ type: 'input', target: transferNoteInput });

    transferRefInput.value = 'TRF-BCA-987654';
    transferRefInput.dispatchEvent({ type: 'input', target: transferRefInput });

    check('4. Transfer amount display is 500.000', transferAmtInput.value, '500.000');
    check('4. Transfer note value is "Titipan transfer BCA"', transferNoteInput.value, 'Titipan transfer BCA');
    check('4. Transfer reference value is "TRF-BCA-987654"', transferRefInput.value, 'TRF-BCA-987654');
    check('4. Summary Total Pembayaran is Rp1.500.000 (1M + 500k)', document.getElementById('pos-kb-calc-total-payment').textContent, 'Rp1.500.000');
    check('4. Summary Sisa Hutang is Rp5.000.000', document.getElementById('pos-kb-calc-remaining-debt').textContent, 'Rp5.000.000');
    // Now that reference is filled, submit button is enabled!
    check('4. Submit button enabled after filling required reference', submitBtn.disabled, false);

    // -------------------------------------------------------------
    // Step 5: Cashier selects QRIS and clicks #pos-kb-add-method-btn
    // EXECUTES THE ACTUAL PRODUCTION EVENT HANDLER!
    // -------------------------------------------------------------
    methodSelect.value = '3'; // Method ID 3: QRIS
    addBtn.click(); // Triggers the real production click listener

    const rowsAfterAddQris = methodsBody.querySelectorAll('tr[data-client-id]');
    check('5. Rows count after adding QRIS is 3', rowsAfterAddQris.length, 3);

    const cashRowStep5 = rowsAfterAddQris[0];
    const transferRowStep5 = rowsAfterAddQris[1];
    const qrisRow = rowsAfterAddQris[2];

    // CRITICAL ASSERTION: All previous rows must preserve their entered values!
    check('5. [STATE PRESERVATION] Cash amount preserved as 1.000.000', cashRowStep5.querySelector('.js-method-amount').value, '1.000.000');
    check('5. [STATE PRESERVATION] Cash note preserved as "Cicilan kedua"', cashRowStep5.querySelector('.js-method-note').value, 'Cicilan kedua');
    check('5. [STATE PRESERVATION] Transfer amount preserved as 500.000', transferRowStep5.querySelector('.js-method-amount').value, '500.000');
    check('5. [STATE PRESERVATION] Transfer note preserved as "Titipan transfer BCA"', transferRowStep5.querySelector('.js-method-note').value, 'Titipan transfer BCA');
    check('5. [STATE PRESERVATION] Transfer reference preserved as "TRF-BCA-987654"', transferRowStep5.querySelector('.js-method-reference').value, 'TRF-BCA-987654');
    check('5. QRIS amount defaulted to unallocated needed 5.000.000', qrisRow.querySelector('.js-method-amount').value, '5.000.000');

    // -------------------------------------------------------------
    // Step 6: Cashier removes QRIS via its .js-remove-method button
    // EXECUTES THE ACTUAL PRODUCTION EVENT HANDLER!
    // -------------------------------------------------------------
    const qrisRemoveBtn = qrisRow.querySelector('.js-remove-method');
    check('6. QRIS remove button exists', !!qrisRemoveBtn, true);
    qrisRemoveBtn.click(); // Triggers the real production click listener

    const rowsAfterRemoveQris = methodsBody.querySelectorAll('tr[data-client-id]');
    check('6. Rows count after removing QRIS is 2', rowsAfterRemoveQris.length, 2);

    const cashRowStep6 = rowsAfterRemoveQris[0];
    const transferRowStep6 = rowsAfterRemoveQris[1];

    // CRITICAL ASSERTION: Removing another row must preserve all remaining rows' values!
    check('6. [STATE PRESERVATION] Cash amount preserved as 1.000.000', cashRowStep6.querySelector('.js-method-amount').value, '1.000.000');
    check('6. [STATE PRESERVATION] Cash note preserved as "Cicilan kedua"', cashRowStep6.querySelector('.js-method-note').value, 'Cicilan kedua');
    check('6. [STATE PRESERVATION] Transfer amount preserved as 500.000', transferRowStep6.querySelector('.js-method-amount').value, '500.000');
    check('6. [STATE PRESERVATION] Transfer note preserved as "Titipan transfer BCA"', transferRowStep6.querySelector('.js-method-note').value, 'Titipan transfer BCA');
    check('6. [STATE PRESERVATION] Transfer reference preserved as "TRF-BCA-987654"', transferRowStep6.querySelector('.js-method-reference').value, 'TRF-BCA-987654');

    check('6. Final Total Pembayaran is Rp1.500.000', document.getElementById('pos-kb-calc-total-payment').textContent, 'Rp1.500.000');
    check('6. Final Sisa Hutang is Rp5.000.000', document.getElementById('pos-kb-calc-remaining-debt').textContent, 'Rp5.000.000');

    // -------------------------------------------------------------
    // Step 7: Cashier clicks Submit Payment #pos-kb-submit-payment
    // EXECUTES THE ACTUAL PRODUCTION SUBMIT HANDLER!
    // -------------------------------------------------------------
    check('7. Submit button is enabled', submitBtn.disabled, false);

    // Click submit button - triggers production submitPayment()
    submitBtn.click();
    await new Promise(resolve => setTimeout(resolve, 50));

    check('7. Submit API endpoint called', lastSubmittedUrl, '/pos/kas-bon/123/collect');
    check('7. [PAYLOAD ASSERTION] Submitted amount_to_pay is 1500000', lastSubmittedPayload.amount_to_pay, 1500000);
    check('7. [PAYLOAD ASSERTION] Submitted fingerprint matches review', lastSubmittedPayload.fingerprint, 'fp-auth-12345');
    check('7. [PAYLOAD ASSERTION] Submitted methods length is 2', lastSubmittedPayload.methods.length, 2);

    // Method 1: Cash
    const subCash = lastSubmittedPayload.methods[0];
    check('7. [PAYLOAD ASSERTION] Cash payment_method_id is 1', subCash.payment_method_id, 1);
    check('7. [PAYLOAD ASSERTION] Cash amount is 1000000', subCash.amount, 1000000);
    check('7. [PAYLOAD ASSERTION] Cash tender_amount is 1000000 (equal to amount)', subCash.tender_amount, 1000000);
    check('7. [PAYLOAD ASSERTION] Cash note is "Cicilan kedua"', subCash.note, 'Cicilan kedua');
    check('7. [PAYLOAD ASSERTION] Cash reference is null', subCash.reference, null);

    // Method 2: Transfer
    const subTransfer = lastSubmittedPayload.methods[1];
    check('7. [PAYLOAD ASSERTION] Transfer payment_method_id is 2', subTransfer.payment_method_id, 2);
    check('7. [PAYLOAD ASSERTION] Transfer amount is 500000', subTransfer.amount, 500000);
    check('7. [PAYLOAD ASSERTION] Transfer tender_amount is 500000', subTransfer.tender_amount, 500000);
    check('7. [PAYLOAD ASSERTION] Transfer note is "Titipan transfer BCA"', subTransfer.note, 'Titipan transfer BCA');
    check('7. [PAYLOAD ASSERTION] Transfer reference is "TRF-BCA-987654"', subTransfer.reference, 'TRF-BCA-987654');
    check('7. [SEPARATE STORAGE] Transfer note and reference are NOT substituted', subTransfer.note !== subTransfer.reference, true);

    // Success view populated by production showSuccessView
    check('7. Success view amount collected is Rp1.500.000', document.getElementById('pos-kb-res-amount').textContent, 'Rp1.500.000');
    check('7. Success view remaining debt is Rp5.000.000', document.getElementById('pos-kb-res-remaining').textContent, 'Rp5.000.000');

    console.log(`\nOK: All ${passed} assertions passed against actual production code.`);
}

runTests().catch(err => {
    console.error('Test failed with error:', err);
    process.exit(1);
});
