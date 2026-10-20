/**
 * Lightweight, dependency-free DOM & jQuery test harness.
 * Enables running client-side scripts like public/js/pos-kas-bon-collection.js
 * and public/js/financial-input.js in Node.js with real event handling,
 * DOM querying, mutations, and fetch mocking.
 */
'use strict';

function matchesSelector(el, selector) {
    if (!el || !el.tagName) return false;
    const parts = selector.split(',').map(s => s.trim());
    for (const part of parts) {
        let match = true;

        // Id selector: #someId
        const idMatch = part.match(/#([a-z0-9_-]+)/i);
        if (idMatch && el.id !== idMatch[1]) {
            match = false;
        }

        // Tag selector: e.g. input, tr, button
        const tagMatch = part.match(/^([a-z0-9]+)/i);
        if (tagMatch && el.tagName.toLowerCase() !== tagMatch[1].toLowerCase()) {
            match = false;
        }

        // Class selectors: e.g. .js-method-amount
        const classes = part.match(/\.([a-z0-9_-]+)/gi) || [];
        for (const c of classes) {
            const cls = c.slice(1);
            if (!el._classes || !el._classes.has(cls)) {
                match = false;
                break;
            }
        }
        if (!match) continue;

        // Attribute with value: [attr="val"]
        const attrValMatches = part.match(/\[([a-z0-9_-]+)=["']?(.*?)["']?\]/gi) || [];
        for (const av of attrValMatches) {
            const m = av.match(/\[([a-z0-9_-]+)=["']?(.*?)["']?\]/);
            if (el.getAttribute(m[1]) !== m[2]) {
                match = false;
                break;
            }
        }
        if (!match) continue;

        // Attribute presence: [attr]
        const attrPresMatches = part.match(/\[([a-z0-9_-]+)\]/gi) || [];
        for (const ap of attrPresMatches) {
            if (ap.includes('=')) continue;
            const attr = ap.slice(1, -1);
            if (!el.hasAttribute(attr)) {
                match = false;
                break;
            }
        }

        if (match) return true;
    }
    return false;
}

class DOMElement {
    constructor(tagName) {
        this.tagName = (tagName || 'div').toUpperCase();
        this.id = '';
        this.className = '';
        this.value = '';
        this.textContent = '';
        this.disabled = false;
        this.selectionStart = 0;
        this.selectionEnd = 0;
        this.parentElement = null;
        this.children = [];
        this.dataset = {};
        this._attributes = new Map();
        this._listeners = new Map();
        this._data = new Map();
        this._classes = new Set();
    }

    setAttribute(name, value) {
        const valStr = String(value);
        this._attributes.set(name, valStr);
        if (name === 'id') this.id = valStr;
        if (name === 'class') {
            this.className = valStr;
            this._classes.clear();
            valStr.split(/\s+/).filter(Boolean).forEach(c => this._classes.add(c));
        }
        if (name === 'value') {
            this.value = valStr;
        }
    }

    getAttribute(name) {
        if (name === 'id') return this.id || null;
        if (name === 'class') return this.className || null;
        if (name === 'value') return this.value !== undefined ? String(this.value) : null;
        return this._attributes.has(name) ? this._attributes.get(name) : null;
    }

    hasAttribute(name) {
        return this._attributes.has(name) || (name === 'id' && !!this.id) || (name === 'class' && !!this.className);
    }

    removeAttribute(name) {
        this._attributes.delete(name);
        if (name === 'id') this.id = '';
        if (name === 'class') {
            this.className = '';
            this._classes.clear();
        }
    }

    get classList() {
        return {
            add: (...classes) => {
                classes.forEach(c => this._classes.add(c));
                this.className = Array.from(this._classes).join(' ');
            },
            remove: (...classes) => {
                classes.forEach(c => this._classes.delete(c));
                this.className = Array.from(this._classes).join(' ');
            },
            contains: (c) => this._classes.has(c),
            toggle: (c, force) => {
                if (force === true || (force === undefined && !this._classes.has(c))) {
                    this._classes.add(c);
                } else {
                    this._classes.delete(c);
                }
                this.className = Array.from(this._classes).join(' ');
            }
        };
    }

    appendChild(child) {
        if (!child) return child;
        child.parentElement = this;
        this.children.push(child);
        return child;
    }

    removeChild(child) {
        const idx = this.children.indexOf(child);
        if (idx !== -1) {
            this.children.splice(idx, 1);
            child.parentElement = null;
        }
        return child;
    }

    setSelectionRange(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    }

    addEventListener(type, listener) {
        if (!this._listeners.has(type)) {
            this._listeners.set(type, []);
        }
        this._listeners.get(type).push(listener);
    }

    removeEventListener(type, listener) {
        if (!this._listeners.has(type)) return;
        const list = this._listeners.get(type);
        const idx = list.indexOf(listener);
        if (idx !== -1) list.splice(idx, 1);
    }

    dispatchEvent(event) {
        const type = event && event.type ? event.type : event;
        const eventObj = typeof event === 'object' ? event : { type: type };
        try {
            if (!eventObj.target) {
                Object.defineProperty(eventObj, 'target', { value: this, configurable: true, writable: true });
            }
        } catch (_) {}
        if (!eventObj.preventDefault) eventObj.preventDefault = () => {};

        const list = this._listeners.get(type) || [];
        for (const fn of list) {
            fn.call(this, eventObj);
        }
        return true;
    }

    click() {
        this.dispatchEvent({ type: 'click', target: this, defaultPrevented: false });
    }

    querySelector(selector) {
        for (const child of this.children) {
            if (matchesSelector(child, selector)) return child;
            const nested = child.querySelector(selector);
            if (nested) return nested;
        }
        return null;
    }

    querySelectorAll(selector) {
        let results = [];
        for (const child of this.children) {
            if (matchesSelector(child, selector)) results.push(child);
            results = results.concat(child.querySelectorAll(selector));
        }
        return results;
    }

    set innerHTML(html) {
        this.children = [];
        this.textContent = '';
        if (!html) return;
        parseHtmlInto(html, this);
    }

    get innerHTML() {
        return this.textContent || '';
    }
}

function parseHtmlInto(html, parent) {
    const tagRegex = /<([a-z0-9]+)((?:\s+[^>]*?)?)(\/?>)/gi;
    const attrRegex = /([a-z0-9_-]+)(?:=(["'])(.*?)\2|=([^\s>]+))?/gi;

    let match;
    while ((match = tagRegex.exec(html)) !== null) {
        const tagName = match[1].toLowerCase();
        if (tagName.startsWith('/')) continue;
        const attrStr = match[2] || '';
        const child = new DOMElement(tagName);

        let attrMatch;
        while ((attrMatch = attrRegex.exec(attrStr)) !== null) {
            const name = attrMatch[1];
            const val = attrMatch[3] !== undefined ? attrMatch[3] : (attrMatch[4] !== undefined ? attrMatch[4] : '');
            child.setAttribute(name, val);
        }

        parent.appendChild(child);
    }
}

function createDOMEnvironment() {
    const elementRegistry = new Map();

    const document = {
        readyState: 'complete',
        body: new DOMElement('body'),
        createElement: (tag) => new DOMElement(tag),
        getElementById: (id) => {
            if (elementRegistry.has(id)) return elementRegistry.get(id);
            return document.body.querySelector('#' + id);
        },
        querySelector: (sel) => {
            if (sel.startsWith('#') && !sel.includes(' ') && !sel.includes('.')) {
                return document.getElementById(sel.slice(1));
            }
            return document.body.querySelector(sel);
        },
        querySelectorAll: (sel) => document.body.querySelectorAll(sel),
        addEventListener: (type, fn) => document.body.addEventListener(type, fn),
        registerElement: (id, el) => {
            el.id = id;
            elementRegistry.set(id, el);
            document.body.appendChild(el);
            return el;
        }
    };

    function wrap(elements) {
        const arr = Array.isArray(elements) ? elements : (elements ? [elements] : []);
        const api = {
            length: arr.length,
            get: (i) => arr[i],
            each: (fn) => {
                arr.forEach((el, i) => fn.call(el, i, el));
                return api;
            },
            val: (v) => {
                if (v === undefined) return arr[0] ? arr[0].value : undefined;
                arr.forEach(el => { el.value = String(v); });
                return api;
            },
            data: (key, v) => {
                if (v === undefined) return arr[0] ? arr[0]._data.get(key) : undefined;
                arr.forEach(el => { el._data.set(key, v); });
                return api;
            },
            addClass: (cls) => {
                arr.forEach(el => el.classList.add(cls));
                return api;
            },
            removeClass: (cls) => {
                arr.forEach(el => el.classList.remove(cls));
                return api;
            },
            hasClass: (cls) => arr[0] ? arr[0].classList.contains(cls) : false,
            attr: (key, v) => {
                if (v === undefined) return arr[0] ? arr[0].getAttribute(key) : undefined;
                arr.forEach(el => el.setAttribute(key, v));
                return api;
            },
            on: (type, fn) => {
                arr.forEach(el => el.addEventListener(type, fn));
                return api;
            },
            trigger: (type, eventArg) => {
                arr.forEach(el => el.dispatchEvent(eventArg || { type: type, target: el }));
                return api;
            },
            find: (sel) => {
                let found = [];
                arr.forEach(el => {
                    found = found.concat(el.querySelectorAll(sel));
                });
                const res = wrap(found);
                res._prevSet = arr;
                return res;
            },
            addBack: (sel) => {
                return wrap((api._prevSet || []).concat(arr));
            },
            modal: (action) => {
                return api;
            }
        };
        return api;
    }

    const jQuery = function (selectorOrElement) {
        if (typeof selectorOrElement === 'function') {
            selectorOrElement();
            return undefined;
        }
        if (typeof selectorOrElement === 'string') {
            if (selectorOrElement.startsWith('#') && !selectorOrElement.includes(' ') && !selectorOrElement.includes('.')) {
                const el = document.getElementById(selectorOrElement.slice(1));
                return wrap(el || []);
            }
            return wrap(document.querySelectorAll(selectorOrElement));
        }
        return wrap(selectorOrElement);
    };
    jQuery.fn = {};

    return {
        document,
        jQuery,
        DOMElement
    };
}

module.exports = {
    DOMElement,
    createDOMEnvironment,
    matchesSelector
};
