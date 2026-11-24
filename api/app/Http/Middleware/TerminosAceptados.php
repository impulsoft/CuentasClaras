<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// RF-03, RN-15: sin aceptar los términos y condiciones, el dueño no puede usar el sistema
class TerminosAceptados
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->debeAceptarTerminos()) {
            return response()->json([
                'codigo' => 'terminos_pendientes',
                'mensaje' => 'Debe aceptar los términos y condiciones para continuar.',
            ], 403);
        }

        return $next($request);
    }
}
