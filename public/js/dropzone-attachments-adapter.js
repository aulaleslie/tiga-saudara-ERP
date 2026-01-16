(function (global) {
    'use strict';

    function toArray(value) {
        return Array.isArray(value) ? value : [];
    }

    function isImage(name) {
        return /\.(jpe?g|png|gif|webp)$/i.test(name || '');
    }

    function ensureHidden(form, inputName, value) {
        if (!value) return;
        var selector = 'input[type="hidden"][name="' + inputName + '"][value="' + value.replace(/(["'\\])/g, '\\$1') + '"]';
        if (form.querySelector(selector)) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = inputName;
        input.value = value;
        form.appendChild(input);
    }

    function removeHidden(form, inputName, value) {
        if (!value) return;
        var inputs = form.querySelectorAll('input[type="hidden"][name="' + inputName + '"]');
        inputs.forEach(function (input) {
            if (input.value === value) {
                input.remove();
            }
        });
    }

    function initDropzone(options) {
        if (!global.Dropzone) return null;

        var element = typeof options.element === 'string'
            ? document.querySelector(options.element)
            : options.element;
        if (!element) return null;

        if (element.dropzone) return element.dropzone;

        var form = options.form
            ? (typeof options.form === 'string' ? document.querySelector(options.form) : options.form)
            : element.closest('form');
        if (!form) return null;

        var inputName = options.inputName || 'attachments[]';
        var tempPreviewUrl = options.tempPreviewUrl || '';
        var initialFiles = toArray(options.initialFiles);
        var csrfToken = options.csrfToken || '';

        var config = {
            url: options.uploadUrl,
            paramName: options.paramName || 'file',
            addRemoveLinks: options.addRemoveLinks !== false,
            dictRemoveFile: options.dictRemoveFile || "<i class='bi bi-x-circle text-danger'></i> remove",
            headers: options.headers || {},
            init: function () {
                var dz = this;
                initialFiles.forEach(function (name) {
                    var mock = {
                        name: name,
                        size: 12345,
                        accepted: true,
                        file_name: name,
                        _isTemp: true
                    };
                    dz.emit('addedfile', mock);
                    if (tempPreviewUrl && isImage(name)) {
                        dz.emit('thumbnail', mock, tempPreviewUrl.replace(':name', encodeURIComponent(name)));
                    }
                    dz.emit('complete', mock);
                    ensureHidden(form, inputName, name);
                });

                if (typeof dz.options.maxFiles === 'number') {
                    dz.options.maxFiles = Math.max(0, dz.options.maxFiles - initialFiles.length);
                }
            },
            success: function (file, response) {
                if (!response || !response.name) return;
                file._serverName = response.name;
                ensureHidden(form, inputName, response.name);
            },
            removedfile: function (file) {
                if (file.previewElement) {
                    file.previewElement.remove();
                }

                var name = file._serverName || file.file_name || file.name;
                removeHidden(form, inputName, name);

                if (!name || !options.deleteUrl) return;

                if (global.$ && typeof global.$.post === 'function') {
                    global.$.post(options.deleteUrl, { _token: csrfToken, file_name: name });
                    return;
                }

                var formData = new FormData();
                if (csrfToken) {
                    formData.append('_token', csrfToken);
                }
                formData.append('file_name', name);
                fetch(options.deleteUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
            }
        };

        if (options.acceptedFiles) {
            config.acceptedFiles = options.acceptedFiles;
        }
        if (typeof options.maxFilesize === 'number') {
            config.maxFilesize = options.maxFilesize;
        }
        if (typeof options.maxFiles === 'number') {
            config.maxFiles = options.maxFiles;
        }

        return new global.Dropzone(element, config);
    }

    global.DropzoneAttachments = {
        init: initDropzone
    };
})(window);
