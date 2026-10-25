<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ControlledDrugLedgerEntry;
use App\Models\Product;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class DigemapsReportController extends Controller
{
    public function ledger(Request $request)
    {
        $tenant = Filament::getTenant() ?? Business::query()->find(session('current_business_id'));
        abort_unless($tenant, 404);
        abort_unless($tenant instanceof Business && $tenant->is_active && $tenant->isPharmacy(), 404);
        abort_unless($tenant->hasModule('reports'), 403);

        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessTenant($tenant), 404);

        $role = $user->roleInBusiness((string) $tenant->getKey());
        if ($role === 'manager') {
            $role = 'supervisor';
        }
        abort_unless(in_array($role, ['owner', 'admin', 'supervisor'], true), 403);

        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());
        $anexo = $request->query('anexo'); // anexo_i, anexo_ii, anexo_iii, psicotropico
        $productId = $request->query('product_id');

        $q = ControlledDrugLedgerEntry::query()
            ->with('product:id,name,sku,controlled_type')
            ->where('business_id', $tenant->id)
            ->whereBetween('recorded_at', [$from.' 00:00:00', $to.' 23:59:59']);

        if ($anexo) {
            $q->where('controlled_type', $anexo);
        }
        if ($productId) {
            $q->where('product_id', $productId);
        }

        $entries = $q->orderBy('product_id')->orderBy('recorded_at')->orderBy('id')->get();
        $grouped = $entries->groupBy('product_id');

        $products = Product::query()
            ->whereIn('id', $grouped->keys())
            ->get()->keyBy('id');

        $pdf = Pdf::loadView('pdf.digemaps-ledger', [
            'business' => $tenant,
            'from' => $from,
            'to' => $to,
            'anexo' => $anexo,
            'grouped' => $grouped,
            'products' => $products,
        ]);
        $pdf->setPaper('letter', 'landscape');

        return $pdf->stream('libro-digemaps-'.$from.'_'.$to.'.pdf');
    }
}
