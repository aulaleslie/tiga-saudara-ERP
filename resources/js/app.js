import './bootstrap.js';
import '@coreui/coreui/dist/js/coreui.bundle.min.js';

// Import Alpine.js components
import './alpine-components/searchable-dropdown.js';
import './alpine-components/modal-manager.js';
import './alpine-components/form-loader.js';
import './alpine-components/purchase-calculator.js';

$(function () {
    $('[data-toggle="tooltip"]').tooltip()
})

