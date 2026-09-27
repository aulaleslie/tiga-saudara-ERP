/**
 * POS Printer Manager
 *
 * Manages printer selection and direct printing for POS receipts.
 * Uses browser's native print dialog with silent printing via Chrome kiosk mode.
 * Printer selection stored in localStorage for device-specific persistence.
 */

class PosPrinterManager {
    constructor() {
        this.storageKey = 'pos_selected_printer';
        this.printerConfigKey = 'pos_printer_configured';
    }

    /**
     * Check if printer has been configured on this device
     * @returns {boolean}
     */
    isPrinterConfigured() {
        const configured = localStorage.getItem(this.printerConfigKey);
        return configured === 'true';
    }

    /**
     * Get stored printer name
     * @returns {string|null}
     */
    getStoredPrinter() {
        return localStorage.getItem(this.storageKey);
    }

    /**
     * Save printer selection to localStorage
     * @param {string} printerName - The name/identifier of the printer
     */
    savePrinter(printerName) {
        localStorage.setItem(this.storageKey, printerName);
        localStorage.setItem(this.printerConfigKey, 'true');
    }

    /**
     * Clear printer configuration
     */
    clearPrinter() {
        localStorage.removeItem(this.storageKey);
        localStorage.removeItem(this.printerConfigKey);
    }

    /**
     * Print HTML content using iframe and window.print()
     * Optimized for 80mm thermal receipt printers
     * For silent printing, Chrome must be launched with:
     * --kiosk --kiosk-printing --disable-print-preview
     * @param {string} htmlContent - The HTML content to print
     * @returns {Promise<boolean>}
     */
    print(htmlContent) {
        return new Promise((resolve, reject) => {
            try {
                // Create a hidden iframe for printing
                const printFrame = document.createElement('iframe');
                printFrame.style.position = 'fixed';
                printFrame.style.right = '0';
                printFrame.style.bottom = '0';
                printFrame.style.width = '0';
                printFrame.style.height = '0';
                printFrame.style.border = '0';
                printFrame.style.overflow = 'hidden';
                printFrame.setAttribute('srcdoc', htmlContent);

                document.body.appendChild(printFrame);

                printFrame.onload = () => {
                    try {
                        // Small delay to ensure content is rendered
                        setTimeout(() => {
                            try {
                                printFrame.contentWindow.focus();
                                printFrame.contentWindow.print();

                                // Clean up after printing
                                // Use longer delay to ensure print dialog has time to process
                                setTimeout(() => {
                                    if (document.body.contains(printFrame)) {
                                        document.body.removeChild(printFrame);
                                    }
                                    resolve(true);
                                }, 500);
                            } catch (error) {
                                if (document.body.contains(printFrame)) {
                                    document.body.removeChild(printFrame);
                                }
                                reject(error);
                            }
                        }, 250);
                    } catch (error) {
                        if (document.body.contains(printFrame)) {
                            document.body.removeChild(printFrame);
                        }
                        reject(error);
                    }
                };

                printFrame.onerror = (error) => {
                    if (document.body.contains(printFrame)) {
                        document.body.removeChild(printFrame);
                    }
                    reject(error);
                };

            } catch (error) {
                reject(error);
            }
        });
    }

    /**
     * Print directly to the configured printer
     * In kiosk mode, this will print silently to the default printer
     * @param {string} htmlContent - The HTML content to print
     * @returns {Promise<boolean>}
     */
    async printReceipt(htmlContent) {
        if (!this.isPrinterConfigured()) {
            throw new Error('Printer belum dikonfigurasi. Silakan pilih printer terlebih dahulu.');
        }

        return this.print(htmlContent);
    }

    /**
     * Test print to verify printer configuration
     * @returns {Promise<boolean>}
     */
    async testPrint() {
        const testContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Test Print</title>
                <style>
                    @page {
                        size: 72mm auto;
                        margin: 0;
                        orientation: portrait;
                    }
                    body {
                        font-family: 'Arial', 'Helvetica', sans-serif;
                        font-size: 12px;
                        font-weight: 500;
                        width: 72mm;
                        margin: 0;
                        padding: 2mm;
                    }
                    .centered {
                        text-align: center;
                    }
                    .dashed {
                        border-top: 1px dashed #000;
                        margin: 10px 0;
                    }
                    .bold {
                        font-weight: 700;
                    }
                </style>
            </head>
            <body>
                <div class="centered">
                    <h2 class="bold">TEST PRINT</h2>
                    <p class="bold">Printer berhasil dikonfigurasi!</p>
                    <div class="dashed"></div>
                    <p>Tanggal: ${new Date().toLocaleString('id-ID')}</p>
                    <p>Printer: ${this.getStoredPrinter() || 'Default'}</p>
                    <div class="dashed"></div>
                    <p>Jika Anda melihat struk ini,<br>printer siap digunakan.</p>
                </div>
            </body>
            </html>
        `;

        return this.print(testContent);
    }
}

// Create global instance
window.PosPrinterManager = new PosPrinterManager();

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PosPrinterManager;
}
