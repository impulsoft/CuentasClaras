<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// RF-46: con una contraseña temporal, el dueño debe cambiarla antes de seguir
class ContrasenaAlDia
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->debe_cambiar_contrasena) {
            return response()->json([
                'codigo' => 'debe_cambiar_contrasena',
                'mensaje' => 'Primero cambie la contraseña temporal por una suya.',
            ], 403);
        }

        return $next($request);
    }
}
