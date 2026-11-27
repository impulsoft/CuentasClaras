<?php

namespace App\Http\Controllers;

use App\Http\Resources\UsuarioResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ContrasenaController extends Controller
{
    // Intentos fallidos de recuperar con el código, por usuario, antes de bloquearlo una hora
    public const INTENTOS_RECUPERAR = 10;

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

    /*
     * El admin crea su código de recuperación (Cuenta). Se muestra una sola vez; si crea otro, el
     * anterior deja de servir.
     */
    public function crearCodigo(Request $request)
    {
        $usuario = $request->user();

        return ['codigo' => $usuario->crearCodigoRecuperacion(), 'creado_en' => $usuario->codigo_recuperacion_en->toIso8601String()];
    }

    /*
     * "¿Olvidó su contraseña?" del admin: con su usuario y su código de recuperación pone una
     * contraseña nueva, sin terminal. El código sirve una sola vez y se cierran sus sesiones abiertas.
     * Los dueños no tienen código: a ellos el admin les da una contraseña temporal (RF-46).
     */
    public function recuperar(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:50'],
            'codigo' => ['required', 'string', 'max:40'],
            'nueva' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'usuario.required' => 'Escriba su usuario.',
            'codigo.required' => 'Escriba el código de recuperación.',
            'nueva.required' => 'Escriba la contraseña nueva.',
            'nueva.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'max' => 'Este dato es demasiado largo.',
        ]);

        /*
         * Además del límite por equipo (5 por minuto, en la ruta), un límite por usuario: después de
         * 10 intentos fallidos con el mismo usuario se bloquea una hora, aunque prueben desde
         * equipos distintos. Con un código de 12 letras al azar, adivinarlo así es imposible.
         */
        $clave = 'recuperar:'.mb_strtolower(trim($datos['usuario']));
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS_RECUPERAR)) {
            throw ValidationException::withMessages([
                'codigo' => 'Demasiados intentos fallidos con este usuario. Espere una hora y vuelva a intentar.',
            ])->status(429);
        }

        $usuario = User::where('usuario', $datos['usuario'])->where('rol', User::ADMIN)->first();
        $valido = $usuario && $usuario->activo && $usuario->codigo_recuperacion
            && Hash::check(User::normalizarCodigo($datos['codigo']), $usuario->codigo_recuperacion);
        if (! $valido) {
            RateLimiter::hit($clave, 3600);
            // El mismo mensaje en todos los casos, para no dar pistas de qué usuarios existen
            throw ValidationException::withMessages(['codigo' => 'El usuario o el código no son correctos.']);
        }
        RateLimiter::clear($clave);

        $usuario->forceFill([
            'password' => $datos['nueva'],
            'debe_cambiar_contrasena' => false,
            'sesiones_validas_desde' => now(),
            'codigo_recuperacion' => null,
            'codigo_recuperacion_en' => null,
        ])->save();
        $usuario->tokens()->delete();

        return ['mensaje' => 'Listo: ya puede entrar con su contraseña nueva. Ese código ya no sirve: cree otro en Cuenta.'];
    }
}
