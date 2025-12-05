/**
 * Form Submission Lock - Prevents double-click form submissions
 * Provides visual feedback and prevents duplicate submissions
 */

class FormSubmissionLock {
    constructor() {
        this.forms = new Map();
    }

    /**
     * Initialize form submission lock for a specific form
     * @param {string} formId - The form ID
     * @param {Object} options - Configuration options
     */
    init(formId, options = {}) {
        const form = document.getElementById(formId);
        if (!form) {
            console.warn(`Form with ID '${formId}' not found`);
            return;
        }

        const config = {
            errorEventName: options.errorEventName || `${formId.replace('-form', '')}:submit-error`,
            processingText: options.processingText || 'Memproses…',
            ...options
        };

        this.forms.set(formId, { form, config });

        // Bind submit handler
        form.addEventListener('submit', (event) => this.handleSubmit(event, formId));

        // Bind error reset listener
        window.addEventListener(config.errorEventName, () => this.resetLock(formId));
    }

    /**
     * Handle form submission
     */
    handleSubmit(event, formId) {
        const { form } = this.forms.get(formId);

        // Prevent double submission
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }

        // Set submitting flag and lock buttons
        form.dataset.submitting = 'true';
        this.toggleButtons(form, true);
    }

    /**
     * Reset form submission lock
     */
    resetLock(formId) {
        const { form } = this.forms.get(formId);
        if (!form) return;

        form.dataset.submitting = 'false';
        this.toggleButtons(form, false);
    }

    /**
     * Toggle button states (lock/unlock)
     */
    toggleButtons(form, processing = false) {
        $(form).find('.submit-lock-btn').each(function () {
            const $btn = $(this);
            const spinner = $btn.find('.button-spinner');
            const textEl = $btn.find('.button-text');
            const defaultText = $btn.data('default-text') || (textEl.length ? textEl.text().trim() : $btn.text().trim());
            const processingText = $btn.data('processing-text') || 'Memproses…';

            if (!$btn.data('default-text') && defaultText) {
                $btn.data('default-text', defaultText);
            }

            if (processing) {
                if (spinner.length) spinner.removeClass('d-none');
                if (textEl.length) textEl.text(processingText);
                $btn.prop('disabled', true).addClass('disabled');
            } else {
                if (spinner.length) spinner.addClass('d-none');
                if (textEl.length) textEl.text($btn.data('default-text'));
                $btn.prop('disabled', false).removeClass('disabled');
            }
        });
    }

    /**
     * Manually lock a form (useful for custom scenarios)
     */
    lock(formId) {
        const { form } = this.forms.get(formId);
        if (form) {
            form.dataset.submitting = 'true';
            this.toggleButtons(form, true);
        }
    }

    /**
     * Manually unlock a form
     */
    unlock(formId) {
        const { form } = this.forms.get(formId);
        if (form) {
            form.dataset.submitting = 'false';
            this.toggleButtons(form, false);
        }
    }
}

// Global instance
window.FormSubmissionLock = new FormSubmissionLock();

// Convenience functions for backward compatibility
window.initFormSubmissionLock = (formId, options) => {
    window.FormSubmissionLock.init(formId, options);
};

window.toggleFormSubmissionLock = (form, processing) => {
    window.FormSubmissionLock.toggleButtons(form, processing);
};