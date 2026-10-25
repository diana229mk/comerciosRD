<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permisos por Rol
    |--------------------------------------------------------------------------
    |
    | Matriz de consistencia (módulo + rol):
    |
    | products            → ver: quien tenga view_products
    |                       crear/editar: owner, admin, supervisor
    | categories          → ver: view_products
    |                       crear/editar: owner, admin, supervisor
    | orders / sales      → cashier, supervisor, admin, owner
    | customers           → ver: cashier+
    |                       crear/editar: supervisor, admin, owner
    | expenses / payables / receivables / inventory / suppliers / purchases
    |                     → supervisor, admin, owner
    | cash_register       → cashier, supervisor, admin, owner
    | employees           → solo owner, admin
    | accounting          → solo owner, admin
    | reports             → owner, admin, supervisor
    |
    | Claves de rol: owner, admin, supervisor, cashier, viewer
    | (manager se trata como alias de supervisor en User::canPerform)
    |
    */

    'roles' => [

        'owner' => ['*'], // El dueño puede hacer absolutamente todo

        'admin' => [
            // Ventas y caja
            'view_sales', 'create_sales', 'edit_sales', 'delete_sales',
            'view_cash', 'open_cash', 'close_cash',
            // Órdenes
            'view_orders', 'create_orders', 'edit_orders', 'delete_orders',
            // Inventario
            'view_products', 'create_products', 'edit_products', 'delete_products',
            'view_categories', 'create_categories', 'edit_categories', 'delete_categories',
            'view_inventory', 'adjust_inventory',
            // Clientes y cobros
            'view_customers', 'create_customers', 'edit_customers', 'delete_customers',
            'view_receivables', 'create_receivables', 'edit_receivables', 'delete_receivables',
            // Gastos y pagos
            'view_expenses', 'create_expenses', 'edit_expenses', 'delete_expenses',
            'view_payables', 'create_payables', 'edit_payables', 'delete_payables',
            // Compras
            'view_suppliers', 'create_suppliers', 'edit_suppliers', 'delete_suppliers',
            'view_purchases', 'create_purchases', 'edit_purchases', 'delete_purchases',
            // Usuarios del negocio
            'view_users', 'create_users', 'edit_users', 'delete_users',
            // Contabilidad
            'view_accounts', 'create_accounts', 'edit_accounts', 'delete_accounts',
            'view_journal', 'create_journal', 'edit_journal',
            'view_income_statement', 'view_balance_sheet',
            // Reportes
            'view_reports',
        ],

        'supervisor' => [
            // Ventas y caja
            'view_sales', 'create_sales', 'edit_sales',
            'view_cash', 'open_cash', 'close_cash',
            // Órdenes
            'view_orders', 'create_orders', 'edit_orders',
            // Inventario (crear/editar productos y categorías)
            'view_products', 'create_products', 'edit_products',
            'view_categories', 'create_categories', 'edit_categories',
            'view_inventory', 'adjust_inventory',
            // Clientes y cobros
            'view_customers', 'create_customers', 'edit_customers',
            'view_receivables', 'create_receivables', 'edit_receivables',
            // Gastos y pagos
            'view_expenses', 'create_expenses', 'edit_expenses',
            'view_payables', 'create_payables', 'edit_payables',
            // Compras
            'view_suppliers', 'create_suppliers', 'edit_suppliers',
            'view_purchases', 'create_purchases', 'edit_purchases',
            // Reportes (sin contabilidad ni usuarios)
            'view_reports',
        ],

        'cashier' => [
            // POS / ventas / cotizaciones / caja
            'view_sales', 'create_sales', 'edit_sales',
            'view_cash', 'open_cash', 'close_cash',
            'view_orders', 'create_orders', 'edit_orders',
            // Consulta de catálogo y clientes (sin editar)
            'view_products',
            'view_customers',
        ],

        // FASE P1.1 · Roles especializados.
        // Cada rol tiene el mínimo indispensable para su puesto.

        'inventory' => [
            // Catálogo (crear/editar productos y categorías)
            'view_products', 'create_products', 'edit_products',
            'view_categories', 'create_categories', 'edit_categories',
            'view_inventory', 'adjust_inventory',
            // Compras (recibir mercancía)
            'view_suppliers', 'create_suppliers', 'edit_suppliers',
            'view_purchases', 'create_purchases', 'edit_purchases',
            // Lectura para contexto operativo
            'view_orders',
        ],

        'accountant' => [
            // Contabilidad completa
            'view_accounts', 'create_accounts', 'edit_accounts',
            'view_journal', 'create_journal', 'edit_journal',
            'view_income_statement', 'view_balance_sheet',
            // Gastos, pagos y cobros
            'view_expenses', 'create_expenses', 'edit_expenses',
            'view_payables', 'create_payables', 'edit_payables',
            'view_receivables', 'create_receivables', 'edit_receivables',
            // Lectura de operación
            'view_sales', 'view_orders', 'view_products', 'view_customers',
            'view_purchases', 'view_suppliers',
            // Reportes
            'view_reports',
        ],

        'seller' => [
            // Preventa: cotizaciones/órdenes, clientes y consulta de catálogo.
            // Sin caja, sin dashboard financiero, sin conteos ni categorías.
            'view_orders', 'create_orders', 'edit_orders',
            'view_customers', 'create_customers', 'edit_customers',
            'view_products',
        ],

        'viewer' => [
            // Solo consulta ligera (sin gastos, compras, caja, contabilidad ni reportes)
            'view_sales',
            'view_orders',
            'view_products',
            'view_customers',
        ],
    ],

];
