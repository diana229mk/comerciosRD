<?php

namespace App\Filament\Master\Resources\BusinessResource\Pages;

use App\Filament\Master\Resources\BusinessResource;
use App\Models\Business;
use App\Models\MasterAuditLog;
use App\Models\User;
use App\Services\AccountingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateBusiness extends CreateRecord
{
    protected static string $resource = BusinessResource::class;

    protected ?string $ownerName = null;

    protected ?string $ownerEmail = null;

    protected ?string $ownerPassword = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Guardamos los datos del dueño para usarlos después
        $this->ownerName = $data['owner_name'] ?? null;
        $this->ownerEmail = isset($data['owner_email'])
            ? Str::lower(trim((string) $data['owner_email']))
            : null;
        $this->ownerPassword = $data['owner_password'] ?? null;

        // Quitamos estos campos para que no intenten guardarse en businesses
        unset($data['owner_name'], $data['owner_email'], $data['owner_password']);

        // El dueño se asigna en afterCreate
        unset($data['owner_id']);

        // Slug libre: vacío → desde el nombre; si choca (aa, gg…), sufijo -1, -2…
        $source = trim((string) ($data['slug'] ?? ''));
        if ($source === '') {
            $source = (string) ($data['name'] ?? 'comercio');
        }
        $data['slug'] = Business::uniqueSlug($source);

        // Módulos prioritarios según tipo si el Master no marcó ninguno
        if (empty($data['enabled_modules']) || ! is_array($data['enabled_modules'])) {
            $data['enabled_modules'] = Business::defaultModulesFor($data['type'] ?? 'general');
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $business = $this->record;

        // Seguridad: si por alguna razón no hay módulos, aplicar defaults del tipo
        if ($business instanceof Business) {
            $business->ensureDefaultModules();
        }

        try {
            DB::transaction(function () use ($business): void {
                if (! filled($this->ownerName) || ! filled($this->ownerEmail) || ! filled($this->ownerPassword)) {
                    throw new \RuntimeException('Faltan datos del usuario dueño.');
                }

                // 1. Crear el usuario dueño
                // password sin Hash::make: el cast 'hashed' del User ya cifra
                $owner = User::create([
                    'name' => $this->ownerName,
                    'email' => $this->ownerEmail,
                    'password' => $this->ownerPassword,
                    'is_active' => true,
                    'is_master_admin' => false,
                    'current_business_id' => $business->getKey(),
                ]);

                // 2. Sucursal Principal (schema de sucursales ya existe)
                $mainBranch = $business->ensureMainBranch();

                // 3. Asociar al negocio con rol owner + sucursal principal
                $owner->businesses()->attach($business->getKey(), [
                    'role' => 'owner',
                    'is_active' => true,
                    'joined_at' => now(),
                    'branch_id' => $mainBranch->getKey(),
                ]);

                // 4. owner_id del negocio
                $business->forceFill([
                    'owner_id' => $owner->getKey(),
                ])->save();

                // 5. Plan de cuentas por defecto
                try {
                    AccountingService::createDefaultAccounts($business);
                } catch (Throwable) {
                    // No bloquear la creación del negocio
                }
            });
        } catch (Throwable $e) {
            Notification::make()
                ->title('Negocio creado, pero falló el usuario dueño')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            MasterAuditLog::log(
                'create_business',
                $business,
                [],
                array_merge(
                    $business->only(['name', 'slug', 'type', 'is_active', 'owner_id']),
                    ['owner_error' => $e->getMessage()]
                )
            );

            return;
        }

        try {
            app(\App\Services\SubscriptionBillingService::class)->ensureCurrentInvoice($business->fresh());
        } catch (Throwable) {
        }

        MasterAuditLog::log(
            'create_business',
            $business->fresh(),
            [],
            array_merge(
                $business->fresh()->only(['name', 'slug', 'type', 'is_active', 'owner_id']),
                ['owner_email' => $this->ownerEmail]
            )
        );

        // 5. Notificación con datos de acceso (solo Master)
        Notification::make()
            ->title('Negocio y usuario dueño creados correctamente')
            ->body(
                "Negocio: {$business->name}\n".
                "Sucursal: Principal\n".
                "Dueño: {$this->ownerName}\n".
                "Email: {$this->ownerEmail}\n".
                "Contraseña temporal: {$this->ownerPassword}\n\n".
                'El dueño entra en /admin con ese correo. No se abre su sesión aquí para no cerrar el panel Master.'
            )
            ->success()
            ->persistent()
            ->send();
    }
}
