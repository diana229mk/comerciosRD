<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crea (o promueve) un usuario master admin en producción SIN usar
 * seeders con contraseñas conocidas.
 *
 * Uso interactivo:
 *   php artisan make:master-admin
 *
 * Uso no interactivo:
 *   php artisan make:master-admin \
 *     --email=root@empresa.com \
 *     --name="Root" \
 *     --password="ClaveMuySegura!123"
 */
class MakeMasterAdmin extends Command
{
    protected $signature = 'make:master-admin
        {--email= : Correo del master admin}
        {--name= : Nombre completo}
        {--password= : Contraseña (si se omite se pedirá interactivamente)}';

    protected $description = 'Crea o promueve un usuario a master admin del panel /master.';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo del master admin'))));
        $name = trim((string) ($this->option('name') ?: $this->ask('Nombre completo')));
        $password = (string) $this->option('password');

        if ($password === '') {
            $password = (string) $this->secret('Contraseña (mínimo 10 chars, mayús, minús, número y símbolo)');
            $confirm = (string) $this->secret('Confirmar contraseña');
            if ($password !== $confirm) {
                $this->error('Las contraseñas no coinciden.');

                return self::FAILURE;
            }
        }

        $rules = [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::defaults()],
        ];

        $validator = Validator::make(compact('email', 'name', 'password'), $rules);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $err) {
                $this->error('✖ '.$err);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            if (! $this->confirm("El correo {$email} ya existe. ¿Promoverlo a master admin y actualizar su contraseña?", false)) {
                $this->warn('Cancelado.');

                return self::SUCCESS;
            }

            $existing->forceFill([
                'name' => $name,
                'password' => $password, // cast 'hashed' del User
                'is_master_admin' => true,
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->info("✓ Usuario {$email} promovido a master admin.");

            return self::SUCCESS;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_master_admin' => true,
            'is_active' => true,
        ]);

        $this->info("✓ Master admin creado: {$email}");
        $this->line('Entra en '.url('/master/login'));

        return self::SUCCESS;
    }
}
