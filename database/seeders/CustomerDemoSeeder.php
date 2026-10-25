<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::where('slug', 'colmado-demo')->first();

        if (! $business) {
            $this->command?->warn('Business colmado-demo no existe.');

            return;
        }

        $customers = [
            [
                'name' => 'María Pérez',
                'phone' => '809-555-1001',
                'document' => '00112345678',
                'document_type' => 'cedula',
                'city' => 'Santo Domingo',
                'credit_limit' => 5000,
            ],
            [
                'name' => 'José Rodríguez',
                'phone' => '829-555-2002',
                'document' => '40299887766',
                'document_type' => 'cedula',
                'city' => 'Santiago',
                'credit_limit' => 0,
            ],
            [
                'name' => 'Mini Market El Sol SRL',
                'phone' => '809-555-3003',
                'document' => '131234567',
                'document_type' => 'rnc',
                'city' => 'La Vega',
                'credit_limit' => 25000,
            ],
        ];

        foreach ($customers as $data) {
            Customer::withoutBusinessScope()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'phone' => $data['phone'],
                ],
                array_merge($data, [
                    'business_id' => $business->id,
                    'is_active' => true,
                ])
            );
        }

        $this->command?->info('Clientes demo creados para colmado-demo.');
    }
}
