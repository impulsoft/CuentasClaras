<?php

namespace Database\Factories;

use App\Models\Negocio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Negocio>
 */
class NegocioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Tienda '.fake()->lastName(),
            'telefono' => fake()->numerify('3#########'),
            'estado' => 'activo',
            'suscripcion_vence_el' => now()->addMonth()->toDateString(),
        ];
    }
}
