<?php

return [

    'target_concurrent_users' => max(1, (int) env('SCALE_TARGET_CONCURRENT_USERS', 10000)),

    /*
    |--------------------------------------------------------------------------
    | Costuras de escala (100k negocios a futuro)
    |--------------------------------------------------------------------------
    |
    | No shards todavía. Estas banderas dejan el código partido para:
    |  - sacar e-CF y contabilidad del request del cajero
    |  - resumir ventas por día (dashboard no recorre sale_items)
    |  - login con contexto de negocio
    |  - buscar productos detrás de un puerto reemplazable
    |
    | El cobro (stock + caja) sigue síncrono. Eso no se mueve a cola.
    |
    */

    /*
     | Si true, asiento contable y e-CF salen a la cola `sales` después del
     | COMMIT de la venta. Requiere worker:
     |   php artisan queue:work --queue=sales,reports,default
     |
     | Local/tests: false (inline, mismo comportamiento de siempre).
     | Producción: true cuando el worker esté supervisado.
     */
    'post_sale_queue' => env('SCALE_POST_SALE_QUEUE', false),

    'queues' => [
        'sales' => env('SCALE_QUEUE_SALES', 'sales'),
        'reports' => env('SCALE_QUEUE_REPORTS', 'reports'),
    ],

    /*
     | Driver del puerto App\Contracts\ProductSearch.
     | Hoy solo `mysql`. Mañana `meilisearch` sin tocar el POS.
     */
    'search_driver' => env('SCALE_SEARCH_DRIVER', 'mysql'),

    'dashboard_use_daily_summary' => env('SCALE_DASHBOARD_DAILY_SUMMARY', true),

    'auth' => [
        // true = el login de /admin exige el slug del negocio.
        // Deja el email global de users como identidad de plataforma,
        // no como llave de caja. Activar cuando un mismo correo deba
        // existir en varios comercios (requiere login_name en el pivot).
        'require_business_slug' => env('AUTH_REQUIRE_BUSINESS_SLUG', false),
    ],

    'metrics' => [
        'enabled' => env('SCALE_METRICS', true),
        'slow_ms' => (float) env('SCALE_METRICS_SLOW_MS', 200),
    ],

    // Tope de negocios hidratados en el switcher Filament de UN usuario.
    // Un cajero/dueño real tiene 1–4; esto es un guardrail, no el master.
    'user_tenant_limit' => (int) env('SCALE_USER_TENANT_LIMIT', 25),

    /*
     | POS sin estado (carrito en el navegador, API JSON).
     | 100 cajas concurrentes: un VPS con Redis + PHP-FPM basta.
     | 100k concurrentes: N VPS idénticos detrás de un load balancer;
     | el código no guarda el carrito en sesión/Livewire, así que sumar
     | máquinas suma capacidad. La base se parte después por business_id.
     */
    'pos' => [
        'search_per_minute' => (int) env('SCALE_POS_SEARCH_PER_MINUTE', 300),
        'checkout_per_minute' => (int) env('SCALE_POS_CHECKOUT_PER_MINUTE', 40),
    ],

];
