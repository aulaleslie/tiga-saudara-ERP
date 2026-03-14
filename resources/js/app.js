import './bootstrap.js';
import '@coreui/coreui/dist/js/coreui.bundle.min.js';

// Import Alpine.js components
import './alpine-components/searchable-dropdown-new.js';
import './alpine-components/modal-manager.js';
import './alpine-components/form-loader.js';
import './alpine-components/purchase-calculator.js';

// Global toast notification helper
window.showToast = function(message, type = 'success', duration = 2000) {
    const iconMap = {
        'success': 'success',
        'error': 'error',
        'warning': 'warning',
        'info': 'info'
    };

    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: iconMap[type] || 'info',
        title: message,
        showConfirmButton: false,
        timer: duration,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });
};

$(function () {
    $('[data-coreui-toggle="tooltip"]').tooltip()
})

document.addEventListener('hide.coreui.modal', (event) => {
    const activeElement = document.activeElement
    if (event.target && activeElement && event.target.contains(activeElement)) {
        activeElement.blur()
    }
})

// Note: Do NOT call Alpine.start() here!
// Livewire v3 automatically starts Alpine after @livewireScripts loads.
// Starting Alpine before Livewire causes "entangle" errors because
// window.Livewire is not yet available.
