<?php

namespace Database\Seeders;

use App\Models\Negocio;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Crea el admin y, en desarrollo, un negocio de prueba.
 * Los usuarios y contraseñas salen de config/cierre.php (.env).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $contrasenaAdmin = config('cierre.admin.contrasena');

        if (! $contrasenaAdmin) {
            throw new RuntimeException('Falta ADMIN_CONTRASENA en el archivo .env.');
        }

        User::updateOrCreate(['usuario' => config('cierre.admin.usuario')], [
            'rol' => User::ADMIN,
            'nombre' => 'Administrador',
            'password' => $contrasenaAdmin,
        ]);

        if (! app()->isLocal() || ! config('cierre.prueba.contrasena')) {
            return;
        }

        $negocio = Negocio::firstOrCreate(['nombre' => 'Tienda de prueba'], [
            'telefono' => '3001234567',
            'estado' => 'activo',
            'suscripcion_inicio_el' => today()->toDateString(),
            'suscripcion_vence_el' => today()->addMonthNoOverflow()->toDateString(),
        ]);

        User::updateOrCreate(['usuario' => config('cierre.prueba.usuario')], [
            'negocio_id' => $negocio->id,
            'rol' => User::DUENO,
            'nombre' => 'Dueño de prueba',
            'telefono' => '3001234567',
            'password' => config('cierre.prueba.contrasena'),
        ]);
    }
}
