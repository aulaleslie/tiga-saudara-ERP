/**
 * Minimal, dependency-free stand-in for the subset of jQuery that
 * public/js/payment-amount-input.js relies on: val(), data(), addClass()/
 * removeClass()/hasClass(), attr(), on()/trigger(), select(), find()/addBack(),
 * and $(document).ready() / $(fn) invocation.
 *
 * Not a general jQuery replacement — only implements what the module under test
 * actually calls, so the real handler functions can be driven end-to-end
 * (focus/blur/input events, submit handlers) without a browser or jsdom.
 */
'use strict';

function makeElement(tag) {
    return {
        tagName: (tag || 'input').toUpperCase(),
        value: '',
        _data: {},
        _classes: {},
        _listeners: {},
        _attrs: {},
        matchesSelector: null, // set by caller when created via a selector match
    };
}

function fire(el, type) {
    var handlers = el._listeners[type] || [];
    handlers.forEach(function (fn) {
        fn.call(el);
    });
}

function wrap(elements) {
    var arr = Array.isArray(elements) ? elements : [elements];

    var api = {
        length: arr.length,
        get: function (i) { return arr[i]; },
        each: function (fn) {
            arr.forEach(function (el, i) {
                fn.call(el, i, el);
            });
            return api;
        },
        val: function (v) {
            if (v === undefined) {
                return arr[0] ? arr[0].value : undefined;
            }
            arr.forEach(function (el) { el.value = String(v); });
            return api;
        },
        data: function (key, v) {
            if (v === undefined) {
                return arr[0] ? arr[0]._data[key] : undefined;
            }
            arr.forEach(function (el) { el._data[key] = v; });
            return api;
        },
        addClass: function (cls) {
            arr.forEach(function (el) { el._classes[cls] = true; });
            return api;
        },
        removeClass: function (cls) {
            arr.forEach(function (el) { delete el._classes[cls]; });
            return api;
        },
        hasClass: function (cls) {
            return !!(arr[0] && arr[0]._classes[cls]);
        },
        attr: function (key, v) {
            if (v === undefined) {
                return arr[0] ? arr[0]._attrs[key] : undefined;
            }
            arr.forEach(function (el) { el._attrs[key] = v; });
            return api;
        },
        on: function (type, fn) {
            arr.forEach(function (el) {
                el._listeners[type] = el._listeners[type] || [];
                el._listeners[type].push(fn);
            });
            return api;
        },
        trigger: function (type) {
            arr.forEach(function (el) { fire(el, type); });
            return api;
        },
        select: function () { return api; },
        find: function () {
            var found = wrap([]);
            found._prevSet = arr;
            return found;
        },
        addBack: function () {
            return wrap((api._prevSet || []).concat(arr));
        },
    };

    return api;
}

/**
 * Creates a fresh fake-jQuery ($) function plus a registry of "enhanced" elements
 * so tests can build multiple independent inputs and drive them individually.
 */
function createFakeJQuery() {
    function $(selectorOrElement) {
        if (typeof selectorOrElement === 'function') {
            // $(document).ready(fn) shorthand: $(fn) - invoke immediately.
            selectorOrElement();
            return undefined;
        }
        if (Array.isArray(selectorOrElement)) {
            return wrap(selectorOrElement);
        }
        if (selectorOrElement && selectorOrElement.tagName) {
            return wrap(selectorOrElement);
        }
        // document or unsupported selector strings: no-op empty set.
        return wrap([]);
    }
    $.fn = {};
    return $;
}

module.exports = { makeElement: makeElement, wrap: wrap, fire: fire, createFakeJQuery: createFakeJQuery };
