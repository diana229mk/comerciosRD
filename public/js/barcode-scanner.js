/**
 * Lector HID (pistola): teclea rápido y manda Enter.
 * Captura en document para que Filament Search no se coma el código.
 */
(function () {
    'use strict';
    if (window.__hwBarcodeBound) return;
    window.__hwBarcodeBound = true;

    var buf = '';
    var lastAt = 0;
    var GAP = 70;
    var MIN = 3;

    function isProtected(el) {
        if (!el || !el.tagName) return false;
        var tag = el.tagName.toLowerCase();
        if (tag === 'textarea') return true;
        if (tag === 'select') return true;
        if (tag === 'input') {
            var t = (el.type || '').toLowerCase();
            if (t === 'password' || t === 'email' || t === 'number' || t === 'date') return true;
            if (el.getAttribute('data-pos-qty') !== null) return true;
            if (el.getAttribute('data-pos-tendered') !== null) return true;
        }
        return false;
    }

    function emit(code) {
        code = String(code || '').trim();
        if (code.length < MIN) return;
        document.dispatchEvent(new CustomEvent('hw-barcode', { detail: { code: code } }));

        var field = document.querySelector('[data-pos-barcode], [data-barcode-scan], input[name*="barcode"]');
        if (field && document.getElementById('pos-root')) {
            return;
        }
        if (field) {
            field.value = code;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            field.focus();
        }
    }

    document.addEventListener('keydown', function (ev) {
        if (ev.ctrlKey || ev.altKey || ev.metaKey) return;
        if (isProtected(ev.target)) return;

        var now = Date.now();
        var interval = now - lastAt;
        lastAt = now;
        if (interval > GAP) {
            buf = '';
        }

        if (ev.key === 'Enter') {
            if (buf.length >= MIN) {
                ev.preventDefault();
                ev.stopPropagation();
                var code = buf;
                buf = '';
                emit(code);
            }
            return;
        }

        if (!ev.key || ev.key.length !== 1) return;

        buf += ev.key;
        if (buf.length >= 2 && interval <= GAP) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    }, true);
})();
