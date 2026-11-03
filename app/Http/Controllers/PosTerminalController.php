<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\PosSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PosTerminalController extends Controller
{
    public function show(Request $request, string $tenant, PosSessionService $pos): View
    {
        $business = $this->business($request);

        return view('pos.terminal', [
            'business' => $business,
            'posConfig' => $pos->clientConfig($business),
            'embedded' => false,
        ]);
    }

    public function bootstrap(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        return response()->json($pos->clientConfig($this->business($request)));
    }

    public function search(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['ok' => true, 'suggestions' => []]);
        }

        return response()->json([
            'ok' => true,
            'suggestions' => $pos->suggest($this->business($request), $term),
        ]);
    }

    public function scan(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $variantId = (string) $request->input('variant_id', '');

        $result = $variantId !== ''
            ? $pos->scanVariant($this->business($request), $variantId)
            : $pos->scan($this->business($request), $code);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function vehicle(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'results' => $pos->searchByVehicle(
                $this->business($request),
                $request->query('make'),
                $request->query('model'),
                $request->query('year'),
            ),
        ]);
    }

    public function customers(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'customers' => $pos->searchCustomers((string) $request->query('q', '')),
        ]);
    }

    public function checkout(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        try {
            $business = $this->business($request);
            $sale = $pos->checkout($business, $request->all());
        } catch (InvalidArgumentException|ValidationException $e) {
            $retrySafe = false;
            try {
                $retrySafe = isset($business) && DB::transactionLevel() === 0
                    && ! DB::table('sale_checkout_requests')
                        ->where('business_id', $business->id)
                        ->where('request_key', (string) $request->input('idempotency_key'))->exists();
            } catch (Throwable) {
                // An unknown commit outcome must retain the original request identity.
            }

            return response()->json([
                'ok' => false,
                'retry_safe' => $retrySafe,
                'title' => 'No se pudo completar la venta',
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'title' => 'No se pudo completar la venta',
                'message' => 'No se pudo confirmar el resultado. Reintenta la misma solicitud, no generes otra venta.',
            ], 422);
        }

        $snapshot = app(CashSessionService::class)->posSnapshot($request->user());

        return response()->json([
            'ok' => true,
            'sale_id' => (string) $sale->id,
            'sale_number' => $sale->sale_number ?: $sale->number,
            'total' => (float) $sale->total,
            'change' => (float) ($sale->change_amount ?? $sale->change ?? 0),
            'print_url' => route('print.ticket', ['sale' => $sale->id]),
            'open_cash' => (bool) ($snapshot['open'] ?? false),
            'cash' => $snapshot,
        ]);
    }

    public function quotes(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        try {
            return response()->json([
                'ok' => true,
                'quotes' => $pos->listQuotes($this->business($request)),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'title' => 'Cotizaciones', 'message' => $e->getMessage()], 422);
        }
    }

    public function showQuote(Request $request, string $tenant, string $quote, PosSessionService $pos): JsonResponse
    {
        try {
            $payload = $pos->loadQuote($this->business($request), $quote);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'title' => 'No se pudo cargar', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $payload);
    }

    public function storeQuote(Request $request, string $tenant, PosSessionService $pos): JsonResponse
    {
        try {
            $quote = $pos->saveQuote($this->business($request), $request->all());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'title' => 'No se pudo cotizar', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'quote_id' => (string) $quote->id,
            'code' => $quote->code,
            'total' => (float) $quote->total,
        ]);
    }

    public function openCash(Request $request, string $tenant, CashSessionService $cash): JsonResponse
    {
        $this->assertCashPermission($request, 'open_cash');

        try {
            $cash->open(
                (float) $request->input('opening_amount', 0),
                filled($request->input('opening_notes')) ? (string) $request->input('opening_notes') : null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'title' => 'No se pudo abrir la caja',
                'message' => $e->getMessage(),
            ], 422);
        }

        $snapshot = $cash->posSnapshot($request->user());

        return response()->json([
            'ok' => true,
            'open_cash' => true,
            'cash' => $snapshot,
        ]);
    }

    public function closeCash(Request $request, string $tenant, CashSessionService $cash): JsonResponse
    {
        $this->assertCashPermission($request, 'close_cash');

        $session = $cash->currentOpen($request->user());
        if (! $session) {
            return response()->json([
                'ok' => false,
                'title' => 'Caja cerrada',
                'message' => 'No hay un turno abierto.',
            ], 422);
        }

        try {
            $closed = $cash->close(
                $session,
                (float) $request->input('closing_amount', 0),
                filled($request->input('closing_notes')) ? (string) $request->input('closing_notes') : null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'title' => 'No se pudo cerrar la caja',
                'message' => $e->getMessage(),
            ], 422);
        }

        $snapshot = $cash->posSnapshot($request->user());

        return response()->json([
            'ok' => true,
            'open_cash' => false,
            'cash' => $snapshot,
            'difference' => (float) $closed->difference,
            'expected' => (float) $closed->expected_amount,
            'closing_amount' => (float) $closed->closing_amount,
        ]);
    }

    protected function assertCashPermission(Request $request, string $permission): void
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canPerform($permission)) {
            abort(403, 'Tu puesto no puede abrir ni cerrar la caja.');
        }
    }

    protected function business(Request $request): Business
    {
        $business = $request->attributes->get('posBusiness');
        if (! $business instanceof Business) {
            abort(404);
        }

        return $business;
    }
}
