<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\Payable;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateDocumentAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
    }

    public function test_authorized_business_member_can_download_a_private_payable_invoice(): void
    {
        [$owner, $business] = $this->createBusinessMember('owner');
        $path = 'payables/invoices/'.$business->id.'/invoice.pdf';
        Storage::disk('private')->put($path, 'invoice-bytes');
        $payable = $this->createPayable($business, $path);

        $response = $this->actingAs($owner)->get(route('private-documents.payables.invoice', [
            'tenant' => $business->slug,
            'payable' => $payable->id,
        ]));

        $response->assertOk()->assertDownload('factura-proveedor-'.$payable->id.'.pdf');
        $this->assertSame('invoice-bytes', $response->streamedContent());
    }

    public function test_private_document_requires_authentication_and_business_permission(): void
    {
        [$owner, $business] = $this->createBusinessMember('owner');
        $payable = $this->createPayable($business, 'payables/invoices/'.$business->id.'/invoice.pdf');
        Storage::disk('private')->put($payable->invoice_file, 'invoice-bytes');
        $url = route('private-documents.payables.invoice', [
            'tenant' => $business->slug,
            'payable' => $payable->id,
        ]);

        $this->get($url)->assertRedirect();

        [$cashier] = $this->createBusinessMember('cashier', $business);
        $this->actingAs($cashier)->get($url)->assertRedirect('/caja/'.$business->slug);
    }

    public function test_user_cannot_download_another_business_document(): void
    {
        [$ownerA, $businessA] = $this->createBusinessMember('owner');
        [$ownerB, $businessB] = $this->createBusinessMember('owner');
        $path = 'payables/invoices/'.$businessA->id.'/invoice.pdf';
        Storage::disk('private')->put($path, 'business-a');
        $payable = $this->createPayable($businessA, $path);

        $response = $this->actingAs($ownerB)->get(route('private-documents.payables.invoice', [
            'tenant' => $businessA->slug,
            'payable' => $payable->id,
        ]));

        $response->assertRedirect('/admin/'.$businessB->slug);
        $this->assertStringNotContainsString('business-a', $response->getContent());
    }

    public function test_inactive_user_cannot_download_a_document(): void
    {
        [$owner, $business] = $this->createBusinessMember('owner');
        $payable = $this->createPayable($business, 'payables/invoices/'.$business->id.'/invoice.pdf');
        Storage::disk('private')->put($payable->invoice_file, 'invoice-bytes');
        $owner->forceFill(['is_active' => false])->save();

        $this->actingAs($owner)->get(route('private-documents.payables.invoice', [
            'tenant' => $business->slug,
            'payable' => $payable->id,
        ]))->assertForbidden();
    }

    public function test_document_path_traversal_is_rejected(): void
    {
        [$owner, $business] = $this->createBusinessMember('owner');
        $payable = $this->createPayable(
            $business,
            'payables/invoices/'.$business->id.'/../../outside.pdf',
        );
        Storage::disk('private')->put('outside.pdf', 'must-not-be-served');

        $response = $this->actingAs($owner)->get(route('private-documents.payables.invoice', [
            'tenant' => $business->slug,
            'payable' => $payable->id,
        ]));

        $response->assertRedirect('/admin/'.$business->slug);
        $this->assertStringNotContainsString('must-not-be-served', $response->getContent());
    }

    public function test_authorized_pharmacy_member_can_download_a_private_prescription_image(): void
    {
        [$owner, $business] = $this->createBusinessMember('owner', null, BusinessType::Farmacia);
        $path = 'prescriptions/'.$business->id.'/prescription.png';
        Storage::disk('private')->put($path, 'prescription-bytes');
        $prescription = Prescription::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'code' => 'RX-TEST-0001',
            'patient_name' => 'Paciente',
            'doctor_name' => 'Doctora',
            'issued_at' => today()->toDateString(),
            'image_path' => $path,
        ]);

        $response = $this->actingAs($owner)->get(route('private-documents.prescriptions.image', [
            'tenant' => $business->slug,
            'prescription' => $prescription->id,
        ]));

        $response->assertOk()->assertDownload('receta-'.$prescription->id.'.png');
        $this->assertSame('prescription-bytes', $response->streamedContent());
    }

    public function test_private_files_persist_and_legacy_public_files_remain_authorized_until_migration(): void
    {
        [$owner, $business] = $this->createBusinessMember('owner');
        $legacyPath = 'payables/invoices/'.$business->id.'/legacy.pdf';
        Storage::disk('public')->put($legacyPath, 'legacy-bytes');
        $payable = $this->createPayable($business, $legacyPath);

        Storage::disk('private')->put('payables/invoices/'.$business->id.'/stored.pdf', 'persisted');
        Storage::disk('private')->assertExists('payables/invoices/'.$business->id.'/stored.pdf');

        $response = $this->actingAs($owner)->get(route('private-documents.payables.invoice', [
            'tenant' => $business->slug,
            'payable' => $payable->id,
        ]));
        $response->assertOk();
        $this->assertSame('legacy-bytes', $response->streamedContent());
    }

    /**
     * @return array{0: User, 1: Business}
     */
    private function createBusinessMember(
        string $role,
        ?Business $business = null,
        BusinessType $type = BusinessType::General,
    ): array {
        $user = User::factory()->create(['is_active' => true]);
        $business ??= Business::create([
            'name' => 'Negocio de prueba '.uniqid(),
            'type' => $type,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => $role, 'is_active' => true]);

        return [$user, $business];
    }

    private function createPayable(Business $business, string $path): Payable
    {
        return Payable::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'supplier_name' => 'Proveedor',
            'description' => 'Factura de prueba',
            'amount' => 100,
            'paid_amount' => 0,
            'balance' => 100,
            'due_date' => today()->toDateString(),
            'status' => 'pendiente',
            'invoice_file' => $path,
        ]);
    }
}
