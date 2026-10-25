<?php

namespace App\Filament\Resources\BusinessUserResource\Pages;

use App\Filament\Resources\BusinessUserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBusinessUser extends CreateRecord
{
    protected static string $resource = BusinessUserResource::class;

    protected ?string $role = 'cashier';

    protected bool $pivotIsActive = true;

    protected ?string $branchId = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Guarda de no-elevación: solo un owner puede crear a otro owner.
        $requestedRole = (string) ($data['role'] ?? 'cashier');
        if ($requestedRole === 'owner' && ! (auth()->user()?->hasRoleInBusiness(['owner']) ?? false)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'data.role' => 'No puedes asignar el puesto de dueño. Solo el dueño del negocio puede hacerlo.',
            ]);
        }

        $this->role = $requestedRole;
        $this->pivotIsActive = (bool) ($data['pivot_is_active'] ?? true);
        $branchRaw = $data['branch_id'] ?? null;
        $this->branchId = filled($branchRaw) ? (string) $branchRaw : null;

        if ($this->branchId === null) {
            $main = Filament::getTenant()?->mainBranch();
            if ($main) {
                $this->branchId = (string) $main->getKey();
            }
        }

        unset($data['role'], $data['pivot_is_active'], $data['branch_id'], $data['password_confirmation']);

        $data['is_active'] = true;
        $data['is_master_admin'] = false;

        $tenant = Filament::getTenant();
        if ($tenant) {
            $data['current_business_id'] = $tenant->getKey();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw ValidationException::withMessages([
                'email' => 'No hay negocio activo.',
            ]);
        }

        $seats = app(\App\Services\SeatLimitService::class);
        if ($this->pivotIsActive && ! $seats->canAdd($tenant)) {
            throw ValidationException::withMessages([
                'email' => $seats->denyMessage($tenant),
            ]);
        }

        $tenantId = (string) $tenant->getKey();

        return DB::transaction(function () use ($data, $tenantId): User {
            $existing = User::query()->where('email', $data['email'])->first();

            if ($existing) {
                $already = $existing->businesses()
                    ->where('businesses.id', $tenantId)
                    ->exists();

                if ($already) {
                    throw ValidationException::withMessages([
                        'email' => 'Este usuario ya pertenece a este negocio. Edítalo desde la lista.',
                    ]);
                }

                $update = [
                    'name' => $data['name'] ?? $existing->name,
                    'is_active' => true,
                ];
                if (! empty($data['password'])) {
                    $update['password'] = $data['password']; // cast hashed
                }
                $existing->forceFill($update)->save();

                $existing->businesses()->attach($tenantId, [
                    'role' => $this->role ?? 'cashier',
                    'is_active' => $this->pivotIsActive,
                    'branch_id' => $this->branchId,
                    'joined_at' => now(),
                ]);

                Notification::make()
                    ->title('Usuario existente asociado al negocio')
                    ->body('El correo ya existía; se vinculó a este comercio con el rol elegido.')
                    ->success()
                    ->send();

                return $existing->fresh();
            }

            /** @var User $user */
            $user = static::getModel()::query()->create($data);

            $user->businesses()->attach($tenantId, [
                'role' => $this->role ?? 'cashier',
                'is_active' => $this->pivotIsActive,
                'branch_id' => $this->branchId,
                'joined_at' => now(),
            ]);

            \App\Models\AuditLog::log(
                event: 'business_user.created',
                subject: $user,
                properties: [
                    'business_id' => $tenantId,
                    'role' => $this->role,
                    'branch_id' => $this->branchId,
                    'pivot_is_active' => $this->pivotIsActive,
                ],
                description: "Creado usuario {$user->email} en el negocio {$tenantId}",
            );

            return $user;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
