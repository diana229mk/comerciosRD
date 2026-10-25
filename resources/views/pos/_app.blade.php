@php
    /** @var array $posConfig */
    $cfg = $posConfig ?? [];
    $biz = $cfg['business'] ?? [];
@endphp
<link rel="stylesheet" href="{{ asset('css/pos-terminal.css') }}?v={{ @filemtime(public_path('css/pos-terminal.css')) ?: time() }}">
<div id="pos-root" class="pos-shell {{ ! empty($embedded) ? 'pos-embedded' : '' }}" data-pos-root>
    <div class="pos-grid">
        <div class="pos-maincol">
            <div class="pos-card pos-pad">
                <label class="pos-label">Escanear / Buscar producto</label>
                <div class="pos-rel">
                    <div class="pos-row">
                        <input
                            type="text"
                            class="pos-input"
                            data-pos-barcode
                            autofocus
                            autocomplete="off"
                            placeholder="{{ $cfg['placeholder'] ?? 'Código de barras, SKU o nombre...' }}"
                        >
                        <button type="button" class="pos-btn pos-btn-primary" data-pos-add>Agregar</button>
                    </div>
                    <div class="pos-suggest pos-hidden" data-pos-suggest></div>
                </div>

                @if (($biz['is_parts'] ?? false))
                    <div class="pos-vehicle">
                        <label class="pos-field">
                            <span class="pos-label">Marca</span>
                            <input class="pos-input" data-pos-make placeholder="Escribe la marca" autocomplete="off">
                        </label>
                        <label class="pos-field">
                            <span class="pos-label">Modelo</span>
                            <input class="pos-input" data-pos-model placeholder="Escribe el modelo" autocomplete="off">
                        </label>
                        <label class="pos-field">
                            <span class="pos-label">Año</span>
                            <input class="pos-input" data-pos-year placeholder="Año" inputmode="numeric" autocomplete="off">
                        </label>
                        <button type="button" class="pos-btn pos-btn-gray" data-pos-vehicle>Buscar vehículo</button>
                    </div>
                    <div class="pos-suggest pos-hidden" style="position:relative;margin-top:.5rem" data-pos-vehicle-results></div>
                @endif
            </div>

            <div class="pos-card" style="margin-top:1rem">
                <div class="pos-cart-head">
                    <span>Cuenta (<span data-pos-count>0</span>)</span>
                    <button type="button" class="pos-btn-ghost" data-pos-clear>Limpiar</button>
                </div>
                <div class="pos-cart-body" data-pos-cart>
                    <div class="pos-empty">{{ ($biz['is_parts'] ?? false) ? 'Busca por OEM, código, nombre o vehículo y pulsa Agregar.' : 'Escribe código o nombre y pulsa Agregar (o Enter).' }}</div>
                </div>
            </div>
        </div>

        <div class="pos-paycol">
            <div class="pos-card pos-pad">
                <div class="pos-meta"><span>Cajero</span><strong>{{ $cfg['cashier'] ?? auth()->user()?->name }}</strong></div>
                @if (! empty($cfg['branch']))
                    <div class="pos-meta"><span>Sucursal</span><strong>{{ $cfg['branch'] }}</strong></div>
                @endif
                <div class="pos-meta"><span>Caja</span><span data-pos-cash class="{{ ($cfg['open_cash'] ?? false) ? 'pos-ok' : 'pos-bad' }}">{{ ($cfg['open_cash'] ?? false) ? 'Abierta' : 'Cerrada' }}</span></div>
                <div class="pos-cash-extra {{ ($cfg['open_cash'] ?? false) ? '' : 'pos-hidden' }}" data-pos-cash-info>
                    <div class="pos-meta"><span>Apertura</span><span data-pos-cash-opening>{{ isset($cfg['cash']['opening_amount']) ? 'RD$ '.number_format((float) $cfg['cash']['opening_amount'], 2) : '—' }}</span></div>
                    <div class="pos-meta"><span>Desde</span><span data-pos-cash-since>{{ $cfg['cash']['opened_at'] ?? '—' }}</span></div>
                    <div class="pos-meta"><span>Abierta por</span><span data-pos-cash-by>{{ $cfg['cash']['opened_by'] ?? '—' }}</span></div>
                    <div class="pos-meta"><span>Efectivo del turno</span><span data-pos-cash-expected>{{ isset($cfg['cash']['expected']) ? 'RD$ '.number_format((float) $cfg['cash']['expected'], 2) : '—' }}</span></div>
                </div>
                <div class="pos-cash-actions">
                    <button type="button" class="pos-btn pos-btn-primary pos-btn-sm {{ ! empty($cfg['cash']['can_open']) ? '' : 'pos-hidden' }}" data-pos-open-cash>Abrir caja</button>
                    <button type="button" class="pos-btn pos-btn-gray pos-btn-sm {{ ! empty($cfg['cash']['can_close']) ? '' : 'pos-hidden' }}" data-pos-close-cash>Cerrar caja</button>
                </div>
            </div>
            <div class="pos-card pos-pad" style="margin-top:1rem">
                <div class="pos-meta"><span>Subtotal</span><span data-pos-subtotal>RD$ 0.00</span></div>
                <div class="pos-meta"><span data-pos-tax-label>ITBIS ({{ $cfg['tax_rate'] ?? 18 }}%{{ ! empty($cfg['tax_included']) ? ' incluido' : '' }})</span><span data-pos-tax>RD$ 0.00</span></div>
                <div class="pos-total"><span>TOTAL</span><span data-pos-total>RD$ 0.00</span></div>
            </div>
            <div class="pos-quote-banner pos-hidden" data-pos-quote-banner>
                Cobrar cotización <strong data-pos-quote-code></strong>
            </div>
            <div class="pos-charge-row">
                <button type="button" class="pos-btn pos-btn-gray {{ empty($cfg['quotes_enabled']) ? 'pos-hidden' : '' }}" data-pos-quote-save disabled>Cotizar</button>
                <button type="button" class="pos-btn pos-btn-success" data-pos-charge disabled>COBRAR</button>
            </div>
            <button type="button" class="pos-btn pos-btn-gray pos-btn-sm {{ empty($cfg['quotes_enabled']) ? 'pos-hidden' : '' }}" style="margin-top:.5rem" data-pos-quotes>Cotizaciones pendientes</button>
            <div class="pos-help" style="margin-top:.75rem">
                <p>• Primero abre la caja con el efectivo del turno.</p>
                <p>• Cotizar guarda la cuenta; luego cárgala y cobra.</p>
                <p>• Enter en el escáner agrega el producto.</p>
                <p>• Efectivo: escribe el monto y ve el vuelto.</p>
                <p>• Tras confirmar se imprime el ticket.</p>
                <p><a class="pos-hidden" data-pos-print-link target="_blank" rel="noopener">Abrir ticket</a></p>
            </div>
        </div>
    </div>

    <div class="pos-modal pos-hidden" data-pos-variants>
        <div class="pos-modal-card pos-modal-wide">
            <div class="pos-cart-head" style="padding:0 0 1rem">
                <h3>Seleccionar variante</h3>
                <button type="button" class="pos-btn-ghost" data-pos-close>✕</button>
            </div>
            <div class="pos-variants" data-pos-variant-grid></div>
        </div>
    </div>

    <div class="pos-modal pos-hidden" data-pos-open-modal>
        <div class="pos-modal-card">
            <div class="pos-cart-head" style="padding:0 0 1rem">
                <h2>Abrir caja</h2>
                <button type="button" class="pos-btn-ghost" data-pos-close>✕</button>
            </div>
            <p class="pos-help" style="margin:0 0 .75rem">Cuenta el efectivo con el que empiezas el turno.</p>
            <label class="pos-label">Monto de apertura</label>
            <input type="number" step="0.01" min="0" class="pos-num" data-pos-opening-amount value="0" placeholder="0.00">
            <label class="pos-label" style="margin-top:.75rem">Nota (opcional)</label>
            <input type="text" class="pos-input" data-pos-opening-notes placeholder="Cambio, fondo de caja…">
            <div class="pos-actions">
                <button type="button" class="pos-btn pos-btn-gray" data-pos-close>Cancelar</button>
                <button type="button" class="pos-btn pos-btn-success" data-pos-confirm-open>Abrir turno</button>
            </div>
        </div>
    </div>

    <div class="pos-modal pos-hidden" data-pos-close-modal>
        <div class="pos-modal-card">
            <div class="pos-cart-head" style="padding:0 0 1rem">
                <h2>Cerrar caja</h2>
                <button type="button" class="pos-btn-ghost" data-pos-close>✕</button>
            </div>
            <div class="pos-meta"><span>Debería haber</span><strong data-pos-close-expected>—</strong></div>
            <label class="pos-label" style="margin-top:.75rem">Efectivo contado</label>
            <input type="number" step="0.01" min="0" class="pos-num" data-pos-closing-amount placeholder="0.00">
            <label class="pos-label" style="margin-top:.75rem">Nota (opcional)</label>
            <input type="text" class="pos-input" data-pos-closing-notes placeholder="Sobrante, faltante, entrega…">
            <div class="pos-actions">
                <button type="button" class="pos-btn pos-btn-gray" data-pos-close>Cancelar</button>
                <button type="button" class="pos-btn pos-btn-success" data-pos-confirm-close>Cerrar turno</button>
            </div>
        </div>
    </div>

    <div class="pos-modal pos-hidden" data-pos-quotes-modal>
        <div class="pos-modal-card pos-modal-wide">
            <div class="pos-cart-head" style="padding:0 0 1rem">
                <h2>Cotizaciones pendientes</h2>
                <button type="button" class="pos-btn-ghost" data-pos-close>✕</button>
            </div>
            <p class="pos-help" style="margin:0 0 .75rem">Elige una para cargarla a la cuenta y cobrarla.</p>
            <div data-pos-quotes-list></div>
        </div>
    </div>

    <div class="pos-modal pos-hidden" data-pos-quote-save-modal>
        <div class="pos-modal-card">
            <div class="pos-cart-head" style="padding:0 0 1rem">
                <h2>Guardar cotización</h2>
                <button type="button" class="pos-btn-ghost" data-pos-close>✕</button>
            </div>
            <p class="pos-help" style="margin:0 0 .75rem">No cobra ni descuenta stock. El cliente puede volver a pagarla.</p>
            <label class="pos-label">Cliente (opcional)</label>
            <select class="pos-select" data-pos-quote-customer>
                <option value="">Sin cliente / mostrador</option>
                @foreach (($cfg['customers'] ?? []) as $c)
                    <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                @endforeach
            </select>
            <label class="pos-label" style="margin-top:.75rem">Nombre en la cotización</label>
            <input type="text" class="pos-input" data-pos-quote-name placeholder="Taller, placa o cliente…">
            <div class="pos-actions">
                <button type="button" class="pos-btn pos-btn-gray" data-pos-close>Cancelar</button>
                <button type="button" class="pos-btn pos-btn-success" data-pos-confirm-quote>Guardar cotización</button>
            </div>
        </div>
    </div>

    <div class="pos-modal pos-hidden" data-pos-checkout>
        <div class="pos-modal-card">
            <div class="pos-cart-head" style="padding:0 0 1rem">
                <h2>Cobrar venta</h2>
                <button type="button" class="pos-btn-ghost" data-pos-close>✕</button>
            </div>
            <div class="pos-empty" style="padding:1rem">
                <div class="muted">Total a cobrar</div>
                <div class="pos-pay" data-pos-pay-total>RD$ 0.00</div>
            </div>
            <label class="pos-label">Método de pago</label>
            <select class="pos-select" data-pos-pay>
                @foreach (($cfg['payment_methods'] ?? []) as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <div data-pos-credit-fields class="pos-hidden" style="margin-top:.75rem">
                <label class="pos-label">Cliente (fiado)</label>
                <select class="pos-select" data-pos-customer>
                    <option value="">Seleccionar cliente…</option>
                    @foreach (($cfg['customers'] ?? []) as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div data-pos-cash-fields style="margin-top:.75rem">
                <label class="pos-label">¿Con cuánto paga?</label>
                <input type="number" step="0.01" min="0" class="pos-num" data-pos-tendered placeholder="0.00">
                <div class="pos-change">
                    <span>Vuelto</span>
                    <strong data-pos-change>RD$ 0.00</strong>
                </div>
            </div>
            <div class="pos-actions">
                <button type="button" class="pos-btn pos-btn-gray" data-pos-close>Cancelar</button>
                <button type="button" class="pos-btn pos-btn-success" data-pos-confirm>Confirmar e imprimir</button>
            </div>
        </div>
    </div>

    <div class="pos-toast pos-hidden" data-pos-toast>
        <strong data-pos-toast-title></strong>
        <div data-pos-toast-body></div>
    </div>
</div>
<script src="{{ asset('js/barcode-scanner.js') }}?v={{ @filemtime(public_path('js/barcode-scanner.js')) ?: time() }}"></script>
<script src="{{ asset('js/pos-terminal.js') }}?v={{ @filemtime(public_path('js/pos-terminal.js')) ?: time() }}"></script>
<script>
    mountPosTerminal(document.getElementById('pos-root'), @json($cfg));
</script>
