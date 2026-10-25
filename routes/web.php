<?php

use App\Http\Controllers\PosTerminalController;
use App\Http\Controllers\PrivateDocumentController;
use App\Http\Controllers\PublicMenuController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\RefreshAuthenticatedContext;
use Illuminate\Support\Facades\Route;

// Entrada al sistema: redirige al login principal del panel.
Route::get('/', function () {
    $to = app()->environment('production') ? '/admin/login' : '/master/login';

    return new \Illuminate\Http\RedirectResponse(url($to));
});

// Alias de Laravel `route('login')` → login del panel de comercio (caja/tickets/2FA).
Route::redirect('/login', '/admin/login')->name('login');

/*
|--------------------------------------------------------------------------
| Menú QR público (sin login). Solo carta: nombre y precio.
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'throttle:menu-public'])->group(function () {
    Route::get('/m/{slug}', [PublicMenuController::class, 'show'])->name('menu.public');
    Route::get('/m/{slug}/qr.svg', [PublicMenuController::class, 'qrSvg'])
        ->middleware('throttle:menu-assets')
        ->name('menu.qr');
    Route::get('/m/{slug}/cartel', [PublicMenuController::class, 'poster'])
        ->middleware('throttle:menu-assets')
        ->name('menu.poster');
});

/*
|--------------------------------------------------------------------------
| 2FA (fuera del prefijo tenant de Filament)
|--------------------------------------------------------------------------
*/
// Prefijo genérico (master + admin) + alias legado bajo /admin/two-factor
Route::middleware(['web', 'auth'])
    ->prefix('security/two-factor')
    ->group(function () {
        Route::get('/setup', [TwoFactorController::class, 'showSetup'])->name('two-factor.setup');
        Route::post('/setup', [TwoFactorController::class, 'enable'])
            ->middleware('throttle:two-factor')
            ->name('two-factor.enable');
        Route::get('/challenge', [TwoFactorController::class, 'showChallenge'])->name('two-factor.challenge');
        Route::post('/challenge', [TwoFactorController::class, 'verify'])
            ->middleware('throttle:two-factor')
            ->name('two-factor.verify');
    });

Route::middleware(['web', 'auth'])
    ->prefix('admin/two-factor')
    ->group(function () {
        Route::get('/setup', [TwoFactorController::class, 'showSetup']);
        Route::post('/setup', [TwoFactorController::class, 'enable'])->middleware('throttle:two-factor');
        Route::get('/challenge', [TwoFactorController::class, 'showChallenge']);
        Route::post('/challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:two-factor');
    });

/*
|--------------------------------------------------------------------------
| Caja liviana (sin Filament/Livewire). Carrito en el navegador.
| 100 cajas concurrentes hablan JSON; el PHP no guarda el ticket abierto.
|--------------------------------------------------------------------------
*/
Route::middleware([
    'web',
    'auth',
    RefreshAuthenticatedContext::class,
    EnsureTwoFactorVerified::class,
    'pos.tenant',
])
    ->prefix('caja/{tenant}')
    ->group(function () {
        Route::get('/', [PosTerminalController::class, 'show'])->name('pos.terminal');
        Route::get('/api/bootstrap', [PosTerminalController::class, 'bootstrap'])->name('pos.api.bootstrap');
        Route::middleware('throttle:pos-search')->group(function () {
            Route::get('/api/search', [PosTerminalController::class, 'search'])->name('pos.api.search');
            Route::post('/api/scan', [PosTerminalController::class, 'scan'])->name('pos.api.scan');
            Route::get('/api/vehicle', [PosTerminalController::class, 'vehicle'])->name('pos.api.vehicle');
            Route::get('/api/customers', [PosTerminalController::class, 'customers'])->name('pos.api.customers');
            Route::get('/api/quotes', [PosTerminalController::class, 'quotes'])->name('pos.api.quotes.index');
            Route::get('/api/quotes/{quote}', [PosTerminalController::class, 'showQuote'])->name('pos.api.quotes.show');
        });
        Route::post('/api/quotes', [PosTerminalController::class, 'storeQuote'])
            ->middleware('throttle:pos-checkout')
            ->name('pos.api.quotes.store');
        Route::post('/api/checkout', [PosTerminalController::class, 'checkout'])
            ->middleware('throttle:pos-checkout')
            ->name('pos.api.checkout');
        Route::post('/api/cash/open', [PosTerminalController::class, 'openCash'])
            ->middleware('throttle:pos-cash')
            ->name('pos.api.cash.open');
        Route::post('/api/cash/close', [PosTerminalController::class, 'closeCash'])
            ->middleware('throttle:pos-cash')
            ->name('pos.api.cash.close');
    });

/*
|--------------------------------------------------------------------------
| Tickets / Recibos PDF (80mm)
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth', EnsureTwoFactorVerified::class, 'throttle:documents'])
    ->prefix('admin/{tenant}/receipts')
    ->group(function () {
        Route::get('/sales/{sale}/pdf', [ReceiptController::class, 'salePdf'])
            ->name('receipts.sales.pdf');
        Route::get('/sales/{sale}/print', [ReceiptController::class, 'salePrint'])
            ->name('receipts.sales.print');
        Route::get('/orders/{order}/pdf', [ReceiptController::class, 'orderPdf'])
            ->name('receipts.orders.pdf');
        Route::get('/orders/{order}/print', [ReceiptController::class, 'orderPrint'])
            ->name('receipts.orders.print');
    });

/*
|--------------------------------------------------------------------------
| Adjuntos privados de negocio
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth', EnsureTwoFactorVerified::class, 'throttle:documents'])
    ->prefix('admin/{tenant}/private-documents')
    ->group(function () {
        Route::get('/payables/{payable}/invoice', [PrivateDocumentController::class, 'payableInvoice'])
            ->name('private-documents.payables.invoice');
        Route::get('/prescriptions/{prescription}/image', [PrivateDocumentController::class, 'prescriptionImage'])
            ->name('private-documents.prescriptions.image');
    });

/*
|--------------------------------------------------------------------------
| Imprimir ticket de venta en nueva pestaña (stream PDF 80mm)
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth', EnsureTwoFactorVerified::class, 'throttle:documents'])
    ->get('/print/ticket/{sale}', [ReceiptController::class, 'printTicket'])
    ->name('print.ticket');

/*
|--------------------------------------------------------------------------
| Etiquetas de lotes con código de barras
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth'])
    ->get('/print/batch-labels', [\App\Http\Controllers\BatchLabelController::class, 'print'])
    ->middleware('throttle:documents')
    ->name('print.batch-labels');

Route::middleware(['web', 'auth'])
    ->get('/print/price-labels', [\App\Http\Controllers\PriceLabelController::class, 'print'])
    ->middleware('throttle:documents')
    ->name('print.price-labels');

/*
|--------------------------------------------------------------------------
| Reporte DIGEMAPS oficial PDF
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth', EnsureTwoFactorVerified::class])
    ->get('/print/digemaps-ledger', [\App\Http\Controllers\DigemapsReportController::class, 'ledger'])
    ->middleware('throttle:documents')
    ->name('print.digemaps-ledger');
