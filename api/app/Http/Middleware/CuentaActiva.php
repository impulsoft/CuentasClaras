<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// RN-16: un dueño desactivado no puede usar el sistema aunque tenga una sesión abierta
class CuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->activo) {
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'codigo' => 'cuenta_desactivada',
                'mensaje' => 'Su cuenta está desactivada. Comuníquese con el administrador.',
            ], 403);
        }

        return $next($request);
    }
}
