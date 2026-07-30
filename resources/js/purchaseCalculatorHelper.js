/**
 * Shared helper for purchase line-total input validation and reverse calculation
 * Used by Alpine cart and tests
 */

export const purchaseCalculatorHelper = {
  /**
   * Validate raw input string before parsing
   * Returns { isValid, error, numericValue }
   */
  validateLineTotalInput(rawValue) {
    // Blank input
    if (rawValue === null || rawValue === undefined || rawValue === '' || rawValue.trim() === '') {
      return { isValid: false, error: 'Total baris harus diisi.', numericValue: null };
    }

    // Check if input is numeric (before parsing)
    // Valid inputs: numbers with optional thousand separators (dots) and decimal comma
    // Examples: "1000", "1.000", "1.000,50", "1000,50"
    const cleaned = rawValue.toString().trim();

    // Only allow digits, commas, dots, and minus sign
    const validPattern = /^-?[\d.,]+$/;
    if (!validPattern.test(cleaned)) {
      return { isValid: false, error: 'Total baris harus berupa angka.', numericValue: null };
    }

    // Parse the value
    const numericValue = this.parseCurrencyInput(cleaned);

    // Check for negative (after parsing)
    if (numericValue < 0) {
      return { isValid: false, error: 'Total baris tidak boleh negatif.', numericValue: null };
    }

    return { isValid: true, error: null, numericValue };
  },

  /**
   * Parse Indonesian currency format
   * Handles: 1000, 1.000, 1.000,50, 1000,50
   * Returns numeric value, or 0 for invalid input
   */
  parseCurrencyInput(value) {
    if (value === null || value === undefined) return 0;
    const cleaned = value
        .toString()
        .replace(/[^0-9,.-]/g, '');
    const withoutThousands = cleaned.replace(/\./g, '');
    const normalized = withoutThousands.replace(',', '.');
    const parsed = parseFloat(normalized);
    return isNaN(parsed) ? 0 : parsed;
  },

  /**
   * Reverse-calculate unit price from total line amount
   * Matches window.purchaseCalculator.calculateItemSubtotalAndTax() contract.
   *
   * Total Baris is always the final line amount including tax, after line discount, before global discount/shipping.
   *
   * For isTaxIncluded === true:
   *   stored unit_price is gross/tax-inclusive
   *   forward: total = unitPrice (already includes tax in the stored price)
   *   reverse: effectivePrice = totalAmount / qty
   *
   * For isTaxIncluded === false:
   *   stored unit_price is net/tax-exclusive
   *   forward: total = (unitPrice * qty) * (1 + taxRate)
   *   reverse: effectivePrice = totalAmount / (1 + taxRate) / qty
   *
   * Then reverse any fixed or percentage line discount to derive stored unit_price.
   */
  calculateUnitPriceFromTotal(totalAmount, qty, discount, discountType, taxRate, isTaxIncluded) {
    const qtyEffective = Math.max(1, qty);

    // Calculate effective price per unit (after discount is applied)
    let effectivePrice;
    if (isTaxIncluded) {
      // Total already includes tax, and stored unit_price is gross (tax-inclusive)
      // total = unitPrice * qty, so effectivePrice = total / qty
      effectivePrice = totalAmount / qtyEffective;
    } else {
      // Total includes tax, but stored unit_price is net (tax-exclusive)
      // total = (unitPrice * qty) * (1 + taxRate)
      // So: unitPrice * qty = total / (1 + taxRate)
      // And: effectivePrice = total / (1 + taxRate) / qty
      effectivePrice = (totalAmount / (1 + taxRate)) / qtyEffective;
    }

    // Reverse the discount to get stored unit_price
    let basePrice = 0;
    if (discountType === 'percentage') {
      const pct = Math.min(100, Math.max(0, discount)) / 100;
      if (pct >= 1.0) {
        basePrice = 0;
      } else {
        basePrice = effectivePrice / (1 - pct);
      }
    } else {
      basePrice = effectivePrice + parseFloat(discount || 0);
    }

    return Math.round(basePrice * 100) / 100;
  }
};

// Expose to global scope for Alpine components
if (typeof window !== 'undefined') {
  window.purchaseCalculatorHelper = purchaseCalculatorHelper;
}

export default purchaseCalculatorHelper;
