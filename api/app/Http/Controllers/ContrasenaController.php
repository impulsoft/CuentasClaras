<?php

namespace App\Http\Controllers;

use App\Http\Resources\UsuarioResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ContrasenaController extends Controller
{
    /**
     * RF-47: cambiar la contraseña escribiendo la actual y la nueva. Con una contraseña temporal
     * (RF-46) no se pide la actual: la acaba de escribir para entrar.
     * Las otras sesiones se cierran cuando sincronicen (SesionVigente); esta sigue con un token nuevo.
     */
    public function cambiar(Request $request)
    {
        $usuario = $request->user();
        $temporal = $usuario->debe_cambiar_contrasena;

        $datos = $request->validate([
            'actual' => [$temporal ? 'nullable' : 'required', 'string', 'max:100'],
            'nueva' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'actual.required' => 'Escriba su contraseña actual.',
            'nueva.required' => 'Escriba la contraseña nueva.',
            'nueva.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'max' => 'La contraseña es demasiado larga.',
        ]);

        if (! $temporal && ! Hash::check($datos['actual'], $usuario->password)) {
            throw ValidationException::withMessages(['actual' => 'La contraseña actual no es correcta.']);
        }
        if (Hash::check($datos['nueva'], $usuario->password)) {
            throw ValidationException::withMessages(['nueva' => 'La contraseña nueva debe ser distinta de la anterior.']);
        }

        $usuario->update([
            'password' => $datos['nueva'],
            'debe_cambiar_contrasena' => false,
            'sesiones_validas_desde' => now(),
        ]);

        $nombre = $usuario->currentAccessToken()?->name ?? 'Dispositivo';
        $usuario->currentAccessToken()?->delete();

        return [
            'token' => $usuario->createToken($nombre)->plainTextToken,
            'usuario' => new UsuarioResource($usuario->load('negocio')),
        ];
    }
}
