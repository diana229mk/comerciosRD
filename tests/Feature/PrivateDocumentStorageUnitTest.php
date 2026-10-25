<?php

namespace Tests\Feature;

use App\Services\PrivateDocumentStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PrivateDocumentStorageUnitTest extends TestCase
{
    public function test_private_file_is_persisted_and_materialized_only_for_its_business(): void
    {
        Storage::fake('private');
        app()->instance('currentBusinessId', 'business-one');
        $path = 'imports/business-one/products.csv';
        Storage::disk('private')->put($path, 'sku,name'.PHP_EOL.'A-1,Test');

        $temporaryPath = app(PrivateDocumentStorage::class)->materializeForProcessing($path, 'imports');

        try {
            $this->assertSame('sku,name'.PHP_EOL.'A-1,Test', file_get_contents($temporaryPath));
            $this->assertSame(0600, fileperms($temporaryPath) & 0777);
            Storage::disk('private')->assertExists($path);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function test_private_file_processing_rejects_traversal_and_foreign_business_paths(): void
    {
        Storage::fake('private');
        app()->instance('currentBusinessId', 'business-one');

        $this->expectException(NotFoundHttpException::class);
        app(PrivateDocumentStorage::class)->materializeForProcessing(
            'imports/business-one/../../outside.csv',
            'imports',
        );
    }

    public function test_upload_reference_must_be_a_stored_file_in_the_same_business_directory(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('payables/invoices/business-two/invoice.pdf', 'other-tenant');

        $this->expectException(ValidationException::class);
        app(PrivateDocumentStorage::class)->assertStoredForBusiness(
            'payables/invoices/business-two/invoice.pdf',
            'payables/invoices',
            'business-one',
            'invoice_file',
        );
    }
}
