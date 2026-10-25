<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política de contraseñas
    |--------------------------------------------------------------------------
    */
    'password' => [
        'min_length' => 10,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_number' => true,
        'require_symbol' => true,
        // HaveIBeenPwned (recomendado true en producción)
        'uncompromised' => (bool) env('SECURITY_PASSWORD_UNCOMPROMISED', false),
        // Días antes de forzar rotación (0 = desactivado)
        'max_age_days' => (int) env('SECURITY_PASSWORD_MAX_AGE_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting de login (escalonado) — LoginThrottleService
    |--------------------------------------------------------------------------
    |
    | intentos => minutos de bloqueo
    |
    */
    'login_throttle' => [
        3 => 1,    // 3 fallos → 1 minuto
        5 => 15,   // 5 fallos → 15 minutos
        8 => 60,   // 8 fallos → 1 hora
        12 => 1440, // 12 fallos → 24 horas
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiter nombrado "login" (panel Admin)
    |--------------------------------------------------------------------------
    */
    'login_rate_limit' => [
        'per_minute_email_ip' => (int) env('SECURITY_LOGIN_PER_MINUTE_EMAIL_IP', 5),
        'per_minute_ip' => (int) env('SECURITY_LOGIN_PER_MINUTE_IP', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mensaje genérico de credenciales (no revelar si el email existe)
    |--------------------------------------------------------------------------
    */
    'login_failed_message' => 'Estas credenciales no coinciden con nuestros registros.',

    /*
    |--------------------------------------------------------------------------
    | Rate limiting del login del Panel Master (más estricto)
    |--------------------------------------------------------------------------
    */
    'master_login_throttle' => [
        // Por IP: ráfaga
        'per_minute' => (int) env('SECURITY_MASTER_LOGIN_PER_MINUTE', 5),
        // Por email: ráfaga (efectivo aunque cambien de IP/VPN)
        'per_email_minute' => (int) env('SECURITY_MASTER_LOGIN_PER_EMAIL_MINUTE', 5),
        // Por email: sostenido
        'per_email_hour' => (int) env('SECURITY_MASTER_LOGIN_PER_EMAIL_HOUR', 15),
        // Por IP: ventanas largas
        'per_hour' => (int) env('SECURITY_MASTER_LOGIN_PER_HOUR', 15),
        'per_day' => (int) env('SECURITY_MASTER_LOGIN_PER_DAY', 30),
        // No reenviar la misma alerta a masters más de 1 vez en N minutos
        'alert_cooldown_minutes' => (int) env('SECURITY_MASTER_LOGIN_ALERT_COOLDOWN', 15),
        // Escalera de bloqueo (fallos 24h → minutos de lockout) más dura
        'ladder' => [
            2 => 2,     // 2 fallos → 2 minutos
            4 => 15,    // 4 fallos → 15 minutos
            6 => 60,    // 6 fallos → 1 hora
            10 => 1440, // 10 fallos → 24 horas
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 2FA obligatorio por rol de negocio / master
    |--------------------------------------------------------------------------
    */
    'two_factor' => [
        // Feature disponible (páginas de setup/challenge). No fuerza a nadie por sí solo.
        'enabled' => env('SECURITY_2FA_ENABLED', true),
        // Roles que DEBEN tener 2FA. Vacío = opcional para todos los roles de negocio.
        // Ej. env: SECURITY_2FA_REQUIRED_ROLES=owner,admin,manager
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECURITY_2FA_REQUIRED_ROLES', ''))
        ))),
        // ¿Master admin obligado a 2FA? false = opcional (recomendado en local / hasta que el usuario lo active)
        'require_master' => (bool) env('SECURITY_2FA_REQUIRE_MASTER', false),
        'issuer' => env('SECURITY_2FA_ISSUER', 'ComerciosRD'),
        'window' => 1, // tolerancia de ventanas TOTP
    ],

    /*
    |--------------------------------------------------------------------------
    | RBAC — privilegios mínimos por rol (acciones lógicas)
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'owner' => ['*'],
        'admin' => [
            'products.*', 'categories.*', 'inventory_movements.*',
            'customers.*', 'sales.*', 'orders.*',
            'expenses.*', 'payables.*', 'receivables.*',
            'suppliers.*', 'purchases.*',
            'employees.*', 'reports.view', 'dashboard.customize',
        ],
        // Alias legacy (manager) + rol de UI "supervisor"
        'manager' => [
            'products.view', 'products.create', 'products.update',
            'categories.view', 'inventory_movements.view', 'inventory_movements.create',
            'customers.*', 'sales.*', 'orders.*',
            'expenses.view', 'expenses.create',
            'payables.view', 'receivables.view',
            'suppliers.view', 'purchases.view', 'purchases.create',
            'reports.view',
        ],
        'supervisor' => [
            'products.view', 'products.create', 'products.update',
            'categories.view', 'inventory_movements.view', 'inventory_movements.create',
            'customers.*', 'sales.*', 'orders.*',
            'expenses.view', 'expenses.create',
            'payables.view', 'receivables.view',
            'suppliers.view', 'purchases.view', 'purchases.create',
            'reports.view',
        ],
        'cashier' => [
            'products.view', 'customers.view', 'customers.create',
            'sales.view', 'sales.create', 'sales.cancel',
            'orders.view', 'orders.create', 'orders.update',
        ],
        'viewer' => [
            'products.view', 'categories.view', 'inventory_movements.view',
            'customers.view', 'sales.view', 'orders.view',
            'expenses.view', 'payables.view', 'receivables.view',
            'suppliers.view', 'purchases.view', 'reports.view',
        ],
        'inventory' => [
            'products.*', 'categories.*', 'inventory_movements.*', 'orders.view',
        ],
        'accountant' => [
            'sales.view', 'expenses.*', 'payables.*', 'receivables.*',
            'customers.view', 'reports.view', 'purchases.view', 'suppliers.view',
        ],
        'delivery' => [
            'orders.view', 'orders.update', 'customers.view',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Features por plan / tipo de negocio (CheckBusinessFeature)
    |--------------------------------------------------------------------------
    */
    'features' => [
        'pos' => true,
        'inventory' => true,
        'orders' => true,
        'expenses' => true,
        'payables' => true,
        'receivables' => ['distribuidora', 'almacen'],
        'expiry_tracking' => ['farmacia'],
        'reports' => true,
        'multi_user' => true,
        'api_access' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers de seguridad HTTP
    |--------------------------------------------------------------------------
    */
    'headers' => [
        // DENY reduce clickjacking; SAMEORIGIN si necesitas iframes same-site
        'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),
        'x_content_type_options' => 'nosniff',
        // Legacy XSS filter (defensa principal = CSP)
        'x_xss_protection' => env('SECURITY_X_XSS_PROTECTION', '1; mode=block'),
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
        'hsts_enabled' => env('SECURITY_HSTS', true),
        'hsts_max_age' => 31536000,
        'hsts_include_subdomains' => true,
        'hsts_preload' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    */
    'csp' => [
        'enabled' => env('SECURITY_CSP_ENABLED', true),
        'report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
        // Directivas base (Filament/Livewire/Vite requieren unsafe-inline en style)
        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-eval'", "'unsafe-inline'"],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net'],
            'connect-src' => ["'self'", 'wss:', 'ws:'],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session fingerprint (anti-hijacking)
    |--------------------------------------------------------------------------
    */
    'session_fingerprint' => [
        'enabled' => env('SECURITY_SESSION_FINGERPRINT', true),
        // Si el fingerprint no coincide: logout + audit
        'bind_user_agent' => true,
        'bind_ip' => env('SECURITY_FINGERPRINT_BIND_IP', false), // false en móviles (IP dinámica)
    ],

    /*
    |--------------------------------------------------------------------------
    | IPs autorizadas para Panel Master
    |--------------------------------------------------------------------------
    |
    | master_ip_whitelist_enabled=false → no se aplica (recomendado en setup)
    | true → solo IPs de master_allowed_ips + localhost
    |
    */
    'master_ip_whitelist_enabled' => (bool) env('SECURITY_MASTER_IP_WHITELIST_ENABLED', false),

    'master_allowed_ips' => array_filter(
        array_map('trim', explode(',', (string) env('SECURITY_MASTER_ALLOWED_IPS', '')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Sesión del Panel Master (minutos). Más corta que la del panel admin.
    | Preferir session.master_lifetime si está definido.
    |--------------------------------------------------------------------------
    */
    'master_session_lifetime' => (int) env('SECURITY_MASTER_SESSION_LIFETIME', 120),

    /*
    |--------------------------------------------------------------------------
    | Campos cifrados (documentación + cast en modelos)
    |--------------------------------------------------------------------------
    */
    'encrypted_fields' => [
        'customers' => ['phone', 'email', 'document', 'notes'],
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
        'accounts_receivable' => ['notes'],
        'accounts_payable' => ['notes'],
        'expenses' => ['notes'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertas de seguridad
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'log_channel' => env('SECURITY_ALERT_CHANNEL', 'stack'),
        'notify_mail' => env('SECURITY_ALERT_MAIL'),
        'brute_force_threshold' => 8,
        'cross_tenant_threshold' => 3, // intentos en ventana de 1h
    ],

];
