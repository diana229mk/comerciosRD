(function () {
    'use strict';

    function money(n) {
        return 'RD$ ' + Number(n || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function csrf(cfg) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.getAttribute('content')) || cfg.csrf || '';
    }

    function qs(root, sel) {
        return root.querySelector(sel);
    }

    window.mountPosTerminal = function (root, cfg) {
        if (!root || !cfg) return;
        if (root.dataset.posMounted === '1') return;
        root.dataset.posMounted = '1';

        var state = {
            cart: {},
            suggestions: [],
            highlight: -1,
            showSuggest: false,
            showCheckout: false,
            showOpenCash: false,
            showCloseCash: false,
            showQuotes: false,
            showQuoteSave: false,
            quotes: [],
            quoteId: '',
            quoteCode: '',
            showVariants: false,
            variants: [],
            payment: 'efectivo',
            customerId: '',
            tendered: '',
            busy: false,
            toast: null,
            toastTimer: null,
            vehicle: [],
        };
        var checkoutStorage = 'pos-checkout:' + cfg.business.id + ':' + cfg.cashier_id;
        var pendingCheckout = null;
        var checkoutStorageAvailable = true;
        try {
            pendingCheckout = JSON.parse(sessionStorage.getItem(checkoutStorage) || 'null');
            if (pendingCheckout && (!pendingCheckout.body || !pendingCheckout.body.idempotency_key
                || !Array.isArray(pendingCheckout.body.items))) {
                throw new Error('Solicitud pendiente ilegible');
            }
            if (pendingCheckout && pendingCheckout.body && Array.isArray(pendingCheckout.body.items)) {
                pendingCheckout.body.items.forEach(function (item) {
                    state.cart[item.key || item.product_id] = Object.assign({}, item);
                });
                state.payment = pendingCheckout.body.payment_method;
                state.customerId = pendingCheckout.body.customer_id || '';
                state.tendered = pendingCheckout.body.amount_tendered || '';
                state.quoteId = pendingCheckout.body.quote_id || '';
            }
        } catch (e) {
            checkoutStorageAvailable = false;
        }

        function cartList() {
            return Object.keys(state.cart).map(function (k) { return state.cart[k]; });
        }

        function subtotal() {
            return Math.round(cartList().reduce(function (s, i) {
                return s + Number(i.price) * Number(i.qty);
            }, 0) * 100) / 100;
        }

        function lineIncluded(i) {
            if (typeof i.tax_included === 'boolean') return i.tax_included;
            return !!cfg.tax_included;
        }

        function tax() {
            var rate = Number(cfg.tax_rate || 0) / 100;
            var t = 0;
            cartList().forEach(function (i) {
                var amount = Number(i.price) * Number(i.qty);
                if (rate <= 0) return;
                if (lineIncluded(i)) {
                    t += amount - amount / (1 + rate);
                } else {
                    t += amount * rate;
                }
            });
            return Math.round(t * 100) / 100;
        }

        function total() {
            var rate = Number(cfg.tax_rate || 0) / 100;
            var t = 0;
            cartList().forEach(function (i) {
                var amount = Number(i.price) * Number(i.qty);
                if (lineIncluded(i) || rate <= 0) {
                    t += amount;
                } else {
                    t += amount * (1 + rate);
                }
            });
            return Math.round(t * 100) / 100;
        }

        function change() {
            if (state.payment !== 'efectivo') return 0;
            var t = parseFloat(state.tendered);
            if (isNaN(t)) return 0;
            return Math.max(0, Math.round((t - total()) * 100) / 100);
        }

        function toast(title, message, kind) {
            state.toast = { title: title || '', message: message || '', kind: kind || 'err' };
            render();
            clearTimeout(state.toastTimer);
            state.toastTimer = setTimeout(function () {
                state.toast = null;
                render();
            }, 5000);
        }

        function api(url, opts) {
            opts = opts || {};
            var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (opts.json) {
                headers['Content-Type'] = 'application/json';
                headers['X-CSRF-TOKEN'] = csrf(cfg);
                var xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
                if (xsrf) {
                    headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf[1]);
                }
            }
            return fetch(url, {
                method: opts.method || 'GET',
                credentials: 'same-origin',
                headers: headers,
                body: opts.json ? JSON.stringify(opts.json) : undefined,
            }).then(function (res) {
                var ct = res.headers.get('content-type') || '';
                if (ct.indexOf('json') === -1) {
                    return {
                        ok: false,
                        action: 'error',
                        title: res.status === 419 ? 'Sesión vencida' : 'Error ' + res.status,
                        message: res.status === 419 ? 'Recarga la página e intenta de nuevo.' : 'No se pudo leer el código.',
                    };
                }
                return res.json().then(function (body) {
                    body._status = res.status;
                    return body;
                });
            });
        }

        function addItem(item) {
            var key = item.key || item.product_id;
            if (state.cart[key]) {
                var next = Number(state.cart[key].qty) + Number(item.qty || 1);
                if (item.deduct_on_sale && next > Number(item.stock)) {
                    toast('Stock insuficiente', item.name + ' — disponible: ' + item.stock, 'err');
                    return;
                }
                state.cart[key].qty = next;
                state.cart[key].stock = item.stock;
            } else {
                state.cart[key] = Object.assign({}, item, { qty: Number(item.qty || 1) });
            }
        }

        function scan(code, variantId) {
            var payload = variantId ? { variant_id: variantId } : { code: code };
            return api(cfg.api.scan, { method: 'POST', json: payload }).then(function (data) {
                if (data.action === 'add' && data.item) {
                    addItem(data.item);
                    if (data.warning) toast('Aviso', data.warning, 'ok');
                    state.showVariants = false;
                    state.variants = [];
                    state.suggestions = [];
                    state.showSuggest = false;
                    render();
                    focusBarcode(true);
                    return;
                }
                if (data.action === 'variants') {
                    state.variants = data.variants || [];
                    state.showVariants = true;
                    render();
                    return;
                }
                if (data.action === 'ambiguous') {
                    state.suggestions = data.suggestions || [];
                    state.showSuggest = true;
                    toast(data.message || 'Varias coincidencias', '', 'err');
                    render();
                    return;
                }
                toast(data.title || 'No se pudo agregar', data.message || '', 'err');
                render();
                focusBarcode(true);
            }).catch(function () {
                toast('Error de red', 'No se pudo buscar el producto.', 'err');
            });
        }

        var searchTimer = null;
        function search(term) {
            clearTimeout(searchTimer);
            if (!term || term.length < 2) {
                state.suggestions = [];
                state.showSuggest = false;
                renderSuggestOnly();
                return;
            }
            searchTimer = setTimeout(function () {
                api(cfg.api.search + '?q=' + encodeURIComponent(term)).then(function (data) {
                    state.suggestions = data.suggestions || [];
                    state.showSuggest = state.suggestions.length > 0;
                    state.highlight = -1;
                    renderSuggestOnly();
                }).catch(function () {});
            }, 280);
        }

        function applyCash(snapshot) {
            if (!snapshot) return;
            cfg.cash = snapshot;
            cfg.open_cash = !!snapshot.open;
        }

        function quoteLoadUrl(id) {
            var base = (cfg.api && cfg.api.quote_load) || '';
            return base.replace(/\/$/, '') + '/' + encodeURIComponent(id);
        }

        function applyQuoteCart(payload) {
            var items = payload.items || [];
            state.cart = {};
            items.forEach(function (item) {
                var key = item.key || item.product_id;
                state.cart[key] = Object.assign({}, item, { qty: Number(item.qty || 1) });
            });
            state.quoteId = payload.quote && payload.quote.id ? payload.quote.id : '';
            state.quoteCode = payload.quote && payload.quote.code ? payload.quote.code : '';
            if (payload.quote && payload.quote.customer_id) {
                state.customerId = payload.quote.customer_id;
            }
            state.showQuotes = false;
            if (payload.quote && payload.quote.expired) {
                toast('Cotización vencida', 'Revisa precios antes de cobrar.', 'err');
            }
        }

        function fetchQuotes() {
            if (!cfg.api || !cfg.api.quotes) return;
            api(cfg.api.quotes).then(function (data) {
                state.quotes = data.quotes || [];
                render();
            }).catch(function () {
                toast('Error de red', 'No se pudieron cargar las cotizaciones.', 'err');
            });
        }

        function loadQuote(id) {
            if (!id) return;
            api(quoteLoadUrl(id)).then(function (data) {
                if (!data.ok) {
                    toast(data.title || 'No se pudo cargar', data.message || '', 'err');
                    render();
                    return;
                }
                applyQuoteCart(data);
                toast('Cotización ' + (state.quoteCode || ''), 'Cargada en la cuenta. Cobra cuando el cliente pague.', 'ok');
                render();
            }).catch(function () {
                toast('Error de red', 'No se pudo cargar la cotización.', 'err');
            });
        }

        function saveQuote() {
            if (state.busy) return;
            if (!cfg.api || !cfg.api.quote_save) {
                toast('No disponible', 'Este negocio no usa cotizaciones.', 'err');
                return;
            }
            var items = cartList();
            if (!items.length) {
                toast('Agrega productos primero', '', 'err');
                return;
            }
            var customerEl = qs(root, '[data-pos-quote-customer]');
            var nameEl = qs(root, '[data-pos-quote-name]');
            state.busy = true;
            render();
            api(cfg.api.quote_save, {
                method: 'POST',
                json: {
                    items: items,
                    customer_id: customerEl && customerEl.value ? customerEl.value : null,
                    customer_name: nameEl && nameEl.value ? nameEl.value : null,
                    tax_rate: cfg.tax_rate,
                },
            }).then(function (data) {
                state.busy = false;
                if (!data.ok) {
                    toast(data.title || 'No se pudo cotizar', data.message || '', 'err');
                    render();
                    return;
                }
                state.showQuoteSave = false;
                state.cart = {};
                state.quoteId = data.quote_id || '';
                state.quoteCode = data.code || '';
                toast('Cotización ' + (data.code || ''), 'Guardada. Cárgala después para cobrar.', 'ok');
                render();
                focusBarcode(true);
            }).catch(function () {
                state.busy = false;
                toast('Error de red', 'No se pudo guardar la cotización.', 'err');
                render();
            });
        }

        function emptyCartMessage() {
            if (cfg.business && cfg.business.is_parts) {
                return 'Busca por OEM, código, nombre o vehículo y pulsa Agregar.';
            }
            return 'Escribe código o nombre y pulsa Agregar (o Enter).';
        }

        function openCash() {
            if (state.busy) return;
            if (!cfg.api || !cfg.api.cash_open) {
                toast('No disponible', 'No se puede abrir la caja aquí.', 'err');
                return;
            }
            var amountEl = qs(root, '[data-pos-opening-amount]');
            var notesEl = qs(root, '[data-pos-opening-notes]');
            var amount = parseFloat(amountEl && amountEl.value);
            if (isNaN(amount) || amount < 0) {
                toast('Monto inválido', 'Indica el efectivo inicial del turno.', 'err');
                return;
            }
            state.busy = true;
            render();
            api(cfg.api.cash_open, {
                method: 'POST',
                json: {
                    opening_amount: amount,
                    opening_notes: notesEl && notesEl.value ? notesEl.value : null,
                },
            }).then(function (data) {
                state.busy = false;
                if (!data.ok) {
                    toast(data.title || 'No se pudo abrir la caja', data.message || '', 'err');
                    render();
                    return;
                }
                applyCash(data.cash);
                state.showOpenCash = false;
                toast('Caja abierta', 'Turno iniciado con ' + money(amount), 'ok');
                if (cartList().length) {
                    state.showCheckout = true;
                    state.tendered = '';
                    state.payment = 'efectivo';
                }
                render();
                focusBarcode(true);
            }).catch(function () {
                state.busy = false;
                toast('Error de red', 'No se pudo abrir la caja.', 'err');
                render();
            });
        }

        function closeCash() {
            if (state.busy) return;
            if (!cfg.api || !cfg.api.cash_close) {
                toast('No disponible', 'No se puede cerrar la caja aquí.', 'err');
                return;
            }
            var amountEl = qs(root, '[data-pos-closing-amount]');
            var notesEl = qs(root, '[data-pos-closing-notes]');
            var amount = parseFloat(amountEl && amountEl.value);
            if (isNaN(amount) || amount < 0) {
                toast('Monto inválido', 'Cuenta el efectivo al cierre del turno.', 'err');
                return;
            }
            state.busy = true;
            render();
            api(cfg.api.cash_close, {
                method: 'POST',
                json: {
                    closing_amount: amount,
                    closing_notes: notesEl && notesEl.value ? notesEl.value : null,
                },
            }).then(function (data) {
                state.busy = false;
                if (!data.ok) {
                    toast(data.title || 'No se pudo cerrar la caja', data.message || '', 'err');
                    render();
                    return;
                }
                applyCash(data.cash);
                state.showCloseCash = false;
                var diff = Number(data.difference || 0);
                var msg = 'Contado ' + money(data.closing_amount) + ' · esperado ' + money(data.expected);
                if (diff !== 0) {
                    msg += ' · diferencia ' + money(diff);
                }
                toast('Caja cerrada', msg, diff === 0 ? 'ok' : 'err');
                render();
                focusBarcode(true);
            }).catch(function () {
                state.busy = false;
                toast('Error de red', 'No se pudo cerrar la caja.', 'err');
                render();
            });
        }

        function checkout() {
            if (state.busy) return;
            if (!checkoutStorageAvailable) {
                toast('No se puede conservar la solicitud', 'Habilita el almacenamiento de la pestaña antes de cobrar.', 'err');
                return;
            }
            if (pendingCheckout && pendingCheckout.editable !== true) {
                toast('Reintentando la solicitud original', 'No se enviará una venta nueva ni los cambios posteriores del carrito.', 'ok');
                sendCheckout(pendingCheckout.body);
                return;
            }
            var items = cartList();
            if (!items.length) {
                toast('Agrega productos primero', '', 'err');
                return;
            }
            if (state.payment === 'efectivo' && !cfg.open_cash) {
                toast('Caja cerrada', 'Abre la caja antes de cobrar en efectivo.', 'err');
                if (cfg.cash && cfg.cash.can_open) {
                    state.showCheckout = false;
                    state.showOpenCash = true;
                }
                render();
                return;
            }
            if (state.payment === 'efectivo') {
                var t = parseFloat(state.tendered);
                if (isNaN(t) || t < total()) {
                    toast('Monto insuficiente', 'Debe entregar al menos ' + money(total()), 'err');
                    return;
                }
            }
            if ((state.payment === 'credito' || cfg.business.requires_customer) && !state.customerId) {
                toast('Cliente requerido', '', 'err');
                return;
            }
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            var body = {
                idempotency_key: pendingCheckout ? pendingCheckout.body.idempotency_key
                    : Array.from(bytes, function (n) { return n.toString(16).padStart(2, '0'); }).join(''),
                items: items,
                payment_method: state.payment,
                customer_id: state.customerId || null,
                amount_tendered: state.payment === 'efectivo' ? parseFloat(state.tendered) : null,
                tax_rate: cfg.tax_rate,
                quote_id: state.quoteId || null,
            };
            pendingCheckout = JSON.parse(JSON.stringify({ body: body }));
            try {
                sessionStorage.setItem(checkoutStorage, JSON.stringify(pendingCheckout));
            } catch (e) {
                checkoutStorageAvailable = false;
                toast('Solicitud no enviada', 'No se pudo conservar su identidad; no se cobró.', 'err');
                return;
            }
            sendCheckout(pendingCheckout.body);
        }

        function sendCheckout(body) {
            state.busy = true;
            render();
            api(cfg.api.checkout, {
                method: 'POST',
                json: body,
            }).then(function (data) {
                state.busy = false;
                if (!data.ok) {
                    if (data.retry_safe === true) {
                        pendingCheckout.editable = true;
                        sessionStorage.setItem(checkoutStorage, JSON.stringify(pendingCheckout));
                    }
                    toast(data.title || 'No se pudo completar la venta', data.message || '', 'err');
                    render();
                    return;
                }
                sessionStorage.removeItem(checkoutStorage);
                pendingCheckout = null;
                var ch = Number(data.change || 0);
                toast(
                    ch > 0 ? ('Vuelto: ' + money(ch)) : 'Venta cobrada',
                    'Total: ' + money(data.total),
                    'ok'
                );
                state.cart = {};
                state.showCheckout = false;
                state.tendered = '';
                state.customerId = '';
                state.payment = 'efectivo';
                state.quoteId = '';
                state.quoteCode = '';
                if (data.cash) applyCash(data.cash);
                render();
                if (data.print_url) {
                    var win = window.open(data.print_url, '_blank');
                    if (!win) {
                        toast('Impresión bloqueada', 'Abre el ticket desde el enlace.', 'err');
                        var link = qs(root, '[data-pos-print-link]');
                        if (link) {
                            link.href = data.print_url;
                            link.classList.remove('pos-hidden');
                        }
                    }
                }
                focusBarcode(true);
            }).catch(function () {
                state.busy = false;
                toast('Resultado sin confirmar', 'Reintenta la misma solicitud; no inicies otro cobro para esta venta.', 'err');
                render();
            });
        }

        function focusBarcode(clear) {
            var input = qs(root, '[data-pos-barcode]');
            if (!input) return;
            if (clear) input.value = '';
            setTimeout(function () { input.focus(); }, 30);
        }

        function renderSuggestOnly() {
            var box = qs(root, '[data-pos-suggest]');
            if (!box) return;
            if (!state.showSuggest || !state.suggestions.length) {
                box.classList.add('pos-hidden');
                box.innerHTML = '';
                return;
            }
            box.classList.remove('pos-hidden');
            box.innerHTML = state.suggestions.map(function (s, i) {
                var on = i === state.highlight ? ' is-on' : '';
                return '<button type="button" data-pos-pick="' + s.id + '" class="' + on + '">' +
                    '<span><strong>' + escapeHtml(s.name) + '</strong>' +
                    (s.brand ? ' <span class="muted">· ' + escapeHtml(s.brand) + '</span>' : '') +
                    '<div class="muted">' +
                    (s.barcode ? '📷 ' + escapeHtml(s.barcode) : '') +
                    (s.sku ? ' · SKU ' + escapeHtml(s.sku) : '') +
                    (s.track_stock ? ' · Stock ' + s.stock : '') +
                    '</div></span><span>' + money(s.price) + '</span></button>';
            }).join('');
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        function render() {
            var countEl = qs(root, '[data-pos-count]');
            if (countEl) countEl.textContent = String(cartList().length);

            var cash = cfg.cash || {};
            var cashEl = qs(root, '[data-pos-cash]');
            if (cashEl) {
                cashEl.textContent = cfg.open_cash ? 'Abierta' : 'Cerrada';
                cashEl.className = cfg.open_cash ? 'pos-ok' : 'pos-bad';
            }
            var cashInfo = qs(root, '[data-pos-cash-info]');
            if (cashInfo) cashInfo.classList.toggle('pos-hidden', !cfg.open_cash);
            var openingEl = qs(root, '[data-pos-cash-opening]');
            if (openingEl) openingEl.textContent = cash.opening_amount != null ? money(cash.opening_amount) : '—';
            var sinceEl = qs(root, '[data-pos-cash-since]');
            if (sinceEl) sinceEl.textContent = cash.opened_at || '—';
            var byEl = qs(root, '[data-pos-cash-by]');
            if (byEl) byEl.textContent = cash.opened_by || '—';
            var expectedEl = qs(root, '[data-pos-cash-expected]');
            if (expectedEl) expectedEl.textContent = cash.expected != null ? money(cash.expected) : '—';
            var openBtn = qs(root, '[data-pos-open-cash]');
            if (openBtn) openBtn.classList.toggle('pos-hidden', !cash.can_open);
            var closeBtn = qs(root, '[data-pos-close-cash]');
            if (closeBtn) closeBtn.classList.toggle('pos-hidden', !cash.can_close);
            var closeExpected = qs(root, '[data-pos-close-expected]');
            if (closeExpected) closeExpected.textContent = cash.expected != null ? money(cash.expected) : '—';
            var openModal = qs(root, '[data-pos-open-modal]');
            if (openModal) openModal.classList.toggle('pos-hidden', !state.showOpenCash);
            var closeModal = qs(root, '[data-pos-close-modal]');
            if (closeModal) closeModal.classList.toggle('pos-hidden', !state.showCloseCash);
            var confirmOpen = qs(root, '[data-pos-confirm-open]');
            if (confirmOpen) {
                confirmOpen.disabled = state.busy;
                confirmOpen.textContent = state.busy ? 'Abriendo…' : 'Abrir turno';
            }
            var confirmClose = qs(root, '[data-pos-confirm-close]');
            if (confirmClose) {
                confirmClose.disabled = state.busy;
                confirmClose.textContent = state.busy ? 'Cerrando…' : 'Cerrar turno';
            }

            qs(root, '[data-pos-subtotal]').textContent = money(subtotal());
            qs(root, '[data-pos-tax]').textContent = money(tax());
            qs(root, '[data-pos-total]').textContent = money(total());
            var taxLabel = qs(root, '[data-pos-tax-label]');
            if (taxLabel) {
                var anyIncluded = cfg.tax_included || cartList().some(function (i) { return lineIncluded(i); });
                taxLabel.textContent = anyIncluded
                    ? ('ITBIS (' + cfg.tax_rate + '% incluido)')
                    : ('ITBIS (' + cfg.tax_rate + '%)');
            }

            var charge = qs(root, '[data-pos-charge]');
            if (charge) charge.disabled = cartList().length === 0;
            var quoteSaveBtn = qs(root, '[data-pos-quote-save]');
            if (quoteSaveBtn) {
                quoteSaveBtn.disabled = cartList().length === 0;
                quoteSaveBtn.classList.toggle('pos-hidden', !cfg.quotes_enabled);
            }
            var quotesBtn = qs(root, '[data-pos-quotes]');
            if (quotesBtn) quotesBtn.classList.toggle('pos-hidden', !cfg.quotes_enabled);
            var quoteBanner = qs(root, '[data-pos-quote-banner]');
            if (quoteBanner) {
                quoteBanner.classList.toggle('pos-hidden', !state.quoteId);
                var codeEl = qs(root, '[data-pos-quote-code]');
                if (codeEl) codeEl.textContent = state.quoteCode || '';
            }
            var quotesModal = qs(root, '[data-pos-quotes-modal]');
            if (quotesModal) quotesModal.classList.toggle('pos-hidden', !state.showQuotes);
            var quoteSaveModal = qs(root, '[data-pos-quote-save-modal]');
            if (quoteSaveModal) quoteSaveModal.classList.toggle('pos-hidden', !state.showQuoteSave);
            var quotesList = qs(root, '[data-pos-quotes-list]');
            if (quotesList && state.showQuotes) {
                if (!state.quotes.length) {
                    quotesList.innerHTML = '<div class="pos-empty">No hay cotizaciones pendientes.</div>';
                } else {
                    quotesList.innerHTML = state.quotes.map(function (q) {
                        return '<button type="button" class="pos-quote-row" data-pos-load-quote="' + escapeHtml(q.id) + '">' +
                            '<span><strong>' + escapeHtml(q.code) + '</strong>' +
                            '<div class="muted">' + escapeHtml(q.customer) + (q.issue_date ? ' · ' + escapeHtml(q.issue_date) : '') +
                            (q.expired ? ' · vencida' : '') + '</div></span>' +
                            '<span>' + money(q.total) + '</span></button>';
                    }).join('');
                }
            }
            var confirmQuote = qs(root, '[data-pos-confirm-quote]');
            if (confirmQuote) {
                confirmQuote.disabled = state.busy;
                confirmQuote.textContent = state.busy ? 'Guardando…' : 'Guardar cotización';
            }

            var list = qs(root, '[data-pos-cart]');
            var items = cartList();
            if (!items.length) {
                list.innerHTML = '<div class="pos-empty">' + emptyCartMessage() + '</div>';
            } else {
                list.innerHTML = items.map(function (i) {
                    var image = i.image_url
                        ? '<div class="pos-product-thumb"><img src="' + escapeHtml(i.image_url) + '" alt="" loading="lazy" data-pos-product-image><span hidden>Sin foto</span></div>'
                        : '<div class="pos-product-thumb pos-product-thumb-empty" aria-hidden="true">Sin foto</div>';
                    return '<div class="pos-line" data-key="' + escapeHtml(i.key) + '">' +
                        image +
                        '<div class="pos-line-info" style="flex:1;min-width:0"><div>' + escapeHtml(i.name) + '</div>' +
                        '<div class="muted">' + money(i.price) +
                        (i.track_stock && i.stock <= 5 ? ' · Stock ' + i.stock : '') +
                        '</div></div>' +
                        '<input type="number" min="0.001" step="any" value="' + i.qty + '" data-pos-qty="' + i.key + '">' +
                        '<div style="width:6rem;text-align:right;font-weight:700">' + money(i.price * i.qty) + '</div>' +
                        '<button type="button" class="pos-btn-ghost" data-pos-remove="' + escapeHtml(i.key) + '">✕</button>' +
                        '</div>';
                }).join('');
                list.querySelectorAll('[data-pos-product-image]').forEach(function (image) {
                    image.addEventListener('error', function () {
                        image.hidden = true;
                        image.nextElementSibling.hidden = false;
                    }, { once: true });
                    if (image.complete && image.naturalWidth === 0) {
                        image.dispatchEvent(new Event('error'));
                    }
                });
            }

            var modal = qs(root, '[data-pos-checkout]');
            if (modal) modal.classList.toggle('pos-hidden', !state.showCheckout);
            var payTotal = qs(root, '[data-pos-pay-total]');
            if (payTotal) payTotal.textContent = money(total());
            var cashBox = qs(root, '[data-pos-cash-fields]');
            if (cashBox) cashBox.classList.toggle('pos-hidden', state.payment !== 'efectivo');
            var creditBox = qs(root, '[data-pos-credit-fields]');
            if (creditBox) creditBox.classList.toggle('pos-hidden', state.payment !== 'credito' && !cfg.business.requires_customer);
            var changeEl = qs(root, '[data-pos-change]');
            if (changeEl) {
                changeEl.textContent = money(change());
                changeEl.parentElement.classList.toggle('has', change() > 0);
            }
            var confirmBtn = qs(root, '[data-pos-confirm]');
            if (confirmBtn) {
                confirmBtn.disabled = state.busy;
                confirmBtn.textContent = state.busy ? 'Procesando…' : 'Confirmar e imprimir';
            }

            var vmodal = qs(root, '[data-pos-variants]');
            if (vmodal) {
                vmodal.classList.toggle('pos-hidden', !state.showVariants);
                var grid = qs(root, '[data-pos-variant-grid]');
                if (grid) {
                    grid.innerHTML = (state.variants || []).map(function (v) {
                        var attrs = Object.keys(v.attributes || {}).map(function (k) {
                            return k + ': ' + v.attributes[k];
                        }).join(' · ');
                        return '<div class="pos-var"><div><div><strong>' + escapeHtml(v.sku || 'VAR') + '</strong></div>' +
                            '<div class="muted">' + escapeHtml(attrs) + '</div>' +
                            '<div>' + money(v.price) + '</div>' +
                            '<div class="muted">Stock ' + v.stock + '</div></div>' +
                            '<button type="button" class="pos-btn pos-btn-primary" data-pos-variant="' + v.id + '">Agregar</button></div>';
                    }).join('');
                }
            }

            var toastEl = qs(root, '[data-pos-toast]');
            if (toastEl) {
                if (!state.toast) {
                    toastEl.classList.add('pos-hidden');
                } else {
                    toastEl.classList.remove('pos-hidden');
                    toastEl.className = 'pos-toast' + (state.toast.kind === 'ok' ? ' is-ok' : ' is-err');
                    qs(toastEl, '[data-pos-toast-title]').textContent = state.toast.title;
                    qs(toastEl, '[data-pos-toast-body]').textContent = state.toast.message || '';
                }
            }

            renderSuggestOnly();
        }

        root.addEventListener('click', function (ev) {
            var t = ev.target.closest('[data-pos-pick],[data-pos-remove],[data-pos-charge],[data-pos-confirm-open],[data-pos-confirm-close],[data-pos-confirm-quote],[data-pos-confirm],[data-pos-close-cash],[data-pos-open-cash],[data-pos-quotes],[data-pos-quote-save],[data-pos-load-quote],[data-pos-close],[data-pos-clear],[data-pos-variant],[data-pos-vehicle],[data-pos-add]');
            if (!t) return;
            if (t.hasAttribute('data-pos-pick')) {
                scan(t.getAttribute('data-pos-pick'));
            } else if (t.hasAttribute('data-pos-remove')) {
                delete state.cart[t.getAttribute('data-pos-remove')];
                render();
            } else if (t.hasAttribute('data-pos-clear')) {
                if (confirm('¿Limpiar la cuenta?')) {
                    state.cart = {};
                    render();
                    focusBarcode(true);
                }
            } else if (t.hasAttribute('data-pos-open-cash')) {
                state.showOpenCash = true;
                state.showCloseCash = false;
                state.showCheckout = false;
                render();
                var openingAmt = qs(root, '[data-pos-opening-amount]');
                if (openingAmt) setTimeout(function () { openingAmt.focus(); openingAmt.select(); }, 30);
            } else if (t.hasAttribute('data-pos-close-cash')) {
                state.showCloseCash = true;
                state.showOpenCash = false;
                state.showCheckout = false;
                var closingAmt = qs(root, '[data-pos-closing-amount]');
                if (closingAmt && cfg.cash && cfg.cash.expected != null) {
                    closingAmt.value = String(cfg.cash.expected);
                }
                render();
                if (closingAmt) setTimeout(function () { closingAmt.focus(); closingAmt.select(); }, 30);
            } else if (t.hasAttribute('data-pos-confirm-open')) {
                openCash();
            } else if (t.hasAttribute('data-pos-confirm-close')) {
                closeCash();
            } else if (t.hasAttribute('data-pos-quotes')) {
                state.showQuotes = true;
                state.showQuoteSave = false;
                state.showCheckout = false;
                fetchQuotes();
                render();
            } else if (t.hasAttribute('data-pos-quote-save')) {
                if (!cartList().length) return;
                state.showQuoteSave = true;
                state.showQuotes = false;
                state.showCheckout = false;
                render();
            } else if (t.hasAttribute('data-pos-confirm-quote')) {
                saveQuote();
            } else if (t.hasAttribute('data-pos-load-quote')) {
                loadQuote(t.getAttribute('data-pos-load-quote'));
            } else if (t.hasAttribute('data-pos-charge')) {
                if (!cfg.open_cash) {
                    if (cfg.cash && cfg.cash.can_open) {
                        toast('Caja cerrada', 'Abre el turno con el efectivo inicial para cobrar.', 'err');
                        state.showOpenCash = true;
                        state.showCheckout = false;
                        render();
                        var openingFocus = qs(root, '[data-pos-opening-amount]');
                        if (openingFocus) setTimeout(function () { openingFocus.focus(); openingFocus.select(); }, 30);
                        return;
                    }
                    toast('Caja cerrada', 'Abre la caja antes de cobrar en efectivo (puedes continuar con tarjeta/transferencia/fiado).', 'err');
                }
                state.showCheckout = true;
                state.tendered = '';
                state.payment = 'efectivo';
                render();
            } else if (t.hasAttribute('data-pos-close')) {
                state.showCheckout = false;
                state.showVariants = false;
                state.showOpenCash = false;
                state.showCloseCash = false;
                state.showQuotes = false;
                state.showQuoteSave = false;
                render();
                focusBarcode(false);
            } else if (t.hasAttribute('data-pos-confirm')) {
                checkout();
            } else if (t.hasAttribute('data-pos-variant')) {
                scan('', t.getAttribute('data-pos-variant'));
            } else if (t.hasAttribute('data-pos-add')) {
                var input = qs(root, '[data-pos-barcode]');
                scan((input && input.value) || '');
            } else if (t.hasAttribute('data-pos-vehicle')) {
                var make = qs(root, '[data-pos-make]');
                var model = qs(root, '[data-pos-model]');
                var year = qs(root, '[data-pos-year]');
                var url = cfg.api.vehicle + '?make=' + encodeURIComponent(make && make.value || '') +
                    '&model=' + encodeURIComponent(model && model.value || '') +
                    '&year=' + encodeURIComponent(year && year.value || '');
                api(url).then(function (data) {
                    var box = qs(root, '[data-pos-vehicle-results]');
                    if (!box) return;
                    box.classList.remove('pos-hidden');
                    box.innerHTML = (data.results || []).map(function (r) {
                        var code = r.oem_code || r.name;
                        return '<button type="button" data-pos-pick="' + escapeHtml(code) + '">' +
                            '<span><strong>' + escapeHtml(r.name) + '</strong><div class="muted">' +
                            escapeHtml(r.oem_code || 'Sin OEM') + '</div></span><span>' + money(r.price) + '</span></button>';
                    }).join('') || '<div class="pos-empty">Sin resultados</div>';
                });
            }
        });

        root.addEventListener('change', function (ev) {
            var qty = ev.target.getAttribute && ev.target.getAttribute('data-pos-qty');
            if (qty && state.cart[qty]) {
                var n = parseFloat(ev.target.value);
                if (isNaN(n) || n <= 0) n = 0.001;
                if (state.cart[qty].deduct_on_sale && n > Number(state.cart[qty].stock)) {
                    toast('Stock insuficiente', state.cart[qty].name, 'err');
                    n = Math.max(0.001, Number(state.cart[qty].stock));
                }
                state.cart[qty].qty = n;
                render();
            }
            if (ev.target.getAttribute && ev.target.getAttribute('data-pos-pay') !== null) {
                state.payment = ev.target.value;
                render();
            }
            if (ev.target.getAttribute && ev.target.getAttribute('data-pos-customer') !== null) {
                state.customerId = ev.target.value;
            }
        });

        root.addEventListener('input', function (ev) {
            if (ev.target.getAttribute && ev.target.getAttribute('data-pos-tendered') !== null) {
                state.tendered = ev.target.value;
                var changeEl = qs(root, '[data-pos-change]');
                if (changeEl) {
                    changeEl.textContent = money(change());
                    changeEl.parentElement.classList.toggle('has', change() > 0);
                }
            }
            if (ev.target.getAttribute && ev.target.getAttribute('data-pos-barcode') !== null) {
                search(ev.target.value.trim());
            }
        });

        var pickedWithKeys = false;
        root.addEventListener('keydown', function (ev) {
            if (!ev.target || ev.target.getAttribute('data-pos-barcode') === null) return;
            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                pickedWithKeys = true;
                state.highlight = Math.min(state.highlight + 1, state.suggestions.length - 1);
                renderSuggestOnly();
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                pickedWithKeys = true;
                state.highlight = Math.max(state.highlight - 1, 0);
                renderSuggestOnly();
            } else if (ev.key === 'Enter') {
                ev.preventDefault();
                if (pickedWithKeys && state.highlight >= 0 && state.suggestions[state.highlight]) {
                    scan(state.suggestions[state.highlight].id);
                } else {
                    scan(ev.target.value);
                }
                pickedWithKeys = false;
            } else if (ev.key === 'Escape') {
                state.showSuggest = false;
                pickedWithKeys = false;
                renderSuggestOnly();
            }
        });

        root.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter' || !ev.target) return;
            if (ev.target.getAttribute('data-pos-opening-amount') !== null) {
                ev.preventDefault();
                openCash();
            } else if (ev.target.getAttribute('data-pos-closing-amount') !== null) {
                ev.preventDefault();
                closeCash();
            }
        });

        document.addEventListener('hw-barcode', function (ev) {
            var code = ev.detail && ev.detail.code;
            if (!code) return;
            var input = qs(root, '[data-pos-barcode]');
            if (input) input.value = code;
            scan(code);
        });

        render();
        focusBarcode(false);
        try {
            var pending = new URLSearchParams(window.location.search).get('quote');
            if (pending && cfg.quotes_enabled) loadQuote(pending);
        } catch (e) {}
    };
})();
