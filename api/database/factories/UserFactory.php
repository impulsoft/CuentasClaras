<?php

namespace Database\Factories;

use App\Models\Negocio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Por defecto crea un dueño con su negocio y los términos ya aceptados.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'negocio_id' => Negocio::factory(),
            'rol' => User::DUENO,
            'nombre' => fake()->name(),
            'usuario' => fake()->unique()->userName(),
            'telefono' => fake()->numerify('3#########'),
            'password' => static::$password ??= Hash::make('password'),
            'activo' => true,
            'terminos_aceptados_en' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'negocio_id' => null,
            'rol' => User::ADMIN,
            'terminos_aceptados_en' => null,
        ]);
    }

    public function sinTerminos(): static
    {
        return $this->state(fn (array $attributes) => [
            'terminos_aceptados_en' => null,
        ]);
    }

    public function desactivado(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }
}
