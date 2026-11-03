<?php

namespace Tests\Feature;

use App\Enums\EcfType;
use App\Filament\Pages\EcfSettings;
use App\Filament\Resources\EcfSequenceResource;
use App\Filament\Resources\ElectronicInvoiceResource;
use App\Jobs\IssueElectronicInvoiceJob;
use App\Jobs\PostSaleAccountingJob;
use App\Models\AccountingOutbox;
use App\Models\Business;
use App\Models\EcfSequence;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Services\AccountingOutboxService;
use App\Services\DgiiSubmissionService;
use App\Services\EcfService;
use App\Services\EcfXmlGenerator;
use App\Support\EcfFeature;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EcfDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecf.enabled' => false,
            'ecf.validation_approved' => false,
        ]);
    }

    public function test_ecf_stays_disabled_by_default_and_cannot_be_enabled_by_env_flag_alone(): void
    {
        $this->assertFalse(EcfFeature::enabled());

        config(['ecf.enabled' => true]);

        $this->assertFalse(EcfFeature::enabled());
    }

    public function test_ecf_administration_and_deletion_are_unavailable_while_disabled(): void
    {
        $this->assertFalse(EcfSettings::canAccess());
        $this->assertFalse(EcfSequenceResource::canViewAny());
        $this->assertFalse(ElectronicInvoiceResource::canDelete(null));
    }

    public function test_invoice_creation_numbering_and_document_generation_are_blocked(): void
    {
        $business = new Business;
        $sale = new Sale;
        $invoice = new ElectronicInvoice;

        $operations = [
            fn () => EcfService::getNextNumber($business, EcfType::CONSUMO),
            fn () => EcfService::createFromSale($sale, EcfType::CONSUMO),
            fn () => EcfService::createCreditNoteForReturn($sale, ['allocation-id' => 1]),
            fn () => EcfService::generatePdf($invoice),
            fn () => EcfService::regenerateXml($invoice),
            fn () => (new EcfSequence)->allocateNextNumber(),
            fn () => EcfXmlGenerator::generate($invoice),
            fn () => EcfXmlGenerator::generateAndStore($invoice),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('An e-CF operation was not blocked.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Las operaciones e-CF están temporalmente deshabilitadas.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_dgii_sign_auth_submit_and_status_requests_are_blocked_before_http(): void
    {
        Http::fake();
        $service = app(DgiiSubmissionService::class);
        $invoice = new ElectronicInvoice;

        $operations = [
            fn () => $service->signXml('<ECF></ECF>'),
            fn () => $service->authenticate(),
            fn () => $service->submit($invoice),
            fn () => $service->checkStatus('track-id'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A DGII operation was not blocked.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Las operaciones e-CF están temporalmente deshabilitadas.',
                    $exception->getMessage()
                );
            }
        }

        Http::assertNothingSent();
    }

    public function test_pending_ecf_jobs_are_not_dispatched_or_executed_but_accounting_job_remains_available(): void
    {
        config(['scale.post_sale_queue' => true]);
        Bus::fake();

        $sale = new Sale;
        $sale->id = 'sale-id';
        $sale->business_id = 'business-id';
        $event = new AccountingOutbox;
        $outbox = \Mockery::mock(AccountingOutboxService::class);
        $outbox->shouldReceive('register')->once()->with($sale)->andReturn($event);
        $outbox->shouldReceive('dispatchAfterCommit')->once()->with($event);
        $this->app->instance(AccountingOutboxService::class, $outbox);

        IssueElectronicInvoiceJob::dispatchFor($sale);
        PostSaleAccountingJob::dispatchFor($sale);
        (new IssueElectronicInvoiceJob('business-id', 'sale-id'))->handle();

        Bus::assertNotDispatched(IssueElectronicInvoiceJob::class);
    }
}
