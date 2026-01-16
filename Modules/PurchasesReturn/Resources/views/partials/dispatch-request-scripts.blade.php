@push('page_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script src="{{ asset('js/dropzone-attachments-adapter.js') }}"></script>
    <script>
        if (window.Dropzone) {
            Dropzone.autoDiscover = false;
        }

        document.addEventListener('DOMContentLoaded', function () {
            var $shipping = null;
            var parseRaw = null;
            var form = document.getElementById('dispatch-request-form');

            if (typeof $ !== 'undefined' && $.fn.maskMoney) {
                $shipping = $('#return_shipping_amount');
                var currencySymbol = '{{ settings()->currency->symbol }}';
                var thousandsSeparator = '{{ settings()->currency->thousand_separator }}';
                var decimalSeparator = '{{ settings()->currency->decimal_separator }}';
                var maskApplied = false;

                parseRaw = function (value) {
                    if (!value) return '';
                    var raw = String(value);
                    if (currencySymbol) {
                        raw = raw.split(currencySymbol).join('');
                    }
                    raw = raw.replace(/\s/g, '');
                    if (thousandsSeparator) {
                        raw = raw.split(thousandsSeparator).join('');
                    }
                    if (decimalSeparator && decimalSeparator !== '.') {
                        raw = raw.split(decimalSeparator).join('.');
                    }
                    raw = raw.replace(/[^0-9.\-]/g, '');
                    return raw;
                };

                function formatPlain(raw) {
                    if (!raw) return '';
                    var num = parseFloat(raw);
                    if (Number.isNaN(num)) return '';
                    var fixed = num.toFixed(2);
                    if (decimalSeparator && decimalSeparator !== '.') {
                        fixed = fixed.replace('.', decimalSeparator);
                    }
                    return fixed;
                }

                function applyMask() {
                    if (!$shipping.length) return;
                    if (!maskApplied) {
                        $shipping.maskMoney({
                            prefix: currencySymbol,
                            thousands: thousandsSeparator,
                            decimal: decimalSeparator,
                            precision: 2,
                            allowZero: true,
                            allowNegative: false
                        });
                        maskApplied = true;
                    }
                    $shipping.maskMoney('mask');
                }

                function showPlainValue() {
                    if (!$shipping.length) return;
                    var raw = parseRaw($shipping.val());
                    if (maskApplied) {
                        $shipping.maskMoney('destroy');
                        maskApplied = false;
                    }
                    $shipping.val(formatPlain(raw));
                }

                if ($shipping.length) {
                    applyMask();

                    $shipping.on('focus', function () {
                        showPlainValue();
                        this.select();
                    });

                    $shipping.on('blur', function () {
                        applyMask();
                    });
                }

                $('#dispatch-request-form').on('submit', function () {
                    if ($shipping.length) {
                        $shipping.val(parseRaw($shipping.val()));
                    }
                });
            }

            if (window.DropzoneAttachments) {
                window.DropzoneAttachments.init({
                    element: '#return-dispatch-attachments-dropzone',
                    form: '#dispatch-request-form',
                    inputName: 'return_awb_attachments[]',
                    uploadUrl: '{{ route('dropzone.upload.documents') }}',
                    deleteUrl: '{{ route('dropzone.delete') }}',
                    tempPreviewUrl: "{{ route('dropzone.temp', ':name') }}",
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    csrfToken: '{{ csrf_token() }}',
                    acceptedFiles: '.jpg,.jpeg,.png,.pdf',
                    maxFilesize: 10,
                    initialFiles: @json(old('return_awb_attachments', []))
                });
            }

            var requestModalEl = document.getElementById('dispatchRequestModal');
            var confirmModalEl = document.getElementById('dispatchRequestConfirmModal');
            var triggerBtn = document.getElementById('dispatch-request-confirm-trigger');
            var submitBtn = document.getElementById('dispatch-request-confirm-submit');

            if (requestModalEl && confirmModalEl && triggerBtn && window.bootstrap && bootstrap.Modal) {
                var confirmModal = bootstrap.Modal.getOrCreateInstance(confirmModalEl);

                triggerBtn.addEventListener('click', function () {
                    if (form && !form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }
                    confirmModal.show();
                });

                if (submitBtn) {
                    submitBtn.addEventListener('click', function () {
                        if (form) {
                            if ($shipping && $shipping.length && typeof parseRaw === 'function') {
                                $shipping.val(parseRaw($shipping.val()));
                            }
                            form.submit();
                        }
                    });
                }

                confirmModalEl.addEventListener('show.bs.modal', function () {
                    var zIndex = 1070;
                    confirmModalEl.style.zIndex = zIndex;
                    window.setTimeout(function () {
                        var backdrops = document.querySelectorAll('.modal-backdrop.show');
                        var backdrop = backdrops[backdrops.length - 1];
                        if (backdrop) {
                            backdrop.style.zIndex = zIndex - 1;
                        }
                    }, 0);
                });

                confirmModalEl.addEventListener('hidden.bs.modal', function () {
                    if (requestModalEl.classList.contains('show')) {
                        document.body.classList.add('modal-open');
                    }
                    confirmModalEl.style.zIndex = '';
                });
            }

        });
    </script>
@endpush
