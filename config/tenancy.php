<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Forzar Global Scope
    |--------------------------------------------------------------------------
    |
    | Si true, BusinessScope lanza RuntimeException cuando hay usuario
    | autenticado (no master) sin business_id resuelto.
    | En producción SIEMPRE debe estar true (o confiar en environment).
    |
    */
    'enforce_scope' => env('TENANCY_ENFORCE_SCOPE', true),

    /*
    |--------------------------------------------------------------------------
    | Nombre de la columna de tenant
    |--------------------------------------------------------------------------
    */
    'column' => 'business_id',

    /*
    |--------------------------------------------------------------------------
    | Tabla pivote user ↔ business
    |--------------------------------------------------------------------------
    */
    'pivot_table' => 'user_business',

    /*
    |--------------------------------------------------------------------------
    | Roles válidos dentro de un negocio (pivot user_business.role)
    |--------------------------------------------------------------------------
    |
    | Independientes de Spatie Permission (roles globales / master).
    | Estos roles son por-comercio y viven en la pivote.
    |
    */
    'roles' => [
        'owner',
        'admin',
        'manager',
        'cashier',
        'inventory',
        'accountant',
        'delivery',
    ],

];
