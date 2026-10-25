<?php

namespace App\Filament\Resources\BusinessUserResource\Pages;

use App\Filament\Resources\BusinessUserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditBusinessUser extends EditRecord
{
    protected static string $resource = BusinessUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('quitar')
                ->label('Quitar del negocio')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (string) $this->record->getKey() !== (string) auth()->id())
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }

                    if ((string) $this->record->getKey() === (string) auth()->id()) {
                        Notification::make()
                            ->title('No puedes quitarte a ti mismo')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->businesses()->detach($tenant->getKey());

                    Notification::make()
                        ->title('Usuario quitado del negocio')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $tenant = Filament::getTenant();

        if ($tenant) {
            $pivot = $this->record->businesses()
                ->where('businesses.id', $tenant->getKey())
                ->first()
                ?->pivot;

            $data['role'] = $pivot?->role ?? 'cashier';
            $data['pivot_is_active'] = (bool) ($pivot?->is_active ?? true);
            $data['branch_id'] = $pivot?->branch_id ?: null;
        }

        $data['password'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['role'], $data['pivot_is_active'], $data['branch_id'], $data['password_confirmation']);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw ValidationException::withMessages([
                'email' => 'No hay negocio activo.',
            ]);
        }

        $tenantId = (string) $tenant->getKey();
        $role = (string) ($this->data['role'] ?? 'cashier');
        $pivotActive = (bool) ($this->data['pivot_is_active'] ?? true);
        $branchRaw = $this->data['branch_id'] ?? null;
        $branchId = filled($branchRaw) ? (string) $branchRaw : null;

        // Guarda de no-elevación: solo un owner puede promover a owner.
        $currentRoleOfEditor = auth()->user()?->getRoleInCurrentBusiness();
        if ($role === 'owner' && $currentRoleOfEditor !== 'owner') {
            throw ValidationException::withMessages([
                'data.role' => 'No puedes asignar el puesto de dueño. Solo el dueño del negocio puede hacerlo.',
            ]);
        }

        $currentRole = $record->businesses()
            ->where('businesses.id', $tenantId)
            ->first()
            ?->pivot
            ?->role;

        // No dejar el negocio sin dueño
        if ($currentRole === 'owner' && ($role !== 'owner' || ! $pivotActive)) {
            $owners = User::query()
                ->whereHas('businesses', function ($q) use ($tenantId): void {
                    $q->where('businesses.id', $tenantId)
                        ->where('user_business.role', 'owner')
                        ->where('user_business.is_active', true);
                })
                ->count();

            if ($owners <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'Debe quedar al menos un dueño activo en el negocio.',
                ]);
            }
        }

        // Detectar cambio de password ANTES del update (para saber si hay
        // que forzar re-login del usuario editado, sin tocar al editor).
        $passwordChanged = array_key_exists('password', $data) && filled($data['password']);

        $record->update($data);

        // FASE 6 P0.3: syncWithoutDetaching en vez de updateExistingPivot
        // para no fallar silencioso si por cualquier razón el pivot no existe.
        // syncWithoutDetaching CREA la fila si falta y ACTUALIZA si existe,
        // sin desvincular al usuario de OTROS negocios.
        $record->businesses()->syncWithoutDetaching([
            $tenantId => [
                'role' => $role,
                'is_active' => $pivotActive,
                'branch_id' => $branchId,
            ],
        ]);

        // FASE 5: si se cambió la clave, invalidar SOLO las sesiones del
        // usuario editado (no del dueño que está haciendo el cambio).
        //  - Nunca se llama session:flush ni logoutOtherDevices global.
        //  - Si el editor se está editando a sí mismo, preservamos su sesión.
        if ($passwordChanged) {
            $record->forceFill(['password_changed_at' => now()])->saveQuietly();
            $this->invalidateOtherSessionsForUser((string) $record->getKey());
        }

        // P2.1: invalidar caché del middleware para que el efecto (rol,
        // is_active) sea inmediato en la próxima request del editado.
        \App\Http\Middleware\RefreshAuthenticatedContext::invalidateFor((string) $record->getKey());

        // P3.2: auditoría append-only del cambio.
        $changes = [];
        if (($currentRole ?? null) !== $role) {
            $changes['role'] = ['from' => $currentRole, 'to' => $role];
        }
        if ($passwordChanged) {
            $changes['password'] = 'changed';
        }
        if (! empty($data['email']) && $record->wasChanged('email')) {
            $changes['email'] = 'changed';
        }
        \App\Models\AuditLog::log(
            event: 'business_user.updated',
            subject: $record,
            properties: [
                'business_id' => $tenantId,
                'pivot_is_active' => $pivotActive,
                'branch_id' => $branchId,
                'changes' => $changes,
            ],
            description: "Actualizado usuario {$record->email} en el negocio {$tenantId}",
        );

        return $record->fresh();
    }

    /**
     * Borra las filas de la tabla `sessions` de un usuario específico,
     * preservando la sesión actual del editor (por si es el mismo).
     */
    protected function invalidateOtherSessionsForUser(string $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $currentSessionId = request()->session()->getId();
        $editorId = (string) (auth()->id() ?? '');

        \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when(
                $editorId === $userId && $currentSessionId !== '',
                fn ($q) => $q->where('id', '!=', $currentSessionId),
            )
            ->delete();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Usuario actualizado')
            ->body('Datos y rol del negocio guardados.');
    }
}
