<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que la app necesita saber del usuario que inició sesión.
 *
 * @mixin User
 */
class UsuarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'usuario' => $this->usuario,
            'rol' => $this->rol,
            'debe_aceptar_terminos' => $this->debeAceptarTerminos(),
            'debe_cambiar_contrasena' => $this->debe_cambiar_contrasena,
            'negocio' => $this->negocio?->paraDueno(), // RF-10, RF-12, RN-34
        ];
    }
}
