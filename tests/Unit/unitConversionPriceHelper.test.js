import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Loads the ACTUAL inline <script> block from the unit-configuration blade
// component and executes it in a minimal DOM-stub sandbox, so this test
// exercises the real production parsing logic rather than a hand-maintained
// copy that could drift from it.

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BLADE_PATH = path.resolve(
  __dirname,
  '../../resources/views/livewire/product/unit-configuration.blade.php'
);

function loadUnitConversionPriceHelper() {
  const bladeSource = fs.readFileSync(BLADE_PATH, 'utf8');

  const scriptMatch = bladeSource.match(/<script>([\s\S]*?)<\/script>/);
  if (!scriptMatch) {
    throw new Error('Could not find <script> block in unit-configuration.blade.php');
  }

  let scriptBody = scriptMatch[1];

  // Hook: expose the two pure functions under test on `module.exports`
  // right before the wrapping IIFE returns, by inserting one line ahead of
  // its closing `})();`. If the IIFE's shape changes such that this no
  // longer matches, fail loudly instead of silently testing nothing.
  const closingMarker = '\n    })();';
  const closingIndex = scriptBody.lastIndexOf(closingMarker);
  if (closingIndex === -1) {
    throw new Error('Could not locate IIFE closing marker to hook exports in unit-configuration.blade.php script');
  }

  const exportHook = "\n        module.exports = { extractRawValue: (v) => extractRawValue(v), toCanonicalString };\n";
  scriptBody = scriptBody.slice(0, closingIndex) + exportHook + scriptBody.slice(closingIndex);

  const moduleStub = { exports: {} };

  // Minimal DOM stub: enough for the module-level wiring (querySelectorAll,
  // MutationObserver, addEventListener, requestAnimationFrame) to run
  // without throwing. None of it is exercised by the functions under test.
  const noopEl = { querySelectorAll: () => [], dataset: {}, addEventListener: () => {} };
  const documentStub = {
    body: noopEl,
    querySelectorAll: () => [],
    addEventListener: () => {},
  };
  const windowStub = {};

  const sandbox = {
    window: windowStub,
    document: documentStub,
    module: moduleStub,
    MutationObserver: class {
      observe() {}
    },
    requestAnimationFrame: () => {},
    console,
  };

  vm.createContext(sandbox);
  vm.runInContext(scriptBody, sandbox, { filename: BLADE_PATH });

  if (typeof moduleStub.exports.extractRawValue !== 'function') {
    throw new Error('Export hook did not capture extractRawValue from unit-configuration.blade.php');
  }

  return moduleStub.exports;
}

const unitConversionPriceHelper = loadUnitConversionPriceHelper();

// extractRawValue reads `.value` off a DOM element in production; a plain
// object with a `value` property stands in for the visible <input>.
function extractRawValue(text) {
  return unitConversionPriceHelper.extractRawValue({ value: text });
}

// Simple test runner
const tests = [];
let passedCount = 0;
let failedCount = 0;

function test(name, fn) {
  tests.push({ name, fn });
}

function assertEqual(actual, expected, message) {
  if (actual !== expected) {
    throw new Error(`${message || 'Assertion failed'}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

// ============ Tests ============
// Regression coverage for raw decimal parsing: '.' must always represent the
// decimal separator while editing, never thousands grouping — even with
// more than 2 fractional digits or a whole-looking value like "1.000".
// Genuinely malformed multi-dot input (e.g. "1.2.3") is rejected rather
// than guessed at.

test('extra precision beyond 2 decimals is rounded, not thousands-grouped', () => {
  assertEqual(extractRawValue('1234.567'), '1234.57', '1234.567');
});

test('a value with 3 zero fractional digits keeps its decimal meaning', () => {
  assertEqual(extractRawValue('1.000'), '1', '1.000');
});

test('a value with 3 nonzero fractional digits keeps its decimal meaning', () => {
  assertEqual(extractRawValue('1.006'), '1.01', '1.006 rounds to 1.01');
});

test('ordinary 2-decimal raw input parses unchanged', () => {
  assertEqual(extractRawValue('1234.56'), '1234.56', '1234.56');
});

test('integer raw input with no dot parses unchanged', () => {
  assertEqual(extractRawValue('50000'), '50000', '50000');
});

test('pasted Indonesian-formatted value (thousands dot + comma decimal) parses correctly', () => {
  assertEqual(extractRawValue('1.234,56'), '1234.56', '1.234,56 -> 1234.56');
});

test('pasted US-formatted value (thousands comma + dot decimal) parses correctly', () => {
  assertEqual(extractRawValue('1,234.56'), '1234.56', '1,234.56 -> 1234.56');
});

test('comma-only decimal input parses correctly', () => {
  assertEqual(extractRawValue('1234,56'), '1234.56', '1234,56 -> 1234.56');
});

test('malformed multi-dot input "1.2.3" is rejected, not guessed', () => {
  assertEqual(extractRawValue('1.2.3'), '', '1.2.3 -> rejected');
});

test('malformed multi-dot input "1.000.000" is rejected, not guessed', () => {
  assertEqual(extractRawValue('1.000.000'), '', '1.000.000 -> rejected');
});

test('RP prefix and spaces are stripped before parsing', () => {
  assertEqual(extractRawValue('RP 1234.567'), '1234.57', 'RP 1234.567');
});

test('empty input returns empty string', () => {
  assertEqual(extractRawValue(''), '', 'empty string');
});

test('toCanonicalString rounds and drops trailing zeros', () => {
  assertEqual(unitConversionPriceHelper.toCanonicalString(1234.567), '1234.57', 'toCanonicalString(1234.567)');
  assertEqual(unitConversionPriceHelper.toCanonicalString(1.0), '1', 'toCanonicalString(1.0)');
});

// ============ Run tests ============

console.log('Running unitConversionPriceHelper tests (against the real blade <script>)...\n');

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
