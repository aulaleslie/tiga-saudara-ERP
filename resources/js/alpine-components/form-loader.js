/**
 * Reusable Alpine.js form loader component
 * Provides loading spinner and overlay functionality
 */
function formLoader() {
    return {
        isLoading: false,
        loadingText: 'Memproses...',

        startLoading(text = 'Memproses...') {
            this.isLoading = true;
            this.loadingText = text;
        },

        stopLoading() {
            this.isLoading = false;
            this.loadingText = 'Memproses...';
        },

        // Computed property for overlay visibility
        get showOverlay() {
            return this.isLoading;
        }
    };
}

// Register as global Alpine data function
window.formLoader = formLoader;