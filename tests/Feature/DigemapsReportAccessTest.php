<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Business;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DigemapsReportAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.two_factor.enabled', true);
    }

    public function test_cashier_cannot_download_controlled_drug_ledger(): void
    {
        $business = $this->pharmacy();
        $user = User::factory()->create();
        $user->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);

        $this->actingAs($user)
            ->withSession([
                'current_business_id' => $business->id,
                EnsureTwoFactorVerified::SESSION_PASSED => true,
            ])
            ->get('/print/digemaps-ledger')
            ->assertRedirect();
    }

    public function test_controlled_drug_ledger_requires_completed_two_factor_challenge(): void
    {
        $business = $this->pharmacy();
        $user = User::factory()->create();
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $user->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->encryptSecret('TESTSECRET'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->withSession(['current_business_id' => $business->id])
            ->get('/print/digemaps-ledger')
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_controlled_drug_ledger_allows_authorized_pharmacy_owner(): void
    {
        $business = $this->pharmacy();
        $user = User::factory()->create();
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $this->actingAs($user)
            ->withSession([
                'current_business_id' => $business->id,
                EnsureTwoFactorVerified::SESSION_PASSED => true,
            ])
            ->get('/print/digemaps-ledger')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    protected function pharmacy(): Business
    {
        return Business::create([
            'name' => 'Farmacia Reporte DIGEMAPS',
            'type' => 'farmacia',
            'is_active' => true,
        ]);
    }
}
