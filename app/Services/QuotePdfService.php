<?php

namespace App\Services;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class QuotePdfService
{
    public function render(Quote $quote): string
    {
        $quote->loadMissing(['items.product', 'customer', 'priceList', 'business']);
        return Pdf::loadView('pdf.quote', ['quote' => $quote])->output();
    }

    public function download(Quote $quote): Response
    {
        $filename = 'Cotizacion-'.$quote->code.'.pdf';
        return response($this->render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
