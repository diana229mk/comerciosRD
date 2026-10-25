<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Planes de asientos (DOP / mes)
    |--------------------------------------------------------------------------
    |
    | El dueño o admin del plan siempre ocupa 1 asiento.
    | extras = usuarios adicionales activos en user_business.
    | Negocios grandes: extra_seats fuera del plan, a extra_seat_dop.
    |
    */

    'currency' => 'DOP',

    'plans' => [
        'esencial' => [
            'label' => 'Esencial',
            'price' => 700,
            'included_seats' => 2, // 1 usuario del negocio + 1 extra
            'summary' => '1 usuario del negocio + 1 usuario extra',
        ],
        'equipo' => [
            'label' => 'Equipo',
            'price' => 1000,
            'included_seats' => 3, // 1 admin + 2 extras
            'summary' => '1 administrador + 2 usuarios extra',
        ],
        'completo' => [
            'label' => 'Completo',
            'price' => 1300,
            'included_seats' => 4, // 1 admin + 3 extras
            'summary' => '1 administrador + 3 usuarios extra',
        ],
    ],

    'extra_seat_dop' => 300,

    'default_plan' => 'esencial',

];
