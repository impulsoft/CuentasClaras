<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['negocio_id', 'rol', 'nombre', 'usuario', 'telefono', 'password', 'activo', 'desactivado_en', 'debe_cambiar_contrasena', 'sesiones_validas_desde', 'terminos_aceptados_en'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ADMIN = 'admin';

    public const DUENO = 'dueno';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_contrasena' => 'boolean',
            'terminos_aceptados_en' => 'datetime',
            'desactivado_en' => 'datetime',
            'sesiones_validas_desde' => 'datetime',
        ];
    }

    /**
     * RF-46 / RF-47: después de cambiar o restablecer la contraseña, las sesiones abiertas antes
     * dejan de valer. Se cierran cuando el dispositivo vuelve a conectarse (ver SesionVigente).
     */
    public function sesionVencida(?Carbon $creadaEl): bool
    {
        return $this->sesiones_validas_desde !== null && $creadaEl !== null && $creadaEl->lessThan($this->sesiones_validas_desde);
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function esAdmin(): bool
    {
        return $this->rol === self::ADMIN;
    }

    // RN-15: el admin no acepta términos; el dueño sí, antes de usar el sistema
    public function debeAceptarTerminos(): bool
    {
        return $this->rol === self::DUENO && $this->terminos_aceptados_en === null;
    }
}
