<?php

namespace App\Http\Controllers;

use App\Http\Resources\UsuarioResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SesionController extends Controller
{
    // RF-01: usuario y contraseña; el token queda guardado en el dispositivo (RF-48)
    public function iniciar(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:50'],
            'contrasena' => ['required', 'string', 'max:100'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ], [
            'required' => 'Escriba su usuario y su contraseña.',
        ]);

        $usuario = User::where('usuario', $datos['usuario'])->first();

        if (! $usuario || ! Hash::check($datos['contrasena'], $usuario->password)) {
            throw ValidationException::withMessages([
                'usuario' => 'El usuario o la contraseña no son correctos.',
            ]);
        }

        if (! $usuario->activo) {
            return response()->json([
                'codigo' => 'cuenta_desactivada',
                'mensaje' => 'Su cuenta está desactivada. Comuníquese con el administrador.',
            ], 403);
        }

        return [
            'token' => $usuario->createToken($datos['dispositivo'] ?? 'Dispositivo')->plainTextToken,
            'usuario' => new UsuarioResource($usuario->load('negocio')),
        ];
    }

    public function yo(Request $request)
    {
        return new UsuarioResource($request->user()->load('negocio'));
    }

    // RF-02: cierra la sesión solo en este dispositivo
    public function cerrar(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
