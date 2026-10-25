<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductDemoSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::where('slug', 'colmado-demo')->first();

        if (! $business) {
            $this->command?->warn('Business colmado-demo no existe. Omite ProductDemoSeeder.');

            return;
        }

        // Crear sin depender del Global Scope (CLI sin tenant)
        $items = [
            [
                'name' => 'Arroz Selecto 5 lb',
                'sku' => 'ARR-5LB',
                'barcode' => '746000000001',
                'price' => 185.00,
                'cost' => 140.00,
                'stock' => 48,
                'min_stock' => 10,
                'unit' => 'paquete',
            ],
            [
                'name' => 'Aceite de soya 1 galón',
                'sku' => 'ACE-1G',
                'barcode' => '746000000002',
                'price' => 520.00,
                'cost' => 410.00,
                'stock' => 20,
                'min_stock' => 5,
                'unit' => 'galon',
            ],
            [
                'name' => 'Habichuela roja 1 lb',
                'sku' => 'HAB-1LB',
                'barcode' => '746000000003',
                'price' => 65.00,
                'cost' => 48.00,
                'stock' => 3,
                'min_stock' => 8,
                'unit' => 'libra',
            ],
            [
                'name' => 'Huevos cartón 30 und',
                'sku' => 'HUE-30',
                'barcode' => '746000000004',
                'price' => 280.00,
                'cost' => 230.00,
                'stock' => 15,
                'min_stock' => 4,
                'unit' => 'caja',
            ],
            [
                'name' => 'Refresco 2 litros',
                'sku' => 'REF-2L',
                'barcode' => '746000000005',
                'price' => 95.00,
                'cost' => 70.00,
                'stock' => 36,
                'min_stock' => 12,
                'unit' => 'unidad',
            ],
        ];

        foreach ($items as $item) {
            Product::withoutBusinessScope()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'sku' => $item['sku'],
                ],
                array_merge($item, [
                    'business_id' => $business->id,
                    'is_active' => true,
                    'track_stock' => true,
                ])
            );
        }

        $this->command?->info('Productos demo creados para colmado-demo.');
    }
}
