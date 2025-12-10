/**
 * Purchase calculation utilities
 * Handles tax-inclusive/exclusive calculations and cart totals
 */
window.purchaseCalculator = {
    /**
     * Calculate subtotal and tax for a single item
     * @param {number} unitPrice - Unit price per item
     * @param {number} quantity - Quantity of items
     * @param {number} discount - Discount amount (fixed or percentage based on discountType)
     * @param {string} discountType - 'fixed' or 'percentage'
     * @param {number|null} taxId - Tax ID
     * @param {Array} taxes - Array of available taxes
     * @param {boolean} isTaxIncluded - Whether tax is included in price
     * @returns {Object} Calculation results
     */
    calculateItemSubtotalAndTax(unitPrice, quantity, discount, discountType, taxId, taxes, isTaxIncluded) {
        // Validate inputs
        unitPrice = Math.max(0, parseFloat(unitPrice) || 0);
        quantity = Math.max(1, parseInt(quantity) || 1);
        discount = Math.max(0, parseFloat(discount) || 0);

        // Calculate discount amount
        let discountAmount = 0;
        if (discountType === 'percentage') {
            discountAmount = unitPrice * (discount / 100);
        } else {
            discountAmount = discount;
        }

        // Ensure discount doesn't exceed unit price
        discountAmount = Math.min(discountAmount, unitPrice);
        const adjustedPrice = unitPrice - discountAmount;

        let subtotalBeforeTax = 0;
        let taxAmount = 0;
        let taxRate = 0;

        if (isTaxIncluded) {
            // Tax is included in the price
            if (taxId) {
                const tax = taxes.find(t => t.id == taxId);
                if (tax) {
                    taxRate = tax.value / 100;
                    // Calculate price excluding tax
                    const priceExTax = adjustedPrice / (1 + taxRate);
                    const taxAmountPerUnit = adjustedPrice - priceExTax;
                    taxAmount = taxAmountPerUnit * quantity;
                    subtotalBeforeTax = priceExTax * quantity;
                } else {
                    subtotalBeforeTax = adjustedPrice * quantity;
                }
            } else {
                subtotalBeforeTax = adjustedPrice * quantity;
            }
        } else {
            // Tax is not included in the price
            subtotalBeforeTax = adjustedPrice * quantity;

            if (taxId) {
                const tax = taxes.find(t => t.id == taxId);
                if (tax) {
                    taxRate = tax.value / 100;
                    taxAmount = subtotalBeforeTax * taxRate;
                }
            }
        }

        return {
            subtotalBeforeTax: subtotalBeforeTax,
            taxAmount: taxAmount,
            total: subtotalBeforeTax + taxAmount,
            taxRate: taxRate * 100,
            discountAmount: discountAmount
        };
    },

    /**
     * Calculate cart totals
     * @param {Array} cartItems - Array of cart items with calculation results
     * @param {number} globalDiscount - Global discount amount
     * @param {string} globalDiscountType - 'fixed' or 'percentage'
     * @param {number} shipping - Shipping amount
     * @returns {Object} Cart totals
     */
    calculateCartTotals(cartItems, globalDiscount, globalDiscountType, shipping) {
        globalDiscount = Math.max(0, parseFloat(globalDiscount) || 0);
        shipping = Math.max(0, parseFloat(shipping) || 0);

        let totalSubtotalBeforeTax = 0;
        let totalTaxAmount = 0;
        let totalSubtotal = 0;

        cartItems.forEach(item => {
            totalSubtotalBeforeTax += item.subtotalBeforeTax || 0;
            totalTaxAmount += item.taxAmount || 0;
            totalSubtotal += item.total || 0;
        });

        // Calculate global discount
        let globalDiscountAmount = 0;
        if (globalDiscountType === 'percentage') {
            globalDiscountAmount = totalSubtotal * (globalDiscount / 100);
        } else {
            globalDiscountAmount = globalDiscount;
        }

        const grandTotal = totalSubtotal - globalDiscountAmount + shipping;

        return {
            subtotalBeforeTax: totalSubtotalBeforeTax,
            taxTotal: totalTaxAmount,
            subtotal: totalSubtotal,
            globalDiscountAmount: globalDiscountAmount,
            shipping: shipping,
            grandTotal: grandTotal
        };
    },

    /**
     * Format currency for display
     * @param {number} amount - Amount to format
     * @param {string} currency - Currency symbol (default: 'Rp')
     * @returns {string} Formatted currency string
     */
    formatCurrency(amount, currency = 'Rp') {
        return currency + ' ' + new Intl.NumberFormat('id-ID').format(amount);
    }
};