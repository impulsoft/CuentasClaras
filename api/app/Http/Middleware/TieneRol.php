<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Uso en rutas: ->middleware('rol:admin') o ->middleware('rol:dueno')
class TieneRol
{
    public function handle(Request $request, Closure $next, string $rol): Response
    {
        if ($request->user()->rol !== $rol) {
            return response()->json([
                'codigo' => 'sin_permiso',
                'mensaje' => 'No tiene permiso para esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
