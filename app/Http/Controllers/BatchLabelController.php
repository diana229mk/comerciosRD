<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Picqer\Barcode\BarcodeGeneratorSVG;

class BatchLabelController extends Controller
{
    public function print(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->filter()->values()->all();
        $copies = max(1, (int) $request->query('copies', 1));

        $batches = ProductBatch::query()
            ->with('product:id,name,sku,price')
            ->whereIn('id', $ids)
            ->get();

        $gen = new BarcodeGeneratorSVG();
        $labels = [];
        foreach ($batches as $batch) {
            $code = $batch->code ?: (string) $batch->id;
            try {
                $svg = $gen->getBarcode($code, $gen::TYPE_CODE_128, 2, 40);
            } catch (\Throwable $e) {
                $svg = '<svg width="200" height="40"><text x="10" y="25">'.htmlspecialchars($code).'</text></svg>';
            }
            for ($i = 0; $i < $copies; $i++) {
                $labels[] = [
                    'product' => $batch->product?->name ?? '—',
                    'sku' => $batch->product?->sku,
                    'price' => (float) ($batch->product?->price ?? 0),
                    'batch' => $code,
                    'expires' => $batch->expires_at?->format('d/m/Y'),
                    'barcode_svg' => $svg,
                ];
            }
        }

        return view('print.batch-labels', ['labels' => $labels]);
    }
}
