<?php

namespace App\Http\Controllers;

use App\Models\Payable;
use App\Models\Prescription;
use App\Services\PrivateDocumentStorage;
use App\Services\TenantDocumentAuthorizer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateDocumentController extends Controller
{
    public function payableInvoice(
        string $tenant,
        string $payable,
        PrivateDocumentStorage $storage,
        TenantDocumentAuthorizer $authorizer,
    ): StreamedResponse {
        $business = $authorizer->authorizeTenant($tenant, 'view_payables');
        $record = Payable::withoutBusinessScope()->whereKey($payable)->firstOrFail();
        $authorizer->assertRecordBelongsToBusiness($record, $business);

        return $storage->download(
            (string) $record->invoice_file,
            (string) $business->getKey(),
            'payables/invoices',
            ['payables/invoices'],
            'factura-proveedor-'.$record->getKey().$this->extension($record->invoice_file),
        );
    }

    public function prescriptionImage(
        string $tenant,
        string $prescription,
        PrivateDocumentStorage $storage,
        TenantDocumentAuthorizer $authorizer,
    ): StreamedResponse {
        $business = $authorizer->authorizeTenant($tenant, 'view_sales');
        abort_unless($business->isPharmacy(), 404);
        $record = Prescription::withoutBusinessScope()->whereKey($prescription)->firstOrFail();
        $authorizer->assertRecordBelongsToBusiness($record, $business);

        return $storage->download(
            (string) $record->image_path,
            (string) $business->getKey(),
            'prescriptions',
            ['prescriptions'],
            'receta-'.$record->getKey().$this->extension($record->image_path),
        );
    }

    private function extension(?string $path): string
    {
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        return in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? '.'.$extension
            : '';
    }
}
