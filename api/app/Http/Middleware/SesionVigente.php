<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RF-46 / RF-47: si la contraseña cambió después de abrir esta sesión, la sesión se cierra.
 * En la sincronización se deja pasar una última vez para que lo pendiente llegue al servidor;
 * el controlador cierra la sesión al terminar.
 */
class SesionVigente
{
    public const ULTIMA_SINCRONIZACION = 'sesion_por_cerrar';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $token = $usuario->currentAccessToken();
        $creadaEl = $token?->created_at ?? null;

        if (! $usuario->sesionVencida($creadaEl)) {
            return $next($request);
        }

        if ($request->routeIs('sincronizar')) {
            $request->attributes->set(self::ULTIMA_SINCRONIZACION, true);

            return $next($request);
        }

        $token->delete();

        return self::respuesta();
    }

    public static function respuesta(): Response
    {
        return response()->json([
            'codigo' => 'sesion_cerrada',
            'mensaje' => 'Su contraseña cambió. Vuelva a entrar con la contraseña nueva.',
        ], 401);
    }
}
