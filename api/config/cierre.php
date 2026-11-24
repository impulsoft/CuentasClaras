<?php

// Cuentas iniciales que crea el seeder (database/seeders/DatabaseSeeder.php)
return [

    'admin' => [
        'usuario' => env('ADMIN_USUARIO', 'admin'),
        'contrasena' => env('ADMIN_CONTRASENA'),
    ],

    // Negocio de prueba: solo se crea con APP_ENV=local
    'prueba' => [
        'usuario' => env('PRUEBA_USUARIO', 'tienda.prueba'),
        'contrasena' => env('PRUEBA_CONTRASENA'),
    ],

];
