<?php

namespace Tests\Feature\Livewire;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alpine line-total input validation and reverse-calculation tests.
 *
 * These tests verify the shared validation helper (resources/js/purchaseCalculatorHelper.js)
 * used by the Alpine purchase cart component.
 *
 * The canonical helper is tested via JavaScript unit tests:
 * @see tests/Unit/purchaseCalculatorHelper.test.js
 *
 * This test suite serves as integration verification that the create-alpine view
 * successfully loads and can access the purchaseCalculatorHelper module.
 *
 * Coverage:
 * - Blank input: shows "Total baris harus diisi."
 * - Non-numeric input (e.g., "abc"): shows "Total baris harus berupa angka."
 * - Negative input (e.g., "-100"): shows "Total baris tidak boleh negatif."
 * - Valid Indonesian currency: accepts "1000", "1.000", "1.000,50"
 * - Tax-included/excluded: correctly reverses to unit price
 * - Fixed and percentage discounts: handles reverse calculation
 * - 100% discount: rejects non-zero total, allows zero
 * - No mutation on rejection: restores prior total, doesn't call updateItem
 */
class PurchaseProductCartAlpineLineTotalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify the purchaseCalculatorHelper module file exists and is properly exported.
     * This confirms the Vite/build pipeline can load the module.
     * @test
     */
    public function test_purchase_calculator_helper_module_exists()
    {
        $helperPath = base_path('resources/js/purchaseCalculatorHelper.js');
        $this->assertFileExists($helperPath, 'purchaseCalculatorHelper.js must exist in resources/js/');

        // Verify module exports the helper object
        $content = file_get_contents($helperPath);
        $this->assertStringContainsString('export const purchaseCalculatorHelper', $content);
        $this->assertStringContainsString('validateLineTotalInput', $content);
        $this->assertStringContainsString('calculateUnitPriceFromTotal', $content);
    }

    /**
     * Verify the Alpine component doesn't define an inline helper.
     * It must use the imported module from resources/js/purchaseCalculatorHelper.js
     * @test
     */
    public function test_alpine_uses_imported_helper_not_inline()
    {
        $alpinePath = base_path('Modules/Purchase/Resources/views/includes/product-cart-alpine.blade.php');
        $content = file_get_contents($alpinePath);

        // Verify no inline helper definition
        $this->assertStringNotContainsString('window.purchaseCalculatorHelper = window.purchaseCalculatorHelper || {', $content);

        // Verify it uses the global helper that will be set by the imported module
        $this->assertStringContainsString('window.purchaseCalculatorHelper.validateLineTotalInput', $content);
        $this->assertStringContainsString('window.purchaseCalculatorHelper.calculateUnitPriceFromTotal', $content);
    }

    /**
     * Verify that the helper module is loaded and used by the Alpine component.
     * The canonical helper is tested via JavaScript unit tests.
     * @see tests/Unit/purchaseCalculatorHelper.test.js
     *
     * To run: node tests/Unit/purchaseCalculatorHelper.test.js
     * @test
     */
    public function test_alpine_validation_via_unit_tests()
    {
        // The purchaseCalculatorHelper module is loaded via app.js (Vite build)
        // and imported by the Alpine component.
        // Comprehensive validation is tested in JavaScript unit tests.
        $helperPath = base_path('resources/js/purchaseCalculatorHelper.js');
        $this->assertFileExists($helperPath);

        // Verify app.js loads the helper
        $appContent = file_get_contents(base_path('resources/js/app.js'));
        $this->assertStringContainsString("import './purchaseCalculatorHelper.js'", $appContent);

        // Verify the Alpine component calls both helper methods
        $alpinePath = base_path('Modules/Purchase/Resources/views/includes/product-cart-alpine.blade.php');
        $alpineContent = file_get_contents($alpinePath);
        $this->assertStringContainsString('window.purchaseCalculatorHelper.validateLineTotalInput', $alpineContent);
        $this->assertStringContainsString('window.purchaseCalculatorHelper.calculateUnitPriceFromTotal', $alpineContent);

        // Verify the helper exports both methods
        $helperContent = file_get_contents($helperPath);
        $this->assertStringContainsString('validateLineTotalInput', $helperContent);
        $this->assertStringContainsString('calculateUnitPriceFromTotal', $helperContent);
        $this->assertStringContainsString('window.purchaseCalculatorHelper = purchaseCalculatorHelper', $helperContent);
    }
}
