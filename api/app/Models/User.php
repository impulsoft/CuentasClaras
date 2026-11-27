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

#[Fillable(['negocio_id', 'rol', 'nombre', 'usuario', 'telefono', 'password', 'activo', 'desactivado_en', 'debe_cambiar_contrasena', 'sesiones_validas_desde', 'terminos_aceptados_en', 'codigo_recuperacion', 'codigo_recuperacion_en'])]
#[Hidden(['password', 'remember_token', 'codigo_recuperacion'])]
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
            'codigo_recuperacion' => 'hashed',
            'codigo_recuperacion_en' => 'datetime',
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

    // No puede entrar y se cierran sus sesiones en todos los equipos (RF-05, RN-16)
    public function desactivar(): void
    {
        $this->update(['activo' => false, 'desactivado_en' => now()]);
        $this->tokens()->delete();
    }

    /**
     * Código de recuperación del admin: si olvida la contraseña, entra con este código y pone una
     * nueva, sin terminal. Se muestra una sola vez; se guarda cifrado, como una contraseña.
     * 12 letras y números sin los que se confunden (0/O, 1/I/L), en tres grupos: K7M2-9QXP-4HTR.
     */
    public function crearCodigoRecuperacion(): string
    {
        $letras = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $codigo = '';
        for ($i = 0; $i < 12; $i++) {
            $codigo .= $letras[random_int(0, strlen($letras) - 1)];
        }
        $this->update(['codigo_recuperacion' => $codigo, 'codigo_recuperacion_en' => now()]);

        return implode('-', str_split($codigo, 4));
    }

    // Lo que escribe la persona: sin guiones ni espacios y en mayúsculas ("k7m2 9qxp-4htr" sirve)
    public static function normalizarCodigo(string $codigo): string
    {
        return strtoupper(preg_replace('/[^a-z0-9]/i', '', $codigo));
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
