import purchaseCalculatorHelper from '../../resources/js/purchaseCalculatorHelper.js';

// Simple test runner
const tests = [];
let passedCount = 0;
let failedCount = 0;

function test(name, fn) {
  tests.push({ name, fn });
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function assertEqual(actual, expected, message) {
  if (actual !== expected) {
    throw new Error(`${message || 'Assertion failed'}: expected ${expected}, got ${actual}`);
  }
}

// ============ Tests ============

test('blank input should show error', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('');
  assert(!result.isValid, 'Should be invalid');
  assertEqual(result.error, 'Total baris harus diisi.', 'Error message');
});

test('whitespace-only input should show error', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('   ');
  assert(!result.isValid, 'Should be invalid');
  assertEqual(result.error, 'Total baris harus diisi.', 'Error message');
});

test('non-numeric input abc should show error', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('abc');
  assert(!result.isValid, 'Should be invalid');
  assertEqual(result.error, 'Total baris harus berupa angka.', 'Error message');
});

test('non-numeric input with symbols should show error', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('12@34');
  assert(!result.isValid, 'Should be invalid');
  assertEqual(result.error, 'Total baris harus berupa angka.', 'Error message');
});

test('negative input should show error', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('-100');
  assert(!result.isValid, 'Should be invalid');
  assertEqual(result.error, 'Total baris tidak boleh negatif.', 'Error message');
});

test('valid numeric input 1000 should be accepted', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('1000');
  assert(result.isValid, 'Should be valid');
  assertEqual(result.numericValue, 1000, 'Numeric value');
});

test('valid Indonesian format 1.000 should be accepted', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('1.000');
  assert(result.isValid, 'Should be valid');
  assertEqual(result.numericValue, 1000, 'Numeric value');
});

test('valid Indonesian format 1.000,50 should be accepted', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('1.000,50');
  assert(result.isValid, 'Should be valid');
  assertEqual(result.numericValue, 1000.50, 'Numeric value');
});

test('valid format 1000,50 should be accepted', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('1000,50');
  assert(result.isValid, 'Should be valid');
  assertEqual(result.numericValue, 1000.50, 'Numeric value');
});

test('zero should be accepted', () => {
  const result = purchaseCalculatorHelper.validateLineTotalInput('0');
  assert(result.isValid, 'Should be valid');
  assertEqual(result.numericValue, 0, 'Numeric value');
});

test('parseCurrencyInput should handle Indonesian thousands', () => {
  const result = purchaseCalculatorHelper.parseCurrencyInput('12.345.678,99');
  assertEqual(result, 12345678.99, 'Should parse correctly');
});

test('calculateUnitPriceFromTotal with no tax, no discount', () => {
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    1000,      // totalAmount
    1,         // qty
    0,         // discount
    'fixed',   // discountType
    0,         // taxRate
    false      // isTaxIncluded (tax excluded)
  );
  assertEqual(unitPrice, 1000, 'Unit price should be 1000');
});

test('calculateUnitPriceFromTotal with tax excluded (11%) - legacy interpretation', () => {
  // Corrected per new spec: totalAmount is always FINAL amount (after tax)
  // If final total is 1110 (after 11% tax), unit price (net) should be 1000
  // Calculation: 1110 / 1.11 = 1000
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    1110,      // totalAmount (final, includes tax)
    1,         // qty
    0,         // discount
    'fixed',   // discountType
    0.11,      // taxRate
    false      // isTaxIncluded (tax excluded from stored price)
  );
  assertEqual(unitPrice, 1000, 'Unit price should be 1000');
});

test('calculateUnitPriceFromTotal with tax included', () => {
  // If total is 1110 (including 11% tax), and stored price is gross (tax-inclusive)
  // Then unit price = 1110
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    1110,      // totalAmount (final, includes tax)
    1,         // qty
    0,         // discount
    'fixed',   // discountType
    0.11,      // taxRate
    true       // isTaxIncluded (stored price includes tax)
  );
  assertEqual(unitPrice, 1110, 'Unit price should be 1110');
});

test('calculateUnitPriceFromTotal with fixed discount', () => {
  // If unit price is 1000, qty is 1, discount is 100, total should be 900
  // Reverse: if total is 900 and discount is 100, unit price should be 1000
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    900,       // totalAmount
    1,         // qty
    100,       // discount
    'fixed',   // discountType
    0,         // taxRate
    false      // isTaxIncluded
  );
  assertEqual(unitPrice, 1000, 'Unit price should be 1000');
});

test('calculateUnitPriceFromTotal with percentage discount (10%)', () => {
  // If unit price is 1000, qty is 1, discount is 10%, total should be 900
  // Reverse: if total is 900 and discount is 10%, unit price should be 1000
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    900,       // totalAmount
    1,         // qty
    10,        // discount
    'percentage', // discountType
    0,         // taxRate
    false      // isTaxIncluded
  );
  assertEqual(unitPrice, 1000, 'Unit price should be 1000');
});

test('calculateUnitPriceFromTotal with 100% discount should give zero', () => {
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    0,         // totalAmount
    1,         // qty
    100,       // discount
    'percentage', // discountType
    0,         // taxRate
    false      // isTaxIncluded
  );
  assertEqual(unitPrice, 0, 'Unit price should be 0');
});

test('calculateUnitPriceFromTotal with multi-quantity', () => {
  // If unit price is 1000, qty is 2, total should be 2000
  // Reverse: if total is 2000 and qty is 2, unit price should be 1000
  const unitPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    2000,      // totalAmount
    2,         // qty
    0,         // discount
    'fixed',   // discountType
    0,         // taxRate
    false      // isTaxIncluded
  );
  assertEqual(unitPrice, 1000, 'Unit price should be 1000');
});

// === Tests from user spec: 11% tax cases ===

test('tax-included 11% tax: final total 1110, qty 1, no discount → derived unit price 1110', () => {
  const derivedPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    1110,      // finalTotal (includes tax)
    1,         // qty
    0,         // discount
    'fixed',   // discountType
    0.11,      // 11% tax rate
    true       // isTaxIncluded
  );
  assertEqual(derivedPrice, 1110, 'Derived unit price should be 1110');
});

test('tax-excluded 11% tax: final total 1110, qty 1, no discount → derived unit price 1000', () => {
  const derivedPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    1110,      // finalTotal (includes tax)
    1,         // qty
    0,         // discount
    'fixed',   // discountType
    0.11,      // 11% tax rate
    false      // isTaxIncluded
  );
  assertEqual(derivedPrice, 1000, 'Derived unit price should be 1000');
});

test('multi-quantity with 11% tax-included and no discount', () => {
  const derivedPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    2220,      // finalTotal for qty 2 at 1110 each (includes tax)
    2,         // qty
    0,         // discount
    'fixed',   // discountType
    0.11,      // 11% tax rate
    true       // isTaxIncluded
  );
  assertEqual(derivedPrice, 1110, 'Derived unit price should be 1110');
});

test('multi-quantity with 11% tax-excluded and no discount', () => {
  const derivedPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    2220,      // finalTotal for qty 2 at 1110 each (includes tax)
    2,         // qty
    0,         // discount
    'fixed',   // discountType
    0.11,      // 11% tax rate
    false      // isTaxIncluded
  );
  assertEqual(derivedPrice, 1000, 'Derived unit price should be 1000');
});

test('11% tax-excluded with fixed discount: total 1110, qty 1, discount 100 → unit price 1100', () => {
  // Forward: unitPrice=1100, discount=100 → adjustedPrice=1000 → subtotal=1000 → tax=110 → total=1110
  // Reverse: total=1110, discount=100 → should derive unitPrice=1100
  const derivedPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    1110,      // finalTotal (after discount, includes tax)
    1,         // qty
    100,       // fixed discount
    'fixed',   // discountType
    0.11,      // 11% tax rate
    false      // isTaxIncluded
  );
  assertEqual(derivedPrice, 1100, 'Derived unit price should be 1100');
});

test('11% tax-excluded with percentage discount (10%): total 999, qty 1, discount 10% → unit price 1100', () => {
  // Forward: unitPrice=1100, discount=10% → discountAmount=110 → adjustedPrice=990 → subtotal=990 → tax=108.9 → total=1098.9
  // But let's use a simpler case: unitPrice=1000, discount=10% → discountAmount=100 → adjustedPrice=900 → subtotal=900 → tax=99 → total=999
  // Reverse: total=999, discount=10% → should derive unitPrice=1000
  const derivedPrice = purchaseCalculatorHelper.calculateUnitPriceFromTotal(
    999,       // finalTotal (after discount, includes tax)
    1,         // qty
    10,        // percentage discount
    'percentage', // discountType
    0.11,      // 11% tax rate
    false      // isTaxIncluded
  );
  assertEqual(derivedPrice, 1000, 'Derived unit price should be 1000');
});

// ============ Run tests ============

console.log('Running purchaseCalculatorHelper tests...\n');

tests.forEach(({ name, fn }) => {
  try {
    fn();
    console.log(`✓ ${name}`);
    passedCount++;
  } catch (error) {
    console.error(`✗ ${name}`);
    console.error(`  ${error.message}`);
    failedCount++;
  }
});

console.log(`\n${passedCount} passed, ${failedCount} failed`);

if (failedCount > 0) {
  process.exit(1);
}
